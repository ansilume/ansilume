<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\WorkflowStepPosition;
use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowRouteMap;
use app\services\WorkflowStepChangeSummary;
use app\services\WorkflowStepRouter;
use app\services\WorkflowStepWriteService;
use app\tests\integration\DbTestCase;

/**
 * The warnings of a step change (WorkflowStepChangeSummary, the web's
 * warning flashes and the API's change.warnings) on changes made through
 * the real write path (WorkflowStepWriteService), where branches lead past
 * an approval step.
 *
 * regression: the warnings went by position only. An approval step added
 * or moved after a step whose On Success (or Always) names another step is
 * skipped by every run that succeeds, and an edit of a branch that leads
 * past the approval step lets runs start the job step after it without the
 * approval; no warning said so.
 */
class WorkflowStepChangeWarningsTest extends DbTestCase
{
    use WorkflowStepWriteFixtures;

    private const SKIPPED = 'The approval step "Approve" is not on the success path: runs whose steps all succeed do not '
        . 'wait for it.';

    private User $admin;
    private WorkflowTemplate $workflow;
    private JobTemplate $template;
    private ApprovalRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('change-warnings-admin', 'admin');
        $this->workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->template = $this->jobTemplate((int)$this->admin->id);
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
    }

    /**
     * @return array<string, array{0: array<string, int|null>}>
     */
    public static function skippingBranchProvider(): array
    {
        return [
            'On Success names the next step' => [['on_success_step_id' => -1]],
            'Always names the next step' => [['on_always_step_id' => -1]],
            'On Success names it, On Failure ends the run (the form default)' => [['on_success_step_id' => -1, 'on_failure_step_id' => 0]],
        ];
    }

    /**
     * Steps Build and Deploy; Build's branches $branches, where -1 stands
     * for Deploy. The admin adds an approval step after Build.
     *
     * @dataProvider skippingBranchProvider
     * @param array<string, int|null> $branches
     */
    public function testRegressionAnApprovalStepAddedAfterABranchThatLeadsPastItWarns(array $branches): void
    {
        [$build, $deploy] = [$this->jobStep('Build', 10), $this->jobStep('Deploy', 20)];
        $this->branch($build, array_map(static fn (?int $to): ?int => $to === -1 ? (int)$deploy->id : $to, $branches));

        $change = $this->writer()->add(
            $this->workflow,
            $this->formCommand(
                ['name' => 'Approve', 'step_type' => WorkflowStep::TYPE_APPROVAL, 'approval_rule_id' => (string)$this->rule->id],
                WorkflowStepPosition::WEB_AFTER_PREFIX . $build->id
            ),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_WEB
        );

        $this->assertSame(['Build', 'Approve', 'Deploy'], $this->runOrder((int)$this->workflow->id));
        $this->assertSame([self::SKIPPED], WorkflowStepChangeSummary::of($change)->warnings());
        // What the engine does: a successful Build goes on with Deploy.
        $this->assertSame((int)$deploy->id, (int)$this->router()->route($this->stored($build), true, false)['next']?->id);
        $this->assertNotContains((int)$change->step->id, WorkflowRouteMap::forTemplate($this->workflow)->successPath()['step_ids']);
    }

    public function testRegressionAnApprovalStepMovedAfterABranchThatLeadsPastItWarns(): void
    {
        [$build, $deploy] = [$this->jobStep('Build', 10), $this->jobStep('Deploy', 20)];
        $approve = $this->approvalStep('Approve', 30);
        $this->branch($build, ['on_success_step_id' => (int)$deploy->id, 'on_failure_step_id' => 0]);

        $change = $this->writer()->move(
            $this->workflow,
            (int)$approve->id,
            WorkflowStepPosition::after((int)$build->id),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_WEB
        );

        $this->assertSame(['Build', 'Approve', 'Deploy'], $this->runOrder((int)$this->workflow->id));
        $this->assertSame([self::SKIPPED], WorkflowStepChangeSummary::of($change)->warnings());
        $this->assertFalse(WorkflowRouteMap::forTemplate($this->workflow)->isReachable((int)$approve->id), 'no run reaches it');
    }

    public function testRegressionAnEditOfABranchPastTheApprovalStepWarnsAboutTheJobStepAndTheApprovalStep(): void
    {
        $build = $this->jobStep('Build', 10);
        $this->approvalStep('Approve', 20);
        $deploy = $this->jobStep('Deploy', 30);
        $this->branch($build, ['on_failure_step_id' => 0]);

        $change = $this->writer()->update(
            $this->workflow,
            (int)$build->id,
            $this->formCommand([
                'name' => 'Build',
                'job_template_id' => (string)$this->template->id,
                'on_success_step_id' => (string)$deploy->id,
                'on_failure_step_id' => '0',
                'on_always_step_id' => '',
            ], WorkflowStepPosition::WEB_KEEP, true),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_WEB
        );

        $summary = WorkflowStepChangeSummary::of($change);
        $this->assertSame('Step "Build" saved: On Success changed.', $summary->message());
        $this->assertSame(
            [
                'Job step "Deploy" now runs before the approval step "Approve": runs start it without waiting for that approval.',
                self::SKIPPED,
            ],
            $summary->warnings()
        );
    }

    public function testAnApprovalStepAddedWhereRunsThatSucceedPassItWarnsAboutNothing(): void
    {
        $build = $this->jobStep('Build', 10);
        $this->jobStep('Deploy', 20);

        $change = $this->writer()->add(
            $this->workflow,
            $this->formCommand(
                ['name' => 'Approve', 'step_type' => WorkflowStep::TYPE_APPROVAL, 'approval_rule_id' => (string)$this->rule->id],
                WorkflowStepPosition::WEB_AFTER_PREFIX . $build->id
            ),
            (int)$this->admin->id,
            WorkflowStepWriteService::SOURCE_WEB
        );

        $this->assertSame([], WorkflowStepChangeSummary::of($change)->warnings());
    }

    private function jobStep(string $name, int $order): WorkflowStep
    {
        return $this->namedStep((int)$this->workflow->id, $name, $order, WorkflowStep::TYPE_JOB, (int)$this->template->id);
    }

    private function approvalStep(string $name, int $order): WorkflowStep
    {
        return $this->namedStep((int)$this->workflow->id, $name, $order, WorkflowStep::TYPE_APPROVAL, null, (int)$this->rule->id);
    }

    /**
     * Save $branches (field => value) on $step.
     *
     * @param array<string, int|null> $branches
     */
    private function branch(WorkflowStep $step, array $branches): void
    {
        WorkflowStep::updateAll($branches, ['id' => $step->id]);
    }

    private function stored(WorkflowStep $step): WorkflowStep
    {
        $stored = WorkflowStep::findOne($step->id);
        $this->assertInstanceOf(WorkflowStep::class, $stored);

        return $stored;
    }

    private function router(): WorkflowStepRouter
    {
        /** @var WorkflowStepRouter $router */
        $router = \Yii::$app->get('workflowStepRouter');
        return $router;
    }
}
