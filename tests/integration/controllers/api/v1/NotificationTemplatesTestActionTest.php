<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\NotificationTemplatesController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use app\services\notification\ChannelInterface;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\RestoresComponents;

/**
 * POST /api/v1/notification-templates/{id}/test through runAction(), as an
 * operator's token: {sent: true} when the channel accepted the message, 502
 * with the channel's error when it did not, 500 for an error outside the
 * delivery, never with the message of a database error.
 */
class NotificationTemplatesTestActionTest extends WebControllerTestCase
{
    use RestoresComponents;

    protected function setUp(): void
    {
        parent::setUp();
        // Tests replace it; tearDown() puts back only what setUp() kept.
        $this->keepComponent('notificationDispatcher');
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    public function testAMessageTheChannelAcceptedAnswersSent(): void
    {
        $nt = $this->templateOf($this->authenticateAsOperator());
        $this->installDispatcherWith(new class () implements ChannelInterface {
            public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
            {
            }
        });

        $result = $this->runTest((int)$nt->id);

        $this->assertSame(['data' => ['sent' => true]], $result);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(1, $this->auditCount(AuditLog::ACTION_NOTIFICATION_DISPATCHED, $nt));
        $this->assertSame(0, $this->auditCount(AuditLog::ACTION_NOTIFICATION_FAILED, $nt));
    }

    /**
     * Regression: a failing test notification was reported as sent. The
     * dispatcher swallowed the channel's error and the API answered
     * {sent: true}; now it answers 502 with the error, which the
     * notification.failed audit entry carries too.
     */
    public function testRegressionAFailingTestNotificationWasReportedAsSent(): void
    {
        $nt = $this->templateOf($this->authenticateAsOperator());
        $this->installDispatcherWith(new class () implements ChannelInterface {
            public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
            {
                throw new \RuntimeException('WebhookChannel: HTTP 503 from https://hooks.example.com/notify');
            }
        });

        $result = $this->runTest((int)$nt->id);

        $error = 'WebhookChannel: HTTP 503 from https://hooks.example.com/notify';
        $this->assertSame(['error' => ['message' => 'Test failed: ' . $error]], $result);
        $this->assertSame(['error' => ['message' => sprintf(NotificationTemplatesController::TEST_FAILED, $error)]], $result);
        $this->assertSame('Test failed: %s', NotificationTemplatesController::TEST_FAILED);
        $this->assertSame(502, \Yii::$app->response->statusCode);
        $this->assertSame(0, $this->auditCount(AuditLog::ACTION_NOTIFICATION_DISPATCHED, $nt));
        $this->assertSame(1, $this->auditCount(AuditLog::ACTION_NOTIFICATION_FAILED, $nt));
    }

    /**
     * A channel's database error answers 502 with its class, SQLSTATE and
     * driver error code only, as its audit entry says it.
     */
    public function testAChannelsDatabaseErrorAnswers502WithoutItsStatement(): void
    {
        $nt = $this->templateOf($this->authenticateAsOperator());
        $this->installDispatcherWith(new class () implements ChannelInterface {
            public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
            {
                throw new \yii\db\Exception(
                    "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\n"
                        . "The SQL being executed was: SELECT * FROM `user` WHERE `email`='marker-4711'",
                    ['HY000', 2006, 'MySQL server has gone away']
                );
            }
        });

        $result = $this->runTest((int)$nt->id);

        $this->assertSame(
            ['error' => ['message' => 'Test failed: yii\db\Exception (SQLSTATE HY000, driver error 2006)']],
            $result
        );
        $this->assertSame(502, \Yii::$app->response->statusCode);
    }

    public function testATemplateWithAnUnknownChannelAnswers502(): void
    {
        $nt = $this->templateOf($this->authenticateAsOperator());
        $nt->channel = 'carrier-pigeon';
        $nt->save(false);
        \Yii::$app->set('notificationDispatcher', new NotificationDispatcher());

        $result = $this->runTest((int)$nt->id);

        $this->assertSame(
            ['error' => ['message' => 'Test failed: ' . sprintf(NotificationDispatcher::UNKNOWN_CHANNEL, 'carrier-pigeon')]],
            $result
        );
        $this->assertSame(502, \Yii::$app->response->statusCode);
    }

    /**
     * An error outside the delivery answers 500 with its message.
     */
    public function testAnErrorOutsideTheDeliveryAnswers500(): void
    {
        $nt = $this->templateOf($this->authenticateAsOperator());
        $this->installThrowingDispatcher(new \RuntimeException('Template renderer is unavailable.'));

        $result = $this->runTest((int)$nt->id);

        $this->assertSame(['error' => ['message' => 'Test failed: Template renderer is unavailable.']], $result);
        $this->assertSame(500, \Yii::$app->response->statusCode);
    }

    /**
     * Regression: a database error outside the delivery answered 500 with
     * its message, which carries the SQL statement with its values.
     */
    public function testRegressionADatabaseErrorOfATestAnsweredWithItsStatement(): void
    {
        $nt = $this->templateOf($this->authenticateAsOperator());
        $this->installThrowingDispatcher(new \yii\db\Exception(
            "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\n"
                . "The SQL being executed was: INSERT INTO `audit_log` (`metadata`) VALUES ('marker-4711')",
            ['HY000', 2006, 'MySQL server has gone away']
        ));

        $result = $this->runTest((int)$nt->id);

        $this->assertStringNotContainsString('marker-4711', (string)json_encode($result));
        $this->assertSame(
            ['error' => ['message' => 'Test failed: yii\db\Exception (SQLSTATE HY000, driver error 2006)']],
            $result
        );
        $this->assertSame(500, \Yii::$app->response->statusCode);
    }

    public function testAMissingTemplateIs404(): void
    {
        $this->authenticateAsOperator();

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->runTest(99999999);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null the action's answer
     */
    private function runTest(int $id): ?array
    {
        /** @var \yii\base\Module $app the test application */
        $app = \Yii::$app;
        /** @var array<string, mixed>|null $result */
        $result = (new NotificationTemplatesController('api/v1/notification-templates', $app))
            ->runAction('test', ['id' => $id]);

        return $result;
    }

    private function authenticateAsOperator(): int
    {
        $user = $this->createUser('nt-test');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole('operator');
        $this->assertNotNull($role);
        $auth->assign($role, (string)$user->id);
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'nt-test-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);

        return (int)$user->id;
    }

    private function templateOf(int $userId): NotificationTemplate
    {
        $nt = $this->createNotificationTemplate($userId);
        $this->assertSame(NotificationTemplate::CHANNEL_EMAIL, $nt->channel);

        return $nt;
    }

    private function installDispatcherWith(ChannelInterface $channel): void
    {
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, $channel);
        \Yii::$app->set('notificationDispatcher', $dispatcher);
    }

    /**
     * The dispatcher cannot even be created: an error outside the delivery.
     */
    private function installThrowingDispatcher(\Throwable $error): void
    {
        \Yii::$app->set('notificationDispatcher', static function () use ($error): never {
            throw $error;
        });
    }

    private function auditCount(string $action, NotificationTemplate $nt): int
    {
        return (int)AuditLog::find()->where(['action' => $action, 'object_id' => $nt->id])->count();
    }
}
