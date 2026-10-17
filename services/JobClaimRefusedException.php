<?php

declare(strict_types=1);

namespace app\services;

use app\components\SurveyDefinition;
use app\models\ApprovalRequest;
use app\models\Inventory;

/**
 * Why a claimed job must not run: it needs an approval it never got
 * (JobClaimGate), or the inventory it was launched with is gone or names no
 * hosts (JobClaimService::resolveInventory()). JobClaimService::claimNextPayload()
 * fails the job before execution with the message as its job log line
 * (JobCompletionService::failBeforeExecution()), job.finished records
 * auditContext(), and the runner gets the next job.
 *
 * By then the claim has committed the job as running and written its
 * job.started entry (JobClaimService::claim()), which names the runner and
 * the job's credentials without decrypting them. So every text says what did
 * not happen: the job did not run, and no credential was decrypted or sent to
 * the runner.
 */
final class JobClaimRefusedException extends \RuntimeException
{
    /** The job's template or inventory has an approval rule, and the job was never approved. */
    public const REASON_NOT_APPROVED = 'not_approved';

    /** The inventory the job was launched with no longer exists. */
    public const REASON_INVENTORY_MISSING = 'inventory_missing';

    /** The job's launch record (its runner payload) names no inventory. */
    public const REASON_INVENTORY_NOT_RECORDED = 'inventory_not_recorded';

    /** The job's static inventory has no content. */
    public const REASON_INVENTORY_EMPTY = 'inventory_empty';

    /** The job's file inventory names no source path. */
    public const REASON_INVENTORY_NO_SOURCE = 'inventory_no_source';

    /**
     * The job log line of a job that needs an approval it never got.
     * sprintf(): what requires it (GATE_JOB_TEMPLATE or GATE_INVENTORY), the rule's ID.
     */
    public const NOT_APPROVED = 'Job aborted before execution: %1$s requires an approval (approval rule #%2$d), '
        . 'and this job was never approved. Launch it again (Re-launch on the job page): the new job waits for '
        . 'approval. The job did not run: no credentials were decrypted or sent to the runner.';

    /** NOT_APPROVED's %1$s when the job template's own rule requires it; sprintf(): the template's ID. */
    public const GATE_JOB_TEMPLATE = 'its job template #%d';

    /** NOT_APPROVED's %1$s when an inventory's rule requires it; sprintf(): the inventory's ID. */
    public const GATE_INVENTORY = 'inventory #%d';

    /** The job log line of a job whose inventory is gone; sprintf(): the inventory's ID. */
    public const INVENTORY_MISSING = 'Job aborted before execution: inventory #%d, which the job was launched with, '
        . 'no longer exists, so its hosts are unknown. Launch the job template again. The job did not run: no '
        . 'credentials were decrypted or sent to the runner.';

    /** The job log line of a job whose launch record names no inventory. */
    public const INVENTORY_NOT_RECORDED = 'Job aborted before execution: its launch record names no inventory, so '
        . 'its hosts are unknown. Launch the job template again. The job did not run: no credentials were decrypted '
        . 'or sent to the runner.';

    /**
     * The job log line of a job whose static inventory has no content.
     * sprintf(): the inventory's ID, its name as SurveyDefinition::quote() gives it.
     */
    public const INVENTORY_EMPTY = 'Job aborted before execution: static inventory #%1$d %2$s has no content, so it '
        . 'names no hosts. Add its hosts, then launch the job template again. The job did not run: no credentials '
        . 'were decrypted or sent to the runner.';

    /**
     * The job log line of a job whose file inventory names no source path.
     * sprintf(): the inventory's ID, its name as SurveyDefinition::quote() gives it.
     */
    public const INVENTORY_NO_SOURCE = 'Job aborted before execution: file inventory #%1$d %2$s names no source path, '
        . 'so its hosts are unknown. Set its source path, then launch the job template again. The job did not run: '
        . 'no credentials were decrypted or sent to the runner.';

