<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds survey fields and notification config to job_template.
 *
 * survey_fields: JSON array of survey field definitions:
 *   [{"name":"version","label":"Version","type":"text","required":true,"default":""}]
 *
 * notify_on_failure: send an email when the job fails
 * notify_emails:     JSON array of email addresses to notify
 */
class m000011_add_survey_and_notify_to_job_template extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%job_template}}', 'survey_fields',    $this->text()->null()->after('skip_tags'));
        $this->addColumn('{{%job_template}}', 'notify_on_failure',$this->boolean()->notNull()->defaultValue(false)->after('survey_fields'));
        $this->addColumn('{{%job_template}}', 'notify_emails',    $this->text()->null()->after('notify_on_failure'));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%job_template}}', 'survey_fields');
        $this->dropColumn('{{%job_template}}', 'notify_on_failure');
        $this->dropColumn('{{%job_template}}', 'notify_emails');
    }
}
