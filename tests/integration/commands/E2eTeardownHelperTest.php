<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eTeardownHelper;
use app\commands\E2eVaultEdgeSeeder;
use app\commands\E2eVaultScanSeeder;
use app\models\ApprovalRequest;
use app\models\Credential;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Project;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\Schedule;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowTemplate;
use app\tests\integration\DbTestCase;
use yii\base\Event;
use yii\base\ModelEvent;
use yii\db\BaseActiveRecord;
use yii\helpers\FileHelper;

/**
 * `yii e2e/teardown` (E2eTeardownHelper): the database part, teardownDatabase(),
 * and the vault fixture checkouts on disk, teardownAll().
 *
 * Regression: the teardown crashed with SQLSTATE 1451 when deleting e2e-admin,
 * because a runner that runners/create-token.spec.ts created in the shared
 * "default" group still referred to it (runner.created_by is RESTRICT), as do
 * the trigger_token_created_by columns of job and workflow templates. Roles were
 * revoked before the delete failed, the vault checkouts on disk were never
 * removed, and the command still exited 0.
 *
 * Each test uses its own prefix instead of "e2e-", so it never touches the
 * fixtures of an E2E run in the same database; the rollback removes the rest.
 * Tests of teardownAll() point the @runtime alias elsewhere, so they never
 * touch the checkouts of an E2E run either.
 */
class E2eTeardownHelperTest extends DbTestCase
{
    private string $prefix;

    /** @var list<string> */
    private array $log = [];

    /** The @runtime alias a test replaced, put back by tearDown(). */
    private ?string $runtimeAlias = null;

