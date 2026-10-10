<?php

declare(strict_types=1);

namespace app\tests\integration;

/**
 * Counts the SQL statements an action sends to the database, with the
 * session's Questions counter of MariaDB / MySQL. Use in DbTestCase
 * subclasses to show that a cost does not grow with the data.
 *
 * Run the action once before measuring: the first use of a model in a
 * process also loads its table schema, which adds statements.
 */
trait CountsQueries
{
    protected function queriesOf(callable $action): int
    {
        $before = $this->questions();
        $action();

        // The closing SHOW STATUS counts itself.
        return $this->questions() - $before - 1;
    }

    private function questions(): int
    {
        $row = \Yii::$app->db->createCommand("SHOW SESSION STATUS LIKE 'Questions'")->queryOne();
        $this->assertIsArray($row);

        return (int)$row['Value'];
    }
}
