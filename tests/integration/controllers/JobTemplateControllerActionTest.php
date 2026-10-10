<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\JobTemplateWarnings;
use app\controllers\JobTemplateController;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\JobTemplateVaultCheck;
use app\models\TeamProject;
use app\models\User;
use app\services\CredentialWriteService;
use app\services\JobLaunchService;
use app\services\JobTemplateCredentialService;
use app\services\LintService;
use yii\data\ActiveDataProvider;
use yii\helpers\Html;
use yii\web\AssetManager;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\View;

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

    // ── actionIndex(): template warnings ────────────────────────────────────

    public function testIndexCountsTheWarningsOfTheTemplatesTheUserMaySee(): void
    {
        $admin = $this->createUser('jt-warn-admin');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $adminRole = $auth->getRole('admin');
        $this->assertNotNull($adminRole);
        $auth->assign($adminRole, (string)$admin->id);
        $member = $this->createUser('jt-warn-member');
        $adminBefore = $this->warningCountsFor($admin);
        $memberBefore = $this->warningCountsFor($member);
        $ownerId = (int)$admin->id;
        $vaultA = $this->storedVault($ownerId, 'vault-a');
        $vaultB = $this->storedVault($ownerId, 'vault-b');

        // Open project: every user sees it.
        $this->giveTwoVaults($this->makeTemplate($ownerId), $vaultA, $vaultB);
        // Restricted to the member's team.
        $teamTemplate = $this->templateWithInventory($ownerId, $this->otherProjectInventory($ownerId));
        $team = $this->createTeam($ownerId);
        $this->addTeamMember((int)$team->id, (int)$member->id);
        $this->createTeamProject((int)$team->id, (int)$teamTemplate->project_id, TeamProject::ROLE_VIEWER);
        // Restricted to another team: both warnings, but hidden from the member.
        $hidden = $this->templateWithInventory($ownerId, $this->otherProjectInventory($ownerId));
        $this->giveTwoVaults($hidden, $vaultA, $vaultB);
        $this->createTeamProject((int)$this->createTeam($ownerId)->id, (int)$hidden->project_id);
        // No warning: one vault password and a static inventory.
        $clean = $this->makeTemplate($ownerId);
        $clean->credential_id = $vaultA->id;
        $clean->save(false);

        $this->assertSame([
            JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS => $adminBefore[JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS] + 2,
            JobTemplateWarnings::INVENTORY_OTHER_PROJECT => $adminBefore[JobTemplateWarnings::INVENTORY_OTHER_PROJECT] + 2,
            JobTemplateWarnings::VAULT_PASSWORD_MISMATCH => $adminBefore[JobTemplateWarnings::VAULT_PASSWORD_MISMATCH],
            JobTemplateWarnings::VAULT_PASSWORD_MISSING => $adminBefore[JobTemplateWarnings::VAULT_PASSWORD_MISSING],
            JobTemplateWarnings::VAULT_FILE_DAMAGED => $adminBefore[JobTemplateWarnings::VAULT_FILE_DAMAGED],
            JobTemplateWarnings::VAULT_CHECK_INCOMPLETE => $adminBefore[JobTemplateWarnings::VAULT_CHECK_INCOMPLETE],
        ], $this->warningCountsFor($admin), 'admins count every template');
        $memberCounts = $this->warningCountsFor($member);
        $this->assertSame([
            JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS => $memberBefore[JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS] + 1,
            JobTemplateWarnings::INVENTORY_OTHER_PROJECT => $memberBefore[JobTemplateWarnings::INVENTORY_OTHER_PROJECT] + 1,
            JobTemplateWarnings::VAULT_PASSWORD_MISMATCH => $memberBefore[JobTemplateWarnings::VAULT_PASSWORD_MISMATCH],
            JobTemplateWarnings::VAULT_PASSWORD_MISSING => $memberBefore[JobTemplateWarnings::VAULT_PASSWORD_MISSING],
            JobTemplateWarnings::VAULT_FILE_DAMAGED => $memberBefore[JobTemplateWarnings::VAULT_FILE_DAMAGED],
            JobTemplateWarnings::VAULT_CHECK_INCOMPLETE => $memberBefore[JobTemplateWarnings::VAULT_CHECK_INCOMPLETE],
        ], $memberCounts, 'the member counts only templates of open projects and of the own team');

        // Unfiltered there is no active warning, and a filter leaves the counts alone.
        $this->assertNull($this->indexParams()['activeWarning']);
        $this->assertSame(
            $memberCounts,
            $this->indexParams(['warning' => JobTemplateWarnings::INVENTORY_OTHER_PROJECT])['warningCounts']
        );
    }

    public function testIndexFilteredByMultipleVaultCredentialsListsOnlyThoseTemplates(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $vaultA = $this->storedVault($userId, 'vault-a');
        $vaultB = $this->storedVault($userId, 'vault-b');
        $primaryAndAdditional = $this->makeTemplate($userId);
        $this->giveTwoVaults($primaryAndAdditional, $vaultA, $vaultB);
        $additionalOnly = $this->makeTemplate($userId);
        $this->attachInOrder($additionalOnly, [$vaultA, $vaultB]);
        // One vault password, stored the way the service stores a primary:
        // as credential_id and as the first pivot row.
        $oneVault = $this->makeTemplate($userId);
        $oneVault->credential_id = $vaultA->id;
        $this->assertTrue($this->credentialService()->saveWithCredentials($oneVault, [$this->createCredential($userId)->id]));
        $clean = $this->makeTemplate($userId);

        $params = $this->indexParams(['warning' => JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS]);

        $this->assertSame(JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS, $params['activeWarning']);
        $listed = $this->listedIds($params);
        $this->assertContains((int)$primaryAndAdditional->id, $listed);
        $this->assertContains((int)$additionalOnly->id, $listed);
        $this->assertNotContains((int)$oneVault->id, $listed);
        $this->assertNotContains((int)$clean->id, $listed);
        $this->assertEveryListedTemplateHas(JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS, $params);
    }

    public function testIndexFilteredByInventoryOfAnotherProjectListsOnlyThoseTemplates(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $file = $this->templateWithInventory($userId, $this->otherProjectInventory($userId));
        $dynamic = $this->templateWithInventory($userId, $this->otherProjectInventory($userId, Inventory::TYPE_DYNAMIC));
        $ownInventory = $this->otherProjectInventory($userId);
        $own = $this->createJobTemplate(
            (int)$ownInventory->project_id,
            (int)$ownInventory->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        // Static inventories work with any project, even when one is set.
        $static = $this->templateWithInventory($userId, $this->otherProjectInventory($userId, Inventory::TYPE_STATIC));
        // A dynamic inventory without a project counts as the template's own.
        $unbound = $this->templateWithInventory($userId, $this->inventory($userId, Inventory::TYPE_DYNAMIC, null));

        $params = $this->indexParams(['warning' => JobTemplateWarnings::INVENTORY_OTHER_PROJECT]);

        $this->assertSame(JobTemplateWarnings::INVENTORY_OTHER_PROJECT, $params['activeWarning']);
        $listed = $this->listedIds($params);
        $this->assertContains((int)$file->id, $listed);
        $this->assertContains((int)$dynamic->id, $listed);
        $this->assertNotContains((int)$own->id, $listed);
        $this->assertNotContains((int)$static->id, $listed);
        $this->assertNotContains((int)$unbound->id, $listed);
        $this->assertEveryListedTemplateHas(JobTemplateWarnings::INVENTORY_OTHER_PROJECT, $params);
    }

    /**
     * Regression: ?warning[]=x reached a (string) cast, and the "Array to
     * string conversion" warning turned into a server error.
     */
    public function testIndexIgnoresAWarningGivenAsAnArray(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $legacy = $this->makeTemplate((int)$user->id);
        $this->giveTwoVaults($legacy, $this->storedVault((int)$user->id, 'vault-a'), $this->storedVault((int)$user->id, 'vault-b'));

        $params = $this->indexParams(['warning' => [JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS]]);

        $this->assertNull($params['activeWarning']);
        $this->assertContains((int)$legacy->id, $this->listedIds($params));
    }

    public function testIndexIgnoresAnUnknownWarningCode(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $legacy = $this->makeTemplate($userId);
        $this->giveTwoVaults($legacy, $this->storedVault($userId, 'vault-a'), $this->storedVault($userId, 'vault-b'));
        $clean = $this->makeTemplate($userId);

        $params = $this->indexParams(['warning' => 'no_such_warning']);

        $this->assertNull($params['activeWarning']);
        $listed = $this->listedIds($params);
        $this->assertContains((int)$legacy->id, $listed, 'the list is not filtered');
        $this->assertContains((int)$clean->id, $listed, 'the list is not filtered');
        $this->assertSame(JobTemplateWarnings::CODES, array_keys($params['warningCounts']));
    }

    /**
     * Template "b" is created first (lower id), and its inventory and
     * project names sort before those of template "a". A null sort sends no
     * sort parameter at all (an empty one would switch off the default
     * order).
     *
     * @return array<string, array{0: string|null, 1: list<string>}>
     */
    public static function inventoryFilteredSortProvider(): array
    {
        return [
            'default order' => [null, ['a', 'b']],
            'name' => ['name', ['a', 'b']],
            'name descending' => ['-name', ['b', 'a']],
            'id' => ['id', ['b', 'a']],
            'id descending' => ['-id', ['a', 'b']],
            'inventory' => ['inventory', ['b', 'a']],
            'inventory descending' => ['-inventory', ['a', 'b']],
            'project' => ['project', ['b', 'a']],
        ];
    }

    /**
     * Sorting the list filtered by inventory_other_project. The filter joins
     * the inventory table, which has id and name columns of its own, and
     * sorting by inventory joins that table a second time. Every sort must
     * still order by the right table and fill the templates with their own
     * columns, not with the inventory's.
     *
     * Guards the table-qualified sort attributes, introduced against
     * ambiguous columns. Note: MariaDB resolves an unqualified ORDER BY name
     * or id against the job_template.* select list Yii uses for joined
     * queries, so the former unqualified attributes pass as well; this fails
     * once a select or join change lets the columns clash.
     *
     * @dataProvider inventoryFilteredSortProvider
     * @param list<string> $expected template keys in listed order
     */
    public function testIndexSortsTheListFilteredByInventoryOfAnotherProject(?string $sort, array $expected): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $templates = [];
        $templates['b'] = $this->crossProjectTemplateNamed($userId, 'b', 'a');
        $templates['a'] = $this->crossProjectTemplateNamed($userId, 'a', 'b');

        $query = ['warning' => JobTemplateWarnings::INVENTORY_OTHER_PROJECT];
        if ($sort !== null) {
            $query['sort'] = $sort;
        }
        $listed = $this->listedTemplates($this->indexParams($query));

        $mine = array_values(array_filter(
            $listed,
            static fn (JobTemplate $t): bool => in_array((int)$t->id, [(int)$templates['a']->id, (int)$templates['b']->id], true)
        ));
        $this->assertSame(
            array_map(static fn (string $key): int => (int)$templates[$key]->id, $expected),
            array_map(static fn (JobTemplate $t): int => (int)$t->id, $mine)
        );
        $this->assertSame(
            array_map(static fn (string $key): string => $templates[$key]->name, $expected),
            array_map(static fn (JobTemplate $t): string => $t->name, $mine),
            'the templates carry their own names, not those of the joined inventory'
        );
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

    public function testViewAndLaunchListTheCredentialsInPrecedenceOrder(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate($user->id);
        $older = $this->createCredential($user->id, \app\models\Credential::TYPE_TOKEN);
        $primary = $this->createCredential($user->id, \app\models\Credential::TYPE_SSH_KEY);
        $tpl->credential_id = $primary->id;
        $tpl->save(false);
        \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
            'job_template_id' => $tpl->id,
            'credential_id' => $older->id,
            'sort_order' => 0,
        ])->execute();
        $expected = [
            ['id' => (int)$primary->id, 'name' => $primary->name, 'credential_type' => \app\models\Credential::TYPE_SSH_KEY, 'role' => \app\models\Credential::ROLE_PRIMARY],
            ['id' => (int)$older->id, 'name' => $older->name, 'credential_type' => \app\models\Credential::TYPE_TOKEN, 'role' => \app\models\Credential::ROLE_ADDITIONAL],
        ];

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$tpl->id);
        $this->assertSame($expected, $ctrl->capturedParams['attachedCredentials']);

        $this->setQueryParams(['id' => (string)$tpl->id]);
        $launch = $this->makeController();
        $launch->actionLaunch();
        $this->assertSame($expected, $launch->capturedParams['attachedCredentials']);
    }

    public function testViewThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionView(9999999);
    }

    public function testViewPassesTheWarningsOfALegacyTemplate(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $inventory = $this->otherProjectInventory($userId);
        $template = $this->templateWithInventory($userId, $inventory);
        $vaultA = $this->storedVault($userId, 'vault-a');
        $vaultB = $this->storedVault($userId, 'vault-b');
        $this->giveTwoVaults($template, $vaultA, $vaultB);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$template->id);

        $warnings = $ctrl->capturedParams['warnings'];
        $this->assertSame(
            [JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS, JobTemplateWarnings::INVENTORY_OTHER_PROJECT],
            array_column($warnings, 'code')
        );
        $this->assertSame([(int)$vaultB->id], $warnings[0]['credential_ids'], 'the vault password Ansible ignores');
        $this->assertStringContainsString(
            "\"{$vaultA->name}\" takes precedence and \"{$vaultB->name}\" is ignored",
            $warnings[0]['message']
        );
        $this->assertSame([], $warnings[1]['credential_ids']);
        $this->assertStringContainsString("\"{$inventory->name}\" is a file or dynamic inventory of another project", $warnings[1]['message']);
    }

    public function testViewPassesNoWarningsForATemplateWithOneVaultAndAnInventoryOfItsProject(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $inventory = $this->otherProjectInventory($userId);
        $template = $this->createJobTemplate(
            (int)$inventory->project_id,
            (int)$inventory->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        $template->credential_id = $this->storedVault($userId, 'vault-only')->id;
        $this->assertTrue($this->credentialService()->saveWithCredentials($template, [$this->createCredential($userId)->id]));

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$template->id);

        $this->assertSame([], $ctrl->capturedParams['warnings']);
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

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function templatePost(\app\models\User $user, string $name, array $extra = []): array
    {
        return [
            'JobTemplate' => [
                'name' => $name,
                'project_id' => $this->createProject($user->id)->id,
                'inventory_id' => $this->createInventory($user->id)->id,
                'runner_group_id' => $this->createRunnerGroup($user->id)->id,
                'playbook' => 'site.yml',
                'verbosity' => 0,
                'forks' => 5,
                'timeout_minutes' => 60,
                'become' => 0,
                'become_method' => 'sudo',
                'become_user' => 'root',
            ] + $extra,
        ];
    }

    public function testCreateStoresTheCheckedCredentialsInFormOrder(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $primary = $this->createCredential($user->id, \app\models\Credential::TYPE_SSH_KEY);
        $second = $this->createCredential($user->id);
        $first = $this->createCredential($user->id, \app\models\Credential::TYPE_VAULT);
        $this->setPost($this->templatePost($user, 'tpl-creds', ['credential_id' => $primary->id]) + [
            'credential_ids' => [(string)$first->id, (string)$second->id],
        ]);

        $this->makeController()->actionCreate();

        $stored = JobTemplate::findOne(['name' => 'tpl-creds']);
        $this->assertNotNull($stored);
        $this->assertSame(
            [$primary->id, $first->id, $second->id],
            array_map(static fn (\app\models\Credential $c): int => (int)$c->id, $stored->orderedCredentials())
        );
    }

    public function testAnUnknownCredentialReRendersTheFormWithTheSelection(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $known = $this->createCredential($user->id);
        $this->setPost($this->templatePost($user, 'tpl-bad-creds') + ['credential_ids' => [(string)$known->id, '999999999']]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertSame('Credential #999999999 does not exist.', $ctrl->capturedParams['model']->getFirstError('credential_ids'));
        $this->assertSame([$known->id, 999999999], $ctrl->capturedParams['selectedCredentialIds']);
        $this->assertNull(JobTemplate::findOne(['name' => 'tpl-bad-creds']));
    }

    public function testUncheckingEveryCredentialDetachesTheAdditionalOnes(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $template = $this->makeTemplate($user->id);
        $extra = $this->createCredential($user->id);
        \Yii::$app->get('jobTemplateCredentialService')->saveWithCredentials($template, [$extra->id]);
        $this->setPost(['JobTemplate' => ['description' => 'no extras any more']]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($template->id);

        $this->assertSame([], \Yii::$app->get('jobTemplateCredentialService')->additionalIds(JobTemplate::findOne($template->id)));
    }

    public function testTheFormShowsTheStoredAdditionalCredentials(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $template = $this->makeTemplate($user->id);
        $extra = $this->createCredential($user->id);
        \Yii::$app->get('jobTemplateCredentialService')->saveWithCredentials($template, [$extra->id]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($template->id);

        $this->assertSame([$extra->id], $ctrl->capturedParams['selectedCredentialIds']);
    }

    public function testCreatingATemplateWithTwoVaultsIsRejected(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $vaultA = $this->storedVault((int)$user->id, 'vault-a');
        $vaultB = $this->storedVault((int)$user->id, 'vault-b');
        $name = 'tpl-two-vaults-' . uniqid('', true);
        $this->setPost($this->templatePost($user, $name, ['credential_id' => $vaultA->id]) + [
            'credential_ids' => [(string)$vaultB->id],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertSame($this->vaultConflictMessage($vaultA, $vaultB), $ctrl->capturedParams['model']->getFirstError('credential_ids'));
        $this->assertSame([], $ctrl->capturedParams['warnings'], 'a new template has no stored state to warn about');
        $this->assertNull(JobTemplate::findOne(['name' => $name]));
    }

    public function testCreatingATemplateWithAnInventoryOfAnotherProjectIsRejected(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $inventory = $this->otherProjectInventory((int)$user->id);
        $name = 'tpl-other-inventory-' . uniqid('', true);
        $post = $this->templatePost($user, $name);
        $post['JobTemplate']['inventory_id'] = $inventory->id;
        $this->setPost($post);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertSame($this->inventoryMessage($inventory), $ctrl->capturedParams['model']->getFirstError('inventory_id'));
        $this->assertNull(JobTemplate::findOne(['name' => $name]));
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

    // ── actionUpdate(): vault passwords and warnings ────────────────────────

    public function testTheEditFormShowsTheWarningsOfALegacyTemplate(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $vaultA = $this->storedVault($userId, 'vault-a');
        $vaultB = $this->storedVault($userId, 'vault-b');
        $template = $this->makeTemplate($userId);
        $this->giveTwoVaults($template, $vaultA, $vaultB);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate((int)$template->id);

        $warnings = $ctrl->capturedParams['warnings'];
        $this->assertSame([JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS], array_column($warnings, 'code'));
        $this->assertSame([(int)$vaultB->id], $warnings[0]['credential_ids']);
    }

    /**
     * A rejected submission must not change what the form warns about: the
     * warnings describe the stored template, which keeps running as it is.
     */
    public function testTheEditFormWarnsAboutTheStoredTemplateNotAboutARejectedSubmission(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $vaultA = $this->storedVault($userId, 'vault-a');
        $vaultB = $this->storedVault($userId, 'vault-b');
        // Stored: an inventory of another project and one vault password.
        $otherInventory = $this->otherProjectInventory($userId);
        $template = $this->templateWithInventory($userId, $otherInventory);
        $template->credential_id = $vaultA->id;
        $template->save(false);
        $this->attachInOrder($template, [$vaultA]);
        // Submitted: a static inventory, and vault B as primary next to vault A.
        $this->setPost([
            'JobTemplate' => ['inventory_id' => $this->createInventory($userId)->id, 'credential_id' => $vaultB->id],
            'credential_ids' => [(string)$vaultA->id],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$template->id);

        $this->assertSame('rendered:form', $result);
        $this->assertSame($this->vaultConflictMessage($vaultB, $vaultA), $ctrl->capturedParams['model']->getFirstError('credential_ids'));
        $this->assertSame(
            [JobTemplateWarnings::INVENTORY_OTHER_PROJECT],
            array_column($ctrl->capturedParams['warnings'], 'code'),
            'the stored inventory, not the submitted one; the stored single vault, not the submitted two'
        );
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);
        $this->assertSame((int)$otherInventory->id, (int)$stored->inventory_id);
        $this->assertSame([(int)$vaultA->id], array_column($stored->credentialSnapshot(), 'id'));
    }

    public function testAnEditThatAddsASecondVaultIsRejectedAndSavesNothing(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $vaultA = $this->storedVault($userId, 'vault-a');
        $vaultB = $this->storedVault($userId, 'vault-b');
        $template = $this->makeTemplate($userId);
        $template->credential_id = $vaultA->id;
        $this->assertTrue($this->credentialService()->saveWithCredentials($template, []));
        $updatesBefore = $this->auditCount(AuditLog::ACTION_TEMPLATE_UPDATED, (int)$template->id);
        $this->setPost([
            'JobTemplate' => ['name' => 'renamed-' . uniqid('', true)],
            'credential_ids' => [(string)$vaultB->id],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$template->id);

        $this->assertSame('rendered:form', $result);
        $this->assertSame($this->vaultConflictMessage($vaultA, $vaultB), $ctrl->capturedParams['model']->getFirstError('credential_ids'));
        $this->assertSame([(int)$vaultB->id], $ctrl->capturedParams['selectedCredentialIds'], 'the submitted selection is shown again');
        $this->assertSame([], $ctrl->capturedParams['warnings'], 'the stored template has one vault password');
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);
        $this->assertSame($template->name, $stored->name);
        $this->assertSame([(int)$vaultA->id], array_column($stored->credentialSnapshot(), 'id'));
        $this->assertSame($updatesBefore, $this->auditCount(AuditLog::ACTION_TEMPLATE_UPDATED, (int)$template->id));
        /** @var object{runCalls: int} $lint */
        $lint = \Yii::$app->get('lintService');
        $this->assertSame(0, $lint->runCalls);
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

    // ── actionClone() ────────────────────────────────────────────────────────

    public function testClonePersistsDuplicateAndAuditsWithLineage(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $source = $this->makeTemplate($user->id);
        // Exercise a few fields that must round-trip through the clone.
        $source->extra_vars = '{"env":"staging"}';
        $source->forks = 12;
        $source->become = true;
        $source->timeout_minutes = 30;
        $source->save(false);

        $ctrl = $this->makeController();
        $result = $ctrl->actionClone((int)$source->id);

        $this->assertInstanceOf(Response::class, $result);

        /** @var JobTemplate|null $clone */
        $clone = JobTemplate::find()
            ->where(['name' => $source->name . ' (copy)'])
            ->one();
        $this->assertNotNull($clone, 'Clone must be persisted under "<source> (copy)".');
        $this->assertNotSame((int)$source->id, (int)$clone->id);

        // Config carried over.
        $this->assertSame('{"env":"staging"}', $clone->extra_vars);
        $this->assertSame(12, (int)$clone->forks);
        $this->assertSame(1, (int)$clone->become);
        $this->assertSame(30, (int)$clone->timeout_minutes);

        // created_by belongs to the cloning user, not the source's creator.
        $this->assertSame($user->id, (int)$clone->created_by);

        // Audit entry is plain CREATED with lineage in meta.
        $audit = AuditLog::findOne([
            'action' => AuditLog::ACTION_TEMPLATE_CREATED,
            'object_id' => $clone->id,
        ]);
        $this->assertNotNull($audit);
        $meta = json_decode((string)$audit->metadata, true);
        $this->assertIsArray($meta);
        $this->assertSame((int)$source->id, (int)$meta['cloned_from']);
        $this->assertSame($source->name, (string)$meta['cloned_from_name']);
    }

    public function testCloneStripsStaleAndSensitiveFields(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $source = $this->makeTemplate($user->id);
        $source->trigger_token = hash('sha256', 'some-raw-token');
        $source->lint_output = 'fake lint output';
        $source->lint_at = time();
        $source->lint_exit_code = 2;
        $source->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionClone((int)$source->id);

        /** @var JobTemplate $clone */
        $clone = JobTemplate::find()->where(['name' => $source->name . ' (copy)'])->one();
        $this->assertNull($clone->trigger_token, 'Trigger token must not leak to the clone.');
        $this->assertNull($clone->lint_output);
        $this->assertNull($clone->lint_at);
        $this->assertNull($clone->lint_exit_code);
    }

    /**
     * Regression: the clone copied trigger_token_created_by although it has
     * no token. The column's foreign key then kept the token's generator
     * from being deleted, also after the source token was revoked, and the
     * clone's page offers no token to revoke.
     */
    public function testCloneDoesNotKeepTheGeneratorOfTheSourceToken(): void
    {
        $admin = $this->createUser('clone_admin');
        $generator = $this->createUser('clone_token_generator');
        $this->loginAs($admin);
        $source = $this->makeTemplate($admin->id);
        $source->generateTriggerToken($generator->id);

        $this->makeController()->actionClone((int)$source->id);

        $clone = JobTemplate::findOne(['name' => $source->name . ' (copy)']);
        $this->assertNotNull($clone);
        $this->assertNull($clone->trigger_token);
        $this->assertNull($clone->trigger_token_created_by);

        $source->revokeTriggerToken();
        $this->assertSame(1, $generator->delete());
        $this->assertNull(User::findOne($generator->id));
    }

    public function testClonePicksNonCollidingNameAcrossRepeatedClones(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $source = $this->makeTemplate($user->id);
        $base = $source->name;

        $ctrl = $this->makeController();
        $ctrl->actionClone((int)$source->id);
        $ctrl->actionClone((int)$source->id);
        $ctrl->actionClone((int)$source->id);

        $this->assertNotNull(JobTemplate::findOne(['name' => "{$base} (copy)"]));
        $this->assertNotNull(JobTemplate::findOne(['name' => "{$base} (copy 2)"]));
        $this->assertNotNull(JobTemplate::findOne(['name' => "{$base} (copy 3)"]));
    }

    public function testCloneStripsExistingCopySuffixSoItDoesntStack(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $source = $this->makeTemplate($user->id);
        $source->name = 'my-template (copy)';
        $source->save(false);

        $ctrl = $this->makeController();
        $ctrl->actionClone((int)$source->id);

        // Must end up as "my-template (copy 2)", not "my-template (copy) (copy)".
        $this->assertNotNull(JobTemplate::findOne(['name' => 'my-template (copy 2)']));
        $this->assertNull(JobTemplate::findOne(['name' => 'my-template (copy) (copy)']));
    }

    public function testCloneThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionClone(9999999);
    }

    /**
     * Regression: a failed clone flashed the JSON of the model errors
     * ("Clone failed: {"credential_ids":[...]}").
     */
    public function testCloningALegacyTwoVaultTemplateFailsWithAReadableMessage(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $vaultA = $this->storedVault($userId, 'vault-a');
        $vaultB = $this->storedVault($userId, 'vault-b');
        $source = $this->makeTemplate($userId);
        $this->giveTwoVaults($source, $vaultA, $vaultB);
        $templatesBefore = JobTemplate::find()->count();
        \Yii::$app->session->removeAllFlashes();

        $ctrl = $this->makeController();
        $result = $ctrl->actionClone((int)$source->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(['view', 'id' => (int)$source->id], $ctrl->capturedRedirect, 'back to the source template');
        $flash = (string)\Yii::$app->session->getFlash('danger');
        $this->assertStringStartsWith('Clone failed: Only one vault password', $flash);
        $this->assertSame('Clone failed: ' . $this->vaultConflictMessage($vaultA, $vaultB) . ' Fix the source template first.', $flash);
        $this->assertStringNotContainsString('{', $flash, 'no JSON dump of the model errors');
        $this->assertFalse(\Yii::$app->session->hasFlash('success'));
        $this->assertSame($templatesBefore, JobTemplate::find()->count(), 'no clone is created');
        $this->assertNull(JobTemplate::findOne(['name' => $source->name . ' (copy)']));
    }

    /**
     * Regression: a failed clone flashed the JSON of the model errors.
     */
    public function testCloningATemplateWithAnInventoryOfAnotherProjectFailsWithAReadableMessage(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $inventory = $this->otherProjectInventory($userId);
        $source = $this->templateWithInventory($userId, $inventory);
        $templatesBefore = JobTemplate::find()->count();
        \Yii::$app->session->removeAllFlashes();

        $ctrl = $this->makeController();
        $ctrl->actionClone((int)$source->id);

        $this->assertSame(['view', 'id' => (int)$source->id], $ctrl->capturedRedirect);
        $this->assertSame(
            'Clone failed: ' . $this->inventoryMessage($inventory) . ' Fix the source template first.',
            \Yii::$app->session->getFlash('danger')
        );
        $this->assertSame($templatesBefore, JobTemplate::find()->count(), 'no clone is created');
    }

    public function testCloningATemplateWithOneVaultCopiesItOnce(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $vault = $this->storedVault($userId, 'vault-only');
        $token = $this->createCredential($userId);
        $source = $this->makeTemplate($userId);
        $source->credential_id = $vault->id;
        // Stores the vault password as primary and as the first pivot row.
        $this->assertTrue($this->credentialService()->saveWithCredentials($source, [$token->id]));

        $ctrl = $this->makeController();
        $ctrl->actionClone((int)$source->id);

        $clone = JobTemplate::findOne(['name' => $source->name . ' (copy)']);
        $this->assertNotNull($clone);
        $this->assertSame(['update', 'id' => (int)$clone->id], $ctrl->capturedRedirect);
        $this->assertSame(
            [[(int)$vault->id, Credential::ROLE_PRIMARY], [(int)$token->id, Credential::ROLE_ADDITIONAL]],
            array_map(static fn (array $c): array => [$c['id'], $c['role']], $clone->credentialSnapshot())
        );
        $this->assertSame([], JobTemplateWarnings::forTemplate($clone));
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

    // ── actionLaunch(): template warnings ───────────────────────────────────

    /**
     * Regression: a launch that skips the launch page (dashboard quick
     * launch posts straight to it) never showed the template's warnings.
     * The job still starts; the warning follows as a flash.
     */
    public function testALaunchPostedWithoutThePageFlashesTheTemplateWarnings(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate((int)$user->id);
        $this->storeVaultCheck($tpl, JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, null, 3);
        \Yii::$app->session->removeAllFlashes();
        $this->setPost(['id' => (string)$tpl->id]);

        $result = $this->makeController()->actionLaunch();

        $this->assertInstanceOf(Response::class, $result);
        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertStringStartsWith('Job #', (string)$flashes['success']);
        $this->assertSame(
            'This template has no vault password, but it probably loads 3 encrypted files or values. '
            . 'Jobs fail when Ansible needs one of them. Attach the vault password of this environment.',
            $flashes['warning']
        );
    }

    public function testALaunchWithoutWarningsFlashesNoWarning(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate((int)$user->id);
        \Yii::$app->session->removeAllFlashes();
        $this->setPost(['id' => (string)$tpl->id]);

        $this->makeController()->actionLaunch();

        $this->assertArrayNotHasKey('warning', \Yii::$app->session->getAllFlashes());
    }

    public function testTheLaunchPageShowsTheVaultWarningOfTheTemplate(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $vault = $this->storedVault($userId, 'prod-vault');
        // Credential names are user input: the page must print them escaped.
        $vault->name = '<script>alert(1)</script> prod-vault';
        $vault->save(false);
        $tpl = $this->makeTemplate($userId);
        $tpl->credential_id = $vault->id;
        $tpl->save(false);
        $this->storeVaultCheck($tpl, JobTemplateVaultCheck::STATUS_MISMATCH, (int)$vault->id, 3, [
            ['path' => 'inventories/prod/group_vars/all/vault.yml', 'line' => null, 'key' => null],
            ['path' => 'inventories/prod/host_vars/prod-web1.yml', 'line' => 2, 'key' => 'host_secret'],
        ]);
        $this->setQueryParams(['id' => (string)$tpl->id]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:launch', $ctrl->actionLaunch());

        $message = sprintf(
            'The vault password "%s" does not open 2 of the 3 encrypted files or values this template probably loads: '
            . 'inventories/prod/group_vars/all/vault.yml, inventories/prod/host_vars/prod-web1.yml:2. '
            . 'Jobs fail when Ansible needs one of them. Check the password and the inventory; if the files changed, rescan the project.',
            $vault->name
        );
        $this->assertSame([[
            'code' => JobTemplateWarnings::VAULT_PASSWORD_MISMATCH,
            'message' => $message,
            'credential_ids' => [(int)$vault->id],
        ]], $ctrl->capturedParams['warnings']);
        $html = $this->renderLaunchPage($ctrl->capturedParams);
        $this->assertSame([JobTemplateWarnings::VAULT_PASSWORD_MISMATCH], $this->renderedWarningCodes($html));
        $this->assertStringContainsString(Html::encode($message), $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testTheLaunchPageShowsEveryWarningOfTheTemplateInOrder(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $tpl = $this->templateWithInventory($userId, $this->otherProjectInventory($userId));
        $vaultA = $this->storedVault($userId, 'vault-a');
        $this->giveTwoVaults($tpl, $vaultA, $this->storedVault($userId, 'vault-b'));
        $this->storeVaultCheck($tpl, JobTemplateVaultCheck::STATUS_UNUSABLE_PASSWORD, (int)$vaultA->id, 2);
        $this->setQueryParams(['id' => (string)$tpl->id]);

        $ctrl = $this->makeController();
        $ctrl->actionLaunch();

        $expected = [
            JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS,
            JobTemplateWarnings::INVENTORY_OTHER_PROJECT,
            JobTemplateWarnings::VAULT_PASSWORD_MISMATCH,
        ];
        $this->assertSame($expected, array_column($ctrl->capturedParams['warnings'], 'code'));
        $html = $this->renderLaunchPage($ctrl->capturedParams);
        $this->assertSame($expected, $this->renderedWarningCodes($html));
        $this->assertStringContainsString(
            Html::encode("The vault password \"{$vaultA->name}\" has no usable secret, so jobs fail."),
            $html
        );
    }

    public function testTheLaunchPageOfATemplateWithoutProblemsShowsNoWarning(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $userId = (int)$user->id;
        $vault = $this->storedVault($userId, 'dev-vault');
        $tpl = $this->makeTemplate($userId);
        $tpl->credential_id = $vault->id;
        $tpl->save(false);
        $this->storeVaultCheck($tpl, JobTemplateVaultCheck::STATUS_OK, (int)$vault->id, 3);
        $this->setQueryParams(['id' => (string)$tpl->id]);

        $ctrl = $this->makeController();
        $ctrl->actionLaunch();

        $this->assertSame([], $ctrl->capturedParams['warnings']);
        $html = $this->renderLaunchPage($ctrl->capturedParams);
        $this->assertSame([], $this->renderedWarningCodes($html));
        $this->assertStringContainsString('id="launch-form"', $html, 'the page itself rendered');
    }

    public function testAFailedLaunchShowsTheWarningsAgain(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate((int)$user->id);
        $this->storeVaultCheck($tpl, JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, null, 4);
        /** @var object{throwOnLaunch: bool} $svc */
        $svc = \Yii::$app->get('jobLaunchService');
        $svc->throwOnLaunch = true;
        $this->setQueryParams(['id' => (string)$tpl->id]);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:launch', $ctrl->actionLaunch());

        $this->assertArrayHasKey('danger', \Yii::$app->session->getAllFlashes());
        $message = 'This template has no vault password, but it probably loads 4 encrypted files or values. '
            . 'Jobs fail when Ansible needs one of them. Attach the vault password of this environment.';
        $this->assertSame([[
            'code' => JobTemplateWarnings::VAULT_PASSWORD_MISSING,
            'message' => $message,
            'credential_ids' => [],
        ]], $ctrl->capturedParams['warnings']);
        $html = $this->renderLaunchPage($ctrl->capturedParams);
        $this->assertSame([JobTemplateWarnings::VAULT_PASSWORD_MISSING], $this->renderedWarningCodes($html));
        $this->assertStringContainsString(Html::encode($message), $html);
    }

    /**
     * Vault warnings are informational: the launch still queues the job.
     */
    public function testAVaultWarningDoesNotBlockTheLaunch(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $tpl = $this->makeTemplate((int)$user->id);
        $this->storeVaultCheck($tpl, JobTemplateVaultCheck::STATUS_MISSING_PASSWORD, null, 4);
        $this->setQueryParams(['id' => (string)$tpl->id]);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionLaunch();

        $this->assertInstanceOf(Response::class, $result);
        /** @var object{launchCalls: int} $svc */
        $svc = \Yii::$app->get('jobLaunchService');
        $this->assertSame(1, $svc->launchCalls);
        $this->assertIsArray($ctrl->capturedRedirect);
        $this->assertSame('/job/view', $ctrl->capturedRedirect[0]);
        $this->assertSame('', $ctrl->capturedView, 'the launch page is not rendered again');
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
        $tpl->generateTriggerToken((int)$tpl->created_by);

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

    // ── Team scoping on save ─────────────────────────────────────────────────

    /**
     * Regression: update checked operate access on the stored project only,
     * so a team operator could move a template into a project of another
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
    public function testUpdateCannotMoveATemplateIntoAProjectTheUserMayNotOperate(string $target): void
    {
        $scope = $this->teamScope();
        $template = $this->createJobTemplate($scope['own'], $scope['ownInventory'], $scope['group'], $scope['userId']);
        $this->setPost(['JobTemplate' => ['project_id' => (string)$scope[$target]]]);

        try {
            $this->makeController()->actionUpdate((int)$template->id);
            $this->fail('Moving the template into that project must be forbidden.');
        } catch (\yii\web\ForbiddenHttpException $e) {
            $this->assertSame('You do not have permission to modify this resource.', $e->getMessage());
        }
        $this->assertSame($scope['own'], (int)JobTemplate::findOne($template->id)?->project_id);
    }

    /**
     * Regression: the form offers only inventories the user may see, but the
     * submitted id was not checked, so a crafted request could run playbooks
     * against another team's hosts. File and dynamic inventories of another
     * project also failed the project rule, whose message named them: the
     * visibility check must come first, so hidden names stay hidden.
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
    public function testCreateRejectsAnInventoryOfAProjectTheUserCannotSee(string $type): void
    {
        $scope = $this->teamScope();
        $hidden = $this->inventory($scope['userId'], $type, $scope['foreign']);
        $user = User::findOne($scope['userId']);
        $this->assertNotNull($user);
        $post = $this->templatePost($user, 'tpl-hidden-inventory');
        $post['JobTemplate']['inventory_id'] = (string)$hidden->id;
        $this->setPost($post);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionCreate());

        $this->assertSame(['inventory_id' => ['The selected inventory does not exist.']], $ctrl->capturedParams['model']->getErrors());
        $this->assertNull(JobTemplate::findOne(['name' => 'tpl-hidden-inventory']));
    }

    public function testUpdateRejectsSwitchingToAnInventoryTheUserCannotSee(): void
    {
        $scope = $this->teamScope();
        $template = $this->createJobTemplate($scope['own'], $scope['ownInventory'], $scope['group'], $scope['userId']);
        $this->setPost(['JobTemplate' => ['inventory_id' => (string)$scope['foreignInventory']]]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$template->id));

        $this->assertSame(['inventory_id' => ['The selected inventory does not exist.']], $ctrl->capturedParams['model']->getErrors());
        $this->assertSame($scope['ownInventory'], (int)JobTemplate::findOne($template->id)?->inventory_id);
    }

    /**
     * A template that already uses such an inventory keeps saving as long
     * as the inventory stays, like the other inventory rules.
     */
    public function testAnUnchangedHiddenInventoryDoesNotBlockOtherChanges(): void
    {
        $scope = $this->teamScope();
        $template = $this->createJobTemplate($scope['own'], $scope['foreignInventory'], $scope['group'], $scope['userId']);
        $this->setPost(['JobTemplate' => ['name' => 'tpl-renamed-hidden', 'inventory_id' => (string)$scope['foreignInventory']]]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate((int)$template->id);

        $this->assertSame(['view', 'id' => $template->id], $ctrl->capturedRedirect);
        $this->assertSame('tpl-renamed-hidden', JobTemplate::findOne($template->id)?->name);
    }

    /**
     * Regression: clone needed only view access, so a member whose team only
     * views a project could create templates in it.
     */
    public function testCloneInAProjectTheTeamOnlyViewsIsForbidden(): void
    {
        $scope = $this->teamScope();
        $template = $this->createJobTemplate($scope['viewed'], $scope['ownInventory'], $scope['group'], $scope['userId']);
        $before = (int)JobTemplate::find()->count();

        $this->expectException(\yii\web\ForbiddenHttpException::class);
        try {
            $this->makeController()->actionClone((int)$template->id);
        } finally {
            $this->assertSame($before, (int)JobTemplate::find()->count());
        }
    }

    /**
     * Cloning a template whose inventory the user cannot see is rejected
     * like saving one: the clone would be a new reference to it.
     */
    public function testCloneOfATemplateWithAHiddenInventoryIsRejected(): void
    {
        $scope = $this->teamScope();
        $template = $this->createJobTemplate($scope['own'], $scope['foreignInventory'], $scope['group'], $scope['userId']);
        $before = (int)JobTemplate::find()->count();

        $ctrl = $this->makeController();
        $ctrl->actionClone((int)$template->id);

        $this->assertSame(['view', 'id' => $template->id], $ctrl->capturedRedirect);
        $this->assertSame(
            ['danger' => 'Clone failed: The selected inventory does not exist. Fix the source template first.'],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertSame($before, (int)JobTemplate::find()->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A logged-in user without RBAC admin whose team operates "own" and only
     * views "viewed"; "foreign" belongs to another team and has a static
     * inventory the user cannot see.
     *
     * @return array{userId: int, own: int, viewed: int, foreign: int, ownInventory: int, foreignInventory: int, group: int}
     */
    private function teamScope(): array
    {
        \Yii::$app->session->removeAllFlashes();
        $admin = (int)$this->createUser('scope-admin')->id;
        $member = $this->createUser('scope-member');
        $own = (int)$this->createProject($admin)->id;
        $viewed = (int)$this->createProject($admin)->id;
        $foreign = (int)$this->createProject($admin)->id;
        $team = (int)$this->createTeam($admin)->id;
        $this->addTeamMember($team, (int)$member->id);
        $this->createTeamProject($team, $own, TeamProject::ROLE_OPERATOR);
        $this->createTeamProject($team, $viewed, TeamProject::ROLE_VIEWER);
        $this->createTeamProject((int)$this->createTeam($admin)->id, $foreign, TeamProject::ROLE_OPERATOR);
        $this->loginAs($member);

        return [
            'userId' => (int)$member->id,
            'own' => $own,
            'viewed' => $viewed,
            'foreign' => $foreign,
            'ownInventory' => (int)$this->inventory($admin, Inventory::TYPE_STATIC, $own)->id,
            'foreignInventory' => (int)$this->inventory($admin, Inventory::TYPE_STATIC, $foreign)->id,
            'group' => (int)$this->createRunnerGroup($admin)->id,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeTemplate(int $userId): JobTemplate
    {
        $project = $this->createProject($userId);
        $inventory = $this->createInventory($userId);
        $group = $this->createRunnerGroup($userId);
        return $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $userId);
    }

    private function credentialService(): JobTemplateCredentialService
    {
        /** @var JobTemplateCredentialService $service */
        $service = \Yii::$app->get('jobTemplateCredentialService');
        return $service;
    }

    /**
     * A vault password credential with a stored secret.
     */
    private function storedVault(int $userId, string $label): Credential
    {
        $credential = new Credential();
        $credential->name = $label . '-' . uniqid('', true);
        $credential->credential_type = Credential::TYPE_VAULT;
        $credential->created_by = $userId;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($credential, ['vault_password' => 'vault-secret']), (string)json_encode($credential->errors));
        return $credential;
    }

    /**
     * Writes pivot rows directly, in the given order, as templates were
     * stored before a second vault password was rejected.
     *
     * @param list<Credential> $credentials
     */
    private function attachInOrder(JobTemplate $template, array $credentials): void
    {
        foreach ($credentials as $sortOrder => $credential) {
            \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $template->id,
                'credential_id' => $credential->id,
                'sort_order' => $sortOrder,
            ])->execute();
        }
    }

    /**
     * Turns $template into a legacy template with two vault passwords:
     * $primary as primary credential (and first pivot row, as the service
     * stores a primary) and $additional as additional credential.
     */
    private function giveTwoVaults(JobTemplate $template, Credential $primary, Credential $additional): void
    {
        $template->credential_id = $primary->id;
        $template->save(false);
        $this->attachInOrder($template, [$primary, $additional]);
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
     * A template of a new project that uses $inventory.
     */
    private function templateWithInventory(int $userId, Inventory $inventory): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$inventory->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    /**
     * A template with a file inventory of another project, named
     * "<prefix>-template-…"; its inventory and its project are named
     * "<sortPrefix>-…".
     */
    private function crossProjectTemplateNamed(int $userId, string $prefix, string $sortPrefix): JobTemplate
    {
        $inventory = $this->otherProjectInventory($userId);
        $inventory->name = $sortPrefix . '-inventory-' . uniqid('', true);
        $inventory->save(false);
        $template = $this->templateWithInventory($userId, $inventory);
        $template->name = $prefix . '-template-' . uniqid('', true);
        $template->save(false);
        $project = $template->project;
        $project->name = $sortPrefix . '-project-' . uniqid('', true);
        $project->save(false);
        return $template;
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
     * What actionIndex passes to the view for the given query parameters.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function indexParams(array $query = []): array
    {
        $this->setQueryParams($query);
        $ctrl = $this->makeController();
        $this->assertSame('rendered:index', $ctrl->actionIndex());
        return $ctrl->capturedParams;
    }

    /**
     * The warning counts of the template list as $user sees it.
     *
     * @return array<string, int> code => number of templates
     */
    private function warningCountsFor(User $user): array
    {
        $this->loginAs($user);
        $counts = $this->indexParams()['warningCounts'];
        $this->assertIsArray($counts);
        $this->assertSame(JobTemplateWarnings::CODES, array_keys($counts), 'one count per warning code');
        /** @var array<string, int> $counts */
        return $counts;
    }

    /**
     * Every template of the data provider in listed order, all pages.
     *
     * @param array<string, mixed> $params what actionIndex passed to the view
     * @return list<JobTemplate>
     */
    private function listedTemplates(array $params): array
    {
        $provider = $params['dataProvider'];
        $this->assertInstanceOf(ActiveDataProvider::class, $provider);
        $provider->pagination = false;
        /** @var list<JobTemplate> $models */
        $models = array_values($provider->getModels());
        return $models;
    }

    /**
     * @param array<string, mixed> $params what actionIndex passed to the view
     * @return list<int>
     */
    private function listedIds(array $params): array
    {
        return array_map(static fn (JobTemplate $t): int => (int)$t->id, $this->listedTemplates($params));
    }

    /**
     * The SQL filter and JobTemplateWarnings::forTemplate() must agree.
     *
     * @param array<string, mixed> $params what actionIndex passed to the view
     */
    private function assertEveryListedTemplateHas(string $code, array $params): void
    {
        foreach ($this->listedTemplates($params) as $template) {
            $this->assertContains(
                $code,
                array_column(JobTemplateWarnings::forTemplate($template), 'code'),
                "template #{$template->id} is listed without the warning"
            );
        }
    }

    /**
     * Stores a template's vault check the way VaultCheckService does.
     *
     * @param list<array{path: string, line: int|null, key: string|null}> $unopened
     */
    private function storeVaultCheck(
        JobTemplate $template,
        string $status,
        ?int $credentialId,
        int $relevantCount,
        array $unopened = []
    ): void {
        $check = new JobTemplateVaultCheck();
        $check->job_template_id = (int)$template->id;
        $check->status = $status;
        $check->credential_id = $credentialId;
        $check->relevant_count = $relevantCount;
        $check->unopened_count = count($unopened);
        $check->unopened = $unopened === [] ? null : (string)json_encode($unopened);
        $check->checked_at = time();
        $check->scanned_at = $this->vaultScanTimeOf((int)$template->project_id);
        $this->assertTrue($check->save(false));
    }

    /**
     * The real launch view, rendered with what actionLaunch passed to it.
     * The console application of the tests has no web root, so asset
     * bundles are dummies; view, asset manager and controller are restored.
     *
     * @param array<string, mixed> $params
     */
    private function renderLaunchPage(array $params): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $ctrl = new JobTemplateController('job-template', \Yii::$app);
        \Yii::$app->controller = $ctrl;
        try {
            return $ctrl->renderPartial('launch', $params);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    /**
     * The codes of the warning alerts on a rendered page, in page order.
     *
     * @return list<string>
     */
    private function renderedWarningCodes(string $html): array
    {
        preg_match_all('/data-testid="template-warning" data-code="([^"]*)"/', $html, $matches);

        return $matches[1];
    }

    private function auditCount(string $action, int $templateId): int
    {
        return (int)AuditLog::find()
            ->where(['action' => $action, 'object_type' => 'job_template', 'object_id' => $templateId])
            ->count();
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
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                $this->capturedRedirect = $url;
                $r = new \yii\web\Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
