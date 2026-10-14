<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\WorkflowStepWriteFixtures;

/**
 * The warnings of the per-step endpoints (change.warnings) where branches
 * lead past an approval step, through the URL rules, the controller and the
 * write path.
 *
 * regression: an approval step added after a step whose On Success names
 * the next step answered no warning, while every run that succeeds skips
 * it; an edit of a branch past the approval step, which lets runs start the
 * job step after it without the approval, answered none either.
 */
class WorkflowStepApiWarningsTest extends WebControllerTestCase
{
    use WorkflowStepApiRequests;
    use WorkflowStepWriteFixtures;

    /** WorkflowStepChangeSummary::APPROVAL_OFF_SUCCESS_PATH for the approval step "Approve". */
    private const SKIPPED = 'The approval step "Approve" is not on the success path: runs whose steps all succeed do not '
        . 'wait for it.';

    private User $admin;
    private JobTemplate $template;
    private ApprovalRule $rule;
    private WorkflowTemplate $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('api-warnings-admin', 'admin');
        $this->tokenOf($this->admin);
        $this->template = $this->jobTemplate((int)$this->admin->id);
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        $this->workflow = $this->createWorkflowTemplate((int)$this->admin->id);
    }

    public function testRegressionAnApprovalStepAddedWhereRunsThatSucceedSkipItIsWarnedAbout(): void
    {
        $build = $this->step('Build', 10);
        $deploy = $this->step('Deploy', 20);
        WorkflowStep::updateAll(['on_success_step_id' => $deploy->id], ['id' => $build->id]);

        $added = $this->api('POST', $this->stepsPath((int)$this->workflow->id), [
            'name' => 'Approve',
            'step_type' => 'approval',
            'approval_rule_id' => (int)$this->rule->id,
            'after_step_id' => (int)$build->id,
        ]);

        $this->assertSame([self::SKIPPED], $this->changeOf($added, 201)['warnings'] ?? null);
        $this->assertTrue($this->dataOf($added, 201)['reachable'] ?? null, 'only after Build failed: its On Failure is empty');
    }

    public function testRegressionAnEditOfABranchPastTheApprovalStepIsWarnedAbout(): void
    {
        $build = $this->step('Build', 10);
        $this->namedStep((int)$this->workflow->id, 'Approve', 20, WorkflowStep::TYPE_APPROVAL, null, (int)$this->rule->id);
        $deploy = $this->step('Deploy', 30);
        WorkflowStep::updateAll(['on_failure_step_id' => 0], ['id' => $build->id]);

        $edited = $this->api('PUT', $this->stepPath((int)$this->workflow->id, (int)$build->id), ['on_success_step_id' => (int)$deploy->id]);

        $this->assertSame(
            [
                'Job step "Deploy" now runs before the approval step "Approve": runs start it without waiting for that approval.',
                self::SKIPPED,
            ],
            $this->changeOf($edited)['warnings'] ?? null
        );
    }

    private function step(string $name, int $order): WorkflowStep
    {
        return $this->namedStep((int)$this->workflow->id, $name, $order, WorkflowStep::TYPE_JOB, (int)$this->template->id);
    }
}
