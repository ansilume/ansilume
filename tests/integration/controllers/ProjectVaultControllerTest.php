<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ProjectVaultController;
use app\models\AuditLog;
use app\models\Project;
use app\models\ProjectVaultEntry;
use app\models\TeamProject;
use app\models\User;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * "Rescan" on the project page (POST project-vault/scan): rescans the
 * checkout for vault content, audits it as project.vault-scanned and returns
 * to the vault card. The access filter needs project.update; the action also
 * needs operator access to the project, so team viewers and other teams are
 * refused and nothing is scanned.
 */
class ProjectVaultControllerTest extends WebControllerTestCase
{
    /** A manual project without a local path: never a checkout. */
    private const NO_CHECKOUT = 'This manual project has no local path, so there is nothing to scan. Set its local path in the project settings.';
    private const NO_OPERATE = 'You do not have permission to modify this project.';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        // $_SESSION outlives a test: start without the flashes of earlier tests.
        \Yii::$app->session->removeAllFlashes();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    // -- actionScan -----------------------------------------------------------

    public function testScanRescansTheCheckoutAuditsAndReturnsToTheVaultCard(): void
    {
        $operator = $this->loginWithRole('operator');
        $project = $this->project((int)$operator->id, $this->copyFixtureRepo());
        $this->entry($project, 'gone/from-the-last-scan.yml');
        $before = time();

        $ctrl = $this->makeController();
        $result = $ctrl->actionScan((int)$project->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(['/project/view', 'id' => (int)$project->id, '#' => 'vault'], $ctrl->capturedRedirect);
        $this->assertSame(['success' => 'Vault files rescanned.'], \Yii::$app->session->getAllFlashes());
        $project->refresh();
        $this->assertGreaterThanOrEqual($before, (int)$project->vault_scanned_at);
        $this->assertNull($project->vault_scan_error);
        $paths = array_map(static fn (ProjectVaultEntry $entry): string => $entry->path, $project->vaultEntries);
        $this->assertCount(7, $paths);
        $this->assertContains('inventories/prod/group_vars/all/vault.yml', $paths);
        $this->assertNotContains('gone/from-the-last-scan.yml', $paths);
        $this->assertSame([
            ['user_id' => (int)$operator->id, 'metadata' => ['name' => $project->name, 'scanned' => true, 'source' => 'web']],
        ], $this->scanAudits($project));
    }

    public function testScanWithoutACheckoutWarnsAndIsStillAudited(): void
    {
        $operator = $this->loginWithRole('operator');
        $project = $this->project((int)$operator->id, null);
        $this->entry($project, 'stale.yml');

        $ctrl = $this->makeController();
        $ctrl->actionScan((int)$project->id);

        $this->assertSame(['/project/view', 'id' => (int)$project->id, '#' => 'vault'], $ctrl->capturedRedirect);
        $this->assertSame(['warning' => 'Vault files not scanned: ' . self::NO_CHECKOUT], \Yii::$app->session->getAllFlashes());
        $project->refresh();
        $this->assertSame(self::NO_CHECKOUT, $project->vault_scan_error);
        $this->assertNull($project->vault_scanned_at);
        $this->assertSame([], $project->vaultEntries, 'entries of an old scan must not outlive the checkout');
        $this->assertSame([
            ['user_id' => (int)$operator->id, 'metadata' => ['name' => $project->name, 'scanned' => false, 'source' => 'web']],
        ], $this->scanAudits($project));
    }

    public function testScanOfAMissingProjectIsNotFound(): void
    {
        $this->loginWithRole('operator');
        $missing = (int)Project::find()->max('id') + 1000;

        try {
            $this->makeController()->actionScan($missing);
            $this->fail('expected NotFoundHttpException');
        } catch (NotFoundHttpException $e) {
            $this->assertSame("Project #{$missing} not found.", $e->getMessage());
        }
        $this->assertSame(0, (int)AuditLog::find()->where(['action' => AuditLog::ACTION_PROJECT_VAULT_SCANNED, 'object_id' => $missing])->count());
    }

    public function testATeamViewerMayNotRescan(): void
    {
        $operator = $this->loginWithRole('operator');
        $project = $this->project((int)$operator->id, $this->copyFixtureRepo());
        $this->restrictToTeamOf($project, $operator, TeamProject::ROLE_VIEWER);

        $this->assertRefused($project);
    }

    public function testAnotherTeamsProjectMayNotBeRescanned(): void
    {
        $operator = $this->loginWithRole('operator');
        $owner = $this->createUser('vault-scan-owner');
        $project = $this->project((int)$owner->id, $this->copyFixtureRepo());
        $this->restrictToTeamOf($project, $owner, TeamProject::ROLE_OPERATOR);
        $this->loginAs($operator);

        $this->assertRefused($project);
    }

    public function testATeamOperatorMayRescan(): void
    {
        $operator = $this->loginWithRole('operator');
        $project = $this->project((int)$operator->id, $this->copyFixtureRepo());
        $this->restrictToTeamOf($project, $operator, TeamProject::ROLE_OPERATOR);

        $this->makeController()->actionScan((int)$project->id);

        $project->refresh();
        $this->assertNotNull($project->vault_scanned_at);
        $this->assertCount(1, $this->scanAudits($project));
    }

    // -- Access and verb filters (through runAction) --------------------------

    public function testTheAccessFilterDeniesAViewer(): void
    {
        $viewer = $this->loginWithRole('viewer');
        $project = $this->project((int)$viewer->id, $this->copyFixtureRepo());
        $this->setPost([]);

        try {
            $this->makeController()->runAction('scan', ['id' => $project->id]);
            $this->fail('expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You are not allowed to perform this action.', $e->getMessage(), 'the access filter refuses, not the action');
        }
        $this->assertNotScanned($project);
    }

    public function testTheAccessFilterAdmitsAnOperator(): void
    {
        $operator = $this->loginWithRole('operator');
        $project = $this->project((int)$operator->id, $this->copyFixtureRepo());
        $this->setPost([]);

        $ctrl = $this->makeController();
        $ctrl->runAction('scan', ['id' => $project->id]);

        $this->assertSame(['/project/view', 'id' => (int)$project->id, '#' => 'vault'], $ctrl->capturedRedirect);
        $project->refresh();
        $this->assertNotNull($project->vault_scanned_at);
    }

    /**
     * Superadmins pass the access filter without any role, as everywhere.
     */
    public function testASuperadminWithoutRolesMayRescan(): void
    {
        $admin = $this->createUser('vault-scan-superadmin');
        $admin->is_superadmin = 1;
        $admin->save(false);
        $this->loginAs($admin);
        $project = $this->project((int)$admin->id, $this->copyFixtureRepo());
        $this->setPost([]);

        $this->makeController()->runAction('scan', ['id' => $project->id]);

        $project->refresh();
        $this->assertNotNull($project->vault_scanned_at);
    }

    public function testScanAcceptsOnlyPost(): void
    {
        $operator = $this->loginWithRole('operator');
        $project = $this->project((int)$operator->id, $this->copyFixtureRepo());

        try {
            $this->makeController()->runAction('scan', ['id' => $project->id]);
            $this->fail('expected MethodNotAllowedHttpException');
        } catch (MethodNotAllowedHttpException) {
            // expected: a GET (a link, a prefetch) must not rescan
        }
        $this->assertNotScanned($project);
    }

    // -- Helpers --------------------------------------------------------------

    private function makeController(): ProjectVaultController
    {
        return new class ('project-vault', \Yii::$app) extends ProjectVaultController {
            /** @var array<int|string, mixed> */
            public array $capturedRedirect = [];

            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = (array)$url;
                $response = new Response();
                $response->content = 'redirected';
                return $response;
            }
        };
    }

