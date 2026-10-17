<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\NotificationTestVariables;
use app\models\NotificationTemplate;
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
    /** The variables whose sample values are the time of the send. */
    private const TIMES = ['timestamp', 'runner.last_seen_at'];

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
     * Every sample value, in its order: the event and its severity, the
     * caller as launched_by, the links under the configured base URL, the
     * times of the send, and the approval of a job that its inventory's
     * rule gates.
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
            'timestamp' => $variables['timestamp'] ?? null,
            'app.url' => 'https://ansilume.example.com',
            'job.id' => '0',
            'job.status' => 'successful',
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
            'workflow.status' => 'successful',
            'workflow.template_id' => '0',
            'workflow.template_name' => 'Example Workflow',
            'schedule.id' => '0',
            'schedule.name' => 'Nightly Deploy',
            'schedule.cron' => '0 2 * * *',
            'schedule.error' => 'Template not found',
            'approval.id' => '0',
            'approval.status' => 'approved',
            'approval.rule_id' => '0',
            'approval.rule_name' => 'Production Gate',
            'approval.required_by' => 'inventory',
            'trigger.token_prefix' => 'abc123',
            'trigger.ip' => '192.168.1.100',
            'trigger.user_agent' => 'curl/8.0',
        ], $variables);
        foreach (self::TIMES as $name) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \S+$/', $variables[$name], $name);
        }
        $this->assertTimeBetween($before, $after, $variables['timestamp'], 'timestamp is the time of the send');
        $this->assertTimeBetween($before - 300, $after - 300, $variables['runner.last_seen_at'], 'the runner was last seen five minutes before');
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
