<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;

/**
 * Seeds team scoping test data for E2E tests.
 *
 * Creates two isolated teams (alpha/beta) with separate projects,
 * templates, and inventories for resource isolation testing.
 */
class E2eTeamScopingSeeder
{
    private const PREFIX = 'e2e-';

    /**
     * @var callable(string): void
     */
    private $logger;

    /**
     * @param callable(string): void $logger
     */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId, int $runnerGroupId): void
    {
        $teamAlpha = Team::find()->where(['name' => self::PREFIX . 'team-alpha'])->one();
        if ($teamAlpha !== null) {
            ($this->logger)("  Team scoping data already exists.\n");
            return;
        }

        $projectAlpha = $this->createProject('alpha-proj', $userId);
        $projectBeta = $this->createProject('beta-proj', $userId);

        $invAlpha = $this->createInventory('alpha-inv', "alpha-host\n", $projectAlpha->id, $userId);
        $invBeta = $this->createInventory('beta-inv', "beta-host\n", $projectBeta->id, $userId);

        $this->createTemplate('alpha-tmpl', $projectAlpha->id, $invAlpha->id, $runnerGroupId, $userId);
        $this->createTemplate('beta-tmpl', $projectBeta->id, $invBeta->id, $runnerGroupId, $userId);

        $this->createTeamWithMember('team-alpha', 'Team Alpha for scoping tests', $projectAlpha->id, 'e2e-operator', $userId);
        $this->createTeamWithMember('team-beta', 'Team Beta for scoping tests', $projectBeta->id, 'e2e-viewer', $userId);

        ($this->logger)("  Created team scoping data (alpha/beta teams, projects, templates, inventories).\n");
    }

    private function createProject(string $suffix, int $userId): Project
    {
        $project = new Project();
        $project->name = self::PREFIX . $suffix;
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->scm_branch = 'main';
        $project->status = Project::STATUS_NEW;
        $project->created_by = $userId;
        $project->save(false);
        return $project;
    }

    private function createInventory(string $suffix, string $content, int $projectId, int $userId): Inventory
    {
        $inv = new Inventory();
        $inv->name = self::PREFIX . $suffix;
        $inv->inventory_type = Inventory::TYPE_STATIC;
        $inv->content = $content;
        $inv->project_id = $projectId;
        $inv->created_by = $userId;
        $inv->save(false);
        return $inv;
    }

    private function createTemplate(
        string $suffix,
        int $projectId,
        int $inventoryId,
        int $runnerGroupId,
        int $userId
    ): void {
        $tmpl = new JobTemplate();
        $tmpl->name = self::PREFIX . $suffix;
        $tmpl->project_id = $projectId;
        $tmpl->inventory_id = $inventoryId;
        $tmpl->playbook = 'site.yml';
        $tmpl->runner_group_id = $runnerGroupId;
        $tmpl->verbosity = 0;
        $tmpl->forks = 5;
        $tmpl->timeout_minutes = 120;
        $tmpl->become = false;
        $tmpl->become_method = 'sudo';
        $tmpl->become_user = 'root';
        $tmpl->created_by = $userId;
        $tmpl->save(false);
    }

    private function createTeamWithMember(
        string $suffix,
        string $description,
        int $projectId,
        string $memberUsername,
        int $userId
    ): void {
        $team = new Team();
        $team->name = self::PREFIX . $suffix;
        $team->description = $description;
        $team->created_by = $userId;
        $team->save(false);

        $tp = new TeamProject();
        $tp->team_id = $team->id;
        $tp->project_id = $projectId;
        $tp->role = TeamProject::ROLE_OPERATOR;
        $tp->created_at = time();
        $tp->save(false);

        /** @var User|null $user */
        $user = User::find()->where(['username' => $memberUsername])->one();
        if ($user !== null) {
            $member = new TeamMember();
            $member->team_id = $team->id;
            $member->user_id = $user->id;
            $member->created_at = time();
            $member->save(false);
        }
    }
}
