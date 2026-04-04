<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Drops the legacy per-template email notification columns from job_template.
 *
 * The legacy email-only notification system has been replaced by the
 * multi-channel NotificationTemplate + NotificationDispatcher pipeline.
 * Existing data was migrated to notification templates in
 * m000046_000000_migrate_legacy_notifications prior to this removal.
 */
class m000056_000000_drop_legacy_notification_columns extends Migration
{
    public function safeUp(): void
    {
        $this->dropColumn('{{%job_template}}', 'notify_on_failure');
        $this->dropColumn('{{%job_template}}', 'notify_on_success');
        $this->dropColumn('{{%job_template}}', 'notify_emails');
    }

    public function safeDown(): void
    {
        $this->addColumn(
            '{{%job_template}}',
            'notify_on_failure',
            $this->boolean()->notNull()->defaultValue(false)
        );
        $this->addColumn(
            '{{%job_template}}',
            'notify_on_success',
            $this->boolean()->notNull()->defaultValue(false)
        );
        $this->addColumn(
            '{{%job_template}}',
            'notify_emails',
            $this->text()->null()
        );
    }
}
