<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ProjectController;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\models\ProjectSyncLog;
use app\models\ProjectVaultEntry;
use app\services\ProjectService;
use app\services\VaultOverviewService;
use app\services\VaultScanService;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Exercises ProjectController actions.
 *
 * Stubs ProjectService (to skip the real queueSync) and LintService (to skip
 * the real lint shell-out). The logged-in test user is marked as superadmin
 * so ProjectAccessChecker gives unrestricted access without requiring RBAC
 * assignments, which aren't seeded in the test database.
 */
class ProjectControllerActionTest extends WebControllerTestCase
{
    /** @var list<array{string, \yii\base\Component}> */
    private array $swappedServices = [];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Stub ProjectService so queueSync doesn't push into the queue.
        $this->swapService('projectService', new class extends ProjectService {
            public int $queueSyncCalls = 0;
            public function queueSync(Project $project): void
            {
                $this->queueSyncCalls++;
            }
            public function localPath(Project $project): string
            {
                return '/tmp/nonexistent-test-project';
            }
        });

        // Stub LintService so runForProject doesn't shell out.
        $this->swapService('lintService', new class extends \app\services\LintService {
            public int $runCalls = 0;
            public function runForProject(Project $project): void
            {
                $this->runCalls++;
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->swappedServices as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swappedServices = [];
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    // ── actionIndex() ────────────────────────────────────────────────────────

    public function testIndexRendersDataProviderAsSuperadmin(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $this->createProject($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionIndex();

        $this->assertSame('rendered:index', $result);
        $this->assertInstanceOf(ActiveDataProvider::class, $ctrl->capturedParams['dataProvider']);
    }

    // ── actionView() ─────────────────────────────────────────────────────────

    public function testViewRendersManualProject(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->local_path = '/tmp/nonexistent-manual-project';
        $project->save(false);

        $ctrl = $this->makeController();
        $result = $ctrl->actionView((int)$project->id);

        $this->assertSame('rendered:view', $result);
        $this->assertSame($project->id, $ctrl->capturedParams['model']->id);
        // Nonexistent path → resolveLocalPath returns null → empty playbooks/tree
        $this->assertSame([], $ctrl->capturedParams['playbooks']);
        $this->assertSame([], $ctrl->capturedParams['tree']);
    }

    public function testViewRendersGitProject(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$project->id);

        // Stubbed ProjectService->localPath returns a path that doesn't exist
        // → resolveEffectivePath → null → empty lists.
        $this->assertSame([], $ctrl->capturedParams['playbooks']);
    }

    public function testViewThrowsNotFound(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionView(9999999);
    }

    public function testViewForbiddenForUserWithoutAccess(): void
    {
        $owner = $this->createUser('owner');
        $outsider = $this->createUser('outsider');
        $project = $this->createProject($owner->id);
        // Restrict project to a team — outsider is not a member
        $team = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id);
        $this->loginAs($outsider);

        $ctrl = $this->makeController();
        $this->expectException(ForbiddenHttpException::class);
        $ctrl->actionView((int)$project->id);
    }

    // ── actionCreate() ───────────────────────────────────────────────────────

    public function testCreateRendersFormOnGet(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertInstanceOf(Project::class, $ctrl->capturedParams['model']);
        $this->assertTrue($ctrl->capturedParams['model']->isNewRecord);
        $this->assertArrayHasKey('scmCredentials', $ctrl->capturedParams);
    }

    public function testCreateGitProjectQueuesSync(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);

        $this->setPost([
            'Project' => [
                'name' => 'test-git-project',
                'scm_type' => Project::SCM_TYPE_GIT,
                'scm_url' => 'https://example.com/test.git',
                'scm_branch' => 'main',
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertInstanceOf(Response::class, $result);
        $stored = Project::findOne(['name' => 'test-git-project']);
        $this->assertNotNull($stored);
        $this->assertSame($user->id, $stored->created_by);

        /** @var object{queueSyncCalls: int} $svc */
        $svc = \Yii::$app->get('projectService');
        $this->assertSame(1, $svc->queueSyncCalls);

        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_CREATED, 'object_id' => $stored->id]);
        $this->assertNotNull($audit);
    }

    public function testCreateManualProjectDoesNotQueueSync(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);

        $this->setPost([
            'Project' => [
                'name' => 'test-manual-project',
                'scm_type' => Project::SCM_TYPE_MANUAL,
                'local_path' => '/tmp/example',
            ],
        ]);

        $ctrl = $this->makeController();
        $ctrl->actionCreate();

        /** @var object{queueSyncCalls: int} $svc */
        $svc = \Yii::$app->get('projectService');
        $this->assertSame(0, $svc->queueSyncCalls);
    }

    public function testCreateInvalidInputRendersForm(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);

        $this->setPost(['Project' => ['name' => '']]); // empty name is invalid

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertTrue($ctrl->capturedParams['model']->hasErrors());
    }

    // ── actionUpdate() ───────────────────────────────────────────────────────

