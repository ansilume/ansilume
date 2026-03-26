<?php

declare(strict_types=1);

use app\jobs\SyncProjectJob;
use yii\db\Migration;

/**
 * Seeds the Demo project from github.com/ansilume/ansible-demo with a set of
 * ready-to-use job templates for common infrastructure tasks.
 *
 * The project is created with status "new" so the runner syncs it on first
 * poll. Job templates are pre-configured but require the user to attach their
 * own inventory (and SSH credential where needed) before launching against
 * real hosts.
 *
 * Includes a "Demo — Localhost" inventory for testing templates against
 * the runner host itself.
 */
class m000035_000000_seed_demo_project extends Migration
{
    private const PROJECT_NAME   = 'Demo';
    private const INVENTORY_NAME = 'Demo — Localhost';
    private const DEMO_REPO_URL  = 'https://github.com/ansilume/ansible-demo.git';

    public function safeUp(): void
    {
        $ownerId = (int) $this->db->createCommand(
            'SELECT id FROM {{%user}} ORDER BY id ASC LIMIT 1'
        )->queryScalar();

        if ($ownerId === 0) {
            echo "    > No users found — skipping Demo project seed.\n";
            return;
        }

        // Idempotent: skip if already seeded.
        $exists = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%project}} WHERE name = :name',
            [':name' => self::PROJECT_NAME]
        )->queryScalar();

        if ($exists > 0) {
            echo "    > Demo project already exists — skipping.\n";
            return;
        }

        $now = time();

        // ------------------------------------------------------------------
        // Project — git-backed, synced by the runner on first poll.
        // ------------------------------------------------------------------
        $this->insert('{{%project}}', [
            'name'               => self::PROJECT_NAME,
            'description'        => 'Example playbooks: package upgrades, service installation, user management. Source: github.com/ansilume/ansible-demo',
            'scm_type'           => 'git',
            'scm_url'            => self::DEMO_REPO_URL,
            'scm_branch'         => 'main',
            'scm_credential_id'  => null,
            'local_path'         => null,
            'status'             => 'new',
            'last_synced_at'     => null,
            'created_by'         => $ownerId,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $projectId = (int) $this->db->getLastInsertID();

        // ------------------------------------------------------------------
        // Inventory — static localhost (for quick tests without SSH).
        // ------------------------------------------------------------------
        $exists = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%inventory}} WHERE name = :name',
            [':name' => self::INVENTORY_NAME]
        )->queryScalar();

        if ($exists === 0) {
            $this->insert('{{%inventory}}', [
                'name'           => self::INVENTORY_NAME,
                'description'    => 'Localhost inventory for testing Demo playbooks on the runner host itself.',
                'inventory_type' => 'static',
                'content'        => "[local]\nlocalhost ansible_connection=local\n",
                'source_path'    => null,
                'project_id'     => null,
                'created_by'     => $ownerId,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }
        $inventoryId = (int) $this->db->createCommand(
            'SELECT id FROM {{%inventory}} WHERE name = :name ORDER BY id ASC LIMIT 1',
            [':name' => self::INVENTORY_NAME]
        )->queryScalar();

        // ------------------------------------------------------------------
        // Job templates — one per playbook.
        // ------------------------------------------------------------------
        $templates = [
            [
                'name'        => 'Demo — Upgrade packages',
                'description' => 'Upgrade all installed packages on the target hosts (apt / dnf). '
                    . 'Prints a reboot notice if a kernel update was applied.',
                'playbook'    => 'upgrade.yml',
                'verbosity'   => 1,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Install vim',
                'description' => 'Install vim on the target hosts.',
                'playbook'    => 'install-vim.yml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Install nginx',
                'description' => 'Install nginx from the distribution package manager and ensure the service is started and enabled.',
                'playbook'    => 'install-nginx.yml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Install Docker CE',
                'description' => 'Install Docker CE from the official Docker repository. '
                    . 'Use Extra Vars to add users to the docker group: {"docker_users": ["ubuntu"]}',
                'playbook'    => 'install-docker-ce.yml',
                'verbosity'   => 1,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Install fail2ban',
                'description' => 'Install and configure fail2ban to protect SSH. '
                    . 'Configurable via Extra Vars: {"fail2ban_bantime": 3600, "fail2ban_maxretry": 3}',
                'playbook'    => 'install-fail2ban.yml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Create user',
                'description' => 'Create a system user with optional sudo and SSH key. '
                    . 'Required Extra Vars: {"new_user": "deploy", "new_user_sudo": true, "new_user_pubkey": "ssh-ed25519 AAAA..."}',
                'playbook'    => 'create-user.yml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
        ];

        foreach ($templates as $tpl) {
            $this->insert('{{%job_template}}', [
                'name'          => $tpl['name'],
                'description'   => $tpl['description'],
                'project_id'    => $projectId,
                'inventory_id'  => $inventoryId,
                'credential_id' => null,
                'playbook'      => $tpl['playbook'],
                'extra_vars'    => null,
                'verbosity'     => $tpl['verbosity'],
                'forks'         => 5,
                'become'        => $tpl['become'],
                'become_method' => 'sudo',
                'become_user'   => 'root',
                'limit'         => null,
                'tags'          => null,
                'skip_tags'     => null,
                'created_by'    => $ownerId,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        // Queue an initial sync so the project is cloned without manual intervention.
        \Yii::$app->queue->push(new SyncProjectJob(['projectId' => $projectId]));

        echo "    > Demo project, inventory, and " . count($templates) . " job templates created.\n";
        echo "    > Sync job queued — project will be cloned when the queue worker runs.\n";
    }

    public function safeDown(): void
    {
        // Remove templates first (FK constraint).
        $this->db->createCommand(
            'DELETE jt FROM {{%job_template}} jt
             INNER JOIN {{%project}} p ON p.id = jt.project_id
             WHERE p.name = :name',
            [':name' => self::PROJECT_NAME]
        )->execute();

        $this->db->createCommand(
            'DELETE FROM {{%project}} WHERE name = :name',
            [':name' => self::PROJECT_NAME]
        )->execute();

        // Only delete the inventory if no other templates reference it.
        $this->db->createCommand(
            'DELETE i FROM {{%inventory}} i
             LEFT JOIN {{%job_template}} jt ON jt.inventory_id = i.id
             WHERE i.name = :name AND jt.id IS NULL',
            [':name' => self::INVENTORY_NAME]
        )->execute();
    }
}
