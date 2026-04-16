<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobTemplateController;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\services\JobLaunchService;
use app\services\LintService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Exercises JobTemplateController actions.
 *
 * Stubs LintService (no shell-out) and JobLaunchService (no runner dispatch).
 */
class JobTemplateControllerActionTest extends WebControllerTestCase
{
    /** @var list<array{string, \yii\base\Component}> */
    private array $swappedServices = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->swapService('lintService', new class extends LintService {
            public int $runCalls = 0;
            public function runForTemplate(JobTemplate $template): void
            {
                $this->runCalls++;
            }
        });

        $this->swapService('jobLaunchService', new class extends JobLaunchService {
            public int $launchCalls = 0;
            public bool $throwOnLaunch = false;
            public function launch(JobTemplate $template, int $userId, array $overrides = []): Job
            {
                $this->launchCalls++;
                if ($this->throwOnLaunch) {
                    throw new \RuntimeException('test-launch-failure');
                }
                $j = new Job();
                $j->job_template_id = $template->id;
                $j->launched_by = $userId;
                $j->status = Job::STATUS_QUEUED;
                $j->timeout_minutes = 120;
                $j->has_changes = 0;
                $j->queued_at = time();
                $j->created_at = time();
                $j->updated_at = time();
                $j->save(false);
                return $j;
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->swappedServices as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swappedServices = [];
        parent::tearDown();
    }

    // ── actionIndex() ────────────────────────────────────────────────────────

    public function testIndexRendersDataProvider(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->makeTemplate($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionIndex();

        $this->assertSame('rendered:index', $result);
        $this->assertInstanceOf(ActiveDataProvider::class, $ctrl->capturedParams['dataProvider']);
    }

    // ── actionView() ─────────────────────────────────────────────────────────

    public function testViewRendersModel(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionView((int)$tpl->id);

        $this->assertSame('rendered:view', $result);
        $this->assertSame($tpl->id, $ctrl->capturedParams['model']->id);
    }

    public function testViewThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionView(9999999);
    }

    // ── actionCreate() ───────────────────────────────────────────────────────

    public function testCreateRendersFormOnGetWithDefaults(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        /** @var JobTemplate $model */
        $model = $ctrl->capturedParams['model'];
        $this->assertSame(0, $model->verbosity);
        $this->assertSame(5, $model->forks);
        $this->assertSame(120, $model->timeout_minutes);
        $this->assertFalse((bool)$model->become);
        $this->assertArrayHasKey('projects', $ctrl->capturedParams);
        $this->assertArrayHasKey('inventories', $ctrl->capturedParams);
        $this->assertArrayHasKey('credentials', $ctrl->capturedParams);
        $this->assertArrayHasKey('runnerGroups', $ctrl->capturedParams);
    }

    public function testCreateWithPrefillFromQuery(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);

        $ctrl = $this->makeController();
        $ctrl->actionCreate((int)$project->id, 'deploy.yml');

        /** @var JobTemplate $model */
        $model = $ctrl->capturedParams['model'];
        $this->assertSame($project->id, $model->project_id);
        $this->assertSame('deploy.yml', $model->playbook);
    }

    public function testCreatePersistsAndAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);

