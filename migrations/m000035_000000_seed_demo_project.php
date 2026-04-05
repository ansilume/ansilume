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
                'description'    => 'Localhost inventory for testing Demo playbooks against the runner container itself. '
                    . 'WARNING: playbooks that install packages or write files will mutate the runner and can leak into '
                    . 'the host repository via the /var/www bind mount. Use for read-only smoke tests (ping, gather facts) only.',
                'inventory_type' => 'static',
                'content'        => "[local]\nlocalhost ansible_connection=local\n",
                'source_path'    => null,
                'project_id'     => null,
                'created_by'     => $ownerId,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }
        $inventoryId   = (int) $this->db->createCommand(
            'SELECT id FROM {{%inventory}} WHERE name = :name ORDER BY id ASC LIMIT 1',
            [':name' => self::INVENTORY_NAME]
        )->queryScalar();
        $runnerGroupId = $this->defaultRunnerGroupId();

        // ------------------------------------------------------------------
        // Job templates — one per playbook.
        // ------------------------------------------------------------------
        $templates = [
            [
                'name'        => 'Demo — Upgrade packages',
                'description' => 'Upgrade all installed packages on the target hosts (apt / dnf). '
                    . 'Prints a reboot notice if a kernel update was applied.',
                'playbook'    => 'playbooks/upgrade.yaml',
                'verbosity'   => 1,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Install vim',
                'description' => 'Install vim on the target hosts.',
                'playbook'    => 'playbooks/install_vim.yaml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Install htop',
                'description' => 'Install htop on the target hosts.',
                'playbook'    => 'playbooks/install_htop.yaml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Install nginx',
                'description' => 'Install nginx from the distribution package manager and ensure the service is started and enabled.',
                'playbook'    => 'playbooks/install_nginx.yaml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Install Docker CE',
                'description' => 'Install Docker CE from the official Docker repository. '
                    . 'Use Extra Vars to add users to the docker group: {"docker_ce_users": ["ubuntu"]}',
                'playbook'    => 'playbooks/install_docker_ce.yaml',
                'verbosity'   => 1,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Install fail2ban',
                'description' => 'Install and configure fail2ban to protect SSH. '
                    . 'Configurable via Extra Vars: {"fail2ban_bantime": 3600, "fail2ban_maxretry": 3}',
                'playbook'    => 'playbooks/install_fail2ban.yaml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Create user',
                'description' => 'Create a system user with optional sudo and SSH key. '
                    . 'Username is required via the launch survey.',
                'playbook'    => 'playbooks/create_user.yaml',
                'verbosity'   => 0,
                'become'      => 1,
                'survey'      => self::createUserSurveyJson(),
            ],
            [
                'name'        => 'Demo — Deploy .bashrc',
                'description' => 'Deploy a managed .bashrc to one or more users with history, aliases, and environment exports. '
                    . 'Configure via Extra Vars: bashrc_users, bashrc_aliases, bashrc_exports.',
                'playbook'    => 'playbooks/bashrc.yaml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Common baseline',
                'description' => 'Apply the common baseline: base package set, SSH authorized keys, apt cache update. '
                    . 'Configure via Extra Vars: common_packages, common_ssh_keys.',
                'playbook'    => 'playbooks/common.yaml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Manage /etc/hosts',
                'description' => 'Write managed entries to /etc/hosts. '
                    . 'Required Extra Vars: hostsfile_servers_mandatory (list of raw "IP  name alias" lines).',
                'playbook'    => 'playbooks/hostsfile.yaml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Install 1Password CLI',
                'description' => 'Install the 1Password command-line tool from the official repository.',
                'playbook'    => 'playbooks/install_onepassword_cli.yaml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — System maintenance',
                'description' => 'Run routine maintenance: autoremove stale packages, autoclean the package cache. '
                    . 'Safe to run regularly from a schedule.',
                'playbook'    => 'playbooks/maintenance.yaml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
            [
                'name'        => 'Demo — Deploy MOTD',
                'description' => 'Write /etc/motd with an optional header/footer around the system info block. '
                    . 'Configure via Extra Vars: motd_header, motd_footer.',
                'playbook'    => 'playbooks/motd.yaml',
                'verbosity'   => 0,
                'become'      => 1,
            ],
        ];

        foreach ($templates as $tpl) {
            $this->insert('{{%job_template}}', [
                'name'            => $tpl['name'],
                'description'     => $tpl['description'],
                'project_id'      => $projectId,
                'inventory_id'    => $inventoryId,
                'credential_id'   => null,
                'runner_group_id' => $runnerGroupId,
                'playbook'        => $tpl['playbook'],
                'extra_vars'      => null,
                'survey_fields'   => $tpl['survey'] ?? null,
                'verbosity'       => $tpl['verbosity'],
                'forks'           => 5,
                'become'          => $tpl['become'],
                'become_method'   => 'sudo',
                'become_user'     => 'root',
                'limit'           => null,
                'tags'            => null,
                'skip_tags'       => null,
                'created_by'      => $ownerId,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        // Queue an initial sync so the project is cloned without manual intervention.
        \Yii::$app->queue->push(new SyncProjectJob(['projectId' => $projectId]));

        echo "    > Demo project, inventory, and " . count($templates) . " job templates created.\n";
        echo "    > Sync job queued — project will be cloned when the queue worker runs.\n";
    }

    /**
     * Survey JSON for the "Create user" demo template.
     *
     * Forces the operator to type a username at launch time so the playbook
     * never runs with an empty system_user_name.
     */
    public static function createUserSurveyJson(): string
    {
        return (string)json_encode([
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
    }

    private function defaultRunnerGroupId(): ?int
    {
        $id = $this->db->createCommand(
            "SELECT id FROM {{%runner_group}} WHERE name = 'default' LIMIT 1"
        )->queryScalar();
        return $id !== false ? (int)$id : null;
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
