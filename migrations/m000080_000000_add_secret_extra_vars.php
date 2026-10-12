<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * job.secret_extra_vars and schedule.secret_extra_vars: the password answers
 * of a job or schedule (extra vars whose key is a password-type survey field
 * of the template), sealed by SurveySecrets. extra_vars keeps the placeholder
 * $encrypted$ in their place.
 *
 * MEDIUMTEXT: the sealed form (IV, padding, base64) is larger than the
 * answers, and job.extra_vars is a TEXT column already.
 *
 * DDL commits implicitly, so a transaction would not undo a half-applied
 * run. Each column is added only when it is missing instead: a run that
 * failed after the first column succeeds when it is retried, rather than
 * failing on the duplicate column at every container start.
 */
class m000080_000000_add_secret_extra_vars extends Migration
{
    /** The tables that get the column. */
    private const TABLES = ['{{%job}}', '{{%schedule}}'];
    private const COLUMN = 'secret_extra_vars';

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->hasColumn($table)) {
                $this->addColumn($table, self::COLUMN, 'MEDIUMTEXT NULL');
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
