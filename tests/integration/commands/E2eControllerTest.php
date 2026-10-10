<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eController;
use app\commands\E2eTeardownHelper;
use app\models\User;
use app\tests\integration\DbTestCase;
use yii\console\ExitCode;

/**
 * `yii e2e/teardown` exits non-zero and prints every problem when the
 * teardown leaves anything behind, so bin/tests-e2e.sh fails the run instead
 * of the next seed building on leftovers.
 *
 * The exit code tests replace the teardown helper: the real one deletes the
 * e2e fixtures of the database and the vault checkouts on disk.
 */
class E2eControllerTest extends DbTestCase
{
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

    /**
     * An e2e controller whose output is captured and whose teardown runs
     * $helper. Without one, actionTeardown() refuses to run.
     *
     * @return E2eController&object{out: string, err: string}
     */
    private function controller(?E2eTeardownHelper $helper): E2eController
    {
        $controller = new class ('e2e', \Yii::$app) extends E2eController {
            public string $out = '';
            public string $err = '';
            public ?E2eTeardownHelper $helper = null;

            public function ownTeardownHelper(): E2eTeardownHelper
            {
                return parent::createTeardownHelper();
            }

            public function stdout($string): int
            {
                $this->out .= $string;
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

        return $controller;
    }
}
