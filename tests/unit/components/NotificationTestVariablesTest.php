<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\NotificationTestVariables;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\WorkflowJob;
use app\services\notification\NotificationEventPayload;
use app\services\notification\NotificationOutbox;
use PHPUnit\Framework\TestCase;

/**
 * What a test notification sends, from the web UI and from the API alike:
 * the template's first event, else 'test', and the sample values of the
 * variables that notifications carry, as the web's Send Test sent them
 * before 2.12.0 moved them here. That both test sends use them,
 * NotificationTestSendParityTest shows.
 */
class NotificationTestVariablesTest extends TestCase
{
    /** The variables whose sample values are the time of the send, formatted. */
    private const TIMES = ['timestamp'];

    private mixed $appBaseUrl = null;

    private bool $hadAppBaseUrl = false;

    protected function setUp(): void
    {
        $this->hadAppBaseUrl = array_key_exists('appBaseUrl', \Yii::$app->params);
        $this->appBaseUrl = \Yii::$app->params['appBaseUrl'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadAppBaseUrl) {
            \Yii::$app->params['appBaseUrl'] = $this->appBaseUrl;
        } else {
            unset(\Yii::$app->params['appBaseUrl']);
        }
    }

    /**
     * @return array<string, array{0: string|null, 1: string}> the template's events, and the event of its test
     */
    public static function eventsProvider(): array
    {
        return [
            'the first of its events' => ['approval.requested,job.failed', 'approval.requested'],
            'the first, trimmed' => [' job.failed , job.succeeded', 'job.failed'],
            'one event' => ['runner.offline', 'runner.offline'],
            'no events' => ['', 'test'],
            'events never set' => [null, 'test'],
            'separators only' => [' , ', 'test'],
        ];
    }

    /**
     * @dataProvider eventsProvider
     */
    public function testATestIsSentForTheTemplatesFirstEventElseTest(?string $events, string $event): void
    {
        $template = new NotificationTemplate();
        $template->events = $events;

        $this->assertSame($event, NotificationTestVariables::eventOf($template));
        $this->assertSame('test', NotificationTestVariables::DEFAULT_EVENT);
    }

    /**
     * Regression: the samples of job.status and workflow.status were
     * "successful", a status no job and no run has, so a template that
     * tests a status ("succeeded") or a channel that colours by it (Teams)
     * showed in Send Test what no real event sends.
     */
    public function testRegressionTheSampleStatusesAreStatusesTheirObjectsHave(): void
    {
        $variables = NotificationTestVariables::of(NotificationTemplate::EVENT_JOB_SUCCEEDED, 'alice');

        $this->assertContains($variables['job.status'] ?? null, Job::statuses());
        $this->assertContains($variables['workflow.status'] ?? null, WorkflowJob::statuses());
        $this->assertContains($variables['project.status'] ?? null, [Project::STATUS_NEW, Project::STATUS_SYNCING, Project::STATUS_SYNCED, Project::STATUS_ERROR]);
    }