    /** A runtime directory a test created, removed by tearDown(). */
    private ?string $temporaryRuntime = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'e2ett' . bin2hex(random_bytes(4)) . '-';
        $this->log = [];
    }

    protected function tearDown(): void
    {
        if ($this->runtimeAlias !== null) {
            \Yii::setAlias('@runtime', $this->runtimeAlias);
            $this->runtimeAlias = null;
        }
        if ($this->temporaryRuntime !== null) {
            FileHelper::removeDirectory($this->temporaryRuntime);
            $this->temporaryRuntime = null;
        }
        parent::tearDown();
    }

    public function testDeletesRunnersOfE2eUsersInSharedGroupsBeforeTheUsers(): void
    {
        $owner = $this->createUser('owner');
        $sharedGroup = $this->createRunnerGroup((int)$owner->id);
        $admin = $this->e2eUser('admin', 'admin');
        $runner = $this->createRunner((int)$sharedGroup->id, (int)$admin->id);
        $runner->name = $this->prefix . 'ui-created-runner';
        $runner->save(false);
        $unnamedRunner = $this->createRunner((int)$sharedGroup->id, (int)$admin->id);
        $otherRunner = $this->createRunner((int)$sharedGroup->id, (int)$owner->id);

        $this->assertSame([], $this->runTeardown());

        $this->assertNull(User::findOne($admin->id));
        $this->assertSame([], $this->auth()->getAssignments((int)$admin->id), 'roles of the deleted user are revoked');
        $this->assertNull(Runner::findOne($runner->id));
        $this->assertNull(Runner::findOne($unnamedRunner->id), 'runners e2e users created go whatever their name');
        $this->assertNotNull(Runner::findOne($otherRunner->id), 'runners of other users stay');
        $this->assertNotNull(RunnerGroup::findOne($sharedGroup->id), 'the shared group stays');
        $this->assertContains("  Deleted user '{$admin->username}'.\n", $this->log);
    }

    public function testRevokesTriggerTokensE2eUsersGeneratedOnTemplatesThatStay(): void
    {
        $owner = $this->createUser('owner');
        $admin = $this->e2eUser('admin', 'admin');
        $template = $this->templateOf((int)$owner->id);
        $template->generateTriggerToken((int)$admin->id);
        $workflow = $this->createWorkflowTemplate((int)$owner->id);
        $workflow->generateTriggerToken((int)$admin->id);

        $this->assertSame([], $this->runTeardown());

        $this->assertNull(User::findOne($admin->id));
        $template = JobTemplate::findOne($template->id);
        $this->assertNotNull($template, 'the template of another user stays');
        $this->assertNull($template->trigger_token, 'the trigger would run as a deleted user: revoked');
        $this->assertNull($template->trigger_token_created_by);
        $workflow = WorkflowTemplate::findOne($workflow->id);
        $this->assertNotNull($workflow);
        $this->assertNull($workflow->trigger_token);
        $this->assertNull($workflow->trigger_token_created_by);
    }

    public function testDeletesSoftDeletedTemplatesNamedWithThePrefix(): void
    {
        $admin = $this->e2eUser('admin', 'admin');
        $template = $this->templateOf((int)$admin->id);
        $template->name = $this->prefix . 'softdel-template';
        $template->save(false);
        $template->softDelete();
        $project = Project::findOne($template->project_id);
        $this->assertNotNull($project);
        $project->name = $this->prefix . 'softdel-project';
        $project->save(false);
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        $workflow->name = $this->prefix . 'softdel-workflow';
        $workflow->save(false);
        $workflow->softDelete();

        $this->assertSame([], $this->runTeardown());

        $this->assertNull(JobTemplate::findWithDeleted()->where(['id' => $template->id])->one());
        $this->assertNull(WorkflowTemplate::findWithDeleted()->where(['id' => $workflow->id])->one());
        $this->assertNull(Project::findOne($project->id));
        $this->assertNull(User::findOne($admin->id));
    }

    public function testDeletesWhatE2eUsersCreatedOrLaunchedWhateverItsNameAndNothingElse(): void
    {
        $owner = $this->createUser('owner');
        $ownTemplate = $this->templateOf((int)$owner->id);
        $admin = $this->e2eUser('admin', 'admin');
        $operator = $this->e2eUser('operator', 'operator');
        // Created through the UI by specs, under names without the prefix.
        $template = $this->templateOf((int)$operator->id);
        $schedule = $this->scheduleOf((int)$template->id, (int)$operator->id);
        $team = $this->createTeam((int)$admin->id);
        $this->addTeamMember((int)$team->id, (int)$operator->id);
        $rule = $this->createApprovalRule((int)$admin->id);
        $webhook = $this->createWebhook((int)$admin->id);
        $notification = $this->createNotificationTemplate((int)$admin->id);
        $credential = $this->createCredential((int)$admin->id);
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        $this->createWorkflowStep((int)$workflow->id, 10, 'job', (int)$template->id);
        // Launched by e2e users, also on templates of other users.
        $job = $this->createJob((int)$ownTemplate->id, (int)$operator->id, Job::STATUS_SUCCEEDED);
        $request = $this->requestFor($job, (int)$rule->id);
        $run = $this->runOf((int)$this->createWorkflowTemplate((int)$owner->id)->id, (int)$admin->id);

        $this->assertSame([], $this->runTeardown());

        $this->assertNull(User::findOne($admin->id));
        $this->assertNull(User::findOne($operator->id));
        foreach ([$schedule, $team, $rule, $webhook, $notification, $credential, $job, $request, $run] as $row) {
            $this->assertFalse($row::find()->where(['id' => $row->getPrimaryKey()])->exists(), get_class($row) . ' is deleted');
        }
        $this->assertNull(JobTemplate::findWithDeleted()->where(['id' => $template->id])->one());
        $this->assertNull(WorkflowTemplate::findWithDeleted()->where(['id' => $workflow->id])->one());
        $this->assertNull(Project::findOne($template->project_id));
        $this->assertNotNull(User::findOne($owner->id), 'users without the prefix stay');
        $this->assertNotNull(JobTemplate::findOne($ownTemplate->id), 'rows of other users stay');
        $this->assertNotNull(Project::findOne($ownTemplate->project_id));
    }

    public function testHandsSharedRunnerGroupsOverToAnotherSuperadmin(): void
    {
        $heir = $this->createUser('heir');
        $heir->is_superadmin = true;
        $heir->save(false);
        $admin = $this->e2eUser('admin', 'admin');
        $default = $this->createRunnerGroup((int)$admin->id);
        $runner = $this->createRunner((int)$default->id, (int)$heir->id);
        $e2eGroup = $this->createRunnerGroup((int)$admin->id);
        $e2eGroup->name = $this->prefix . 'runner-group';
        $e2eGroup->save(false);

        $this->assertSame([], $this->runTeardown());

        $default = RunnerGroup::findOne($default->id);
        $this->assertNotNull($default, 'a group without the prefix is shared infrastructure');
        $newOwner = User::findOne($default->created_by);
        $this->assertNotNull($newOwner);
        $this->assertTrue((bool)$newOwner->is_superadmin);
        $this->assertStringNotContainsString($this->prefix, (string)$newOwner->username);
        $this->assertNotNull(Runner::findOne($runner->id));
        $this->assertNull(RunnerGroup::findOne($e2eGroup->id));
        $this->assertNull(User::findOne($admin->id));
    }

    public function testDeletesSharedRunnerGroupsWhenNoOtherSuperadminCouldOwnThem(): void
    {
        // Nobody else may own them: deleting is the only way to delete the user.
        User::updateAll(['is_superadmin' => false], ['is_superadmin' => true]);
        $admin = $this->e2eUser('admin', 'admin');
        $default = $this->createRunnerGroup((int)$admin->id);

        $this->assertSame([], $this->runTeardown());

        $this->assertNull(RunnerGroup::findOne($default->id));
        $this->assertNull(User::findOne($admin->id));
    }

    public function testKeepsAUserItCannotDeleteWithItsRolesAndReportsWhatIsLeft(): void
    {
        $owner = $this->createUser('owner');
        $operator = $this->e2eUser('operator', 'operator');
        // A template of another user uses an inventory the e2e user created:
        // the inventory cannot go, so neither can its creator.
        $inventory = $this->createInventory((int)$operator->id);
        $project = $this->createProject((int)$owner->id);
        $template = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$this->createRunnerGroup((int)$owner->id)->id, (int)$owner->id);

        $problems = $this->runTeardown();

        $this->assertNotNull(User::findOne($operator->id));
        $this->assertNotNull($this->auth()->getAssignment('operator', (int)$operator->id), 'a user that stays keeps its roles');
        $this->assertNotNull(JobTemplate::findOne($template->id));
        $this->assertContainsMatching('/^Failed deleting the inventory rows of e2e users: .*1451/', $problems);
        $this->assertContainsMatching("/^User '{$operator->username}' was not deleted: .*1451/", $problems);
        $this->assertContains("1 user row(s) named like '{$this->prefix}' are left.", $problems);
    }

    public function testKeepsAndReportsAUserWhoseDeleteIsRefused(): void
    {
        $operator = $this->e2eUser('operator', 'operator');
        $refuse = static function (ModelEvent $event): void {
            $event->isValid = false;
        };
        Event::on(User::class, BaseActiveRecord::EVENT_BEFORE_DELETE, $refuse);
        try {
            $problems = $this->runTeardown();
        } finally {
            Event::off(User::class, BaseActiveRecord::EVENT_BEFORE_DELETE, $refuse);
        }

        $this->assertNotNull(User::findOne($operator->id));
        $this->assertNotNull($this->auth()->getAssignment('operator', (int)$operator->id), 'a user that stays keeps its roles');
        $this->assertSame([
            "User '{$operator->username}' was not deleted: the delete was refused",
            "1 user row(s) named like '{$this->prefix}' are left.",
        ], $problems);
    }

    /**
     * Regression: users and rows matched when their name only contained the
     * prefix (LIKE '%e2e-%'). A user named 'qa-e2e-bob' lost their account
     * and everything they had created, and rows such as a project named
     * 'customer-e2e-x' or a runner named 'build-e2e-runner' were deleted.
     */
    public function testUsersAndRowsThatOnlyContainThePrefixStay(): void
    {
        $owner = $this->createUser('owner');
        $bystander = $this->createUser('bob');
        $bystander->username = 'qa-' . $this->prefix . 'bob';
        $bystander->save(false);
        $project = $this->createProject((int)$bystander->id);
        $credential = $this->createCredential((int)$bystander->id);
        $customerProject = $this->namedProject('customer-' . $this->prefix . 'x', (int)$owner->id);
        $runner = $this->createRunner((int)$this->createRunnerGroup((int)$owner->id)->id, (int)$owner->id);
        $runner->name = 'build-' . $this->prefix . 'runner';
        $runner->save(false);

        $this->assertSame([], $this->runTeardown());

        $this->assertNotNull(User::findOne($bystander->id), 'a user whose name only contains the prefix stays');
        $this->assertNotNull(Project::findOne($project->id), 'and so does what they created');
        $this->assertNotNull(Credential::findOne($credential->id));
        $this->assertNotNull(Project::findOne($customerProject->id), 'a row whose name only contains the prefix stays');
        $this->assertNotNull(Runner::findOne($runner->id));
        $this->assertSame([], $this->log, 'nothing was deleted');
    }

    /**
     * Regression: a runner group an e2e user created whose name only contained
     * the prefix counted as a fixture, so it was deleted with its runners
     * instead of handed over like other shared infrastructure.
     */
    public function testHandsOverRunnerGroupsWhoseNameOnlyContainsThePrefix(): void
    {
        $heir = $this->createUser('heir');
        $heir->is_superadmin = true;
        $heir->save(false);
        $admin = $this->e2eUser('admin', 'admin');
        $group = $this->createRunnerGroup((int)$admin->id);
        $group->name = 'shared-' . $this->prefix . 'group';
        $group->save(false);
        $runner = $this->createRunner((int)$group->id, (int)$heir->id);

        $this->assertSame([], $this->runTeardown());

        $group = RunnerGroup::findOne($group->id);
        $this->assertNotNull($group, 'shared infrastructure, not a fixture');
        $newOwner = User::findOne($group->created_by);
        $this->assertNotNull($newOwner);
        $this->assertTrue((bool)$newOwner->is_superadmin);
        $this->assertNotNull(Runner::findOne($runner->id), 'its runners stay');
        $this->assertNull(User::findOne($admin->id));
    }

    public function testRemovesCustomRolesNamedWithThePrefixOnly(): void
    {
        $auth = $this->auth();
        $fixture = $auth->createRole($this->prefix . 'custom-role');
        $auth->add($fixture);
        $other = $auth->createRole('qa-' . $this->prefix . 'role');
        $auth->add($other);

        $this->assertSame([], $this->runTeardown());

        $this->assertNull($auth->getRole($fixture->name));
        $this->assertNotNull($auth->getRole($other->name), 'a role whose name only contains the prefix stays');
        $this->assertSame(["  Deleted custom role '{$fixture->name}'.\n"], $this->log);
    }

    /**
     * Guards the escaping: '_' and '%' in the prefix are plain characters,
     * not LIKE wildcards that would match other names.
     */
    public function testLikeWildcardsInThePrefixOnlyMatchThemselves(): void
    {
        $this->prefix = 'e2ett' . bin2hex(random_bytes(4)) . '_%-';
        // As wildcards, '_' would match the 'x' and '%' nothing, so names
        // starting with "e2ett<hex>x-" would count as named with the prefix.
        $lookalike = substr($this->prefix, 0, -3) . 'x-';
        $admin = $this->e2eUser('admin', 'admin');
        $fixture = $this->namedProject($this->prefix . 'project', (int)$admin->id);
        $other = $this->createUser('other');
        $other->username = $lookalike . 'admin';
        $other->save(false);
        $project = $this->namedProject($lookalike . 'project', (int)$other->id);

        $this->assertSame([], $this->runTeardown());

        $this->assertNull(User::findOne($admin->id));
        $this->assertNull(Project::findOne($fixture->id));
        $this->assertNotNull(User::findOne($other->id), "'{$other->username}' does not start with '{$this->prefix}'");
        $this->assertNotNull(Project::findOne($project->id));
    }

    /**
     * Regression: the named rows go first, so the e2e project and inventory
     * could not go while a template a spec created on them (under another
     * name) still existed. That template went with the rows of the e2e users
     * right after, and the project and inventory with it, yet the failures of
     * the first attempt were still reported: e2e/teardown exited non-zero
     * although nothing was left.
     */
    public function testAnUnprefixedRowOnPrefixedFixturesLeavesNoProblem(): void
    {
        $admin = $this->e2eUser('admin', 'admin');
        $project = $this->namedProject($this->prefix . 'project', (int)$admin->id);
        $inventory = $this->createInventory((int)$admin->id);
        $inventory->name = $this->prefix . 'inventory';
        $inventory->save(false);
        $template = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$this->createRunnerGroup((int)$admin->id)->id, (int)$admin->id);
        $template->name = 'Deploy (created in a spec)';
        $template->save(false);

        $this->assertSame([], $this->runTeardown());

        $this->assertNull(JobTemplate::findWithDeleted()->where(['id' => $template->id])->one());
        $this->assertNull(Inventory::findOne($inventory->id));
        $this->assertNull(Project::findOne($project->id));
        $this->assertNull(User::findOne($admin->id));
    }

    /**
     * A row named with the prefix that another user created goes too, once
     * the row of an e2e user that referred to it is gone: the named rows get
     * a second pass after the rows of the e2e users.
     */
    public function testDeletesANamedRowOfAnotherUserOnceTheE2eRowOnItIsGone(): void
    {
        $owner = $this->createUser('owner');
        $project = $this->namedProject($this->prefix . 'project', (int)$owner->id);
        $operator = $this->e2eUser('operator', 'operator');
        $template = $this->createJobTemplate(
            (int)$project->id,
            (int)$this->createInventory((int)$operator->id)->id,
            (int)$this->createRunnerGroup((int)$operator->id)->id,
            (int)$operator->id
        );

        $this->assertSame([], $this->runTeardown());

        $this->assertNull(Project::findOne($project->id), 'named with the prefix, whoever created it');
        $this->assertNull(JobTemplate::findWithDeleted()->where(['id' => $template->id])->one());
        $this->assertNull(User::findOne($operator->id));
        $this->assertNotNull(User::findOne($owner->id));
    }

    public function testReportsOnceWhyANamedRowThatStaysCannotGo(): void
    {
        $owner = $this->createUser('owner');
        $operator = $this->e2eUser('operator', 'operator');
        $inventory = $this->createInventory((int)$operator->id);
        $inventory->name = $this->prefix . 'inventory';
        $inventory->save(false);
        // A template of another user uses it: neither the inventory nor its creator can go.
        $this->createJobTemplate((int)$this->createProject((int)$owner->id)->id, (int)$inventory->id, (int)$this->createRunnerGroup((int)$owner->id)->id, (int)$owner->id);

        $problems = $this->runTeardown();

        $this->assertNotNull(Inventory::findOne($inventory->id));
        $reasons = preg_grep("/^Failed deleting inventory '{$this->prefix}inventory': .*1451/", $problems);
        $this->assertCount(1, $reasons, 'reported once, by the second pass: ' . implode(' | ', $problems));
        $this->assertContains("1 inventory row(s) named like '{$this->prefix}' are left.", $problems);
    }

    public function testTeardownAllAlsoRemovesTheVaultFixtureCheckouts(): void
    {
        [$scan, $edge] = $this->checkoutsInTemporaryRuntime();
        $admin = $this->e2eUser('admin', 'admin');

        $this->assertSame([], $this->helper()->teardownAll());

        $this->assertNull(User::findOne($admin->id), 'the database part runs too');
        $this->assertDirectoryDoesNotExist($scan);
        $this->assertDirectoryDoesNotExist($edge);
        $this->assertContains("  Deleted vault scan checkout {$scan}.\n", $this->log);
        $this->assertContains("  Deleted vault edge checkout {$edge}.\n", $this->log);
    }

    public function testTeardownAllReportsWhatEitherPartLeftAndStillRunsTheOther(): void
    {
        // Without the alias the checkout paths cannot be resolved.
        $this->replaceRuntimeAlias(null);
        $owner = $this->createUser('owner');
        $operator = $this->e2eUser('operator', 'operator');
        $inventory = $this->createInventory((int)$operator->id);
        $this->createJobTemplate((int)$this->createProject((int)$owner->id)->id, (int)$inventory->id, (int)$this->createRunnerGroup((int)$owner->id)->id, (int)$owner->id);
        $admin = $this->e2eUser('admin', 'admin');

        $problems = $this->helper()->teardownAll();

        $this->assertNull(User::findOne($admin->id), 'the database part does what it can');
        $this->assertNotNull(User::findOne($operator->id));
        $this->assertCount(5, $problems, implode(' | ', $problems));
        $this->assertMatchesRegularExpression('/^Failed deleting the inventory rows of e2e users: .*1451/', $problems[0]);
        $this->assertMatchesRegularExpression("/^User '{$operator->username}' was not deleted: /", $problems[1]);
        $this->assertSame("1 user row(s) named like '{$this->prefix}' are left.", $problems[2]);
        $this->assertSame('Failed removing the vault scan checkout: Invalid path alias: ' . E2eVaultScanSeeder::CHECKOUT, $problems[3]);
        $this->assertSame('Failed removing the vault edge checkout: Invalid path alias: ' . E2eVaultEdgeSeeder::CHECKOUT, $problems[4]);
    }

    /**
     * @return list<string>
     */
    private function runTeardown(): array
    {
        return $this->helper()->teardownDatabase();
    }

    private function helper(): E2eTeardownHelper
    {
        return new E2eTeardownHelper($this->prefix, function (string $msg): void {
            $this->log[] = $msg;
        });
    }

    private function namedProject(string $name, int $createdBy): Project
    {
        $project = $this->createProject($createdBy);
        $project->name = $name;
        $project->save(false);

        return $project;
    }

    /**
     * Points the @runtime alias at $path for this test, or removes it (null).
     */
    private function replaceRuntimeAlias(?string $path): void
    {
        $this->runtimeAlias = (string)\Yii::getAlias('@runtime');
        \Yii::setAlias('@runtime', $path);
    }

    /**
     * Both vault fixture checkouts, in a runtime directory of this test.
     *
     * @return array{0: string, 1: string} the scan checkout, the edge checkout
     */
    private function checkoutsInTemporaryRuntime(): array
    {
        $this->temporaryRuntime = sys_get_temp_dir() . '/e2e-teardown-test-' . bin2hex(random_bytes(4));
        $this->replaceRuntimeAlias($this->temporaryRuntime);
        $scan = (string)\Yii::getAlias(E2eVaultScanSeeder::CHECKOUT);
        $edge = (string)\Yii::getAlias(E2eVaultEdgeSeeder::CHECKOUT);
        foreach ([$scan, $edge] as $checkout) {
            FileHelper::createDirectory($checkout . '/group_vars');
            file_put_contents($checkout . '/group_vars/all.yml', "---\n");
        }
        $this->assertStringStartsWith($this->temporaryRuntime . '/', $scan);

        return [0 => $scan, 1 => $edge];
    }

    private function e2eUser(string $name, string $role): User
    {
        $user = $this->createUser($name);
        $user->username = $this->prefix . $name;
        $user->is_superadmin = $role === 'admin';
        $user->save(false);
        $rbacRole = $this->auth()->getRole($role);
        $this->assertNotNull($rbacRole, "RBAC role {$role}");
        $this->auth()->assign($rbacRole, (int)$user->id);

        return $user;
    }

    /** A job template in a new project, with a new inventory and runner group, all by $userId. */
    private function templateOf(int $userId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    private function scheduleOf(int $templateId, int $userId): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'nightly';
        $schedule->job_template_id = $templateId;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = false;
        $schedule->created_by = $userId;
        $schedule->save(false);

        return $schedule;
    }

    private function requestFor(Job $job, int $ruleId): ApprovalRequest
    {
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = $ruleId;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        return $request;
    }

    private function runOf(int $workflowTemplateId, int $userId): WorkflowJob
    {
        $run = new WorkflowJob();
        $run->workflow_template_id = $workflowTemplateId;
        $run->launched_by = $userId;
        $run->status = WorkflowJob::STATUS_SUCCEEDED;
        $run->save(false);

        return $run;
    }

    /**
     * @param list<string> $problems
     */
    private function assertContainsMatching(string $pattern, array $problems): void
    {
        $this->assertNotEmpty(preg_grep($pattern, $problems), "a problem matching {$pattern} in: " . implode(' | ', $problems));
    }

    private function auth(): \yii\rbac\ManagerInterface
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);

        return $auth;
    }
}
