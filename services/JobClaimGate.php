<?php

declare(strict_types=1);

namespace app\services;

use app\components\JobInventory;
use app\models\ApprovalRequest;
use app\models\Job;
use yii\db\Transaction;

/**
 * The claim's approval gate (JobClaimService::checkClaimable()): a runner
 * starts a job whose job template or inventory has an approval rule only
 * once the job was approved, its approval request approved or timed out
 * (RUNNABLE_REQUEST_STATUSES; a timeout that rejects rejects the job, which
 * no runner claims). Every job passes the claim, also one that never passed
 * a launch's gate: queued before its template or inventory got a rule, put
 * back in the queue by JOB_RECLAIM_MODE=requeue, or queued by hand. Such a
 * job fails before execution (JobClaimRefusedException::notApproved()), with
 * a hint to launch it again, before any credential is decrypted.
 *
 * What gates the job, the first that has a rule:
 * 1. its job template's own rule (ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE);
 * 2. the rule of the inventory its launch record names, whose hosts the
 *    runner gets (REQUIRED_BY_INVENTORY);
 * 3. the rule of the template's current inventory, which a re-point after
 *    the launch may have gated (REQUIRED_BY_INVENTORY).
 *
 * That is JobTemplateGate's answer (the template's own rule, else its
 * inventory's), plus the inventory the job was launched with. The rules are
 * read from the rows as stored (JobTemplateGate::readInventoryRules()), never
 * through the models' cached schema, so a schema cached from before m000088
 * cannot hide an inventory's rule.
 *
 * It reads without locks, after the claim committed the job as running, in
 * at most 3 statements: the template row, the inventory rows (one statement,
 * none when the template's own rule gates the job), and, only for a gated
 * job, its approval request. They run in one transaction at REPEATABLE READ,
 * so all of them see the snapshot of the first read: an admin who moves a
 * rule in two commits, each of which keeps the template gated (the template
 * takes its inventory's rule, then the inventory loses it; or the template
 * moves to another inventory with the rule, then the one the job was
 * launched with loses it), cannot make the reads miss the rule when both
 * commits land between two of them. The level is set for this transaction,
 * whatever the server's default: at READ COMMITTED each read would see a
 * snapshot of its own (a caller that is inside a transaction already, as no
 * production caller is, gets a savepoint of it, with its level and
 * snapshot). The transaction ends before a refusal is thrown. A rule given
 * after the first read does not stop the job, like any job that is already
 * running.
 */
final class JobClaimGate
{
    /**
     * The statuses of an approval request whose job may run: approved by the
     * votes, or timed out with the rule's approve action.
     */
    public const RUNNABLE_REQUEST_STATUSES = [ApprovalRequest::STATUS_APPROVED, ApprovalRequest::STATUS_TIMED_OUT];

    /** The job template's own rule and its current inventory, as stored. */
    private const TEMPLATE_SQL = 'SELECT [[approval_rule_id]], [[inventory_id]] FROM {{%job_template}} WHERE [[id]] = :id';

    /** The job's approval request; a job has at most one (unique job_id). */
    private const REQUEST_SQL = 'SELECT [[id]], [[status]] FROM {{%approval_request}} WHERE [[job_id]] = :id';

