<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ProjectsController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\services\VaultScanService;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the Projects API controller.
 *
 * Exercises authentication, authorization, CRUD operations, sync action,
 * delete guards and the vault password source (validation, audit, re-check of
 * the templates) against a real database (rolled back after each test).
 */
class ProjectsControllerTest extends WebControllerTestCase
{
    private ProjectsController $ctrl;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new ProjectsController('api/v1/projects', \Yii::$app);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexReturnsPaginatedList(): void
    {
        $this->authenticateWithAdmin();
        $user = \Yii::$app->user;
        $this->createProject((int)$user->id);

        $result = $this->ctrl->actionIndex();
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
        /** @var array{total: int, page: int, per_page: int, pages: int} $meta */
        $meta = $result['meta'];
        $this->assertArrayHasKey('total', $meta);
        $this->assertArrayHasKey('page', $meta);
        $this->assertArrayHasKey('per_page', $meta);
        $this->assertArrayHasKey('pages', $meta);
        $this->assertGreaterThanOrEqual(1, $meta['total']);
    }

    // -- View -----------------------------------------------------------------

    public function testViewReturnsProject(): void
    {
        $this->authenticateWithAdmin();
        $project = $this->createProject((int)\Yii::$app->user->id);

        $data = $this->callSuccess($this->ctrl->actionView($project->id));
        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($project->id, $item['id']);
        $this->assertSame($project->name, $item['name']);
        $this->assertArrayHasKey('scm_type', $item);
        $this->assertArrayHasKey('status', $item);
    }

    public function testViewReturns404(): void
    {
        $this->authenticateWithAdmin();
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView(999999);
    }

    // -- Create ---------------------------------------------------------------