    public function testUpdatePersistsChangesAndAudits(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = 'https://example.com/old.git';
        $project->save(false);

        $this->setPost([
            'Project' => [
                'name' => 'updated-name',
                'scm_type' => Project::SCM_TYPE_GIT,
                'scm_url' => 'https://example.com/new.git',
                'scm_branch' => 'main',
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$project->id);

        $this->assertInstanceOf(Response::class, $result);
        /** @var Project $reloaded */
        $reloaded = Project::findOne($project->id);
        $this->assertSame('updated-name', $reloaded->name);
        $this->assertSame('https://example.com/new.git', $reloaded->scm_url);

        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_PROJECT_UPDATED,
            'object_id' => $project->id,
        ]));
    }

    public function testUpdateRendersFormOnGet(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$project->id);

        $this->assertSame('rendered:form', $result);
    }

    // ── actionDelete() ───────────────────────────────────────────────────────

    public function testDeleteRemovesProjectWhenNoTemplates(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete((int)$project->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertNull(Project::findOne($project->id));
        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_PROJECT_DELETED,
            'object_id' => $project->id,
        ]));
    }

    public function testDeleteRefusesWhenTemplatesExist(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $group = $this->createRunnerGroup($user->id);
        $inv = $this->createInventory($user->id);
        $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete((int)$project->id);

        $this->assertInstanceOf(Response::class, $result);
        // Project must still exist.
        $this->assertNotNull(Project::findOne($project->id));
        // Flash must contain the refusal message.
        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertArrayHasKey('danger', $flashes);
    }

    /**
     * Regression: templates are soft-deleted (hidden by JobTemplate::find()),
     * but their rows still reference the project. The old guard counted only
     * visible templates, so the delete reached the database and died with an
     * integrity constraint violation (HTTP 500) — e.g. when removing the Demo
     * project after deleting its templates in the UI.
     */
    public function testDeleteSucceedsWhenOnlySoftDeletedTemplatesRemain(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $group = $this->createRunnerGroup($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);
        $this->assertTrue($template->softDelete());

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete((int)$project->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertNull(Project::findOne($project->id), 'project must be deleted');
        $this->assertNull(
            \app\models\JobTemplate::findWithDeleted()->where(['id' => $template->id])->one(),
            'the soft-deleted template row must be purged with the project'
        );
        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertArrayHasKey('success', $flashes);
        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_PROJECT_DELETED,
            'object_id' => $project->id,
        ]));
    }

    // ── actionSync() ─────────────────────────────────────────────────────────

    public function testSyncQueuesGitProject(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->save(false);

        $ctrl = $this->makeController();
        $result = $ctrl->actionSync((int)$project->id);

        $this->assertInstanceOf(Response::class, $result);
        /** @var object{queueSyncCalls: int} $svc */
        $svc = \Yii::$app->get('projectService');
        $this->assertSame(1, $svc->queueSyncCalls);

        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_PROJECT_SYNCED,
            'object_id' => $project->id,
        ]));
    }

    public function testSyncWarnsForNonGitProject(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionSync((int)$project->id);

        /** @var object{queueSyncCalls: int} $svc */
        $svc = \Yii::$app->get('projectService');
        $this->assertSame(0, $svc->queueSyncCalls);
        $this->assertArrayHasKey('warning', \Yii::$app->session->getAllFlashes());
    }

    // ── actionLint() ─────────────────────────────────────────────────────────

    public function testLintDelegatesToService(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLint((int)$project->id);

        $this->assertInstanceOf(Response::class, $result);
        /** @var object{runCalls: int} $svc */
        $svc = \Yii::$app->get('lintService');
        $this->assertSame(1, $svc->runCalls);
        $this->assertNotNull(AuditLog::findOne([
            'action' => AuditLog::ACTION_PROJECT_LINTED,
            'object_id' => $project->id,
        ]));
    }

    // ── actionUpdate() — additional branches ────────────────────────────────

    public function testUpdateManualProjectDoesNotQueueSync(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->local_path = '/tmp/some-path';
        $project->save(false);

        $this->setPost([
            'Project' => [
                'name' => 'manual-updated',
                'scm_type' => Project::SCM_TYPE_MANUAL,
                'local_path' => '/tmp/other-path',
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$project->id);

        $this->assertInstanceOf(Response::class, $result);
        /** @var object{queueSyncCalls: int} $svc */
        $svc = \Yii::$app->get('projectService');
        $this->assertSame(0, $svc->queueSyncCalls);
        $this->assertArrayHasKey('success', \Yii::$app->session->getAllFlashes());
    }

    public function testUpdateForbiddenForUserWithoutAccess(): void
    {
        $owner = $this->createUser('upd-owner');
        $outsider = $this->createUser('upd-outsider');
        $project = $this->createProject($owner->id);
        $team = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id);
        $this->loginAs($outsider);

        $ctrl = $this->makeController();
        $this->expectException(ForbiddenHttpException::class);
        $ctrl->actionUpdate((int)$project->id);
    }

    public function testUpdateThrowsNotFound(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionUpdate(9999999);
    }

    // ── actionDelete() — additional branches ─────────────────────────────────

    public function testDeleteForbiddenForUserWithoutAccess(): void
    {
        $owner = $this->createUser('del-owner');
        $outsider = $this->createUser('del-outsider');
        $project = $this->createProject($owner->id);
        $team = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id);
        $this->loginAs($outsider);

        $ctrl = $this->makeController();
        $this->expectException(ForbiddenHttpException::class);
        $ctrl->actionDelete((int)$project->id);
    }

    public function testDeleteThrowsNotFound(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionDelete(9999999);
    }

    // ── actionSync() — additional branches ───────────────────────────────────

    public function testSyncForbiddenForUserWithoutAccess(): void
    {
        $owner = $this->createUser('sync-owner');
        $outsider = $this->createUser('sync-outsider');
        $project = $this->createProject($owner->id);
        $team = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id);
        $this->loginAs($outsider);

        $ctrl = $this->makeController();
        $this->expectException(ForbiddenHttpException::class);
        $ctrl->actionSync((int)$project->id);
    }

    public function testSyncThrowsNotFound(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionSync(9999999);
    }

    // ── actionSyncStatus() — JSON polling endpoint ───────────────────────────

    public function testSyncStatusReturnsCurrentStatusAndLogs(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $project->status = Project::STATUS_SYNCING;
        $project->sync_started_at = time() - 5;
        $project->save(false);

        $this->seedSyncLog($project->id, 1, 'cloning into …');
        $this->seedSyncLog($project->id, 2, 'remote: 100% done');

        $ctrl = $this->makeController();
        $result = $ctrl->actionSyncStatus((int)$project->id);

        $this->assertSame((int)$project->id, $result['id']);
        $this->assertTrue($result['is_syncing']);
        $this->assertSame(Project::STATUS_SYNCING, $result['status']);
        $this->assertCount(2, $result['logs']);
        $this->assertSame(1, $result['logs'][0]['sequence']);
        $this->assertStringContainsString('cloning', $result['logs'][0]['content']);
    }

    public function testSyncStatusReturnsWorkerSnapshotShape(): void
    {
        // The worker block lets the polling panel render a "no worker
        // running" warning the operator can act on. Pin the shape so a
        // future refactor can't drop the field silently.
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionSyncStatus((int)$project->id);

        $this->assertArrayHasKey('worker', $result);
        $worker = $result['worker'];
        $this->assertIsBool($worker['alive']);
        $this->assertIsInt($worker['count']);
        $this->assertGreaterThanOrEqual(0, $worker['count']);
        $this->assertArrayHasKey('last_seen_seconds_ago', $worker);
        $this->assertArrayHasKey('oldest_started_seconds_ago', $worker);
        $this->assertSame(120, $worker['stale_after_seconds']);
        $this->assertArrayHasKey('current_app_version', $worker);
        $this->assertArrayHasKey('oldest_app_version', $worker);
        $this->assertArrayHasKey('stale_code', $worker);
        $this->assertIsBool($worker['stale_code']);
    }

    public function testSyncStatusWorkerAliveReflectsHeartbeatPresence(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $ctrl = $this->makeController();

        // Plant a fixture heartbeat directly so we don't depend on the
        // dev queue-worker container being up during tests.
        $key = 'ansilume:worker:phpunit-' . uniqid('', true);
        $redis = $this->connectRedisOrSkip();
        try {
            $redis->setex($key, 120, json_encode([
                'worker_id' => 'phpunit-fixture',
                'pid' => 1,
                'hostname' => 'phpunit',
                'started_at' => time() - 30,
                'seen_at' => time(),
            ]));

            $alive = $ctrl->actionSyncStatus((int)$project->id);
            $this->assertTrue($alive['worker']['alive']);
            $this->assertGreaterThanOrEqual(1, $alive['worker']['count']);
            $this->assertNotNull($alive['worker']['last_seen_seconds_ago']);
            $this->assertNotNull($alive['worker']['oldest_started_seconds_ago']);

            $redis->del($key);

            $dead = $ctrl->actionSyncStatus((int)$project->id);
            // A real dev queue-worker may also be running and writing its
            // own heartbeat — only assert that removing OUR fixture
            // shrinks the count, not that it drops to zero.
            $this->assertLessThanOrEqual($alive['worker']['count'], $dead['worker']['count']);
        } finally {
            try {
                $redis->del($key);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    public function testSyncStatusFlagsStaleCodeWhenWorkerVersionDoesNotMatch(): void
    {
        // The previous time-based threshold gave false positives for any
        // long-running healthy worker. The new contract: `stale_code` is
        // true iff at least one worker's stamped app_version differs from
        // the on-disk version.
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $ctrl = $this->makeController();

        $key = 'ansilume:worker:phpunit-vermismatch-' . uniqid('', true);
        $redis = $this->connectRedisOrSkip();
        try {
            $redis->setex($key, 120, json_encode([
                'worker_id' => 'phpunit-vermismatch',
                'pid' => 2,
                'hostname' => 'phpunit',
                'started_at' => time() - 60,
                'seen_at' => time(),
                'app_version' => '0.0.0-deliberately-stale',
            ]));

            $result = $ctrl->actionSyncStatus((int)$project->id);
            $this->assertTrue($result['worker']['alive']);
            $this->assertTrue(
                $result['worker']['stale_code'],
                'A worker stamped with a different app_version must flip stale_code on.',
            );
        } finally {
            try {
                $redis->del($key);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    public function testSyncStatusFlagsStaleCodeWhenWorkerHasNoVersionStamp(): void
    {
        // Pre-upgrade workers have no app_version field at all. Treat that
        // as stale so the operator gets a one-time nudge to restart them.
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $ctrl = $this->makeController();

        $key = 'ansilume:worker:phpunit-noversion-' . uniqid('', true);
        $redis = $this->connectRedisOrSkip();
        $this->clearWorkerHeartbeats($redis);
        try {
            $redis->setex($key, 120, json_encode([
                'worker_id' => 'phpunit-noversion',
                'pid' => 3,
                'hostname' => 'phpunit',
                'started_at' => time() - 60,
                'seen_at' => time(),
                // app_version intentionally absent
            ]));

            $result = $ctrl->actionSyncStatus((int)$project->id);
            $this->assertTrue($result['worker']['stale_code']);
            $this->assertNull($result['worker']['oldest_app_version']);
        } finally {
            try {
                $redis->del($key);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    public function testStaleCodeSnapshotIgnoresForeignVersionedWorkers(): void
    {
        // Regression (main red v2.3.16 → v2.4.2): the worker snapshot
        // aggregates every `ansilume:worker:*` key, so the live queue-worker
        // container that CI and the dev stack run leaks its own heartbeat —
        // stamped with the current app_version — into this assertion. The
        // no-version stale-code test then read oldest_app_version='2.4.2'
        // instead of null and failed. A snapshot test must own the worker set
        // it asserts over, even when a foreign versioned worker is present.
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $ctrl = $this->makeController();

        $redis = $this->connectRedisOrSkip();
        $foreignKey = 'ansilume:worker:phpunit-foreign-' . uniqid('', true);
        $ourKey = 'ansilume:worker:phpunit-noversion-' . uniqid('', true);
        try {
            // A foreign, versioned worker — exactly what the live queue-worker
            // looks like in CI.
            $redis->setex($foreignKey, 120, json_encode([
                'worker_id' => 'phpunit-foreign',
                'pid' => 99,
                'hostname' => 'phpunit',
                'started_at' => time() - 90,
                'seen_at' => time(),
                'app_version' => '9.9.9-foreign',
            ]));

            // Take ownership of the population, then plant only our no-version
            // fixture so the aggregate reflects a known, isolated set.
            $this->clearWorkerHeartbeats($redis);
            $redis->setex($ourKey, 120, json_encode([
                'worker_id' => 'phpunit-noversion',
                'pid' => 3,
                'hostname' => 'phpunit',
                'started_at' => time() - 60,
                'seen_at' => time(),
                // app_version intentionally absent
            ]));

            $result = $ctrl->actionSyncStatus((int)$project->id);
            $this->assertTrue(
                $result['worker']['stale_code'],
                'A worker with no app_version stamp must flip stale_code on.',
            );
            $this->assertNull(
                $result['worker']['oldest_app_version'],
                'oldest_app_version must reflect only the test-owned worker '
                . 'set, not a foreign/live worker heartbeat.',
            );
        } finally {
            try {
                $redis->del($foreignKey);
                $redis->del($ourKey);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    public function testSyncStatusReportsCurrentAppVersionFromVersionFile(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $ctrl = $this->makeController();

        $result = $ctrl->actionSyncStatus((int)$project->id);
        $current = $result['worker']['current_app_version'];
        $this->assertIsString($current);
        $this->assertNotSame('', $current);
        // Whatever the operator deploys: the snapshot must surface a
        // non-empty version string so the JS banner has something to name.
    }

    // ── stuck-worker detection ────────────────────────────────────────────────

    public function testSyncStatusExposesStuckThresholdAndQueueDepth(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $ctrl = $this->makeController();

        $result = $ctrl->actionSyncStatus((int)$project->id);
        $w = $result['worker'];
        $this->assertSame(300, $w['stuck_threshold_seconds']);
        $this->assertIsInt($w['queue_depth']);
        $this->assertGreaterThanOrEqual(0, $w['queue_depth']);
        $this->assertArrayHasKey('last_job_processed_seconds_ago', $w);
        $this->assertArrayHasKey('is_stuck', $w);
        $this->assertIsBool($w['is_stuck']);
    }

    public function testSyncStatusFlagsStuckWhenWorkerNeverProcessedAndQueueHasDepth(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $ctrl = $this->makeController();

        $redis = $this->connectRedisOrSkip();
        $hbKey = 'ansilume:worker:phpunit-noprog-' . uniqid('', true);
        $waitingKey = 'ansilume-test-queue.waiting';
        $sentinel = 'phpunit-fixture-' . uniqid('', true);

        try {
            $redis->setex($hbKey, 120, json_encode([
                'worker_id' => 'phpunit-noprog',
                'pid' => 1,
                'hostname' => 'phpunit',
                'started_at' => time() - 600,
                'seen_at' => time(),
                'app_version' => \app\helpers\AppVersion::current(),
                'last_job_processed_at' => null,
            ]));
            $redis->lPush($waitingKey, $sentinel);

            $result = $ctrl->actionSyncStatus((int)$project->id);
            $this->assertGreaterThanOrEqual(1, $result['worker']['queue_depth']);
            $this->assertTrue($result['worker']['is_stuck']);
        } finally {
            try {
                $redis->del($hbKey);
                $redis->lRem($waitingKey, $sentinel, 0);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    public function testSyncStatusNotStuckWhenWorkerProcessedRecently(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $ctrl = $this->makeController();

        $redis = $this->connectRedisOrSkip();
        $hbKey = 'ansilume:worker:phpunit-fresh-' . uniqid('', true);
        $waitingKey = 'ansilume-test-queue.waiting';
        $sentinel = 'phpunit-fixture-' . uniqid('', true);

        try {
            $redis->setex($hbKey, 120, json_encode([
                'worker_id' => 'phpunit-fresh',
                'pid' => 2,
                'hostname' => 'phpunit',
                'started_at' => time() - 600,
                'seen_at' => time(),
                'app_version' => \app\helpers\AppVersion::current(),
                'last_job_processed_at' => time() - 10,
            ]));
            $redis->lPush($waitingKey, $sentinel);

            $result = $ctrl->actionSyncStatus((int)$project->id);
            $this->assertFalse($result['worker']['is_stuck']);
            $this->assertNotNull($result['worker']['last_job_processed_seconds_ago']);
            $this->assertLessThanOrEqual(30, $result['worker']['last_job_processed_seconds_ago']);
        } finally {
            try {
                $redis->del($hbKey);
                $redis->lRem($waitingKey, $sentinel, 0);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    public function testSyncStatusNotStuckWhenQueueIsEmpty(): void
    {
        // Even a worker that hasn't processed anything for hours is fine
        // when there's nothing to process. is_stuck must require BOTH
        // signals so a quiet system never trips the warning.
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $ctrl = $this->makeController();

        $redis = $this->connectRedisOrSkip();
        $hbKey = 'ansilume:worker:phpunit-quiet-' . uniqid('', true);

        try {
            $redis->setex($hbKey, 120, json_encode([
                'worker_id' => 'phpunit-quiet',
                'pid' => 3,
                'hostname' => 'phpunit',
                'started_at' => time() - 86400,
                'seen_at' => time(),
                'app_version' => \app\helpers\AppVersion::current(),
                'last_job_processed_at' => time() - 7200, // 2h ago
            ]));

            // No job pushed for THIS test — but a parallel test or the dev
            // queue-worker might have items in flight. Assert relative to
            // the depth we observe: stuck iff depth>0 AND last_job stale,
            // and last_job here is 7200s which is >> threshold, so the
            // verdict comes down to depth.
            $result = $ctrl->actionSyncStatus((int)$project->id);
            if ($result['worker']['queue_depth'] === 0) {
                $this->assertFalse($result['worker']['is_stuck']);
            } else {
                $this->markTestSkipped('Concurrent queue activity made depth>0; is_stuck=true is correct then.');
            }
        } finally {
            try {
                $redis->del($hbKey);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    private function connectRedisOrSkip(): \Redis
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('phpredis extension not loaded.');
        }
        try {
            return \app\components\RedisSettings::fromEnvironment($_ENV)->connectPhpRedis(new \Redis());
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }
    }

    /**
     * Drop every worker heartbeat so the test owns the population it asserts
     * over.
     *
     * The snapshot aggregates ALL `ansilume:worker:*` keys (see
     * {@see \app\components\WorkerHeartbeat::all()}), so the live queue-worker
     * container that both CI and the dev stack run leaks its own heartbeat —
     * stamped with the current on-disk app_version — into assertions like
     * `oldest_app_version === null`. That foreign heartbeat is what kept the
     * integration suite (and therefore main) red from v2.3.16 onward. Clearing
     * the namespace immediately before planting our fixtures makes the
     * aggregate deterministic regardless of any concurrently running worker.
     */
    private function clearWorkerHeartbeats(\Redis $redis): void
    {
        $keys = $redis->keys('ansilume:worker:*');
        foreach ($keys as $key) {
            $redis->del($key);
        }
    }

    public function testSyncStatusFiltersBySinceSequence(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);

        $this->seedSyncLog($project->id, 1, 'old');
        $this->seedSyncLog($project->id, 2, 'old');
        $this->seedSyncLog($project->id, 3, 'fresh');

        $ctrl = $this->makeController();
        $result = $ctrl->actionSyncStatus((int)$project->id, 2);

        $this->assertCount(1, $result['logs']);
        $this->assertSame(3, $result['logs'][0]['sequence']);
    }

    public function testSyncStatusForbiddenForUserWithoutAccess(): void
    {
        $owner = $this->createUser('status-owner');
        $outsider = $this->createUser('status-outsider');
        $project = $this->createProject($owner->id);
        $team = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id);
        $this->loginAs($outsider);

        $ctrl = $this->makeController();
        $this->expectException(ForbiddenHttpException::class);
        $ctrl->actionSyncStatus((int)$project->id);
    }

    private function seedSyncLog(int $projectId, int $sequence, string $content): void
    {
        $row = new ProjectSyncLog();
        $row->project_id = $projectId;
        $row->sequence = $sequence;
        $row->stream = ProjectSyncLog::STREAM_STDOUT;
        $row->content = $content;
        $row->created_at = time();
        $row->save(false);
    }

    // ── actionLint() — additional branches ───────────────────────────────────

    public function testLintForbiddenForUserWithoutAccess(): void
    {
        $owner = $this->createUser('lint-owner');
        $outsider = $this->createUser('lint-outsider');
        $project = $this->createProject($owner->id);
        $team = $this->createTeam($owner->id);
        $this->createTeamProject($team->id, $project->id);
        $this->loginAs($outsider);

        $ctrl = $this->makeController();
        $this->expectException(ForbiddenHttpException::class);
        $ctrl->actionLint((int)$project->id);
    }

    public function testLintThrowsNotFound(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionLint(9999999);
    }

    // ── resolveLocalPath — existing directory branches ───────────────────────

    public function testViewManualProjectWithExistingDirectory(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->local_path = '/tmp';
        $project->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$project->id);

        // /tmp exists and is a directory, so resolveLocalPath returns it.
        // The scanner runs on /tmp and finds files — we just verify it doesn't crash.
        $this->assertIsArray($ctrl->capturedParams['playbooks']);
        $this->assertIsArray($ctrl->capturedParams['tree']);
    }

    public function testViewManualProjectWithYiiAliasPath(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->local_path = '@runtime';
        $project->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$project->id);

        // @runtime resolves via Yii::getAlias and the directory exists.
        $this->assertIsArray($ctrl->capturedParams['playbooks']);
        $this->assertIsArray($ctrl->capturedParams['tree']);
    }

    // ── Vault: overview on the project page ─────────────────────────────────

    public function testViewPassesTheVaultOverviewOfTheProject(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);
        $project->local_path = '/tmp/nonexistent-manual-project';
        $project->vault_scanned_at = 1700000000;
        $project->vault_scan_summary = (string)json_encode(['cfg' => ['vault_password_file' => '.vault_pass'], 'findings' => [], 'files_scanned' => 2]);
        $project->save(false);
        $entry = new ProjectVaultEntry();
        $entry->project_id = (int)$project->id;
        $entry->path = 'inventories/prod/group_vars/all/vault.yml';
        $entry->kind = ProjectVaultEntry::KIND_FILE;
        $entry->vault_id = 'prod';
        $entry->format_version = '1.2';
        $entry->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$project->id);

        /** @var VaultOverviewService $overview */
        $overview = \Yii::$app->get('vaultOverviewService');
        // A superadmin sees every detail.
        $this->assertSame($overview->forProject($this->reloadProject($project), static fn (): bool => true), $ctrl->capturedParams['vault']);
        $vault = $ctrl->capturedParams['vault'];
        $this->assertIsArray($vault);
        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $vault['password_source']);
        $this->assertSame(1700000000, $vault['scanned_at']);
        $this->assertSame(['prod'], $vault['vault_ids']);
        $this->assertSame('.vault_pass', $vault['repository_settings']['vault_password_file']);
    }

    /**
     * Regression: the vault card showed runner names, groups and versions
     * and the names of vault passwords to anyone who may see the project.
     * They follow runner-group.view and credential.view or job-template.view.
     */
    public function testTheVaultOverviewOnThePageFollowsThePermissionsOfTheViewer(): void
    {
        $user = $this->createUser('vault-card');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('vault-card-' . uniqid());
        $auth->add($role);
        $projectView = $auth->getPermission('project.view');
        $this->assertNotNull($projectView);
        $auth->addChild($role, $projectView);
        $auth->assign($role, (string)$user->id);
        $this->loginAs($user);
        $project = $this->createProject((int)$user->id);
        $project->vault_scanned_at = 1700000000;
        $project->save(false);
        $group = $this->createRunnerGroup((int)$user->id);
        $template = $this->createJobTemplate((int)$project->id, (int)$this->createInventory((int)$user->id)->id, (int)$group->id, (int)$user->id);
        $vault = $this->createCredential((int)$user->id, Credential::TYPE_VAULT);
        (new JobTemplateVaultCheck([
            'job_template_id' => $template->id,
            'status' => JobTemplateVaultCheck::STATUS_OK,
            'credential_id' => $vault->id,
            'relevant_count' => 1,
            'checked_at' => 1700000100,
            'scanned_at' => 1700000000,
        ]))->save(false);
        $this->createRunner((int)$group->id, (int)$user->id);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$project->id);

        $vaultCard = $ctrl->capturedParams['vault'];
        $this->assertIsArray($vaultCard);
        $this->assertSame([], $vaultCard['runners_without_support']);
        $this->assertSame(1, $vaultCard['runners_without_support_count']);
        $this->assertSame(['id' => (int)$vault->id, 'name' => null], $vaultCard['templates'][0]['credential']);
    }

    // ── Vault: password source changes on update ────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function vaultSourceChangeProvider(): array
    {
        return [
            'to Ansilume and repository' => [
                Project::VAULT_SOURCE_ANSILUME,
                Project::VAULT_SOURCE_REPOSITORY,
                JobTemplateVaultCheck::STATUS_MISSING_PASSWORD,
                JobTemplateVaultCheck::STATUS_REPO_MANAGED,
            ],
            'back to Ansilume only' => [
                Project::VAULT_SOURCE_REPOSITORY,
                Project::VAULT_SOURCE_ANSILUME,
                JobTemplateVaultCheck::STATUS_REPO_MANAGED,
                JobTemplateVaultCheck::STATUS_MISSING_PASSWORD,
            ],
        ];
    }

    /**
     * The fixture repository's ansible.cfg brings a vault password file: in
     * 'Ansilume and repository' mode a template without a vault password is
     * fine (the repository supplies one), in 'Ansilume only' mode it is not.
     * Switching the mode is audited and re-checks the templates at once.
     *
     * @dataProvider vaultSourceChangeProvider
     */
    public function testUpdateAuditsAVaultSourceChangeAndRechecksTheTemplates(string $from, string $to, string $statusBefore, string $statusAfter): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        [$project, $template, $root] = $this->scannedVaultProject($user->id, $from);
        $this->assertSame($statusBefore, $this->vaultCheckStatus($template));

        $this->setPost(['Project' => [
            'name' => $project->name,
            'scm_type' => Project::SCM_TYPE_MANUAL,
            'local_path' => $root,
            'vault_password_source' => $to,
        ]]);
        $result = $this->makeController()->actionUpdate((int)$project->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame($to, $this->reloadProject($project)->vault_password_source);
        $this->assertSame(
            [['user_id' => (int)$user->id, 'metadata' => ['name' => $project->name, 'from' => $from, 'to' => $to]]],
            $this->vaultSourceAudits($project)
        );
        $this->assertSame($statusAfter, $this->vaultCheckStatus($template), 'the templates are re-checked against the new mode');
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function unchangedVaultSourceProvider(): array
    {
        return [
            'the same value posted' => [true],
            'the field not posted' => [false],
        ];
    }

    /**
     * No source change, no audit entry. A manual project never syncs, so
     * saving it scans its files again, and that re-checks the templates.
     *
     * @dataProvider unchangedVaultSourceProvider
     */
    public function testUpdateWithoutAVaultSourceChangeDoesNotAuditButRescansAManualProject(bool $postTheField): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        [$project, $template, $root] = $this->scannedVaultProject($user->id, Project::VAULT_SOURCE_ANSILUME);
        // A sentinel no real check would store: a re-check would replace it.
        JobTemplateVaultCheck::updateAll(['status' => JobTemplateVaultCheck::STATUS_STALE, 'checked_at' => 1], ['job_template_id' => $template->id]);

        $fields = ['name' => 'renamed-' . uniqid(), 'scm_type' => Project::SCM_TYPE_MANUAL, 'local_path' => $root];
        if ($postTheField) {
            $fields['vault_password_source'] = Project::VAULT_SOURCE_ANSILUME;
        }
        $this->setPost(['Project' => $fields]);
        $this->makeController()->actionUpdate((int)$project->id);

        $this->assertSame($fields['name'], $this->reloadProject($project)->name, 'the update itself went through');
        $this->assertSame([], $this->vaultSourceAudits($project));
        $check = JobTemplateVaultCheck::findOne($template->id);
        $this->assertNotNull($check);
        $this->assertNotSame(JobTemplateVaultCheck::STATUS_STALE, $check->status, 'the save rescanned the manual project');
        $this->assertGreaterThan(1, (int)$check->checked_at);
    }

    /**
     * Manual projects never sync, so the scan runs when they are saved:
     * without it their templates would never get a vault check.
     */
    public function testCreatingAManualProjectScansItsFiles(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $root = sys_get_temp_dir() . '/ansilume-project-vault-' . bin2hex(random_bytes(6));
        $this->tempDirs[] = $root;
        $this->copyTree(dirname(__DIR__, 2) . '/fixtures/vault/repo', $root);
        $name = 'manual-vault-' . uniqid();
        $this->setPost(['Project' => ['name' => $name, 'scm_type' => Project::SCM_TYPE_MANUAL, 'local_path' => $root]]);

        $this->makeController()->actionCreate();

        $project = Project::findOne(['name' => $name]);
        $this->assertNotNull($project);
        $this->assertNotNull($project->vault_scanned_at);
        $this->assertNull($project->vault_scan_error);
        $this->assertCount(7, $project->vaultEntries);
    }

    public function testCreatingAGitProjectWaitsForItsFirstSync(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $name = 'git-vault-' . uniqid();
        $this->setPost(['Project' => [
            'name' => $name,
            'scm_type' => Project::SCM_TYPE_GIT,
            'scm_url' => 'https://git.example.com/ops/playbooks.git',
            'scm_branch' => 'main',
        ]]);

        $this->makeController()->actionCreate();

        $project = Project::findOne(['name' => $name]);
        $this->assertNotNull($project);
        $this->assertNull($project->vault_scanned_at);
        $this->assertNull($project->vault_scan_error);
    }

    public function testUpdateRejectsAnUnknownVaultSource(): void
    {
        $user = $this->createSuperadmin();
        $this->loginAs($user);
        $project = $this->createProject($user->id);

        $this->setPost(['Project' => [
            'name' => $project->name,
            'scm_type' => Project::SCM_TYPE_MANUAL,
            'vault_password_source' => 'ansible-cfg-only',
        ]]);
        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$project->id);

        $this->assertSame('rendered:form', $result);
        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(Project::class, $model);
        $this->assertSame(['Vault Password Source is invalid.'], $model->getErrors('vault_password_source'));
        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $this->reloadProject($project)->vault_password_source);
        $this->assertSame([], $this->vaultSourceAudits($project));
        $this->assertNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_UPDATED, 'object_id' => $project->id]));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createSuperadmin(string $suffix = ''): \app\models\User
    {
        $u = $this->createUser($suffix);
        $u->is_superadmin = 1;
        $u->save(false);
        return $u;
    }

    private function reloadProject(Project $project): Project
    {
        $fresh = Project::findOne($project->id);
        $this->assertNotNull($fresh);
        return $fresh;
    }

    /**
     * A manual project on a private copy of the fixture repository
     * (tests/fixtures/vault/repo), scanned, with one template that loads
     * encrypted files (site.yml on the dev inventory) and has no vault
     * password.
     *
     * @return array{0: Project, 1: JobTemplate, 2: string}
     */
    private function scannedVaultProject(int $userId, string $source): array
    {
        $root = sys_get_temp_dir() . '/ansilume-project-vault-' . bin2hex(random_bytes(6));
        $this->tempDirs[] = $root;
        $this->copyTree(dirname(__DIR__, 2) . '/fixtures/vault/repo', $root);
        $project = $this->createProject($userId);
        $project->local_path = $root;
        $project->vault_password_source = $source;
        $project->save(false);
        $inventory = $this->createInventory($userId);
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->content = null;
        $inventory->source_path = 'inventories/dev/hosts.yml';
        $inventory->project_id = (int)$project->id;
        $inventory->save(false);
        $template = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$this->createRunnerGroup($userId)->id, $userId);
        /** @var VaultScanService $scans */
        $scans = \Yii::$app->get('vaultScanService');
        $this->assertTrue($scans->scanProject($this->reloadProject($project)));

        return [$this->reloadProject($project), $template, $root];
    }

    private function vaultCheckStatus(JobTemplate $template): ?string
    {
        return JobTemplateVaultCheck::findOne($template->id)?->status;
    }

    /**
     * @return list<array{user_id: int|null, metadata: mixed}>
     */
    private function vaultSourceAudits(Project $project): array
    {
        $logs = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_PROJECT_VAULT_SOURCE_CHANGED, 'object_type' => 'project', 'object_id' => $project->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return array_map(static fn (AuditLog $log): array => [
            'user_id' => $log->user_id === null ? null : (int)$log->user_id,
            'metadata' => json_decode((string)$log->metadata, true),
        ], $logs);
    }

    private function copyTree(string $source, string $target): void
    {
        mkdir($target, 0755, true);
        foreach ((array)scandir($source) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $from = $source . '/' . $name;
            if (is_dir($from)) {
                $this->copyTree($from, $target . '/' . $name);
            } else {
                copy($from, $target . '/' . $name);
            }
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array)scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function swapService(string $id, \yii\base\Component $replacement): void
    {
        /** @var \yii\base\Component $original */
        $original = \Yii::$app->get($id);
        $this->swappedServices[] = [$id, $original];
        \Yii::$app->set($id, $replacement);
    }

    private function makeController(): ProjectController
    {
        return new class ('project', \Yii::$app) extends ProjectController {
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
