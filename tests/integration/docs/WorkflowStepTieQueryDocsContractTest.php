<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\components\WorkflowStepPosition;
use app\components\WorkflowRoute;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowRouteMap;
use app\services\WorkflowStepWriteService;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\ReadsOperatorDocs;
use app\tests\unit\docs\WorkflowStepsUpdateDocsContractTest;

/**
 * The read-only query that docs/updating.md gives for the update from
 * 2.10.1 (workflow steps and waiting runs) is the tie query as released,
 * runs on the schema of this release, and lists what its text says:
 * each group of steps of a live workflow that share a step order, with the
 * steps in the order runs now take them, by ID. The test runs the
 * documented text in the test's transaction, on fixtures of each case, and
 * checks what the section says around it: runs take the steps in that
 * order, an empty branch leads to the twin, and a move renumbers the steps,
 * after which the query no longer lists the workflow.
 *
 * Regression (2.11.0): an empty branch skipped a step that shared its step
 * order with the step it left, and the docs gave no way to find such
 * workflows. The test fails on the docs from before.
 */
class WorkflowStepTieQueryDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;

    /** The tie query as 2.11.0 gives it, whitespace collapsed and its comment left out. */
    private const PLANNED = "SELECT w.id AS workflow_id, w.name AS workflow, s.step_order, COUNT(*) AS steps, "
        . "GROUP_CONCAT(CONCAT('ID ', s.id, ' ', s.name) ORDER BY s.id SEPARATOR ', ') AS steps_in_run_order "
        . "FROM workflow_step s JOIN workflow_template w ON w.id = s.workflow_template_id "
        . "WHERE w.deleted_at IS NULL GROUP BY w.id, w.name, s.step_order HAVING COUNT(*) > 1 ORDER BY w.id, s.step_order;";

    /** The comment the tie query starts with. */
    private const COMMENT = '-- Steps that share a step order, in the order runs now take them';

    /** The columns the query returns, in its order. */
    private const COLUMNS = ['workflow_id', 'workflow', 'step_order', 'steps', 'steps_in_run_order'];

    private int $userId;

    private int $jobTemplateId;

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->createUser('docs-tie-query');
        $user->is_superadmin = true;
        $this->assertTrue($user->save(false));
        $this->userId = (int)$user->id;
        $this->jobTemplateId = (int)$this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        )->id;
    }

    /**
     * The section has the tie query of the plan, and it is read-only.
     */
    public function testTheSectionHasTheTieQueryOfThePlan(): void
    {
        $sql = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/^\s*--.*$/m', '', $this->query())));

        $this->assertSame(self::PLANNED, $sql);
        $this->assertSame(1, preg_match('/^SELECT\b/', $sql), 'The query is a SELECT.');
    }

    /**
     * Regression (2.11.0): each group of steps that share a step order is
     * listed once, its steps in the order of their IDs, whatever their
     * names; workflows without such steps and deleted workflows are not.
     */
    public function testRegressionTheQueryListsEachGroupOfTiedStepsInRunOrder(): void
    {
        $tied = $this->workflow('docs-tie-pair', [['Zeta', 10], ['Alpha', 10], ['Deploy', 20]]);
        $three = $this->workflow('docs-tie-three', [['One', 5], ['Two', 5], ['Three', 5]]);
        $groups = $this->workflow('docs-tie-groups', [['A', 10], ['B', 10], ['C', 20], ['D', 20]]);
        $plain = $this->workflow('docs-tie-none', [['Build', 10], ['Test', 20], ['Deploy', 30]]);
        $deleted = $this->workflow('docs-tie-deleted', [['Build', 10], ['Test', 10]]);
        \Yii::$app->db->createCommand()->update('{{%workflow_template}}', ['deleted_at' => time()], ['id' => $deleted['id']])->execute();

        $rows = $this->rowsOf([$tied['id'], $three['id'], $groups['id'], $plain['id'], $deleted['id']]);

        $this->assertSame(self::COLUMNS, array_keys($rows[0] ?? []));
        $this->assertSame([
            [$tied['id'], 'docs-tie-pair', 10, 2, sprintf('ID %d Zeta, ID %d Alpha', $tied['Zeta'], $tied['Alpha'])],
            [$three['id'], 'docs-tie-three', 5, 3, sprintf('ID %d One, ID %d Two, ID %d Three', $three['One'], $three['Two'], $three['Three'])],
            [$groups['id'], 'docs-tie-groups', 10, 2, sprintf('ID %d A, ID %d B', $groups['A'], $groups['B'])],
            [$groups['id'], 'docs-tie-groups', 20, 2, sprintf('ID %d C, ID %d D', $groups['C'], $groups['D'])],
        ], array_map(static fn (array $row): array => [
            (int)$row['workflow_id'],
            (string)$row['workflow'],
            (int)$row['step_order'],
            (int)$row['steps'],
            (string)$row['steps_in_run_order'],
        ], $rows));
    }

    /**
     * What the section says around the query: runs take steps that share a
     * step order by their IDs, which is the order the query lists, and an
     * empty branch of the first leads to its twin.
     */
    public function testRunsTakeTiedStepsInTheOrderTheQueryLists(): void
    {
        $tied = $this->workflow('docs-tie-run-order', [['Zeta', 10], ['Alpha', 10], ['Deploy', 20]]);
        $template = WorkflowTemplate::findOne($tied['id']);
        $this->assertInstanceOf(WorkflowTemplate::class, $template);

        /** @var list<WorkflowStep> $steps */
        $steps = $template->getSteps()->all();
        $names = array_map(static fn (WorkflowStep $step): string => (string)$step->name, $steps);
        $this->assertSame(['Zeta', 'Alpha', 'Deploy'], $names, 'The run order: by step order, ties by ID.');
        $this->assertSame('Zeta', $template->getStartStep()?->name);
        $map = WorkflowRouteMap::forTemplate($template);
        $zeta = $map->find($tied['Zeta']);
        $this->assertNotNull($zeta);
        $route = $map->route($zeta, WorkflowRouteMap::OUTCOME_SUCCESS);
        $this->assertSame([WorkflowRoute::VIA_NEXT, $tied['Alpha']], [$route?->via, $route?->stepId], 'An empty branch leads to the twin.');

        $section = $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION);
        $this->assertMentions('Runs take a workflow\'s steps by their step order, and steps that share one by their IDs', $section, 'updating.md');
        $this->assertMentions('an empty branch skipped a step that shared its step order with the step it left', $section, 'updating.md');
    }

    /**
     * The fix the section gives: a move renumbers the workflow's steps 10,
     * 20, 30… in their run order, so the query no longer lists it.
     */
    public function testAMoveRenumbersTheStepsSoTheQueryNoLongerListsTheWorkflow(): void
    {
        $tied = $this->workflow('docs-tie-fixed', [['Zeta', 10], ['Alpha', 10], ['Deploy', 20]]);
        $template = WorkflowTemplate::findOne($tied['id']);
        $this->assertInstanceOf(WorkflowTemplate::class, $template);
        $this->assertCount(1, $this->rowsOf([$tied['id']]));

        /** @var WorkflowStepWriteService $writer */
        $writer = \Yii::$app->get('workflowStepWriteService');
        $writer->move($template, $tied['Deploy'], WorkflowStepPosition::up(), $this->userId, WorkflowStepWriteService::SOURCE_WEB);

        $this->assertSame([], $this->rowsOf([$tied['id']]));
        $orders = WorkflowStep::find()->select(['step_order', 'name'])->where(['workflow_template_id' => $tied['id']])
            ->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])->asArray()->all();
        $this->assertSame(
            [['step_order' => 10, 'name' => 'Zeta'], ['step_order' => 20, 'name' => 'Deploy'], ['step_order' => 30, 'name' => 'Alpha']],
            array_map(static fn (array $row): array => ['step_order' => (int)$row['step_order'], 'name' => (string)$row['name']], $orders)
        );
        $this->assertMentions(
            'A step added, moved or removed renumbers the workflow\'s steps 10, 20, 30… in their run order, and the query no longer lists the workflow.',
            $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION),
            'updating.md'
        );
    }

    /**
     * A live workflow named $name with job steps [name, step_order], created
     * in this order, so a later step has the higher ID: the step IDs by
     * name, and the workflow's under 'id'.
     *
     * @param list<array{0: string, 1: int}> $steps
     * @return array<string, int>
     */
    private function workflow(string $name, array $steps): array
    {
        $workflow = $this->createWorkflowTemplate($this->userId);
        $workflow->name = $name;
        $this->assertTrue($workflow->save(false));
        $ids = ['id' => (int)$workflow->id];
        foreach ($steps as [$stepName, $order]) {
            $step = $this->createWorkflowStep((int)$workflow->id, $order, WorkflowStep::TYPE_JOB, $this->jobTemplateId);
            $step->name = $stepName;
            $this->assertTrue($step->save(false));
            $ids[$stepName] = (int)$step->id;
        }

        return $ids;
    }

    /**
     * The rows the documented query returns for the workflows $workflowIds,
     * in its order.
     *
     * @param list<int> $workflowIds
     * @return list<array<string, mixed>>
     */
    private function rowsOf(array $workflowIds): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = \Yii::$app->db->createCommand(rtrim(trim($this->query()), ';'))->queryAll();

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array((int)$row['workflow_id'], $workflowIds, true)
        ));
    }

    /**
     * The tie query, the ```sql block of the section that starts with
     * COMMENT.
     */
    private function query(): string
    {
        preg_match_all('/^```sql\R(.*?)^```/ms', $this->section('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION), $blocks);
        // The tie query, the query of the superadmins and admins
        // (SuperadminQueryDocsContractTest), those of the changes to their
        // accounts (AccountChangesQueryDocsContractTest) and to the accounts
        // of team members (TeamMemberChangesQueryDocsContractTest), that of
        // the role changes (RoleChangesQueryDocsContractTest), that of the
        // git projects whose branch starts with "-" or contains whitespace
        // (GitSyncBehaviourDocsContractTest), that of the accounts that
        // ldap/sync disabled (LdapSyncQueryDocsContractTest) and that of the
        // accounts a sign-in or ldap/sync activated again after something
        // else had deactivated them (LdapDeactivationQueryDocsContractTest).
        $this->assertCount(8, $blocks[1], 'The section has eight queries.');
        $ties = array_values(array_filter($blocks[1], static fn (string $sql): bool => str_starts_with($sql, self::COMMENT)));
        $this->assertCount(1, $ties, 'The section has one tie query.');

        return $ties[0];
    }
}
