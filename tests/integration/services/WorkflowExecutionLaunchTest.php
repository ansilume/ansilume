<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveySecrets;
use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApproverEligibility;
use app\services\JobLaunchSecrets;
use app\services\WorkflowExecutionService;
use app\services\WorkflowLaunchRejectedException;
use app\services\WorkflowRunVars;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\base\Model;
use yii\base\ModelEvent;

/**
 * WorkflowExecutionService::launch(): one transaction holds the workflow
 * template's row lock and refuses, before anything is written, a deleted
 * template, approvals that could never be granted, and launch extra vars it
 * cannot take; the run is written with its extra vars.
 */
class WorkflowExecutionLaunchTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;

    private RecordingNotificationDispatcher $notifications;
    private mixed $dispatcherBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcherBefore = \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null;
        $this->notifications = new RecordingNotificationDispatcher();
        \Yii::$app->set('notificationDispatcher', $this->notifications);
    }

    protected function tearDown(): void
    {
        \Yii::$app->set('notificationDispatcher', $this->dispatcherBefore);
        parent::tearDown();
    }

    // -- The template ------------------------------------------------------------

    /**
     * Regression: a launch of a workflow template that was deleted after the
     * caller loaded it was accepted and started a run of the deleted
     * workflow, which its deletion could no longer cancel.
     */
    public function testRegressionALaunchOfADeletedWorkflowTemplateIsRefused(): void
    {
        $user = $this->operator('wf-launch-deleted');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        WorkflowTemplate::updateAll(['deleted_at' => time()], ['id' => $workflow->id]);

        try {
            $this->engine()->launch($workflow, (int)$user->id);
            $this->fail('expected a refusal');
        } catch (WorkflowLaunchRejectedException $e) {
            $this->assertSame(sprintf(WorkflowExecutionService::WORKFLOW_TEMPLATE_DELETED, $workflow->id), $e->getMessage());
            $this->assertSame("Workflow template #{$workflow->id} was deleted.", $e->getMessage());
        }

        $this->assertNothingWasLaunched($workflow);
    }

    /**
     * The template's row is locked (WorkflowTemplate::lockLive(), the lock a
     * deletion and a step change take too) before its steps are read and the
     * run is written, inside one transaction, and the launch is announced
     * only after it committed: a deletion either comes first (the launch is
     * refused) or sees the run (and cancels it), and no step change slips in
     * between the check for steps and the run.
     */
    public function testTheTemplateRowIsLockedBeforeTheRunIsWritten(): void
    {
        $user = $this->operator('wf-launch-lock');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);

        $statements = $this->statementsOf(fn () => $this->engine()->launch($workflow, (int)$user->id));

        $savepoint = $this->statementIndex($statements, '~^SAVEPOINT LEVEL\d+$~');
        $lock = $this->statementIndex(
            $statements,
            '~^SELECT `deleted_at` FROM `workflow_template` WHERE `id` = ' . $workflow->id . ' FOR UPDATE$~'
        );
        $steps = $this->statementIndex($statements, '~^SELECT \* FROM `workflow_step` WHERE `workflow_template_id`=' . $workflow->id . ' ORDER BY~');
        $insert = $this->statementIndex($statements, '~^INSERT INTO `workflow_job`~');
        $release = $this->statementIndex($statements, '~^RELEASE SAVEPOINT LEVEL\d+$~');
        $announced = $this->statementIndex($statements, '~^INSERT INTO `audit_log`~');
        $this->assertTrue(
            $savepoint < $lock && $lock < $steps && $steps < $insert && $insert < $release && $release < $announced,
            implode("\n", $statements)
        );
    }

    /**
     * A template object whose row is gone altogether (hard-deleted) is
     * refused the same way.
     */
    public function testALaunchOfATemplateWhoseRowIsGoneIsRefused(): void
    {
        $user = $this->operator('wf-launch-gone');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        WorkflowTemplate::deleteAll(['id' => $workflow->id]);

        $this->expectException(WorkflowLaunchRejectedException::class);
        $this->expectExceptionMessage(sprintf(WorkflowExecutionService::WORKFLOW_TEMPLATE_DELETED, $workflow->id));
        $this->engine()->launch($workflow, (int)$user->id);
    }

    /**
     * A run that cannot be saved is a server error; the transaction leaves
     * nothing behind.
     */
    public function testARunThatCannotBeSavedIsAnErrorAndNothingIsWritten(): void
    {
        $user = $this->operator('wf-launch-unsaved');
        $workflow = $this->pauseWorkflow((int)$user->id);
        $refuse = static function (ModelEvent $event): void {
            $event->isValid = false;
        };
        Event::on(WorkflowJob::class, Model::EVENT_BEFORE_VALIDATE, $refuse);
        try {
            $this->engine()->launch($workflow, (int)$user->id, ['extra_vars' => ['env' => 'prod']]);
            $this->fail('expected an error');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(WorkflowLaunchRejectedException::class, $e);
            $this->assertSame('Failed to create workflow job: []', $e->getMessage());
        } finally {
            Event::off(WorkflowJob::class, Model::EVENT_BEFORE_VALIDATE, $refuse);
        }

        $this->assertNothingWasLaunched($workflow);
    }

    /**
     * A workflow without steps is a refusal the caller can fix, like the
     * others: web danger flash, API and trigger 422 (it was a plain
     * RuntimeException, which the trigger answered with 500 "Launch failed.").
     */
    public function testAWorkflowWithoutStepsIsRefusedAndNothingIsWritten(): void
    {
        $user = $this->operator('wf-launch-empty');
        $workflow = $this->createWorkflowTemplate((int)$user->id);

        try {
            $this->engine()->launch($workflow, (int)$user->id);
            $this->fail('expected a refusal');
        } catch (WorkflowLaunchRejectedException $e) {
            $this->assertSame(sprintf(WorkflowExecutionService::NO_STEPS, $workflow->id), $e->getMessage());
            $this->assertSame("Workflow template #{$workflow->id} has no steps.", $e->getMessage());
        }

        $this->assertNothingWasLaunched($workflow);
    }

    /**
     * A deleted template is refused as deleted, also when it has no steps.
     */
    public function testADeletedWorkflowWithoutStepsIsRefusedAsDeleted(): void
    {
        $user = $this->operator('wf-launch-empty-deleted');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        WorkflowTemplate::updateAll(['deleted_at' => time()], ['id' => $workflow->id]);

        $this->expectException(WorkflowLaunchRejectedException::class);
        $this->expectExceptionMessage(sprintf(WorkflowExecutionService::WORKFLOW_TEMPLATE_DELETED, $workflow->id));
        $this->engine()->launch($workflow, (int)$user->id);
    }

    // -- Approvals that could never be granted -----------------------------------------

    /**
     * Regression: a workflow whose approval step only its launcher could
     * approve was launched; the run then waited at that step for a decision
     * nobody may make.
     */
    public function testRegressionALaunchWhoseApprovalStepHasTooFewEligibleApproversIsRefused(): void
    {
        $launcher = $this->operator('wf-launch-self');
        $rule = $this->usersRuleOf((int)$launcher->id, (int)$launcher->id);
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);

        try {
            $this->engine()->launch($workflow, (int)$launcher->id);
            $this->fail('expected a refusal');
        } catch (WorkflowLaunchRejectedException $e) {
            $this->assertSame(
                sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, '"step-0"', json_encode($rule->name), 1, 0),
                $e->getMessage()
            );
        }

        $this->assertNothingWasLaunched($workflow);
        $this->assertSame(0, (int)ApprovalRequest::find()->where(['approval_rule_id' => $rule->id])->count());
    }

    public function testALaunchWhoseApprovalStepHasNoRuleIsRefused(): void
    {
        $user = $this->operator('wf-launch-norule');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_APPROVAL);

        try {
            $this->engine()->launch($workflow, (int)$user->id);
            $this->fail('expected a refusal');
        } catch (WorkflowLaunchRejectedException $e) {
            $this->assertSame(sprintf(ApproverEligibility::REFUSAL_WORKFLOW_RULE_MISSING, '"step-1"'), $e->getMessage());
        }

        $this->assertNothingWasLaunched($workflow);
    }

    /**
     * A job step whose job template waits for approval counts too: its job
     * could never be approved.
     */
    public function testALaunchWhoseGatedJobStepHasTooFewEligibleApproversIsRefused(): void
    {
        $launcher = $this->operator('wf-launch-gated');
        $rule = $this->usersRuleOf((int)$launcher->id, (int)$launcher->id);
        $template = $this->gatedTemplate((int)$launcher->id, $rule);
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);

        $this->expectException(WorkflowLaunchRejectedException::class);
        $this->expectExceptionMessage(sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, '"step-0"', json_encode($rule->name), 1, 0));
        try {
            $this->engine()->launch($workflow, (int)$launcher->id);
        } finally {
            $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
        }
    }

    public function testAnApproverBesidesTheLauncherLetsTheLaunchThrough(): void
    {
        $launcher = $this->operator('wf-launch-ok');
        $approver = $this->operator('wf-launch-ok-approver');
        $rule = $this->usersRuleOf((int)$launcher->id, (int)$launcher->id, (int)$approver->id);
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $approval = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);

        $run = $this->engine()->launch($workflow, (int)$launcher->id);

        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
        $placeholder = $this->jobOfStep($run, $approval);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $placeholder->status);
        $this->assertSame((int)$launcher->id, (int)$placeholder->launched_by);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->requestOfJob($placeholder)->status);
        $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_WORKFLOW_LAUNCHED, 'workflow_job', (int)$run->id));
        $this->assertCount(1, $this->notifications->payloadsOf(NotificationTemplate::EVENT_WORKFLOW_LAUNCHED));
    }

    // -- Launch extra vars --------------------------------------------------------------

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function refusedExtraVarsProvider(): array
    {
        $notAnObject = WorkflowRunVars::NOT_AN_OBJECT;

        return [
            'a list' => [['web1', 'web2'], $notAnObject],
            'a number' => [5, $notAnObject],
            'true' => [true, $notAnObject],
            'false' => [false, $notAnObject],
            'zero' => [0, $notAnObject],
            'text that is no JSON' => ['env=prod', $notAnObject],
            'a JSON list as text' => ['["web1"]', $notAnObject],
            'broken JSON text' => ['{"env": ', $notAnObject],
            'a number too large for JSON' => [['limit' => INF], SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN)],
            'a JSON text with a number too large for JSON' => ['{"limit": 1e999}', SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN)],
            'text that is not UTF-8' => [['env' => "\xB1\x31"], SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_UTF8)],
        ];
    }

    /**
     * Launch extra vars the run cannot take are refused before it is
     * written: they would reach every job step.
     *
     * @dataProvider refusedExtraVarsProvider
     */
    public function testExtraVarsTheRunCannotTakeAreRefused(mixed $extraVars, string $message): void
    {
        $user = $this->operator('wf-launch-vars');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$user->id)->id);

        try {
            $this->engine()->launch($workflow, (int)$user->id, ['extra_vars' => $extraVars], 'api');
            $this->fail('expected a refusal');
        } catch (WorkflowLaunchRejectedException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertNothingWasLaunched($workflow);
    }

    /**
     * The placeholder sent as the answer to a password field of any job
     * step's template, not only the start step's, is refused: the step would
     * otherwise run with "$encrypted$" as the password, or fail after the
     * steps before it ran.
     */
    public function testThePlaceholderAsAPasswordAnswerOfALaterStepIsRefused(): void
    {
        $user = $this->operator('wf-launch-placeholder');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$user->id)->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->createPasswordSurveyTemplate((int)$user->id)->id);

        try {
            $this->engine()->launch($workflow, (int)$user->id, ['extra_vars' => [self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER]]);
            $this->fail('expected a refusal');
        } catch (WorkflowLaunchRejectedException $e) {
            $this->assertSame(
                sprintf(JobLaunchSecrets::PLACEHOLDER_SENT, '"' . self::SURVEY_PASSWORD_FIELD . '"', SurveySecrets::PLACEHOLDER),
                $e->getMessage()
            );
            $this->assertSame('The value of "db_password" is the placeholder $encrypted$; send the real value.', $e->getMessage());
        }

        $this->assertNothingWasLaunched($workflow);
    }

    /**
     * The run's extra vars column holds 65535 bytes: more is refused with a
     * message instead of a database error; exactly that much is stored.
     */
    public function testExtraVarsTooLargeToStoreAreRefused(): void
    {
        $user = $this->operator('wf-launch-large');
        $workflow = $this->pauseWorkflow((int)$user->id);
        // {"blob":"…"} is 11 bytes of JSON around the value.
        $fits = str_repeat('x', WorkflowRunVars::MAX_EXTRA_VARS_BYTES - 11);

        $run = $this->engine()->launch($workflow, (int)$user->id, ['extra_vars' => ['blob' => $fits]]);
        $stored = WorkflowJob::findOne($run->id);
        $this->assertNotNull($stored);
        $this->assertSame(['blob' => $fits], $stored->launchExtraVars());

        try {
            $this->engine()->launch($workflow, (int)$user->id, ['extra_vars' => ['blob' => $fits . 'x']]);
            $this->fail('expected a refusal');
        } catch (WorkflowLaunchRejectedException $e) {
            $this->assertSame(sprintf(WorkflowRunVars::TOO_LARGE, 65535), $e->getMessage());
            $this->assertSame('The extra vars are too large: a run stores at most 65535 bytes of them as JSON.', $e->getMessage());
        }
        $this->assertSame(1, (int)WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->count());
    }

    /**
     * A password answer JSON cannot hold gets the message for the answers.
     */
    public function testAPasswordAnswerJsonCannotHoldIsRefused(): void
    {
        $user = $this->operator('wf-launch-answer-inf');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->createPasswordSurveyTemplate((int)$user->id)->id);

        try {
            $this->engine()->launch($workflow, (int)$user->id, ['extra_vars' => [self::SURVEY_PASSWORD_FIELD => INF]]);
            $this->fail('expected a refusal');
        } catch (WorkflowLaunchRejectedException $e) {
            $this->assertSame(SurveySecrets::unencodable(JobLaunchSecrets::PASSWORD_ANSWERS, JSON_ERROR_INF_OR_NAN), $e->getMessage());
            $this->assertSame('The password answers cannot be stored: they contain a number too large for JSON.', $e->getMessage());
        }

        $this->assertNothingWasLaunched($workflow);
    }

    /**
     * The placeholder under a key that is no password field is a value like
     * any other.
     */
    public function testThePlaceholderUnderAnotherKeyIsKept(): void
    {
        $user = $this->operator('wf-launch-literal');
        $workflow = $this->pauseWorkflow((int)$user->id);

        $run = $this->engine()->launch($workflow, (int)$user->id, ['extra_vars' => ['note' => SurveySecrets::PLACEHOLDER]]);

        $this->assertSame(['note' => SurveySecrets::PLACEHOLDER], $run->launchExtraVars());
        $this->assertNull($run->getSealedSecretExtraVars());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function noExtraVarsProvider(): array
    {
        return [
            'no key' => [[]],
            'null' => [['extra_vars' => null]],
            'empty text' => [['extra_vars' => '']],
            'blank text' => [['extra_vars' => "  \n"]],
            'empty object' => [['extra_vars' => []]],
            'empty JSON object as text' => [['extra_vars' => '{}']],
        ];
    }

    /**
     * @dataProvider noExtraVarsProvider
     * @param array<string, mixed> $overrides
     */
    public function testNoExtraVarsStoreNone(array $overrides): void
    {
        $user = $this->operator('wf-launch-novars');
        $workflow = $this->pauseWorkflow((int)$user->id);

        $run = $this->engine()->launch($workflow, (int)$user->id, $overrides);

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
        $this->assertSame([], $run->launchExtraVars());
        $this->assertNull($run->getAttribute('extra_vars'));
        $this->assertNull($run->getSealedSecretExtraVars());
    }

    /**
     * The run keeps its launch extra vars: the password answers sealed, the
     * placeholder in their place, as a JSON object also when sent as JSON
     * text. A password field of a deleted job template counts: an answer to
     * it is secret all the same.
     */
    public function testTheRunStoresItsExtraVarsWithTheAnswersSealed(): void
    {
        $user = $this->operator('wf-launch-store');
        $secretTemplate = $this->createPasswordSurveyTemplate((int)$user->id);
        JobTemplate::updateAll(['deleted_at' => time()], ['id' => $secretTemplate->id]);
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$secretTemplate->id);
        $json = (string)json_encode(['env' => 'prod', self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER, 'zone' => 'eu']);

        $run = $this->engine()->launch($workflow, (int)$user->id, ['extra_vars' => $json], 'trigger');

        $stored = WorkflowJob::findOne($run->id);
        $this->assertNotNull($stored);
        $this->assertSame(
            ['env' => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER, 'zone' => 'eu'],
            $stored->launchExtraVars()
        );
        $this->assertStringNotContainsString(self::SURVEY_PASSWORD_ANSWER, (string)$stored->getAttribute('extra_vars'));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER], SurveySecrets::open($stored->getSealedSecretExtraVars()));
        $this->assertArrayNotHasKey('secret_extra_vars', $stored->toArray());
    }

    /**
     * A decoded object keeps its keys; a JSON text whose keys are 0 to n-1 is
     * an object too.
     */
    public function testAJsonTextObjectWithNumericKeysIsTaken(): void
    {
        $user = $this->operator('wf-launch-numeric');
        $workflow = $this->pauseWorkflow((int)$user->id);

        $run = $this->engine()->launch($workflow, (int)$user->id, ['extra_vars' => '{"0": "first"}']);

        $this->assertSame(['0' => 'first'], $run->launchExtraVars());
        $this->assertSame('{"0":"first"}', $run->getAttribute('extra_vars'));
    }

    // -- Helpers --------------------------------------------------------------------------

    private function pauseWorkflow(int $userId): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate($userId);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);

        return $workflow;
    }

    /**
     * No run, no workflow.launched entry and no notification.
     */
    private function assertNothingWasLaunched(WorkflowTemplate $workflow): void
    {
        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->exists(), 'no run was written');
        $this->assertFalse(
            AuditLog::find()->where(['action' => AuditLog::ACTION_WORKFLOW_LAUNCHED])->andWhere(['like', 'metadata', '"template_id":' . $workflow->id . ','])->exists(),
            'no workflow.launched entry'
        );
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_WORKFLOW_LAUNCHED));
    }
}
