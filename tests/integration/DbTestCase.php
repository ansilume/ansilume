<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\models\Credential;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
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
        $u->status        = User::STATUS_ACTIVE;
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

    protected function createRunner(int $groupId, int $createdBy): Runner
    {
        $r = new Runner();
        $r->runner_group_id = $groupId;
        $r->name            = 'test-runner-' . uniqid('', true);
        $r->token_hash      = hash('sha256', bin2hex(random_bytes(8)));
        $r->created_by      = $createdBy;
        $r->created_at      = time();
        $r->updated_at      = time();
        $r->save(false);
        return $r;
    }

    protected function createJob(int $templateId, int $launchedBy, string $status = Job::STATUS_QUEUED): Job
    {
        $j = new Job();
        $j->job_template_id = $templateId;
        $j->launched_by     = $launchedBy;
        $j->status          = $status;
        $j->timeout_minutes = 120;
        $j->has_changes     = 0;
        $j->queued_at       = time();
        $j->created_at      = time();
        $j->updated_at      = time();
        $j->save(false);
        return $j;
    }

    protected function createCredential(int $createdBy, string $type = Credential::TYPE_TOKEN): Credential
    {
        $c = new Credential();
        $c->name            = 'test-credential-' . uniqid('', true);
        $c->credential_type = $type;
        $c->created_by      = $createdBy;
        $c->created_at      = time();
        $c->updated_at      = time();
        $c->save(false);
        return $c;
    }

    protected function createNotificationTemplate(
        int $createdBy,
        string $channel = NotificationTemplate::CHANNEL_EMAIL,
        string $events = 'job.failed',
        string $config = '{"emails": ["test@example.com"]}'
    ): NotificationTemplate {
        $nt = new NotificationTemplate();
        $nt->name = 'test-nt-' . uniqid('', true);
        $nt->channel = $channel;
        $nt->config = $config;
        $nt->subject_template = 'Job #{{ job.id }} {{ job.status }}';
        $nt->body_template = 'Template: {{ template.name }}';
        $nt->events = $events;
        $nt->created_by = $createdBy;
        $nt->created_at = time();
        $nt->updated_at = time();
        $nt->save(false);
        return $nt;
    }

    protected function createTeam(int $createdBy): Team
    {
        $t = new Team();
        $t->name       = 'test-team-' . uniqid('', true);
        $t->created_by = $createdBy;
        $t->created_at = time();
        $t->updated_at = time();
        $t->save(false);
        return $t;
    }

    protected function addTeamMember(int $teamId, int $userId): TeamMember
    {
        $m = new TeamMember();
        $m->team_id    = $teamId;
        $m->user_id    = $userId;
        $m->created_at = time();
        $m->save(false);
        return $m;
    }

    protected function createTeamProject(int $teamId, int $projectId, string $role = TeamProject::ROLE_OPERATOR): TeamProject
    {
        $tp = new TeamProject();
        $tp->team_id    = $teamId;
        $tp->project_id = $projectId;
        $tp->role       = $role;
        $tp->created_at = time();
        $tp->save(false);
        return $tp;
    }
}
