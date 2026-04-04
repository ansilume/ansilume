<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the job_template_notification pivot table.
 *
 * Links job templates to notification templates (many-to-many).
 */
class m000045_000000_create_job_template_notification extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%job_template_notification}}', [
            'id' => $this->primaryKey()->unsigned(),
            'job_template_id' => $this->integer()->unsigned()->notNull(),
            'notification_template_id' => $this->integer()->unsigned()->notNull(),
        ]);

        $this->createIndex(
            'idx-jtn-unique',
            '{{%job_template_notification}}',
            ['job_template_id', 'notification_template_id'],
            true
        );

        $this->addForeignKey(
            'fk-jtn-job_template_id',
            '{{%job_template_notification}}',
            'job_template_id',
            '{{%job_template}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            'fk-jtn-notification_template_id',
            '{{%job_template_notification}}',
            'notification_template_id',
            '{{%notification_template}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%job_template_notification}}');
    }
}
