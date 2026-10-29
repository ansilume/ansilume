<?php

declare(strict_types=1);

namespace app\tests\integration;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;
use yii\rbac\DbManager;

/**
 * A DbTestCase runs only while the authManager writes through the
 * application's connection, the one that holds the test's transaction.
 *
 * Regression: after WorkerJobDbConnectionTest had put back db as its
 * definition, Yii made a new connection for every later test, while the
 * authManager kept the old one: the roles and assignments those tests
 * wrote stayed in the test database, and 616 tests failed on them, far
 * from the cause. setUp() now fails at once and names it.
 */
final class DbTestCaseConnectionTest extends TestCase
{
    public function testASetUpFailsWhileTheAuthManagerWritesThroughAnotherConnection(): void
    {
        $auth = \Yii::$app->getAuthManager();
        $this->assertInstanceOf(DbManager::class, $auth);
        $db = \Yii::$app->getDb();
        $auth->db = new Connection(['dsn' => $db->dsn, 'username' => $db->username, 'password' => $db->password]);
        $case = self::dbTestCase();
        $failure = null;
        try {
            (new \ReflectionMethod($case, 'setUp'))->invoke($case);
        } catch (AssertionFailedError $e) {
            $failure = $e;
        } finally {
            $auth->db = $db;
            $begun = $db->getTransaction();
            $begun?->rollBack();
        }

        $this->assertNotNull($failure, 'setUp() ran with the authManager on another connection');
        $this->assertStringContainsString(DbTestCase::AUTH_MANAGER_ON_ANOTHER_CONNECTION, $failure->getMessage());
        $this->assertNull($begun, 'setUp() began no transaction');
    }

    public function testASetUpBeginsItsTransactionOnTheConnectionOfTheAuthManager(): void
    {
        $auth = \Yii::$app->getAuthManager();
        $this->assertInstanceOf(DbManager::class, $auth);
        $case = self::dbTestCase();
        (new \ReflectionMethod($case, 'setUp'))->invoke($case);
        try {
            $this->assertNotNull(\Yii::$app->getDb()->getTransaction(), 'setUp() began a transaction');
            $this->assertSame(\Yii::$app->getDb(), $auth->db);
        } finally {
            (new \ReflectionMethod($case, 'tearDown'))->invoke($case);
        }

        $this->assertNull(\Yii::$app->getDb()->getTransaction(), 'tearDown() rolled it back');
    }

    private static function dbTestCase(): DbTestCase
    {
        return new class ('testNothing') extends DbTestCase {
            public function testNothing(): void
            {
            }
        };
    }
}
