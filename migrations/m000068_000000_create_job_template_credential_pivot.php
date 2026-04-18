<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Pivot `job_template_credential` so a template can reference multiple
 * credentials at once (SSH key + token + vault + ...).
 *
 * The existing `job_template.credential_id` stays and acts as the
 * primary credential — every existing row is mirrored into the pivot so
 * there is no behavioural break on upgrade. Going forward, the launch
 * path reads the pivot and merges all linked credentials; the old
 * single FK is kept as a convenience for "primary SSH credential" in
 * the UI and the API contract.
 */
class m000068_000000_create_job_template_credential_pivot extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%job_template_credential}}', [
            'job_template_id' => $this->integer()->unsigned()->notNull(),
            'credential_id' => $this->integer()->unsigned()->notNull(),
            'sort_order' => $this->integer()->notNull()->defaultValue(0)->comment(
                'Lower sort_order wins when two credentials target the same Ansible slot (e.g. --user).'
            ),
            'PRIMARY KEY(job_template_id, credential_id)',
        ]);

        $this->createIndex(
            'idx_job_template_credential_credential',
            '{{%job_template_credential}}',
            'credential_id'
        );

        $this->addForeignKey(
            'fk_job_template_credential_template',
            '{{%job_template_credential}}',
            'job_template_id',
            '{{%job_template}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            'fk_job_template_credential_credential',
            '{{%job_template_credential}}',
            'credential_id',
            '{{%credential}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        // Back-fill: mirror every existing primary credential into the pivot
        // so the new multi-credential code path behaves identically on day one.
        $this->execute(
            'INSERT INTO {{%job_template_credential}} (job_template_id, credential_id, sort_order) ' .
            'SELECT id, credential_id, 0 FROM {{%job_template}} WHERE credential_id IS NOT NULL'
        );
    }

    public function safeDown(): void
    {
        $this->dropForeignKey('fk_job_template_credential_template', '{{%job_template_credential}}');
        $this->dropForeignKey('fk_job_template_credential_credential', '{{%job_template_credential}}');
        $this->dropTable('{{%job_template_credential}}');
    }
}
