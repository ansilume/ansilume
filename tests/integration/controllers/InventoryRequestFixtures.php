<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\JobLaunchService;

/**
 * Fixtures of the page tests of approval requests that an inventory or a
 * job template required: jobs launched by the real launch service, which
 * records the inventory in the job's runner payload (inventory_id and its
 * name at launch) and what required the request (required_by). Use in
 * DbTestCase subclasses.
 *
 * An inventory's rule is written with updateAll(), as an admin's save
 * stores it: the model never mass-assigns approval_rule_id.
 */
trait InventoryRequestFixtures
{
    protected function inventoryNamed(int $createdBy, string $name): Inventory
    {
        $inventory = $this->createInventory($createdBy);
        $inventory->name = $name;
        $inventory->save(false);

        return $inventory;
    }

    /**
     * A job template on $inventory, in $projectId (null: a project of its
     * own, without a team) and a runner group of its own.
     */
    protected function templateOnInventory(Inventory $inventory, int $createdBy, ?int $projectId = null): JobTemplate
    {
        return $this->createJobTemplate(
            $projectId ?? (int)$this->createProject($createdBy)->id,
            (int)$inventory->id,
            (int)$this->createRunnerGroup($createdBy)->id,
            $createdBy
        );
    }

    /**
     * A job launched by $launcher on a template whose new inventory
     * $inventoryName has $rule: it waits, and its request records that the
     * inventory required it.
     *
     * @return array{job: Job, request: ApprovalRequest, template: JobTemplate, inventory: Inventory}
     */
    protected function inventoryGatedRequest(User $launcher, ApprovalRule $rule, string $inventoryName, ?int $projectId = null): array
    {
        $inventory = $this->inventoryNamed((int)$launcher->id, $inventoryName);
        $this->assertSame(1, Inventory::updateAll(['approval_rule_id' => (int)$rule->id], ['id' => $inventory->id]));
        $template = $this->templateOnInventory($inventory, (int)$launcher->id, $projectId);

        return $this->launchedWaiting($template, $launcher, ApprovalRequest::REQUIRED_BY_INVENTORY) + ['template' => $template, 'inventory' => $inventory];
    }

    /**
     * A job launched by $launcher on a template with the rule $rule of its
     * own, on a new inventory $inventoryName without a rule: it waits, and
     * its request records that the job template required it.
     *
     * @return array{job: Job, request: ApprovalRequest, template: JobTemplate, inventory: Inventory}
     */
    protected function templateGatedRequest(User $launcher, ApprovalRule $rule, string $inventoryName, ?int $projectId = null): array
    {
        $inventory = $this->inventoryNamed((int)$launcher->id, $inventoryName);
        $template = $this->templateOnInventory($inventory, (int)$launcher->id, $projectId);
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);

        return $this->launchedWaiting($template, $launcher, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE) + ['template' => $template, 'inventory' => $inventory];
    }

    /**
     * $rounds times: a request that the inventory of its job required and
     * one that its job template required, each of a job $launcher launched
     * with a new inventory of its own (named "cost-…" and "own-rule-…"), on
     * job templates in $projectId: for the tests that count queries.
     */
    protected function inventoryAndTemplateRequests(User $launcher, ApprovalRule $rule, int $projectId, int $rounds): void
    {
        for ($i = 0; $i < $rounds; $i++) {
            $this->inventoryGatedRequest($launcher, $rule, 'cost-' . uniqid('', true), $projectId);
            $this->templateGatedRequest($launcher, $rule, 'own-rule-' . uniqid('', true), $projectId);
        }
    }

    /**
     * Record that a workflow approval step required $request, as the
     * dispatcher of a run records it for the request of its placeholder.
     */
    protected function requiredByApprovalStep(ApprovalRequest $request): ApprovalRequest
    {
        $request->recordRequiredBy(ApprovalRequest::REQUIRED_BY_APPROVAL_STEP);
        $request->save(false);
        $stored = ApprovalRequest::findOne($request->id);
        $this->assertNotNull($stored);
        $this->assertSame(ApprovalRequest::REQUIRED_BY_APPROVAL_STEP, $stored->requiredBy());

        return $stored;
    }

    /**
     * Point $template at $inventory, so that the inventory it pointed at
     * can be deleted (the foreign key restricts deleting an inventory that a
     * job template uses).
     */
    protected function moveTemplateTo(JobTemplate $template, Inventory $inventory): void
    {
        $template->inventory_id = (int)$inventory->id;
        $template->save(false);
    }

    /**
     * Launch $template as $launcher: the job waits for approval, and its
     * pending request records $requiredBy.
     *
     * @return array{job: Job, request: ApprovalRequest}
     */
    private function launchedWaiting(JobTemplate $template, User $launcher, string $requiredBy): array
    {
        /** @var JobLaunchService $launches */
        $launches = \Yii::$app->get('jobLaunchService');
        $job = $launches->launch($template, (int)$launcher->id);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status, 'the job waits for approval');
        $request = ApprovalRequest::findOne(['job_id' => $job->id]);
        $this->assertNotNull($request, 'the job has its approval request');
        $this->assertSame($requiredBy, $request->requiredBy());

        return ['job' => $job, 'request' => $request];
    }
}
