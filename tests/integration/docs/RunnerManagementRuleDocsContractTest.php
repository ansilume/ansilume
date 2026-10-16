<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\controllers\api\v1\RunnersController;
use app\controllers\RunnerController;
use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\tests\unit\docs\ReadsOperatorDocs;
use app\tests\unit\docs\RunnerManagementDocsContractTest;
use PHPUnit\Framework\TestCase;

/**
 * CLAUDE.md's security rule 16 is what was built: the two permissions it
 * names are the ones m000087_000000_restrict_runner_management takes from
 * the operator role, the migrated roles of the test database give them to
 * admin and to no other built-in role, every request that issues a runner
 * token, puts a runner into a group or deletes a runner requires
 * runner-group.update, and docs/runners.md documents both limits the rule
 * points to: a custom role with these permissions, and the bootstrap
 * secret, which also creates the runner group `default`. The current state
 * and the domain language of CLAUDE.md say the same.
 *
 * Regression (runner takeover): CLAUDE.md had no rule on who manages
 * runners, the operator role held runner-group.create and
 * runner-group.update, and docs/runners.md named no custom role as a way
 * around the rule. The test fails on the code and the docs from before.
 * Rule 16 did not name deleting runners, and the domain language gave a
 * runner group "shared token authentication", which it does not have.
 */
class RunnerManagementRuleDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/' . RunnerManagementDocsContractTest::MIGRATION . '.php';
    }

    public function testClaudeMdRule16IsWhatWasBuilt(): void
    {
        $rule = $this->rule(16);
        $permissions = (new \ReflectionClassConstant(RunnerManagementDocsContractTest::MIGRATION, 'PERMISSIONS'))->getValue();
        $this->assertSame(['runner-group.create', 'runner-group.update'], $permissions, 'The permissions the migration takes.');
        $this->assertStringStartsWith(
            '16. Only admins register, move and delete runners, regenerate runner tokens, and create or change runner groups (`runner-group.create`, `runner-group.update`; the operator role no longer holds them).',
            $rule,
            'Rule 16 of CLAUDE.md names deleting runners too.'
        );

        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        foreach (['viewer' => false, 'operator' => false, 'admin' => true] as $role => $holds) {
            $held = array_keys($auth->getPermissionsByRole($role));
            foreach ($permissions as $permission) {
                $this->assertSame($holds, in_array($permission, $held, true), "Whether the migrated {$role} role holds {$permission}.");
            }
        }

        $this->assertMentions(
            'A runner receives the decrypted credentials, the inventory and the extra vars of every job of its group, whatever the team, approved jobs included, so whoever registers a runner or holds a runner token can take over those jobs.',
            $rule,
            'Rule 16 of CLAUDE.md'
        );
        $this->assertMentions('New code that issues a runner token or puts a runner into a group requires `runner-group.update`.', $rule, 'Rule 16 of CLAUDE.md');
        $this->assertSame(
            ['create' => 'runner-group.update', 'delete' => 'runner-group.update', 'move' => 'runner-group.update', 'regenerate-token' => 'runner-group.update'],
            $this->tokenAndGroupRules(),
            'Registering a runner, deleting it, regenerating its token and moving it need runner-group.update, on the web and in the API.'
        );

        $this->assertMentions(
            'A custom role with these permissions, and whoever holds the bootstrap secret (`RUNNER_BOOTSTRAP_SECRET`), can do the same: documented limits (`docs/runners.md`).',
            $rule,
            'Rule 16 of CLAUDE.md'
        );
        $limits = array_values(array_filter(
            $this->units('docs/runners.md', RunnerManagementDocsContractTest::RUNNERS_SECTION),
            static fn (string $unit): bool => str_starts_with($unit, '- **A custom role**') || str_starts_with($unit, '- **The bootstrap secret** (`RUNNER_BOOTSTRAP_SECRET`)')
        ));
        $this->assertCount(2, $limits, 'runners.md documents both limits of rule 16.');
        $this->assertMentions(
            'with `runner-group.create` and `runner-group.update` lets its users register runners, regenerate their tokens, move and delete runners, and create and change runner groups, and so take over the jobs of every runner group.',
            $limits[0],
            'The custom role limit'
        );
        $this->assertMentions('lets whoever holds it register runners in any runner group without any permission', $limits[1], 'The bootstrap secret limit');
        $this->assertMentions(
            'Where no runner group is named `default`, registering a runner without a group creates that group',
            $limits[1],
            'The bootstrap secret limit says that it creates a runner group (RunnerSelfRegistrationDocsContractTest runs it).'
        );
        $this->assertMentions(
            '- Runner groups with token-based runner registration, managed by admins',
            $this->flat('CLAUDE.md', '## Current State'),
            'The current state of CLAUDE.md'
        );
    }

    /**
     * The domain language of CLAUDE.md says what a runner group is: a job
     * template names the one whose runners run its jobs, and each runner,
     * not the group, has a token. It said "with shared token
     * authentication", and a runner group has no token.
     */
    public function testClaudeMdNamesARunnerGroupAsBuilt(): void
    {
        $language = $this->flat('CLAUDE.md', '## Domain Language');
        $this->assertMentions(
            '- **Runner Group**: logical grouping of runners; a job template names the runner group whose runners run its jobs, and each runner authenticates with a token of its own',
            $language,
            'The domain language of CLAUDE.md'
        );
        $this->assertStringNotContainsString('shared token', $language);
        $this->assertSame([], preg_grep('/token/', RunnerGroup::getTableSchema()->getColumnNames()), 'A runner group has no token.');
        $this->assertNotNull(Runner::getTableSchema()->getColumn('token_hash'), 'Each runner has one.');
        $this->assertNotNull(JobTemplate::getTableSchema()->getColumn('runner_group_id'), 'A job template names its runner group.');
    }

    /**
     * The rule $number of CLAUDE.md's security requirements, on one line.
     */
    private function rule(int $number): string
    {
        foreach ($this->units('CLAUDE.md', '## Security Requirements') as $unit) {
            if (str_starts_with($unit, $number . '. ')) {
                return $unit;
            }
        }
        $this->fail("CLAUDE.md has no security rule {$number}.");
    }

    /**
     * The permission that registering a runner (web), deleting it,
     * regenerating its token and moving it (web and API) require; the same
     * on both, or the test fails.
     *
     * @return array<string, string>
     */
    private function tokenAndGroupRules(): array
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Module::class, $app);
        $web = new RunnerController('runner', $app);
        $webRules = [];
        foreach ((array)(new \ReflectionMethod($web, 'accessRules'))->invoke($web) as $rule) {
            $this->assertIsArray($rule);
            foreach ((array)($rule['actions'] ?? []) as $action) {
                $webRules[(string)$action] = implode(',', array_map('strval', (array)($rule['roles'] ?? [])));
            }
        }
        $api = new RunnersController('api/v1/runners', $app);
        $apiRules = (array)(new \ReflectionMethod($api, 'apiAccessRules'))->invoke($api);
        $rules = ['create' => $webRules['create'] ?? null];
        foreach (['delete', 'move', 'regenerate-token'] as $action) {
            $this->assertSame($webRules[$action] ?? null, $apiRules[$action] ?? null, "The web and the API require the same for {$action}.");
            $rules[$action] = $webRules[$action] ?? null;
        }

        return array_map('strval', $rules);
    }
}
