<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\controllers\api\v1\RunnerGroupsController;
use app\controllers\api\v1\RunnersController;
use app\controllers\RunnerController;
use app\controllers\RunnerGroupController;
use app\helpers\PermissionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * What the docs say about who manages runners (CLAUDE.md security rule 16)
 * is what the code does: the section of docs/updating.md for the update
 * from 2.11.0 quotes the migration m000087_000000_restrict_runner_management,
 * the note of the runner group page (RunnerGroupController) and the label of
 * the role editor (PermissionCatalog), and names the requests that the web
 * UI and the API refuse, as the access rules of the controllers decide;
 * docs/runners.md says who manages runners and names the two documented
 * limits; README.md's security list says the same.
 *
 * The queries of the section, and the lines the migration prints and what
 * reverting it gives back, run in RunnerManagementQueriesDocsContractTest;
 * rule 16 against the migrated roles in RunnerManagementRuleDocsContractTest;
 * the spec in OpenApiRunnerManagementContractTest.
 *
 * Regression (runner takeover, fixed by m000087): up to 2.11.0 the operator
 * role held runner-group.create and runner-group.update, so every operator
 * could register runners in any runner group and regenerate the token of
 * any runner, and no doc said that whoever does so can take over the jobs
 * of the group; docs/runners.md named the bootstrap secret as the only way
 * to register runners in any group. Each test fails on the docs from
 * before.
 */
class RunnerManagementDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    /** The section of docs/updating.md. */
    public const UPDATE_SECTION = '## After updating from 2.11.0 or older: only admins manage runners';

    /** The anchor GitHub gives the section. */
    public const SECTION_ANCHOR = 'after-updating-from-2110-or-older-only-admins-manage-runners';

    /** The section of docs/runners.md on who manages runners. */
    public const RUNNERS_SECTION = '## Who manages runners';

    /** The anchor GitHub gives that section. */
    public const RUNNERS_ANCHOR = 'who-manages-runners';

    /** The migration that takes the permissions from the operator role. */
    public const MIGRATION = 'm000087_000000_restrict_runner_management';

    /**
     * The API requests that create or change a runner group, or move,
     * delete or regenerate the token of a runner: controller and action.
     */
    private const API_WRITES = [
        'POST /api/v1/runner-groups' => [RunnerGroupsController::class, 'create'],
        'PUT /api/v1/runner-groups/{id}' => [RunnerGroupsController::class, 'update'],
        'POST /api/v1/runners/{id}/move' => [RunnersController::class, 'move'],
        'DELETE /api/v1/runners/{id}' => [RunnersController::class, 'delete'],
        'POST /api/v1/runners/{id}/regenerate-token' => [RunnersController::class, 'regenerate-token'],
    ];

    /** The web actions that register, regenerate, move and delete runners, and create and change groups. */
    private const WEB_WRITES = [
        RunnerController::class => ['create' => 'runner-group.update', 'regenerate-token' => 'runner-group.update', 'move' => 'runner-group.update', 'delete' => 'runner-group.update'],
        RunnerGroupController::class => ['create' => 'runner-group.create', 'update' => 'runner-group.update'],
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION . '.php';
    }

    /**
     * The section follows the newest one, of the update that gives
     * inventories approval rules (2.12.0), and its anchor and that of
     * runners.md's section are the ones GitHub gives their headings, which
     * the docs link to.
     */
    public function testTheSectionFollowsTheNewest(): void
    {
        preg_match_all('/^## After updating from .*$/m', $this->read('docs/updating.md'), $headings);
        $this->assertSame(InventoryApprovalDocsContractTest::UPDATE_SECTION, $headings[0][0] ?? null, 'The newest section comes first.');
        $this->assertSame(self::UPDATE_SECTION, $headings[0][1] ?? null, 'This section follows.');
        foreach ([self::UPDATE_SECTION => self::SECTION_ANCHOR, self::RUNNERS_SECTION => self::RUNNERS_ANCHOR] as $heading => $anchor) {
            $this->assertSame(
                $anchor,
                str_replace(' ', '-', (string)preg_replace('/[^a-z0-9 -]/', '', strtolower(substr($heading, 3)))),
                'The anchor GitHub gives the heading.'
            );
        }
    }

    /**
     * regression: no update note said that operators lose runner
     * management, why, or what stays. The intro says it with the
     * permissions the migration takes, and every item the section points
     * to exists.
     */
    public function testRegressionTheUpdateSectionSaysOnlyAdminsManageRunnersNow(): void
    {
        [$create, $update] = $this->permissions();
        $section = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $expected = [
            "Only admins register runners, regenerate runner tokens, move and delete runners, and create or change runner groups: the operator role no longer holds `{$create}` and `{$update}` (see **Only admins manage runners**).",
            'A runner receives the decrypted credentials, the inventory and the extra vars of every job of its runner group, whatever the team, approved jobs included, so whoever registers a runner or holds its token can take over the jobs of its group (see **Why runners are for admins**).',
            'Operators still see runner groups and their runners, custom roles keep what they hold, and existing runners keep running with their tokens (see **Existing runners keep running**).',
            'The update has one migration, which changes only the roles; runners need no update (see **Database and deployment**).',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $section, 'The intro of the update section');
        }
        $this->assertMentions(
            "Give the users it lists who should keep managing runners the admin role, or a custom role with `{$create}` and `{$update}`, with which they can take over the jobs of every runner group.",
            $this->checklist(1),
            'Step 1 of the checklist'
        );

        $titles = $this->itemTitles();
        $this->assertSame(
            ['Who loses runner management', 'Only admins manage runners', 'Why runners are for admins', 'Existing runners keep running', 'Check who managed runners', 'A runner you do not trust', 'Roles that still manage runners', 'Database and deployment'],
            $titles,
            'The items of the section, in order.'
        );
        preg_match_all('/(?:\(see|under) \*\*([^*]+)\*\*/', $section, $references);
        $this->assertNotEmpty($references[1]);
        foreach ($references[1] as $reference) {
            $this->assertContains($reference, $titles, "The section points to **{$reference}**, which it does not have.");
        }
        for ($step = 1; $step <= 6; $step++) {
            $this->assertNotSame('', $this->checklist($step));
        }
    }

    /**
     * The item names every web and API request that needs the permissions
     * operators lose, as the access rules of the controllers require them,
     * and quotes the page's note, the API's answer and the role editor's
     * label; deleting runner groups stays as it was.
     */
    public function testTheItemNamesTheRefusedRequestsAsTheControllersCheckThem(): void
    {
        $permissions = $this->permissions();
        $item = $this->item('Only admins manage runners');
        foreach (self::API_WRITES as $request => [$controller, $action]) {
            $this->assertContains($this->apiRules($controller)[$action] ?? null, $permissions, "{$request} needs a permission operators lose.");
            $this->assertMentions('`' . $request . '`', $item, 'The item, on the API');
        }
        foreach ([RunnerGroupsController::class, RunnersController::class] as $controller) {
            foreach ($this->apiRules($controller) as $action => $permission) {
                if (in_array($permission, $permissions, true)) {
                    $this->assertContains([$controller, $action], self::API_WRITES, "The item names the API action {$controller}::{$action}.");
                }
            }
        }
        foreach (self::WEB_WRITES as $controller => $actions) {
            $rules = array_intersect_key($this->webRules($controller), $actions);
            ksort($rules);
            ksort($actions);
            $this->assertSame($actions, $rules, "The web actions of {$controller} need what the item says.");
        }
        $expected = [
            'Migration `' . self::MIGRATION . "` takes `{$permissions[0]}` and `{$permissions[1]}` from the operator role, and so from custom roles that include it.",
            'Admins keep both: the migration grants them to the admin role directly where it held them only through operator.',
            '`' . RunnerGroupController::RUNNER_MANAGEMENT_ADMIN_ONLY . '` where the form was;',
            'registering a runner, regenerating its token, moving or deleting it, and creating or changing a runner group answer `403` in the web UI and change nothing;',
            'answer `403` `{"error":{"message":"Forbidden."}}`, change nothing and return no token.',
            'Deleting runner groups (`runner-group.delete`) was for admins already.',
            'Operators and viewers keep `runner-group.view`',
            'The role editor and `GET /api/v1/permissions` call `runner-group.update` `' . PermissionCatalog::labelFor('runner-group.update') . '`.',
            "To let other users manage runners, give them a custom role with `runner-group.view`, `{$permissions[0]}` and `{$permissions[1]}`; with it they can take over the jobs of every runner group (see [Who manages runners](runners.md#"
                . self::RUNNERS_ANCHOR . ')).',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'The item **Only admins manage runners.**');
        }
        $this->assertStringContainsString(
            '<p class="text-muted small mb-4" data-testid="runner-management-admin-only"><?= Html::encode(RunnerGroupController::RUNNER_MANAGEMENT_ADMIN_ONLY) ?></p>',
            $this->read('views/runner-group/view.php'),
            'The group page shows the note the item quotes.'
        );
    }

    /**
     * The migration's lines are quoted in its words: the three lines of a
     * default installation in the order it prints them, and the lines of
     * the other cases; the permissions it takes are the two the docs name.
     */
    public function testTheMigrationOutputIsQuotedInItsWords(): void
    {
        [$create, $update] = $this->permissions();
        $this->assertSame(['runner-group.create', 'runner-group.update'], [$create, $update], 'The permissions the docs name.');
        $deployment = $this->item('Database and deployment');
        $this->assertMentions('One migration runs when the app container starts, `' . self::MIGRATION . '`.', $deployment, 'Database and deployment');
        $this->assertSame(
            [
                '> ' . sprintf($this->migrationText('REMOVED_FROM_OPERATOR'), $create),
                '> ' . sprintf($this->migrationText('REMOVED_FROM_OPERATOR'), $update),
                '> ' . $this->migrationText('RUNNERS_FOR_ADMINS'),
            ],
            $this->outputBlock(),
            'The output of a default installation, line by line.'
        );
        $quoted = [
            '> ' . sprintf($this->migrationText('GRANTED_TO_ADMIN'), $update),
            '> ' . sprintf($this->migrationText('ROLE_MISSING'), 'operator'),
            '> ' . sprintf($this->migrationText('ROLE_MISSING'), 'admin'),
            '> ' . sprintf($this->migrationText('PERMISSION_MISSING'), $create),
            '> ' . sprintf($this->migrationText('EVERY_USER_STILL_HOLDS'), $update, 'viewer', 'role'),
            '> ' . sprintf($this->migrationText('STILL_HELD'), $update, 'runner-admins', 'role'),
        ];
        foreach ($quoted as $line) {
            $this->assertMentions('`' . $line . '`', $deployment, 'Database and deployment');
        }
    }

    /**
     * regression (review of the runner step, finding 2): the migration said
     * that operators no longer manage runners where viewer, which operator
     * includes and every signed-in user holds, still granted a permission.
     * It names what still grants operators a permission now, in place of
     * that line: the section quotes both lines, says what they mean, and
     * what to do about them.
     */
    public function testRegressionTheLinesOnWhatStillGrantsOperatorsAreQuotedWithWhatToDo(): void
    {
        $deployment = $this->item('Database and deployment');
        $expected = [
            'The migration takes the two permissions from the operator role itself, not from the roles and permissions that operator includes: viewer, whose permissions admins can change in the role editor, keeps what it holds.',
            'For each of them that still grants one of the two permissions, the migration prints a line that names it, in place of the last line of the block above, as operators still manage runners then.',
            'For viewer, the default role of every signed-in user, and for what viewer includes, the line reads',
            'For what only operator includes, such as a role `runner-admins` that a change outside the role editor put below operator, it reads',
            'A permission that includes one of the two is named the same way, as `the <name> permission`.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $deployment, 'Database and deployment');
        }
        $this->assertSame(
            '> ' . $this->migrationText('RUNNERS_FOR_ADMINS'),
            $this->outputBlock()[2] ?? null,
            'The last line of the block is the one the lines replace.'
        );
        foreach (['STILL_HELD' => 'Operators still hold ', 'EVERY_USER_STILL_HOLDS' => 'Every signed-in user still holds '] as $name => $opening) {
            $this->assertStringStartsWith($opening, $this->migrationText($name), 'Step 2 of the checklist names the lines by their opening.');
            $this->assertStringContainsString(
                'through the <name> permission: take it from that permission',
                sprintf($this->migrationText($name), 'runner-group.update', '<name>', 'permission'),
                "{$name} names a permission as the section says."
            );
        }

        $this->assertMentions(
            'Where a line says that operators or every signed-in user still hold a permission through a role, take the permission from that role in the role editor (**Roles** → the role → **Edit**) unless its users should take over the jobs of every runner group: viewer is the role of every signed-in user.',
            $this->checklist(2),
            'Step 2 of the checklist'
        );
        $this->assertMentions(
            'A line that names a permission comes from a change made outside the role editor, which does not change what a permission includes: undo that change.',
            $this->checklist(2),
            'Step 2 of the checklist'
        );
        $this->assertMentions(
            'The query lists the roles that hold the permissions themselves. The users of a role that includes a listed one hold them too: the lines the migration printed name what viewer and operator include (see **Database and deployment**).',
            $this->item('Roles that still manage runners'),
            'Roles that still manage runners'
        );
    }

    /**
     * The rollback keeps the roles as the migration left them, and says how
     * operators get the permissions back; the Rollback steps point to it.
     */
    public function testTheRollbackSaysWhatStaysAndHowToRevertTheMigration(): void
    {
        $deployment = $this->item('Database and deployment');
        $expected = [
            'To roll back to 2.11.0, deploy it as [Rollback](#rollback) describes. 2.11.0 runs with the roles as the migration left them, so operators do not manage runners there either.',
            'revert the migration before you deploy 2.11.0, which does not have it: `docker compose exec app php yii migrate/down 1 --interactive=0` reverts `' . self::MIGRATION . '`.',
            'The direct grants it gave the admin role stay.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $deployment, 'The rollback of the section');
        }
        $this->assertMentions(
            'To roll back from the update that leaves runner management to admins (migration `m000087`), see **Database and deployment** under [After updating from 2.11.0 or older](#'
                . self::SECTION_ANCHOR . '): the older version keeps the roles as the migration left them.',
            $this->flat('docs/updating.md', '## Rollback'),
            'The Rollback steps of updating.md'
        );
    }

    /**
     * regression: runners.md named the bootstrap secret as the only way to
     * register runners in any runner group. It says who manages runners,
     * with the requests and permissions of the code, why, and both
     * documented limits of rule 16: a custom role, and the bootstrap
     * secret.
     */
    public function testRegressionRunnersMdSaysWhoManagesRunnersAndNamesBothLimits(): void
    {
        [$create, $update] = $this->permissions();
        $section = $this->flat('docs/runners.md', self::RUNNERS_SECTION);
        foreach (array_keys(self::API_WRITES) as $request) {
            $this->assertMentions('`' . $request . '`', $section, 'runners.md, on who manages runners,');
        }
        $this->assertSame('runner-group.delete', $this->apiRules(RunnerGroupsController::class)['delete'] ?? null);
        $expected = [
            'Only admins register runners (**Add Runner** on a runner group\'s page), regenerate runner tokens (**Regen Token**,',
            'and create, change and delete runner groups (**New Group**, **Edit** and **Delete** on the list of runner groups, `POST /api/v1/runner-groups`, `PUT /api/v1/runner-groups/{id}` and `DELETE /api/v1/runner-groups/{id}`).',
            "Creating a runner group needs `{$create}` and deleting one `runner-group.delete`; everything else needs `{$update}`.",
            'Of the built-in roles only admin holds these permissions, and without them a request answers `403` and changes nothing.',
            'to users without `runner-group.update` the group\'s page shows, in place of the Add Runner form, `' . RunnerGroupController::RUNNER_MANAGEMENT_ADMIN_ONLY . '`',
            'A runner claims every queued job of its group and receives the job\'s decrypted credentials, its inventory and its extra vars, whatever the team, approved jobs included: runner groups have no team scope.',
            'Whoever registers a runner or holds a runner\'s token can therefore take over the jobs of its group.',
            "Up to 2.11.0 the operator role held `{$create}` and `{$update}` as well, see [updating.md](updating.md#" . self::SECTION_ANCHOR . ').',
            "- **A custom role** with `{$create}` and `{$update}` lets its users register runners, regenerate their tokens, move and delete runners, and create and change runner groups, and so take over the jobs of every runner group.",
            'The role editor calls `runner-group.update` `' . PermissionCatalog::labelFor('runner-group.update') . '`.',
            '- **The bootstrap secret** (`RUNNER_BOOTSTRAP_SECRET`) lets whoever holds it register runners in any runner group without any permission, and re-register an existing runner of a group under its name, which gives the runner a new token (see [Option A: Self-registration](#option-a-self-registration-recommended-for-automated-deployments)).',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $section, 'runners.md, on who manages runners,');
        }

        $runners = $this->flat('docs/runners.md');
        $pointers = [
            '| `RUNNER_TOKEN` | Alternative | Pre-configured runner token. If set, skips self-registration. An admin creates it with **Add Runner** under Runner Groups, see [Option B: Pre-configured token](#option-b-pre-configured-token). |',
            '1. As an admin, go to **Runner Groups** → select a group → **Add Runner**; only admins add runners, see [Who manages runners](#' . self::RUNNERS_ANCHOR . ')',
            'An admin assigns a runner to a different group (see [Who manages runners](#' . self::RUNNERS_ANCHOR . ')):',
            "- **Runner management**: Only admins register runners and hand out their tokens, regenerate tokens, move and delete runners, and create and change runner groups, as a runner receives the decrypted credentials of every job of its group. A custom role with `{$create}` and `{$update}` allows the same, and the bootstrap secret registers runners: documented limits, see [Who manages runners](#"
                . self::RUNNERS_ANCHOR . ').',
            '- **Bootstrap secret**: Treat this like a password. Anyone with this secret can register runners in any runner group, and so take over the jobs of that group.',
            'The bootstrap secret lets anyone register a runner in any runner group, and so take over the jobs of that group (see [Who manages runners](#' . self::RUNNERS_ANCHOR . ')).',
        ];
        foreach ($pointers as $phrase) {
            $this->assertMentions($phrase, $runners, 'runners.md');
        }
        $this->assertStringNotContainsString('Obtain from the Ansilume UI under Runner Groups.', $runners, 'Not everyone obtains a runner token any more.');
    }

    /**
     * README.md's security list says who manages runners, with the
     * permissions operators lose.
     */
    public function testReadmeSaysOnlyAdminsManageRunners(): void
    {
        [$create, $update] = $this->permissions();
        $this->assertMentions(
            "only admins register, move and delete runners, regenerate runner tokens and create or change runner groups (`{$create}`, `{$update}`), as a runner receives the decrypted credentials of every job of its group",
            $this->flat('README.md', '## Security'),
            'The security list of README.md'
        );
    }

    /**
     * The permissions the migration takes from the operator role.
     *
     * @return array{0: string, 1: string}
     */
    private function permissions(): array
    {
        $permissions = (new \ReflectionClassConstant(self::MIGRATION, 'PERMISSIONS'))->getValue();
        $this->assertIsArray($permissions);
        $this->assertCount(2, $permissions);

        return [(string)$permissions[0], (string)$permissions[1]];
    }

    /**
     * The text of the constant $name of the migration, the sprintf pattern
     * of a line it prints.
     */
    private function migrationText(string $name): string
    {
        $text = (new \ReflectionClassConstant(self::MIGRATION, $name))->getValue();
        $this->assertIsString($text);

        return $text;
    }

    /**
     * The lines of the code block of the section that shows what the
     * migration prints: the only block whose fence names no language.
     *
     * @return list<string>
     */
    private function outputBlock(): array
    {
        $blocks = [];
        $block = null;
        foreach (preg_split('/\R/', $this->section('docs/updating.md', self::UPDATE_SECTION)) ?: [] as $line) {
            if ($block === null && str_starts_with($line, '```')) {
                $block = ['language' => substr($line, 3), 'lines' => []];
            } elseif ($block !== null && $line === '```') {
                $blocks[] = $block;
                $block = null;
            } elseif ($block !== null) {
                $block['lines'][] = trim($line);
            }
        }
        $plain = array_values(array_filter($blocks, static fn (array $candidate): bool => $candidate['language'] === ''));
        $this->assertCount(1, $plain, 'The section shows the output of the migration in one block.');

        return array_values(array_filter($plain[0]['lines'], static fn (string $line): bool => $line !== ''));
    }

    /**
     * The titles of the section's items, in order.
     *
     * @return list<string>
     */
    private function itemTitles(): array
    {
        $titles = [];
        foreach ($this->units('docs/updating.md', self::UPDATE_SECTION) as $unit) {
            if (preg_match('/^\*\*([^*]+)\.\*\*/', $unit, $match) === 1) {
                $titles[] = $match[1];
            }
        }

        return $titles;
    }

    /**
     * The item of the section under the bold title $title: its paragraph
     * and everything after it up to the next bold title, on one line.
     */
    private function item(string $title): string
    {
        $item = [];
        foreach ($this->units('docs/updating.md', self::UPDATE_SECTION) as $unit) {
            if ($item === [] && !str_starts_with($unit, '**' . $title . '.**')) {
                continue;
            }
            if ($item !== [] && preg_match('/^\*\*[^*]+\.\*\*/', $unit) === 1) {
                break;
            }
            $item[] = $unit;
        }
        $this->assertNotEmpty($item, "The section has no item {$title}.");

        return implode(' ', $item);
    }

    /**
     * Step $number of the section's checklist, on one line.
     */
    private function checklist(int $number): string
    {
        foreach ($this->units('docs/updating.md', self::UPDATE_SECTION) as $unit) {
            if (str_starts_with($unit, $number . '. ')) {
                return $unit;
            }
        }
        $this->fail("The checklist of the section has no step {$number}.");
    }

    /**
     * The permission each action of the API controller $class requires.
     *
     * @param class-string<RunnerGroupsController|RunnersController> $class
     * @return array<string, string>
     */
    private function apiRules(string $class): array
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Module::class, $app);
        $controller = new $class('api', $app);
        $rules = (new \ReflectionMethod($controller, 'apiAccessRules'))->invoke($controller);
        $this->assertIsArray($rules);

        return array_map('strval', $rules);
    }

    /**
     * The permission each action of the web controller $class requires, by
     * action, from its access rules.
     *
     * @param class-string<RunnerController|RunnerGroupController> $class
     * @return array<string, string>
     */
    private function webRules(string $class): array
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Module::class, $app);
        $controller = new $class('web', $app);
        $rules = (new \ReflectionMethod($controller, 'accessRules'))->invoke($controller);
        $this->assertIsArray($rules);
        $byAction = [];
        foreach ($rules as $rule) {
            $this->assertIsArray($rule);
            $roles = (array)($rule['roles'] ?? []);
            $this->assertCount(1, $roles, 'Each rule names one permission.');
            foreach ((array)($rule['actions'] ?? []) as $action) {
                $byAction[(string)$action] = (string)$roles[0];
            }
        }

        return $byAction;
    }
}
