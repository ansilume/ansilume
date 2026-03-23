<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\models\User;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests that need a real database.
 *
 * Each test runs inside a transaction that is rolled back in tearDown(),
 * so tests are fully isolated from each other and leave no residue in
 * the ansilume_test DB.
 */
abstract class DbTestCase extends TestCase
{
    private \yii\db\Transaction $tx;

    protected function setUp(): void
    {
        $this->tx = \Yii::$app->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (\Yii::$app->db->transaction !== null) {
            \Yii::$app->db->transaction->rollBack();
        }
    }

    // -------------------------------------------------------------------------
    // Fixture helpers — all use save(false) so no validation runs, and all
    // records are wiped by the rollback in tearDown().
    // -------------------------------------------------------------------------

    protected function createUser(string $suffix = ''): User
    {
        $u = new User();
        $u->username      = 'test_user_' . $suffix . uniqid('', true);
        $u->email         = 'test_' . uniqid('', true) . '@example.com';
        $u->password_hash = \Yii::$app->security->generatePasswordHash('test');
        $u->auth_key      = \Yii::$app->security->generateRandomString();
        $u->status        = 'active';
        $u->created_at    = time();
        $u->updated_at    = time();
        $u->save(false);
        return $u;
    }

    protected function createRunnerGroup(int $createdBy): RunnerGroup
    {
        $g = new RunnerGroup();
        $g->name       = 'test-group-' . uniqid('', true);
        $g->created_by = $createdBy;
        $g->created_at = time();
        $g->updated_at = time();
        $g->save(false);
        return $g;
    }

    protected function createProject(int $createdBy): Project
    {
        $p = new Project();
        $p->name       = 'test-project-' . uniqid('', true);
        $p->scm_type   = Project::SCM_TYPE_MANUAL;
        $p->scm_branch = 'main';
        $p->status     = Project::STATUS_NEW;
        $p->created_by = $createdBy;
        $p->created_at = time();
        $p->updated_at = time();
        $p->save(false);
        return $p;
    }

    protected function createInventory(int $createdBy): Inventory
    {
        $i = new Inventory();
        $i->name           = 'test-inventory-' . uniqid('', true);
        $i->inventory_type = Inventory::TYPE_STATIC;
        $i->content        = "localhost\n";
        $i->created_by     = $createdBy;
        $i->created_at     = time();
        $i->updated_at     = time();
        $i->save(false);
        return $i;
    }

    protected function createJobTemplate(int $projectId, int $inventoryId, int $runnerGroupId, int $createdBy): JobTemplate
    {
        $t = new JobTemplate();
        $t->name            = 'test-template-' . uniqid('', true);
        $t->project_id      = $projectId;
        $t->inventory_id    = $inventoryId;
        $t->playbook        = 'site.yml';
        $t->runner_group_id = $runnerGroupId;
        $t->verbosity       = 0;
        $t->forks           = 5;
        $t->timeout_minutes = 120;
        $t->become          = false;
        $t->become_method   = 'sudo';
        $t->become_user     = 'root';
        $t->created_by      = $createdBy;
        $t->created_at      = time();
        $t->updated_at      = time();
        $t->save(false);
        return $t;
    }
}
