<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eCancelOnlyUserSeeder;
use app\commands\E2eController;
use app\commands\E2eExtraVarsEditorSeeder;
use app\commands\E2eSurveyEditorSeeder;
use app\commands\E2eSurveySecretSeeder;
use app\commands\E2eTeardownHelper;
use app\commands\E2eUnusablePasswordNameSeeder;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\tests\integration\DbTestCase;
use yii\console\ExitCode;

/**
 * `yii e2e/teardown` exits non-zero and prints every problem when the
 * teardown leaves anything behind, so bin/tests-e2e.sh fails the run instead
 * of the next seed building on leftovers.
 *
 * Every e2e command refuses to run unless YII_ENV is dev or test.
 *
 * The exit code tests replace the teardown helper: the real one deletes the
 * e2e fixtures of the database and the vault checkouts on disk.
 *
 * `yii e2e/seed` runs here only up to the fixtures that live in the
 * database alone: the seeders after them write fixture projects into /tmp
 * and the runtime directory, outside the test's transaction.
 */
class E2eControllerTest extends DbTestCase
{
    /**
     * The lines e2e/seed prints before the cancel-only user's line: the
     * users, then the survey fixtures.
     */
    private const SURVEY_SEED_LINES = [
        "Seeding E2E test data...\n",
        "  Created user '",
        "  User '",
        "  Reset user '",
        '  Restored unusable password name fixtures: ',
        '  Restored survey secret fixtures: ',
        '  Restored survey editor fixtures ',
        '  Restored Extra Vars editor fixtures ',
    ];

    /** Where the test stops the seed: the cancel-only user's line, before any fixture is written to disk. */
    private const STOP_LINE = "  Restored user '" . E2eCancelOnlyUserSeeder::USERNAME . "' ";

    private const SEED_STOPPED = 'The test stopped the seed at the cancel-only user.';

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedEnvironmentProvider(): array
    {
        return [
            'prod' => ['prod'],
            'empty' => [''],
            'staging' => ['staging'],
            'other case' => ['Dev'],
            'padded' => [' test'],
        ];
    }

    /**
     * Regression: e2e/seed ran on every installation. It creates e2e-admin,
     * a superadmin whose password is in the public repository, so one seed on
     * a production install handed out a superadmin login.
     *
     * The controller fails the test as soon as the seed starts writing.
     *
     * @dataProvider refusedEnvironmentProvider
     */
    public function testSeedRefusesToRunOutsideDevAndTest(string $environment): void
    {
        $controller = $this->controller(null, $environment);
        $controller->failWhenWriting = true;
        $users = User::find()->count();

        $this->assertSame(ExitCode::CONFIG, $controller->runAction('seed'));

        $this->assertSame('', $controller->out);
        $this->assertRefusal($controller->err, $environment);
        $this->assertSame($users, User::find()->count());
    }

    /**
     * Regression: e2e/teardown ran on every installation and deleted every
     * record whose name starts with "e2e-", and the users named so with
     * everything they created. Without a teardown helper the controller
     * would fail the test if the teardown started.
     *
     * @dataProvider refusedEnvironmentProvider
     */
    public function testTeardownRefusesToRunOutsideDevAndTest(string $environment): void
    {
        $controller = $this->controller(null, $environment);

        $this->assertSame(ExitCode::CONFIG, $controller->runAction('teardown'));

        $this->assertSame('', $controller->out);
        $this->assertRefusal($controller->err, $environment);
    }

