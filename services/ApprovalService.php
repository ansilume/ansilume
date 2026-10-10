<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use yii\base\Component;

/**
 * Manages approval workflows: creating requests, recording decisions,
 * checking thresholds, and processing timeouts.
 */
class ApprovalService extends Component
{
    /**
     * Create an approval request for a job and set it to pending_approval.
     */
    public function createRequest(Job $job, ApprovalRule $rule): ApprovalRequest
    {
        $request = new ApprovalRequest();
        $request->job_id = $job->id;
        $request->approval_rule_id = $rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();

        if ($rule->timeout_minutes !== null) {
            $request->expires_at = time() + ($rule->timeout_minutes * 60);
        }

        if (!$request->save()) {
            throw new \RuntimeException(
                'Failed to create approval request: ' . json_encode($request->errors)
            );
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_APPROVAL_REQUESTED,
            'approval_request',
            $request->id,
            null,
            ['job_id' => $job->id, 'rule_id' => $rule->id, 'rule_name' => $rule->name]
        );

        $this->dispatchApprovalNotification(
            NotificationTemplate::EVENT_APPROVAL_REQUESTED,
            $request,
            $job,
            $rule
        );

        return $request;
    }

    /**
     * Record a user's approval or rejection decision.
     *
     * @return ApprovalDecision The recorded decision.
     * @throws \RuntimeException on failure.
     */
    public function recordDecision(
        ApprovalRequest $request,
        int $userId,
        string $decision,
        ?string $comment = null
    ): ApprovalDecision {
        if ($request->isResolved()) {
            throw new \RuntimeException('Approval request is already resolved.');
        }

        $existing = ApprovalDecision::findOne([
            'approval_request_id' => $request->id,
            'user_id' => $userId,
        ]);
        if ($existing !== null) {
            throw new \RuntimeException('User has already voted on this request.');
        }

        $vote = new ApprovalDecision();
        $vote->approval_request_id = $request->id;
        $vote->user_id = $userId;
        $vote->decision = $decision;
        $vote->comment = $comment;
        $vote->created_at = time();

        if (!$vote->save()) {
            throw new \RuntimeException(
                'Failed to save approval decision: ' . json_encode($vote->errors)
            );
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_APPROVAL_DECIDED,
            'approval_request',
            $request->id,
            $userId,
            ['decision' => $decision, 'job_id' => $request->job_id]
        );

        $this->evaluateThreshold($request);

        return $vote;
    }

    /**
     * Check whether a user may decide on a request: it is still pending and
     * the user is one of its {@see eligibleApproverIds()}.
     */
    public function canUserApprove(ApprovalRequest $request, int $userId): bool
    {
        if ($request->isResolved()) {
            return false;
        }

        /** @var ApprovalRule|null $rule */
        $rule = $request->approvalRule;
        if ($rule === null || !in_array($userId, $rule->getApproverUserIds(), true)) {
            return false;
        }

        // Equal to in_array($userId, eligibleApproverIds()) without checking every approver.
        return $this->access()->canViewApprovalRequest($userId, $request);
    }

    /**
     * The users who may decide on a request: the rule's approvers who may also
     * see it (view access to the job's project or, for a workflow approval
     * step, to the workflow). Team scoping applies to deciding as to viewing.
     *
     * Without team-restricted projects every approver may see every request,
     * so the approvers are returned as they are: checking each one would cost
     * queries per approver on every vote that does not settle the request.
     *
     * @return list<int>
     */
    public function eligibleApproverIds(ApprovalRequest $request): array
    {
        /** @var ApprovalRule|null $rule */
        $rule = $request->approvalRule;
        if ($rule === null) {
            return [];
        }

        $approverIds = array_values(array_unique($rule->getApproverUserIds()));
        if (!$this->projects()->hasRestrictedProjects()) {
            return $approverIds;
        }

        $access = $this->access();
        $eligible = [];
        foreach ($approverIds as $userId) {
            if ($access->canViewApprovalRequest($userId, $request)) {
                $eligible[] = $userId;
            }
        }

        return $eligible;
    }

    /**
     * Process expired approval requests (called by cron).
     *
     * @return int Number of requests processed.
     */
    public function processTimeouts(): int
    {
        $now = time();
        /** @var ApprovalRequest[] $expired */
        $expired = ApprovalRequest::find()
            ->where(['status' => ApprovalRequest::STATUS_PENDING])
            ->andWhere(['<=', 'expires_at', $now])
            ->andWhere(['not', ['expires_at' => null]])
            ->all();

        $count = 0;
        foreach ($expired as $request) {
            $this->applyTimeout($request);
            $count++;
        }

        return $count;
    }

