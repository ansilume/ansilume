<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Seed two more demo templates that map to the newest playbooks in the
 * ansible-demo repository:
 *
 *   - debug_vars.yaml   — dumps every variable available to the play, used
 *                         to verify extra-var passthrough end-to-end.
 *   - timeout_test.yaml — sleeps for 12h (configurable), used to exercise
 *                         Ansilume's timeout and reclaim behaviour.
 *
 * Idempotent: skips templates that already exist by name. Requires the
 * Demo project + Demo Localhost inventory seeded by m000035; follows the
 * same pattern as m000058.
 */
class m000070_000000_seed_debug_and_timeout_demo_templates extends Migration
{
    private const PROJECT_NAME = 'Demo';
    private const INVENTORY_NAME = 'Demo — Localhost';

    public function safeUp(): void
    {
        $projectId = (int)$this->db->createCommand(
            'SELECT id FROM {{%project}} WHERE name = :name',
            [':name' => self::PROJECT_NAME]
        )->queryScalar();

        if ($projectId === 0) {
            echo "    > Demo project not found — skipping debug_vars/timeout demo templates.\n";
            return;
        }

        $inventoryId = (int)$this->db->createCommand(
            'SELECT id FROM {{%inventory}} WHERE name = :name ORDER BY id ASC LIMIT 1',
            [':name' => self::INVENTORY_NAME]
        )->queryScalar();
        $inventoryId = $inventoryId > 0 ? $inventoryId : null;

        $ownerId = (int)$this->db->createCommand(
            'SELECT id FROM {{%user}} ORDER BY id ASC LIMIT 1'
        )->queryScalar();

        if ($ownerId === 0) {
            echo "    > No users found — skipping debug_vars/timeout demo templates.\n";
            return;
        }

        $runnerGroupId = $this->db->createCommand(
            "SELECT id FROM {{%runner_group}} WHERE name = 'default' LIMIT 1"
        )->queryScalar();
        $runnerGroupId = $runnerGroupId !== false ? (int)$runnerGroupId : null;

        $now = time();

        $templates = [
            [
                'name' => 'Demo — Debug all variables',
                'description' => 'Dump every variable available to the play to verify extra-var passthrough. '
                    . 'No changes on the target host. Launch with Extra Vars to confirm they reach the playbook.',
                'playbook' => 'playbooks/debug_vars.yaml',
                'timeout_minutes' => 5,
            ],
            [
                'name' => 'Demo — Timeout test (sleeps 12h)',
                'description' => 'Deliberately long-running playbook that sleeps for 12 hours. Used to exercise '
                    . "Ansilume's timeout handling and the runner reclaim path. Override the sleep via Extra Vars: "
                    . 'timeout_test_duration (seconds).',
                'playbook' => 'playbooks/timeout_test.yaml',
                'timeout_minutes' => 1,
            ],
        ];

        $inserted = 0;
        foreach ($templates as $tpl) {
            $exists = (int)$this->db->createCommand(
                'SELECT COUNT(*) FROM {{%job_template}} WHERE name = :name',
                [':name' => $tpl['name']]
            )->queryScalar();
            if ($exists > 0) {
                continue;
            }
            $this->insert('{{%job_template}}', [
                'name' => $tpl['name'],
                'description' => $tpl['description'],
                'project_id' => $projectId,
                'inventory_id' => $inventoryId,
                'credential_id' => null,
                'runner_group_id' => $runnerGroupId,
                'playbook' => $tpl['playbook'],
                'extra_vars' => null,
                'survey_fields' => null,
                'verbosity' => 0,
                'forks' => 5,
                'become' => 1,
                'become_method' => 'sudo',
                'become_user' => 'root',
                'limit' => null,
                'tags' => null,
                'skip_tags' => null,
                'timeout_minutes' => $tpl['timeout_minutes'],
                'created_by' => $ownerId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $inserted++;
        }

        echo "    > Seeded {$inserted} additional demo templates (debug_vars + timeout_test).\n";
    }

    public function safeDown(): void
    {
        $names = [
            'Demo — Debug all variables',
            'Demo — Timeout test (sleeps 12h)',
        ];
        $this->db->createCommand()
            ->delete('{{%job_template}}', ['name' => $names])
            ->execute();
    }
}
