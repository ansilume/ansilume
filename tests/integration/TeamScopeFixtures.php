<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\models\JobTemplate;
use app\models\TeamProject;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;

/**
 * Shared fixtures for team-scoping tests; use in DbTestCase subclasses.
 *
 * teamScope() builds four projects with one job template each:
 * - own: the member's team operates it
 * - viewed: the member's team only views it
 * - foreign: another team (with `outsider`) operates it
 * - open: no team at all, so everyone may use it
 */
trait TeamScopeFixtures
{
    protected function assignRole(int $userId, string $roleName): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole($roleName);
        $this->assertNotNull($role, $roleName);
        $auth->assign($role, (string)$userId);
    }

    protected function createUserWithRole(string $suffix, string $roleName): User
    {
        $user = $this->createUser($suffix);
        $this->assignRole($user->id, $roleName);
        return $user;
    }

    /**
     * @return array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate}
     */
    protected function teamScope(string $memberRole = 'operator'): array
    {
        $admin = $this->createUserWithRole('scope_admin', 'admin');
        $member = $this->createUserWithRole('scope_member', $memberRole);
        $outsider = $this->createUserWithRole('scope_outsider', 'operator');
        $group = $this->createRunnerGroup($admin->id);
        $inventory = $this->createInventory($admin->id);

        $templates = [];
        foreach (['own', 'viewed', 'foreign', 'open'] as $key) {
            $project = $this->createProject($admin->id);
            $templates[$key] = $this->createJobTemplate($project->id, $inventory->id, $group->id, $admin->id);
        }

        $team = $this->createTeam($admin->id);
        $this->addTeamMember($team->id, $member->id);
        $this->createTeamProject($team->id, (int)$templates['own']->project_id, TeamProject::ROLE_OPERATOR);
        $this->createTeamProject($team->id, (int)$templates['viewed']->project_id, TeamProject::ROLE_VIEWER);

        $other = $this->createTeam($admin->id);
        $this->addTeamMember($other->id, $outsider->id);
        $this->createTeamProject($other->id, (int)$templates['foreign']->project_id, TeamProject::ROLE_OPERATOR);

        return [
            'member' => $member,
            'admin' => $admin,
            'outsider' => $outsider,
            'own' => $templates['own'],
            'viewed' => $templates['viewed'],
            'foreign' => $templates['foreign'],
            'open' => $templates['open'],
        ];
    }

    protected function createWorkflowWithJobSteps(int $createdBy, int ...$jobTemplateIds): WorkflowTemplate
    {
        $wt = $this->createWorkflowTemplate($createdBy);
        foreach (array_values($jobTemplateIds) as $order => $jobTemplateId) {
            $this->createWorkflowStep($wt->id, $order, WorkflowStep::TYPE_JOB, $jobTemplateId);
        }
        return $wt;
    }

    protected function createWorkflowJob(
        int $workflowTemplateId,
        int $launchedBy,
        string $status = WorkflowJob::STATUS_RUNNING
    ): WorkflowJob {
        $wfJob = new WorkflowJob();
        $wfJob->workflow_template_id = $workflowTemplateId;
        $wfJob->launched_by = $launchedBy;
        $wfJob->status = $status;
        $wfJob->started_at = time();
        $wfJob->created_at = time();
        $wfJob->updated_at = time();
        $wfJob->save(false);
        return $wfJob;
    }
}