    private function assertRefused(Project $project): void
    {
        try {
            $this->makeController()->actionScan((int)$project->id);
            $this->fail('expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(self::NO_OPERATE, $e->getMessage());
        }
        $this->assertNotScanned($project);
    }

    private function assertNotScanned(Project $project): void
    {
        $project->refresh();
        $this->assertNull($project->vault_scanned_at, 'nothing may be scanned');
        $this->assertNull($project->vault_scan_error);
        $this->assertSame([], $project->vaultEntries);
        $this->assertSame([], $this->scanAudits($project));
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    /**
     * The project.vault-scanned audit entries of a project, oldest first.
     *
     * @return list<array{user_id: int|null, metadata: mixed}>
     */
    private function scanAudits(Project $project): array
    {
        $logs = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_PROJECT_VAULT_SCANNED, 'object_type' => 'project', 'object_id' => $project->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return array_map(static fn (AuditLog $log): array => [
            'user_id' => $log->user_id === null ? null : (int)$log->user_id,
            'metadata' => json_decode((string)$log->metadata, true),
        ], $logs);
    }

    private function loginWithRole(string $roleName): User
    {
        $user = $this->createUser('vault-scan');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole($roleName);
        $this->assertNotNull($role, $roleName);
        $auth->assign($role, (string)$user->id);
        $this->loginAs($user);

        return $user;
    }

    private function restrictToTeamOf(Project $project, User $member, string $role): void
    {
        $team = $this->createTeam((int)$member->id);
        $this->addTeamMember((int)$team->id, (int)$member->id);
        $this->createTeamProject((int)$team->id, (int)$project->id, $role);
    }

    /**
     * A manual project whose checkout is $localPath (null: none yet).
     */
    private function project(int $userId, ?string $localPath): Project
    {
        $project = $this->createProject($userId);
        $project->local_path = $localPath;
        $project->save(false);
        $fresh = Project::findOne($project->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function entry(Project $project, string $path): void
    {
        $entry = new ProjectVaultEntry();
        $entry->project_id = (int)$project->id;
        $entry->path = $path;
        $entry->kind = ProjectVaultEntry::KIND_FILE;
        $entry->format_version = '1.1';
        $entry->fingerprint = hash('sha256', $path);
        $entry->save(false);
    }

    /**
     * A private copy of the fixture repository (tests/fixtures/vault/repo);
     * tests never write into tests/fixtures.
     */
    private function copyFixtureRepo(): string
    {
        $target = sys_get_temp_dir() . '/ansilume-vault-scan-web-' . bin2hex(random_bytes(6));
        $this->tempDirs[] = $target;
        $this->copyTree(dirname(__DIR__, 2) . '/fixtures/vault/repo', $target);

        return $target;
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
}