    /**
     * @param string $reason one of the REASON_* codes
     * @param array<string, int|string> $context what job.finished records besides claim_refusal
     */
    private function __construct(string $message, public readonly string $reason, private readonly array $context)
    {
        parent::__construct($message);
    }

    /**
     * The job needs an approval and was never approved: approval rule
     * #$ruleId of its job template (ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE)
     * or of inventory #$inventoryId (ApprovalRequest::REQUIRED_BY_INVENTORY).
     *
     * @param int|null $jobTemplateId the job's template; null when it has none
     * @param int|null $inventoryId the inventory whose rule requires the approval; read only when
     *     $requiredBy is ApprovalRequest::REQUIRED_BY_INVENTORY
     * @param array{id: int, status: string}|null $request the job's approval request; null when it has none
     */
    public static function notApproved(
        int $ruleId,
        string $requiredBy,
        ?int $jobTemplateId,
        ?int $inventoryId,
        ?array $request
    ): self {
        $byInventory = $requiredBy === ApprovalRequest::REQUIRED_BY_INVENTORY;
        $gate = $byInventory
            ? sprintf(self::GATE_INVENTORY, (int)$inventoryId)
            : sprintf(self::GATE_JOB_TEMPLATE, (int)$jobTemplateId);
        $context = ['approval_rule_id' => $ruleId, 'required_by' => $requiredBy]
            + ($jobTemplateId === null ? [] : ['job_template_id' => $jobTemplateId])
            + ($byInventory && $inventoryId !== null ? ['inventory_id' => $inventoryId] : [])
            + ($request === null ? [] : [
                'approval_request_id' => $request['id'],
                'approval_request_status' => $request['status'],
            ]);

        return new self(sprintf(self::NOT_APPROVED, $gate, $ruleId), self::REASON_NOT_APPROVED, $context);
    }

    /**
     * The inventory #$inventoryId the job was launched with no longer exists.
     */
    public static function inventoryMissing(int $inventoryId): self
    {
        return new self(
            sprintf(self::INVENTORY_MISSING, $inventoryId),
            self::REASON_INVENTORY_MISSING,
            ['inventory_id' => $inventoryId]
        );
    }

    /**
     * The job's launch record names no inventory.
     */
    public static function inventoryNotRecorded(): self
    {
        return new self(self::INVENTORY_NOT_RECORDED, self::REASON_INVENTORY_NOT_RECORDED, []);
    }

    /**
     * The job's static inventory has no content (NULL, empty or whitespace).
     */
    public static function inventoryEmpty(Inventory $inventory): self
    {
        return self::ofInventory(self::INVENTORY_EMPTY, self::REASON_INVENTORY_EMPTY, $inventory);
    }

    /**
     * The job's file inventory names no source path (NULL, empty or whitespace).
     */
    public static function inventoryWithoutSource(Inventory $inventory): self
    {
        return self::ofInventory(self::INVENTORY_NO_SOURCE, self::REASON_INVENTORY_NO_SOURCE, $inventory);
    }

    /**
     * What job.finished records for the refusal: claim_refusal (the reason)
     * first; for REASON_NOT_APPROVED approval_rule_id and required_by,
     * job_template_id when the job has a template, inventory_id when an
     * inventory's rule requires the approval, approval_request_id and
     * approval_request_status when the job has a request; for the inventory
     * refusals inventory_id, except when the launch record names none.
     *
     * @return array<string, int|string>
     */
    public function auditContext(): array
    {
        return ['claim_refusal' => $this->reason] + $this->context;
    }

    /**
     * $format filled with the inventory's ID and quoted name, for $reason.
     */
    private static function ofInventory(string $format, string $reason, Inventory $inventory): self
    {
        $inventoryId = (int)$inventory->id;

        return new self(
            sprintf($format, $inventoryId, SurveyDefinition::quote((string)$inventory->name)),
            $reason,
            ['inventory_id' => $inventoryId]
        );
    }
}
