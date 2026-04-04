<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the notification_template table.
 *
 * Reusable notification definitions (Email, Slack, Teams, Webhook) that can be
 * attached to job templates via the job_template_notification pivot.
 */
class m000044_000000_create_notification_template extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%notification_template}}', [
            'id' => $this->primaryKey()->unsigned(),
            'name' => $this->string(128)->notNull(),
            'description' => $this->text()->null(),
            'channel' => $this->string(32)->notNull()->comment('email, slack, teams, webhook'),
            'config' => $this->text()->null()->comment('JSON channel config'),
            'subject_template' => $this->string(512)->null(),
            'body_template' => $this->text()->null(),
            'events' => $this->string(512)->notNull()->comment('Comma-separated event names'),
            'created_by' => $this->integer()->unsigned()->notNull(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        $this->createIndex(
            'idx-notification_template-channel',
            '{{%notification_template}}',
            'channel'
        );

        $this->addForeignKey(
            'fk-notification_template-created_by',
            '{{%notification_template}}',
            'created_by',
            '{{%user}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%notification_template}}');
    }
}
