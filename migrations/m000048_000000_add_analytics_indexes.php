<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds composite indexes to improve analytics query performance.
 *
 * These cover the most common filtering and aggregation patterns used
 * by AnalyticsService: status+time ranges, per-template stats, per-user
 * activity, and per-host health lookups.
 */
class m000048_000000_add_analytics_indexes extends Migration
{
    public function safeUp(): void
    {
        $this->createIndex(
            'idx_job_finished_status',
            '{{%job}}',
            ['finished_at', 'status']
        );

        $this->createIndex(
            'idx_job_template_status',
            '{{%job}}',
            ['job_template_id', 'status']
        );

        $this->createIndex(
            'idx_job_launched_created',
            '{{%job}}',
            ['launched_by', 'created_at']
        );

        $this->createIndex(
            'idx_job_host_summary_host',
            '{{%job_host_summary}}',
            'host'
        );
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_job_host_summary_host', '{{%job_host_summary}}');
        $this->dropIndex('idx_job_launched_created', '{{%job}}');
        $this->dropIndex('idx_job_template_status', '{{%job}}');
        $this->dropIndex('idx_job_finished_status', '{{%job}}');
    }
}
