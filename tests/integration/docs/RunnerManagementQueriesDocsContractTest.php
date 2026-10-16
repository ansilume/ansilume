<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\controllers\RunnerController;
use app\controllers\RunnerGroupController;
use app\models\AuditLog;
use app\models\Job;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\User;
use app\services\JobClaimService;
use app\services\JobLaunchService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use app\tests\unit\docs\ReadsOperatorDocs;
use app\tests\unit\docs\RunnerManagementDocsContractTest;
use yii\db\Migration;
use yii\rbac\BaseManager;
use yii\rbac\ManagerInterface;
use yii\web\Response;

/**
 * The read-only queries of docs/updating.md's section for the update from
 * 2.11.0 (only admins manage runners) run on the schema of this release, in
 * the test's transaction, and list what their text says; the migration
 * prints the lines the section quotes, and reverting it does what the
 * rollback says.
 *
 * The audit entries the queries read come from the web actions themselves:
 * registering, regenerating, moving and deleting runners and creating and
 * changing runner groups, by a user whose custom role holds
 * runner-group.create and runner-group.update (the documented limit), by an
 * admin and by a superadmin. A change of the audit actions, their object
 * types or their metadata fails here.
 *
 * Regression (runner takeover, fixed by m000087): no doc said how to find
 * the runners and tokens that operators had registered or regenerated, or
 * what such a runner ran; each test fails on the docs from before, which
 * had none of these queries.
 */
class RunnerManagementQueriesDocsContractTest extends WebControllerTestCase
{
    use ReadsOperatorDocs;
    use TeamScopeFixtures;

    /** The comment lines the queries start with, in the order of the section. */
    private const USERS = '-- Users who manage runners only as operators';
    private const CHANGES = '-- Runner and runner group changes by users who are neither admins nor superadmins';
    private const TOKENS = '-- Runners whose current token a user who is neither an admin nor a superadmin received';
    private const TEMPLATES = '-- Job templates whose jobs runner <runner id> ran';
    private const ROLES = '-- Roles besides admin that hold runner-group.create or runner-group.update, with their users';

    /** The placeholder of the job template query. */
    private const RUNNER_ID = '<runner id>';

    /** The permissions operators lose. */
    private const MANAGEMENT = ['runner-group.create', 'runner-group.update'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/' . RunnerManagementDocsContractTest::MIGRATION . '.php';
    }

    /**
     * The section has exactly these five ```sql blocks, in this order, each
     * a read-only SELECT that runs on the schema of this release.
     */
    public function testEverySqlBlockOfTheSectionIsOneOfTheFiveReadOnlyQueries(): void
    {
        $blocks = $this->sqlBlocks();
        $comments = [self::USERS, self::CHANGES, self::TOKENS, self::TEMPLATES, self::ROLES];
        $this->assertCount(count($comments), $blocks);
        foreach ($comments as $index => $comment) {
            $this->assertStringStartsWith($comment . "\n", $blocks[$index]);
            $this->assertSame(1, preg_match('/^\s*SELECT\b/', (string)preg_replace('/^\s*--.*$/m', '', $blocks[$index])), "{$comment} is a SELECT.");
            $this->assertIsArray($this->rowsOf($comment, 0));
        }
    }

