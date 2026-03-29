<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds job templates for playbooks added to the ansible-demo repository
 * after the initial seed (m000035): bashrc, common, hostsfile, maintenance, motd.
 */
class m000040_000000_seed_demo_templates_wave2 extends Migration
{
    private const PROJECT_NAME = 'Demo';

    public function safeUp(): void
    {
        $project = $this->db->createCommand(
            'SELECT id, created_by FROM {{%project}} WHERE name = :name LIMIT 1',
            [':name' => self::PROJECT_NAME]
        )->queryOne();

        if ($project === false) {
            echo "    > Demo project not found — skipping wave-2 templates.\n";
            return;
        }

        $projectId = (int) $project['id'];
        $ownerId = (int) $project['created_by'];

        $inventoryId = $this->db->createCommand(
            "SELECT id FROM {{%inventory}} WHERE name = 'Demo — Localhost' ORDER BY id ASC LIMIT 1"
        )->queryScalar();
        $inventoryId = $inventoryId !== false ? (int) $inventoryId : null;

        $runnerGroupId = $this->db->createCommand(
            "SELECT id FROM {{%runner_group}} WHERE name = 'default' LIMIT 1"
        )->queryScalar();
        $runnerGroupId = $runnerGroupId !== false ? (int) $runnerGroupId : null;

        $now = time();

        $templates = [
            [
                'name' => 'Demo — Deploy .bashrc',
                'description' => 'Deploy a managed .bashrc shell configuration to the target hosts.',
                'playbook' => 'playbooks/bashrc.yaml',
                'verbosity' => 0,
                'become' => 1,
            ],
            [
                'name' => 'Demo — Common baseline',
                'description' => 'Apply a common baseline configuration to the target hosts (packages, settings, hardening).',
                'playbook' => 'playbooks/common.yaml',
                'verbosity' => 0,
                'become' => 1,
            ],
            [
                'name' => 'Demo — Manage /etc/hosts',
                'description' => 'Manage /etc/hosts entries on the target hosts.',
                'playbook' => 'playbooks/hostsfile.yaml',
                'verbosity' => 0,
                'become' => 1,
            ],
            [
                'name' => 'Demo — System maintenance',
                'description' => 'Run system maintenance tasks: cleanup, log rotation, and general housekeeping.',
                'playbook' => 'playbooks/maintenance.yaml',
                'verbosity' => 0,
                'become' => 1,
            ],
            [
                'name' => 'Demo — Message of the day',
                'description' => 'Deploy a managed message of the day (motd) to the target hosts.',
                'playbook' => 'playbooks/motd.yaml',
                'verbosity' => 0,
                'become' => 1,
            ],
        ];

        $inserted = 0;
        foreach ($templates as $tpl) {
            // Idempotent: skip if a template with this name already exists for this project.
            $exists = (int) $this->db->createCommand(
                'SELECT COUNT(*) FROM {{%job_template}} WHERE name = :name AND project_id = :pid',
                [':name' => $tpl['name'], ':pid' => $projectId]
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
                'verbosity' => $tpl['verbosity'],
                'forks' => 5,
                'become' => $tpl['become'],
                'become_method' => 'sudo',
                'become_user' => 'root',
                'limit' => null,
                'tags' => null,
                'skip_tags' => null,
                'created_by' => $ownerId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $inserted++;
        }

        echo "    > {$inserted} wave-2 demo job templates created.\n";
    }

    public function safeDown(): void
    {
        $projectId = $this->db->createCommand(
            'SELECT id FROM {{%project}} WHERE name = :name LIMIT 1',
            [':name' => self::PROJECT_NAME]
        )->queryScalar();

        if ($projectId === false) {
            return;
        }

        $names = [
            'Demo — Deploy .bashrc',
            'Demo — Common baseline',
            'Demo — Manage /etc/hosts',
            'Demo — System maintenance',
            'Demo — Message of the day',
        ];

        foreach ($names as $name) {
            $this->db->createCommand(
                'DELETE FROM {{%job_template}} WHERE name = :name AND project_id = :pid',
                [':name' => $name, ':pid' => (int) $projectId]
            )->execute();
        }
    }
}
