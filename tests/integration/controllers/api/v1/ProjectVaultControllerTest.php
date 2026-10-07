<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ProjectVaultController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Credential;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\models\ProjectVaultEntry;
use app\models\TeamProject;
use app\models\User;
use app\services\VaultOverviewService;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * REST API for the vault overview of a project:
 * GET /api/v1/projects/{id}/vault needs project.view and view access to the
 * project; POST /api/v1/projects/{id}/vault/scan needs project.update and
 * operator access, rescans the checkout and answers with the fresh overview.
 * A refused request scans nothing and writes no audit entry.
 */
class ProjectVaultControllerTest extends WebControllerTestCase
{
    /** A manual project without a local path: never a checkout. */
    private const NO_CHECKOUT = 'This manual project has no local path, so there is nothing to scan. Set its local path in the project settings.';
    private const FORBIDDEN = ['error' => ['message' => 'Forbidden.']];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    // -- GET: view ------------------------------------------------------------

    public function testViewAnswersWithTheOverviewOfTheProject(): void
    {
        $viewer = $this->authenticateWithRoles(['viewer']);
        $project = $this->project((int)$viewer->id, null);
        $project->vault_scanned_at = 1700000000;
        $project->vault_scan_summary = (string)json_encode(['cfg' => [], 'findings' => [], 'truncated' => false, 'files_scanned' => 3]);
        $project->save(false);
        $this->entry($project, 'inventories/prod/group_vars/all/vault.yml', 'prod');

        $result = $this->controller()->actionView((int)$project->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $data = $this->dataOf($result);
        $this->assertSame($this->overviewOf($project), $data);
        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $data['password_source']);
        $this->assertSame(1700000000, $data['scanned_at']);
        $this->assertSame(3, $data['files_scanned']);
        $this->assertSame(['prod'], $data['vault_ids']);
        $this->assertSame('inventories/prod/group_vars/all/vault.yml', $data['entries'][0]['path']);
    }

