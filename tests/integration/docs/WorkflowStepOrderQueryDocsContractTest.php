<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\WorkflowStep;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\ReadsOperatorDocs;
use app\tests\unit\docs\WorkflowStepOrderDocsContractTest;

/**
 * The read-only query that docs/updating.md gives for the update from
 * 2.10.0 (new workflow steps go to the end) runs on the schema of this
 * release and lists what its text says: the job steps that come before the
 * first approval step of their workflow in the step order, and whether
 * each was added after that approval step. The test runs the documented
 * text in the test's transaction, on fixtures of each case.
 *
 * Regression (patch 2.10.1): from 2.3.15 to 2.10.0 a job step added with
 * the Step Order that the Add Step form filled in went in front of the
 * workflow's approval step and ran without that approval, and the docs gave
 * no way to find such workflows. The test fails on the docs from before.
 */
class WorkflowStepOrderQueryDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;

    /** The columns the query returns, in its order; the section explains them. */
    private const COLUMNS = [
        'workflow_id',
        'workflow',
        'step_id',
        'step_order',
        'step',
        'approval_step_order',
        'approval_step',
        'added_after_approval_step',
    ];

    private int $userId;

    private int $jobTemplateId;

    private int $ruleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('docs-step-order')->id;
        $this->jobTemplateId = (int)$this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        )->id;
        $this->ruleId = (int)$this->createApprovalRule($this->userId)->id;
    }

    /**
     * The section has one query: a read-only SELECT that runs and returns
     * the columns the section explains.
     */
    public function testTheSectionHasOneReadOnlyQueryWithTheColumnsItExplains(): void
    {
        $sql = $this->query();
        $this->assertSame(1, preg_match('/^\s*SELECT\b/i', (string)preg_replace('/^\s*--.*$/m', '', $sql)), 'The query is a SELECT.');
        $this->workflow('docs-order-columns', [['Approve', WorkflowStep::TYPE_APPROVAL, 20], ['Deploy', WorkflowStep::TYPE_JOB, 10]]);

        $rows = $this->rows();

        $this->assertNotSame([], $rows);
        $this->assertSame(self::COLUMNS, array_keys($rows[0]));
        $this->assertMentions(
            '`added_after_approval_step` is 1 for a job step that was added after that approval step and still comes before it',
            $this->flat('docs/updating.md', WorkflowStepOrderDocsContractTest::UPDATE_SECTION),
            'updating.md'
        );
    }

    /**
     * Regression (patch 2.10.1): the layout that 2.10.0 left. The approval
     * step came first, then a job step was added with the form's Step Order
     * and went in front of it. The query lists that job step, added after
     * the approval step, and a job step that was in front of the approval
     * step before the approval step was added, which it marks 0.
     */
    public function testRegressionTheQueryListsAJobStepThatWentInFrontOfTheApprovalStep(): void
    {
        $landed = $this->workflow('docs-order-landed', [
            ['Approve', WorkflowStep::TYPE_APPROVAL, 20],
            ['Deploy prod', WorkflowStep::TYPE_JOB, 30],
            // Added last, with the Step Order the form filled in: it went to the top.
            ['DB migrate', WorkflowStep::TYPE_JOB, 10],
        ]);
        $designed = $this->workflow('docs-order-designed', [
            ['Build', WorkflowStep::TYPE_JOB, 10],
            ['Approve', WorkflowStep::TYPE_APPROVAL, 20],
            ['Deploy', WorkflowStep::TYPE_JOB, 30],
        ]);

        $listed = $this->listedSteps([$landed['id'], $designed['id']]);

        $this->assertSame([$landed['DB migrate'], $designed['Build']], array_keys($listed));
        $this->assertSame([
            'workflow_id' => $landed['id'],
            'workflow' => 'docs-order-landed',
            'step_id' => $landed['DB migrate'],
            'step_order' => 10,
            'step' => 'DB migrate',
            'approval_step_order' => 20,
            'approval_step' => 'Approve',
            'added_after_approval_step' => 1,
        ], $listed[$landed['DB migrate']]);
        $this->assertSame(0, $listed[$designed['Build']]['added_after_approval_step'], 'Build was there before the approval step.');
        $this->assertSame('docs-order-designed', $listed[$designed['Build']]['workflow']);
    }

    /**
     * Only job steps before the FIRST approval step count, each once: a job
     * step between two approval steps runs after an approval. Equal orders
     * go by ID, as the renumbering of every change sorts them. Types are
     * compared exactly, as runs compare them: an "APPROVAL" step, which an
     * older version may have stored, gates nothing.
     */
    public function testTheQueryListsOnlyJobStepsBeforeTheFirstApprovalStepEachOnce(): void
    {
        $shouting = $this->workflow('docs-order-shouting-gate', [
            ['Shouting gate', 'APPROVAL', 10],
            ['Deploy', WorkflowStep::TYPE_JOB, 20],
            ['Approve', WorkflowStep::TYPE_APPROVAL, 30],
        ]);
        $two = $this->workflow('docs-order-two-gates', [
            ['Lint', WorkflowStep::TYPE_JOB, 10],
            ['Approve QA', WorkflowStep::TYPE_APPROVAL, 20],
            ['Deploy QA', WorkflowStep::TYPE_JOB, 30],
            ['Approve prod', WorkflowStep::TYPE_APPROVAL, 40],
            ['Deploy prod', WorkflowStep::TYPE_JOB, 50],
            // Added after both approval steps, in front of the second only.
            ['Smoke QA', WorkflowStep::TYPE_JOB, 35],
        ]);
        $tie = $this->workflow('docs-order-tie', [
            ['Before', WorkflowStep::TYPE_JOB, 10],
            ['Approve', WorkflowStep::TYPE_APPROVAL, 10],
            ['After', WorkflowStep::TYPE_JOB, 10],
        ]);

        $listed = $this->listedSteps([$shouting['id'], $two['id'], $tie['id']]);

        $this->assertSame([$shouting['Deploy'], $two['Lint'], $tie['Before']], array_keys($listed));
        $this->assertSame('Approve', $listed[$shouting['Deploy']]['approval_step']);
        $this->assertSame('Approve QA', $listed[$two['Lint']]['approval_step']);
        $this->assertSame(0, $listed[$tie['Before']]['added_after_approval_step']);
    }

    /**
     * Not listed: job steps after the approval step, steps of other types
     * in front of it (compared exactly, as runs compare them: a "JOB" step
     * is no job step), workflows without an approval step, and deleted
     * workflows.
     */
    public function testTheQueryListsNothingElse(): void
    {
        $after = $this->workflow('docs-order-after', [
            ['Approve', WorkflowStep::TYPE_APPROVAL, 10],
            ['Deploy', WorkflowStep::TYPE_JOB, 20],
        ]);
        $others = $this->workflow('docs-order-other-types', [
            ['Approve', WorkflowStep::TYPE_APPROVAL, 30],
            ['Wait', WorkflowStep::TYPE_PAUSE, 10],
            ['Shouting', 'JOB', 20],
        ]);
        $ungated = $this->workflow('docs-order-ungated', [
            ['Deploy', WorkflowStep::TYPE_JOB, 10],
            ['Wait', WorkflowStep::TYPE_PAUSE, 20],
        ]);
        $deleted = $this->workflow('docs-order-deleted', [
            ['Approve', WorkflowStep::TYPE_APPROVAL, 20],
            ['DB migrate', WorkflowStep::TYPE_JOB, 10],
        ]);
        \Yii::$app->db->createCommand()->update('{{%workflow_template}}', ['deleted_at' => time()], ['id' => $deleted['id']])->execute();

        $this->assertSame([], $this->listedSteps([$after['id'], $others['id'], $ungated['id'], $deleted['id']]));
    }

    /**
     * A workflow named $name with $steps [name, type, step_order], created
     * in this order, so a later step has the higher ID: the step IDs by
     * name, and the workflow's under 'id'.
     *
     * @param list<array{0: string, 1: string, 2: int}> $steps
     * @return array<string, int>
     */
    private function workflow(string $name, array $steps): array
    {
        $workflow = $this->createWorkflowTemplate($this->userId);
        $workflow->name = $name;
        $this->assertTrue($workflow->save(false));
        $ids = ['id' => (int)$workflow->id];
        foreach ($steps as [$stepName, $type, $order]) {
            $step = $this->createWorkflowStep(
                (int)$workflow->id,
                $order,
                $type,
                $type === WorkflowStep::TYPE_JOB ? $this->jobTemplateId : null,
                $type === WorkflowStep::TYPE_APPROVAL ? $this->ruleId : null
            );
            $step->name = $stepName;
            $this->assertTrue($step->save(false));
            $ids[$stepName] = (int)$step->id;
        }

        return $ids;
    }

    /**
     * The rows the query lists for the workflows $workflowIds, by step ID,
     * in the query's order, with the IDs, orders and the 0/1 as integers.
     *
     * @param list<int> $workflowIds
     * @return array<int, array<string, int|string>>
     */
    private function listedSteps(array $workflowIds): array
    {
        $listed = [];
        foreach ($this->rows() as $row) {
            if (!in_array((int)$row['workflow_id'], $workflowIds, true)) {
                continue;
            }
            $typed = [];
            foreach ($row as $column => $value) {
                $typed[$column] = in_array($column, ['workflow', 'step', 'approval_step'], true) ? (string)$value : (int)$value;
            }
            $listed[(int)$row['step_id']] = $typed;
        }

        return $listed;
    }

    /**
     * The rows the documented query returns, in its order.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = \Yii::$app->db->createCommand(rtrim(trim($this->query()), ';'))->queryAll();

        return $rows;
    }

    /**
     * The one ```sql block of the section.
     */
    private function query(): string
    {
        preg_match_all('/^```sql\R(.*?)^```/ms', $this->section('docs/updating.md', WorkflowStepOrderDocsContractTest::UPDATE_SECTION), $blocks);
        $this->assertCount(1, $blocks[1], 'The section has one query.');

        return $blocks[1][0];
    }
}
