<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\NotificationTemplateController;
use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use app\services\notification\ChannelInterface;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class NotificationTemplateControllerActionTest extends WebControllerTestCase
{
    /** @var object|null the dispatcher the test replaced */
    private ?object $originalDispatcher = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDispatcher = \Yii::$app->get('notificationDispatcher');
    }

    protected function tearDown(): void
    {
        \Yii::$app->set('notificationDispatcher', $this->originalDispatcher);
        $this->originalDispatcher = null;
        parent::tearDown();
    }

    // ── actionTest() ─────────────────────────────────────────────────────────

    public function testTestSendsNotificationAndRedirects(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $nt = $this->makeTemplate($user->id);
        $this->setPost([]);
        $dispatcher = $this->stubDispatcher();

        $ctrl = $this->makeController();
        $result = $ctrl->actionTest((int)$nt->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(['success' => NotificationTemplateController::TEST_SENT], \Yii::$app->session->getAllFlashes());
        $this->assertSame('Test notification sent successfully.', NotificationTemplateController::TEST_SENT);

        $this->assertSame(1, $dispatcher->sendCalls);
        $this->assertSame('job.failed', $dispatcher->lastEvent);
        $this->assertArrayHasKey('job.id', $dispatcher->lastVariables);
        $this->assertArrayHasKey('template.name', $dispatcher->lastVariables);
        $this->assertArrayHasKey('project.name', $dispatcher->lastVariables);
        $this->assertArrayHasKey('runner.name', $dispatcher->lastVariables);
        $this->assertArrayHasKey('workflow.template_name', $dispatcher->lastVariables);
        $this->assertArrayHasKey('schedule.name', $dispatcher->lastVariables);
        $this->assertArrayHasKey('approval.rule_name', $dispatcher->lastVariables);
        $this->assertArrayHasKey('event', $dispatcher->lastVariables);
        $this->assertArrayHasKey('severity', $dispatcher->lastVariables);
        $this->assertArrayHasKey('timestamp', $dispatcher->lastVariables);
        $this->assertArrayHasKey('app.url', $dispatcher->lastVariables);
    }

    public function testTestUsesFirstEventFromTemplate(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $nt = $this->makeTemplate($user->id, 'runner.offline,runner.recovered');
        $this->setPost([]);
        $dispatcher = $this->stubDispatcher();

        $ctrl = $this->makeController();
        $ctrl->actionTest((int)$nt->id);

        $this->assertSame('runner.offline', $dispatcher->lastEvent);
    }

    /**
     * The dispatcher's answer for a message the channel did not accept is
     * the flash's reason, as a danger flash (the old type 'error' rendered
     * as the unstyled class alert-error).
     */
    public function testAFailedDeliveryShowsTheChannelsErrorInADangerFlash(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $nt = $this->makeTemplate($user->id);
        $this->setPost([]);
        $dispatcher = $this->stubDispatcher();
        $dispatcher->result = 'SlackChannel: HTTP 404 — no_service';

        $result = $this->makeController()->actionTest((int)$nt->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(
            ['danger' => 'Test notification failed: SlackChannel: HTTP 404 — no_service'],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertSame('Test notification failed: %s', NotificationTemplateController::TEST_FAILED);
    }

    /**
     * An error outside the delivery (here the dispatcher itself) is a
     * danger flash too, with its message.
     */
    public function testTestHandlesSendFailure(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $nt = $this->makeTemplate($user->id);
        $this->setPost([]);

        $dispatcher = $this->stubDispatcher();
        $dispatcher->throwOnSend = new \RuntimeException('test-send-failure');

        $ctrl = $this->makeController();
        $result = $ctrl->actionTest((int)$nt->id);

        // Should redirect (not throw) — a danger flash instead.
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(
            ['danger' => sprintf(NotificationTemplateController::TEST_FAILED, 'test-send-failure')],
            \Yii::$app->session->getAllFlashes()
        );
    }

    /**
     * Regression: a database error outside the delivery reached the flash
     * with its message, which carries the SQL statement with its values.
     * The flash names its class, SQLSTATE and driver error code only.
     */
    public function testRegressionADatabaseErrorOfATestReachedTheFlashWithItsStatement(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $nt = $this->makeTemplate($user->id);
        $this->setPost([]);
        $error = new \yii\db\Exception(
            "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\n"
                . "The SQL being executed was: INSERT INTO `audit_log` (`metadata`) VALUES ('marker-4711')",
            ['HY000', 2006, 'MySQL server has gone away']
        );
        // The dispatcher cannot even be created: an error outside the delivery.
        \Yii::$app->set('notificationDispatcher', static function () use ($error): never {
            throw $error;
        });

        $this->makeController()->actionTest((int)$nt->id);

        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertStringNotContainsString('marker-4711', (string)json_encode($flashes));
        $this->assertSame(
            ['danger' => 'Test notification failed: yii\db\Exception (SQLSTATE HY000, driver error 2006)'],
            $flashes
        );
    }

    /**
     * Regression: a failing test notification was reported as sent. The
     * dispatcher swallowed the channel's error, so the page said "sent
     * successfully" and only the notification.failed audit entry and the
     * log knew. Now the flash says why it was not sent.
     */
    public function testRegressionAFailingTestNotificationWasReportedAsSent(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $nt = $this->makeTemplate($user->id);
        $this->setPost([]);
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, new class () implements ChannelInterface {
            public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
            {
                throw new \RuntimeException('Connection could not be established with host "mailhog:1025".');
            }
        });
        \Yii::$app->set('notificationDispatcher', $dispatcher);

        $this->makeController()->actionTest((int)$nt->id);

        $error = 'Connection could not be established with host "mailhog:1025".';
        $this->assertSame(['danger' => 'Test notification failed: ' . $error], \Yii::$app->session->getAllFlashes(), 'no success flash');
        $this->assertSame(
            ['danger' => sprintf(NotificationTemplateController::TEST_FAILED, $error)],
            \Yii::$app->session->getAllFlashes()
        );
        $failure = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_NOTIFICATION_FAILED, 'object_id' => $nt->id])
            ->one();
        $this->assertInstanceOf(AuditLog::class, $failure);
        $context = json_decode((string)$failure->metadata, true);
        $this->assertIsArray($context);
        $this->assertSame($error, $context['error'] ?? null, 'the flash says what the audit entry says');
    }

    /**
     * A template whose channel no handler sends (a stored value the model
     * no longer accepts) is not sent either: the flash names the channel.
     */
    public function testATemplateWithAnUnknownChannelIsReportedAsNotSent(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $nt = $this->makeTemplate($user->id);
        $nt->channel = 'carrier-pigeon';
        $nt->save(false);
        $this->setPost([]);
        \Yii::$app->set('notificationDispatcher', new NotificationDispatcher());

        $this->makeController()->actionTest((int)$nt->id);

        $this->assertSame(
            ['danger' => "Test notification failed: Unknown channel 'carrier-pigeon'"],
            \Yii::$app->session->getAllFlashes()
        );
    }

    public function testTestThrowsNotFoundForMissingTemplate(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionTest(9999999);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Install a dispatcher that records its calls instead of sending.
     */
    private function stubDispatcher(): ScriptedNotificationDispatcher
    {
        $stub = new ScriptedNotificationDispatcher();
        \Yii::$app->set('notificationDispatcher', $stub);

        return $stub;
    }

    private function makeTemplate(int $userId, string $events = 'job.failed,job.succeeded'): NotificationTemplate
    {
        $nt = new NotificationTemplate();
        $nt->name = 'test-nt-' . uniqid();
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->config = '{"emails":["test@example.com"]}';
        $nt->events = $events;
        $nt->subject_template = '[Test] {{ event }}';
        $nt->body_template = 'Event: {{ event }}';
        $nt->created_by = $userId;
        $nt->save(false);
        return $nt;
    }

    private function makeController(): NotificationTemplateController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return new class ('notification-template', $app) extends NotificationTemplateController {
            public string $capturedView = '';
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            /**
             * @param string|array<int|string, mixed> $url
             */
            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                $r = new \yii\web\Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
