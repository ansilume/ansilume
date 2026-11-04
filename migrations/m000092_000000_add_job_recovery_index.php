<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds the index with which RecoveredFailures tells a failed job that a
 * later run of the same job template recovered from one that is still
 * open: job (job_template_id, status, check_mode, started_at). Per failure
 * it reads one index entry: the start of the template's latest success
 * that was no dry run, or the first such success after the failure's end.
 *
 * Measured on 300,000 jobs, a third of them of one job template, with the
 * indexes before it: the dashboard's Failed Jobs panel took 313
 * milliseconds, the count of the job list's filter "recovered" 328 and the
 * recovering jobs of a page of 25 failures 1.3 seconds, each sorting the
 * template's successes per failure; with it 2.6, 20 and 1.6 milliseconds.
 * idx_job_template_status (job_template_id, status) stays: it is a prefix
 * of this one, and other queries' plans use it.
 */
class m000092_000000_add_job_recovery_index extends Migration
{
    public const INDEX = 'idx_job_template_status_check_started';

    public function safeUp(): void
    {
        $this->createIndex(self::INDEX, '{{%job}}', ['job_template_id', 'status', 'check_mode', 'started_at']);
    }

    public function safeDown(): void
    {
        $this->dropIndex(self::INDEX, '{{%job}}');
    }
}
