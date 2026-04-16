<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds job.last_progress_at — a per-job liveness timestamp distinct from
 * updated_at (which is bumped by any field write, including bookkeeping).
 *
 * The reclaim job uses this to detect runners that died mid-execution: a
 * job in STATUS_RUNNING whose last_progress_at is older than the configured
 * threshold AND whose runner is offline gets transitioned to STATUS_FAILED
 * with a clear "runner stopped responding" reason.
 *
 * Existing RUNNING rows are backfilled from started_at so they get a fair
 * grace period before the first reclaim sweep, instead of being immediately
 * marked stale on first run after migration.
 */
class m000065_000000_add_job_last_progress_at extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn(
            '{{%job}}',
            'last_progress_at',
            $this->integer()->unsigned()->null()->after('finished_at')
        );

        // Backfill: for already-running jobs, treat started_at as the last
        // known signal so they aren't all reclaimed on the first sweep.
        $this->execute(
            'UPDATE {{%job}} SET last_progress_at = started_at WHERE last_progress_at IS NULL AND started_at IS NOT NULL'
        );

        $this->createIndex('idx_job_last_progress_at', '{{%job}}', 'last_progress_at');
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_job_last_progress_at', '{{%job}}');
        $this->dropColumn('{{%job}}', 'last_progress_at');
    }
}