    /**
     * The first query lists the users who manage runners only as operators,
     * through the operator role or a custom role that includes it; these
     * lose the permissions with the update, the others keep them.
     */
    public function testTheFirstQueryListsWhoManagesRunnersOnlyAsAnOperator(): void
    {
        $auth = $this->auth();
        $operator = $this->createUserWithRole('docs-rm-operator', 'operator');
        $admin = $this->createUserWithRole('docs-rm-admin', 'operator');
        $this->assignRole((int)$admin->id, 'admin');
        $superadmin = $this->createUserWithRole('docs-rm-superadmin', 'operator');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $including = $auth->createRole('docs-rm-including-' . uniqid());
        $auth->add($including);
        $operatorRole = $auth->getRole('operator');
        $this->assertNotNull($operatorRole);
        $auth->addChild($including, $operatorRole);
        $deputy = $this->createUser('docs-rm-deputy');
        $auth->assign($including, (string)$deputy->id);
        $manager = $this->createUserWithRole('docs-rm-op-manager', 'operator');
        $auth->assign($this->customRole(['runner-group.update']), (string)$manager->id);
        $viewer = $this->createUserWithRole('docs-rm-viewer', 'viewer');

        $ids = array_map(static fn (User $user): int => (int)$user->id, [$operator, $admin, $superadmin, $deputy, $manager, $viewer]);
        $rows = array_values(array_filter($this->rowsOf(self::USERS), static fn (array $row): bool => in_array((int)$row['id'], $ids, true)));

        $this->assertSame([[(int)$operator->id, 'operator'], [(int)$deputy->id, $including->name]], array_map(static fn (array $row): array => [(int)$row['id'], $row['role']], $rows));
        $this->assertSame(['id', 'username', 'role'], array_keys($rows[0]));
        foreach (self::MANAGEMENT as $permission) {
            $this->assertFalse($auth->checkAccess((int)$operator->id, $permission), "An operator lost {$permission}.");
            $this->assertFalse($auth->checkAccess((int)$deputy->id, $permission), "A role that includes operator lost {$permission}.");
            $this->assertTrue($auth->checkAccess((int)$admin->id, $permission), "An admin keeps {$permission}.");
        }
        $this->assertTrue($auth->checkAccess((int)$manager->id, 'runner-group.update'), 'Another role keeps what it holds.');
    }

    /**
     * The second query lists every change of runners and runner groups by
     * users who are neither admins nor superadmins, a user who no longer
     * exists included, and the third the runners whose current token such a
     * user received; neither lists what admins, superadmins and
     * self-registration did, nor a token issued since.
     */
    public function testTheAuditQueriesListWhatUsersWhoAreNeitherAdminsNorSuperadminsDid(): void
    {
        $owner = $this->createUserWithRole('docs-rm-owner', 'admin');
        $group = $this->createRunnerGroup((int)$owner->id);
        $target = $this->createRunnerGroup((int)$owner->id);
        $fixtures = [];
        foreach (['regenerated', 'moved', 'kept', 'reregistered', 'superadmin', 'gone', 'self'] as $name) {
            $fixtures[$name] = $this->createRunner((int)$group->id, (int)$owner->id);
        }
        $before = $this->lastAuditId();

        $manager = $this->createUser('docs-rm-manager');
        $this->auth()->assign($this->customRole(['runner-group.view', ...self::MANAGEMENT]), (string)$manager->id);
        $this->loginAs($manager);
        $registered = $this->registerRunner($group, 'docs-rm-registered-' . uniqid());
        $this->regenerate($fixtures['regenerated']);
        $this->post('runner', 'move', ['id' => (int)$fixtures['moved']->id], ['target_group_id' => (string)$target->id]);
        $this->regenerate($fixtures['kept']);
        $this->regenerate($fixtures['reregistered']);
        $this->post('runner-group', 'create', [], ['RunnerGroup' => ['name' => 'docs-rm-group-' . uniqid(), 'description' => '']]);
        $this->post('runner-group', 'update', ['id' => (int)$group->id], ['RunnerGroup' => ['name' => 'docs-rm-renamed-' . uniqid()]]);
        $this->post('runner', 'delete', ['id' => (int)$this->registerRunner($group, 'docs-rm-deleted-' . uniqid())->id]);
        $byManager = $this->auditIdsSince($before);

        $this->loginAs($owner);
        $this->regenerate($fixtures['regenerated']);
        $superadmin = $this->createUser('docs-rm-super');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->loginAs($superadmin);
        $this->regenerate($fixtures['superadmin']);
        $this->entry(AuditLog::ACTION_RUNNER_REREGISTERED, 'runner', (int)$fixtures['reregistered']->id, null);
        $this->entry(AuditLog::ACTION_RUNNER_CREATED, 'runner', (int)$fixtures['self']->id, null);
        $gone = $this->entry(AuditLog::ACTION_RUNNER_TOKEN_REGENERATED, 'runner', (int)$fixtures['gone']->id, (int)User::find()->max('id') + 1000);
        $this->entry(AuditLog::ACTION_JOB_LAUNCHED, 'job', 1, (int)$manager->id);

        $this->assertCount(9, $byManager, 'The manager registered, regenerated three times, moved, created and changed a group, registered and deleted.');
        $changes = array_values(array_filter($this->rowsOf(self::CHANGES), static fn (array $row): bool => (int)$row['id'] > $before));
        $this->assertSame([...$byManager, $gone], array_map(static fn (array $row): int => (int)$row['id'], $changes), 'The changes of the manager and of a user who no longer exists, by ID.');
        $this->assertChangesAreDescribedAsTheSectionSays($changes, $manager);

        $tokens = $this->rowsOf(self::TOKENS);
        $runnerIds = array_map(static fn (Runner $runner): int => (int)$runner->id, [$registered, ...array_values($fixtures)]);
        $listed = array_values(array_filter($tokens, static fn (array $row): bool => in_array((int)$row['runner_id'], $runnerIds, true)));
        $expected = [(int)$fixtures['kept']->id => 'runner.token.regenerated', (int)$fixtures['gone']->id => 'runner.token.regenerated', (int)$registered->id => 'runner.created'];
        ksort($expected);
        $this->assertSame($expected, array_column($listed, 'action', 'runner_id'), 'The runners whose current token the manager or a user who no longer exists received.');
        $this->assertSame(['runner_id', 'runner', 'runner_group', 'action', 'received_at', 'user_id', 'username'], array_keys($listed[0]));
        $usernames = array_column($listed, 'username', 'runner_id');
        $this->assertArrayHasKey((int)$fixtures['gone']->id, $usernames);
        $this->assertNull($usernames[(int)$fixtures['gone']->id], 'A user who no longer exists shows without a username.');
        $this->assertSame($manager->username, $usernames[(int)$registered->id]);
    }

