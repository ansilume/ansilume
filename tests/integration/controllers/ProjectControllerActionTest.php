<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ProjectController;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Project;
use app\models\ProjectSyncLog;
use app\services\ProjectService;
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

    private function connectRedisOrSkip(): \Redis
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('phpredis extension not loaded.');
        }
        $redis = new \Redis();
        try {
            $redis->connect($_ENV['REDIS_HOST'] ?? 'redis', (int)($_ENV['REDIS_PORT'] ?? 6379));
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }
        return $redis;
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

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createSuperadmin(string $suffix = ''): \app\models\User
    {
        $u = $this->createUser($suffix);
        $u->is_superadmin = 1;
        $u->save(false);
        return $u;
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
