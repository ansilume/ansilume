<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Vault files in project checkouts and how job templates fit them.
 *
 * - project.vault_password_source: whether runners use only Ansilume's vault
 *   password ('ansilume', neutralising the repository's ansible.cfg vault
 *   settings) or also the repository's ('repository', the behaviour before
 *   this release). New projects get 'ansilume'; existing projects keep
 *   'repository'.
 * - project.vault_scanned_at / vault_scan_commit / vault_scan_summary /
 *   vault_scan_error: the last scan of the checkout (summary is JSON).
 * - project_vault_entry: one row per encrypted file or inline value the scan
 *   found; never plaintext, never a password.
 * - job_template_vault_check: whether the template's vault password opens the
 *   encrypted files the template probably loads, checked without decrypting;
 *   unopened holds the first of those behind the status, unopened_count how
 *   many there are, incomplete_reason why a check is incomplete.
 * - runner.capabilities: features a runner reports in its requests, such as
 *   'vault_password_source'.
 */
class m000076_000000_add_vault_scan extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%project}}', 'vault_password_source', $this->string(16)->notNull()->defaultValue('ansilume'));
        $this->update('{{%project}}', ['vault_password_source' => 'repository']);
        $this->addColumn('{{%project}}', 'vault_scanned_at', $this->integer()->null()->defaultValue(null));
        $this->addColumn('{{%project}}', 'vault_scan_commit', $this->string(64)->null()->defaultValue(null));
        $this->addColumn('{{%project}}', 'vault_scan_summary', $this->text()->null()->defaultValue(null));
        $this->addColumn('{{%project}}', 'vault_scan_error', $this->text()->null()->defaultValue(null));

        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';

        $this->createTable('{{%project_vault_entry}}', [
            'id' => $this->primaryKey()->unsigned(),
            'project_id' => $this->integer()->unsigned()->notNull(),
            'path' => $this->string(1024)->notNull(),
            'kind' => $this->string(8)->notNull(),
            'line' => $this->integer()->null(),
            'var_key' => $this->string(255)->null(),
            'vault_id' => $this->string(255)->null(),
            'format_version' => $this->string(8)->null(),
            'fingerprint' => $this->char(64)->null(),
            'error' => $this->string(255)->null(),
        ], $tableOptions);
        $this->createIndex('idx_project_vault_entry_project', '{{%project_vault_entry}}', 'project_id');
        $this->addForeignKey(
            'fk_project_vault_entry_project',
            '{{%project_vault_entry}}',
            'project_id',
            '{{%project}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->createTable('{{%job_template_vault_check}}', [
            'job_template_id' => $this->integer()->unsigned()->notNull(),
            'status' => $this->string(32)->notNull(),
            'credential_id' => $this->integer()->unsigned()->null(),
            'relevant_count' => $this->integer()->notNull()->defaultValue(0),
            'unopened_count' => $this->integer()->notNull()->defaultValue(0),
            'unopened' => $this->text()->null(),
            'incomplete_reason' => $this->string(16)->null(),
            'checked_at' => $this->integer()->notNull(),
            'scanned_at' => $this->integer()->null(),
        ], $tableOptions);
        $this->addPrimaryKey('pk_job_template_vault_check', '{{%job_template_vault_check}}', 'job_template_id');
        $this->createIndex('idx_job_template_vault_check_status', '{{%job_template_vault_check}}', 'status');
        $this->addForeignKey(
            'fk_job_template_vault_check_template',
            '{{%job_template_vault_check}}',
            'job_template_id',
            '{{%job_template}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk_job_template_vault_check_credential',
            '{{%job_template_vault_check}}',
            'credential_id',
            '{{%credential}}',
            'id',
            'SET NULL',
            'CASCADE'
        );

        $this->addColumn('{{%runner}}', 'capabilities', $this->string(255)->null()->defaultValue(null));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%runner}}', 'capabilities');
        $this->dropTable('{{%job_template_vault_check}}');
        $this->dropTable('{{%project_vault_entry}}');
        $this->dropColumn('{{%project}}', 'vault_scan_error');
        $this->dropColumn('{{%project}}', 'vault_scan_summary');
        $this->dropColumn('{{%project}}', 'vault_scan_commit');
        $this->dropColumn('{{%project}}', 'vault_scanned_at');
        $this->dropColumn('{{%project}}', 'vault_password_source');
    }
}