    /**
     * bin/tests-e2e.sh counts a seed as successful when its output contains
     * "complete" or "already exists", and ignores the exit code. The refusal
     * must say neither.
     */
    public function testTheRefusalCannotPassForASuccessfulSeed(): void
    {
        $controller = $this->controller(null, 'prod');
        $controller->failWhenWriting = true;

        $this->assertNotSame(ExitCode::OK, $controller->runAction('seed'));

        $output = strtolower($controller->out . $controller->err);
        $this->assertStringNotContainsString('complete', $output);
        $this->assertStringNotContainsString('already exists', $output);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function allowedEnvironmentProvider(): array
    {
        return [
            'dev (bin/tests-e2e.sh and CI)' => ['dev'],
            'test' => ['test'],
            'YII_ENV of this process' => [null],
        ];
    }

    /**
     * @dataProvider allowedEnvironmentProvider
     */
    public function testTheCommandsRunInDevAndTest(?string $environment): void
    {
        $controller = $this->controller($this->helperReporting([]), $environment);

        $this->assertSame(ExitCode::OK, $controller->runAction('teardown'));

        $this->assertSame("Tearing down E2E test data...\nE2E teardown complete.\n", $controller->out);
        $this->assertSame('', $controller->err);
    }
    public function testTeardownFailsAndPrintsEveryProblemWhenSomethingIsLeft(): void
    {
        $controller = $this->controller($this->helperReporting([
            "User 'e2e-operator' was not deleted: SQLSTATE[23000]: Integrity constraint violation: 1451",
            "1 user row(s) named like 'e2e-' are left.",
        ]));

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $controller->actionTeardown());

        $this->assertSame("Tearing down E2E test data...\n", $controller->out);
        $this->assertSame(
            "  User 'e2e-operator' was not deleted: SQLSTATE[23000]: Integrity constraint violation: 1451\n"
            . "  1 user row(s) named like 'e2e-' are left.\n"
            . "E2E teardown incomplete: 2 problem(s), see above.\n",
            $controller->err
        );
    }

    public function testTeardownSucceedsWhenNothingIsLeft(): void
    {
        $controller = $this->controller($this->helperReporting([]));

        $this->assertSame(ExitCode::OK, $controller->actionTeardown());

        $this->assertSame("Tearing down E2E test data...\nE2E teardown complete.\n", $controller->out);
        $this->assertSame('', $controller->err);
    }

    /**
     * The controller's own helper removes users named "e2e-…" and reports on
     * stdout. Only its database part runs, inside the test's transaction.
     */
    public function testTheTeardownHelperRemovesE2eUsersAndReportsOnStdout(): void
    {
        $user = $this->createUser();
        $user->username = 'e2e-' . bin2hex(random_bytes(4)) . '-ctl';
        $user->save(false);
        $controller = $this->controller(null);

        $this->assertSame([], $controller->ownTeardownHelper()->teardownDatabase());

        $this->assertNull(User::findOne($user->id));
        $this->assertStringContainsString("  Deleted user '{$user->username}'.\n", $controller->out);
    }

    /**
     * e2e/seed stores the survey fixtures first, before any fixture project:
     * the unusable password name template and schedule, the survey secret
     * template, then the survey editor and the Extra Vars editor templates
     * on its project, inventory and runner group, then the cancel-only user.
     * The seeder tests run the seeders themselves; this runs the seed's own
     * calls of them.
     *
     * The test stops the seed at the cancel-only user's line and fails at
     * any other line it does not expect before it, so the seed never gets
     * to the seeders that write into /tmp and the runtime directory, which a
     * Playwright run may be using at the same time.
     */
    public function testTheSeedStoresTheSurveyFixturesFirstOnTheSameParents(): void
    {
        $controller = $this->controller(null, null, static function (string $line): void {
            if (str_starts_with($line, self::STOP_LINE)) {
                throw new \RuntimeException(self::SEED_STOPPED);
            }
            foreach (self::SURVEY_SEED_LINES as $start) {
                if (str_starts_with($line, $start)) {
                    return;
                }
            }
            throw new \LogicException('The seed went past the survey fixtures: ' . $line);
        });

        try {
            $controller->runAction('seed');
            $this->fail('The seed ended without restoring the cancel-only user.');
        } catch (\RuntimeException $e) {
            $this->assertSame(self::SEED_STOPPED, $e->getMessage());
        }

        $parents = $this->parentsOf(E2eSurveySecretSeeder::TEMPLATE);
        $extraVars = [
            E2eSurveyEditorSeeder::REWRITTEN_VARS_TEMPLATE => E2eSurveyEditorSeeder::REWRITTEN_EXTRA_VARS,
            E2eExtraVarsEditorSeeder::BIGINT_TEMPLATE => E2eExtraVarsEditorSeeder::BIGINT_EXTRA_VARS,
            E2eExtraVarsEditorSeeder::BIGINT_SECRET_TEMPLATE => E2eExtraVarsEditorSeeder::BIGINT_SECRET_EXTRA_VARS,
            E2eExtraVarsEditorSeeder::REFUSAL_TEMPLATE => E2eExtraVarsEditorSeeder::REFUSAL_EXTRA_VARS,
        ];
        foreach ($extraVars as $name => $text) {
            $this->assertSame($parents, $this->parentsOf($name), "{$name} uses the survey secret template's parents");
            $this->assertSame($text, $this->templateNamed($name)->extra_vars, "{$name} keeps its Extra Vars as text");
        }
        $this->assertNotNull(User::findOne(['username' => E2eCancelOnlyUserSeeder::USERNAME]));
        $unusable = $this->templateNamed(E2eUnusablePasswordNameSeeder::TEMPLATE);
        $this->assertLessThan((int)$this->templateNamed(E2eSurveySecretSeeder::TEMPLATE)->id, (int)$unusable->id, 'seeded first, with the lowest ids');
        $this->assertNotSame($parents, $this->parentsOf(E2eUnusablePasswordNameSeeder::TEMPLATE), 'on parents of its own');
        $this->assertNotNull(Schedule::findOne(['name' => E2eUnusablePasswordNameSeeder::SCHEDULE, 'job_template_id' => $unusable->id]));
        foreach (array_slice(self::SURVEY_SEED_LINES, 4) as $line) {
            $this->assertSame(1, substr_count($controller->out, $line), "printed once: {$line}");
        }
    }