    public function testCreateWithValidData(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'name' => 'api-test-project-' . uniqid('', true),
            'scm_type' => Project::SCM_TYPE_MANUAL,
        ]);

        $data = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertArrayHasKey('id', $item);
        $this->assertSame(Project::SCM_TYPE_MANUAL, $item['scm_type']);
        $this->assertSame('new', $item['status']);
    }

    public function testCreateRejects422OnMissingName(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'scm_type' => Project::SCM_TYPE_MANUAL,
        ]);

        $this->ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testCreateRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-create-perm');
        $this->setBody([
            'name' => 'forbidden-project',
            'scm_type' => Project::SCM_TYPE_MANUAL,
        ]);

        $this->ctrl->actionCreate();
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Update ---------------------------------------------------------------

    public function testUpdateWithValidData(): void
    {
        $this->authenticateWithAdmin();
        $project = $this->createProject((int)\Yii::$app->user->id);

        $this->setBody(['name' => 'updated-name-' . uniqid('', true)]);
        $data = $this->callSuccess($this->ctrl->actionUpdate($project->id));

        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($project->id, $item['id']);
        $this->assertStringStartsWith('updated-name-', (string)$item['name']);
    }

    public function testUpdateReturns404(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody(['name' => 'ghost']);
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionUpdate(999999);
    }

    // -- Delete ---------------------------------------------------------------

    public function testDeleteReturnsSuccess(): void
    {
        $this->authenticateWithAdmin();
        $project = $this->createProject((int)\Yii::$app->user->id);

        $data = $this->callSuccess($this->ctrl->actionDelete($project->id));
        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertTrue($payload['deleted']);

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView($project->id);
    }

    public function testDeleteRefusesWhenTemplatesExist(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);

        $this->ctrl->actionDelete($project->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    /**
     * Regression: a project whose templates were soft-deleted could not be
     * removed via the API either — the guard saw zero templates, the RESTRICT
     * foreign key saw them all, and the request ended in a 500.
     */
    public function testDeleteSucceedsWhenOnlySoftDeletedTemplatesRemain(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);
        $this->assertTrue($template->softDelete());

        $data = $this->callSuccess($this->ctrl->actionDelete($project->id));
        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertTrue($payload['deleted']);
        $this->assertNull(\app\models\Project::findOne($project->id));
        $this->assertNull(\app\models\JobTemplate::findWithDeleted()->where(['id' => $template->id])->one());
    }

    // -- Sync -----------------------------------------------------------------

    public function testSyncRejects422ForManualProject(): void
    {
        $this->authenticateWithAdmin();
        $project = $this->createProject((int)\Yii::$app->user->id);
        // createProject uses SCM_TYPE_MANUAL by default

        $this->ctrl->actionSync($project->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    // -- Vault password source ------------------------------------------------

    public function testViewIncludesTheVaultFieldsOfANewProject(): void
    {
        $this->authenticateWithAdmin();
        $project = $this->createProject((int)\Yii::$app->user->id);

        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionView($project->id));

        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $item['vault_password_source']);
        $this->assertNull($item['vault_scanned_at']);
    }

    public function testViewIncludesTheVaultFieldsOfAScannedProject(): void
    {
        $this->authenticateWithAdmin();
        $project = $this->createProject((int)\Yii::$app->user->id);
        $project->vault_password_source = Project::VAULT_SOURCE_REPOSITORY;
        $project->vault_scanned_at = 1700000000;
        $project->save(false);

        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionView($project->id));

        $this->assertSame(Project::VAULT_SOURCE_REPOSITORY, $item['vault_password_source']);
        $this->assertSame(1700000000, $item['vault_scanned_at']);
    }

    public function testCreateDefaultsTheVaultSourceToAnsilume(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody(['name' => 'api-vault-default-' . uniqid('', true), 'scm_type' => Project::SCM_TYPE_MANUAL]);

        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $item['vault_password_source']);
        $this->assertNull($item['vault_scanned_at']);
        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $this->reloadProject((int)$item['id'])->vault_password_source);
    }

    public function testCreateKeepsAnExplicitVaultSource(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'name' => 'api-vault-repository-' . uniqid('', true),
            'scm_type' => Project::SCM_TYPE_MANUAL,
            'vault_password_source' => Project::VAULT_SOURCE_REPOSITORY,
        ]);

        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionCreate());

        $this->assertSame(Project::VAULT_SOURCE_REPOSITORY, $item['vault_password_source']);
        $this->assertSame(Project::VAULT_SOURCE_REPOSITORY, $this->reloadProject((int)$item['id'])->vault_password_source);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unknownVaultSourceProvider(): array
    {
        return [
            'unknown name' => ['ansible-cfg-only'],
            'wrong case' => ['Repository'],
            'a number' => [7],
            'a boolean' => [true],
        ];
    }

    /**
     * @dataProvider unknownVaultSourceProvider
     */
    public function testCreateRejectsAnUnknownVaultSource(mixed $value): void
    {
        $this->authenticateWithAdmin();
        $name = 'api-vault-bogus-' . uniqid('', true);
        $this->setBody(['name' => $name, 'scm_type' => Project::SCM_TYPE_MANUAL, 'vault_password_source' => $value]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Vault Password Source is invalid.']], $result);
        $this->assertNull(Project::findOne(['name' => $name]));
    }

    /**
     * The fixture repository's ansible.cfg brings a vault password file, so
     * in 'Ansilume and repository' mode the template without a vault password
     * is no longer flagged: the change is audited and the templates are
     * re-checked at once.
     */
    public function testUpdateChangesTheVaultSourceAuditsItAndRechecksTheTemplates(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        [$project, $template] = $this->scannedVaultProject($userId);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, $this->vaultCheckStatus($template));

        $this->setBody(['vault_password_source' => Project::VAULT_SOURCE_REPOSITORY]);
        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionUpdate($project->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(Project::VAULT_SOURCE_REPOSITORY, $item['vault_password_source']);
        $this->assertSame(Project::VAULT_SOURCE_REPOSITORY, $this->reloadProject($project->id)->vault_password_source);
        $this->assertSame([[
            'user_id' => $userId,
            'metadata' => ['name' => $project->name, 'from' => Project::VAULT_SOURCE_ANSILUME, 'to' => Project::VAULT_SOURCE_REPOSITORY, 'source' => 'api'],
        ]], $this->vaultSourceAudits($project));
        $this->assertSame(JobTemplateVaultCheck::STATUS_REPO_MANAGED, $this->vaultCheckStatus($template));
    }

    /**
     * @dataProvider unknownVaultSourceProvider
     */
    public function testUpdateRejectsAnUnknownVaultSource(mixed $value): void
    {
        $this->authenticateWithAdmin();
        $project = $this->createProject((int)\Yii::$app->user->id);

        $this->setBody(['vault_password_source' => $value]);
        $result = $this->ctrl->actionUpdate($project->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Vault Password Source is invalid.']], $result);
        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $this->reloadProject($project->id)->vault_password_source);
        $this->assertSame([], $this->vaultSourceAudits($project));
        $this->assertNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_UPDATED, 'object_id' => $project->id]));
    }

    /**
     * Manual projects never sync, so creating one scans it at once. The API
     * cannot set a local path, so the scan reports that there is none.
     */
    public function testCreatingAManualProjectScansIt(): void
    {
        $this->authenticateWithAdmin();
        $name = 'api-manual-vault-' . uniqid('', true);
        $this->setBody(['name' => $name, 'scm_type' => Project::SCM_TYPE_MANUAL]);

        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionCreate());

        $this->assertNull($item['vault_scanned_at']);
        $this->assertSame(
            'This manual project has no local path, so there is nothing to scan. Set its local path in the project settings.',
            $this->reloadProject((int)$item['id'])->vault_scan_error
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function emptyVaultSourceProvider(): array
    {
        return ['null' => [null], 'empty string' => ['']];
    }

    /**
     * Regression: the default rule also ran on updates, so a PUT with null
     * or '' silently switched a repository project to 'Ansilume only', and
     * jobs relying on the repository's password file failed.
     *
     * @dataProvider emptyVaultSourceProvider
     */
    public function testUpdateRejectsAnEmptyVaultSourceInsteadOfSwitchingIt(mixed $value): void
    {
        $this->authenticateWithAdmin();
        $project = $this->createProject((int)\Yii::$app->user->id);
        $project->vault_password_source = Project::VAULT_SOURCE_REPOSITORY;
        $project->save(false);

        $this->setBody(['vault_password_source' => $value]);
        $result = $this->ctrl->actionUpdate($project->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Vault Password Source cannot be blank.']], $result);
        $this->assertSame(Project::VAULT_SOURCE_REPOSITORY, $this->reloadProject($project->id)->vault_password_source);
        $this->assertSame([], $this->vaultSourceAudits($project));
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function unchangedVaultSourceProvider(): array
    {
        return [
            'field left out' => [[]],
            'the same value' => [['vault_password_source' => Project::VAULT_SOURCE_ANSILUME]],
        ];
    }

    /**
     * No source change, no audit entry. A manual project never syncs, so
     * saving it scans its files again, and that re-checks the templates.
     *
     * @dataProvider unchangedVaultSourceProvider
     * @param array<string, string> $vaultField
     */
    public function testUpdateWithoutAVaultSourceChangeDoesNotAuditButRescansAManualProject(array $vaultField): void
    {
        $this->authenticateWithAdmin();
        [$project, $template] = $this->scannedVaultProject((int)\Yii::$app->user->id);
        // A sentinel no real check would store: a re-check would replace it.
        JobTemplateVaultCheck::updateAll(['status' => JobTemplateVaultCheck::STATUS_STALE, 'checked_at' => 1], ['job_template_id' => $template->id]);

        $this->setBody(['name' => 'renamed-' . uniqid('', true)] + $vaultField);
        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionUpdate($project->id));

        $this->assertStringStartsWith('renamed-', (string)$item['name'], 'the update itself went through');
        $this->assertSame(Project::VAULT_SOURCE_ANSILUME, $item['vault_password_source']);
        $this->assertSame([], $this->vaultSourceAudits($project));
        $check = JobTemplateVaultCheck::findOne($template->id);
        $this->assertNotNull($check);
        $this->assertNotSame(JobTemplateVaultCheck::STATUS_STALE, $check->status, 'the save rescanned the manual project');
        $this->assertGreaterThan(1, (int)$check->checked_at);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Extract the data payload from a success response.
     *
     * @param array<string, mixed> $result
     */
    private function callSuccess(array $result): mixed
    {
        $this->assertArrayHasKey('data', $result);
        return $result['data'];
    }

    /**
     * Create a user with no RBAC role — will fail all permission checks.
     */
    private function authenticateAs(string $label): void
    {
        $user = $this->createUser($label);
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * Create an admin user with full permissions and authenticate.
     */
    private function authenticateWithAdmin(): void
    {
        $user = $this->createUser('api-admin');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $adminRole = $auth->getRole('admin');
        $this->assertNotNull($adminRole);
        $auth->assign($adminRole, (string)$user->id);

        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'admin-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    private function reloadProject(int $id): Project
    {
        $project = Project::findOne($id);
        $this->assertNotNull($project);

        return $project;
    }

    /**
     * A manual 'Ansilume only' project on a private copy of the fixture
     * repository (tests/fixtures/vault/repo), scanned, with one template that
     * loads encrypted files (site.yml on the dev inventory) and has no vault
     * password.
     *
     * @return array{0: Project, 1: JobTemplate}
     */
    private function scannedVaultProject(int $userId): array
    {
        $root = sys_get_temp_dir() . '/ansilume-api-project-vault-' . bin2hex(random_bytes(6));
        $this->tempDirs[] = $root;
        $this->copyTree(dirname(__DIR__, 4) . '/fixtures/vault/repo', $root);
        $project = $this->createProject($userId);
        $project->local_path = $root;
        $project->save(false);
        $inventory = $this->createInventory($userId);
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->content = null;
        $inventory->source_path = 'inventories/dev/hosts.yml';
        $inventory->project_id = $project->id;
        $inventory->save(false);
        $template = $this->createJobTemplate($project->id, $inventory->id, $this->createRunnerGroup($userId)->id, $userId);
        /** @var VaultScanService $scans */
        $scans = \Yii::$app->get('vaultScanService');
        $this->assertTrue($scans->scanProject($this->reloadProject($project->id)));

        return [$this->reloadProject($project->id), $template];
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

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }
}
