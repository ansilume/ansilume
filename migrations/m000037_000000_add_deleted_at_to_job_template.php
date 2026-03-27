<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds a deleted_at column to job_template for soft-delete support.
 *
 * When a template is deleted it is flagged rather than removed,
 * preserving referential integrity with the job table and keeping
 * the audit trail intact.
 */
class m000037_000000_add_deleted_at_to_job_template extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%job_template}}', 'deleted_at', $this->integer()->null()->after('updated_at'));
        $this->createIndex('idx_job_template_deleted_at', '{{%job_template}}', 'deleted_at');
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_job_template_deleted_at', '{{%job_template}}');
        $this->dropColumn('{{%job_template}}', 'deleted_at');
    }
}
