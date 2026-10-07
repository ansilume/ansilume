<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\JobTemplateWarnings;
use app\controllers\api\v1\JobTemplatesController;
use app\models\ApiToken;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\TeamProject;
use app\models\User;
use app\services\CredentialWriteService;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the Job Templates API controller.
 *
 * Exercises authentication, authorization, CRUD operations, validation,
 * and 404 handling against a real database (rolled back after each test).
 */
class JobTemplatesControllerTest extends WebControllerTestCase
{
    private JobTemplatesController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new JobTemplatesController('api/v1/job-templates', \Yii::$app);
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexReturnsPaginatedList(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);

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

    public function testViewReturnsTemplate(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);

        $data = $this->callSuccess($this->ctrl->actionView($template->id));
        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($template->id, $item['id']);
        $this->assertSame($template->name, $item['name']);
        $this->assertSame($project->id, $item['project_id']);
        $this->assertSame($inventory->id, $item['inventory_id']);
        $this->assertArrayHasKey('playbook', $item);
        $this->assertArrayHasKey('verbosity', $item);
        $this->assertArrayHasKey('forks', $item);
        $this->assertArrayHasKey('become', $item);
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
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);

        $this->setBody([
            'name' => 'api-test-template-' . uniqid('', true),
            'project_id' => $project->id,
            'inventory_id' => $inventory->id,
            'playbook' => 'deploy.yml',
            'runner_group_id' => $group->id,
        ]);

        $data = $this->callSuccess($this->ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);

        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertArrayHasKey('id', $item);
        $this->assertSame('deploy.yml', $item['playbook']);
        $this->assertSame($project->id, $item['project_id']);
        $this->assertSame($inventory->id, $item['inventory_id']);
    }

    public function testCreateRejects422OnMissingRequiredFields(): void
    {
        $this->authenticateWithAdmin();
        $this->setBody([
            'playbook' => 'deploy.yml',
        ]);

        $this->ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testCreateRejects403WithoutPermission(): void
    {
        $this->authenticateAs('no-create-perm');
        $this->setBody([
            'name' => 'forbidden-template',
            'playbook' => 'deploy.yml',
        ]);

        $this->ctrl->actionCreate();
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -- Update ---------------------------------------------------------------

    public function testUpdateWithValidData(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);

        $newName = 'updated-template-' . uniqid('', true);
        $this->setBody(['name' => $newName]);
        $data = $this->callSuccess($this->ctrl->actionUpdate($template->id));

        /** @var array<string, mixed> $item */
        $item = $data;
        $this->assertSame($template->id, $item['id']);
        $this->assertSame($newName, $item['name']);
    }

    // -- Delete ---------------------------------------------------------------

    public function testDeleteReturnsSuccess(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $userId);

        $data = $this->callSuccess($this->ctrl->actionDelete($template->id));
        /** @var array<string, mixed> $payload */
        $payload = $data;
        $this->assertTrue($payload['deleted']);

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView($template->id);
    }

    /**
     * Regression: the API hard-deleted templates while the web UI soft-deletes
     * them, so jobs lost their template link.
     */
    public function testDeleteIsASoftDeleteLikeTheWebUi(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $template = $this->createJobTemplate(
            $this->createProject($userId)->id,
            $this->createInventory($userId)->id,
            $this->createRunnerGroup($userId)->id,
            $userId
        );

        $this->ctrl->actionDelete($template->id);

        $row = \app\models\JobTemplate::findWithDeleted()->where(['id' => $template->id])->one();
        $this->assertNotNull($row, 'the row stays');
        $this->assertNotNull($row->deleted_at);
    }

    // -- Credentials -----------------------------------------------------------

    /**
     * @return array{project: int, inventory: int, group: int}
     */
    private function templateParents(int $userId): array
    {
        return [
            'project' => $this->createProject($userId)->id,
            'inventory' => $this->createInventory($userId)->id,
            'group' => $this->createRunnerGroup($userId)->id,
        ];
    }

    public function testCreateAcceptsAdditionalCredentialsInOrder(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $parents = $this->templateParents($userId);
        $primary = $this->createCredential($userId, \app\models\Credential::TYPE_SSH_KEY);
        $vault = $this->createCredential($userId, \app\models\Credential::TYPE_VAULT);
        $token = $this->createCredential($userId);
        $this->setBody([
            'name' => 'api-creds-' . uniqid('', true),
            'project_id' => $parents['project'],
            'inventory_id' => $parents['inventory'],
            'runner_group_id' => $parents['group'],
            'playbook' => 'site.yml',
            'credential_id' => $primary->id,
            'credential_ids' => [$token->id, $vault->id],
        ]);

        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame($parents['group'], $item['runner_group_id']);
        $this->assertSame([$token->id, $vault->id], $item['credential_ids']);
        $this->assertSame([$primary->id, $token->id, $vault->id], array_column($item['credentials'], 'id'));
        $this->assertSame(['primary', 'additional', 'additional'], array_column($item['credentials'], 'role'));
    }

    /**
     * Regression: changing credential_id over the API left the old primary
     * attached as an additional credential.
     */
    public function testChangingThePrimaryDetachesTheOldOne(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $parents = $this->templateParents($userId);
        $old = $this->createCredential($userId, \app\models\Credential::TYPE_SSH_KEY);
        $new = $this->createCredential($userId, \app\models\Credential::TYPE_SSH_KEY);
        $template = $this->createJobTemplate($parents['project'], $parents['inventory'], $parents['group'], $userId);
        $this->setBody(['credential_id' => $old->id, 'credential_ids' => []]);
        $this->ctrl->actionUpdate($template->id);

        $this->setBody(['credential_id' => $new->id]);
        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionUpdate($template->id));

        $this->assertSame([$new->id], array_column($item['credentials'], 'id'));
        $this->assertSame([], $item['credential_ids']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidCredentialBodyProvider(): array
    {
        return [
            'credential_ids not a list' => [['credential_ids' => 'abc'], 'credential_ids must be an array of credential IDs.'],
            'credential_ids an object' => [['credential_ids' => ['a' => 1]], 'credential_ids must be an array of credential IDs.'],
            'unknown additional credential' => [['credential_ids' => [999999999]], 'Credential #999999999 does not exist.'],
            // Regression: answered 500 (foreign key violation) instead of 422.
            'unknown primary credential' => [['credential_id' => 999999999], 'The selected credential does not exist.'],
        ];
    }

    /**
     * @dataProvider invalidCredentialBodyProvider
     * @param array<string, mixed> $body
     */
    public function testInvalidCredentialsAre422(array $body, string $message): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $parents = $this->templateParents($userId);
        $template = $this->createJobTemplate($parents['project'], $parents['inventory'], $parents['group'], $userId);
        $this->setBody($body);

        $result = $this->ctrl->actionUpdate($template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $message]], $result);
    }

    public function testCreateRejectsCredentialIdsThatAreNotAList(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $parents = $this->templateParents($userId);
        $before = \app\models\JobTemplate::find()->count();
        $this->setBody([
            'name' => 'api-bad-credential-ids',
            'project_id' => $parents['project'],
            'inventory_id' => $parents['inventory'],
            'runner_group_id' => $parents['group'],
            'playbook' => 'deploy.yml',
            'credential_ids' => 'abc',
        ]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'credential_ids must be an array of credential IDs.']], $result);
        $this->assertSame($before, \app\models\JobTemplate::find()->count());
    }

    public function testDeleteReturns404(): void
    {
        $this->authenticateWithAdmin();
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionDelete(999999);
    }

    // -- Vault passwords ------------------------------------------------------

    public function testCreateWithAVaultPrimaryAndASecondVaultIs422(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $parents = $this->templateParents($userId);
        $primary = $this->storedVault('vault-primary');
        $additional = $this->storedVault('vault-additional');
        $before = JobTemplate::find()->count();
        $this->setBody([
            'name' => 'api-two-vaults-' . uniqid('', true),
            'project_id' => $parents['project'],
            'inventory_id' => $parents['inventory'],
            'runner_group_id' => $parents['group'],
            'playbook' => 'site.yml',
            'credential_id' => $primary->id,
            'credential_ids' => [$additional->id],
        ]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $this->vaultConflictMessage($primary, $additional)]], $result);
        $this->assertSame($before, JobTemplate::find()->count());
    }

    /**
     * Templates stored with two vault passwords keep running, but every save
     * that keeps both is rejected, also one that only renames the template.
     */
    public function testRenamingALegacyTwoVaultTemplateIs422(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $vaultA = $this->storedVault('vault-a');
        $vaultB = $this->storedVault('vault-b');
        $template = $this->legacyVaultTemplate($userId, $vaultA, $vaultB);
        $this->setBody(['name' => 'renamed-' . uniqid('', true)]);

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $this->vaultConflictMessage($vaultA, $vaultB)]], $result);
        $this->assertSame($template->name, JobTemplate::findOne($template->id)?->name);
    }

    public function testDroppingTheSecondVaultFixesALegacyTemplate(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $vaultA = $this->storedVault('vault-a');
        $template = $this->legacyVaultTemplate($userId, $vaultA, $this->storedVault('vault-b'));
        $this->setBody(['credential_ids' => []]);

        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionUpdate((int)$template->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame([(int)$vaultA->id], array_column($item['credentials'], 'id'));
        $this->assertSame([], $item['credential_ids']);
        $this->assertSame([], $item['warnings']);
    }

    // -- Template warnings ----------------------------------------------------

    public function testViewOfACleanTemplateHasNoWarnings(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $parents = $this->templateParents($userId);
        $template = $this->createJobTemplate($parents['project'], $parents['inventory'], $parents['group'], $userId);
        $template->credential_id = $this->storedVault('vault-only')->id;
        $template->save(false);

        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionView((int)$template->id));

        $this->assertSame([], $item['warnings']);
    }

    public function testViewListsTheVaultPasswordsAnsibleIgnoresInPrecedenceOrder(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $first = $this->storedVault('vault-first');
        // Created before $second (lower id), but later in precedence.
        $third = $this->storedVault('vault-third');
        $second = $this->storedVault('vault-second');
        $template = $this->legacyVaultTemplate($userId, $first, $second, $third);

        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionView((int)$template->id));

        $this->assertCount(1, $item['warnings']);
        $warning = $item['warnings'][0];
        $this->assertSame(JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS, $warning['code']);
        $this->assertSame([(int)$second->id, (int)$third->id], $warning['credential_ids']);
        $this->assertSame(sprintf(
            'This template has 3 vault passwords, but Ansible gets only one: "%s" takes precedence and "%s" and "%s" are ignored. '
            . 'Saving the template is rejected until only one is left.',
            $first->name,
            $second->name,
            $third->name
        ), $warning['message']);
    }

    public function testViewWarnsAboutAFileInventoryOfAnotherProject(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $inventory = $this->otherProjectInventory($userId);
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$inventory->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );

        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionView((int)$template->id));

        $this->assertSame([JobTemplateWarnings::INVENTORY_OTHER_PROJECT], array_column($item['warnings'], 'code'));
        $this->assertSame([], $item['warnings'][0]['credential_ids']);
        $this->assertStringContainsString(
            "\"{$inventory->name}\" is a file or dynamic inventory of another project",
            $item['warnings'][0]['message']
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function warningCodeProvider(): array
    {
        return [
            'more than one vault password' => [JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS],
            'inventory of another project' => [JobTemplateWarnings::INVENTORY_OTHER_PROJECT],
        ];
    }

    /**
     * @dataProvider warningCodeProvider
     */
    public function testIndexFilteredByAWarningListsOnlyTheAffectedTemplates(string $code): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $parents = $this->templateParents($userId);
        $templates = [
            'clean' => $this->createJobTemplate($parents['project'], $parents['inventory'], $parents['group'], $userId),
            JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS => $this->legacyVaultTemplate(
                $userId,
                $this->storedVault('vault-a'),
                $this->storedVault('vault-b')
            ),
            JobTemplateWarnings::INVENTORY_OTHER_PROJECT => $this->createJobTemplate(
                (int)$this->createProject($userId)->id,
                (int)$this->otherProjectInventory($userId)->id,
                $parents['group'],
                $userId
            ),
        ];
        $this->setQueryParams(['warning' => $code]);

        $result = $this->ctrl->actionIndex();

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('data', $result);
        $ids = array_column($result['data'], 'id');
        foreach ($templates as $key => $template) {
            if ($key === $code) {
                $this->assertContains((int)$template->id, $ids, "{$key} template is listed");
            } else {
                $this->assertNotContains((int)$template->id, $ids, "{$key} template is not listed");
            }
        }
        foreach ($result['data'] as $item) {
            $this->assertContains($code, array_column($item['warnings'], 'code'), "template #{$item['id']} is listed without the warning");
        }
    }

    /**
     * The inventory filter joins the inventory table, which has id and name
     * columns of its own. The list still comes newest template first and
     * shows the templates' own names. The inventories are created in the
     * opposite order, so ordering by their ids would reverse the list.
     */
    public function testTheInventoryWarningFilterStillListsTheNewestTemplateFirst(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $group = (int)$this->createRunnerGroup($userId)->id;
        $newerInventory = $this->otherProjectInventory($userId);
        $olderInventory = $this->otherProjectInventory($userId);
        $older = $this->createJobTemplate((int)$this->createProject($userId)->id, (int)$olderInventory->id, $group, $userId);
        $newer = $this->createJobTemplate((int)$this->createProject($userId)->id, (int)$newerInventory->id, $group, $userId);
        $this->setQueryParams(['warning' => JobTemplateWarnings::INVENTORY_OTHER_PROJECT]);

        $result = $this->ctrl->actionIndex();

        $this->assertArrayHasKey('data', $result);
        $mine = array_values(array_filter(
            $result['data'],
            static fn (array $item): bool => in_array($item['id'], [(int)$older->id, (int)$newer->id], true)
        ));
        $this->assertSame([(int)$newer->id, (int)$older->id], array_column($mine, 'id'));
        $this->assertSame([$newer->name, $older->name], array_column($mine, 'name'));
    }

    public function testIndexWithAnUnknownWarningIs422NamingTheValidCodes(): void
    {
        $this->authenticateWithAdmin();
        $this->setQueryParams(['warning' => 'no_such_warning']);

        $result = $this->ctrl->actionIndex();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => 'Unknown warning. Use one of: multiple_vault_credentials, inventory_other_project.']],
            $result
        );
    }

    /**
     * Regression: ?warning[]=x reached a (string) cast, and the "Array to
     * string conversion" warning turned into a server error.
     */
    public function testIndexWithAnArrayAsWarningIs422(): void
    {
        $this->authenticateWithAdmin();
        $this->setQueryParams(['warning' => [JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS]]);

        $result = $this->ctrl->actionIndex();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => 'Unknown warning. Use one of: multiple_vault_credentials, inventory_other_project.']],
            $result
        );
    }

    public function testTheWarningFilterOnlyListsTemplatesTheUserMaySee(): void
    {
        $viewer = $this->authenticateWithRole('viewer');
        $ownerId = (int)$this->createUser('jt-api-owner')->id;
        $vaultA = $this->storedVault('vault-a');
        $vaultB = $this->storedVault('vault-b');
        $visible = $this->legacyVaultTemplate($ownerId, $vaultA, $vaultB);
        $team = $this->createTeam($ownerId);
        $this->addTeamMember((int)$team->id, (int)$viewer->id);
        $this->createTeamProject((int)$team->id, (int)$visible->project_id, TeamProject::ROLE_VIEWER);
        $hidden = $this->legacyVaultTemplate($ownerId, $vaultA, $vaultB);
        $this->createTeamProject((int)$this->createTeam($ownerId)->id, (int)$hidden->project_id);
        $this->setQueryParams(['warning' => JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS]);

        $result = (new JobTemplatesController('api/v1/job-templates', \Yii::$app))->runAction('index');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $ids = array_column($result['data'], 'id');
        $this->assertContains((int)$visible->id, $ids);
        $this->assertNotContains((int)$hidden->id, $ids, 'a template of another team is not listed');
    }

    // -- Inventories of other projects -----------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function projectBoundInventoryTypeProvider(): array
    {
        return [
            'file inventory' => [Inventory::TYPE_FILE],
            'dynamic inventory' => [Inventory::TYPE_DYNAMIC],
        ];
    }

    /**
     * @dataProvider projectBoundInventoryTypeProvider
     */
    public function testCreateWithAnInventoryOfAnotherProjectIs422(string $type): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $inventory = $this->otherProjectInventory($userId, $type);
        $before = JobTemplate::find()->count();
        $this->setBody($this->templateBody((int)$this->createProject($userId)->id, (int)$inventory->id));

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $this->inventoryMessage($inventory)]], $result);
        $this->assertSame($before, JobTemplate::find()->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function usableInventoryProvider(): array
    {
        return [
            'static inventory of another project' => ['static-of-another-project'],
            'file inventory of the same project' => ['file-of-the-same-project'],
            'dynamic inventory without a project' => ['dynamic-without-project'],
        ];
    }

    /**
     * @dataProvider usableInventoryProvider
     */
    public function testCreateAcceptsInventoriesTheRunnerCanUse(string $kind): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $projectId = (int)$this->createProject($userId)->id;
        $inventory = match ($kind) {
            'static-of-another-project' => $this->otherProjectInventory($userId, Inventory::TYPE_STATIC),
            'file-of-the-same-project' => $this->inventory($userId, Inventory::TYPE_FILE, $projectId),
            'dynamic-without-project' => $this->inventory($userId, Inventory::TYPE_DYNAMIC, null),
        };
        $this->setBody($this->templateBody($projectId, (int)$inventory->id));

        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame((int)$inventory->id, $item['inventory_id']);
        $this->assertSame([], $item['warnings']);
    }

    public function testUpdateOntoAnInventoryOfAnotherProjectIs422(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $parents = $this->templateParents($userId);
        $template = $this->createJobTemplate($parents['project'], $parents['inventory'], $parents['group'], $userId);
        $inventory = $this->otherProjectInventory($userId);
        $this->setBody(['inventory_id' => $inventory->id]);

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $this->inventoryMessage($inventory)]], $result);
        $this->assertSame($parents['inventory'], (int)JobTemplate::findOne($template->id)?->inventory_id);
    }

    public function testMovingATemplateAwayFromTheProjectOfItsFileInventoryIs422(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $projectId = (int)$this->createProject($userId)->id;
        $inventory = $this->inventory($userId, Inventory::TYPE_FILE, $projectId);
        $template = $this->createJobTemplate($projectId, (int)$inventory->id, (int)$this->createRunnerGroup($userId)->id, $userId);
        $this->setBody(['project_id' => $this->createProject($userId)->id]);

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $this->inventoryMessage($inventory)]], $result);
        $this->assertSame($projectId, (int)JobTemplate::findOne($template->id)?->project_id);
    }

    /**
     * Unlike a second vault password, an inventory of another project only
     * blocks changing the project or the inventory: older templates keep
     * saving and show the warning.
     */
    public function testRenamingALegacyTemplateWithAnInventoryOfAnotherProjectStillWorks(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->otherProjectInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        $newName = 'renamed-legacy-' . uniqid('', true);
        $this->setBody(['name' => $newName]);

        /** @var array<string, mixed> $item */
        $item = $this->callSuccess($this->ctrl->actionUpdate((int)$template->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame($newName, $item['name']);
        $this->assertSame([JobTemplateWarnings::INVENTORY_OTHER_PROJECT], array_column($item['warnings'], 'code'));
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * A vault password credential with a stored secret, owned by the
     * authenticated user.
     */
    // -- Team scoping on save ---------------------------------------------------

    /**
     * Regression: PUT checked operate access on the stored project only, so
     * a team operator could move a template into another team's project.
     */
    public function testUpdateCannotMoveATemplateIntoAnotherTeamsProject(): void
    {
        $scope = $this->apiTeamScope();
        $template = $this->createJobTemplate($scope['own'], $scope['ownInventory'], $scope['group'], $scope['userId']);
        $this->setBody(['project_id' => $scope['foreign']]);

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertSame($scope['own'], (int)JobTemplate::findOne($template->id)?->project_id);
    }

    /**
     * Regression: an inventory of a project the caller cannot see was
     * accepted, so a request could run playbooks against another team's
     * hosts. It is reported like an unknown inventory, also for file and
     * dynamic inventories, whose project rule message would name them.
     *
     * @return array<string, array{0: string}>
     */
    public static function hiddenInventoryTypeProvider(): array
    {
        return [
            'static' => [Inventory::TYPE_STATIC],
            'file' => [Inventory::TYPE_FILE],
            'dynamic' => [Inventory::TYPE_DYNAMIC],
        ];
    }

    /**
     * @dataProvider hiddenInventoryTypeProvider
     */
    public function testCreateRejectsAnInventoryOfAProjectTheCallerCannotSee(string $type): void
    {
        $scope = $this->apiTeamScope();
        $hidden = $this->inventory($scope['userId'], $type, $scope['foreign']);
        $body = $this->templateBody($scope['own'], (int)$hidden->id);
        $this->setBody($body);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'The selected inventory does not exist.']], $result);
        $this->assertNull(JobTemplate::findOne(['name' => $body['name']]));
    }

    /**
     * @dataProvider hiddenInventoryTypeProvider
     */
    public function testUpdateRejectsSwitchingToAnInventoryTheCallerCannotSee(string $type): void
    {
        $scope = $this->apiTeamScope();
        $template = $this->createJobTemplate($scope['own'], $scope['ownInventory'], $scope['group'], $scope['userId']);
        $hidden = $this->inventory($scope['userId'], $type, $scope['foreign']);
        $this->setBody(['inventory_id' => (int)$hidden->id]);

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'The selected inventory does not exist.']], $result);
        $this->assertSame($scope['ownInventory'], (int)JobTemplate::findOne($template->id)?->inventory_id);
    }

    public function testAnUnknownProjectIs422NotAServerError(): void
    {
        $this->authenticateWithAdmin();
        $userId = (int)\Yii::$app->user->id;
        $body = $this->templateBody((int)$this->createProject($userId)->id, (int)$this->createInventory($userId)->id);
        $body['project_id'] = 999999999;
        $this->setBody($body);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'The selected project does not exist.']], $result);
    }

    /**
     * An operator (RBAC) whose team operates "own"; "foreign" belongs to
     * another team and has a static inventory the caller cannot see.
     *
     * @return array{userId: int, own: int, foreign: int, ownInventory: int, foreignInventory: int, group: int}
     */
    private function apiTeamScope(): array
    {
        $member = $this->authenticateWithRole('operator');
        $admin = (int)$this->createUser('api-scope-admin')->id;
        $own = (int)$this->createProject($admin)->id;
        $foreign = (int)$this->createProject($admin)->id;
        $team = (int)$this->createTeam($admin)->id;
        $this->addTeamMember($team, (int)$member->id);
        $this->createTeamProject($team, $own, TeamProject::ROLE_OPERATOR);
        $this->createTeamProject((int)$this->createTeam($admin)->id, $foreign, TeamProject::ROLE_OPERATOR);

        return [
            'userId' => (int)$member->id,
            'own' => $own,
            'foreign' => $foreign,
            'ownInventory' => (int)$this->inventory($admin, Inventory::TYPE_STATIC, $own)->id,
            'foreignInventory' => (int)$this->inventory($admin, Inventory::TYPE_STATIC, $foreign)->id,
            'group' => (int)$this->createRunnerGroup($admin)->id,
        ];
    }

    private function storedVault(string $label): Credential
    {
        $credential = new Credential();
        $credential->name = $label . '-' . uniqid('', true);
        $credential->credential_type = Credential::TYPE_VAULT;
        $credential->created_by = (int)\Yii::$app->user->id;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($credential, ['vault_password' => 'vault-secret']), (string)json_encode($credential->errors));

        return $credential;
    }

    /**
     * A template stored before a second vault password was rejected: the
     * first vault is the primary credential, and every vault is a pivot row
     * in the given order (the first one too, as the service stores a
     * primary).
     */
    private function legacyVaultTemplate(int $userId, Credential ...$vaults): JobTemplate
    {
        $parents = $this->templateParents($userId);
        $template = $this->createJobTemplate($parents['project'], $parents['inventory'], $parents['group'], $userId);
        $template->credential_id = $vaults[0]->id;
        $template->save(false);
        foreach ($vaults as $sortOrder => $vault) {
            \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $template->id,
                'credential_id' => $vault->id,
                'sort_order' => $sortOrder,
            ])->execute();
        }

        return $template;
    }

    private function inventory(int $userId, string $type, ?int $projectId): Inventory
    {
        $inventory = new Inventory();
        $inventory->name = $type . '-inventory-' . uniqid('', true);
        $inventory->inventory_type = $type;
        $inventory->content = $type === Inventory::TYPE_STATIC ? "localhost\n" : null;
        $inventory->source_path = $type === Inventory::TYPE_STATIC ? null : 'inventories/hosts.yml';
        $inventory->project_id = $projectId;
        $inventory->created_by = $userId;
        $inventory->save(false);

        return $inventory;
    }

    /**
     * An inventory (a file inventory by default) of a project of its own.
     */
    private function otherProjectInventory(int $userId, string $type = Inventory::TYPE_FILE): Inventory
    {
        return $this->inventory($userId, $type, (int)$this->createProject($userId)->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateBody(int $projectId, int $inventoryId): array
    {
        return [
            'name' => 'api-template-' . uniqid('', true),
            'project_id' => $projectId,
            'inventory_id' => $inventoryId,
            'runner_group_id' => $this->createRunnerGroup((int)\Yii::$app->user->id)->id,
            'playbook' => 'site.yml',
        ];
    }

    private function vaultConflictMessage(Credential $first, Credential $second): string
    {
        return sprintf(
            'Only one vault password can be attached to a job template. "%s" and "%s" are both vault passwords; keep one of them.',
            $first->name,
            $second->name
        );
    }

    private function inventoryMessage(Inventory $inventory): string
    {
        return sprintf(
            'File and dynamic inventories must belong to the job template\'s project, but "%s" belongs to another project. '
            . 'Choose an inventory of this project or a static inventory.',
            $inventory->name
        );
    }

    /**
     * Create a user with one RBAC role and authenticate with an API token.
     */
    private function authenticateWithRole(string $roleName): User
    {
        $user = $this->createUser('api-' . $roleName);
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
