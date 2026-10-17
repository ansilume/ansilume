<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\WorkflowStepPosition;
use app\models\JobTemplate;
use app\models\TeamProject;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowStepChangeRefusedException;
use app\tests\integration\DbTestCase;
use yii\log\Logger;

/**
 * Security rule 12 for job steps gated through their job template's
 * inventory (contract C15): a job step whose job template has no rule of its
 * own, but whose inventory has one, is a gated job step. Only admins
 * (approval-rule.update) add one, give a job step such a job template, or
 * change the steps of a workflow that has one; the refusal names the
 * inventory (GATED_INVENTORY_JOB_STEP_ADD_REFUSED,
 * GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED), after an approval step and a
 * job template's own rule. The own-rule cases stay
 * ApprovalRuleAssignmentPolicyTest's.
 */
class ApprovalRuleAssignmentPolicyInventoryTest extends DbTestCase
{
    use InventoryGatedStepFixtures;
    use WorkflowStepWriteFixtures;

    private ApprovalRuleAssignmentPolicy $policy;

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    private int $ruleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ApprovalRuleAssignmentPolicy();
        $this->s = $this->teamScope();
        $this->ruleId = (int)$this->createApprovalRule((int)$this->s['admin']->id)->id;
    }

    /**
     * The texts keep their wording: the docs, the OpenAPI spec and the E2E
     * specs quote them.
     */
    public function testTheTextsKeepTheirWording(): void
    {
        $this->assertSame(
            "Only admins may add a job step whose job template's inventory has an approval rule: that needs approval-rule.update.",
            ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED
        );
        $this->assertSame(
            "This workflow has a job step whose job template's inventory has an approval rule: only admins may change its steps "
                . '(approval-rule.update).',
            ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function addPathProvider(): array
    {
        return [
            'the policy' => ['policy'],
            'adding a job step' => ['add'],
            'giving a job step that job template' => ['edit'],
        ];
    }

    /**
     * regression: a job step whose job template had no approval rule of its
     * own counted as ungated, though the rule of its inventory gates its
     * job. An operator added such a step to a workflow, or gave an existing
     * job step such a job template, and could approve the job that a run
     * of another user launched. Now the step is gated (403, the inventory's
     * text), and nothing is written.
     *
     * @dataProvider addPathProvider
     */
    public function testRegressionAnOperatorAddedAJobStepWhoseJobTemplateIsGatedThroughItsInventory(string $path): void
    {
        $this->moveOwnToGatedInventory();
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->s['open']->id);
        $before = $this->stepRows((int)$workflow->id);
        $memberId = (int)$this->s['member']->id;

        $refusal = $this->addGatedStep($path, $workflow, $memberId);

        $this->assertNotNull($refusal, 'the change is refused');
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED, $refusal);
        $this->assertSame($before, $this->stepRows((int)$workflow->id), 'nothing is written');
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * An admin adds the same step and gives a job step the same job
     * template.
     *
     * @dataProvider addPathProvider
     */
    public function testAnAdminAddsAJobStepWhoseJobTemplateIsGatedThroughItsInventory(string $path): void
    {
        $this->moveOwnToGatedInventory();
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->s['open']->id);

        $this->assertNull($this->addGatedStep($path, $workflow, (int)$this->s['admin']->id));
        $this->assertCount($path === 'policy' ? 0 : 1, $this->stepAudits((int)$workflow->id));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function changePathProvider(): array
    {
        return [
            'the policy' => ['policy'],
            'adding an ungated step' => ['add'],
            'editing a step' => ['update'],
            'moving a step' => ['move'],
            'removing a step' => ['remove'],
        ];
    }

    /**
     * regression: a workflow whose job step was gated only through the
     * inventory of its job template stayed open to operators, who could
     * edit, move and remove its steps (the API's replacement of all steps
     * is WorkflowStepInventoryGateRaceApiTest's). Now its steps are for
     * admins: 403, the inventory's text, nothing written.
     *
     * @dataProvider changePathProvider
     */
    public function testRegressionAnOperatorChangedTheStepsOfAWorkflowWithAnInventoryGatedJobStep(string $path): void
    {
        $this->moveOwnToGatedInventory();
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->s['open']->id);
        $this->namedStep((int)$workflow->id, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$this->s['own']->id);
        $before = $this->stepRows((int)$workflow->id);

        $refusal = $this->changeSteps($path, $workflow, (int)$this->s['member']->id);

        $this->assertNotNull($refusal, 'the change is refused');
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED, $refusal);
        $this->assertSame($before, $this->stepRows((int)$workflow->id), 'nothing is written');
        $this->assertSame([], $this->stepAudits((int)$workflow->id));

        $this->assertNull($this->changeSteps($path, $workflow, (int)$this->s['admin']->id), 'an admin may');
    }

    /**
     * What a change adds is named before what the workflow has, an approval
     * step before a gated job step, and a job template's own rule before an
     * inventory's.
     */
    public function testTheRefusalNamesWhatTheChangeAddsFirstThenTheJobTemplatesOwnRule(): void
    {
        $memberId = (int)$this->s['member']->id;
        $this->moveOwnToGatedInventory();
        JobTemplate::updateAll(['approval_rule_id' => $this->ruleId], ['id' => $this->s['viewed']->id]);
        $approval = new WorkflowStep();
        $approval->step_type = WorkflowStep::TYPE_APPROVAL;
        $approval->approval_rule_id = $this->ruleId;
        $inventoryGated = $this->createWorkflowWithJobSteps((int)$this->s['admin']->id, (int)$this->s['own']->id);
        $ownGated = $this->createWorkflowWithJobSteps((int)$this->s['admin']->id, (int)$this->s['viewed']->id);
        $both = $this->createWorkflowWithJobSteps((int)$this->s['admin']->id, (int)$this->s['own']->id, (int)$this->s['viewed']->id);
        $withApproval = $this->createWorkflowWithJobSteps((int)$this->s['admin']->id, (int)$this->s['own']->id);
        $this->createWorkflowStep((int)$withApproval->id, 5, WorkflowStep::TYPE_APPROVAL, null, $this->ruleId);
        $own = $this->addedJobStep((int)$this->s['own']->id);
        $viewed = $this->addedJobStep((int)$this->s['viewed']->id);
        $open = $this->addedJobStep((int)$this->s['open']->id);

        $this->assertSame(ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED, $this->policy->stepsAddRefusal($memberId, $inventoryGated, [$own, $approval]));
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED, $this->policy->stepsAddRefusal($memberId, new WorkflowTemplate(), [$own, $viewed]));
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED, $this->policy->stepsAddRefusal($memberId, $ownGated, [$own]));
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED, $this->policy->stepsAddRefusal($memberId, $inventoryGated, [$open]));
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED, $this->policy->workflowStepsChangeRefusal($memberId, $both, false));
        $this->assertSame(ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED, $this->policy->workflowStepsChangeRefusal($memberId, $withApproval, false));
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED, $this->policy->stepsAddRefusal($memberId, $inventoryGated, []), 'replacing its steps with none');
        $this->assertNull($this->policy->stepsAddRefusal((int)$this->s['admin']->id, $both, [$own, $viewed]));
    }

    /**
     * Whether a workflow has a step gated through an inventory is read when
     * asked: an inventory that gets a rule, or a job template moved to an
     * inventory with one, locks the workflows that use it; an inventory
     * that loses its rule unlocks them.
     */
    public function testAnInventoryRuleLocksTheWorkflowsFromThenOn(): void
    {
        $memberId = (int)$this->s['member']->id;
        $inventory = $this->gatedInventory((int)$this->s['admin']->id, $this->ruleId);
        $this->giveInventoryRule((int)$inventory->id, null);
        $this->moveToInventory((int)$this->s['own']->id, (int)$inventory->id);
        $workflow = $this->createWorkflowWithJobSteps((int)$this->s['admin']->id, (int)$this->s['own']->id);
        $this->assertNull($this->policy->workflowStepsChangeRefusal($memberId, $workflow, false));
        $this->assertFalse($this->policy->hasGatedJobStep($workflow));

        $this->giveInventoryRule((int)$inventory->id, $this->ruleId);
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED, $this->policy->workflowStepsChangeRefusal($memberId, $workflow, false));
        $this->assertTrue($this->policy->hasGatedJobStep($workflow));

        $this->giveInventoryRule((int)$inventory->id, null);
        $this->assertNull($this->policy->workflowStepsChangeRefusal($memberId, $workflow, false), 'and the rule removed unlocks them');

        $this->moveToInventory((int)$this->s['own']->id, (int)$this->gatedInventory((int)$this->s['admin']->id, $this->ruleId)->id);
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED,
            $this->policy->workflowStepsChangeRefusal($memberId, $workflow, false),
            'its job template moved to an inventory with a rule'
        );
    }

    /**
     * A job template the user cannot see counts as none, whatever its
     * inventory's rule: the step is refused as not found, and the answer
     * must not tell whether the inventory has a rule. One the user may see
     * but not operate counts, as on its page.
     */
    public function testAJobTemplateOnAGatedInventoryTheUserCannotSeeCountsAsNone(): void
    {
        $memberId = (int)$this->s['member']->id;
        $this->moveToInventory((int)$this->s['foreign']->id, (int)$this->gatedInventory((int)$this->s['admin']->id, $this->ruleId)->id);
        $this->moveToInventory((int)$this->s['viewed']->id, (int)$this->gatedInventory((int)$this->s['admin']->id, $this->ruleId)->id);
        $foreign = $this->addedJobStep((int)$this->s['foreign']->id);
        $viewed = $this->addedJobStep((int)$this->s['viewed']->id);

        $this->assertFalse($this->policy->includesGatedJobStep($memberId, [$foreign]));
        $this->assertNull($this->policy->stepsAddRefusal($memberId, new WorkflowTemplate(), [$foreign]));
        $this->assertTrue($this->policy->includesGatedJobStep($memberId, [$viewed]));
        $this->assertSame(
            ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED,
            $this->policy->stepsAddRefusal($memberId, new WorkflowTemplate(), [$foreign, $viewed])
        );
        $this->assertTrue($this->policy->includesGatedJobStep((int)$this->s['outsider']->id, [$foreign]), 'its own team sees it');
    }

    /**
     * On an installation without team-restricted projects (the visibility
     * filter is null), an operator is refused the same, and nothing fails.
     */
    public function testAnOperatorIsRefusedWithoutAnyTeamRestrictedProject(): void
    {
        $this->moveOwnToGatedInventory();
        TeamProject::deleteAll();
        $workflow = $this->createWorkflowWithJobSteps((int)$this->s['admin']->id, (int)$this->s['own']->id);
        $memberId = (int)$this->s['member']->id;

        $this->assertSame(
            ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED,
            $this->policy->stepsAddRefusal($memberId, new WorkflowTemplate(), [$this->addedJobStep((int)$this->s['own']->id)])
        );
        $this->assertSame(ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED, $this->policy->workflowStepsChangeRefusal($memberId, $workflow, false));
    }

    /**
     * Lock order (C16): a change of a workflow's steps locks the workflow's
     * row first (FOR UPDATE, WorkflowTemplate::lockLive()), as the first
     * statement of its transaction, then reads the gates of the workflow's
     * job steps and of the step it adds, the inventories joined, with plain
     * reads. No statement of the change locks an inventory or a job template
     * row, so it cannot cross a launch (the job template row, then the
     * inventory row) or an inventory save (its own row only).
     */
    public function testAStepChangeLocksTheWorkflowFirstAndReadsTheGatesWithoutLocks(): void
    {
        $workflow = $this->createWorkflowWithJobSteps((int)$this->s['admin']->id, (int)$this->s['own']->id);
        $command = $this->apiCommand(['name' => 'Lint', 'job_template_id' => (int)$this->s['open']->id]);
        $memberId = (int)$this->s['member']->id;

        $statements = $this->statementsOf(fn () => $this->writer()->add($workflow, $command, $memberId, 'api'));

        $savepoint = $this->indexOf($statements, '/^SAVEPOINT /');
        $this->assertMatchesRegularExpression(
            '/^SELECT `deleted_at` FROM `workflow_template` WHERE `id` = ' . (int)$workflow->id . ' FOR UPDATE$/',
            $statements[$savepoint + 1],
            'the workflow\'s row lock is the first statement of the change'
        );
        $gateReads = array_filter($statements, static fn (string $sql, int $index): bool => $index > $savepoint && str_contains($sql, '`gate_inventory`'), ARRAY_FILTER_USE_BOTH);
        $this->assertGreaterThanOrEqual(2, count($gateReads), 'the gates are read again under the lock: the workflow\'s, then the added step\'s');
        $locking = array_values(array_filter($statements, static fn (string $sql): bool => preg_match('/FOR UPDATE|LOCK IN SHARE MODE/', $sql) === 1));
        $this->assertSame([$statements[$savepoint + 1]], $locking, 'the workflow\'s row is the only row the change locks');
        $this->assertSame(['Lint'], array_values(array_diff($this->runOrder((int)$workflow->id), ['step-0'])), 'the step was added');
    }

    /**
     * The step form's choices leave out job templates gated through their
     * inventory too (WorkflowTemplatePage), and a deleted one gates nothing.
     */
    public function testGatedJobTemplateIdsIncludeTheInventoryGatedOnes(): void
    {
        $this->moveOwnToGatedInventory();
        JobTemplate::updateAll(['approval_rule_id' => $this->ruleId], ['id' => $this->s['viewed']->id]);
        $ids = [(int)$this->s['open']->id, (int)$this->s['own']->id, (int)$this->s['viewed']->id];

        $expected = [(int)$this->s['own']->id, (int)$this->s['viewed']->id];
        sort($expected);
        $this->assertSame($expected, ApprovalRuleAssignmentPolicy::gatedJobTemplateIds($ids));

        JobTemplate::updateAll(['deleted_at' => time()], ['id' => $this->s['own']->id]);
        $this->assertSame([(int)$this->s['viewed']->id], ApprovalRuleAssignmentPolicy::gatedJobTemplateIds($ids));
    }

    // -- Helpers -----------------------------------------------------------------------

    /**
     * Give the member's job template own an inventory of its own whose
     * approval rule gates it; it keeps no rule of its own.
     */
    private function moveOwnToGatedInventory(): void
    {
        $this->moveToInventory((int)$this->s['own']->id, (int)$this->gatedInventory((int)$this->s['admin']->id, $this->ruleId)->id);
    }

    /**
     * An unsaved job step of the job template $templateId, as a request
     * submits it.
     */
    private function addedJobStep(int $templateId): WorkflowStep
    {
        $step = new WorkflowStep();
        $step->step_type = WorkflowStep::TYPE_JOB;
        $step->job_template_id = $templateId;

        return $step;
    }

    /**
     * As $userId, give $workflow a job step on the job template own, by
     * $path: what the policy answers, or the write path (adding a step
     * Deploy, or giving Build that job template). The refusal's text, or
     * null when nothing was refused.
     */
    private function addGatedStep(string $path, WorkflowTemplate $workflow, int $userId): ?string
    {
        $own = (int)$this->s['own']->id;
        if ($path === 'policy') {
            return $this->policy->stepsAddRefusal($userId, $workflow, [$this->addedJobStep($own)]);
        }

        return $this->refusalTextOf(fn () => $path === 'add'
            ? $this->writer()->add($workflow, $this->apiCommand(['name' => 'Deploy', 'job_template_id' => $own]), $userId, 'api')
            : $this->writer()->update($workflow, $this->stepIdOf($workflow, 'Build'), $this->apiCommand(['job_template_id' => $own], false), $userId, 'api'));
    }

    /**
     * As $userId, change the steps of $workflow by $path, touching only its
     * ungated step Build: what the policy answers, or the write path. The
     * refusal's text, or null when nothing was refused.
     */
    private function changeSteps(string $path, WorkflowTemplate $workflow, int $userId): ?string
    {
        if ($path === 'policy') {
            return $this->policy->workflowStepsChangeRefusal($userId, $workflow, false);
        }
        $build = $this->stepIdOf($workflow, 'Build');

        return $this->refusalTextOf(fn () => match ($path) {
            'add' => $this->writer()->add($workflow, $this->apiCommand(['name' => 'Lint', 'job_template_id' => (int)$this->s['open']->id]), $userId, 'api'),
            'update' => $this->writer()->update($workflow, $build, $this->apiCommand(['name' => 'Compile'], false), $userId, 'api'),
            'move' => $this->writer()->move($workflow, $build, WorkflowStepPosition::end(), $userId, 'api'),
            default => $this->writer()->remove($workflow, $build, $userId, 'api'),
        });
    }

    /**
     * The text of the 403 that $action is refused with; null when it goes
     * through.
     */
    private function refusalTextOf(callable $action): ?string
    {
        try {
            $action();
        } catch (WorkflowStepChangeRefusedException $e) {
            $this->assertSame(403, $e->status, $e->getMessage());

            return $e->getMessage();
        }

        return null;
    }

    /**
     * The SQL statements $action sends, in order.
     *
     * @return list<string>
     */
    private function statementsOf(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $statements = [];
        foreach ($logger->messages as $message) {
            if (
                in_array($message[2], ['yii\db\Command::query', 'yii\db\Command::execute'], true)
                && $message[1] === Logger::LEVEL_INFO
                && is_string($message[0])
            ) {
                $statements[] = $message[0];
            }
        }

        return $statements;
    }

    /**
     * The index of the first statement that matches $pattern.
     *
     * @param list<string> $statements
     */
    private function indexOf(array $statements, string $pattern): int
    {
        foreach ($statements as $index => $statement) {
            if (preg_match($pattern, $statement) === 1) {
                return $index;
            }
        }
        $this->fail("No statement matches {$pattern}:\n" . implode("\n", $statements));
    }

    private function stepIdOf(WorkflowTemplate $workflow, string $name): int
    {
        $id = WorkflowStep::find()->select('id')->where(['workflow_template_id' => $workflow->id, 'name' => $name])->scalar();
        $this->assertNotFalse($id, $name);

        return (int)$id;
    }
}