    /**
     * Refuses $job when its job template or an inventory requires an
     * approval that the job does not have; returns otherwise.
     *
     * @param array<string, mixed> $raw the job's decoded runner payload; its extra vars hold the
     *     launch's survey answers
     * @throws JobClaimRefusedException (REASON_NOT_APPROVED)
     */
    public function check(Job $job, #[\SensitiveParameter] array $raw): void
    {
        $templateId = self::idOf($job->job_template_id);
        $payloadInventoryId = JobInventory::idOf($raw);
        $jobId = (int)$job->id;
        /** @var array{gate: array{rule_id: int, required_by: string, inventory_id: int|null}|null, request: array{id: int, status: string}|null} $read */
        $read = \Yii::$app->db->transaction(
            fn (): array => $this->read($templateId, $payloadInventoryId, $jobId),
            Transaction::REPEATABLE_READ
        );
        $gate = $read['gate'];
        if ($gate === null) {
            return;
        }
        $request = $read['request'];
        if ($request !== null && in_array($request['status'], self::RUNNABLE_REQUEST_STATUSES, true)) {
            return;
        }

        throw JobClaimRefusedException::notApproved(
            $gate['rule_id'],
            $gate['required_by'],
            $templateId,
            $gate['inventory_id'],
            $request
        );
    }

    /**
     * What gates the job, and, only when something does, its approval
     * request. check() runs it in its one transaction: the template row is
     * the first read and fixes the snapshot that the others see.
     *
     * @return array{gate: array{rule_id: int, required_by: string, inventory_id: int|null}|null, request: array{id: int, status: string}|null}
     */
    private function read(?int $templateId, ?int $payloadInventoryId, int $jobId): array
    {
        $template = $templateId === null ? null : $this->templateRow($templateId);
        $gate = $this->ownGate($template)
            ?? $this->inventoryGate([$payloadInventoryId, self::idOf($template['inventory_id'] ?? null)]);

        return ['gate' => $gate, 'request' => $gate === null ? null : $this->requestOf($jobId)];
    }

    /**
     * The job template's row as stored, deleted or not; null when it is gone.
     *
     * @return array{approval_rule_id: int|string|null, inventory_id: int|string|null}|null
     */
    private function templateRow(int $templateId): ?array
    {
        /** @var array{approval_rule_id: int|string|null, inventory_id: int|string|null}|false $row */
        $row = \Yii::$app->db->createCommand(self::TEMPLATE_SQL, [':id' => $templateId])->queryOne();

        return $row === false ? null : $row;
    }

    /**
     * The gate of the template's own rule, which comes first; null when it
     * has none (or its row is gone).
     *
     * @param array{approval_rule_id: int|string|null, inventory_id: int|string|null}|null $template
     * @return array{rule_id: int, required_by: string, inventory_id: int|null}|null
     */
    private function ownGate(?array $template): ?array
    {
        $ruleId = self::ruleIdOf($template['approval_rule_id'] ?? null);

        return $ruleId === null
            ? null
            : ['rule_id' => $ruleId, 'required_by' => ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, 'inventory_id' => null];
    }

    /**
     * The gate of the first inventory of $inventoryIds whose row has a rule:
     * the inventory the job was launched with, then the template's current
     * one. One statement for both, none without an ID; a row that is gone
     * has no rule.
     *
     * @param array{0: int|null, 1: int|null} $inventoryIds
     * @return array{rule_id: int, required_by: string, inventory_id: int|null}|null
     */
    private function inventoryGate(array $inventoryIds): ?array
    {
        $ids = array_values(array_filter($inventoryIds, static fn (?int $id): bool => $id !== null));
        $rules = JobTemplateGate::readInventoryRules($ids, false);
        foreach ($ids as $inventoryId) {
            $ruleId = $rules[$inventoryId] ?? null;
            if ($ruleId !== null) {
                return ['rule_id' => $ruleId, 'required_by' => ApprovalRequest::REQUIRED_BY_INVENTORY, 'inventory_id' => $inventoryId];
            }
        }

        return null;
    }

    /**
     * The job's approval request: its ID and status; null when it has none.
     *
     * @return array{id: int, status: string}|null
     */
    private function requestOf(int $jobId): ?array
    {
        /** @var array{id: int|string, status: string|null}|false $row */
        $row = \Yii::$app->db->createCommand(self::REQUEST_SQL, [':id' => $jobId])->queryOne();

        return $row === false ? null : ['id' => (int)$row['id'], 'status' => (string)$row['status']];
    }

    /**
     * A record ID as stored: a positive number; null for anything else.
     */
    private static function idOf(mixed $value): ?int
    {
        $id = is_numeric($value) ? (int)$value : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * An approval rule ID as stored: any number is a rule, as for
     * JobTemplateGate (the foreign key keeps a used rule from being
     * deleted); null for none.
     */
    private static function ruleIdOf(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }
}
