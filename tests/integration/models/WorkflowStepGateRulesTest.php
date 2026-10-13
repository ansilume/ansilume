<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\WorkflowStep;
use app\tests\integration\DbTestCase;

/**
 * What a workflow step may be saved with: an approval step needs an
 * existing approval rule and has no Always branch, and every branch leads to
 * END_WORKFLOW or to a step of the same workflow.
 */
class WorkflowStepGateRulesTest extends DbTestCase
{
    private int $userId;
    private int $workflowId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('step_rules')->id;
        $this->workflowId = (int)$this->createWorkflowTemplate($this->userId)->id;
    }

    /**
     * Regression: an approval step without an approval rule was accepted
     * (the web form's empty choice too); a run that reached it failed there.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function missingRuleProvider(): array
    {
        return [
            'no rule' => [null],
            'the empty choice of the form' => [''],
        ];
    }

    /**
     * @dataProvider missingRuleProvider
     */
    public function testAnApprovalStepNeedsAnApprovalRule(mixed $ruleId): void
    {
        $step = $this->approvalStep();
        $step->setAttribute('approval_rule_id', $ruleId);

        $this->assertFalse($step->validate());
        $this->assertSame([WorkflowStep::APPROVAL_RULE_REQUIRED], $step->getErrors('approval_rule_id'));
        $this->assertSame('An approval step needs an approval rule.', WorkflowStep::APPROVAL_RULE_REQUIRED);
    }

    /**
     * Regression: an approval step with the ID of a rule that does not exist
     * was accepted on the web.
     */
    public function testTheApprovalRuleMustExist(): void
    {
        $step = $this->approvalStep();
        $step->approval_rule_id = 987654321;

        $this->assertFalse($step->validate());
        $this->assertSame([WorkflowStep::APPROVAL_RULE_NOT_FOUND], $step->getErrors('approval_rule_id'));
        $this->assertSame('The selected approval rule does not exist.', WorkflowStep::APPROVAL_RULE_NOT_FOUND);

        $step->setAttribute('approval_rule_id', 'abc');
        $this->assertFalse($step->validate());
        $this->assertSame(['Approval Rule Id must be an integer.'], $step->getErrors('approval_rule_id'), 'one error per value');

        $step->approval_rule_id = (int)$this->createApprovalRule($this->userId)->id;
        $this->assertTrue($step->validate(), implode(' ', $step->getFirstErrors()));
    }

    /**
     * Only approval steps need a rule; a rule ID that is set must exist
     * whatever the type, as for job templates.
     */
    public function testOtherStepTypesNeedNoRule(): void
    {
        $template = $this->jobTemplateId();
        $job = $this->step(WorkflowStep::TYPE_JOB);
        $job->job_template_id = $template;
        $pause = $this->step(WorkflowStep::TYPE_PAUSE);

        $this->assertTrue($job->validate(), implode(' ', $job->getFirstErrors()));
        $this->assertTrue($pause->validate(), implode(' ', $pause->getFirstErrors()));

        $pause->approval_rule_id = 987654321;
        $this->assertFalse($pause->validate());
        $this->assertSame([WorkflowStep::APPROVAL_RULE_NOT_FOUND], $pause->getErrors('approval_rule_id'));
    }

    /**
     * Regression: an approval step could have an Always branch, which a run
     * also took after a rejection, continuing past the gate.
     *
     * @return array<string, array{0: string}>
     */
    public static function alwaysTargetProvider(): array
    {
        return [
            'another step' => ['step'],
            'the end of the workflow' => ['end'],
        ];
    }

    /**
     * @dataProvider alwaysTargetProvider
     */
    public function testAnApprovalStepHasNoAlwaysBranch(string $target): void
    {
        $next = $this->savedStep(WorkflowStep::TYPE_PAUSE);
        $step = $this->approvalStep((int)$this->createApprovalRule($this->userId)->id);
        $step->on_always_step_id = $target === 'end' ? WorkflowStep::END_WORKFLOW : (int)$next->id;

        $this->assertFalse($step->validate());
        $this->assertSame([WorkflowStep::APPROVAL_ALWAYS_REFUSED], $step->getErrors('on_always_step_id'), 'one error');
        $this->assertSame(
            'An approval step cannot have an Always branch: a rejection would continue past it.',
            WorkflowStep::APPROVAL_ALWAYS_REFUSED
        );

        $step->on_always_step_id = null;
        $step->on_success_step_id = (int)$next->id;
        $step->on_failure_step_id = WorkflowStep::END_WORKFLOW;
        $this->assertTrue($step->validate(), 'success and failure branches stay');
    }

    public function testOtherStepsMayHaveAnAlwaysBranch(): void
    {
        $next = $this->savedStep(WorkflowStep::TYPE_PAUSE);
        $job = $this->step(WorkflowStep::TYPE_JOB);
        $job->job_template_id = $this->jobTemplateId();
        $job->on_always_step_id = (int)$next->id;
        $pause = $this->step(WorkflowStep::TYPE_PAUSE);
        $pause->on_always_step_id = WorkflowStep::END_WORKFLOW;

        $this->assertTrue($job->validate(), implode(' ', $job->getFirstErrors()));
        $this->assertTrue($pause->validate(), implode(' ', $pause->getFirstErrors()));
    }

    /**
     * Regression: a branch could lead to a step of another workflow or to a
     * step that does not exist (a forged add-step form). A run that took it
     * ended there as if the workflow were done.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function outsideTargetProvider(): array
    {
        $cases = [];
        foreach (['on_success_step_id', 'on_failure_step_id', 'on_always_step_id'] as $attribute) {
            $cases["{$attribute}: a step of another workflow"] = [$attribute, 'other workflow'];
            $cases["{$attribute}: a step that does not exist"] = [$attribute, 'unknown'];
        }

        return $cases;
    }

    /**
     * @dataProvider outsideTargetProvider
     */
    public function testABranchCannotLeaveTheWorkflow(string $attribute, string $target): void
    {
        $foreign = $this->createWorkflowStep((int)$this->createWorkflowTemplate($this->userId)->id, 0, WorkflowStep::TYPE_PAUSE);
        $step = $this->step(WorkflowStep::TYPE_PAUSE);
        $step->$attribute = $target === 'other workflow' ? (int)$foreign->id : 987654321;

        $this->assertFalse($step->validate());
        $this->assertSame([WorkflowStep::ROUTE_TARGET_OUTSIDE], $step->getErrors($attribute));
        $this->assertSame('The selected step is not part of this workflow.', WorkflowStep::ROUTE_TARGET_OUTSIDE);
        $this->assertFalse($step->save(), 'nothing is saved');
    }

    public function testABranchMayEndTheWorkflowOrLeadToAStepOfIt(): void
    {
        $earlier = $this->savedStep(WorkflowStep::TYPE_PAUSE);
        $step = $this->step(WorkflowStep::TYPE_PAUSE);
        $step->on_success_step_id = WorkflowStep::END_WORKFLOW;
        $step->on_failure_step_id = (int)$earlier->id;
        $step->on_always_step_id = null;

        $this->assertTrue($step->save(), implode(' ', $step->getFirstErrors()));

        // A loop back to itself, as an on-failure retry, stays allowed.
        $step->on_failure_step_id = (int)$step->id;
        $step->setAttribute('on_success_step_id', '0');
        $this->assertTrue($step->validate(), implode(' ', $step->getFirstErrors()));
    }

    public function testABranchTargetMustBeAnIdFirst(): void
    {
        $step = $this->step(WorkflowStep::TYPE_PAUSE);
        $step->on_success_step_id = -1;

        $this->assertFalse($step->validate());
        $this->assertSame(['On Success Step Id must be no less than 0.'], $step->getErrors('on_success_step_id'), 'one error');
    }

    private function step(string $type): WorkflowStep
    {
        $step = new WorkflowStep();
        $step->workflow_template_id = $this->workflowId;
        $step->name = $type . ' step';
        $step->step_type = $type;
        $step->step_order = 10;

        return $step;
    }

    private function approvalStep(?int $ruleId = null): WorkflowStep
    {
        $step = $this->step(WorkflowStep::TYPE_APPROVAL);
        $step->approval_rule_id = $ruleId;

        return $step;
    }

    private function savedStep(string $type): WorkflowStep
    {
        return $this->createWorkflowStep($this->workflowId, 0, $type);
    }

    private function jobTemplateId(): int
    {
        return (int)$this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        )->id;
    }
}
