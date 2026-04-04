<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
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
     * Check whether a user is eligible to approve a given request.
     */
    public function canUserApprove(ApprovalRequest $request, int $userId): bool
    {
        if ($request->isResolved()) {
            return false;
        }

        /** @var ApprovalRule|null $rule */
        $rule = $request->approvalRule;
        if ($rule === null) {
            return false;
        }

        $eligible = $rule->getApproverUserIds();
        return in_array($userId, $eligible, true);
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
        $rejections = $request->rejectionCount();
        $required = $rule->required_approvals;
        $eligible = count($rule->getApproverUserIds());

        if ($approvals >= $required) {
            $request->status = ApprovalRequest::STATUS_APPROVED;
            $request->resolved_at = time();
            $request->save(false);

            /** @var Job|null $job */
            $job = $request->job;
            if ($job !== null) {
                $this->approveJob($job);
            }
            return;
        }

        // If remaining possible approvals can't meet threshold, auto-reject
        $remaining = $eligible - $approvals - $rejections;
        if ($approvals + $remaining < $required) {
            $request->status = ApprovalRequest::STATUS_REJECTED;
            $request->resolved_at = time();
            $request->save(false);

            /** @var Job|null $job */
            $job = $request->job;
            if ($job !== null) {
                $this->rejectJob($job);
            }
        }
    }

    private function approveJob(Job $job): void
    {
        // Check if this job belongs to a workflow step
        $wjs = \app\models\WorkflowJobStep::findOne(['job_id' => $job->id]);
        if ($wjs !== null) {
            // Workflow context: let the workflow engine handle advancement
            $job->status = Job::STATUS_QUEUED;
            $job->queued_at = time();
            $job->save(false);
            return;
        }

        $job->status = Job::STATUS_QUEUED;
        $job->queued_at = time();
        $job->save(false);
    }

    private function rejectJob(Job $job): void
    {
        $job->status = Job::STATUS_REJECTED;
        $job->finished_at = time();
        $job->save(false);
    }
}
