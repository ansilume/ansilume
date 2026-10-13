<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\commands\E2eDeletedTemplateApprovalSeeder;
use app\controllers\E2eController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\services\MaintenanceService;
use yii\caching\CacheInterface;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The YII_DEBUG hook POST /e2e/create-deleted-template-request, which makes
 * a job of the soft-deleted e2e-deleted-gate-template that waits for
 * approval, with its pending request, right before
 * tests/e2e/tests/approvals/deleted-template.spec.ts needs it. Each request
 * goes through runAction(), as a real request does.
 */
class E2eControllerDeletedTemplateRequestTest extends WebControllerTestCase
{
    private bool $hadDebugEnv = false;
    private mixed $savedDebugEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadDebugEnv = array_key_exists('YII_DEBUG', $_ENV);
        $this->savedDebugEnv = $_ENV['YII_DEBUG'] ?? null;
        $_ENV['YII_DEBUG'] = 'true';
        $this->setPost([]);
    }

    protected function tearDown(): void
    {
        if ($this->hadDebugEnv) {
            $_ENV['YII_DEBUG'] = $this->savedDebugEnv;
        } else {
            unset($_ENV['YII_DEBUG']);
        }
        $cache = \Yii::$app->cache;
        $this->assertInstanceOf(CacheInterface::class, $cache);
        $cache->delete(MaintenanceService::DELETED_TEMPLATE_SWEEP_KEY);
        parent::tearDown();
    }

    public function testTheHookAnswers404WhenYiiDebugIsOff(): void
    {
        $_ENV['YII_DEBUG'] = 'false';

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->runAction('create-deleted-template-request', ['launcher' => 'e2e-operator']);
    }

    public function testALauncherWithoutTheE2ePrefixIsRefusedBeforeAnythingIsWritten(): void
    {
        $jobs = (int)Job::find()->count();

        $response = $this->controller()->runAction('create-deleted-template-request', ['launcher' => 'admin']);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(400, $response->statusCode);
        $this->assertSame(['error' => 'non-e2e launcher name'], $response->data);
        $this->assertSame($jobs, (int)Job::find()->count());
    }

    public function testWithoutTheSeedTheHookAnswers404WithTheReason(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage(sprintf(E2eDeletedTemplateApprovalSeeder::NOT_SEEDED, E2eDeletedTemplateApprovalSeeder::TEMPLATE));

        $this->controller()->runAction('create-deleted-template-request', ['launcher' => 'e2e-operator']);
    }

    /**
     * Seeded: the hook answers the IDs of the job and its request; the
     * launcher defaults to e2e-operator.
     */
    public function testSeededTheHookAnswersTheWaitingJobAndItsPendingRequest(): void
    {
        $admin = $this->createUser('admin');
        $operator = $this->createUser('operator');
        $operator->username = 'e2e-operator';
        $operator->save(false);
        $parent = $this->createJobTemplate(
            (int)$this->createProject((int)$admin->id)->id,
            (int)$this->createInventory((int)$admin->id)->id,
            (int)$this->createRunnerGroup((int)$admin->id)->id,
            (int)$admin->id
        );
        $rule = $this->createApprovalRule((int)$admin->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [(int)$admin->id]]));
        (new E2eDeletedTemplateApprovalSeeder(static function (string $msg): void {
        }))->seed((int)$admin->id, (int)$parent->id, (int)$rule->id);

        $response = $this->controller()->runAction('create-deleted-template-request');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($response->data);
        $this->assertSame(['job_id', 'request_id'], array_keys($response->data));
        $job = Job::findOne($response->data['job_id']);
        $this->assertNotNull($job);
        $this->assertSame((int)$operator->id, (int)$job->launched_by);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status);
        $request = ApprovalRequest::findOne($response->data['request_id']);
        $this->assertNotNull($request);
        $this->assertSame((int)$job->id, (int)$request->job_id);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
    }

    private function controller(): E2eController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return new E2eController('e2e', $app);
    }
}