    /**
     * The job template query lists, for a runner, the job templates whose
     * jobs it ran, until the runner is deleted: deleting it clears the
     * runner of its jobs. The job.started entries keep naming the runner and
     * the credentials each job got.
     */
    public function testTheJobTemplateQueryListsWhatARunnerRanUntilItIsDeleted(): void
    {
        $owner = $this->createUserWithRole('docs-rm-jobs-owner', 'admin');
        $ownerId = (int)$owner->id;
        $group = $this->createRunnerGroup($ownerId);
        $projectId = (int)$this->createProject($ownerId)->id;
        $inventoryId = (int)$this->createInventory($ownerId)->id;
        $credential = $this->createCredential($ownerId);
        $deploy = $this->createJobTemplate($projectId, $inventoryId, (int)$group->id, $ownerId);
        $deploy->credential_id = (int)$credential->id;
        $deploy->save(false);
        $backup = $this->createJobTemplate($projectId, $inventoryId, (int)$group->id, $ownerId);
        $runner = $this->createRunner((int)$group->id, $ownerId);
        $jobs = [];
        foreach ([$deploy, $deploy, $backup] as $template) {
            $jobs[] = $this->launchService()->launch($template, $ownerId);
        }
        foreach ($jobs as $job) {
            $this->assertSame((int)$job->id, (int)$this->claimService()->claim($group, $runner)?->id, 'The runner claims the oldest job of its group.');
        }
        $elsewhere = $this->createRunnerGroup($ownerId);
        $other = $this->createRunner((int)$elsewhere->id, $ownerId);
        $this->launchService()->launch($this->createJobTemplate($projectId, $inventoryId, (int)$elsewhere->id, $ownerId), $ownerId);
        $this->assertNotNull($this->claimService()->claim($elsewhere, $other));

        $rows = $this->rowsOf(self::TEMPLATES, (int)$runner->id);
        $this->assertSame([[(int)$deploy->id, 2], [(int)$backup->id, 1]], array_map(static fn (array $row): array => [(int)$row['job_template_id'], (int)$row['jobs']], $rows));
        $this->assertSame(['job_template_id', 'job_template', 'project_id', 'jobs', 'first_started', 'last_started'], array_keys($rows[0]));
        $this->assertSame([$deploy->name, $projectId], [$rows[0]['job_template'], (int)$rows[0]['project_id']]);

        $runnerId = (int)$runner->id;
        $runnerName = $runner->name;
        $this->assertSame(1, $runner->delete());
        foreach ($jobs as $job) {
            $job->refresh();
            $this->assertNull($job->runner_id, 'Deleting the runner clears the runner of its jobs.');
        }
        $this->assertSame([], $this->rowsOf(self::TEMPLATES, $runnerId), 'The query finds none of them afterwards.');
        $started = $this->startedEntryOf($jobs[0]);
        $this->assertSame([$runnerId, $runnerName], [$started['runner_id'] ?? null, $started['runner_name'] ?? null], 'job.started still names the runner.');
        $this->assertSame([(int)$credential->id], array_column((array)($started['credentials'] ?? []), 'id'), 'and the credentials the job got.');
    }

