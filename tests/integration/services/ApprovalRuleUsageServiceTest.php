<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\services\ApprovalRuleUsageService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Where an approval rule is used: live job templates, steps of live
 * workflows (of any type) and pending requests whose job still waits. Lists
 * hold only what the viewer may see, totals count everything.
 */
class ApprovalRuleUsageServiceTest extends DbTestCase
{
    use TeamScopeFixtures;

    private ApprovalRuleUsageService $service;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ApprovalRuleUsageService();
        $admin = $this->createUser('rule-usage-admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
    }

    public function testEveryKindOfUseIsFound(): void
    {
        $rule = $this->createApprovalRule($this->adminId);
        $gated = $this->gatedTemplate($rule, 'b-gated');
        $alsoGated = $this->gatedTemplate($rule, 'a-gated');
        $workflow = $this->createWorkflowTemplate($this->adminId);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        // A step of a type older versions saved keeps its rule: it is a reference too.
        $legacy = $this->createWorkflowStep((int)$workflow->id, 1, 'APPROVAL', null, (int)$rule->id);
        $this->pendingRequest($rule, $gated, Job::STATUS_PENDING_APPROVAL);

        $usage = $this->service->forRule($rule, $this->adminId);

        $this->assertTrue($usage->isInUse());
        $this->assertSame((string)$rule->name, $usage->ruleName);
        $this->assertSame(2, $usage->jobTemplateTotal);
        $this->assertSame([(int)$alsoGated->id, (int)$gated->id], array_column($usage->jobTemplates, 'id'), 'by name');
        $this->assertSame([
            'id' => (int)$alsoGated->id,
            'name' => 'a-gated',
            'project_id' => (int)$alsoGated->project_id,
            'project_name' => $alsoGated->project->name,
        ], $usage->jobTemplates[0]);
        $this->assertSame(2, $usage->workflowStepTotal);
        $this->assertSame([
            ['workflow_template_id' => (int)$workflow->id, 'workflow_template_name' => (string)$workflow->name, 'step_id' => (int)$step->id, 'step_name' => (string)$step->name],
            ['workflow_template_id' => (int)$workflow->id, 'workflow_template_name' => (string)$workflow->name, 'step_id' => (int)$legacy->id, 'step_name' => (string)$legacy->name],
        ], $usage->workflowSteps);
        $this->assertSame(1, $usage->pendingRequestCount);
    }

    /**
     * Soft-deleted templates and workflows cannot run (the delete detaches
     * them), a pending request whose job no longer waits decides nothing,
     * and resolved requests are history: none of them is a use.
     */
    public function testWhatCannotRunAnyMoreIsNoUse(): void
    {
        $rule = $this->createApprovalRule($this->adminId);
        $deletedTemplate = $this->gatedTemplate($rule, 'deleted');
        $deletedTemplate->softDelete();
        $deletedWorkflow = $this->createWorkflowTemplate($this->adminId);
        $this->createWorkflowStep((int)$deletedWorkflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $deletedWorkflow->softDelete();
        $other = $this->templateOf();
        $this->pendingRequest($rule, $other, Job::STATUS_CANCELED);
        $this->pendingRequest($rule, $other, Job::STATUS_QUEUED);
        $resolved = $this->pendingRequest($rule, $other, Job::STATUS_SUCCEEDED);
        $resolved->status = ApprovalRequest::STATUS_APPROVED;
        $resolved->save(false);
        $otherRule = $this->createApprovalRule($this->adminId);
        $this->gatedTemplate($otherRule, 'other-rule');
        $this->pendingRequest($otherRule, $other, Job::STATUS_PENDING_APPROVAL);

        $usage = $this->service->forRule($rule, $this->adminId);

        $this->assertFalse($usage->isInUse(), $usage->summary());
        $this->assertSame(0, $usage->jobTemplateTotal);
        $this->assertSame(0, $usage->workflowStepTotal);
        $this->assertSame(0, $usage->pendingRequestCount);
    }

    public function testTheListsHoldOnlyWhatTheViewerMaySee(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule((int)$s['admin']->id);
        foreach (['own', 'foreign', 'open'] as $key) {
            $s[$key]->approval_rule_id = (int)$rule->id;
            $s[$key]->save(false);
        }
        $visible = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['own']->id);
        $visibleStep = $this->createWorkflowStep((int)$visible->id, 5, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $hidden = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['foreign']->id);
        $this->createWorkflowStep((int)$hidden->id, 5, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);

        $usage = $this->service->forRule($rule, (int)$s['member']->id);

        $this->assertSame(3, $usage->jobTemplateTotal);
        $this->assertEqualsCanonicalizing([(int)$s['own']->id, (int)$s['open']->id], array_column($usage->jobTemplates, 'id'));
        $this->assertSame(1, $usage->hiddenJobTemplateCount());
        $this->assertSame(2, $usage->workflowStepTotal);
        $this->assertSame([(int)$visibleStep->id], array_column($usage->workflowSteps, 'step_id'));
        $this->assertSame(1, $usage->hiddenWorkflowStepCount());

        $everything = $this->service->forRule($rule, (int)$s['admin']->id);
        $this->assertCount(3, $everything->jobTemplates, 'admins see every team');
        $this->assertCount(2, $everything->workflowSteps);
    }

    public function testWithoutAViewerNothingIsListedButEverythingCounted(): void
    {
        $rule = $this->createApprovalRule($this->adminId);
        $this->gatedTemplate($rule, 'counted');
        $workflow = $this->createWorkflowTemplate($this->adminId);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);

        $usage = $this->service->forRule($rule, null);

        $this->assertSame([], $usage->jobTemplates);
        $this->assertSame([], $usage->workflowSteps);
        $this->assertSame(1, $usage->jobTemplateTotal);
        $this->assertSame(1, $usage->workflowStepTotal);
        $this->assertTrue($usage->isInUse());
    }

    // -- Helpers ----------------------------------------------------------------

    private function templateOf(): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($this->adminId)->id,
            (int)$this->createInventory($this->adminId)->id,
            (int)$this->createRunnerGroup($this->adminId)->id,
            $this->adminId
        );
    }

    private function gatedTemplate(ApprovalRule $rule, string $name): JobTemplate
    {
        $template = $this->templateOf();
        $template->name = $name;
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);

        return $template;
    }

    private function pendingRequest(ApprovalRule $rule, JobTemplate $template, string $jobStatus): ApprovalRequest
    {
        $job = $this->createJob((int)$template->id, $this->adminId, $jobStatus);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        return $request;
    }
}
