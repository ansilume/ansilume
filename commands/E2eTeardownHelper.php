<?php

declare(strict_types=1);

namespace app\commands;

use app\models\ApprovalRule;
use app\models\Credential;
use app\models\Inventory;
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

/**
 * Handles teardown of E2E test data.
 *
 * Extracted from E2eController to keep class size manageable.
 */
class E2eTeardownHelper
{
    private string $prefix;

    /** @var callable(string): void */
    private $logger;

    /**
     * @param callable(string): void $logger
     */
    public function __construct(string $prefix, callable $logger)
    {
        $this->prefix = $prefix;
        $this->logger = $logger;
    }

    private function log(string $msg): void
    {
        ($this->logger)($msg);
    }

    public function teardownAll(): void
    {
        $this->teardownEntities();
        $this->teardownUsers();
    }

    private function teardownEntities(): void
    {
        $this->deleteByPrefix(WorkflowStep::class, 'name');
        $this->deleteByPrefix(WorkflowTemplate::class, 'name');
        $this->deleteByPrefix(ApprovalRule::class, 'name');
        $this->deleteByPrefix(Schedule::class, 'name');
        $this->deleteByPrefix(Webhook::class, 'name');
        $this->deleteByPrefix(NotificationTemplate::class, 'name');
        $this->deleteByPrefix(JobTemplate::class, 'name');
        $this->deleteByPrefix(Credential::class, 'name');
        $this->deleteByPrefix(Inventory::class, 'name');
        $this->deleteByPrefix(Project::class, 'name');

        $teams = Team::find()->where(['like', 'name', $this->prefix])->all();
        foreach ($teams as $team) {
            TeamProject::deleteAll(['team_id' => $team->id]);
            TeamMember::deleteAll(['team_id' => $team->id]);
            $team->delete();
            $this->log("  Deleted team '{$team->name}'.\n");
        }

        $this->deleteByPrefix(RunnerGroup::class, 'name');
        $this->teardownCustomRoles();
    }

    private function teardownCustomRoles(): void
    {
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        foreach ($auth->getRoles() as $role) {
            if (str_starts_with($role->name, $this->prefix)) {
                $auth->remove($role);
                $this->log("  Deleted custom role '{$role->name}'.\n");
            }
        }
    }

    private function teardownUsers(): void
    {
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;

        $users = User::find()->where(['like', 'username', $this->prefix])->all();
        $userIds = array_map(fn ($u) => $u->id, $users);

        if ($userIds !== []) {
            $this->teardownJobsByUsers($userIds);
        }

        foreach ($users as $user) {
            $auth->revokeAll($user->id);
            $user->delete();
            $this->log("  Deleted user '{$user->username}'.\n");
        }
    }

    /**
     * @param int[] $userIds
     */
    private function teardownJobsByUsers(array $userIds): void
    {
        $db = \Yii::$app->db;
        $del = fn (string $t, array $w) => $db->createCommand()->delete($t, $w)->execute();
        $col = fn (string $t, array $w) => (new \yii\db\Query())->select('id')->from($t)->where($w)->column($db);

        $jobIds = $col('{{%job}}', ['launched_by' => $userIds]);
        if ($jobIds !== []) {
            $del('{{%workflow_job_step}}', ['job_id' => $jobIds]);
            $requestIds = $col('{{%approval_request}}', ['job_id' => $jobIds]);
            if ($requestIds !== []) {
                $del('{{%approval_decision}}', ['approval_request_id' => $requestIds]);
                $del('{{%approval_request}}', ['id' => $requestIds]);
            }
            foreach (['job_artifact', 'job_host_summary', 'job_task', 'job_log'] as $t) {
                $del('{{%' . $t . '}}', ['job_id' => $jobIds]);
            }
            $del('{{%job}}', ['id' => $jobIds]);
            $this->log("  Cleaned up " . count($jobIds) . " job(s) created by e2e users.\n");
        }

        $wfJobIds = $col('{{%workflow_job}}', ['launched_by' => $userIds]);
        if ($wfJobIds !== []) {
            $del('{{%workflow_job_step}}', ['workflow_job_id' => $wfJobIds]);
            $del('{{%workflow_job}}', ['id' => $wfJobIds]);
            $this->log("  Cleaned up " . count($wfJobIds) . " workflow job(s).\n");
        }
    }

    /**
     * @param class-string<\yii\db\ActiveRecord> $modelClass
     */
    private function deleteByPrefix(string $modelClass, string $column): void
    {
        $models = $modelClass::find()->where(['like', $column, $this->prefix])->all();
        foreach ($models as $model) {
            $name = $model->$column ?? '(unknown)';
            $model->delete();
            $this->log("  Deleted {$modelClass}::'{$name}'.\n");
        }
    }
}