    /**
     * The role query lists the roles besides admin that hold the
     * permissions, one row for each user they are assigned to and one
     * without a user for a role nobody holds; on the migrated roles it lists
     * no built-in role, until someone gives viewer or operator the
     * permissions back.
     */
    public function testTheRoleQueryListsTheRolesBesidesAdminThatStillManageRunners(): void
    {
        foreach ($this->rowsOf(self::ROLES) as $row) {
            $this->assertNotContains($row['role'], ['viewer', 'operator', 'admin'], 'No built-in role besides admin manages runners after the update.');
        }
        $auth = $this->auth();
        $managers = $this->customRole(self::MANAGEMENT);
        $first = $this->createUser('docs-rm-role-first');
        $second = $this->createUser('docs-rm-role-second');
        $auth->assign($managers, (string)$first->id);
        $auth->assign($managers, (string)$second->id);
        $unused = $this->customRole(['runner-group.create', 'runner-group.view']);
        $viewer = $auth->getRole('viewer');
        $update = $auth->getPermission('runner-group.update');
        $this->assertNotNull($viewer);
        $this->assertNotNull($update);
        $auth->addChild($viewer, $update);

        $rows = $this->rowsOf(self::ROLES);
        $this->assertSame(['role', 'permissions', 'user_id', 'username'], array_keys($rows[0]));
        $byRole = [];
        foreach ($rows as $row) {
            $byRole[(string)$row['role']][] = [(string)$row['permissions'], $row['user_id'] === null ? null : (int)$row['user_id']];
        }
        $this->assertSame([['runner-group.create,runner-group.update', (int)$first->id], ['runner-group.create,runner-group.update', (int)$second->id]], $byRole[$managers->name] ?? null);
        $this->assertSame([['runner-group.create', null]], $byRole[$unused->name] ?? null, 'A role nobody holds shows once, without a user.');
        $this->assertArrayHasKey('viewer', $byRole, 'Viewer is listed once it holds a permission again.');
    }

