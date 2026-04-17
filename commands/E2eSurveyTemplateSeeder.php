<?php

declare(strict_types=1);

namespace app\commands;

use app\models\JobTemplate;

/**
 * Seeds a second JobTemplate alongside the regular e2e-template, this one
 * equipped with a three-field survey so the launch form exercises all
 * supported survey field types (text, boolean, select) in a single flow.
 */
class E2eSurveyTemplateSeeder
{
    private const TEMPLATE_NAME = 'e2e-survey-template';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(
        int $userId,
        int $projectId,
        int $inventoryId,
        int $credentialId,
        int $runnerGroupId,
    ): int {
        $this->deleteExisting();

        $template = new JobTemplate();
        $template->name = self::TEMPLATE_NAME;
        $template->description = 'Survey-equipped template for e2e';
        $template->project_id = $projectId;
        $template->inventory_id = $inventoryId;
        $template->credential_id = $credentialId;
        $template->runner_group_id = $runnerGroupId;
        $template->playbook = 'selftest.yml';
        $template->verbosity = 0;
        $template->forks = 5;
        $template->become = false;
        $template->become_method = 'sudo';
        $template->become_user = 'root';
        $template->timeout_minutes = 120;
        $template->created_by = $userId;
        $template->survey_fields = (string)json_encode([
            [
                'name' => 'target_env',
                'label' => 'Target environment',
                'type' => 'text',
                'required' => true,
                'default' => 'staging',
                'options' => [],
                'hint' => '',
            ],
            [
                'name' => 'dry_run',
                'label' => 'Dry run',
                'type' => 'boolean',
                'required' => false,
                'default' => '0',
                'options' => [],
                'hint' => '',
            ],
            [
                'name' => 'log_level',
                'label' => 'Log level',
                'type' => 'select',
                'required' => true,
                'options' => ['debug', 'info', 'warn'],
                'default' => 'info',
                'hint' => '',
            ],
        ], JSON_UNESCAPED_UNICODE);
        $template->save(false);

        ($this->logger)("  Created survey template '" . self::TEMPLATE_NAME . "' (ID {$template->id}) with 3 survey fields.\n");

        return (int)$template->id;
    }

    private function deleteExisting(): void
    {
        // Hard-delete to avoid soft-deleted rows accumulating across seed runs
        // and to keep idempotency clean regardless of unique-key semantics.
        \Yii::$app->db->createCommand(
            'DELETE FROM {{%job_template}} WHERE name = :n',
            [':n' => self::TEMPLATE_NAME]
        )->execute();
    }
}
