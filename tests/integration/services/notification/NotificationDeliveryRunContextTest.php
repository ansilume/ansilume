<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\Job;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\services\NotificationDispatcher;
use app\services\notification\NotificationIdentity;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use app\tests\unit\services\notification\HttpLoopbackServer;

/**
 * The context of a workflow run that a message of a job or an approval
 * event of the run carries (workflow.*, step.*:
 * NotificationDispatcher::RUN_CONTEXT_PREFIXES) goes out only while the
 * user its template delivers as may see the run's workflow when the
 * notification worker sends it (NotificationDeliveryService), as the
 * dispatcher keeps it only for such a user when the event happens.
 *
 * Regression (review of the workflow notifications, access finding 3 and
 * delivery finding 8): the worker checked again only that the user may see
 * the job or the request, and sent the variables that the dispatcher had
 * kept for the user the template delivered as then: after the workflow got
 * a step of another team's project, or the template came to deliver as
 * another user while its delivery waited for a retry, the message still
 * named the workflow, its step and why it waits or failed, in the body,
 * and in the event payload of a webhook.
 */
class NotificationDeliveryRunContextTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;
    use DeliveryFixtures;

    /** A subject and a body that show the run's context. */
    private const SUBJECT = 'Job #{{ job.id }} of {{ workflow.template_name }}';
    private const BODY = 'Step {{ step.position }} {{ step.name }}: {{ workflow.reason }} ({{ workflow.url }})';

    private ?HttpLoopbackServer $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTestDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        $this->server?->stop();
        parent::tearDown();
    }

    /**
     * Regression: a member of team A saw the workflow, whose only job step
     * was in team A's project, when its job failed; before the worker sent
     * the message, the workflow got a step in team B's project. The member
     * still may see the job, so the message goes out, but without the run's
     * context: in the variables, the rendered subject and body, and the
     * webhook's event payload. The admin, who still sees the workflow, gets
     * it with the context.
     */
    public function testRegressionAMessageLeavesTheRunsContextOutOnceTheWorkflowIsOutOfSight(): void
    {
        $scope = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id);
        $run = (int)$this->createWorkflowJob((int)$workflow->id, (int)$scope['admin']->id)->id;
        $job = (int)$this->createJob((int)$scope['own']->id, (int)$scope['admin']->id, Job::STATUS_FAILED)->id;
        $this->server = new HttpLoopbackServer();
        $hook = $this->server->start();
        $members = $this->jobTemplateOf($scope['member']);
        $membersHook = $this->jobTemplateOf($scope['member'], NotificationTemplate::CHANNEL_WEBHOOK, (string)json_encode(['url' => $hook . '/hook', 'payload' => 'event']));
        $admins = $this->jobTemplateOf($scope['admin']);
        $this->dispatchJobFailed($job, $run);
        foreach ([$members, $membersHook, $admins] as $template) {
            $this->assertSame('deploy-everything', $this->onlyDeliveryOf($template)->getVariableMap()['workflow.template_name'] ?? null, 'kept for each when the event happened');
        }

        $this->createWorkflowStep((int)$workflow->id, 9, WorkflowStep::TYPE_JOB, (int)$scope['foreign']->id);
        foreach ([$members, $membersHook, $admins] as $template) {
            $this->service()->deliver((int)$this->onlyDeliveryOf($template)->id);
            $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->onlyDeliveryOf($template)->status, "template #{$template->id}");
        }

        [$toMember, $toAdmin] = $this->channel->calls;
        $this->assertSame([(int)$members->id, (int)$admins->id], [$toMember['template'], $toAdmin['template']]);
        $this->assertSame([], self::runContextOf($toMember['variables']), 'no context of the run for the member');
        $this->assertSame((string)$job, $toMember['variables']['job.id'] ?? null, 'the job\'s own variables stay');
        $this->assertSame(["Job #{$job} of ", 'Step  :  ()'], [$toMember['subject'], $toMember['body']]);
        $this->assertSame('deploy-everything', $toAdmin['variables']['workflow.template_name'] ?? null, 'the admin still sees the workflow');
        $this->assertSame("Job #{$job} of deploy-everything", $toAdmin['subject']);

        $requests = $this->server->requests();
        $this->assertCount(1, $requests);
        $payload = json_decode($requests[0]['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame((int)$job, $payload['job']['id'] ?? null);
        $this->assertArrayNotHasKey('workflow', $payload, 'the event payload names no run');
        $this->assertArrayNotHasKey('step', $payload);
    }

    /**
     * Regression: the delivery of an admin's template waited for its retry
     * when an operator of team A changed where the template delivers, and
     * so became the user it delivers as. The operator may see the job but
     * not the workflow, a step of which is in team B's project: the retry
     * goes to the new address without the run's context that the
     * dispatcher kept for the admin.
     */
    public function testRegressionARetryAfterTheIdentityChangedLeavesTheRunsContextOut(): void
    {
        $scope = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id, (int)$scope['foreign']->id);
        $run = (int)$this->createWorkflowJob((int)$workflow->id, (int)$scope['admin']->id)->id;
        $job = (int)$this->createJob((int)$scope['own']->id, (int)$scope['admin']->id, Job::STATUS_FAILED)->id;
        $template = $this->jobTemplateOf($scope['admin']);
        $this->dispatchJobFailed($job, $run);
        $delivery = $this->onlyDeliveryOf($template);
        $this->channel->script = [new \RuntimeException('The mail server is busy.')];
        $this->service()->deliver((int)$delivery->id);
        $this->assertSame([NotificationDelivery::STATUS_PENDING, 1], [$this->reload($delivery)->status, (int)$this->reload($delivery)->attempts], 'waits for its retry');
        $this->assertSame('deploy-everything', $this->channel->calls[0]['variables']['workflow.template_name'] ?? null, 'the attempt as the admin');

        $this->deliverAs($template, $scope['member'], '{"emails":["operator@example.com"]}');
        NotificationDelivery::updateAll(['next_attempt_at' => time()], ['id' => $delivery->id]);
        $this->service()->deliver((int)$delivery->id);

        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $this->reload($delivery)->status);
        $this->assertCount(2, $this->channel->calls);
        $retry = $this->channel->calls[1];
        $this->assertSame([], self::runContextOf($retry['variables']), 'no context of the run for the operator');
        $this->assertSame((string)$job, $retry['variables']['job.id'] ?? null);
        $this->assertSame("Job #{$job} of ", $retry['subject']);
        $this->assertSame('deploy-everything', $this->reload($delivery)->getVariableMap()['workflow.template_name'] ?? null, 'the delivery keeps what the dispatcher kept; only what is sent leaves it out');
    }

    /**
     * A message whose identity may still see the run's workflow, and one of
     * a job of no run, go out with their variables as the dispatcher kept
     * them.
     */
    public function testAMessageWhoseIdentitySeesTheWorkflowOrThatNamesNoRunKeepsItsVariables(): void
    {
        $scope = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id);
        $run = (int)$this->createWorkflowJob((int)$workflow->id, (int)$scope['admin']->id)->id;
        $job = (int)$this->createJob((int)$scope['own']->id, (int)$scope['admin']->id, Job::STATUS_FAILED)->id;
        $template = $this->jobTemplateOf($scope['member']);
        $this->dispatchJobFailed($job, $run);
        $loneJob = (int)$this->createJob((int)$scope['own']->id, (int)$scope['admin']->id, Job::STATUS_FAILED)->id;
        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_JOB_FAILED, [
            'job' => ['id' => (string)$loneJob, 'status' => Job::STATUS_FAILED],
            'workflow' => ['template_name' => 'not of a run'],
            NotificationDispatcher::REFS => ['job_id' => $loneJob],
        ]);

        foreach (NotificationDelivery::find()->where(['notification_template_id' => $template->id])->orderBy(['id' => SORT_ASC])->all() as $delivery) {
            $this->service()->deliver((int)$delivery->id);
        }

        $this->assertCount(2, $this->channel->calls);
        $this->assertSame("Job #{$job} of deploy-everything", $this->channel->calls[0]['subject']);
        $this->assertSame('Rollout', $this->channel->calls[0]['variables']['step.name'] ?? null);
        $this->assertSame("Job #{$loneJob} of not of a run", $this->channel->calls[1]['subject'], 'a job of no run is no message of a run');
    }

    /**
     * Dispatches job.failed of job $job, a step of run $run, with the
     * context of the run, as the job's producer builds it.
     */
    private function dispatchJobFailed(int $job, int $run): void
    {
        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_JOB_FAILED, [
            'job' => ['id' => (string)$job, 'status' => Job::STATUS_FAILED],
            'workflow' => ['id' => (string)$run, 'template_name' => 'deploy-everything', 'reason' => 'step 1 failed', 'url' => 'https://ansilume.example.com/workflow-job/view?id=' . $run],
            'step' => ['name' => 'Rollout', 'position' => '1', 'job_id' => (string)$job],
            NotificationDispatcher::REFS => ['workflow_job_id' => $run, 'job_id' => $job],
        ]);
    }

    /**
     * A template of job.failed on $channel that delivers as $user, with a
     * subject and a body that show the run's context.
     */
    private function jobTemplateOf(User $user, string $channel = NotificationTemplate::CHANNEL_EMAIL, string $config = '{"emails":["ops@example.com"]}'): NotificationTemplate
    {
        $template = $this->createNotificationTemplate((int)$user->id, $channel, NotificationTemplate::EVENT_JOB_FAILED, $config);
        $template->subject_template = self::SUBJECT;
        $template->body_template = self::BODY;
        $this->assertTrue($template->save(false));

        return $template;
    }

    /**
     * $user changes the destination of $template to $config, as the edit
     * form saves it: the template delivers as $user from now on.
     */
    private function deliverAs(NotificationTemplate $template, User $user, string $config): void
    {
        $stored = NotificationTemplate::findOne($template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);
        $identity = new NotificationIdentity($stored);
        $stored->config = $config;
        $this->assertTrue($stored->validate(), (string)json_encode($stored->errors));
        $this->assertTrue($identity->changesIdentity());
        $identity->apply((int)$user->id);
        $this->assertTrue($stored->save(false));
    }

    private function onlyDeliveryOf(NotificationTemplate $template): NotificationDelivery
    {
        $rows = NotificationDelivery::find()->where(['notification_template_id' => $template->id])->all();
        $this->assertCount(1, $rows);
        $this->assertInstanceOf(NotificationDelivery::class, $rows[0]);

        return $rows[0];
    }

    private function dispatcher(): NotificationDispatcher
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');

        return $dispatcher;
    }

    /**
     * The variables of the run's context among $variables.
     *
     * @param array<string, string> $variables
     * @return array<string, string>
     */
    private static function runContextOf(array $variables): array
    {
        return array_filter(
            $variables,
            static fn (string $name): bool => preg_match('/^(workflow|step)\./', $name) === 1,
            ARRAY_FILTER_USE_KEY
        );
    }
}
