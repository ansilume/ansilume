<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Every approval request records what required it
 * (approval_request.required_by): ApprovalService::openRequest() takes it,
 * createRequest() defaults to the job template's own rule, and each producer
 * passes its own: a launch the source of its gate (the template's own rule,
 * also when its inventory has the same one, else the inventory's), a
 * workflow approval step its placeholder's (WorkflowStepDispatcher).
 */
class ApprovalRequestRequiredByTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;
    use LaunchGateFixtures;

    private User $launcher;

    private ApprovalRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordNotifications();
        $this->launcher = $this->gateApprover('required-by-launcher');
        $this->rule = $this->listRule((int)$this->launcher->id, [$this->gateApprover('required-by-approver')]);
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function valueProvider(): array
    {
        return [
            'job template' => [ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE],
            'inventory' => [ApprovalRequest::REQUIRED_BY_INVENTORY],
            'approval step' => [ApprovalRequest::REQUIRED_BY_APPROVAL_STEP],
        ];
    }

    /**
     * @dataProvider valueProvider
     */
    public function testOpenRequestStoresWhatRequiredTheRequest(string $requiredBy): void
    {
        $job = $this->waitingJob();

        $request = $this->approvals()->openRequest($job, $this->rule, $requiredBy);

        $this->assertSame($requiredBy, $request->requiredBy());
        $this->assertSame($requiredBy, $this->reloadRequest($request)->requiredBy(), 'as stored');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownProvider(): array
    {
        return [
            'another word' => ['workflow'],
            'a label' => ['Inventory'],
            'padded' => ['job_template '],
            // Regression: the rule skipped an empty value, so '' was saved.
            'empty' => [''],
        ];
    }

    /**
     * A value none of the three is refused like any request that does not
     * validate, and nothing is written.
     *
     * @dataProvider unknownProvider
     */
    public function testAnUnknownValueIsRefused(string $requiredBy): void
    {
        $job = $this->waitingJob();
        $before = (int)ApprovalRequest::find()->count();

        try {
            $this->approvals()->openRequest($job, $this->rule, $requiredBy);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Failed to create approval request: {"required_by":["Required By is invalid."]}', $e->getMessage());
        }

        $this->assertSame($before, (int)ApprovalRequest::find()->count());
    }

    public function testCreateRequestDefaultsToTheJobTemplatesRule(): void
    {
        $request = $this->approvals()->createRequest($this->waitingJob(), $this->rule);

        $this->assertSame(ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, $this->reloadRequest($request)->requiredBy());
        $this->assertSame(ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, $this->requestedContextOf($request)['required_by'] ?? null);
    }

    public function testCreateRequestPassesAValueOnAndAnnouncesIt(): void
    {
        $request = $this->approvals()->createRequest($this->waitingJob(), $this->rule, ApprovalRequest::REQUIRED_BY_INVENTORY);

        $this->assertSame(ApprovalRequest::REQUIRED_BY_INVENTORY, $this->reloadRequest($request)->requiredBy());
        $this->assertSame(ApprovalRequest::REQUIRED_BY_INVENTORY, $this->requestedContextOf($request)['required_by'] ?? null);
    }

    /**
     * What each launch records: the template's own rule wins, also when its
     * inventory has the same rule; the inventory's rule alone is recorded as
     * the inventory's.
     *
     * @return array<string, array{0: bool, 1: bool, 2: string}>
     */
    public static function launchProvider(): array
    {
        return [
            'the template has the rule' => [true, false, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE],
            'its inventory has the rule' => [false, true, ApprovalRequest::REQUIRED_BY_INVENTORY],
            'both have the same rule' => [true, true, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE],
        ];
    }

    /**
     * @dataProvider launchProvider
     */
    public function testALaunchRecordsTheSourceOfItsGate(bool $templateRule, bool $inventoryRule, string $requiredBy): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        if ($templateRule) {
            $template->approval_rule_id = (int)$this->rule->id;
            $template->save(false);
        }
        if ($inventoryRule) {
            $this->gateInventoryOf($template, $this->rule);
        }

        $job = $this->launcherService()->launch($template, (int)$this->launcher->id);

        $request = $this->assertWaitsFor($job, $this->rule, $requiredBy);
        $this->assertSame($requiredBy, $this->requestedContextOf($request)['required_by'] ?? null);
    }

    /**
     * The placeholder job of a workflow approval step holds a request
     * required by the step; a gated job step's job one required by what
     * gates its template, here its inventory.
     */
    public function testAWorkflowRecordsItsApprovalStepAndItsInventoryGatedJobStep(): void
    {
        $launcherId = (int)$this->launcher->id;
        $template = $this->inventoryGatedTemplate($launcherId, $this->rule);
        $workflow = $this->createWorkflowTemplate($launcherId);
        $approval = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$this->rule->id);
        $jobStep = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$template->id);

        $run = $this->workflowEngine()->launch($workflow, $launcherId);

        $placeholder = $this->jobOfStep((int)$run->id, (int)$approval->id);
        $this->assertNull($placeholder->job_template_id);
        $request = $this->assertWaitsFor($placeholder, $this->rule, ApprovalRequest::REQUIRED_BY_APPROVAL_STEP);
        $this->assertSame(
            ['required_by' => ApprovalRequest::REQUIRED_BY_APPROVAL_STEP, 'inventory_id' => null],
            array_intersect_key((array)$this->requestedContextOf($request), ['required_by' => 0, 'inventory_id' => 0])
        );

        $this->approvals()->recordDecision($request, (int)$this->gateApproverOf($this->rule)->id, 'approved');

        $job = $this->jobOfStep((int)$run->id, (int)$jobStep->id);
        $this->assertWaitsFor($job, $this->rule, ApprovalRequest::REQUIRED_BY_INVENTORY);
    }

    // -- Helpers ----------------------------------------------------------------

    private function waitingJob(): Job
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        $job = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_PENDING_APPROVAL);
        $job->queued_at = null;
        $job->save(false);

        return $job;
    }

    private function jobOfStep(int $runId, int $stepId): Job
    {
        $execution = WorkflowJobStep::find()
            ->where(['workflow_job_id' => $runId, 'workflow_step_id' => $stepId])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($execution, 'the step ran');
        $job = Job::findOne($execution->job_id);
        $this->assertNotNull($job, 'the step has its job');

        return $job;
    }

    /**
     * The one approver of $rule (listRule()).
     */
    private function gateApproverOf(ApprovalRule $rule): User
    {
        $ids = $rule->getApproverUserIds();
        $this->assertCount(1, $ids);
        $approver = User::findOne($ids[0]);
        $this->assertNotNull($approver);

        return $approver;
    }
}
