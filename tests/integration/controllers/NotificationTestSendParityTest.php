<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\NotificationTemplatesController;
use app\controllers\NotificationTemplateController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\NotificationDispatcher;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\RecordingChannel;
use app\tests\integration\TeamScopeFixtures;
use Symfony\Component\Yaml\Yaml;
use yii\base\Module;
use yii\web\Response;

/**
 * The test send of a notification template sends the same from the web
 * UI's Send Test and from POST /api/v1/notification-templates/{id}/test:
 * the sample variables of NotificationTestVariables, for the template's
 * first event, else 'test', which the audit entry of each send names; and
 * the spec says so.
 */
class NotificationTestSendParityTest extends WebControllerTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;

    /** The variables whose sample values are the time of the send. */
    private const TIMES = ['timestamp', 'runner.last_seen_at'];

    /** The OpenAPI path of the API's test send. */
    private const TEST_PATH = '/api/v1/notification-templates/{id}/test';

    private RecordingChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new RecordingChannel();
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, $this->channel);
        $this->swapComponent('notificationDispatcher', $dispatcher);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string}> the template's events, and the event both tests are sent for
     */
    public static function eventsProvider(): array
    {
        return [
            'the first of its events' => ['approval.requested,job.failed', 'approval.requested'],
            'a template without events' => ['', 'test'],
        ];
    }

    /**
     * regression: POST /api/v1/notification-templates/{id}/test sent nine
     * sample variables of its own, without event, severity, app.url and
     * the approval, workflow, schedule, runner and trigger ones, and so
     * without the inventory and approval.required_by of 2.12.0, which only
     * the web's Send Test carried; and it sent for the event 'test' where
     * the web sent for the template's first event. A template that uses
     * them could not be tried through the API. Both send the same now.
     *
     * @dataProvider eventsProvider
     */
    public function testRegressionTheApiTestSendCarriedOtherVariablesThanTheWebOne(string $events, string $event): void
    {
        $user = $this->createUserWithRole('nt-parity', 'operator');
        $template = $this->template((int)$user->id, $events);

        $this->loginAs($user);
        $web = $this->webController()->actionTest((int)$template->id);
        $this->bearerOf($user);
        $api = $this->apiTest((int)$template->id);

        $this->assertInstanceOf(Response::class, $web);
        $this->assertSame(['success' => NotificationTemplateController::TEST_SENT], \Yii::$app->session->getAllFlashes());
        $this->assertSame(['data' => ['sent' => true]], $api);
        $this->assertCount(2, $this->channel->calls, 'each test send sent one message');
        [$fromWeb, $fromApi] = $this->channel->calls;
        $this->assertSame(array_keys($fromWeb['variables']), array_keys($fromApi['variables']), 'both send the same variables, in the same order');
        $this->assertSame($this->withoutTimes($fromWeb['variables']), $this->withoutTimes($fromApi['variables']), 'both send the same sample values');
        foreach (self::TIMES as $name) {
            $this->assertEqualsWithDelta(strtotime($fromWeb['variables'][$name]), strtotime($fromApi['variables'][$name]), 5, "{$name} is the time of each send");
        }
        $this->assertSame($fromWeb['subject'], $fromApi['subject'], 'both render the same subject');
        $this->assertSame($fromWeb['body'], $fromApi['body'], 'both render the same body');
        $this->assertSame([$event, $event], $this->auditedEvents($template), 'both audit entries name the event of the test');
        $this->assertSame($event, $fromApi['variables']['event']);
        $this->assertSame((string)$user->username, $fromApi['variables']['launched_by'], 'launched_by is the caller');
        $this->assertSame('inventory Production hosts', $fromApi['subject']);
    }

    /**
     * The spec of the API's test send says that it sends the sample
     * variables of the web UI's Send Test, for the template's first event.
     */
    public function testTheSpecSaysTheApiSendsTheSampleVariablesOfTheWebsSendTest(): void
    {
        $spec = Yaml::parseFile($this->specPath());
        $this->assertIsArray($spec);
        $description = $spec['paths'][self::TEST_PATH]['post']['description'] ?? null;
        $this->assertIsString($description, 'web/openapi.yaml describes POST ' . self::TEST_PATH);
        $text = trim((string)preg_replace('/\s+/', ' ', $description));

        $this->assertStringContainsString("Sends one test message through the template's channel, with the sample variables of the web UI's Send Test:", $text);
        $this->assertStringContainsString('`approval.required_by`, `job.inventory_id` and `job.inventory_name`; `launched_by` is the caller.', $text);
        $this->assertStringContainsString("The event is the template's first event, else `test`, as in the web UI.", $text);
    }

    /**
     * The spec file under test.
     */
    protected function specPath(): string
    {
        return dirname(__DIR__, 3) . '/web/openapi.yaml';
    }

    private function template(int $userId, string $events): NotificationTemplate
    {
        $template = new NotificationTemplate();
        $template->name = 'nt-parity-' . uniqid('', true);
        $template->channel = NotificationTemplate::CHANNEL_EMAIL;
        $template->config = '{"emails":["parity@example.com"]}';
        $template->events = $events;
        $template->subject_template = '{{ approval.required_by }} {{ job.inventory_name }}';
        $template->body_template = '{{ event }} ({{ severity }}) of {{ template.name }} in {{ project.name }} by {{ launched_by }}: {{ job.url }}';
        $template->created_by = $userId;
        $template->save(false);

        return $template;
    }

    private function webController(): NotificationTemplateController
    {
        return new class ('notification-template', $this->app()) extends NotificationTemplateController {
            /**
             * @param string|array<int|string, mixed> $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }

    /**
     * POST /api/v1/notification-templates/{id}/test, as the web application
     * runs it: its answer.
     *
     * @return array<string, mixed>|null
     */
    private function apiTest(int $id): ?array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        /** @var array<string, mixed>|null $answer */
        $answer = (new NotificationTemplatesController('api/v1/notification-templates', $this->app()))
            ->runAction('test', ['id' => $id]);

        return $answer;
    }

    /**
     * The next API calls carry a new API token of $user.
     */
    private function bearerOf(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'nt-parity-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    /**
     * @param array<string, string> $variables
     * @return array<string, string> $variables without the times of the send
     */
    private function withoutTimes(array $variables): array
    {
        return array_diff_key($variables, array_flip(self::TIMES));
    }

    /**
     * The events that the notification.dispatched entries of $template
     * name, in order.
     *
     * @return list<mixed>
     */
    private function auditedEvents(NotificationTemplate $template): array
    {
        $entries = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_DISPATCHED, 'object_type' => 'notification_template', 'object_id' => (int)$template->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return array_map(
            static fn (AuditLog $entry): mixed => json_decode((string)$entry->metadata, true)['event'] ?? null,
            $entries
        );
    }

    private function app(): Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return $app;
    }
}
