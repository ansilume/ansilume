<?php

declare(strict_types=1);

namespace app\commands;

use app\models\ApprovalRule;
use app\models\Credential;
use app\models\Inventory;
use app\models\Job;
use app\models\JobArtifact;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\models\Schedule;
use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use app\models\Webhook;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * E2E test data seeding and teardown.
 *
 * All entities are prefixed with "e2e-" for safe identification and cleanup.
 */
class E2eController extends Controller
{
    private const PREFIX = 'e2e-';

    /**
     * Seed E2E test users and data. Idempotent — safe to run multiple times.
     */
    public function actionSeed(): int
    {
        $this->stdout("Seeding E2E test data...\n");

        $adminId = $this->seedUsers();
        if ($adminId === null) {
            $this->stderr("Failed to seed users.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->seedData($adminId);

        $this->stdout("E2E seed complete.\n");
        return ExitCode::OK;
    }

    /**
     * Remove all E2E test data (entities prefixed with "e2e-").
     */
    public function actionTeardown(): int
    {
        $this->stdout("Tearing down E2E test data...\n");

        $this->createTeardownHelper()->teardownAll();

        $this->stdout("E2E teardown complete.\n");
        return ExitCode::OK;
    }

    /**
     * @return int|null Admin user ID, or null on failure.
     */
    private function seedUsers(): ?int
    {
        $users = [
            [
                'username' => 'e2e-admin',
                'email' => 'e2e-admin@example.com',
                'password' => 'E2eAdminPass1!',
                'role' => 'admin',
                'superadmin' => true,
            ],
            [
                'username' => 'e2e-operator',
                'email' => 'e2e-operator@example.com',
                'password' => 'E2eOperatorPass1!',
                'role' => 'operator',
                'superadmin' => false,
            ],
            [
                'username' => 'e2e-viewer',
                'email' => 'e2e-viewer@example.com',
                'password' => 'E2eViewerPass1!',
                'role' => 'viewer',
                'superadmin' => false,
            ],
        ];

        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $adminId = null;

        foreach ($users as $data) {
            $existing = User::find()->where(['username' => $data['username']])->one();
            if ($existing !== null) {
                $this->stdout("  User '{$data['username']}' already exists (ID {$existing->id}).\n");
                if ($data['role'] === 'admin') {
                    $adminId = $existing->id;
                }
                continue;
            }

            $user = new User();
            $user->username = $data['username'];
            $user->email = $data['email'];
            $user->status = User::STATUS_ACTIVE;
            $user->is_superadmin = $data['superadmin'];
            $user->setPassword($data['password']);
            $user->generateAuthKey();

            if (!$user->save()) {
                $this->stderr("  Failed to create '{$data['username']}': "
                    . json_encode($user->errors) . "\n");
                return null;
            }

            $role = $auth->getRole($data['role']);
            if ($role !== null) {
                $auth->assign($role, $user->id);
            }

            $this->stdout("  Created user '{$data['username']}' (ID {$user->id}, role: {$data['role']}).\n");

            if ($data['role'] === 'admin') {
                $adminId = $user->id;
            }
        }

        return $adminId;
    }

    private function seedData(int $userId): void
    {
        $runnerGroupId = $this->seedRunnerGroup($userId);
        $this->seedSecondRunnerGroup($userId);
        $projectId = $this->seedProject($userId);
        $inventoryId = $this->seedInventory($userId);
        $credentialId = $this->seedCredential($userId);
        $templateId = $this->seedJobTemplate($userId, $projectId, $inventoryId, $credentialId, $runnerGroupId);
        $this->seedNotificationTemplate($userId);
        $this->seedWebhook($userId);
        $this->seedSchedule($userId, $templateId);
        $approvalRuleId = $this->seedApprovalRule($userId);
        $workflowTemplateId = $this->seedWorkflowTemplate($userId);
        $this->seedWorkflowStep($workflowTemplateId, $templateId, $approvalRuleId);
        $this->seedApprovalWorkflow($userId, $templateId, $approvalRuleId);
        $this->seedTeam($userId, $projectId);
        $this->seedJobWithArtifacts($userId, $templateId);
        $seeder = new E2eTeamScopingSeeder(function (string $msg): void {
            $this->stdout($msg);
        });
        $seeder->seed($userId, $runnerGroupId);
        $this->seedCustomRole();
    }

    private function seedCustomRole(): void
    {
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $name = self::PREFIX . 'custom-role';
        if ($auth->getRole($name) !== null) {
            $this->stdout("  Custom role '{$name}' already exists.\n");
            return;
        }

        $role = $auth->createRole($name);
        $role->description = 'E2E custom role';
        $auth->add($role);

        foreach (['project.view', 'job.view', 'analytics.view'] as $permName) {
            $perm = $auth->getPermission($permName);
            if ($perm !== null) {
                $auth->addChild($role, $perm);
            }
        }
        $this->stdout("  Created custom role '{$name}'.\n");
    }

    private function seedRunnerGroup(int $userId): int
    {
        $existing = RunnerGroup::find()->where(['name' => self::PREFIX . 'runner-group'])->one();
        if ($existing !== null) {
            $this->stdout("  Runner group already exists (ID {$existing->id}).\n");
            return $existing->id;
        }

        // Try to use the default runner group first
        $defaultGroup = RunnerGroup::find()->where(['name' => 'default'])->one();
        if ($defaultGroup !== null) {
            return $defaultGroup->id;
        }

        $group = new RunnerGroup();
        $group->name = self::PREFIX . 'runner-group';
        $group->description = 'E2E test runner group';
        $group->created_by = $userId;
        $group->save(false);

        $this->stdout("  Created runner group (ID {$group->id}).\n");
        return $group->id;
    }

    private function seedSecondRunnerGroup(int $userId): void
    {
        $name = self::PREFIX . 'runner-group-2';
        $existing = RunnerGroup::find()->where(['name' => $name])->one();
        if ($existing !== null) {
            $this->stdout("  Second runner group already exists (ID {$existing->id}).\n");
            return;
        }

        $group = new RunnerGroup();
        $group->name = $name;
        $group->description = 'E2E second runner group (for move tests)';
        $group->created_by = $userId;
        $group->save(false);

        $this->stdout("  Created second runner group (ID {$group->id}).\n");
    }

    private function seedProject(int $userId): int
    {
        $existing = Project::find()->where(['name' => self::PREFIX . 'project'])->one();
        if ($existing !== null) {
            $this->stdout("  Project already exists (ID {$existing->id}).\n");
            return $existing->id;
        }

        $project = new Project();
        $project->name = self::PREFIX . 'project';
        $project->description = 'E2E test project (manual)';
        $project->scm_type = 'manual';
        $project->status = 'new';
        $project->created_by = $userId;
        $project->save(false);

        $this->stdout("  Created project (ID {$project->id}).\n");
        return $project->id;
    }

    private function seedInventory(int $userId): int
    {
        $existing = Inventory::find()->where(['name' => self::PREFIX . 'inventory'])->one();
        if ($existing !== null) {
            $this->stdout("  Inventory already exists (ID {$existing->id}).\n");
            return $existing->id;
        }

        $inventory = new Inventory();
        $inventory->name = self::PREFIX . 'inventory';
        $inventory->description = 'E2E test static inventory';
        $inventory->inventory_type = 'static';
        $inventory->content = "all:\n  hosts:\n    localhost:\n      ansible_connection: local\n";
        $inventory->created_by = $userId;
        $inventory->save(false);

        $this->stdout("  Created inventory (ID {$inventory->id}).\n");
        return $inventory->id;
    }

    private function seedCredential(int $userId): int
    {
        $existing = Credential::find()->where(['name' => self::PREFIX . 'credential'])->one();
        if ($existing !== null) {
            $this->stdout("  Credential already exists (ID {$existing->id}).\n");
            return $existing->id;
        }

        $credential = new Credential();
        $credential->name = self::PREFIX . 'credential';
        $credential->description = 'E2E test token credential';
        $credential->credential_type = 'token';
        $credential->created_by = $userId;
        $credential->save(false);

        /** @var \app\services\CredentialService $credentialService */
        $credentialService = \Yii::$app->get('credentialService');
        $credentialService->storeSecrets($credential, [
            'token' => 'e2e-dummy-token-value',
        ]);

        $this->stdout("  Created credential (ID {$credential->id}).\n");
        return $credential->id;
    }

    private function seedJobTemplate(
        int $userId,
        int $projectId,
        int $inventoryId,
        int $credentialId,
        int $runnerGroupId
    ): int {
        $existing = JobTemplate::find()->where(['name' => self::PREFIX . 'template'])->one();
        if ($existing !== null) {
            $this->stdout("  Job template already exists (ID {$existing->id}).\n");
            return $existing->id;
        }

        $template = new JobTemplate();
        $template->name = self::PREFIX . 'template';
        $template->description = 'E2E test job template';
        $template->project_id = $projectId;
        $template->inventory_id = $inventoryId;
        $template->credential_id = $credentialId;
        $template->runner_group_id = $runnerGroupId;
        $template->playbook = 'site.yml';
        $template->verbosity = 0;
        $template->forks = 5;
        $template->become = false;
        $template->timeout_minutes = 30;
        $template->created_by = $userId;
        $template->save(false);

        $this->stdout("  Created job template (ID {$template->id}).\n");
        return $template->id;
    }

    private function seedNotificationTemplate(int $userId): void
    {
        $existing = NotificationTemplate::find()
            ->where(['name' => self::PREFIX . 'notification'])->one();
        if ($existing !== null) {
            $this->stdout("  Notification template already exists.\n");
            return;
        }

        $nt = new NotificationTemplate();
        $nt->name = self::PREFIX . 'notification';
        $nt->description = 'E2E test email notification';
        $nt->channel = 'email';
        $nt->events = 'job.succeeded,job.failed';
        $nt->config = (string)json_encode(['emails' => ['e2e@example.com']]);
        $nt->subject_template = 'Job {{job.status}}: {{job.name}}';
        $nt->body_template = 'Job {{job.name}} finished with status {{job.status}}.';
        $nt->created_by = $userId;
        $nt->save(false);

        $this->stdout("  Created notification template (ID {$nt->id}).\n");
    }

    private function seedWebhook(int $userId): void
    {
        $existing = Webhook::find()->where(['name' => self::PREFIX . 'webhook'])->one();
        if ($existing !== null) {
            $this->stdout("  Webhook already exists.\n");
            return;
        }

        $wh = new Webhook();
        $wh->name = self::PREFIX . 'webhook';
        $wh->url = 'https://example.com/e2e-webhook';
        $wh->events = 'job.success,job.failure';
        $wh->enabled = false;
        $wh->created_by = $userId;
        $wh->save(false);

        $this->stdout("  Created webhook (ID {$wh->id}).\n");
    }

    private function seedSchedule(int $userId, int $templateId): void
    {
        $existing = Schedule::find()->where(['name' => self::PREFIX . 'schedule'])->one();
        if ($existing !== null) {
            $this->stdout("  Schedule already exists.\n");
            return;
        }

        $schedule = new Schedule();
        $schedule->name = self::PREFIX . 'schedule';
        $schedule->job_template_id = $templateId;
        $schedule->cron_expression = '0 0 1 1 *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = false;
        $schedule->created_by = $userId;
        $schedule->save(false);
        $schedule->computeNextRunAt();

        $this->stdout("  Created schedule (ID {$schedule->id}).\n");
    }

    private function seedApprovalRule(int $userId): int
    {
        $existing = ApprovalRule::find()->where(['name' => self::PREFIX . 'approval-rule'])->one();
        if ($existing !== null) {
            $this->stdout("  Approval rule already exists (ID {$existing->id}).\n");
            return $existing->id;
        }

        $rule = new ApprovalRule();
        $rule->name = self::PREFIX . 'approval-rule';
        $rule->description = 'E2E test approval rule';
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_USERS;
        $rule->approver_config = (string)json_encode(['user_ids' => [$userId]]);
        $rule->required_approvals = 1;
        $rule->timeout_minutes = 60;
        $rule->timeout_action = 'reject';
        $rule->created_by = $userId;
        $rule->save(false);

        $this->stdout("  Created approval rule (ID {$rule->id}).\n");
        return $rule->id;
    }

    private function seedWorkflowTemplate(int $userId): int
    {
        $existing = WorkflowTemplate::find()
            ->where(['name' => self::PREFIX . 'workflow'])->one();
        if ($existing !== null) {
            $this->stdout("  Workflow template already exists (ID {$existing->id}).\n");
            return $existing->id;
        }

        $wt = new WorkflowTemplate();
        $wt->name = self::PREFIX . 'workflow';
        $wt->description = 'E2E test workflow template';
        $wt->created_by = $userId;
        $wt->save(false);

        $this->stdout("  Created workflow template (ID {$wt->id}).\n");
        return $wt->id;
    }

    private function seedWorkflowStep(
        int $workflowTemplateId,
        int $jobTemplateId,
        int $approvalRuleId
    ): void {
        $existing = WorkflowStep::find()
            ->where(['workflow_template_id' => $workflowTemplateId])
            ->exists();
        if ($existing) {
            $this->stdout("  Workflow steps already exist.\n");
            return;
        }

        $step = new WorkflowStep();
        $step->workflow_template_id = $workflowTemplateId;
        $step->name = self::PREFIX . 'step-run';
        $step->step_order = 1;
        $step->step_type = 'job';
        $step->job_template_id = $jobTemplateId;
        $step->save(false);

        $this->stdout("  Created workflow step (ID {$step->id}).\n");
    }

    private function seedApprovalWorkflow(int $userId, int $jobTemplateId, int $approvalRuleId): void
    {
        $name = self::PREFIX . 'approval-workflow';
        $existing = WorkflowTemplate::find()->where(['name' => $name])->one();
        if ($existing !== null) {
            $this->stdout("  Approval workflow already exists (ID {$existing->id}).\n");
            return;
        }

        $wt = new WorkflowTemplate();
        $wt->name = $name;
        $wt->description = 'E2E workflow with approval gate';
        $wt->created_by = $userId;
        $wt->save(false);

        $approval = new WorkflowStep();
        $approval->workflow_template_id = $wt->id;
        $approval->name = self::PREFIX . 'step-approve';
        $approval->step_order = 0;
        $approval->step_type = 'approval';
        $approval->approval_rule_id = $approvalRuleId;
        $approval->on_failure_step_id = WorkflowStep::END_WORKFLOW;
        $approval->save(false);

        $job = new WorkflowStep();
        $job->workflow_template_id = $wt->id;
        $job->name = self::PREFIX . 'step-job-after-approval';
        $job->step_order = 1;
        $job->step_type = 'job';
        $job->job_template_id = $jobTemplateId;
        $job->save(false);
        $this->stdout("  Created approval workflow (ID {$wt->id}) with 2 steps.\n");
    }
    private function seedTeam(int $userId, int $projectId): void
    {
        $existing = Team::find()->where(['name' => self::PREFIX . 'team'])->one();
        if ($existing !== null) {
            $this->stdout("  Team already exists.\n");
            return;
        }

        $team = new Team();
        $team->name = self::PREFIX . 'team';
        $team->description = 'E2E test team';
        $team->created_by = $userId;
        $team->save(false);

        // Add operator user as team member
        $operator = User::find()->where(['username' => 'e2e-operator'])->one();
        if ($operator !== null) {
            $member = new TeamMember();
            $member->team_id = $team->id;
            $member->user_id = $operator->id;
            $member->created_at = time();
            $member->save(false);
        }

        // Grant team access to the e2e project
        $tp = new TeamProject();
        $tp->team_id = $team->id;
        $tp->project_id = $projectId;
        $tp->role = 'operator';
        $tp->created_at = time();
        $tp->save(false);

        $this->stdout("  Created team (ID {$team->id}) with member and project.\n");
    }

    private function seedJobWithArtifacts(int $userId, int $templateId): void
    {
        // Check if we already have an e2e job with artifacts
        $existing = Job::find()
            ->where(['job_template_id' => $templateId, 'status' => Job::STATUS_SUCCEEDED])
            ->andWhere(['like', 'execution_command', 'e2e-artifact'])
            ->one();
        if ($existing !== null) {
            $this->stdout("  Job with artifacts already exists (ID {$existing->id}).\n");
            return;
        }

        $job = new Job();
        $job->job_template_id = $templateId;
        $job->launched_by = $userId;
        $job->status = Job::STATUS_SUCCEEDED;
        $job->exit_code = 0;
        $job->execution_command = 'e2e-artifact-job';
        $job->timeout_minutes = 120;
        $job->has_changes = 0;
        $job->queued_at = time() - 60;
        $job->started_at = time() - 30;
        $job->finished_at = time();
        $job->created_at = time();
        $job->updated_at = time();
        $job->save(false);

        // Create artifact files on disk (world-readable so www-data can serve them)
        $storagePath = \Yii::getAlias('@runtime/artifacts') . '/job_' . $job->id;
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        // Text artifact (previewable)
        $textFile = $storagePath . '/report.txt';
        file_put_contents($textFile, "E2E Artifact Report\nStatus: OK\nTimestamp: " . date('c'));
        $a1 = new JobArtifact();
        $a1->job_id = $job->id;
        $a1->filename = 'report.txt';
        $a1->display_name = 'report.txt';
        $a1->mime_type = 'text/plain';
        $a1->size_bytes = (int)filesize($textFile);
        $a1->storage_path = $textFile;
        $a1->created_at = time();
        $a1->save(false);

        // JSON artifact (previewable)
        $jsonFile = $storagePath . '/results.json';
        file_put_contents($jsonFile, '{"status":"ok","tests_passed":42,"tests_failed":0}');
        $a2 = new JobArtifact();
        $a2->job_id = $job->id;
        $a2->filename = 'results.json';
        $a2->display_name = 'results.json';
        $a2->mime_type = 'application/json';
        $a2->size_bytes = (int)filesize($jsonFile);
        $a2->storage_path = $jsonFile;
        $a2->created_at = time();
        $a2->save(false);

        $this->stdout("  Created job #{$job->id} with 2 artifacts.\n");
    }

    private function createTeardownHelper(): E2eTeardownHelper
    {
        return new E2eTeardownHelper(self::PREFIX, function (string $msg): void {
            $this->stdout($msg);
        });
    }
}
