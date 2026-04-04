<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds the newer playbooks from the ansible-demo repository as Demo job
 * templates: bashrc, common baseline, hosts file, 1Password CLI, maintenance,
 * and MOTD.
 *
 * Idempotent: skips templates that already exist by name. Requires the Demo
 * project + Demo Localhost inventory seeded by m000035.
 */
class m000058_000000_seed_more_demo_templates extends Migration
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
            echo "    > Demo project not found — skipping additional demo templates.\n";
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
            echo "    > No users found — skipping additional demo templates.\n";
            return;
        }

        $runnerGroupId = $this->db->createCommand(
            "SELECT id FROM {{%runner_group}} WHERE name = 'default' LIMIT 1"
        )->queryScalar();
        $runnerGroupId = $runnerGroupId !== false ? (int)$runnerGroupId : null;

        $now = time();

        $templates = [
            [
                'name' => 'Demo — Deploy .bashrc',
                'description' => 'Deploy a managed .bashrc to one or more users with history, aliases, and environment exports. '
                    . 'Configure via Extra Vars: bashrc_users, bashrc_aliases, bashrc_exports.',
                'playbook' => 'playbooks/bashrc.yaml',
            ],
            [
                'name' => 'Demo — Common baseline',
                'description' => 'Apply the common baseline: base package set, SSH authorized keys, apt cache update. '
                    . 'Configure via Extra Vars: common_packages, common_ssh_keys.',
                'playbook' => 'playbooks/common.yaml',
            ],
            [
                'name' => 'Demo — Manage /etc/hosts',
                'description' => 'Write managed entries to /etc/hosts. '
                    . 'Required Extra Vars: hostsfile_servers_mandatory (list of raw "IP  name alias" lines).',
                'playbook' => 'playbooks/hostsfile.yaml',
            ],
            [
                'name' => 'Demo — Install 1Password CLI',
                'description' => 'Install the 1Password command-line tool from the official repository.',
                'playbook' => 'playbooks/install_onepassword_cli.yaml',
            ],
            [
                'name' => 'Demo — System maintenance',
                'description' => 'Run routine maintenance: autoremove stale packages, autoclean the package cache. '
                    . 'Safe to run regularly from a schedule.',
                'playbook' => 'playbooks/maintenance.yaml',
            ],
            [
                'name' => 'Demo — Deploy MOTD',
                'description' => 'Write /etc/motd with an optional header/footer around the system info block. '
                    . 'Configure via Extra Vars: motd_header, motd_footer.',
                'playbook' => 'playbooks/motd.yaml',
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
                'created_by' => $ownerId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $inserted++;
        }

        echo "    > Seeded {$inserted} additional demo templates.\n";
    }

    public function safeDown(): void
    {
        $names = [
            'Demo — Deploy .bashrc',
            'Demo — Common baseline',
            'Demo — Manage /etc/hosts',
            'Demo — Install 1Password CLI',
            'Demo — System maintenance',
            'Demo — Deploy MOTD',
        ];
        $this->db->createCommand()
            ->delete('{{%job_template}}', ['name' => $names])
            ->execute();
    }
}
