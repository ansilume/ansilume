<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\InventoryController;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\models\TeamProject;
use app\services\VaultScanService;
use yii\web\ForbiddenHttpException;

/**
 * InventoryController::actionUpdate(): which projects an inventory may be
 * moved to, and the vault check of the job templates using an inventory
 * after it changed.
 */
class InventoryControllerActionTest extends WebControllerTestCase
{
    /** @var list<string> */
    private array $checkouts = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->session->removeAllFlashes();
    }

    protected function tearDown(): void
    {
        \Yii::$app->session->removeAllFlashes();
        foreach ($this->checkouts as $checkout) {
            self::removeTree($checkout);
        }
        $this->checkouts = [];
        parent::tearDown();
    }

    public function testUpdateRendersTheFormOnGet(): void
    {
        $scope = $this->teamScope();
        $ctrl = $this->makeController();

        $this->assertSame('rendered:form', $ctrl->actionUpdate($scope['inventory']));

        $this->assertSame($scope['inventory'], $ctrl->capturedParams['model']->id);
        /** @var list<Project> $projects */
        $projects = $ctrl->capturedParams['projects'];
        $projectIds = array_map(static fn (Project $project): int => (int)$project->id, $projects);
        $this->assertContains($scope['own'], $projectIds);
        $this->assertNotContains($scope['foreign'], $projectIds);
    }

    /**
     * Regression: update checked operate access on the stored project only,
     * so a team operator could move an inventory into a project of another
     * team, or into one their team may only view.
     *
     * @return array<string, array{0: string}>
     */
    public static function projectTheUserMayNotOperateProvider(): array
    {
        return ['another team\'s project' => ['foreign'], 'a project the team only views' => ['viewed']];
    }

    /**
     * @dataProvider projectTheUserMayNotOperateProvider
     */
    public function testUpdateCannotMoveAnInventoryIntoAProjectTheUserMayNotOperate(string $target): void
    {
        $scope = $this->teamScope();
        $this->setPost(['Inventory' => ['name' => 'moved-inventory', 'project_id' => (string)$scope[$target]]]);

        try {
            $this->makeController()->actionUpdate($scope['inventory']);
            $this->fail('Moving the inventory into that project must be forbidden.');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You do not have permission to modify this resource.', $e->getMessage());
        }

        $stored = Inventory::findOne($scope['inventory']);
        $this->assertNotNull($stored);
        $this->assertSame($scope['own'], $stored->project_id);
        $this->assertSame('scoped-inventory', $stored->name);
        $this->assertSame(0, $this->updateAuditCount($scope['inventory']));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function projectTheUserMayOperateProvider(): array
    {
        return ['another project the team operates' => ['ownSecond'], 'no project' => ['none']];
    }

    /**
     * @dataProvider projectTheUserMayOperateProvider
     */
    public function testUpdateMovesAnInventoryIntoAProjectTheUserMayOperate(string $target): void
    {
        $scope = $this->teamScope();
        $projectId = $target === 'none' ? null : $scope[$target];
        $this->setPost(['Inventory' => ['name' => 'moved-inventory', 'project_id' => (string)$projectId]]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($scope['inventory']);

        $this->assertSame(['view', 'id' => $scope['inventory']], $ctrl->capturedRedirect);
        $stored = Inventory::findOne($scope['inventory']);
        $this->assertNotNull($stored);
        $this->assertSame($projectId, $stored->project_id);
        $this->assertSame('moved-inventory', $stored->name);
        $this->assertSame(1, $this->updateAuditCount($scope['inventory']));
        $this->assertSame(['success' => 'Inventory "moved-inventory" updated.'], \Yii::$app->session->getAllFlashes());
    }

    public function testAnInvalidUpdateRendersTheFormAndSavesNothing(): void
    {
        $scope = $this->teamScope();
        $this->setPost(['Inventory' => ['name' => '', 'project_id' => (string)$scope['ownSecond']]]);

        $ctrl = $this->makeController();

        $this->assertSame('rendered:form', $ctrl->actionUpdate($scope['inventory']));
        $this->assertArrayHasKey('name', $ctrl->capturedParams['model']->getErrors());
        $stored = Inventory::findOne($scope['inventory']);
        $this->assertNotNull($stored);
        $this->assertSame($scope['own'], $stored->project_id);
        $this->assertSame(0, $this->updateAuditCount($scope['inventory']));
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
        $this->assertNotNull($before, 'the scan checks the template');
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $before->status);
        $this->assertSame(3, $before->relevant_count);
        $this->setPost(['Inventory' => ['source_path' => 'inventories/prod/hosts.yml']]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($vault['inventory']);

        $this->assertSame(['view', 'id' => $vault['inventory']], $ctrl->capturedRedirect);
        $after = JobTemplateVaultCheck::findOne($vault['template']);
        $this->assertNotNull($after);
        $this->assertSame(JobTemplateVaultCheck::STATUS_MISMATCH, $after->status);
        $this->assertSame(4, $after->relevant_count);
        $this->assertSame($vault['credential'], $after->credential_id);
        $this->assertSame([
            ['path' => 'inventories/prod/group_vars/all/vault.yml', 'line' => null, 'key' => null, 'reason' => null],
            ['path' => 'inventories/prod/host_vars/prod-web1.yml', 'line' => 2, 'key' => 'host_secret', 'reason' => null],
        ], $after->unopenedEntries());
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
        $this->setPost(['Inventory' => ['name' => 'renamed-dev-inventory']]);

        $this->makeController()->actionUpdate($vault['inventory']);

        $check = JobTemplateVaultCheck::findOne($late->id);
        $this->assertNotNull($check);
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $check->status);
        $this->assertSame(3, $check->relevant_count);
    }

    // -- Helpers ---------------------------------------------------------------

    /**
     * A logged-in user without RBAC admin whose team operates "own" and
     * "ownSecond" and only views "viewed"; "foreign" belongs to another
     * team. "inventory" is a static inventory of "own".
     *
     * @return array{userId: int, own: int, ownSecond: int, viewed: int, foreign: int, inventory: int}
     */
    private function teamScope(): array
    {
        $admin = (int)$this->createUser('inv-scope-admin')->id;
        $member = $this->createUser('inv-scope-member');
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
        $inventory = $this->createInventory($admin);
        $inventory->name = 'scoped-inventory';
        $inventory->content = "all:\n  hosts:\n    web1.example.com:\n";
        $inventory->project_id = $own;
        $inventory->save(false);
        $this->loginAs($member);

        return [
            'userId' => (int)$member->id,
            'own' => $own,
            'ownSecond' => $ownSecond,
            'viewed' => $viewed,
            'foreign' => $foreign,
            'inventory' => (int)$inventory->id,
        ];
    }

    /**
     * A manual project whose checkout is a copy of tests/fixtures/vault/repo,
     * scanned, with a file inventory of inventories/dev/hosts.yml and a job
     * template (playbook site.yml) whose vault password is the dev password.
     * The user is logged in; the project belongs to no team.
     *
     * @return array{userId: int, project: int, inventory: int, template: int, credential: int}
     */
    private function vaultScope(): array
    {
        $user = $this->createUser('inv-vault');
        $this->loginAs($user);
        $userId = (int)$user->id;
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
        $target = sys_get_temp_dir() . '/ansilume_inventory_vault_' . bin2hex(random_bytes(6));
        self::copyTree(dirname(__DIR__, 2) . '/fixtures/vault/repo', $target);

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

    private function makeController(): InventoryController
    {
        return new class ('inventory', \Yii::$app) extends InventoryController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                $this->capturedRedirect = $url;
                return new \yii\web\Response();
            }
        };
    }
}
