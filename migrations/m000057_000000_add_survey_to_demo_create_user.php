<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Backfills the "Demo — Create user" template with a launch survey so operators
 * are forced to provide a username. Without it the playbook fails with
 * "Variable 'system_user_name' must be set to a non-empty username."
 *
 * Idempotent: only updates the template if it exists and has no survey yet, so
 * operators who already customized the survey are left alone.
 */
class m000057_000000_add_survey_to_demo_create_user extends Migration
{
    public function safeUp(): void
    {
        // Inlined (not pulled from m000035) because migrations are loaded on
        // demand and the other class is not on the autoloader at runtime.
        $survey = (string)json_encode([
            [
                'name' => 'system_user_name',
                'label' => 'Username',
                'type' => 'text',
                'required' => true,
                'default' => '',
                'hint' => 'Login name for the new system user (e.g. "deploy").',
            ],
            [
                'name' => 'system_user_sudo',
                'label' => 'Grant sudo',
                'type' => 'boolean',
                'required' => false,
                'default' => 'false',
                'hint' => 'Add the user to the sudoers group with NOPASSWD.',
            ],
            [
                'name' => 'system_user_pubkey',
                'label' => 'SSH public key',
                'type' => 'textarea',
                'required' => false,
                'default' => '',
                'hint' => 'Optional. If set, installed into ~/.ssh/authorized_keys.',
            ],
        ]);

        $this->db->createCommand(
            "UPDATE {{%job_template}}
             SET survey_fields = :survey, updated_at = :now
             WHERE name = 'Demo — Create user'
               AND (survey_fields IS NULL OR survey_fields = '' OR survey_fields = '[]')",
            [':survey' => $survey, ':now' => time()]
        )->execute();
    }

    public function safeDown(): void
    {
        // No-op: leaving the survey in place is harmless and non-destructive.
    }
}
