<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use yii\db\Command;

/**
 * Test double for the schedule tests: a database command whose statement
 * fails when its SQL matches $failingPattern, as PDO reports a lost
 * connection: the message of the yii\db\Exception quotes the statement with
 * its values (as Yii's own does), the PDOException's the driver's message.
 * Install it as the connection's command class (Connection::$commandMap) for
 * the part of a test that should fail.
 */
class ScheduleFailingCommand extends Command
{
    /** The statements whose SQL matches this pattern fail; '' for none. */
    public string $failingPattern = '';

    /** Fail with a PDOException that escaped Yii instead of a yii\db\Exception. */
    public bool $asPdoException = false;

    public function execute()
    {
        $this->failIfMatching();

        return parent::execute();
    }

    protected function queryInternal($method, $fetchMode = null)
    {
        $this->failIfMatching();

        return parent::queryInternal($method, $fetchMode);
    }

    private function failIfMatching(): void
    {
        $sql = $this->getRawSql();
        if ($this->failingPattern === '' || preg_match($this->failingPattern, $sql) !== 1) {
            return;
        }
        if ($this->asPdoException) {
            throw new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$sql}' for key 'PRIMARY'");
        }

        throw new \yii\db\Exception(
            "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\nThe SQL being executed was: {$sql}",
            ['HY000', 2006, 'MySQL server has gone away']
        );
    }
}
