<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\NotificationTemplateController;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class NotificationTemplateControllerActionTest extends WebControllerTestCase
{
    /** @var NotificationDispatcher|null */
    private $originalDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDispatcher = \Yii::$app->get('notificationDispatcher');
        \Yii::$app->set('notificationDispatcher', new class extends NotificationDispatcher {
            public int $sendCalls = 0;
            /** @var array<string, string> */
            public array $lastVariables = [];
            public string $lastEvent = '';
            public bool $throwOnSend = false;

            public function sendSingle(NotificationTemplate $nt, array $variables, string $event = 'test'): void
            {
                $this->sendCalls++;
                $this->lastVariables = $variables;
                $this->lastEvent = $event;
                if ($this->throwOnSend) {
                    throw new \RuntimeException('test-send-failure');
                }
            }
        });
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

        $ctrl = $this->makeController();
        $result = $ctrl->actionTest((int)$nt->id);

        $this->assertInstanceOf(Response::class, $result);

        /** @var object{sendCalls: int, lastEvent: string, lastVariables: array} $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
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

        $ctrl = $this->makeController();
        $ctrl->actionTest((int)$nt->id);

        /** @var object{lastEvent: string} $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $this->assertSame('runner.offline', $dispatcher->lastEvent);
    }

    public function testTestHandlesSendFailure(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $nt = $this->makeTemplate($user->id);
        $this->setPost([]);

        /** @var object{throwOnSend: bool} $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->throwOnSend = true;

        $ctrl = $this->makeController();
        $result = $ctrl->actionTest((int)$nt->id);

        // Should redirect (not throw) — flash error instead.
        $this->assertInstanceOf(Response::class, $result);
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
        return new class ('notification-template', \Yii::$app) extends NotificationTemplateController {
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

            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                $r = new \yii\web\Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
