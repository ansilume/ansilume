<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Seeds a self-test project, inventory, and job template so the installation
 * has something ready to run out of the box.
 *
 * The selftest playbook lives at ansible/selftest/selftest.yaml inside the
 * repo and targets localhost only — no SSH keys or remote hosts required.
 */
class m000020_000000_seed_selftest_template extends Migration
{
    private const PROJECT_NAME  = 'Selftest';
    private const INVENTORY_NAME = 'Localhost';
    private const TEMPLATE_NAME  = 'Selftest — verify runner';

    public function safeUp(): void
    {
        // Find any superadmin/first user to act as owner.
        $ownerId = (int) $this->db->createCommand(
            'SELECT id FROM {{%user}} ORDER BY id ASC LIMIT 1'
        )->queryScalar();

        if ($ownerId === 0) {
            echo "    > No users found — skipping selftest seed.\n";
            return;
        }

        // Skip if already seeded (idempotent).
        $exists = $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%job_template}} WHERE name = :name',
            [':name' => self::TEMPLATE_NAME]
        )->queryScalar();

        if ((int)$exists > 0) {
            echo "    > Selftest template already exists — skipping.\n";
            return;
        }

        $now            = time();
        $runnerGroupId  = $this->defaultRunnerGroupId();

        // ------------------------------------------------------------------
        // Project — manual type, local path points to the bundled
        // ansible/selftest/ directory mounted at /var/www in the container.
        // ------------------------------------------------------------------
        $this->insert('{{%project}}', [
            'name'        => self::PROJECT_NAME,
            'description' => 'Built-in self-test playbooks. Bundled with Ansilume.',
            'scm_type'    => 'manual',
            'scm_url'     => null,
            'scm_branch'  => 'main',
            'local_path'  => '/var/www/ansible/selftest',
            'status'      => 'synced',
            'last_synced_at' => $now,
            'created_by'  => $ownerId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        $projectId = (int) $this->db->getLastInsertID();

        // ------------------------------------------------------------------
        // Inventory — static localhost-only inventory (no SSH required).
        // ------------------------------------------------------------------
        $this->insert('{{%inventory}}', [
            'name'           => self::INVENTORY_NAME,
            'description'    => 'Single localhost entry. Suitable for local runner tests.',
            'inventory_type' => 'static',
            'content'        => "[local]\nlocalhost ansible_connection=local\n",
            'source_path'    => null,
            'project_id'     => null,
            'created_by'     => $ownerId,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        $inventoryId = (int) $this->db->getLastInsertID();

        // ------------------------------------------------------------------
        // Job Template
        // ------------------------------------------------------------------
        $this->insert('{{%job_template}}', [
            'name'            => self::TEMPLATE_NAME,
            'description'     => 'Runs ansible/selftest/selftest.yaml against localhost to verify the Ansible runner is working correctly.',
            'project_id'      => $projectId,
            'inventory_id'    => $inventoryId,
            'credential_id'   => null,
            'runner_group_id' => $runnerGroupId,
            'playbook'        => 'selftest.yaml',
            'extra_vars'      => null,
            'verbosity'       => 1,
            'forks'           => 1,
            'become'          => 0,
            'become_method'   => 'sudo',
            'become_user'     => 'root',
            'limit'           => null,
            'tags'            => null,
            'skip_tags'       => null,
            'created_by'      => $ownerId,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        echo "    > Selftest project, inventory, and job template created.\n";
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
        $this->delete('{{%job_template}}', ['name' => self::TEMPLATE_NAME]);

        // Only delete project/inventory if nothing else references them.
        $this->db->createCommand(
            'DELETE p FROM {{%project}} p
             LEFT JOIN {{%job_template}} jt ON jt.project_id = p.id
             WHERE p.name = :name AND jt.id IS NULL',
            [':name' => self::PROJECT_NAME]
        )->execute();

        $this->db->createCommand(
            'DELETE i FROM {{%inventory}} i
             LEFT JOIN {{%job_template}} jt ON jt.inventory_id = i.id
             WHERE i.name = :name AND jt.id IS NULL',
            [':name' => self::INVENTORY_NAME]
        )->execute();
    }
}
