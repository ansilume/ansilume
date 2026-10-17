<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eTeardownHelper;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use yii\db\IntegrityException;
use yii\rbac\DbManager;

/**
 * E2eTeardownHelper reports what it could not remove, with the first line of
 * what ErrorText says about the error: a database error only as its class,
 * SQLSTATE and driver error code. tests/integration/commands/
 * E2eTeardownHelperTest pins the foreign key refusals (1451) of real
 * deletes.
 *
 * Regression: a database error's report quoted the first line of its
 * message, where the driver quotes the value.
 */
class TeardownHelperDatabaseErrorTest extends DbTestCase
{
    use RestoresComponents;

    private const MARKER = 'teardown-marker-4402';

    protected function setUp(): void
    {
        parent::setUp();
        // Tests replace it; tearDown() puts back only what setUp() kept.
        $this->keepComponent('authManager');
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    public function testRegressionADatabaseErrorWasReportedWithTheValueItsMessageQuotes(): void
    {
        // The custom roles are read through the RBAC manager, the database
        // error is raised there.
        \Yii::$app->set('authManager', new class () extends DbManager {
            public function getRoles()
            {
                throw new IntegrityException(
                    "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'teardown-marker-4402' for key 'name'"
                        . "\nThe SQL being executed was: SELECT * FROM `auth_item` WHERE `name`='teardown-marker-4402'",
                    ['23000', 1062, "Duplicate entry 'teardown-marker-4402' for key 'name'"]
                );
            }
        });
        // A prefix nothing in the database starts with: only the roles fail.
        $helper = new E2eTeardownHelper('teardown-db-error-' . bin2hex(random_bytes(4)) . '-', static function (string $msg): void {
        });

        $problems = $helper->teardownDatabase();

        $this->assertSame(['Failed removing the custom roles: yii\db\IntegrityException (SQLSTATE 23000, driver error 1062)'], $problems);
        $this->assertStringNotContainsString(self::MARKER, implode("\n", $problems));
    }

    /**
     * Any other error is reported with the first line of its message, as
     * before.
     */
    public function testAnyOtherErrorIsReportedWithTheFirstLineOfItsMessage(): void
    {
        \Yii::$app->set('authManager', new class () extends DbManager {
            public function getRoles()
            {
                throw new \RuntimeException("The RBAC cache is corrupt.\nIt has 3 entries.");
            }
        });
        $helper = new E2eTeardownHelper('teardown-db-error-' . bin2hex(random_bytes(4)) . '-', static function (string $msg): void {
        });

        $this->assertSame(['Failed removing the custom roles: The RBAC cache is corrupt.'], $helper->teardownDatabase());
    }
}
