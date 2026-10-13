<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\DbErrorSummary;
use app\components\ErrorText;
use app\tests\integration\DbTestCase;
use yii\db\Connection;
use yii\db\Exception as DbException;

/**
 * DbErrorSummary and ErrorText with database errors as PDO and Yii raise
 * them: a PDOException that escaped Yii's wrapping (PDO fills in its
 * errorInfo for a statement error and for a failed connection, but not for
 * an error PDO raises itself), and Yii's wrapping of a failed connection.
 *
 * Regression: a raw PDOException had no SQLSTATE in its summary: every one
 * read 'PDOException (SQLSTATE -, driver error -)', so a log line or a
 * message about a failed BEGIN or COMMIT did not say which error it was.
 */
class DbErrorSummaryPdoTest extends DbTestCase
{
    private const MARKER = 'pdo-summary-marker-3301';

    /** A local port nothing listens on: the connection is refused at once. */
    private const REFUSED_DSN = 'mysql:host=127.0.0.1;port=1;dbname=ansilume_test';

    public function testRegressionARawPdoExceptionHadNoSqlstateInItsSummary(): void
    {
        $error = $this->rawStatementError();

        $this->assertSame([\PDOException::class, '42S02', '1146'], DbErrorSummary::of($error));
        $this->assertSame('PDOException (SQLSTATE 42S02, driver error 1146)', ErrorText::of($error));
        $this->assertStringNotContainsString(self::MARKER, ErrorText::of($error));
        $this->assertNull(DbErrorSummary::connectionFailure($error), 'a statement error keeps no driver text');
    }

    /**
     * An error PDO raises itself, such as a BEGIN while a transaction is
     * active, has neither error info nor a code.
     */
    public function testAnErrorPdoRaisesItselfHasNoSqlstate(): void
    {
        $pdo = \Yii::$app->db->pdo;
        $this->assertInstanceOf(\PDO::class, $pdo);

        try {
            // DbTestCase runs every test in a transaction.
            $pdo->beginTransaction();
            $this->fail('expected PDO to refuse a second transaction');
        } catch (\PDOException $e) {
            $this->assertSame([\PDOException::class, DbErrorSummary::NONE, DbErrorSummary::NONE], DbErrorSummary::of($e));
            $this->assertSame('PDOException (SQLSTATE -, driver error -)', ErrorText::of($e));
        }
    }

    /**
     * A failed connection: PDO's own exception and Yii's wrapping of it
     * name the SQLSTATE and the driver's code, and keep the driver's text,
     * which says why the connection failed.
     */
    public function testAFailedConnectionKeepsTheDriversText(): void
    {
        try {
            new \PDO(self::REFUSED_DSN, 'nobody', 'none', [\PDO::ATTR_TIMEOUT => 2]);
            $this->fail('expected the connection to be refused');
        } catch (\PDOException $e) {
            $this->assertSame([\PDOException::class, 'HY000', '2002'], DbErrorSummary::of($e));
            $this->assertSame('Connection refused', DbErrorSummary::connectionFailure($e));
        }

        $connection = new Connection(['dsn' => self::REFUSED_DSN, 'username' => 'nobody', 'password' => 'none']);
        try {
            $connection->open();
            $this->fail('expected the connection to be refused');
        } catch (DbException $e) {
            $this->assertSame([DbException::class, 'HY000', '2002'], DbErrorSummary::of($e));
            $this->assertSame('Connection refused', DbErrorSummary::connectionFailure($e));
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    private function rawStatementError(): \PDOException
    {
        $pdo = \Yii::$app->db->pdo;
        $this->assertInstanceOf(\PDO::class, $pdo);
        try {
            $pdo->query("SELECT * FROM no_such_table_3301 WHERE name = '" . self::MARKER . "'");
        } catch (\PDOException $e) {
            return $e;
        }
        $this->fail('expected PDO to refuse the statement');
    }
}
