<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * job_template.lint_queued_at and project.lint_queued_at, INT UNSIGNED NULL:
 * when a lint run was requested whose result is not stored yet, in Unix
 * seconds as lint_at; null when nothing is pending.
 *
 * A save of a job template and Run Lint queue the lint run for the
 * queue-worker (LintService::queueForTemplate(), queueForProject()) instead
 * of running ansible-lint in the request, and set the column; the run
 * clears it in the UPDATE that stores its result, unless a newer request
 * set it again. The pages show "Lint queued" while it is set, and the API
 * lint.queued_at.
 *
 * up()/down(), not safeUp(): DDL commits implicitly, so a transaction would
 * not undo a half-applied run. Each column is added only when it is
 * missing, and removed only when it is there, so a run that failed after
 * the first table succeeds when it is retried, instead of failing on the
 * duplicate column at every container start.
 */
class m000094_000000_add_lint_queued_at extends Migration
{
    /** The tables that get the column, in the order up() adds it. */
    public const TABLES = ['{{%job_template}}', '{{%project}}'];

    public const COLUMN = 'lint_queued_at';

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->hasColumn($table)) {
                $this->addColumn(
                    $table,
                    self::COLUMN,
                    $this->integer()->unsigned()->null()->after('lint_exit_code')
                        ->comment('When a lint run was requested whose result is not stored yet; null: none pending')
                );
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            if ($this->hasColumn($table)) {
                $this->dropColumn($table, self::COLUMN);
            }
        }
    }

    private function hasColumn(string $table): bool
    {
        return $this->db->getTableSchema($table, true)?->getColumn(self::COLUMN) !== null;
    }
}