    /**
     * Every sample value, in its order: the event and its severity, the
     * caller as launched_by, the links under the configured base URL, the
     * times of the send, the approval of a job that its inventory's rule
     * gates, and the run of that job's step, which waits for the approval
     * (the run's context, RunContext).
     */
    public function testTheSampleValuesOfEveryVariable(): void
    {
        \Yii::$app->params['appBaseUrl'] = 'https://ansilume.example.com';
        $before = time();
        $variables = NotificationTestVariables::of('approval.requested', 'alice');
        $after = time();

        $this->assertSame([
            'event' => 'approval.requested',
            'severity' => 'warning',
            'event_id' => $variables['event_id'] ?? null,
            'occurred_at' => $variables['occurred_at'] ?? null,
            'timestamp' => $variables['timestamp'] ?? null,
            'app.url' => 'https://ansilume.example.com',
            'job.id' => '0',
            'job.status' => 'succeeded',
            'job.exit_code' => '0',
            'job.duration' => '1m 23s',
            'job.url' => 'https://ansilume.example.com/job/view?id=0',
            'job.template_id' => '0',
            'job.inventory_id' => '0',
            'job.inventory_name' => 'Production hosts',
            'template.id' => '0',
            'template.name' => 'Example Playbook',
            'project.id' => '0',
            'project.name' => 'Example Project',
            'project.status' => 'synced',
            'project.error' => '',
            'launched_by' => 'alice',
            'runner.id' => '1',
            'runner.name' => 'runner-1',
            'runner.last_seen_at' => $variables['runner.last_seen_at'] ?? null,
            'workflow.id' => '0',
            'workflow.status' => 'succeeded',
            'workflow.template_id' => '0',
            'workflow.template_name' => 'Example Workflow',
            'workflow.url' => 'https://ansilume.example.com/workflow-job/view?id=0',
            'workflow.steps_done' => '1',
            'workflow.steps_total' => '3',
            'workflow.reason' => 'Job #0 of step "Deploy" waits for approval.',
            'step.id' => '0',
            'step.job_id' => '0',
            'step.execution_id' => '0',
            'step.workflow_step_id' => '0',
            'step.position' => '2',
            'step.name' => 'Deploy',
            'step.type' => 'job',
            'step.waiting_for' => 'approval',
            'schedule.id' => '0',
            'schedule.name' => 'Nightly Deploy',
            'schedule.cron' => '0 2 * * *',
            'schedule.error' => 'Template not found',
            'approval.id' => '0',
            'approval.status' => 'approved',
            'approval.rule_id' => '0',
            'approval.rule_name' => 'Production Gate',
            'approval.required_by' => 'inventory',
            'approval.url' => 'https://ansilume.example.com/approval/view?id=0',
            'trigger.token_prefix' => 'abc123',
            'trigger.ip' => '192.168.1.100',
            'trigger.user_agent' => 'curl/8.0',
            'trigger.note' => sprintf(NotificationOutbox::THROTTLE_NOTE, 5),
        ], $variables);
        foreach (self::TIMES as $name) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \S+$/', $variables[$name], $name);
        }
        $this->assertTimeBetween($before, $after, $variables['timestamp'], 'timestamp is the time of the send');
        $this->assertMatchesRegularExpression('/^\d+$/', $variables['runner.last_seen_at'], 'a Unix time, as the runner events carry it');
        $this->assertGreaterThanOrEqual($before - 300, (int)$variables['runner.last_seen_at'], 'the runner was last seen five minutes before');
        $this->assertLessThanOrEqual($after - 300, (int)$variables['runner.last_seen_at']);
        $this->assertMatchesRegularExpression('/^\d+$/', $variables['occurred_at']);
        $this->assertGreaterThanOrEqual($before, (int)$variables['occurred_at'], 'occurred_at is the time of the send, as a Unix time');
        $this->assertLessThanOrEqual($after, (int)$variables['occurred_at']);
    }

    /**
     * Regression: the sample runner.last_seen_at was a formatted date, while
     * the runner events carry a Unix time (HealthController), so the event
     * payload of a test of a webhook template with payload event
     * (NotificationEventPayload) showed the runner's last_seen_at as null.
     * It is the Unix time of five minutes before the send now, as a real
     * runner.offline carries it.
     */
    public function testRegressionTheRunnerOfATestWasLastSeenAtNull(): void
    {
        $before = time();
        $variables = NotificationTestVariables::of(NotificationTemplate::EVENT_RUNNER_OFFLINE, 'alice');

        $runner = NotificationEventPayload::build(NotificationTemplate::EVENT_RUNNER_OFFLINE, $variables, [])['runner'] ?? null;

        $this->assertIsArray($runner);
        $this->assertSame(1, $runner['id'] ?? null);
        $this->assertIsInt($runner['last_seen_at'] ?? null, 'the payload shows when the runner was last seen');
        $this->assertGreaterThanOrEqual($before - 300, $runner['last_seen_at']);
        $this->assertLessThanOrEqual(time() - 300, $runner['last_seen_at']);
    }

    /**
     * Every test is an event of its own, as every event the dispatcher
     * queues: a receiver that skips an event ID it saw before still takes
     * the next test.
     */
    public function testEveryTestHasAnEventIdOfItsOwn(): void
    {
        $first = NotificationTestVariables::of('job.failed', 'alice')['event_id'];
        $second = NotificationTestVariables::of('job.failed', 'alice')['event_id'];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $second);
        $this->assertNotSame($first, $second);
    }

    /**
     * The severity follows the event: a test of a template without events
     * is 'info'.
     */
    public function testTheSeverityFollowsTheEvent(): void
    {
        $this->assertSame('critical', NotificationTestVariables::of('job.failed', 'alice')['severity']);
        $this->assertSame('info', NotificationTestVariables::of(NotificationTestVariables::DEFAULT_EVENT, 'alice')['severity']);
        $this->assertSame(NotificationTestVariables::DEFAULT_EVENT, NotificationTestVariables::of('test', 'alice')['event']);
    }

    /**
     * Without a configured base URL, the links point at localhost; the
     * callers name a test without a signed-in user NO_USER.
     */
    public function testWithoutABaseUrlTheLinksPointAtLocalhost(): void
    {
        unset(\Yii::$app->params['appBaseUrl']);

        $variables = NotificationTestVariables::of('job.failed', NotificationTestVariables::NO_USER);

        $this->assertSame('http://localhost', $variables['app.url']);
        $this->assertSame('http://localhost/job/view?id=0', $variables['job.url']);
        $this->assertSame('system', $variables['launched_by']);
    }

    private function assertTimeBetween(int $from, int $to, string $shown, string $message): void
    {
        $time = strtotime($shown);
        $this->assertIsInt($time, $message);
        $this->assertGreaterThanOrEqual($from, $time, $message);
        $this->assertLessThanOrEqual($to, $time, $message);
    }
}