    /**
     * A teardown helper that deletes nothing and reports $problems.
     *
     * @param list<string> $problems
     */
    private function helperReporting(array $problems): E2eTeardownHelper
    {
        return new class ($problems) extends E2eTeardownHelper {
            /** @var list<string> */
            private array $reported;

            /**
             * @param list<string> $reported
             */
            public function __construct(array $reported)
            {
                parent::__construct('unused-', static function (string $msg): void {
                });
                $this->reported = $reported;
            }

            public function teardownAll(): array
            {
                return $this->reported;
            }
        };
    }

    private function assertRefusal(string $stderr, string $environment): void
    {
        $this->assertStringContainsString('Refused: the e2e commands run only with YII_ENV dev or test', $stderr);
        $this->assertStringContainsString('YII_ENV is "' . $environment . '" here', $stderr);
    }

    private function templateNamed(string $name): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(JobTemplate::class, $template, "{$name} is stored");

        return $template;
    }

    /**
     * @return array{0: int, 1: int, 2: int} the project, inventory and runner group ids of the template $name
     */
    private function parentsOf(string $name): array
    {
        $template = $this->templateNamed($name);

        return [(int)$template->project_id, (int)$template->inventory_id, (int)$template->runner_group_id];
    }

    /**
     * An e2e controller whose output is captured and whose teardown runs
     * $helper. Without one, actionTeardown() refuses to run. $environment
     * replaces YII_ENV for the guard (null: the YII_ENV of this process).
     * $onOutput gets every line written to stdout, once it is captured.
     *
     * @param (\Closure(string): void)|null $onOutput
     * @return E2eController&object{out: string, err: string, failWhenWriting: bool}
     */
    private function controller(
        ?E2eTeardownHelper $helper,
        ?string $environment = null,
        ?\Closure $onOutput = null
    ): E2eController {
        $controller = new class ('e2e', \Yii::$app) extends E2eController {
            public string $out = '';
            public string $err = '';
            public ?E2eTeardownHelper $helper = null;
            public ?string $environment = null;
            public bool $failWhenWriting = false;
            /** @var (\Closure(string): void)|null */
            public ?\Closure $onOutput = null;

            public function ownTeardownHelper(): E2eTeardownHelper
            {
                return parent::createTeardownHelper();
            }

            protected function yiiEnvironment(): string
            {
                return $this->environment ?? parent::yiiEnvironment();
            }

            public function stdout($string): int
            {
                if ($this->failWhenWriting) {
                    throw new \LogicException('The e2e command ran: ' . $string);
                }
                $this->out .= $string;
                if ($this->onOutput !== null) {
                    ($this->onOutput)($string);
                }
                return 0;
            }

            public function stderr($string): int
            {
                $this->err .= $string;
                return 0;
            }

            protected function createTeardownHelper(): E2eTeardownHelper
            {
                if ($this->helper === null) {
                    throw new \LogicException('No teardown helper set: the real one deletes the E2E fixtures.');
                }
                return $this->helper;
            }
        };
        $controller->helper = $helper;
        $controller->environment = $environment;
        $controller->onOutput = $onOutput;

        return $controller;
    }
}