    private function applyTimeout(ApprovalRequest $request): void
    {
        /** @var ApprovalRule|null $rule */
        $rule = $request->approvalRule;
        $action = $rule?->timeout_action ?? ApprovalRule::TIMEOUT_ACTION_REJECT;

        $request->status = ApprovalRequest::STATUS_TIMED_OUT;
        $request->resolved_at = time();
        $request->save(false);

        /** @var Job|null $job */
        $job = $request->job;
        if ($job === null) {
            return;
        }

        if ($action === ApprovalRule::TIMEOUT_ACTION_APPROVE) {
            $this->approveJob($job);
        } else {
            $this->rejectJob($job);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_APPROVAL_TIMED_OUT,
            'approval_request',
            $request->id,
            null,
            ['job_id' => $job->id, 'timeout_action' => $action]
        );
    }

    private function evaluateThreshold(ApprovalRequest $request): void
    {
        /** @var ApprovalRule|null $rule */
        $rule = $request->approvalRule;
        if ($rule === null) {
            return;
        }

        $approvals = $request->approvalCount();
        $required = $rule->required_approvals;

        if ($approvals >= $required) {
            $request->status = ApprovalRequest::STATUS_APPROVED;
            $request->resolved_at = time();
            $request->save(false);

            /** @var Job|null $job */
            $job = $request->job;
            if ($job !== null) {
                $this->approveJob($job);
                $this->dispatchApprovalNotification(
                    NotificationTemplate::EVENT_APPROVAL_APPROVED,
                    $request,
                    $job,
                    $rule
                );
            }
            return;
        }

        // Auto-reject once the approvals still possible can't meet the
        // threshold. Only eligible approvers can still vote: one without
        // access to the request never will.
        if ($approvals + $this->openVoteCount($request) < $required) {
            $request->status = ApprovalRequest::STATUS_REJECTED;
            $request->resolved_at = time();
            $request->save(false);

            /** @var Job|null $job */
            $job = $request->job;
            if ($job !== null) {
                $this->rejectJob($job);
                $this->dispatchApprovalNotification(
                    NotificationTemplate::EVENT_APPROVAL_REJECTED,
                    $request,
                    $job,
                    $rule
                );
            }
        }
    }

    private function dispatchApprovalNotification(
        string $event,
        ApprovalRequest $request,
        Job $job,
        ApprovalRule $rule
    ): void {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');
        $dispatcher->dispatch($event, [
            'approval' => [
                'id' => (string)$request->id,
                'status' => (string)$request->status,
                'rule_id' => (string)$rule->id,
                'rule_name' => (string)$rule->name,
            ],
            'job' => [
                'id' => (string)$job->id,
                'status' => (string)$job->status,
                'template_id' => (string)($job->job_template_id ?? ''),
            ],
        ]);
    }

    private function approveJob(Job $job): void
    {
        $wjs = \app\models\WorkflowJobStep::findOne(['job_id' => $job->id]);

        if ($wjs !== null) {
            // Placeholder job — mark succeeded, never queued for execution
            if ($this->leavePendingApproval($job, ['status' => Job::STATUS_SUCCEEDED, 'finished_at' => time()])) {
                $this->notifyWorkflow($job, true);
            }
            return;
        }
        $this->leavePendingApproval($job, ['status' => Job::STATUS_QUEUED, 'queued_at' => time()]);
    }

    private function rejectJob(Job $job): void
    {
        if (!$this->leavePendingApproval($job, ['status' => Job::STATUS_REJECTED, 'finished_at' => time()])) {
            return;
        }

        $wjs = \app\models\WorkflowJobStep::findOne(['job_id' => $job->id]);
        if ($wjs !== null) {
            $this->notifyWorkflow($job, false);
        }
    }

    /**
     * Apply a decision to the job only while it still waits for approval.
     * A job canceled in the meantime stays canceled: the decision must not
     * queue it again, nor take its workflow down a route a second time.
     * One conditional UPDATE, so a cancel between reading and writing the
     * job cannot be overwritten either.
     *
     * @param array<string, int|string> $attributes
     * @return bool whether the job changed
     */
    private function leavePendingApproval(Job $job, array $attributes): bool
    {
        $attributes['updated_at'] = time();
        $changed = Job::updateAll(
            $attributes,
            ['id' => $job->id, 'status' => Job::STATUS_PENDING_APPROVAL]
        ) === 1;
        $job->refresh();

        return $changed;
    }

    /**
     * Notify the workflow engine that an approval step has been resolved.
     */
    private function notifyWorkflow(Job $job, bool $approved): void
    {
        /** @var WorkflowExecutionService $wfService */
        $wfService = \Yii::$app->get('workflowExecutionService');
        $wfService->onApprovalResolved($job, $approved);
    }

    /**
     * Number of eligible approvers who have not voted on the request yet.
     */
    private function openVoteCount(ApprovalRequest $request): int
    {
        $voted = array_map('intval', ApprovalDecision::find()
            ->select('user_id')
            ->where(['approval_request_id' => $request->id])
            ->column());

        return count(array_diff($this->eligibleApproverIds($request), $voted));
    }

    private function access(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');

        return $checker;
    }

    private function projects(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');

        return $checker;
    }
}
