<?php

declare(strict_types=1);

namespace app\tests\integration\services\runstate;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\runstate\ApprovalWaits;
use app\services\runstate\WorkflowRunStates;

/**
 * Fixtures of the run state tests; use in DbTestCase subclasses together
 * with TeamScopeFixtures. Runs, executions, jobs and requests are written
 * as the engine leaves them, by hand, so every state can be built, also
 * those a race or an older version leaves behind.
 */
trait RunStateFixtures
{
    protected function runStates(): WorkflowRunStates
    {
        /** @var WorkflowRunStates $states */
        $states = \Yii::$app->get('workflowRunStates');

        return $states;
    }

    protected function approvalWaits(): ApprovalWaits
    {
        /** @var ApprovalWaits $waits */
        $waits = \Yii::$app->get('approvalWaits');

        return $waits;
    }

    /**
     * A job template in a project of its own, without a team.
     */
    protected function openJobTemplate(int $userId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    protected function namedStep(
        WorkflowTemplate $workflow,
        int $order,
        string $name,
        string $type,
        ?int $jobTemplateId = null,
        ?int $ruleId = null
    ): WorkflowStep {
        $step = $this->createWorkflowStep((int)$workflow->id, $order, $type, $jobTemplateId, $ruleId);
        $step->name = $name;
        $step->save(false);

        return $step;
    }

    /**
     * A job as a launch leaves it; $queuedAt and $createdAt as given (null
     * queued_at: never queued).
     */
    protected function jobOf(?int $templateId, int $launcherId, string $status, ?int $queuedAt = null, int $createdAt = 1760000000): Job
    {
        $job = new Job();
        $job->job_template_id = $templateId;
        $job->launched_by = $launcherId;
        $job->status = $status;
        $job->timeout_minutes = $templateId === null ? 0 : 120;
        $job->has_changes = 0;
        $job->queued_at = $queuedAt;
        $job->save(false);
        // TimestampBehavior stamps the insert with the current time.
        Job::updateAll(['created_at' => $createdAt], ['id' => $job->id]);
        $job->created_at = $createdAt;

        return $job;
    }

    /**
     * An execution of $step in $run; the snapshot is copied from $step
     * unless $snapshotType overrides the type.
     */
    protected function executionOf(
        WorkflowJob $run,
        WorkflowStep $step,
        string $status,
        ?Job $job = null,
        ?string $errorMessage = null,
        ?int $startedAt = 1760000100,
        ?string $snapshotType = null
    ): WorkflowJobStep {
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = $job === null ? null : (int)$job->id;
        $execution->status = $status;
        $execution->error_message = $errorMessage;
        $execution->started_at = $startedAt;
        $execution->finished_at = in_array($status, [WorkflowJobStep::STATUS_RUNNING, WorkflowJobStep::STATUS_PENDING], true)
            ? null
            : 1760000200;
        $execution->snapshotStep($step);
        if ($snapshotType !== null) {
            $execution->step_type = $snapshotType;
        }
        $execution->created_at = time();
        $execution->updated_at = time();
        $execution->save(false);

        return $execution;
    }

    protected function requestOf(
        Job $job,
        ?ApprovalRule $rule,
        string $status = ApprovalRequest::STATUS_PENDING,
        ?int $requestedAt = 1760000150,
        ?int $expiresAt = null,
        ?int $resolvedAt = null
    ): ApprovalRequest {
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = $rule === null ? null : (int)$rule->id;
        $request->status = $status;
        $request->requested_at = $requestedAt;
        $request->expires_at = $expiresAt;
        $request->resolved_at = $resolvedAt;
        $request->save(false);

        return $request;
    }

    /**
     * A rule whose approvers are exactly $approvers.
     *
     * @param list<User> $approvers
     */
    protected function ruleOf(int $createdBy, array $approvers, int $required = 1, ?int $timeoutMinutes = null, string $timeoutAction = ApprovalRule::TIMEOUT_ACTION_REJECT): ApprovalRule
    {
        $rule = $this->createApprovalRule(
            $createdBy,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => array_map(static fn (User $u): int => (int)$u->id, $approvers)]),
            $required
        );
        $rule->timeout_minutes = $timeoutMinutes;
        $rule->timeout_action = $timeoutAction;
        $rule->save(false);

        return $rule;
    }

    protected function voteOn(ApprovalRequest $request, User $voter, string $decision, ?string $comment = null): ApprovalDecision
    {
        $vote = new ApprovalDecision();
        $vote->approval_request_id = (int)$request->id;
        $vote->user_id = (int)$voter->id;
        $vote->decision = $decision;
        $vote->comment = $comment;
        $vote->created_at = time();
        $vote->save(false);

        return $vote;
    }

    protected function namedUser(string $name, ?string $role = null): User
    {
        $user = $role === null ? $this->createUser($name) : $this->createUserWithRole($name, $role);
        $user->username = $name . '-' . substr(md5(uniqid('', true)), 0, 8);
        $user->save(false);

        return $user;
    }
}
