<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds job.attempt_count + job.max_attempts to support automatic re-queueing
 * of jobs whose runner died mid-execution.
 *
 * Behavior: when JobReclaimService runs in mode=requeue and a stale job has
 * attempt_count < max_attempts, the job is moved back to STATUS_QUEUED with
 * runner_id and timing fields cleared, instead of being marked FAILED. Once
 * the limit is reached the next reclaim falls back to FAILED.
 *
 * Both columns default to 1 so the existing fail-only behavior is preserved
 * for historical rows: with attempt_count=1 and max_attempts=1 the requeue
 * branch never fires regardless of the configured mode.
 */
class m000066_000000_add_job_attempt_tracking extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%job}}',
            'attempt_count',
            $this->integer()->unsigned()->notNull()->defaultValue(1)->after('exit_code')
        );
        $this->addColumn(
            '{{%job}}',
            'max_attempts',
            $this->integer()->unsigned()->notNull()->defaultValue(1)->after('attempt_count')
        );
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%job}}', 'max_attempts');
        $this->dropColumn('{{%job}}', 'attempt_count');
    }
}
