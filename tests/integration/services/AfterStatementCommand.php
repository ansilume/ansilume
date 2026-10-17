<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use yii\db\Command;

/**
 * A command that calls $afterStatement with each statement it sent (values
 * filled in), once the database has answered it and before its caller goes
 * on. A test gives it to a connection of its own:
 * `new Connection([..., 'commandMap' => ['mysql' => ['class' => AfterStatementCommand::class, 'afterStatement' => $hook]]])`,
 * to commit changes from another connection between two statements of the
 * code under test, or to record what that code sends.
 */
final class AfterStatementCommand extends Command
{
    /** @var (\Closure(string): void)|null */
    public ?\Closure $afterStatement = null;

    /**
     * @return int the rows the statement affected
     */
    public function execute()
    {
        $affected = parent::execute();
        $this->reportStatement();

        return $affected;
    }

    /**
     * @param string $method
     * @param int|null $fetchMode
     * @return mixed
     */
    protected function queryInternal($method, $fetchMode = null)
    {
        $result = parent::queryInternal($method, $fetchMode);
        $this->reportStatement();

        return $result;
    }

    private function reportStatement(): void
    {
        if ($this->afterStatement !== null) {
            ($this->afterStatement)($this->getRawSql());
        }
    }
}