    /**
     * The migration prints, on the default roles, the lines of the
     * section's block; where admin held a permission only through operator,
     * the quoted line that grants it comes before the line that removes it.
     * Reverting it gives the operator role both permissions back, and the
     * direct grants of the admin role stay.
     */
    public function testTheMigrationPrintsTheQuotedLinesAndRevertingItGivesOperatorsThePermissionsBack(): void
    {
        $auth = $this->auth();
        $this->giveOperatorTheManagementBack();
        $default = $this->linesOf(function (): void {
            $this->migration()->safeUp();
        });
        $section = $this->flat('docs/updating.md', RunnerManagementDocsContractTest::UPDATE_SECTION);
        $this->assertCount(3, $default, implode("\n", $default));
        $this->assertMentions('``` ' . implode(' ', $default) . ' ```', $section, 'The output block of the section');

        $admin = $auth->getRole('admin');
        $update = $auth->getPermission('runner-group.update');
        $this->assertNotNull($admin);
        $this->assertNotNull($update);
        $this->assertTrue($auth->removeChild($admin, $update), 'The test database grants admin runner-group.update directly.');
        $this->giveOperatorTheManagementBack();
        $lines = $this->linesOf(function (): void {
            $this->migration()->safeUp();
        });
        $granted = array_search('> Granted runner-group.update to the admin role directly.', $lines, true);
        $this->assertIsInt($granted, implode("\n", $lines));
        $this->assertMentions('`' . $lines[$granted] . '`', $section, 'The section, on admins who held a permission through operator,');
        $this->assertSame('> Removed runner-group.update from the operator role.', $lines[$granted + 1] ?? null, 'It comes before the line that removes it.');

        $this->assertSame([], $this->linesOf(function (): void {
            $this->migration()->safeDown();
        }), 'Reverting prints nothing.');
        $operator = $auth->getRole('operator');
        $this->assertNotNull($operator);
        foreach (self::MANAGEMENT as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission);
            $this->assertTrue($auth->hasChild($operator, $permission), "Reverting gives operator {$name} back.");
            $this->assertTrue($auth->hasChild($admin, $permission), "The direct grant of {$name} to admin stays.");
        }
    }

    /**
     * Where viewer, which the role editor lets admins change, still grants a
     * permission after the migration, and a role that only operator includes
     * (here the section's example runner-admins) the other, the migration
     * prints the two lines the section quotes, in place of its closing line.
     * It runs with viewer as the default role, as config/console.php gives
     * it to the console application that migrates.
     */
    public function testTheMigrationNamesWhatStillGrantsOperatorsWithTheLinesTheSectionQuotes(): void
    {
        $auth = $this->auth();
        $this->assertInstanceOf(BaseManager::class, $auth);
        $this->giveOperatorTheManagementBack();
        $viewer = $auth->getRole('viewer');
        $operator = $auth->getRole('operator');
        $create = $auth->getPermission('runner-group.create');
        $update = $auth->getPermission('runner-group.update');
        $this->assertNotNull($viewer);
        $this->assertNotNull($operator);
        $this->assertNotNull($create);
        $this->assertNotNull($update);
        $this->assertNull($auth->getRole('runner-admins'), 'The test database has no role runner-admins.');
        $auth->addChild($viewer, $update);
        $runnerAdmins = $auth->createRole('runner-admins');
        $auth->add($runnerAdmins);
        $auth->addChild($operator, $runnerAdmins);
        $auth->addChild($runnerAdmins, $update);
        $auth->addChild($runnerAdmins, $create);

        $defaultRoles = $auth->getDefaultRoles();
        $auth->setDefaultRoles(['viewer']);
        try {
            $lines = $this->linesOf(function (): void {
                $this->migration()->safeUp();
            });
        } finally {
            $auth->setDefaultRoles($defaultRoles);
        }

        $section = $this->flat('docs/updating.md', RunnerManagementDocsContractTest::UPDATE_SECTION);
        $this->assertSame(
            [
                '> Removed runner-group.create from the operator role.',
                '> Removed runner-group.update from the operator role.',
                '> Operators still hold runner-group.create through the runner-admins role: take it from that role to leave runner management to admins.',
                '> Operators still hold runner-group.update through the runner-admins role: take it from that role to leave runner management to admins.',
                '> Every signed-in user still holds runner-group.update through the viewer role: take it from that role to leave runner management to admins.',
            ],
            $lines
        );
        foreach ([$lines[3], $lines[4]] as $line) {
            $this->assertMentions('`' . $line . '`', $section, 'The section, on what still grants operators a permission,');
        }
        $this->assertTrue($auth->hasChild($viewer, $update), 'The migration takes nothing from viewer.');
        $this->assertTrue($auth->hasChild($runnerAdmins, $update), 'Nor from a role that operator includes.');
    }

    /**
     * Each change names its runner or group, with the groups the section
     * names, and the user who made it.
     *
     * @param list<array<string, mixed>> $changes
     */
    private function assertChangesAreDescribedAsTheSectionSays(array $changes, User $manager): void
    {
        $this->assertSame(['id', 'changed_at', 'action', 'object_type', 'object_id', 'user_id', 'username', 'metadata'], array_keys($changes[0]));
        $byAction = [];
        foreach ($changes as $row) {
            $this->assertSame(str_starts_with((string)$row['action'], 'runner-group.') ? 'runner_group' : 'runner', $row['object_type']);
            $byAction[(string)$row['action']] = json_decode((string)$row['metadata'], true);
        }
        $this->assertSame(
            ['runner.created', 'runner.token.regenerated', 'runner.updated', 'runner-group.created', 'runner-group.updated', 'runner.deleted'],
            array_keys($byAction)
        );
        $this->assertArrayHasKey('group_id', (array)$byAction['runner.created'], 'A registered runner names its group.');
        $this->assertArrayHasKey('group_id', (array)$byAction['runner.deleted'], 'So does a deleted one.');
        $this->assertArrayHasKey('from_group_id', (array)$byAction['runner.updated'], 'A move names both groups.');
        $this->assertArrayHasKey('to_group_id', (array)$byAction['runner.updated'], 'A move names both groups.');
        $this->assertSame($manager->username, $changes[0]['username']);
        $this->assertNull($changes[count($changes) - 1]['username'], 'A user who no longer exists shows without a username.');
    }

    /**
     * The ```sql blocks of the section, in order.
     *
     * @return list<string>
     */
    private function sqlBlocks(): array
    {
        preg_match_all('/^```sql\R(.*?)^```/ms', $this->section('docs/updating.md', RunnerManagementDocsContractTest::UPDATE_SECTION), $blocks);

        return $blocks[1];
    }

    /**
     * The rows the query that starts with $comment returns on the test
     * database, with $runnerId for the placeholder of the job template
     * query.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOf(string $comment, int $runnerId = 0): array
    {
        $queries = array_values(array_filter($this->sqlBlocks(), static fn (string $sql): bool => str_starts_with($sql, $comment . "\n")));
        $this->assertCount(1, $queries, "The section has one query that starts with {$comment}.");
        $sql = str_replace(self::RUNNER_ID, (string)$runnerId, rtrim(trim($queries[0]), ';'));
        /** @var list<array<string, mixed>> $rows */
        $rows = \Yii::$app->db->createCommand($sql)->queryAll();

        return $rows;
    }

    /**
     * Registers a runner named $name in $group through the web UI, as the
     * signed-in user.
     */
    private function registerRunner(RunnerGroup $group, string $name): Runner
    {
        $this->post('runner', 'create', [], ['group_id' => (string)$group->id, 'name' => $name, 'description' => '']);
        $runner = Runner::findOne(['name' => $name]);
        $this->assertNotNull($runner, "{$name} was registered.");

        return $runner;
    }

    private function regenerate(Runner $runner): void
    {
        $before = $runner->token_hash;
        $this->post('runner', 'regenerate-token', ['id' => (int)$runner->id]);
        $this->assertNotSame($before, Runner::findOne($runner->id)?->token_hash, 'The token was regenerated.');
    }

    /**
     * Posts $body to the web action $action of the runner or runner group
     * controller, through runAction(), so the access filter runs.
     *
     * @param array<string, int> $params
     * @param array<string, mixed> $body
     */
    private function post(string $controller, string $action, array $params, array $body = []): void
    {
        $this->setPost($body);
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Module::class, $app);
        $ctrl = $controller === 'runner'
            ? new class ('runner', $app) extends RunnerController {
                /** @param array<int|string, mixed>|string $url */
                public function redirect($url, $statusCode = 302): Response
                {
                    return new Response();
                }
            }
            : new class ('runner-group', $app) extends RunnerGroupController {
                /** @param array<int|string, mixed>|string $url */
                public function redirect($url, $statusCode = 302): Response
                {
                    return new Response();
                }
            };
        $this->assertInstanceOf(Response::class, $ctrl->runAction($action, $params), "{$controller}/{$action} was done.");
        $session = \Yii::$app->session;
        $this->assertInstanceOf(\yii\web\Session::class, $session);
        $session->removeAllFlashes();
    }

    /**
     * A custom role with $permissions.
     *
     * @param list<string> $permissions
     */
    private function customRole(array $permissions): \yii\rbac\Role
    {
        $auth = $this->auth();
        $role = $auth->createRole('docs-rm-role-' . uniqid());
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }

        return $role;
    }

    /**
     * Gives the operator role runner-group.create and runner-group.update,
     * as m000025 did before the migration took them.
     */
    private function giveOperatorTheManagementBack(): void
    {
        $auth = $this->auth();
        $operator = $auth->getRole('operator');
        $this->assertNotNull($operator);
        foreach (self::MANAGEMENT as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission);
            if (!$auth->hasChild($operator, $permission)) {
                $auth->addChild($operator, $permission);
            }
        }
    }

    /**
     * The migration $class (required in setUpBeforeClass()) on the test
     * database, printing only its own lines.
     */
    private function migration(string $class = RunnerManagementDocsContractTest::MIGRATION): Migration
    {
        $migration = new $class(['db' => \Yii::$app->db, 'compact' => true]);
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    /**
     * The lines $run echoes, without their indent.
     *
     * @param \Closure(): void $run
     * @return list<string>
     */
    private function linesOf(\Closure $run): array
    {
        ob_start();
        try {
            $run();
        } finally {
            $printed = (string)ob_get_clean();
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $printed) ?: [])));
    }

    /**
     * An audit entry of $action, as the code writes it; its ID.
     */
    private function entry(string $action, string $objectType, int $objectId, ?int $userId): int
    {
        $db = \Yii::$app->db;
        $db->createCommand()->insert('{{%audit_log}}', [
            'user_id' => $userId,
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'metadata' => (string)json_encode(['source' => 'docs']),
            'created_at' => time(),
        ])->execute();

        return (int)$db->getLastInsertID();
    }

    /**
     * The metadata of the job.started entry of $job.
     *
     * @return array<string, mixed>
     */
    private function startedEntryOf(Job $job): array
    {
        $entry = AuditLog::findOne(['action' => AuditLog::ACTION_JOB_STARTED, 'object_type' => 'job', 'object_id' => $job->id]);
        $this->assertNotNull($entry, 'The claim wrote job.started.');
        $metadata = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($metadata);

        /** @var array<string, mixed> $metadata */
        return $metadata;
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    /**
     * The IDs of the audit entries after $id, in order.
     *
     * @return list<int>
     */
    private function auditIdsSince(int $id): array
    {
        return array_map('intval', AuditLog::find()->select('id')->where(['>', 'id', $id])->orderBy(['id' => SORT_ASC])->column());
    }

    private function auth(): ManagerInterface
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);

        return $auth;
    }

    private function launchService(): JobLaunchService
    {
        $service = \Yii::$app->get('jobLaunchService');
        $this->assertInstanceOf(JobLaunchService::class, $service);

        return $service;
    }

    private function claimService(): JobClaimService
    {
        $service = \Yii::$app->get('jobClaimService');
        $this->assertInstanceOf(JobClaimService::class, $service);

        return $service;
    }
}
