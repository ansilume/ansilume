<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Migrates legacy notify_on_failure / notify_on_success / notify_emails
 * from job_template into notification_template records + pivot links.
 *
 * For each job template that has at least one notify flag and emails configured,
 * creates an Email notification_template and links it via the pivot table.
 */
class m000046_000000_migrate_legacy_notifications extends Migration
{
    public function safeUp(): void
    {
        $rows = (new \yii\db\Query())
            ->select(['id', 'name', 'notify_on_failure', 'notify_on_success', 'notify_emails', 'created_by'])
            ->from('{{%job_template}}')
            ->where(['or',
                ['notify_on_failure' => 1],
                ['notify_on_success' => 1],
            ])
            ->andWhere(['not', ['notify_emails' => null]])
            ->andWhere(['not', ['notify_emails' => '']])
            ->all();

        $now = time();

        foreach ($rows as $row) {
            $emails = json_decode((string)$row['notify_emails'], true);
            if (!is_array($emails) || empty($emails)) {
                continue;
            }

            $events = [];
            if ($row['notify_on_failure']) {
                $events[] = 'job.failed';
            }
            if ($row['notify_on_success']) {
                $events[] = 'job.succeeded';
            }
            if (empty($events)) {
                continue;
            }

            $this->insert('{{%notification_template}}', [
                'name' => 'Email — ' . $row['name'],
                'description' => 'Auto-migrated from legacy notification settings.',
                'channel' => 'email',
                'config' => json_encode(['emails' => $emails]),
                'subject_template' => '[Ansilume] Job #{{ job.id }} {{ job.status }} — {{ template.name }}',
                'body_template' => "Job #{{ job.id }} finished with status {{ job.status }}.\n\nTemplate: {{ template.name }}\nProject: {{ project.name }}\nLaunched by: {{ launched_by }}\n\nView: {{ job.url }}",
                'events' => implode(',', $events),
                'created_by' => $row['created_by'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $ntId = (int)$this->db->getLastInsertID();

            $this->insert('{{%job_template_notification}}', [
                'job_template_id' => $row['id'],
                'notification_template_id' => $ntId,
            ]);
        }
    }

    public function safeDown(): void
    {
        // Remove all auto-migrated notification templates and their pivot links.
        // The pivot FK cascades, so deleting the template removes the link.
        $this->delete('{{%notification_template}}', [
            'description' => 'Auto-migrated from legacy notification settings.',
        ]);
    }
}
