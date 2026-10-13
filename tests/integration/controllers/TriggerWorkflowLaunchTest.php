<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveySecrets;
use app\controllers\TriggerController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApproverEligibility;
use app\services\JobLaunchSecrets;
use app\services\WorkflowExecutionService;
use app\services\WorkflowRunVars;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\BaseActiveRecord;
use yii\web\Response;

/**
 * POST /trigger/fire-workflow with the real services: the body's extra_vars
 * are the run's launch extra vars, they reach every job step, password
 * answers among them stay sealed, and a launch refused for a reason the
 * caller can fix answers 422 with the reason and launches nothing.
 */
class TriggerWorkflowLaunchTest extends WebControllerTestCase
{
    use PresentsTriggerTokens;
    use TeamScopeFixtures;

    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    private User $user;
    private ?\Closure $hook = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swapped[] = ['notificationDispatcher', \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null];
        \Yii::$app->set('notificationDispatcher', new RecordingNotificationDispatcher());
        $this->user = $this->createUserWithRole('wf_trigger', 'operator');
    }

    protected function tearDown(): void
    {
        if ($this->hook !== null) {
            Event::off(WorkflowTemplate::class, BaseActiveRecord::EVENT_AFTER_FIND, $this->hook);
            $this->hook = null;
        }
        foreach (array_reverse($this->swapped) as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    /**
     * Regression: the extra vars of a workflow trigger reached the start
     * step only (the docs said so); every later job step ran without them.
     */
    public function testRegressionTheTriggersExtraVarsReachEveryJobStep(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $first = $this->jobStep($workflow, 0, $this->openTemplate());
        $second = $this->jobStep($workflow, 1, $this->openTemplate());

        $run = $this->fire($workflow, ['extra_vars' => ['env' => 'prod', 'release' => 42]]);
        $this->finish($this->jobOf($run, $first));

        $this->assertSame(['env' => 'prod', 'release' => 42], $this->extraVarsOf($this->jobOf($run, $first)));
        $this->assertSame(['env' => 'prod', 'release' => 42], $this->extraVarsOf($this->jobOf($run, $second)));
        $this->assertSame(['env' => 'prod', 'release' => 42], $run->launchExtraVars(), 'the run keeps them');
    }

    /**
     * A password answer sent by a workflow trigger is stored sealed on the
     * run and in the job of every step, the placeholder in its place.
     */
    public function testAPasswordAnswerSentByAWorkflowTriggerStaysSealed(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $first = $this->jobStep($workflow, 0, $this->openTemplate());
        $second = $this->jobStep($workflow, 1, $this->createPasswordSurveyTemplate((int)$this->user->id));

        $run = $this->fire($workflow, ['extra_vars' => [self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER]]);
        $this->finish($this->jobOf($run, $first));

        $this->assertSame([self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], $run->launchExtraVars());
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER], SurveySecrets::open($run->getSealedSecretExtraVars()));
        foreach ([$first, $second] as $step) {
            $job = $this->jobOf($run, $step);
            $this->assertSame([self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], $this->extraVarsOf($job), $step->name);
            $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
            $this->assertStringNotContainsString(self::SURVEY_PASSWORD_ANSWER, (string)$job->runner_payload);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusedBodyProvider(): array
    {
        return [
            'extra_vars that are a list' => [['extra_vars' => ['web1']], WorkflowRunVars::NOT_AN_OBJECT],
            'extra_vars that are a number' => [['extra_vars' => 7], WorkflowRunVars::NOT_AN_OBJECT],
            'the placeholder as a password answer of a later step' => [
                ['extra_vars' => [self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER]],
                sprintf(JobLaunchSecrets::PLACEHOLDER_SENT, '"' . self::SURVEY_PASSWORD_FIELD . '"', SurveySecrets::PLACEHOLDER),
            ],
        ];
    }

    /**
     * @dataProvider refusedBodyProvider
     * @param array<string, mixed> $body
     */
    public function testExtraVarsTheRunCannotTakeAre422AndLaunchNothing(array $body, string $message): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $this->jobStep($workflow, 0, $this->openTemplate());
        $this->jobStep($workflow, 1, $this->createPasswordSurveyTemplate((int)$this->user->id));

        $result = $this->trigger($workflow, $body);

        $this->assertRefused($result, $workflow, $message);
    }

    /**
     * An approval step that only the user the trigger runs as could approve
     * refuses the launch with 422 and the reason, instead of a run that waits
     * for ever.
     */
    public function testAnApprovalNobodyElseMayDecideIs422(): void
    {
        $rule = $this->createApprovalRule(
            (int)$this->user->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [(int)$this->user->id]])
        );
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);

        $result = $this->trigger($workflow, []);

        $this->assertRefused(
            $result,
            $workflow,
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, '"step-0"', json_encode($rule->name), 1, 0)
        );
    }

    /**
     * A workflow deleted between the token lookup and the launch: 422, and
     * no run of the deleted workflow.
     */
    public function testAWorkflowDeletedWhileTheTriggerRunsIs422(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $workflowId = (int)$workflow->id;
        $this->hook = static function (Event $event) use ($workflowId): void {
            if ($event->sender instanceof WorkflowTemplate && (int)$event->sender->id === $workflowId) {
                WorkflowTemplate::updateAll(['deleted_at' => time()], ['id' => $workflowId]);
            }
        };
        Event::on(WorkflowTemplate::class, BaseActiveRecord::EVENT_AFTER_FIND, $this->hook);

        $result = $this->trigger($workflow, []);

        $this->assertRefused($result, $workflow, sprintf(WorkflowExecutionService::WORKFLOW_TEMPLATE_DELETED, $workflowId));
    }

    // -- Helpers ---------------------------------------------------------------------------

    private function openTemplate(): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject((int)$this->user->id)->id,
            (int)$this->createInventory((int)$this->user->id)->id,
            (int)$this->createRunnerGroup((int)$this->user->id)->id,
            (int)$this->user->id
        );
    }

    private function jobStep(WorkflowTemplate $workflow, int $order, JobTemplate $template): WorkflowStep
    {
        return $this->createWorkflowStep((int)$workflow->id, $order, WorkflowStep::TYPE_JOB, (int)$template->id);
    }

    /**
     * POST the JSON $body to the workflow's trigger, with its token in the
     * header.
     *
     * @param array<string, mixed> $body
     */
    private function trigger(WorkflowTemplate $workflow, array $body): Response
    {
        $token = $workflow->generateTriggerToken((int)$this->user->id);
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setRawBody($body === [] ? '' : (string)json_encode($body));
        $this->presentTriggerToken($token);
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return (new TriggerController('trigger', $app))->actionFireWorkflow();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function fire(WorkflowTemplate $workflow, array $body): WorkflowJob
    {
        $result = $this->trigger($workflow, $body);
        $this->assertSame(201, $result->statusCode);
        $this->assertIsArray($result->data);
        $run = WorkflowJob::findOne($result->data['workflow_job_id'] ?? 0);
        $this->assertNotNull($run);

        return $run;
    }

    private function assertRefused(Response $result, WorkflowTemplate $workflow, string $message): void
    {
        $this->assertSame(422, $result->statusCode);
        $this->assertSame(['error' => $message], $result->data);
        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->exists(), 'no run');
        $this->assertFalse(
            AuditLog::find()->where(['action' => AuditLog::ACTION_TRIGGER_FIRED, 'object_type' => 'workflow_template', 'object_id' => $workflow->id])->exists()
        );
    }

    private function jobOf(WorkflowJob $run, WorkflowStep $step): Job
    {
        /** @var WorkflowJobStep|null $execution */
        $execution = WorkflowJobStep::findOne(['workflow_job_id' => $run->id, 'workflow_step_id' => $step->id]);
        $this->assertNotNull($execution, "{$step->name} ran");
        /** @var Job|null $job */
        $job = Job::findOne(['id' => $execution->job_id]);
        $this->assertNotNull($job);

        return $job;
    }

    private function finish(Job $job): void
    {
        Job::updateAll(['status' => Job::STATUS_SUCCEEDED, 'finished_at' => time()], ['id' => $job->id]);
        $job->refresh();
        /** @var WorkflowExecutionService $engine */
        $engine = \Yii::$app->get('workflowExecutionService');
        $engine->onChildJobCompleted($job);
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function extraVarsOf(Job $job): ?array
    {
        $vars = json_decode((string)$job->extra_vars, true);

        return is_array($vars) ? $vars : null;
    }
}
