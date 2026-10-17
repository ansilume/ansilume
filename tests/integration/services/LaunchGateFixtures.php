<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\JobLaunchRejectedException;
use app\services\JobLaunchService;

/**
 * Fixtures of the launch tests of job templates gated through their
 * inventory (JobLaunchService, JobTemplateGate); use in DbTestCase
 * subclasses together with TeamScopeFixtures, CancellationFixtures and
 * ApprovalVoteFixtures.
 *
 * Inventory rules are written with updateAll(): the model never mass-assigns
 * approval_rule_id, and the tests change rules behind the back of loaded
 * models on purpose.
 */
trait LaunchGateFixtures
{
    /**
     * Give the inventory of $template the rule $rule (null: none), as an
     * admin's save stores it.
     */
    protected function gateInventoryOf(JobTemplate $template, ?ApprovalRule $rule): void
    {
        $this->setInventoryRule((int)$template->inventory_id, $rule === null ? null : (int)$rule->id);
    }

    protected function setInventoryRule(int $inventoryId, ?int $ruleId): void
    {
        $this->assertSame(1, Inventory::updateAll(['approval_rule_id' => $ruleId], ['id' => $inventoryId]));
    }

    /**
     * A job template of its own project, inventory and runner group whose
     * inventory has $rule, and which has no rule of its own.
     */
    protected function inventoryGatedTemplate(int $userId, ApprovalRule $rule): JobTemplate
    {
        $template = $this->voteTemplate($userId);
        $this->gateInventoryOf($template, $rule);

        return $template;
    }

    /**
     * $job waits for approval by $rule, with its pending request recording
     * $requiredBy; the request.
     */
    protected function assertWaitsFor(Job $job, ApprovalRule $rule, string $requiredBy): ApprovalRequest
    {
        $stored = Job::findOne($job->id);
        $this->assertNotNull($stored);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $stored->status, 'the job waits for approval');
        $this->assertNull($stored->queued_at);
        $request = ApprovalRequest::findOne(['job_id' => $job->id]);
        $this->assertNotNull($request, 'the job has its approval request');
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertSame((int)$rule->id, (int)$request->approval_rule_id);
        $this->assertSame($requiredBy, $request->requiredBy());

        return $request;
    }

    /**
     * $launch() is refused with the message $message() returns (computed
     * afterwards, so old code fails on the launch, not on a constant),
     * unchanged and without a previous exception, and nothing is written:
     * no job, no request, no job.launched or approval.requested entry, no
     * notification.
     *
     * @param callable(): mixed $launch
     * @param callable(): string $message
     */
    protected function assertLaunchRefused(callable $launch, callable $message, RecordingNotificationDispatcher $notifications): void
    {
        $actions = [AuditLog::ACTION_JOB_LAUNCHED, AuditLog::ACTION_APPROVAL_REQUESTED];
        $jobs = (int)Job::find()->count();
        $requests = (int)ApprovalRequest::find()->count();
        $audits = (int)AuditLog::find()->where(['action' => $actions])->count();

        $refusal = null;
        try {
            $launch();
        } catch (JobLaunchRejectedException $e) {
            $refusal = $e;
        }

        $this->assertNotNull($refusal, 'the launch is refused');
        $this->assertSame($message(), $refusal->getMessage());
        $this->assertNull($refusal->getPrevious());
        $this->assertSame($jobs, (int)Job::find()->count(), 'no job is written');
        $this->assertSame($requests, (int)ApprovalRequest::find()->count(), 'no request is written');
        $this->assertSame($audits, (int)AuditLog::find()->where(['action' => $actions])->count());
        $this->assertSame([], $notifications->events);
    }

    /**
     * The approval.requested entry of $request: its context.
     *
     * @return array<string, mixed>|null
     */
    protected function requestedContextOf(ApprovalRequest $request): ?array
    {
        return $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_APPROVAL_REQUESTED, 'approval_request', (int)$request->id));
    }

    /**
     * Run $action with foreign key checks off, to leave a job template on an
     * inventory row that is gone (the foreign key restricts deleting it).
     */
    protected function withoutForeignKeyChecks(callable $action): void
    {
        $db = \Yii::$app->db;
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        try {
            $action();
        } finally {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
        }
    }

    /**
     * Delete the inventory row of $template, which keeps referring to it.
     */
    protected function deleteInventoryOf(JobTemplate $template): void
    {
        $this->withoutForeignKeyChecks(function () use ($template): void {
            $this->assertSame(1, Inventory::deleteAll(['id' => $template->inventory_id]));
        });
    }

    /**
     * Inventory #$id as code that runs before m000088 (or with a schema
     * cached from before it) loads it: a model without approval_rule_id.
     */
    protected function inventoryWithoutRuleColumn(int $id): Inventory
    {
        $legacy = new class () extends Inventory {
            public function attributes(): array
            {
                return array_values(array_diff(parent::attributes(), ['approval_rule_id']));
            }
        };
        /** @var Inventory|null $inventory */
        $inventory = $legacy::findOne($id);
        $this->assertNotNull($inventory);
        $this->assertFalse($inventory->hasAttribute('approval_rule_id'));

        return $inventory;
    }

    /**
     * A user who may approve: an operator (approval.decide).
     */
    protected function gateApprover(string $suffix): User
    {
        return $this->createUserWithRole($suffix, 'operator');
    }

    protected function launcherService(): JobLaunchService
    {
        /** @var JobLaunchService $service */
        $service = \Yii::$app->get('jobLaunchService');

        return $service;
    }
}