    public function testATeamViewerMayView(): void
    {
        $viewer = $this->authenticateWithRoles(['viewer']);
        $project = $this->project((int)$viewer->id, null);
        $this->restrictToTeamOf($project, $viewer, TeamProject::ROLE_VIEWER);

        $data = $this->dataOf($this->controller()->actionView((int)$project->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame($this->overviewOf($project), $data);
        $this->assertNull($data['scanned_at'], 'never scanned');
    }

    /**
     * The overview of another team's project stays hidden.
     */
    public function testViewOfAnotherTeamsProjectIsForbidden(): void
    {
        $this->authenticateWithRoles(['viewer']);
        $project = $this->foreignProject();
        $this->entry($project, 'secret-layout/vault.yml', null);

        $result = $this->controller()->actionView((int)$project->id);

        $this->assertSame(self::FORBIDDEN, $result, 'nothing of the overview, e.g. file paths, may leak');
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testViewOfAMissingProjectIsNotFound(): void
    {
        $this->authenticateWithRoles(['viewer']);
        $missing = $this->missingProjectId();

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage("Project #{$missing} not found.");
        $this->controller()->actionView($missing);
    }

    public function testATokenWithoutProjectViewIsStoppedByTheGate(): void
    {
        $user = $this->authenticateWithRoles([]);
        $project = $this->project((int)$user->id, null);

        $this->assertForbiddenByTheGate($this->controller()->runAction('view', ['id' => $project->id]));
    }

    public function testAViewerTokenPassesTheGateForView(): void
    {
        $viewer = $this->authenticateWithRoles(['viewer']);
        $project = $this->project((int)$viewer->id, null);

        $result = $this->controller()->runAction('view', ['id' => $project->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame($this->overviewOf($project), $this->dataOf($result));
    }

    /**
     * Regression: GET showed runner names, groups and versions and the names
     * of vault passwords to anyone with project.view. Without
     * runner-group.view, credential.view and job-template.view the overview
     * gives only how many runners are too old and the ids of the passwords.
     */
    public function testDetailsOfOtherAreasFollowTheirPermissions(): void
    {
        $user = $this->authenticateWithPermissions(['project.view']);
        $project = $this->project((int)$user->id, null);
        $project->vault_scanned_at = 1700000000;
        $project->save(false);
        $group = $this->createRunnerGroup((int)$user->id);
        $template = $this->createJobTemplate((int)$project->id, (int)$this->createInventory((int)$user->id)->id, (int)$group->id, (int)$user->id);
        $vault = $this->createCredential((int)$user->id, Credential::TYPE_VAULT);
        $vault->name = 'prod-vault-password-' . uniqid();
        $vault->save(false);
        $check = new JobTemplateVaultCheck([
            'job_template_id' => $template->id,
            'status' => JobTemplateVaultCheck::STATUS_OK,
            'credential_id' => $vault->id,
            'relevant_count' => 1,
            'checked_at' => 1700000100,
            'scanned_at' => 1700000000,
        ]);
        $check->save(false);
        $runner = $this->createRunner((int)$group->id, (int)$user->id);
        $runner->software_version = '2.7.0';
        $runner->save(false);

        $result = $this->controller()->runAction('view', ['id' => $project->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $data = $this->dataOf($result);
        $this->assertSame([], $data['runners_without_support']);
        $this->assertSame(1, $data['runners_without_support_count']);
        $this->assertSame(['id' => (int)$vault->id, 'name' => null], $data['templates'][0]['credential']);
        $json = (string)json_encode($result);
        foreach ([$vault->name, (string)$runner->name, (string)$group->name, '2.7.0'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }
    }

    // -- POST: scan -----------------------------------------------------------

    public function testScanRescansAuditsAndAnswersWithTheFreshOverview(): void
    {
        $operator = $this->authenticateWithRoles(['operator']);
        $project = $this->project((int)$operator->id, $this->copyFixtureRepo());
        $template = $this->createJobTemplate(
            (int)$project->id,
            (int)$this->createInventory((int)$operator->id)->id,
            (int)$this->createRunnerGroup((int)$operator->id)->id,
            (int)$operator->id
        );
        $before = time();

        $result = $this->controller()->actionScan((int)$project->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $data = $this->dataOf($result);
        $this->assertSame($this->overviewOf($project), $data, 'the answer is the overview after the scan');
        $this->assertGreaterThanOrEqual($before, $data['scanned_at']);
        $this->assertNull($data['error']);
        $this->assertCount(7, $data['entries']);
        $this->assertSame(['prod'], $data['vault_ids']);
        $this->assertSame(
            ['password_file_committed', 'ask_vault_pass', 'vault_id_match', 'encrypt_salt'],
            array_column($data['findings'], 'code')
        );
        // The template was checked as part of the scan: site.yml loads the
        // encrypted group_vars/all.yml and vars/secrets.yml, and the template
        // has no vault password.
        $this->assertSame([(int)$template->id], array_column($data['templates'], 'id'));
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, $data['templates'][0]['status']);
        $this->assertSame([
            ['user_id' => (int)$operator->id, 'metadata' => ['name' => $project->name, 'scanned' => true, 'source' => 'api']],
        ], $this->scanAudits($project));
    }

    /**
     * Without a checkout the scan is still an answer, not an error: the
     * overview says why it is empty.
     */
    public function testScanWithoutACheckoutAnswersWithTheReason(): void
    {
        $operator = $this->authenticateWithRoles(['operator']);
        $project = $this->project((int)$operator->id, null);
        $this->entry($project, 'stale.yml', null);

        $data = $this->dataOf($this->controller()->actionScan((int)$project->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(self::NO_CHECKOUT, $data['error']);
        $this->assertNull($data['scanned_at']);
        $this->assertSame([], $data['entries']);
        $this->assertSame([
            ['user_id' => (int)$operator->id, 'metadata' => ['name' => $project->name, 'scanned' => false, 'source' => 'api']],
        ], $this->scanAudits($project));
    }

    public function testATeamViewerMayNotScan(): void
    {
        $operator = $this->authenticateWithRoles(['operator']);
        $project = $this->project((int)$operator->id, $this->copyFixtureRepo());
        $this->restrictToTeamOf($project, $operator, TeamProject::ROLE_VIEWER);

        $this->assertSame(self::FORBIDDEN, $this->controller()->actionScan((int)$project->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertNotScanned($project);
    }

    public function testAnotherTeamsProjectMayNotBeScanned(): void
    {
        $this->authenticateWithRoles(['operator']);
        $project = $this->foreignProject($this->copyFixtureRepo());

        $this->assertSame(self::FORBIDDEN, $this->controller()->actionScan((int)$project->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertNotScanned($project);
    }

    public function testScanOfAMissingProjectIsNotFound(): void
    {
        $this->authenticateWithRoles(['operator']);
        $missing = $this->missingProjectId();

        try {
            $this->controller()->actionScan($missing);
            $this->fail('expected NotFoundHttpException');
        } catch (NotFoundHttpException $e) {
            $this->assertSame("Project #{$missing} not found.", $e->getMessage());
        }
        $this->assertSame(0, (int)AuditLog::find()->where(['action' => AuditLog::ACTION_PROJECT_VAULT_SCANNED, 'object_id' => $missing])->count());
    }

    public function testAViewerTokenIsStoppedByTheGateAndScansNothing(): void
    {
        $viewer = $this->authenticateWithRoles(['viewer']);
        $project = $this->project((int)$viewer->id, $this->copyFixtureRepo());

        $this->assertForbiddenByTheGate($this->controller()->runAction('scan', ['id' => $project->id]));
        $this->assertNotScanned($project);
    }

    public function testAnOperatorTokenScansThroughTheGate(): void
    {
        $operator = $this->authenticateWithRoles(['operator']);
        $project = $this->project((int)$operator->id, $this->copyFixtureRepo());

        $result = $this->controller()->runAction('scan', ['id' => $project->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertCount(7, $this->dataOf($result)['entries']);
    }

    public function testARequestWithoutATokenIsRejected(): void
    {
        $project = $this->project((int)$this->createUser('vault-api-owner')->id, $this->copyFixtureRepo());

        try {
            $this->controller()->runAction('scan', ['id' => $project->id]);
            $this->fail('expected UnauthorizedHttpException');
        } catch (UnauthorizedHttpException) {
            // expected
        }
        $this->assertNotScanned($project);
    }

    // -- Routing --------------------------------------------------------------

    public function testTheUrlRulesRouteTheVaultEndpoints(): void
    {
        $config = require \Yii::getAlias('@app') . '/config/web.php';
        $manager = new \yii\web\UrlManager([
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => false,
            'rules' => $config['components']['urlManager']['rules'],
            'cache' => false,
            'baseUrl' => '',
            'scriptUrl' => '/index.php',
        ]);

        $this->assertSame(['api/v1/project-vault/view', ['id' => '42']], $manager->parseRequest($this->request('GET', 'api/v1/projects/42/vault')));
        $this->assertSame(['api/v1/project-vault/scan', ['id' => '42']], $manager->parseRequest($this->request('POST', 'api/v1/projects/42/vault/scan')));
        foreach (['POST' => 'api/v1/projects/42/vault', 'GET' => 'api/v1/projects/42/vault/scan', 'DELETE' => 'api/v1/projects/42/vault'] as $verb => $path) {
            $parsed = $manager->parseRequest($this->request($verb, $path));
            $this->assertIsArray($parsed);
            $this->assertStringStartsNotWith('api/v1/project-vault/', $parsed[0], "{$verb} {$path}");
        }
        $nonNumeric = $manager->parseRequest($this->request('GET', 'api/v1/projects/abc/vault'));
        $this->assertIsArray($nonNumeric);
        $this->assertStringStartsNotWith('api/v1/project-vault/', $nonNumeric[0]);
        // The project routes next to the new ones still work.
        $this->assertSame(['api/v1/projects/view', ['id' => '42']], $manager->parseRequest($this->request('GET', 'api/v1/projects/42')));
        $this->assertSame(['api/v1/projects/sync', ['id' => '42']], $manager->parseRequest($this->request('POST', 'api/v1/projects/42/sync')));
        $this->assertSame('/api/v1/projects/42/vault', $manager->createUrl(['api/v1/project-vault/view', 'id' => 42]));
        $this->assertSame('/api/v1/projects/42/vault/scan', $manager->createUrl(['api/v1/project-vault/scan', 'id' => 42]));

        $this->assertSame(ProjectVaultController::class, $config['controllerMap']['api/v1/project-vault']);
        $controller = \Yii::createObject($config['controllerMap']['api/v1/project-vault'], ['api/v1/project-vault', \Yii::$app]);
        $this->assertInstanceOf(ProjectVaultController::class, $controller);
        foreach (['view' => 'actionView', 'scan' => 'actionScan'] as $actionId => $method) {
            $action = $controller->createAction($actionId);
            $this->assertInstanceOf(\yii\base\InlineAction::class, $action, $actionId);
            $this->assertSame($method, $action->actionMethod);
        }
    }

    // -- Helpers --------------------------------------------------------------

    private function controller(): ProjectVaultController
    {
        return new ProjectVaultController('api/v1/project-vault', \Yii::$app);
    }

    /**
     * @return array<string, mixed>
     */
    private function overviewOf(Project $project): array
    {
        /** @var VaultOverviewService $service */
        $service = \Yii::$app->get('vaultOverviewService');
        $fresh = Project::findOne($project->id);
        $this->assertNotNull($fresh);

        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;

        return $service->forProject($fresh, static fn (string $permission): bool => $user->can($permission));
    }

    /**
     * @param list<string> $roles
     */
    private function authenticateWithRoles(array $roles): User
    {
        $user = $this->createUser('vault-api');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        foreach ($roles as $roleName) {
            $role = $auth->getRole($roleName);
            $this->assertNotNull($role, $roleName);
            $auth->assign($role, (string)$user->id);
        }
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'vault-api-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);

        return $user;
    }

    /**
     * Signs in a token user whose only role holds exactly $permissions.
     *
     * @param list<string> $permissions
     */
    private function authenticateWithPermissions(array $permissions): User
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('vault-api-' . uniqid());
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $user = $this->authenticateWithRoles([]);
        $auth->assign($role, (string)$user->id);

        return $user;
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

    /**
     * A project restricted to a team the caller is not in.
     */
    private function foreignProject(?string $localPath = null): Project
    {
        $owner = $this->createUser('vault-api-owner');
        $project = $this->project((int)$owner->id, $localPath);
        $this->restrictToTeamOf($project, $owner, TeamProject::ROLE_OPERATOR);

        return $project;
    }

    private function restrictToTeamOf(Project $project, User $member, string $role): void
    {
        $team = $this->createTeam((int)$member->id);
        $this->addTeamMember((int)$team->id, (int)$member->id);
        $this->createTeamProject((int)$team->id, (int)$project->id, $role);
    }

    private function entry(Project $project, string $path, ?string $vaultId): void
    {
        $entry = new ProjectVaultEntry();
        $entry->project_id = (int)$project->id;
        $entry->path = $path;
        $entry->kind = ProjectVaultEntry::KIND_FILE;
        $entry->vault_id = $vaultId;
        $entry->format_version = $vaultId === null ? '1.1' : '1.2';
        $entry->fingerprint = hash('sha256', $path);
        $entry->save(false);
    }

    private function missingProjectId(): int
    {
        return (int)Project::find()->max('id') + 1000;
    }

    private function assertNotScanned(Project $project): void
    {
        $project->refresh();
        $this->assertNull($project->vault_scanned_at, 'nothing may be scanned');
        $this->assertNull($project->vault_scan_error);
        $this->assertSame([], $this->scanAudits($project));
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

    /**
     * @param mixed $result
     * @return array<string, mixed>
     */
    private function dataOf($result): array
    {
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    /**
     * The gate refuses before the action runs: runAction() returns nothing
     * and the gate writes the answer to the response.
     */
    private function assertForbiddenByTheGate(mixed $result): void
    {
        $this->assertNull($result, 'the action must not run');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(self::FORBIDDEN, \Yii::$app->response->data);
    }

    private function request(string $verb, string $pathInfo): \yii\web\Request
    {
        $request = new \yii\web\Request(['enableCsrfValidation' => false, 'cookieValidationKey' => 'test-key']);
        $request->setPathInfo($pathInfo);
        $request->headers->set('X-Http-Method-Override', $verb);

        return $request;
    }

    /**
     * A private copy of the fixture repository (tests/fixtures/vault/repo);
     * tests never write into tests/fixtures.
     */
    private function copyFixtureRepo(): string
    {
        $target = sys_get_temp_dir() . '/ansilume-vault-scan-api-' . bin2hex(random_bytes(6));
        $this->tempDirs[] = $target;
        $this->copyTree(dirname(__DIR__, 4) . '/fixtures/vault/repo', $target);

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
