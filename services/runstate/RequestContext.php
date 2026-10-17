<?php

declare(strict_types=1);

namespace app\services\runstate;

use app\components\JobInventory;
use app\models\ApprovalRequest;

/**
 * What an approval request is for: the workflow, step and run of the job
 * that waits for it, or the job's template; what required it, and the
 * inventory the job was launched with. The approval pages and the dashboard
 * show it as "For" (text()) and in the Rule cell (ruleText()), and the API's
 * workflow fields come from origin, its gate fields from gateFields(). Built
 * by ApprovalRequestContexts::forRequests().
 *
 * The workflow is named only to a viewer who may view it: a user can see the
 * request of a gated job step through its job template's project while the
 * workflow stays hidden from them, and such a user learns only the job
 * template. Nothing here tells them that the job belongs to a workflow run.
 */
final class RequestContext
{
    /**
     * text() of a request that neither a workflow the viewer may view nor a
     * job template names: a job whose template was purged, the placeholder
     * of a step whose execution 2.9.0 and older deleted with the step, or a
     * job that no longer exists. The dash the job pages show for a job
     * without a template. Also ruleText() of a request whose rule no longer
     * exists.
     */
    public const UNKNOWN = '—';

    /**
     * @param int $requestId the approval request
     * @param WorkflowOrigin|null $origin the workflow run and step the request's job was created
     *     for; null when the job belongs to no workflow step, or the viewer may not view that workflow
     * @param int|null $jobTemplateId the job's template; null for the placeholder of an approval step,
     *     and when the template was purged
     * @param string|null $jobTemplateName the template's name, also when it was deleted; null without
     *     a template
     * @param bool $jobTemplateDeleted whether the job's template was deleted: soft-deleted, or purged
     *     (the job has no template and is no approval step's placeholder)
     * @param bool $workflowRun whether the request belongs to a workflow run the viewer may view: true
     *     exactly when origin is set, so a hidden run is never revealed, not even as a flag
     * @param string|null $requiredBy what required the request (ApprovalRequest::REQUIRED_BY_VALUES,
     *     ApprovalRequest::requiredBy()); null before m000088
     * @param JobInventory $inventory the inventory the request's job was launched with, as its runner
     *     payload records it; JobInventory::none() for a job without one (the placeholder of an
     *     approval step) and for a job that no longer exists
     */
    public function __construct(
        public readonly int $requestId,
        public readonly ?WorkflowOrigin $origin,
        public readonly ?int $jobTemplateId,
        public readonly ?string $jobTemplateName,
        public readonly bool $jobTemplateDeleted,
        public readonly bool $workflowRun,
        public readonly ?string $requiredBy,
        public readonly JobInventory $inventory,
    ) {
    }

    /**
     * What the request is for, as plain text (encode it for HTML):
     * Texts::FOR_WORKFLOW_STEP with the workflow (Texts::WORKFLOW_DELETED
     * after the name of a deleted one), the step and the run; else
     * Texts::FOR_JOB_TEMPLATE, or Texts::FOR_DELETED_JOB_TEMPLATE, with the
     * job template's name; else UNKNOWN.
     */
    public function text(): string
    {
        $origin = $this->origin;
        if ($origin !== null) {
            $workflow = $origin->workflowDeleted ? $origin->workflowName . ' ' . Texts::WORKFLOW_DELETED : $origin->workflowName;

            return sprintf(Texts::FOR_WORKFLOW_STEP, $workflow, $origin->stepName, $origin->workflowJobId);
        }
        if ($this->jobTemplateName === null) {
            return self::UNKNOWN;
        }

        return sprintf(
            $this->jobTemplateDeleted ? Texts::FOR_DELETED_JOB_TEMPLATE : Texts::FOR_JOB_TEMPLATE,
            $this->jobTemplateName
        );
    }

    /**
     * The Rule cell of the approval lists and the dashboard, as plain text
     * (encode it for HTML): $ruleName, the name of the request's rule; for a
     * request that the inventory of its job required, and whose job names
     * that inventory, Texts::RULE_OF_INVENTORY with the inventory's label
     * (JobInventory::label(): its name at launch, else its current one,
     * else its ID); UNKNOWN when the rule no longer exists ($ruleName null).
     */
    public function ruleText(?string $ruleName): string
    {
        if ($ruleName === null) {
            return self::UNKNOWN;
        }
        if ($this->requiredBy !== ApprovalRequest::REQUIRED_BY_INVENTORY || $this->inventory->id === null) {
            return $ruleName;
        }

        return sprintf(Texts::RULE_OF_INVENTORY, $ruleName, $this->inventory->label());
    }

    /**
     * The gate fields of the API's ApprovalRequest: what required the
     * request (null before m000088), and the ID and name of the inventory
     * its job was launched with (JobInventory::apiFields(): both null for a
     * job without one; the name null when it is unknown).
     *
     * @return array{required_by: string|null, inventory_id: int|null, inventory_name: string|null}
     */
    public function gateFields(): array
    {
        return ['required_by' => $this->requiredBy] + $this->inventory->apiFields();
    }

    /**
     * The workflow fields of the API's ApprovalRequest: the run, the step
     * execution (workflow job step) and the workflow of the request's job,
     * and the step's name when it ran. All null when the request belongs to
     * no workflow step, or the viewer may not view its workflow.
     *
     * @return array{
     *     workflow_job_id: int|null,
     *     workflow_job_step_id: int|null,
     *     workflow_template_id: int|null,
     *     workflow_step_name: string|null
     * }
     */
    public function apiFields(): array
    {
        $origin = $this->origin;

        return [
            'workflow_job_id' => $origin?->workflowJobId,
            'workflow_job_step_id' => $origin?->executionId,
            'workflow_template_id' => $origin?->workflowTemplateId,
            'workflow_step_name' => $origin?->stepName,
        ];
    }
}