        $this->setPost([
            'JobTemplate' => [
                'name' => 'tpl-created',
                'project_id' => $project->id,
                'inventory_id' => $inventory->id,
                'runner_group_id' => $group->id,
                'playbook' => 'site.yml',
                'verbosity' => 0,
                'forks' => 5,
                'timeout_minutes' => 60,
                'become' => 0,
                'become_method' => 'sudo',
                'become_user' => 'root',
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertInstanceOf(Response::class, $result);
        $stored = JobTemplate::findOne(['name' => 'tpl-created']);
        $this->assertNotNull($stored);
        $this->assertSame($user->id, (int)$stored->created_by);

        /** @var object{runCalls: int} $lint */
        $lint = \Yii::$app->get('lintService');
        $this->assertSame(1, $lint->runCalls);

        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_TEMPLATE_CREATED,
            'object_id' => $stored->id,
        ]));
    }

    public function testCreateInvalidInputRendersForm(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $this->setPost(['JobTemplate' => ['name' => '']]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertTrue($ctrl->capturedParams['model']->hasErrors());
    }

    // ── actionUpdate() ───────────────────────────────────────────────────────

    public function testUpdatePersistsChanges(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $this->setPost([
            'JobTemplate' => [
                'name' => 'renamed',
                'project_id' => $tpl->project_id,
                'inventory_id' => $tpl->inventory_id,
                'runner_group_id' => $tpl->runner_group_id,
                'playbook' => 'site.yml',
                'verbosity' => 1,
                'forks' => 10,
                'timeout_minutes' => 120,
                'become' => 0,
                'become_method' => 'sudo',
                'become_user' => 'root',
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$tpl->id);

        $this->assertInstanceOf(Response::class, $result);
        $reloaded = JobTemplate::findOne($tpl->id);
        $this->assertSame('renamed', $reloaded->name);
        $this->assertSame(10, (int)$reloaded->forks);

        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_TEMPLATE_UPDATED,
            'object_id' => $tpl->id,
        ]));
    }

    public function testUpdateRendersFormOnGet(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$tpl->id);

        $this->assertSame('rendered:form', $result);
        $this->assertSame($tpl->id, $ctrl->capturedParams['model']->id);
    }

    // ── actionDelete() ───────────────────────────────────────────────────────

    public function testDeleteSoftDeletesAndAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);
        $id = (int)$tpl->id;

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete($id);

        $this->assertInstanceOf(Response::class, $result);
        // softDelete sets deleted_at; findModel() uses findOne which respects default scope if any.
        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_TEMPLATE_DELETED,
            'object_id' => $id,
        ]));
    }

    public function testDeleteThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionDelete(9999999);
    }

    // ── actionLaunch() ───────────────────────────────────────────────────────

    public function testLaunchRendersFormOnGet(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $this->setQueryParams(['id' => (string)$tpl->id]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLaunch();

        $this->assertSame('rendered:launch', $result);
        $this->assertSame($tpl->id, $ctrl->capturedParams['template']->id);
    }

    public function testLaunchQueuesJobOnPost(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $this->setQueryParams(['id' => (string)$tpl->id]);
        $this->setPost([
            'overrides' => ['limit' => 'localhost'],
            'survey' => ['env' => 'staging'],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLaunch();

        $this->assertInstanceOf(Response::class, $result);
        /** @var object{launchCalls: int} $svc */
        $svc = \Yii::$app->get('jobLaunchService');
        $this->assertSame(1, $svc->launchCalls);
    }

    /**
     * Regression: dashboard quick-launch form sends id via POST body,
     * not as a GET parameter (GitHub #9).
     */
    public function testLaunchAcceptsIdFromPostBody(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $this->setQueryParams([]);
        $this->setPost(['id' => (string)$tpl->id]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLaunch();

        $this->assertInstanceOf(Response::class, $result);
        /** @var object{launchCalls: int} $svc */
        $svc = \Yii::$app->get('jobLaunchService');
        $this->assertSame(1, $svc->launchCalls);
    }

    public function testLaunchHandlesRuntimeException(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        /** @var object{throwOnLaunch: bool} $svc */
        $svc = \Yii::$app->get('jobLaunchService');
        $svc->throwOnLaunch = true;

        $this->setQueryParams(['id' => (string)$tpl->id]);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLaunch();

        // Falls through to render('launch') after catching the exception.
        $this->assertSame('rendered:launch', $result);
        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertArrayHasKey('danger', $flashes);
    }

    public function testLaunchThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->setQueryParams(['id' => '9999999']);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionLaunch();
    }

    // ── actionGenerateTriggerToken() / actionRevokeTriggerToken() ───────────

    public function testGenerateTriggerTokenAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionGenerateTriggerToken((int)$tpl->id);

        $this->assertInstanceOf(Response::class, $result);
        $reloaded = JobTemplate::findOne($tpl->id);
        $this->assertNotEmpty($reloaded->trigger_token);
        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED,
            'object_id' => $tpl->id,
        ]));

        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertArrayHasKey('trigger_token_raw', $flashes);

        // The flashed raw token must match the stored hash and must NOT be
        // what is stored on the row — a DB dump must not reveal the trigger URL.
        $rawToken = (string)$flashes['trigger_token_raw'];
        $this->assertNotSame($rawToken, $reloaded->trigger_token);
        $this->assertSame(hash('sha256', $rawToken), $reloaded->trigger_token);
    }

    public function testRevokeTriggerTokenAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);
        $tpl->generateTriggerToken();

        $ctrl = $this->makeController();
        $result = $ctrl->actionRevokeTriggerToken((int)$tpl->id);

        $this->assertInstanceOf(Response::class, $result);
        $reloaded = JobTemplate::findOne($tpl->id);
        $this->assertEmpty($reloaded->trigger_token);
        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED,
            'object_id' => $tpl->id,
        ]));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeTemplate(int $userId): JobTemplate
    {
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        return $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $userId);
    }

    private function swapService(string $id, \yii\base\Component $replacement): void
    {
        /** @var \yii\base\Component $original */
        $original = \Yii::$app->get($id);
        $this->swappedServices[] = [$id, $original];
        \Yii::$app->set($id, $replacement);
    }

    private function makeController(): JobTemplateController
    {
        return new class ('job-template', \Yii::$app) extends JobTemplateController {
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
