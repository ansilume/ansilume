<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\services\WorkflowStepRouteCells;
use app\services\WorkflowStepsTable;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowStepsTable (contract C9): what each row of the steps table of the
 * workflow template page shows, worked out on steps built in memory.
 *
 * Every route cell names where the branch really leads, as the engine routes
 * a run: an empty branch goes on with the next step ("(next)") or, after the
 * last step, ends the run; a branch back to the same or an earlier step is a
 * loop (↺); a branch the step never takes reads "—" with the reason. Steps
 * are named by their position, never by their step_order (10, 20, 30…).
 */
class WorkflowStepsTableTest extends TestCase
{
    use WorkflowStepModels;

    /** The steps around the step under test: P (1), S (2, under test), N (3), L (4). */
    private const P = 1;
    private const S = 2;
    private const N = 3;
    private const L = 4;
    private const GONE = 999;

    public function testTheTextsKeepTheirWording(): void
    {
        $this->assertSame(
            [
                'STEPS_HEADING' => 'Steps',
                'NO_STEPS' => 'No steps defined yet.',
                'COLUMNS' => ['#', 'Name', 'Type', 'Target', 'On Success', 'On Failure', 'Always'],
                'ACTIONS_COLUMN' => 'Actions',
                'POSITION_TITLE' => 'Position: runs start with step 1.',
                'START_BADGE' => 'Start',
                'START_TITLE' => 'Runs start with this step.',
                'UNREACHABLE_BADGE' => 'unreachable',
                'UNREACHABLE_TITLE' => 'No branch that a run takes leads to this step, so runs never start it.',
                'GATED_BADGE' => 'needs approval',
                'GATED_TITLE' => 'Its job template has the approval rule "%s": the job waits for an approval before it runs.',
                'GATED_INVENTORY_TITLE' => 'Its job template\'s inventory has the approval rule "%s": the job waits for an approval '
                    . 'before it runs.',
                'STEP_LABEL' => '%1$d. %2$s',
                'STEP_REFERENCE' => 'step %1$d "%2$s"',
                'APPROVAL_STEP_SUFFIX' => ' (approval step)',
                'ROUTE_TO' => '→ %s',
                'ROUTE_TO_NEXT' => '→ %s (next)',
                'ROUTE_LOOP' => '↺ %s',
                'ROUTE_UNUSED' => '—',
                'ROUTE_MISSING' => 'missing step (ID %d) · run fails',
                'ROUTE_END_SUCCEEDS' => 'end · run succeeds',
                'ROUTE_END_FAILS' => 'end · run fails',
                'ROUTE_END_WITH_STEP' => 'end · run succeeds or fails with this step',
                'ALSO_AFTER_FAILURE' => ', also after a failure',
                'ALSO_AFTER_FAILED_JOB' => ', also after a failed job',
                'AFTER_FAILED_JOB' => ' after a failed job',
                'AFTER_REFUSED_APPROVAL' => ' after a refused approval',
                'PART_SEPARATOR' => '; ',
                'APPROVAL_ALWAYS_NOTE' => '(only on approval)',
                'GATED_JOB_ALWAYS_NOTE' => '(not after a rejected, timed-out or canceled approval)',
                'PAUSE_ALWAYS_NOTE' => '(on Resume)',
                'NEXT_TITLE' => 'This branch is empty: the run goes on with the next step in the list, so where it leads '
                    . 'changes when steps move.',
                'LOOP_TITLE' => 'Leads back to this step or an earlier one: the run repeats steps until a branch leads elsewhere.',
                'ALWAYS_WINS_TITLE' => 'Not used: the Always branch is taken instead.',
                'PAUSE_FAILURE_TITLE' => 'Not used: a pause step does not fail; Resume continues with On Success.',
                'CANNOT_RUN_TITLE' => 'Not used: this step cannot run, so a run fails when it reaches it.',
                'MISSING_TITLE' => 'The step this branch leads to no longer exists: the run fails here. Edit the step to choose '
                    . 'another one.',
                'REFUSED_APPROVAL_TITLE' => 'A refused approval was rejected, timed out or canceled: the job never ran, only On '
                    . 'Failure is followed, and Always is not.',
                'NO_TARGET' => '—',
                'TARGET_TEMPLATE_GONE' => 'job template ID %d no longer exists · runs fail here',
                'TARGET_TEMPLATE_NONE' => 'no job template · runs fail here',
                'TARGET_RULE_GONE' => 'approval rule ID %d no longer exists · runs fail here',
                'TARGET_RULE_NONE' => 'no approval rule · runs fail here',
                'TARGET_RULES_DIFFER' => 'job template and inventory have different approval rules · runs fail here',
                'TARGET_UNKNOWN_TYPE' => 'unknown step type · runs fail here',
                'SUCCESS_PATH_LABEL' => 'Success path:',
                'SUCCESS_PATH_SEPARATOR' => ' → ',
                'SUCCESS_PATH_CANNOT_RUN' => '%s cannot run · run fails',
                'SUCCESS_PATH_LOOP_NOTE' => 'When every step succeeds, the run repeats these steps until one fails or the run is '
                    . 'canceled.',
                'LEGEND' => 'A run starts with step 1. An empty branch goes on with the next step in the list, marked (next), and '
                    . 'changes when steps move. ↺ marks a branch back to the same or an earlier step.',
                'EDIT' => 'Edit',
                'EDIT_LABEL' => 'Edit %s',
                'INSERT_BELOW' => '+ Insert below',
                'INSERT_BELOW_LABEL' => 'Insert a step below %s',
                'MOVE_UP_BUTTON' => '▲',
                'MOVE_DOWN_BUTTON' => '▼',
                'MOVE_UP_TITLE' => 'Move up',
                'MOVE_DOWN_TITLE' => 'Move down',
                'MOVE_UP_LABEL' => 'Move %s up',
                'MOVE_DOWN_LABEL' => 'Move %s down',
                'MOVE_TO' => 'Move to…',
                'MOVE_TO_LABEL' => 'Move %s to another position',
                'MOVE_MENU_LIMIT' => 20,
                'MOVE_TO_EDIT_PAGE_TITLE' => 'Choose the place on the page of the step: its Position field says what each place '
                    . 'would change.',
                'MOVE_TO_START' => 'To the start',
                'MOVE_AFTER' => 'After %s',
                'MOVE_TO_END' => 'To the end',
                'REMOVE' => 'Remove',
                'REMOVE_LABEL' => 'Remove %s',
                'REMOVE_CONFIRM' => 'Remove %s?',
                'WILL_START' => 'Runs will start with "%1$s" instead of "%2$s".',
                'WILL_PRECEDE_APPROVAL' => 'Job step "%1$s" will run before the approval step "%2$s", without waiting for it.',
                'WILL_SKIP_APPROVAL' => 'The approval step "%s" will not be on the success path: '
                    . 'runs whose steps all succeed will not wait for it.',
            ],
            (new \ReflectionClass(WorkflowStepsTable::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC)
        );
        $this->assertSame(
            ['next', 'step', 'loop', 'end', 'missing', 'unused', 'mixed'],
            [
                WorkflowStepRouteCells::KIND_NEXT,
                WorkflowStepRouteCells::KIND_STEP,
                WorkflowStepRouteCells::KIND_LOOP,
                WorkflowStepRouteCells::KIND_END,
                WorkflowStepRouteCells::KIND_MISSING,
                WorkflowStepRouteCells::KIND_UNUSED,
                WorkflowStepRouteCells::KIND_MIXED,
            ]
        );
    }

    // -- Route cells --------------------------------------------------------------------

    /**
     * @return array<string, array{0: array{success?: int|null, failure?: int|null, always?: int|null}, 1: array<string, array{0: string, 1: string, 2: list<string|null>}>}>
     */
    public static function jobStepProvider(): array
    {
        $next = WorkflowStepsTable::NEXT_TITLE;
        $loop = WorkflowStepsTable::LOOP_TITLE;
        $wins = WorkflowStepsTable::ALWAYS_WINS_TITLE;

        return [
            'empty branches go on with the next step, also after a failure' => [[], [
                'success' => ['next', '→ 3. N (next)', [$next]],
                'failure' => ['next', '→ 3. N (next), also after a failure', [$next]],
                'always' => ['unused', '—', [null]],
            ]],
            'an explicit end' => [['success' => 0, 'failure' => 0], [
                'success' => ['end', 'end · run succeeds', [null]],
                'failure' => ['end', 'end · run fails', [null]],
            ]],
            'an empty On Failure when On Success ends the run' => [['success' => 0], [
                'failure' => ['next', '→ 3. N (next)', [$next]],
            ]],
            'an empty On Failure that leads where an explicit On Success leads' => [['success' => self::N], [
                'success' => ['step', '→ 3. N', [null]],
                'failure' => ['next', '→ 3. N (next), also after a failure', [$next]],
            ]],
            'an empty On Failure when On Success leads elsewhere' => [['success' => self::L], [
                'success' => ['step', '→ 4. L', [null]],
                'failure' => ['next', '→ 3. N (next)', [$next]],
            ]],
            'a later step' => [['failure' => self::L], [
                'failure' => ['step', '→ 4. L', [null]],
            ]],
            'an earlier step loops' => [['failure' => self::P], [
                'failure' => ['loop', '↺ 1. P', [$loop]],
            ]],
            'the step itself loops' => [['failure' => self::S], [
                'failure' => ['loop', '↺ 2. S', [$loop]],
            ]],
            'a step that no longer exists' => [['success' => self::GONE], [
                'success' => ['missing', 'missing step (ID 999) · run fails', [WorkflowStepsTable::MISSING_TITLE]],
            ]],
            'Always wins over On Success and On Failure' => [['success' => 0, 'failure' => self::L, 'always' => self::P], [
                'success' => ['unused', '—', [$wins]],
                'failure' => ['unused', '—', [$wins]],
                'always' => ['loop', '↺ 1. P', [$loop]],
            ]],
            'an Always that ends the run ends it with the step' => [['always' => 0], [
                'always' => ['end', 'end · run succeeds or fails with this step', [null]],
            ]],
            'an Always to a later step' => [['always' => self::L], [
                'always' => ['step', '→ 4. L', [null]],
            ]],
        ];
    }

    /**
     * @dataProvider jobStepProvider
     * @param array{success?: int|null, failure?: int|null, always?: int|null} $routes
     * @param array<string, array{0: string, 1: string, 2: list<string|null>}> $expected
     */
    public function testAJobStepsCellsNameWhereEachBranchLeads(array $routes, array $expected): void
    {
        $this->assertCells($expected, self::job(self::S, 'S', $routes));
    }

    /**
     * A job step's empty On Failure says that the run goes on after a
     * failure (the regression is WorkflowTemplateStepsTableViewTest's).
     */
    public function testAJobStepsEmptyOnFailureSaysTheRunGoesOnAfterAFailure(): void
    {
        $cells = $this->cellsOf(self::job(self::S, 'S'));

        $this->assertSame('→ 3. N (next), also after a failure', $cells['failure'][1]);
    }

    /**
     * The last step's empty branches end the run (the regression is
     * WorkflowTemplateStepsTableViewTest's).
     */
    public function testTheLastStepsEmptyBranchesEndTheRun(): void
    {
        $table = self::table([self::job(self::P, 'P'), self::job(self::S, 'Last')]);

        $this->assertSame(
            ['success' => ['end', 'end · run succeeds', [null]], 'failure' => ['end', 'end · run fails', [null]], 'always' => ['unused', '—', [null]]],
            self::flatCells($table->rows()[1]['routes'])
        );
    }

    /**
     * @return array<string, array{0: array{success?: int|null, failure?: int|null, always?: int|null}, 1: array<string, array{0: string, 1: string, 2: list<string|null>}>}>
     */
    public static function gatedJobStepProvider(): array
    {
        $next = WorkflowStepsTable::NEXT_TITLE;
        $refused = WorkflowStepsTable::REFUSED_APPROVAL_TITLE;

        return [
            'a failed job goes on with the next step as a success does, a refused approval ends the run' => [[], [
                'success' => ['next', '→ 3. N (next)', [$next]],
                'failure' => [
                    'mixed',
                    '→ 3. N (next), also after a failed job; end · run fails after a refused approval',
                    [$next, $refused],
                ],
            ]],
            'a failed job goes on with the next step, a success ends the run' => [['success' => 0], [
                'failure' => ['mixed', '→ 3. N (next) after a failed job; end · run fails after a refused approval', [$next, $refused]],
            ]],
            'an explicit end is one label' => [['failure' => 0], [
                'failure' => ['end', 'end · run fails', [null]],
            ]],
            'an explicit step is one label' => [['failure' => self::L], [
                'failure' => ['step', '→ 4. L', [null]],
            ]],
            'an explicit loop is one label' => [['failure' => self::P], [
                'failure' => ['loop', '↺ 1. P', [WorkflowStepsTable::LOOP_TITLE]],
            ]],
            'Always: only a refused approval follows On Failure' => [['always' => self::N], [
                'success' => ['unused', '—', [WorkflowStepsTable::ALWAYS_WINS_TITLE]],
                'failure' => ['end', 'end · run fails after a refused approval', [$refused]],
                'always' => ['step', '→ 3. N ' . WorkflowStepsTable::GATED_JOB_ALWAYS_NOTE, [null]],
            ]],
            'Always and an On Failure for the refused approval' => [['always' => self::N, 'failure' => self::L], [
                'failure' => ['step', '→ 4. L after a refused approval', [$refused]],
            ]],
        ];
    }

    /**
     * @dataProvider gatedJobStepProvider
     * @param array{success?: int|null, failure?: int|null, always?: int|null} $routes
     * @param array<string, array{0: string, 1: string, 2: list<string|null>}> $expected
     */
    public function testAGatedJobStepsOnFailureSaysWhatAFailedJobAndARefusedApprovalDo(array $routes, array $expected): void
    {
        $this->assertCells($expected, self::job(self::S, 'S', $routes, 'Change board'));
    }

    /**
     * A job step gated by the rule of its job template's inventory (C15)
     * routes as any gated job step: a refused approval follows only On
     * Failure, and Always only after its job ran.
     *
     * @dataProvider gatedJobStepProvider
     * @param array{success?: int|null, failure?: int|null, always?: int|null} $routes
     * @param array<string, array{0: string, 1: string, 2: list<string|null>}> $expected
     */
    public function testAJobStepGatedByItsInventoryRoutesAsAGatedJobStep(array $routes, array $expected): void
    {
        $this->assertCells($expected, self::job(self::S, 'S', $routes, null, 'Prod hosts'));
    }

    public function testAGatedLastStepsEmptyOnFailureEndsTheRunEitherWay(): void
    {
        $table = self::table([self::job(self::P, 'P'), self::job(self::S, 'Last', [], 'Change board')]);

        $this->assertSame(['end', 'end · run fails', [null]], self::flatCells($table->rows()[1]['routes'])['failure']);
    }

    /**
     * @return array<string, array{0: array{success?: int|null, failure?: int|null, always?: int|null}, 1: array<string, array{0: string, 1: string, 2: list<string|null>}>}>
     */
    public static function approvalStepProvider(): array
    {
        return [
            'an empty On Failure ends the run as failed' => [[], [
                'success' => ['next', '→ 3. N (next)', [WorkflowStepsTable::NEXT_TITLE]],
                'failure' => ['end', 'end · run fails', [null]],
                'always' => ['unused', '—', [null]],
            ]],
            'so does 0' => [['failure' => 0], [
                'failure' => ['end', 'end · run fails', [null]],
            ]],
            'an On Failure to an earlier step' => [['failure' => self::P], [
                'failure' => ['loop', '↺ 1. P', [WorkflowStepsTable::LOOP_TITLE]],
            ]],
            'an On Failure to a later step' => [['failure' => self::L], [
                'failure' => ['step', '→ 4. L', [null]],
            ]],
            'an Always from an older version is taken on approval' => [['success' => self::L, 'always' => self::N], [
                'success' => ['unused', '—', [WorkflowStepsTable::ALWAYS_WINS_TITLE]],
                'always' => ['step', '→ 3. N ' . WorkflowStepsTable::APPROVAL_ALWAYS_NOTE, [null]],
            ]],
            'an Always that ends the run ends it as succeeded' => [['always' => 0], [
                'always' => ['end', 'end · run succeeds ' . WorkflowStepsTable::APPROVAL_ALWAYS_NOTE, [null]],
            ]],
        ];
    }

    /**
     * @dataProvider approvalStepProvider
     * @param array{success?: int|null, failure?: int|null, always?: int|null} $routes
     * @param array<string, array{0: string, 1: string, 2: list<string|null>}> $expected
     */
    public function testAnApprovalStepsOnFailureIsItsClosedGate(array $routes, array $expected): void
    {
        $this->assertCells($expected, self::approval(self::S, 'S', $routes));
    }

    /**
     * @return array<string, array{0: array{success?: int|null, failure?: int|null, always?: int|null}, 1: array<string, array{0: string, 1: string, 2: list<string|null>}>}>
     */
    public static function pauseStepProvider(): array
    {
        $unused = ['unused', '—', [WorkflowStepsTable::PAUSE_FAILURE_TITLE]];

        return [
            'Resume goes on with the next step; On Failure is not used' => [[], [
                'success' => ['next', '→ 3. N (next)', [WorkflowStepsTable::NEXT_TITLE]],
                'failure' => $unused,
                'always' => ['unused', '—', [null]],
            ]],
            'a stored On Failure is not used either' => [['failure' => self::L], ['failure' => $unused]],
            'an Always from an older version is taken on Resume' => [['always' => self::N], [
                'success' => ['unused', '—', [WorkflowStepsTable::ALWAYS_WINS_TITLE]],
                'failure' => $unused,
                'always' => ['step', '→ 3. N ' . WorkflowStepsTable::PAUSE_ALWAYS_NOTE, [null]],
            ]],
        ];
    }

    /**
     * @dataProvider pauseStepProvider
     * @param array{success?: int|null, failure?: int|null, always?: int|null} $routes
     * @param array<string, array{0: string, 1: string, 2: list<string|null>}> $expected
     */
    public function testAPauseStepShowsNoOnFailureItNeverUses(array $routes, array $expected): void
    {
        $this->assertCells($expected, self::pause(self::S, 'S', $routes));
    }

    /**
     * @return array<string, array{0: WorkflowStep}>
     */
    public static function cannotRunProvider(): array
    {
        $gone = new JobTemplate();
        $gone->id = 55;
        $gone->name = 'gated';
        $gone->approval_rule_id = 66;
        $gone->populateRelation('approvalRule', null);

        return [
            'a job step whose job template was deleted' => [self::stepModel(self::S, 'S', 'job', ['success' => self::L], null, null, 12)],
            'an approval step without its rule' => [self::stepModel(self::S, 'S', 'approval', ['failure' => self::L], null, null, null, 9)],
            'a gated job step whose rule is gone' => [self::stepModel(self::S, 'S', 'job', ['always' => self::L], $gone, null, 55)],
            'an unknown type, compared exactly' => [self::stepModel(self::S, 'S', 'JOB', ['success' => self::L])],
        ];
    }

    /**
     * @dataProvider cannotRunProvider
     */
    public function testAStepThatCannotRunUsesNoBranch(WorkflowStep $step): void
    {
        $unused = ['unused', '—', [WorkflowStepsTable::CANNOT_RUN_TITLE]];

        $this->assertSame(['success' => $unused, 'failure' => $unused, 'always' => $unused], $this->cellsOf($step));
    }

    // -- Target, badges -----------------------------------------------------------------

    public function testTheTargetCellLinksTheTargetOfTheStepsTypeOrSaysWhyRunsFail(): void
    {
        $gatedGone = new JobTemplate();
        $gatedGone->id = 155;
        $gatedGone->name = 'gated-template';
        $gatedGone->approval_rule_id = 66;
        $gatedGone->populateRelation('approvalRule', null);
        $gatedGone->populateRelation('inventory', null);
        $table = self::table([
            self::job(1, 'job'),
            self::approval(2, 'approval'),
            self::pause(3, 'pause'),
            self::stepModel(4, 'deleted', 'job', [], null, null, 12),
            self::stepModel(5, 'none', 'job'),
            self::stepModel(6, 'rule gone', 'approval', [], null, null, null, 9),
            self::stepModel(7, 'no rule', 'approval'),
            self::stepModel(8, 'gated, rule gone', 'job', [], $gatedGone, null, 155),
            self::stepModel(9, 'unknown', 'JOB'),
            // Saved by an older version with a job template: a pause step has none.
            self::stepModel(10, 'pause with a template', 'pause', [], $gatedGone, null, 155),
        ]);

        $targets = array_map(static fn (array $row): array => $row['target'], $table->rows());

        $this->assertSame([
            ['text' => 'template-job', 'route' => ['/job-template/view', 'id' => 101], 'problem' => null],
            ['text' => 'rule-approval', 'route' => ['/approval-rule/view', 'id' => 202], 'problem' => null],
            ['text' => '—', 'route' => null, 'problem' => null],
            ['text' => '', 'route' => null, 'problem' => 'job template ID 12 no longer exists · runs fail here'],
            ['text' => '', 'route' => null, 'problem' => 'no job template · runs fail here'],
            ['text' => '', 'route' => null, 'problem' => 'approval rule ID 9 no longer exists · runs fail here'],
            ['text' => '', 'route' => null, 'problem' => 'no approval rule · runs fail here'],
            [
                'text' => 'gated-template',
                'route' => ['/job-template/view', 'id' => 155],
                'problem' => 'approval rule ID 66 no longer exists · runs fail here',
            ],
            ['text' => '', 'route' => null, 'problem' => 'unknown step type · runs fail here'],
            ['text' => '—', 'route' => null, 'problem' => null],
        ], $targets);
        $this->assertSame(
            ['Job', 'Approval', 'Pause', 'Job', 'Job', 'Approval', 'Approval', 'Job', 'JOB', 'Pause'],
            array_map(static fn (array $row): string => $row['typeLabel'], $table->rows()),
            'an unknown type is shown as stored'
        );
        $this->assertSame(
            'approval rule ID 66 no longer exists · runs fail here',
            $table->rows()[7]['gatedTitle'],
            'the badge of a gated job step whose rule is gone says so'
        );
    }

    public function testTheBadgesMarkTheStartStepUnreachableStepsGatedStepsAndWaitingRuns(): void
    {
        $table = self::table([
            self::job(1, 'Build', ['success' => 0, 'failure' => 0]),
            self::job(2, 'Spare'),
            self::job(3, 'Deploy', [], 'Change board'),
        ], false, [2 => 2, 3 => 0, 999 => 1]);

        $rows = $table->rows();

        $this->assertSame([true, false, false], array_column($rows, 'isStart'));
        $this->assertSame([true, false, false], array_column($rows, 'isFirst'));
        $this->assertSame([false, false, true], array_column($rows, 'isLast'));
        $this->assertSame([true, false, false], array_column($rows, 'reachable'), 'no branch leads past Build');
        $this->assertSame([1, 2, 3], array_column($rows, 'position'));
        $this->assertSame([1, 2, 3], array_column($rows, 'id'));
        $this->assertSame(['Build', 'Spare', 'Deploy'], array_column($rows, 'name'));
        $this->assertSame(['job', 'job', 'job'], array_column($rows, 'type'));
        $this->assertSame(
            [null, null, 'Its job template has the approval rule "Change board": the job waits for an approval before it runs.'],
            array_column($rows, 'gatedTitle')
        );
        $this->assertSame([null, '2 run(s) waiting', null], array_column($rows, 'waiting'));
    }

    /**
     * The badge of a job step gated by its inventory names the inventory's
     * rule (GATED_INVENTORY_TITLE). A step whose job template has a rule of
     * its own is named by that rule, also when its inventory has the same
     * one. A step whose job template and inventory have different rules
     * cannot run (TARGET_RULES_DIFFER), and the badge says so; one whose
     * inventory names a rule that is gone names that rule's ID.
     */
    public function testTheBadgeAndTargetOfAJobStepGatedByItsInventory(): void
    {
        $same = self::job(4, 'Same', [], 'Board');
        $sameTemplate = $same->jobTemplate;
        $this->assertNotNull($sameTemplate);
        $sameTemplate->inventory->setAttribute('approval_rule_id', 304);
        $sameTemplate->inventory->populateRelation('approvalRule', $sameTemplate->approvalRule);
        $gone = self::job(5, 'Gone');
        $goneTemplate = $gone->jobTemplate;
        $this->assertNotNull($goneTemplate);
        $goneTemplate->inventory->setAttribute('approval_rule_id', 77);
        $ownGone = self::job(6, 'Own gone', [], 'Board', 'Prod hosts');
        $ownGoneTemplate = $ownGone->jobTemplate;
        $this->assertNotNull($ownGoneTemplate);
        $ownGoneTemplate->populateRelation('approvalRule', null);
        $table = self::table([
            self::job(1, 'Inventory', [], null, 'Prod hosts'),
            self::job(2, 'Own', [], 'Board'),
            self::job(3, 'Differ', [], 'Board', 'Prod hosts'),
            $same,
            $gone,
            $ownGone,
        ]);

        $rows = $table->rows();

        $this->assertSame(
            [
                sprintf(WorkflowStepsTable::GATED_INVENTORY_TITLE, 'Prod hosts'),
                sprintf(WorkflowStepsTable::GATED_TITLE, 'Board'),
                WorkflowStepsTable::TARGET_RULES_DIFFER,
                sprintf(WorkflowStepsTable::GATED_TITLE, 'Board'),
                'approval rule ID 77 no longer exists · runs fail here',
                WorkflowStepsTable::TARGET_RULES_DIFFER,
            ],
            array_column($rows, 'gatedTitle')
        );
        $this->assertSame(
            [null, null, WorkflowStepsTable::TARGET_RULES_DIFFER, null, 'approval rule ID 77 no longer exists · runs fail here', WorkflowStepsTable::TARGET_RULES_DIFFER],
            array_map(static fn (array $row): ?string => $row['target']['problem'], $rows)
        );
        $this->assertSame(
            ['template-Inventory', 'template-Own', 'template-Differ', 'template-Same', 'template-Gone', 'template-Own gone'],
            array_map(static fn (array $row): string => $row['target']['text'], $rows),
            'the Target cell links the job template'
        );
        $unused = ['unused', '—', [WorkflowStepsTable::CANNOT_RUN_TITLE]];
        $this->assertSame(['success' => $unused, 'failure' => $unused, 'always' => $unused], self::flatCells($rows[2]['routes']));
        $this->assertSame(['success' => $unused, 'failure' => $unused, 'always' => $unused], self::flatCells($rows[4]['routes']));
        $this->assertSame([true, true, true, false, false, false], array_column($rows, 'reachable'), 'runs fail at the step whose rules differ');
    }

    /**
     * regression: a step after one that cannot run was not marked, though a
     * run fails at that step and never goes on to it.
     */
    public function testRegressionAStepOnlyAStepThatCannotRunLeadsToIsUnreachable(): void
    {
        $table = self::table([self::stepModel(1, 'Build', 'job', [], null, null, 12), self::job(2, 'Deploy')]);

        $this->assertSame([true, false], array_column($table->rows(), 'reachable'));
    }

    // -- Success path -------------------------------------------------------------------

    public function testTheSuccessPathEndsWithTheRunsEnd(): void
    {
        $table = self::table([self::job(1, 'Build'), self::approval(2, 'Approve'), self::job(3, 'Deploy')]);

        $this->assertSame(
            ['text' => '1. Build → 2. Approve (approval step) → 3. Deploy → end · run succeeds', 'loops' => false],
            $table->successPath()
        );
    }

    public function testASuccessPathThatLeadsBackLoops(): void
    {
        $table = self::table([self::approval(1, 'Approve'), self::job(2, 'Poll', ['success' => 1])]);

        $this->assertSame(
            ['text' => '1. Approve (approval step) → 2. Poll → ↺ 1. Approve (approval step)', 'loops' => true],
            $table->successPath()
        );
    }

    public function testASuccessPathToAMissingStepFailsTheRun(): void
    {
        $table = self::table([self::job(1, 'Build', ['success' => self::GONE])]);

        $this->assertSame(['text' => '1. Build → missing step (ID 999) · run fails', 'loops' => false], $table->successPath());
    }

    public function testASuccessPathEndsAtAStepThatCannotRun(): void
    {
        $table = self::table([self::job(1, 'Build'), self::stepModel(2, 'Deploy', 'job', [], null, null, 12), self::job(3, 'Never')]);

        $this->assertSame(['text' => '1. Build → 2. Deploy cannot run · run fails', 'loops' => false], $table->successPath());
    }

    public function testAWorkflowWithoutStepsHasNoRowsAndNoSuccessPath(): void
    {
        $table = self::table([]);

        $this->assertSame([], $table->rows());
        $this->assertNull($table->successPath());
    }

    // -- Labels -------------------------------------------------------------------------

    public function testStepsAreNamedByTheirPosition(): void
    {
        $map = self::mapOf([self::job(7, 'Build'), self::approval(9, 'Approve')]);

        $this->assertSame('3. Deploy', WorkflowStepsTable::stepLabel(3, 'Deploy'));
        $this->assertSame('step 2 "Test"', WorkflowStepsTable::stepReference(2, 'Test'));
        $this->assertSame('1. Build', WorkflowStepsTable::choiceLabelIn($map, 7));
        $this->assertSame('2. Approve (approval step)', WorkflowStepsTable::choiceLabelIn($map, 9));
        $this->assertSame('', WorkflowStepsTable::choiceLabelIn($map, 8));
    }

    /**
     * A branch names the position of its step, not its step_order (the
     * regression is WorkflowTemplateStepsTableViewTest's).
     */
    public function testRouteLabelsNameThePositionNotTheStepOrder(): void
    {
        $table = self::table([self::job(1, 'Build', ['failure' => 2]), self::job(2, 'Deploy')]);

        $this->assertSame('→ 2. Deploy', $table->rows()[0]['routes']['failure']['parts'][0]['text']);
    }

    // -- Actions ------------------------------------------------------------------------

    public function testATableWithoutActionsHasNone(): void
    {
        $table = self::table([self::job(1, 'Build')]);

        $this->assertFalse($table->hasActions());
        $this->assertNull($table->rows()[0]['actions']);
    }

    /**
     * Every action names its step (the regression is
     * WorkflowTemplateStepsTableViewTest's).
     */
    public function testTheActionsNameTheirStep(): void
    {
        $table = self::table([self::job(1, 'Build'), self::job(2, 'Test')], true);

        $this->assertTrue($table->hasActions());
        $actions = $table->rows()[1]['actions'];
        $this->assertNotNull($actions);
        $this->assertSame('Edit step 2 "Test"', $actions['edit']);
        $this->assertSame('Insert a step below step 2 "Test"', $actions['insertBelow']);
        $this->assertSame('Move step 2 "Test" up', $actions['moveUp']);
        $this->assertSame('Move step 2 "Test" down', $actions['moveDown']);
        $this->assertSame('Move step 2 "Test" to another position', $actions['moveTo']);
        $this->assertSame('Remove step 2 "Test"', $actions['remove']);
        $this->assertSame('Remove step 2 "Test"?', $actions['confirm']);
        $this->assertSame('Move step 1 "Build" up', $table->rows()[0]['actions']['moveUp'] ?? null);
    }

    public function testMoveToOffersOnlyPlacesThatMoveTheStep(): void
    {
        $table = self::table([self::job(1, 'A'), self::approval(2, 'B'), self::job(3, 'C'), self::job(4, 'D')], true);

        $targets = array_map(
            static fn (array $row): array => array_column($row['actions']['moveTargets'] ?? [], 'label', 'value'),
            $table->rows()
        );

        $this->assertSame([
            ['after:2' => 'After 2. B', 'after:3' => 'After 3. C', 'end' => 'To the end'],
            ['start' => 'To the start', 'after:3' => 'After 3. C', 'end' => 'To the end'],
            ['start' => 'To the start', 'after:1' => 'After 1. A', 'end' => 'To the end'],
            ['start' => 'To the start', 'after:1' => 'After 1. A', 'after:2' => 'After 2. B'],
        ], $targets);
    }

    public function testTheOnlyStepCannotMove(): void
    {
        $table = self::table([self::job(1, 'Build')], true);

        $this->assertSame([], $table->rows()[0]['actions']['moveTargets'] ?? null);
    }

    public function testEachMoveToTargetWarnsAboutWhatTheMoveWouldDo(): void
    {
        $table = self::table([self::job(1, 'A'), self::approval(2, 'B'), self::job(3, 'C'), self::job(4, 'D')], true);
        $rows = $table->rows();
        $hints = static fn (int $row, string $value): array => array_column($rows[$row]['actions']['moveTargets'] ?? [], 'hints', 'value')[$value] ?? [];

        $this->assertSame(['Runs will start with "B" instead of "A".'], $hints(0, 'after:2'));
        $this->assertSame([
            'Job step "C" will run before the approval step "B", without waiting for it.',
            'Job step "D" will run before the approval step "B", without waiting for it.',
        ], $hints(1, 'end'));
        $this->assertSame([
            'Runs will start with "C" instead of "A".',
            'Job step "C" will run before the approval step "B", without waiting for it.',
        ], $hints(2, 'start'));
        $this->assertSame([], $hints(3, 'after:2'), 'D stays after the approval step');
    }

    /**
     * regression: the Move to… hints went by position only. Moving an
     * approval step after a step whose On Success names the next step
     * showed no hint, while every run that succeeds skips it there.
     */
    public function testRegressionAMoveToWhereNoSuccessLeadsWarnsThatTheApprovalStepIsSkipped(): void
    {
        $table = self::table([
            self::job(1, 'Build', ['success' => 2, 'failure' => 0]),
            self::job(2, 'Deploy'),
            self::approval(3, 'Approve'),
        ], true);
        $targets = $table->rows()[2]['actions']['moveTargets'] ?? [];

        $this->assertSame(
            [
                'start' => ['Runs will start with "Approve" instead of "Build".'],
                'after:1' => ['The approval step "Approve" will not be on the success path: runs whose steps all succeed will not wait for it.'],
            ],
            array_column($targets, 'hints', 'value')
        );
    }

    public function testMovingAJobStepThatTakesTheApprovalStepOffTheSuccessPathWarns(): void
    {
        $table = self::table([
            self::job(1, 'Build', ['success' => 2]),
            self::job(2, 'Test'),
            self::approval(3, 'Approve'),
            self::job(4, 'Deploy'),
        ], true);
        $hints = array_column($table->rows()[1]['actions']['moveTargets'] ?? [], 'hints', 'value');

        $this->assertSame(
            ['The approval step "Approve" will not be on the success path: runs whose steps all succeed will not wait for it.'],
            $hints['end'] ?? null,
            'Build goes on with Test, which would end the run'
        );
    }

    /**
     * regression: every row's Move to… listed every other place with its
     * hints, so the page grew with the square of the steps. Above
     * MOVE_MENU_LIMIT steps, Move to… links to the step's edit page.
     */
    public function testRegressionALongTableLinksMoveToToTheEditPage(): void
    {
        $limit = 20; // WorkflowStepsTable::MOVE_MENU_LIMIT
        $atTheLimit = self::table(self::jobs($limit), true);
        $above = self::table(self::jobs($limit + 1), true);

        foreach ($atTheLimit->rows() as $row) {
            $this->assertFalse($row['actions']['moveToEditPage'] ?? null);
            $this->assertNotSame([], $row['actions']['moveTargets'] ?? []);
        }
        foreach ($above->rows() as $row) {
            $this->assertTrue($row['actions']['moveToEditPage'] ?? null);
            $this->assertSame([], $row['actions']['moveTargets'] ?? null);
            $this->assertSame(sprintf('Move step %d "job %d" to another position', $row['position'], $row['id']), $row['actions']['moveTo'] ?? null);
        }
    }

    public function testTheMovesOfAStepLeaveOutThePlacesItIsAlreadyIn(): void
    {
        $map = self::mapOf([self::job(1, 'A'), self::job(2, 'B'), self::job(3, 'C')]);
        $steps = $map->steps();

        $this->assertSame(['after:2', 'end'], array_keys(WorkflowStepsTable::movesOf($map, $steps[0])));
        $this->assertSame(['start', 'end'], array_keys(WorkflowStepsTable::movesOf($map, $steps[1])));
        $this->assertSame(['start', 'after:1'], array_keys(WorkflowStepsTable::movesOf($map, $steps[2])));
        $this->assertSame('A', WorkflowStepsTable::movesOf($map, $steps[2])['after:1']?->name);
    }

    public function testEveryStepNeedsItsModel(): void
    {
        $build = self::job(1, 'Build');
        $map = self::mapOf([$build, self::job(2, 'Deploy')]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The steps table has no model of step ID 2.');

        new WorkflowStepsTable($map, [$build], false, []);
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * $count job steps, "job 1" to "job $count".
     *
     * @return list<WorkflowStep>
     */
    private static function jobs(int $count): array
    {
        return array_map(static fn (int $id): WorkflowStep => self::job($id, "job {$id}"), range(1, $count));
    }

    /**
     * @param list<WorkflowStep> $models
     * @param array<int, int> $waiting
     */
    private static function table(array $models, bool $withActions = false, array $waiting = []): WorkflowStepsTable
    {
        return new WorkflowStepsTable(self::mapOf($models), $models, $withActions, $waiting);
    }

    /**
     * The cells of $step at position 2 of P (1), $step, N (3), L (4), as
     * [kind, text with the note, the titles of its parts].
     *
     * @return array<string, array{0: string, 1: string, 2: list<string|null>}>
     */
    private function cellsOf(WorkflowStep $step): array
    {
        $table = self::table([self::job(self::P, 'P'), $step, self::job(self::N, 'N'), self::job(self::L, 'L')]);

        return self::flatCells($table->rows()[1]['routes']);
    }

    /**
     * @param array<string, array{kind: string, parts: list<array{text: string, title: string|null}>, note: string|null}> $routes
     * @return array<string, array{0: string, 1: string, 2: list<string|null>}>
     */
    private static function flatCells(array $routes): array
    {
        $cells = [];
        foreach ($routes as $route => $cell) {
            $text = implode(WorkflowStepsTable::PART_SEPARATOR, array_column($cell['parts'], 'text'));
            $cells[$route] = [
                $cell['kind'],
                $cell['note'] === null ? $text : $text . ' ' . $cell['note'],
                array_column($cell['parts'], 'title'),
            ];
        }

        return $cells;
    }

    /**
     * @param array<string, array{0: string, 1: string, 2: list<string|null>}> $expected
     */
    private function assertCells(array $expected, WorkflowStep $step): void
    {
        $cells = $this->cellsOf($step);
        foreach ($expected as $route => $cell) {
            $this->assertSame($cell, $cells[$route], $route);
        }
    }
}
