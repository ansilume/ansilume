<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\InventoriesController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\models\TeamProject;
use app\models\User;
use app\services\VaultScanService;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * PUT /api/v1/inventories/{id}: which projects an inventory may be moved to,
 * and the vault check of the job templates using an inventory after it
 * changed. Authenticates with a real API token.
 */
class InventoriesControllerTest extends WebControllerTestCase
{
    private InventoriesController $ctrl;

    /** @var list<string> */
    private array $checkouts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new InventoriesController('api/v1/inventories', \Yii::$app);
    }

    protected function tearDown(): void
    {
        foreach ($this->checkouts as $checkout) {
            self::removeTree($checkout);
        }
        $this->checkouts = [];
        parent::tearDown();
    }

    public function testUpdateRequiresTheInventoryUpdatePermission(): void
    {
        $this->authenticateWithRole('viewer');
        $inventory = $this->staticInventory((int)$this->createProject((int)$this->createUser('api-inv-admin')->id)->id);
        $this->setBody(['name' => 'renamed-inventory']);

        $result = $this->ctrl->actionUpdate($inventory);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertSame('api-scoped-inventory', Inventory::findOne($inventory)?->name);
    }

    public function testUpdateOfAnInventoryInAProjectTheCallerMayNotOperateIsForbidden(): void
    {
        $scope = $this->apiTeamScope();
        $inventory = $this->staticInventory($scope['foreign']);
        $this->setBody(['name' => 'renamed-inventory']);

        $result = $this->ctrl->actionUpdate($inventory);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertSame('api-scoped-inventory', Inventory::findOne($inventory)?->name);
    }

    /**
     * Regression: update checked operate access on the stored project only,
     * so a team operator could move an inventory into a project of another
     * team, or into one their team may only view.
     *
     * @return array<string, array{0: string}>
     */
    public static function projectTheCallerMayNotOperateProvider(): array
    {
        return ['another team\'s project' => ['foreign'], 'a project the team only views' => ['viewed']];
    }

    /**
     * @dataProvider projectTheCallerMayNotOperateProvider
     */
    public function testUpdateCannotMoveAnInventoryIntoAProjectTheCallerMayNotOperate(string $target): void
    {
        $scope = $this->apiTeamScope();
        $inventory = $this->staticInventory($scope['own']);
        $this->setBody(['name' => 'moved-inventory', 'project_id' => $scope[$target]]);

        $result = $this->ctrl->actionUpdate($inventory);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $stored = Inventory::findOne($inventory);
        $this->assertNotNull($stored);
        $this->assertSame($scope['own'], $stored->project_id);
        $this->assertSame('api-scoped-inventory', $stored->name);
        $this->assertSame(0, $this->updateAuditCount($inventory));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function projectTheCallerMayOperateProvider(): array
    {
        return ['another project the team operates' => ['ownSecond'], 'no project' => ['none']];
    }

    /**
     * @dataProvider projectTheCallerMayOperateProvider
     */
    public function testUpdateMovesAnInventoryIntoAProjectTheCallerMayOperate(string $target): void
    {
        $scope = $this->apiTeamScope();
        $inventory = $this->staticInventory($scope['own']);
        $projectId = $target === 'none' ? null : $scope[$target];
        $this->setBody(['name' => 'moved-inventory', 'project_id' => $projectId]);

        $result = $this->ctrl->actionUpdate($inventory);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame($inventory, $result['data']['id']);
        $this->assertSame($projectId, $result['data']['project_id']);
        $this->assertSame('moved-inventory', $result['data']['name']);
        $this->assertSame($projectId, Inventory::findOne($inventory)?->project_id);
        $this->assertSame(1, $this->updateAuditCount($inventory));
    }

    public function testAnInvalidUpdateIs422AndSavesNothing(): void
    {
        $scope = $this->apiTeamScope();
        $inventory = $this->staticInventory($scope['own']);
        $this->setBody(['name' => '', 'project_id' => $scope['ownSecond']]);

        $result = $this->ctrl->actionUpdate($inventory);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Name cannot be blank.']], $result);
        $this->assertSame($scope['own'], Inventory::findOne($inventory)?->project_id);
        $this->assertSame(0, $this->updateAuditCount($inventory));
    }

    /**
     * Regression: an unknown project id passed validation and the save then
     * failed on the foreign key, which the API answered with a server error.
     */
    public function testAnUnknownProjectIs422NotAServerError(): void
    {
        $scope = $this->apiTeamScope();
        $inventory = $this->staticInventory($scope['own']);
        // An id without team rows passes the access check: it counts as an open project.
        $this->authenticateWithRole('admin');
        $this->setBody(['project_id' => 999999999]);

        $result = $this->ctrl->actionUpdate($inventory);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'The selected project does not exist.']], $result);
        $this->assertSame($scope['own'], Inventory::findOne($inventory)?->project_id);
    }

    /**
     * Pointing a file inventory at another source changes which encrypted
     * files its job templates load, so their vault check is redone: the dev
     * password opens the dev vaults but not the prod ones.
     */
    public function testAnUpdateChecksTheVaultPasswordOfTheTemplatesUsingTheInventoryAgain(): void
    {
        $vault = $this->vaultScope();
        $before = JobTemplateVaultCheck::findOne($vault['template']);
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $before?->status, 'the scan checks the template');
        $this->setBody(['source_path' => 'inventories/prod/hosts.yml']);

        $this->ctrl->actionUpdate($vault['inventory']);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $check = JobTemplateVaultCheck::findOne($vault['template']);
        $this->assertNotNull($check);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $check->status);
        $this->assertSame(4, $check->relevant_count);
        $this->assertSame($vault['credential'], $check->credential_id);
        $this->assertSame([
            ['path' => 'inventories/prod/group_vars/all/vault.yml', 'line' => null, 'key' => null, 'reason' => null],
            ['path' => 'inventories/prod/host_vars/prod-web1.yml', 'line' => 2, 'key' => 'host_secret', 'reason' => null],
        ], $check->unopenedEntries());
    }

    /**
     * A template that has no check yet, because it was added after the last
     * scan, gets one when its inventory is saved.
     */
    public function testAnUpdateChecksATemplateThatHadNoCheckYet(): void
    {
        $vault = $this->vaultScope();
        $group = (int)$this->createRunnerGroup($vault['userId'])->id;
        $late = $this->createJobTemplate($vault['project'], $vault['inventory'], $group, $vault['userId']);
        $late->credential_id = $vault['credential'];
        $late->save(false);
        $this->assertNull(JobTemplateVaultCheck::findOne($late->id));
        $this->setBody(['name' => 'renamed-dev-inventory']);

        $this->ctrl->actionUpdate($vault['inventory']);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $check = JobTemplateVaultCheck::findOne($late->id);
        $this->assertNotNull($check);
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $check->status);
        $this->assertSame(3, $check->relevant_count);
    }

    // -- Helpers ---------------------------------------------------------------

    /**
     * An operator (RBAC) whose team operates "own" and "ownSecond" and only
     * views "viewed"; "foreign" belongs to another team.
     *
     * @return array{userId: int, own: int, ownSecond: int, viewed: int, foreign: int}
     */
    private function apiTeamScope(): array
    {
        $member = $this->authenticateWithRole('operator');
        $admin = (int)$this->createUser('api-inv-scope-admin')->id;
        $own = (int)$this->createProject($admin)->id;
        $ownSecond = (int)$this->createProject($admin)->id;
        $viewed = (int)$this->createProject($admin)->id;
        $foreign = (int)$this->createProject($admin)->id;
        $team = (int)$this->createTeam($admin)->id;
        $this->addTeamMember($team, (int)$member->id);
        $this->createTeamProject($team, $own, TeamProject::ROLE_OPERATOR);
        $this->createTeamProject($team, $ownSecond, TeamProject::ROLE_OPERATOR);
        $this->createTeamProject($team, $viewed, TeamProject::ROLE_VIEWER);
        $this->createTeamProject((int)$this->createTeam($admin)->id, $foreign, TeamProject::ROLE_OPERATOR);

        return [
            'userId' => (int)$member->id,
            'own' => $own,
            'ownSecond' => $ownSecond,
            'viewed' => $viewed,
            'foreign' => $foreign,
        ];
    }

    private function staticInventory(int $projectId): int
    {
        $inventory = $this->createInventory((int)$this->createUser('api-inv-owner')->id);
        $inventory->name = 'api-scoped-inventory';
        $inventory->content = "all:\n  hosts:\n    web1.example.com:\n";
        $inventory->project_id = $projectId;
        $inventory->save(false);

        return (int)$inventory->id;
    }

    /**
     * A manual project whose checkout is a copy of tests/fixtures/vault/repo,
     * scanned, with a file inventory of inventories/dev/hosts.yml and a job
     * template (playbook site.yml) whose vault password is the dev password.
     * The caller is an operator; the project belongs to no team.
     *
     * @return array{userId: int, project: int, inventory: int, template: int, credential: int}
     */
    private function vaultScope(): array
    {
        $userId = (int)$this->authenticateWithRole('operator')->id;
        $project = $this->createProject($userId);
        $project->local_path = $this->copyFixtureRepo();
        $project->vault_password_source = Project::VAULT_SOURCE_ANSILUME;
        $project->save(false);

        $inventory = $this->createInventory($userId);
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->content = null;
        $inventory->source_path = 'inventories/dev/hosts.yml';
        $inventory->project_id = (int)$project->id;
        $inventory->save(false);

        $credential = $this->createCredential($userId, Credential::TYPE_VAULT);
        $credential->secret_data = \Yii::$app->get('credentialService')
            ->encryptSecrets(['vault_password' => 'ansilume-test-dummy-dev']);
        $credential->save(false);

        $group = (int)$this->createRunnerGroup($userId)->id;
        $template = $this->createJobTemplate((int)$project->id, (int)$inventory->id, $group, $userId);
        $template->credential_id = (int)$credential->id;
        $template->save(false);

        /** @var VaultScanService $scans */
        $scans = \Yii::$app->get('vaultScanService');
        $this->assertTrue($scans->scanProject($project));

        return [
            'userId' => $userId,
            'project' => (int)$project->id,
            'inventory' => (int)$inventory->id,
            'template' => (int)$template->id,
            'credential' => (int)$credential->id,
        ];
    }

    private function updateAuditCount(int $inventoryId): int
    {
        return (int)AuditLog::find()
            ->where([
                'action' => AuditLog::ACTION_INVENTORY_UPDATED,
                'object_type' => 'inventory',
                'object_id' => $inventoryId,
            ])
            ->count();
    }

    /**
     * Copies tests/fixtures/vault/repo (dotfiles included) to a temporary
     * directory that tearDown() removes.
     */
    private function copyFixtureRepo(): string
    {
        $target = sys_get_temp_dir() . '/ansilume_api_inventory_vault_' . bin2hex(random_bytes(6));
        self::copyTree(dirname(__DIR__, 4) . '/fixtures/vault/repo', $target);

        return $this->checkouts[] = $target;
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0755, true);
        foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $name) {
            if (is_dir($from . '/' . $name)) {
                self::copyTree($from . '/' . $name, $to . '/' . $name);
            } else {
                copy($from . '/' . $name, $to . '/' . $name);
            }
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            self::removeTree($path . '/' . $name);
        }
        rmdir($path);
    }

    /**
     * Creates a user with one RBAC role and authenticates with an API token.
     */
    private function authenticateWithRole(string $roleName): User
    {
        $user = $this->createUser('api-inv-' . $roleName);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole($roleName);
        $this->assertNotNull($role, $roleName);
        $auth->assign($role, (string)$user->id);

        ['raw' => $raw] = ApiToken::generate((int)$user->id, $roleName . '-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);

        return $user;
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
