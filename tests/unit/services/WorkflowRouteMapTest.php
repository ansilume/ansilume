<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\components\WorkflowOrderEffects;
use app\components\WorkflowRoute;
use app\components\WorkflowRouteStep;
use app\services\WorkflowRouteMap;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowRouteMap (contract C7) on steps given in memory: where every
 * outcome of every kind of step leads for every kind of stored branch, the
 * Always branch, the success path, which steps a run can reach (also
 * without passing an approval step), a simulated placement, and what a
 * change of the steps did, worked out from the branches (the start step,
 * job steps that runs now reach without passing an approval step, approval
 * steps off the success path). The database side (forTemplate(),
 * fromModels()) is WorkflowRouteMapLoadTest; the agreement with the real
 * router and real runs is WorkflowRouteMapEngineAgreementTest.
 */
class WorkflowRouteMapTest extends TestCase
{
    /** The steps of the matrix: P (1), S (2, the step under test), N (3), L (4). */
    private const P = 11;
    private const S = 22;
    private const N = 33;
    private const L = 44;
    private const GONE = 999;

    /** The stored branch values of the matrix, by name. */
    private const VALUES = [
        'empty' => null,
        'end (0)' => 0,
        'forward' => self::L,
        'back' => self::P,
        'self' => self::S,
        'missing' => self::GONE,
    ];

    /** The types of the matrix: [type, gated rule name]. */
    private const TYPES = [
        'job' => ['job', null],
        'gated job' => ['job', 'Change board'],
        'approval' => ['approval', null],
        'pause' => ['pause', null],
        'unknown ("JOB")' => ['JOB', null],
    ];

    private const OUTCOMES = ['success', 'failure', 'closed_gate'];

    public function testTheConstantsKeepTheirValues(): void
    {
        $this->assertSame(
            ['success', 'failure', 'closed_gate'],
            [WorkflowRouteMap::OUTCOME_SUCCESS, WorkflowRouteMap::OUTCOME_FAILURE, WorkflowRouteMap::OUTCOME_CLOSED_GATE]
        );
        $this->assertSame(
            ['succeeds', 'loop', 'missing', 'cannot_run'],
            [WorkflowRouteMap::PATH_SUCCEEDS, WorkflowRouteMap::PATH_LOOP, WorkflowRouteMap::PATH_MISSING, WorkflowRouteMap::PATH_CANNOT_RUN]
        );
        $this->assertSame(['jobTemplate.approvalRule', 'approvalRule'], WorkflowRouteMap::RELATIONS);
        $this->assertSame('Step ID %d is listed twice.', WorkflowRouteMap::STEP_TWICE);
        $this->assertSame('Step ID %d is not part of this workflow.', WorkflowRouteMap::NOT_IN_MAP);
        $this->assertSame('Unknown placement "%s": use start, end, keep or after:<step ID>.', WorkflowRouteMap::PLACEMENT_INVALID);
        $this->assertSame('Step ID %d cannot be placed after itself.', WorkflowRouteMap::AFTER_ITSELF);
        $this->assertSame(
            ['step', 'end', 'missing', 'on_success', 'on_failure', 'on_always', 'next', 'succeeds', 'fails'],
            [
                WorkflowRoute::KIND_STEP,
                WorkflowRoute::KIND_END,
                WorkflowRoute::KIND_MISSING,
                WorkflowRoute::VIA_ON_SUCCESS,
                WorkflowRoute::VIA_ON_FAILURE,
                WorkflowRoute::VIA_ON_ALWAYS,
                WorkflowRoute::VIA_NEXT,
                WorkflowRoute::RUN_SUCCEEDS,
                WorkflowRoute::RUN_FAILS,
            ]
        );
        $this->assertSame(-1, WorkflowRouteStep::NEW_STEP_ID);
        $this->assertSame(
            ['job_template_missing', 'approval_rule_missing', 'unknown_type'],
            [
                WorkflowRouteStep::PROBLEM_JOB_TEMPLATE_MISSING,
                WorkflowRouteStep::PROBLEM_APPROVAL_RULE_MISSING,
                WorkflowRouteStep::PROBLEM_UNKNOWN_TYPE,
            ]
        );
    }

    // -- Outcomes and routes ------------------------------------------------------------

    public function testEachTypeEndsWithItsOwnOutcomesComparedExactly(): void
    {
        $map = WorkflowRouteMap::fromSteps([]);

        $this->assertSame(['success', 'failure'], $map->outcomes(self::step(1, 'job')));
        $this->assertSame(['success', 'failure', 'closed_gate'], $map->outcomes(self::step(1, 'job', gated: 'Change board')));
        $this->assertSame(['success', 'failure', 'closed_gate'], $map->outcomes(self::step(1, 'job', gated: '')), 'a gated step whose rule is gone');
        $this->assertSame(['success', 'closed_gate'], $map->outcomes(self::step(1, 'approval')));
        $this->assertSame(['success'], $map->outcomes(self::step(1, 'pause')));
        $this->assertSame([], $map->outcomes(self::step(1, 'JOB')), '"JOB" is an unknown type, as dispatch compares it');
        $this->assertSame([], $map->outcomes(self::step(1, 'approval ')));
        $this->assertSame([], $map->outcomes(self::step(1, '')));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function matrixProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::TYPES) as $type) {
            foreach (['on_success', 'on_failure', 'on_always'] as $field) {
                foreach (array_keys(self::VALUES) as $value) {
                    $cases["{$type}: {$field} = {$value}"] = [$type, $field, $value];
                }
            }
        }

        return $cases;
    }

    /**
     * Every outcome of every type, for every kind of stored value in each
     * branch: S at position 2 of P, S, N, L stores the value in one branch
     * and leaves the others empty. The expectation restates the rules of
     * contract C7 (expectedRoute()).
     *
     * @dataProvider matrixProvider
     */
    public function testEveryOutcomeOfEveryTypeForEveryStoredValue(string $type, string $field, string $value): void
    {
        [$stepType, $gated] = self::TYPES[$type];
        $stored = self::VALUES[$value];
        $s = self::step(
            self::S,
            $stepType,
            $field === 'on_success' ? $stored : null,
            $field === 'on_failure' ? $stored : null,
            $field === 'on_always' ? $stored : null,
            $gated
        );
        $map = WorkflowRouteMap::fromSteps([self::step(self::P), $s, self::step(self::N), self::step(self::L)]);

        foreach (self::OUTCOMES as $outcome) {
            $this->assertSame(
                self::expectedRoute($type, $field, $stored, $outcome),
                $map->route($s, $outcome)?->toArray(),
                "{$type}, {$field} = {$value}, outcome {$outcome}"
            );
        }
    }

    /**
     * An empty branch of the last step ends the run with the step's outcome;
     * an empty On Failure after a closed gate ends it as failed.
     */
    public function testAnEmptyBranchOfTheLastStepEndsTheRunWithTheOutcome(): void
    {
        $last = self::step(2, 'job', gated: 'Change board');
        $map = WorkflowRouteMap::fromSteps([self::step(1), $last]);

        $this->assertSame(self::end('next', 'succeeds'), $map->route($last, 'success')?->toArray());
        $this->assertSame(self::end('next', 'fails'), $map->route($last, 'failure')?->toArray());
        $this->assertSame(self::end('on_failure', 'fails'), $map->route($last, 'closed_gate')?->toArray());
    }

    /**
     * Always wins over On Success and On Failure, but never after a closed
     * gate, which follows only On Failure.
     */
    public function testAlwaysWinsExceptAfterAClosedGate(): void
    {
        $gated = self::step(2, 'job', 3, 4, 5, 'Change board');
        $map = WorkflowRouteMap::fromSteps([self::step(1), $gated, self::step(3), self::step(4), self::step(5)]);

        $this->assertSame(self::toStep('on_always', 5, 5), $map->route($gated, 'success')?->toArray());
        $this->assertSame(self::toStep('on_always', 5, 5), $map->route($gated, 'failure')?->toArray());
        $this->assertSame(self::toStep('on_failure', 4, 4), $map->route($gated, 'closed_gate')?->toArray());
    }

    /**
     * The route of a step is read from the step given, its position from the
     * map: a preview of an edited step needs no new map.
     */
    public function testTheBranchesComeFromTheStepGivenAndThePositionFromTheMap(): void
    {
        $map = WorkflowRouteMap::fromSteps([self::step(1), self::step(2), self::step(3)]);

        $this->assertSame(self::toStep('on_success', 1, 1, true), $map->route(self::step(2, 'job', 1), 'success')?->toArray());
        $this->assertSame(self::toStep('next', 3, 3), $map->route(self::step(2, 'pause'), 'success')?->toArray());
    }

    public function testAStepThatIsNotPartOfTheMapIsRefused(): void
    {
        $map = WorkflowRouteMap::fromSteps([self::step(1)]);

        foreach (['route', 'always'] as $method) {
            try {
                $method === 'route' ? $map->route(self::step(7), 'success') : $map->always(self::step(7, 'job', null, null, 0));
                $this->fail("{$method}() refuses a step that is not part of the map");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Step ID 7 is not part of this workflow.', $e->getMessage());
            }
        }
    }

    public function testAListThatNamesAStepTwiceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Step ID 2 is listed twice.');

        WorkflowRouteMap::fromSteps([self::step(1), self::step(2), self::step(2, 'pause')]);
    }

    // -- Always -------------------------------------------------------------------------

    /**
     * An approval or pause step takes its Always branch only on success, so
     * an end succeeds; a job step takes it after either outcome, so an end
     * ends the run with the step's outcome. A step that cannot end with
     * anything never takes it.
     */
    public function testAlwaysResolvesTheStoredBranch(): void
    {
        $job = self::step(1, 'job', null, null, 0);
        $gated = self::step(2, 'job', null, null, 1, 'Change board');
        $approval = self::step(3, 'approval', null, null, 0);
        $pause = self::step(4, 'pause', null, null, 4);
        $missing = self::step(5, 'job', null, null, self::GONE);
        $unknown = self::step(6, 'JOB', null, null, 0);
        $none = self::step(7, 'job', 1, 0);
        $forward = self::step(8, 'approval', null, null, 9);
        $map = WorkflowRouteMap::fromSteps([$job, $gated, $approval, $pause, $missing, $unknown, $none, $forward, self::step(9)]);

        $this->assertSame(self::end('on_always', null), $map->always($job)?->toArray());
        $this->assertSame(self::toStep('on_always', 1, 1, true), $map->always($gated)?->toArray());
        $this->assertSame(self::end('on_always', 'succeeds'), $map->always($approval)?->toArray());
        $this->assertSame(self::toStep('on_always', 4, 4, true), $map->always($pause)?->toArray());
        $this->assertSame(
            ['kind' => 'missing', 'via' => 'on_always', 'step_id' => self::GONE, 'position' => null, 'run_outcome' => 'fails', 'loop' => false],
            $map->always($missing)?->toArray()
        );
        $this->assertNull($map->always($unknown), 'an unknown type never takes a branch');
        $this->assertNull($map->always($none), 'no Always branch');
        $this->assertSame(self::toStep('on_always', 9, 9), $map->always($forward)?->toArray());
    }

    // -- Positions ----------------------------------------------------------------------

    public function testPositionsAreTheOrderGivenFromOne(): void
    {
        $build = self::step(30, 'job', name: 'Build');
        $test = self::step(10, 'job', name: 'Test');
        $deploy = self::step(20, 'job', name: 'Deploy');
        $map = WorkflowRouteMap::fromSteps([$build, $test, $deploy]);

        $this->assertSame([$build, $test, $deploy], $map->steps());
        $this->assertSame($build, $map->start());
        $this->assertSame([1, 2, 3, null], [$map->position(30), $map->position(10), $map->position(20), $map->position(40)]);
        $this->assertSame($test, $map->find(10));
        $this->assertNull($map->find(40));
    }

    public function testAMapWithoutStepsHasNoStartAndNoPath(): void
    {
        $map = WorkflowRouteMap::fromSteps([]);

        $this->assertSame([], $map->steps());
        $this->assertNull($map->start());
        $this->assertNull($map->position(1));
        $this->assertNull($map->find(1));
        $this->assertFalse($map->isReachable(1));
        $this->assertSame(['step_ids' => [], 'end' => null, 'loop_to_step_id' => null, 'missing_step_id' => null], $map->successPath());
        $this->assertSame(
            ['start_step_id' => null, 'success_path' => $map->successPath(), 'steps' => []],
            $map->toApi()
        );
    }

    // -- Success path -------------------------------------------------------------------

    public function testTheSuccessPathEndsWhenTheRunSucceeds(): void
    {
        $map = WorkflowRouteMap::fromSteps([
            self::step(1, 'job', null, 0, name: 'Build'),
            self::step(2, 'approval', name: 'Approve'),
            self::step(3, 'job', 5, gated: 'Change board', name: 'Deploy'),
            self::step(4, 'job', name: 'Skipped'),
            self::step(5, 'pause', name: 'Wait'),
        ]);

        $this->assertSame(self::path([1, 2, 3, 5], 'succeeds'), $map->successPath());
    }

    public function testTheSuccessPathEndsWhereItLoops(): void
    {
        $back = WorkflowRouteMap::fromSteps([self::step(1), self::step(2), self::step(3, 'job', 1)]);
        $itself = WorkflowRouteMap::fromSteps([self::step(1), self::step(2, 'job', 2)]);
        $forwardAgain = WorkflowRouteMap::fromSteps([self::step(1, 'job', 3), self::step(2, 'job', 3), self::step(3, 'job', 2)]);
        $always = WorkflowRouteMap::fromSteps([self::step(1, 'job', 0, null, 1)]);

        $this->assertSame(self::path([1, 2, 3], 'loop', 1), $back->successPath());
        $this->assertSame(self::path([1, 2], 'loop', 2), $itself->successPath());
        $this->assertSame(self::path([1, 3, 2], 'loop', 3), $forwardAgain->successPath(), 'a forward branch to a step passed before');
        $this->assertSame(self::path([1], 'loop', 1), $always->successPath(), 'Always wins');
    }

    public function testTheSuccessPathEndsAtAStepThatNoLongerExists(): void
    {
        $map = WorkflowRouteMap::fromSteps([self::step(1), self::step(2, 'approval', self::GONE), self::step(3)]);

        $this->assertSame(self::path([1, 2], 'missing', null, self::GONE), $map->successPath());
    }

    /**
     * @return array<string, array{0: WorkflowRouteStep}>
     */
    public static function cannotRunProvider(): array
    {
        return [
            'job template missing' => [self::step(2, 'job', problem: 'job_template_missing')],
            'approval rule missing' => [self::step(2, 'approval', problem: 'approval_rule_missing')],
            'unknown type' => [self::step(2, 'JOB', problem: 'unknown_type')],
            'unknown type, no problem given' => [self::step(2, 'JOB')],
        ];
    }

    /**
     * @dataProvider cannotRunProvider
     */
    public function testTheSuccessPathEndsAtAStepThatCannotRun(WorkflowRouteStep $broken): void
    {
        $map = WorkflowRouteMap::fromSteps([self::step(1), $broken, self::step(3)]);

        $this->assertSame(self::path([1, 2], 'cannot_run'), $map->successPath());
    }

    // -- Reachable steps ----------------------------------------------------------------

    public function testAStepIsReachableThroughAnyOutcome(): void
    {
        $map = WorkflowRouteMap::fromSteps([
            self::step(1, 'job', 2, 4, name: 'Build'),
            self::step(2, 'job', 0, 0, name: 'Test'),
            self::step(3, 'job', name: 'Skipped'),
            self::step(4, 'job', 0, 0, name: 'Cleanup'),
        ]);

        $this->assertTrue($map->isReachable(1), 'the start step');
        $this->assertTrue($map->isReachable(2), 'through On Success');
        $this->assertTrue($map->isReachable(4), 'only through On Failure');
        $this->assertFalse($map->isReachable(3), 'every branch before it ends the run or jumps past it');
        $this->assertFalse($map->isReachable(99), 'no such step');
    }

    public function testBranchesThatAreNeverTakenReachNothing(): void
    {
        $map = WorkflowRouteMap::fromSteps([
            self::step(1, 'job', 2, 3, 4),
            self::step(2, 'job', 0, 0),
            self::step(3, 'job', 0, 0),
            self::step(4, 'job', 0, 5, 0, gated: 'Change board'),
            self::step(5, 'pause', 0, 6),
            self::step(6, 'JOB', 7, 7, 7),
            self::step(7, 'job', 0, 0),
            self::step(8, 'job', 0, 0),
        ]);

        $this->assertFalse($map->isReachable(2), 'Always overrides On Success');
        $this->assertFalse($map->isReachable(3), 'Always overrides On Failure');
        $this->assertTrue($map->isReachable(4));
        $this->assertTrue($map->isReachable(5), 'after a closed gate, On Failure is followed');
        $this->assertFalse($map->isReachable(6), 'a pause step never fails');
        $this->assertFalse($map->isReachable(7), 'only an unreachable step leads to it');
        $this->assertFalse($map->isReachable(8));

        $unknownStart = WorkflowRouteMap::fromSteps([self::step(1, 'JOB', 2), self::step(2)]);
        $this->assertTrue($unknownStart->isReachable(1));
        $this->assertFalse($unknownStart->isReachable(2), 'a step of an unknown type takes no branch');
    }

    /**
     * @return array<string, array{0: WorkflowRouteStep}>
     */
    public static function brokenStartProvider(): array
    {
        return [
            'its job template is missing' => [self::step(1, 'job', problem: 'job_template_missing')],
            'its approval rule is missing' => [self::step(1, 'approval', problem: 'approval_rule_missing')],
            'a gated job step whose rule is missing' => [self::step(1, 'job', gated: '', problem: 'approval_rule_missing')],
            'its type is unknown' => [self::step(1, 'JOB', problem: 'unknown_type')],
        ];
    }

    /**
     * regression: a step after one that cannot run is reachable only through
     * it. A run fails at a job step whose job template is missing, or at an
     * approval step whose rule is missing, without following a branch
     * (WorkflowExecutionService::failStep()), yet the steps after it counted
     * as reachable, in the API too, while a step of an unknown type already
     * stopped the walk.
     *
     * @dataProvider brokenStartProvider
     */
    public function testRegressionAStepAfterOneThatCannotRunIsReachableOnlyThroughIt(WorkflowRouteStep $broken): void
    {
        $map = WorkflowRouteMap::fromSteps([$broken, self::step(2)]);

        $this->assertTrue($map->isReachable(1), 'the start step');
        $this->assertFalse($map->isReachable(2), 'only the step that cannot run leads to it');
        $this->assertFalse($map->toApi()['steps'][2]['reachable']);
        $this->assertTrue($map->toApi()['steps'][1]['reachable']);
        $this->assertSame(self::path([1], 'cannot_run'), $map->successPath(), 'where the success path stops too');
    }

    public function testAStepThatCannotRunLeadsNowhereButOtherBranchesStillDo(): void
    {
        $map = WorkflowRouteMap::fromSteps([
            self::step(1, 'job', null, 4, name: 'Build'),
            self::step(2, 'job', problem: 'job_template_missing', name: 'Gone'),
            self::step(3, 'job', name: 'Behind'),
            self::step(4, 'job', 0, 0, name: 'Rollback'),
        ]);

        $this->assertTrue($map->isReachable(2), 'a run reaches it, and fails there');
        $this->assertFalse($map->isReachable(3), 'only the step that cannot run goes on with it');
        $this->assertTrue($map->isReachable(4), 'On Failure of a step that can run');
    }

    // -- Reachable without passing an approval step -------------------------------------

    public function testAnApprovedApprovalStepStopsTheWalkWithoutApprovalButAClosedGateDoesNot(): void
    {
        $map = WorkflowRouteMap::fromSteps([
            self::step(1, 'job', name: 'Build'),
            self::step(2, 'approval', null, 4, name: 'Approve'),
            self::step(3, 'job', name: 'Deploy'),
            self::step(4, 'job', 0, 0, name: 'Notify'),
            self::step(5, 'job', name: 'Spare'),
        ]);

        $this->assertSame([true, true, true, true, false], array_map(static fn (int $id): bool => $map->isReachable($id), [1, 2, 3, 4, 5]));
        $this->assertTrue($map->isReachableWithoutApproval(1));
        $this->assertTrue($map->isReachableWithoutApproval(2), 'the approval step itself');
        $this->assertFalse($map->isReachableWithoutApproval(3), 'only once approved');
        $this->assertTrue($map->isReachableWithoutApproval(4), 'after a closed gate: rejected, timed out or canceled');
        $this->assertFalse($map->isReachableWithoutApproval(5), 'no run reaches it at all');
        $this->assertFalse($map->isReachableWithoutApproval(99), 'no such step');
    }

    public function testABranchPastTheApprovalStepReachesAStepWithoutIt(): void
    {
        $map = WorkflowRouteMap::fromSteps([
            self::step(1, 'job', 3, 0, name: 'Build'),
            self::step(2, 'approval', name: 'Approve'),
            self::step(3, 'job', name: 'Deploy'),
        ]);

        $this->assertFalse($map->isReachable(2), 'Build leads past it, and ends the run after a failure');
        $this->assertFalse($map->isReachableWithoutApproval(2));
        $this->assertTrue($map->isReachableWithoutApproval(3));
        $this->assertSame([], WorkflowRouteMap::fromSteps([])->toApi()['steps']);
        $this->assertFalse(WorkflowRouteMap::fromSteps([])->isReachableWithoutApproval(1), 'no steps');
    }

    // -- Placement ----------------------------------------------------------------------

    public function testANewStepIsInsertedWherePlaced(): void
    {
        $map = WorkflowRouteMap::fromSteps([self::step(1), self::step(2), self::step(3)]);
        $new = self::step(WorkflowRouteStep::NEW_STEP_ID, 'approval');

        $this->assertSame([-1, 1, 2, 3], self::ids($map->withPlacement($new, 'start')));
        $this->assertSame([1, 2, 3, -1], self::ids($map->withPlacement($new, 'end')));
        $this->assertSame([1, -1, 2, 3], self::ids($map->withPlacement($new, 'after:1')));
        $this->assertSame([1, 2, 3, -1], self::ids($map->withPlacement($new, 'after:3')));
        $this->assertSame([1, 2, -1, 3], self::ids($map->withPlacement($new, 'after:02')), 'read as the web form reads it');
        $this->assertSame([1, 2, 3], self::ids($map), 'the map itself stays as it is');
        $this->assertSame(
            WorkflowRouteStep::NEW_STEP_ID,
            $map->withPlacement($new, 'start')->start()?->id
        );
    }

    public function testAStepOfTheMapIsMovedWithItsNewValues(): void
    {
        $map = WorkflowRouteMap::fromSteps([self::step(1), self::step(2), self::step(3), self::step(4)]);
        $edited = self::step(3, 'job', 0, null, null, null, null, 'Renamed');

        $this->assertSame([3, 1, 2, 4], self::ids($map->withPlacement($edited, 'start')));
        $this->assertSame([1, 2, 4, 3], self::ids($map->withPlacement($edited, 'end')));
        $this->assertSame([1, 3, 2, 4], self::ids($map->withPlacement($edited, 'after:1')));
        $this->assertSame([1, 2, 4, 3], self::ids($map->withPlacement($edited, 'after:4')));
        $this->assertSame([1, 2, 3, 4], self::ids($map->withPlacement($edited, 'after:2')), 'after the step above it: no move');
        $kept = $map->withPlacement($edited, 'keep');
        $this->assertSame([1, 2, 3, 4], self::ids($kept));
        $this->assertSame('Renamed', $kept->find(3)?->name);
        $this->assertSame(self::end('on_success', 'succeeds'), $kept->route($edited, 'success')?->toArray());
        $this->assertSame(self::toStep('next', 4, 4), $map->route(self::step(3), 'success')?->toArray(), 'the map itself stays');
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function placementRefusalProvider(): array
    {
        return [
            'an empty placement' => ['', 2, 'Unknown placement "": use start, end, keep or after:<step ID>.'],
            'another case' => ['START', 2, 'Unknown placement "START": use start, end, keep or after:<step ID>.'],
            'a position' => ['2', 2, 'Unknown placement "2": use start, end, keep or after:<step ID>.'],
            'after nothing' => ['after:', 2, 'Unknown placement "after:": use start, end, keep or after:<step ID>.'],
            'after 0' => ['after:0', 2, 'Unknown placement "after:0": use start, end, keep or after:<step ID>.'],
            'after a sign' => ['after:+1', 2, 'Unknown placement "after:+1": use start, end, keep or after:<step ID>.'],
            'after itself' => ['after:2', 2, 'Step ID 2 cannot be placed after itself.'],
            'after a step of no map' => ['after:9', 2, 'Step ID 9 is not part of this workflow.'],
            'keep a new step' => ['keep', WorkflowRouteStep::NEW_STEP_ID, 'Step ID -1 is not part of this workflow.'],
        ];
    }

    /**
     * @dataProvider placementRefusalProvider
     */
    public function testAPlacementThatNamesNoPlaceIsRefused(string $placement, int $stepId, string $message): void
    {
        $map = WorkflowRouteMap::fromSteps([self::step(1), self::step(2), self::step(3)]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $map->withPlacement(self::step($stepId), $placement);
    }

    // -- Effects of a change of the run order -------------------------------------------

    public function testAChangedStartStepIsReported(): void
    {
        $before = WorkflowRouteMap::fromSteps([self::step(1), self::step(2)]);
        $moved = $before->withPlacement(self::step(2), 'start');

        $effects = $moved->effectsSince($before);

        $this->assertSame([1, 2, null, []], self::effects($effects));
        $this->assertTrue($effects->startChanged());
        $this->assertFalse($before->effectsSince($before)->startChanged(), 'the same start');
    }

    public function testNoStartChangeForTheFirstStepAddedOrTheLastRemoved(): void
    {
        $none = WorkflowRouteMap::fromSteps([]);
        $one = WorkflowRouteMap::fromSteps([self::step(1)]);

        $added = $one->effectsSince($none, 1);
        $removed = $none->effectsSince($one);

        $this->assertSame([null, 1, null, []], self::effects($added));
        $this->assertFalse($added->startChanged());
        $this->assertSame([1, null, null, []], self::effects($removed));
        $this->assertFalse($removed->startChanged());
    }

    public function testAJobStepMovedBeforeTheApprovalStepIsUnguarded(): void
    {
        $before = WorkflowRouteMap::fromSteps([self::step(1, 'approval'), self::step(2), self::step(3, 'pause'), self::step(4)]);

        $effects = $before->withPlacement(self::step(4), 'start')->effectsSince($before);

        $this->assertSame([1, 4, 1, [4]], self::effects($effects));
    }

    /**
     * The step of an unknown type stands last: a run fails there, so a step
     * after it would be reached by no run (testAJobStepNoRunReachesIsNeverReported()).
     */
    public function testAnApprovalStepMovedAfterJobStepsUnguardsThem(): void
    {
        $before = WorkflowRouteMap::fromSteps([
            self::step(1, 'approval'),
            self::step(2, 'job', gated: 'Change board'),
            self::step(3, 'pause'),
            self::step(5),
            self::step(6),
            self::step(4, 'JOB'),
        ]);

        $effects = $before->withPlacement(self::step(1, 'approval'), 'after:5')->effectsSince($before);

        $this->assertSame([1, 2, 1, [2, 5]], self::effects($effects), 'a gated job step counts; pause and unknown types do not');
        $this->assertSame([], $effects->skippedApprovalStepIds, 'the approval step is still on the success path');
    }

    public function testAJobStepNoRunReachesIsNeverReported(): void
    {
        $before = WorkflowRouteMap::fromSteps([self::step(1, 'approval'), self::step(2), self::step(3, 'JOB'), self::step(4)]);

        $effects = $before->withPlacement(self::step(1, 'approval'), 'after:3')->effectsSince($before);

        $this->assertSame([1, 2, 1, [2]], self::effects($effects), 'a run fails at the step of an unknown type before step 4');
    }

    public function testAJobStepRunsReachAfterAClosedGateWasNeverGuarded(): void
    {
        $before = WorkflowRouteMap::fromSteps([self::step(1, 'approval', null, 3), self::step(2), self::step(3, 'job', 0, 0)]);

        $effects = $before->withPlacement(self::step(2), 'start')->effectsSince($before);

        $this->assertSame([1, 2, 1, [2]], self::effects($effects), 'step 3 ran without an approval before too: after a refusal');
    }

    /**
     * regression: a job step counted as guarded by its position after the
     * first approval step. An edit of a branch that lets runs go past the
     * approval step said nothing; it lets them reach the job step without
     * the approval, and takes the approval step off the success path.
     */
    public function testRegressionABranchPastTheApprovalStepUnguardsTheJobStepAndSkipsTheApproval(): void
    {
        $before = WorkflowRouteMap::fromSteps([self::step(1, 'job', null, 0), self::step(2, 'approval'), self::step(3)]);
        $edited = self::step(1, 'job', 3, 0);

        $effects = $before->withPlacement($edited, 'keep')->effectsSince($before, null, 1);

        $this->assertSame([1, 1, 2, [3]], self::effects($effects));
        $this->assertSame([2], $effects->skippedApprovalStepIds);
    }

    /**
     * regression: an approval step placed after a step whose On Success (or
     * Always) names the next step is skipped by every run that succeeds,
     * and the effects of the change said nothing about it.
     *
     * @return array<string, array{0: WorkflowRouteStep}>
     */
    public static function skippingBuildProvider(): array
    {
        return [
            'On Success names the next step' => [self::step(1, 'job', 2)],
            'Always names the next step' => [self::step(1, 'job', null, null, 2)],
            'On Success names it, On Failure ends the run' => [self::step(1, 'job', 2, 0)],
        ];
    }

    /**
     * @dataProvider skippingBuildProvider
     */
    public function testRegressionAnApprovalStepAddedWhereNoSuccessLeadsIsOffTheSuccessPath(WorkflowRouteStep $build): void
    {
        $before = WorkflowRouteMap::fromSteps([$build, self::step(2)]);
        $approval = self::step(WorkflowRouteStep::NEW_STEP_ID, 'approval');

        $effects = $before->withPlacement($approval, 'after:1')->effectsSince($before, -1);

        $this->assertSame([-1], $effects->skippedApprovalStepIds, 'the new step counts as changed');
        $this->assertSame([], $effects->unguardedJobStepIds);
        $this->assertSame([], $before->withPlacement($approval, 'end')->effectsSince($before, -1)->skippedApprovalStepIds, 'at the end it is on it');
    }

    public function testAnApprovalStepOffTheSuccessPathCountsOnlyWhenItLeftItOrWasChanged(): void
    {
        $before = WorkflowRouteMap::fromSteps([self::step(1, 'job', 3), self::step(2, 'approval'), self::step(3), self::step(4, 'approval')]);
        $renamed = $before->withPlacement(self::step(2, 'approval', name: 'Renamed'), 'keep');
        $moved = $before->withPlacement(self::step(4, 'approval'), 'after:1');

        $this->assertSame([], $renamed->effectsSince($before, null, 3)->skippedApprovalStepIds, 'off it before, and not changed');
        $this->assertSame([2], $renamed->effectsSince($before, null, 2)->skippedApprovalStepIds, 'the changed step');
        $this->assertSame([4], $moved->effectsSince($before)->skippedApprovalStepIds, 'on it before');
        $this->assertSame([2, 4], $before->withPlacement(self::step(3), 'end')->effectsSince($before, null, 2)->skippedApprovalStepIds);
    }

    public function testRemovingTheApprovalStepUnguardsTheJobStepsAfterIt(): void
    {
        $before = WorkflowRouteMap::fromSteps([self::step(1), self::step(2, 'approval'), self::step(3), self::step(4)]);
        $after = WorkflowRouteMap::fromSteps([self::step(1), self::step(3), self::step(4)]);

        $this->assertSame([1, 1, null, [3, 4]], self::effects($after->effectsSince($before)));
    }

    public function testOnlyTheFirstApprovalStepCounts(): void
    {
        $before = WorkflowRouteMap::fromSteps([
            self::step(1, 'approval'),
            self::step(2),
            self::step(3, 'approval'),
            self::step(4),
        ]);

        $firstMovedDown = $before->withPlacement(self::step(1, 'approval'), 'after:3');
        $jobMovedUp = $before->withPlacement(self::step(4), 'after:1');
        $secondMovedUp = $before->withPlacement(self::step(3, 'approval'), 'start');

        $this->assertSame([1, 2, 3, [2]], self::effects($firstMovedDown->effectsSince($before)));
        $this->assertSame([1, 1, 1, []], self::effects($jobMovedUp->effectsSince($before)), 'still after the first one');
        $this->assertSame([1, 3, 3, []], self::effects($secondMovedUp->effectsSince($before)));
    }

    public function testANewJobStepCountsOnlyBeforeTheFirstApprovalStep(): void
    {
        $gated = WorkflowRouteMap::fromSteps([self::step(1, 'approval'), self::step(2)]);
        $open = WorkflowRouteMap::fromSteps([self::step(1), self::step(2)]);
        $job = self::step(WorkflowRouteStep::NEW_STEP_ID);
        $pause = self::step(WorkflowRouteStep::NEW_STEP_ID, 'pause');

        $this->assertSame([1, -1, 1, [-1]], self::effects($gated->withPlacement($job, 'start')->effectsSince($gated, -1)));
        $this->assertSame([1, 1, 1, []], self::effects($gated->withPlacement($job, 'end')->effectsSince($gated, -1)));
        $this->assertSame([1, -1, 1, []], self::effects($gated->withPlacement($pause, 'start')->effectsSince($gated, -1)));
        $this->assertSame([1, -1, null, []], self::effects($open->withPlacement($job, 'start')->effectsSince($open, -1)), 'no approval step');
        $this->assertSame(
            [1, -1, 1, []],
            self::effects($gated->withPlacement($job, 'start')->effectsSince($gated)),
            'not named as new: a step that was never after the approval step'
        );
    }

    public function testWithoutAnApprovalStepNoJobStepIsUnguarded(): void
    {
        $before = WorkflowRouteMap::fromSteps([self::step(1), self::step(2), self::step(3)]);

        $this->assertSame([1, 3, null, []], self::effects($before->withPlacement(self::step(3), 'start')->effectsSince($before)));
    }

    // -- The API ------------------------------------------------------------------------

    public function testTheApiShapeListsEveryStepByIdInRunOrder(): void
    {
        $map = WorkflowRouteMap::fromSteps([
            self::step(7, 'approval', name: 'Approve'),
            self::step(3, 'job', null, 0, gated: 'Change board', name: 'Deploy'),
            self::step(5, 'pause', name: 'Wait'),
            self::step(9, 'JOB', problem: 'unknown_type', name: 'Broken'),
        ]);

        $api = $map->toApi();

        $this->assertSame(7, $api['start_step_id']);
        $this->assertSame(self::path([7, 3, 5, 9], 'cannot_run'), $api['success_path']);
        $this->assertSame([7, 3, 5, 9], array_keys($api['steps']));
        $this->assertSame(
            [
                'position' => 1,
                'is_start' => true,
                'reachable' => true,
                'cannot_run' => null,
                'routes' => [
                    'success' => self::toStep('next', 3, 2),
                    'failure' => null,
                    'closed_gate' => self::end('on_failure', 'fails'),
                ],
            ],
            $api['steps'][7]
        );
        $this->assertSame(
            [
                'position' => 2,
                'is_start' => false,
                'reachable' => true,
                'cannot_run' => null,
                'routes' => [
                    'success' => self::toStep('next', 5, 3),
                    'failure' => self::end('on_failure', 'fails'),
                    'closed_gate' => self::end('on_failure', 'fails'),
                ],
            ],
            $api['steps'][3]
        );
        $this->assertSame(
            ['success' => self::toStep('next', 9, 4), 'failure' => null, 'closed_gate' => null],
            $api['steps'][5]['routes']
        );
        $this->assertSame(
            [
                'position' => 4,
                'is_start' => false,
                'reachable' => true,
                'cannot_run' => 'unknown_type',
                'routes' => ['success' => null, 'failure' => null, 'closed_gate' => null],
            ],
            $api['steps'][9]
        );
    }

    // -- The value objects --------------------------------------------------------------

    public function testTwoRoutesHaveTheSameTargetWhateverDecidedThem(): void
    {
        $next = new WorkflowRoute('step', 'next', 3, 3, null, false);
        $branch = new WorkflowRoute('step', 'on_success', 3, 3, null, false);
        $other = new WorkflowRoute('step', 'next', 4, 4, null, false);
        $endSucceeds = new WorkflowRoute('end', 'next', null, null, 'succeeds', false);
        $endFails = new WorkflowRoute('end', 'on_failure', null, null, 'fails', false);
        $endFailsToo = new WorkflowRoute('end', 'next', null, null, 'fails', false);
        $missing = new WorkflowRoute('missing', 'on_success', 3, null, 'fails', false);

        $this->assertTrue($next->sameTarget($branch));
        $this->assertFalse($next->sameTarget($other));
        $this->assertFalse($endSucceeds->sameTarget($endFails), 'the run ends otherwise');
        $this->assertTrue($endFails->sameTarget($endFailsToo));
        $this->assertFalse($missing->sameTarget($branch), 'a missing step is no step');
        $this->assertSame(
            ['kind' => 'missing', 'via' => 'on_success', 'step_id' => 3, 'position' => null, 'run_outcome' => 'fails', 'loop' => false],
            $missing->toArray()
        );
    }

    public function testTheStartChangesOnlyBetweenTwoStartSteps(): void
    {
        $this->assertTrue((new WorkflowOrderEffects(1, 2, null, []))->startChanged());
        $this->assertFalse((new WorkflowOrderEffects(1, 1, null, []))->startChanged());
        $this->assertFalse((new WorkflowOrderEffects(null, 2, null, []))->startChanged());
        $this->assertFalse((new WorkflowOrderEffects(1, null, null, []))->startChanged());
        $this->assertSame([], (new WorkflowOrderEffects(1, 2, null, []))->skippedApprovalStepIds);
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * The route contract C7 gives step S (position 2 of P, S, N, L), of the
     * matrix type $type, whose branch $field stores $stored (the others are
     * empty), for $outcome; null when the type cannot end with it.
     *
     * @return array{kind: string, via: string, step_id: int|null, position: int|null, run_outcome: string|null, loop: bool}|null
     */
    private static function expectedRoute(string $type, string $field, ?int $stored, string $outcome): ?array
    {
        $outcomes = [
            'job' => ['success', 'failure'],
            'gated job' => ['success', 'failure', 'closed_gate'],
            'approval' => ['success', 'closed_gate'],
            'pause' => ['success'],
            'unknown ("JOB")' => [],
        ][$type];
        if (!in_array($outcome, $outcomes, true)) {
            return null;
        }
        if ($outcome === 'closed_gate') {
            $value = $field === 'on_failure' ? $stored : null;

            return $value === null ? self::end('on_failure', 'fails') : self::resolved($value, 'on_failure', 'fails');
        }
        $run = $outcome === 'success' ? 'succeeds' : 'fails';
        if ($field === 'on_always' && $stored !== null) {
            return self::resolved($stored, 'on_always', $run);
        }
        $own = $outcome === 'success' ? 'on_success' : 'on_failure';
        $value = $field === $own ? $stored : null;

        return $value === null ? self::toStep('next', self::N, 3) : self::resolved($value, $own, $run);
    }

    /**
     * @return array{kind: string, via: string, step_id: int|null, position: int|null, run_outcome: string|null, loop: bool}
     */
    private static function resolved(int $value, string $via, string $run): array
    {
        return match ($value) {
            0 => self::end($via, $run),
            self::L => self::toStep($via, self::L, 4),
            self::P => self::toStep($via, self::P, 1, true),
            self::S => self::toStep($via, self::S, 2, true),
            default => ['kind' => 'missing', 'via' => $via, 'step_id' => $value, 'position' => null, 'run_outcome' => 'fails', 'loop' => false],
        };
    }

    /**
     * @return array{kind: string, via: string, step_id: int|null, position: int|null, run_outcome: string|null, loop: bool}
     */
    private static function toStep(string $via, int $stepId, int $position, bool $loop = false): array
    {
        return ['kind' => 'step', 'via' => $via, 'step_id' => $stepId, 'position' => $position, 'run_outcome' => null, 'loop' => $loop];
    }

    /**
     * @return array{kind: string, via: string, step_id: int|null, position: int|null, run_outcome: string|null, loop: bool}
     */
    private static function end(string $via, ?string $run): array
    {
        return ['kind' => 'end', 'via' => $via, 'step_id' => null, 'position' => null, 'run_outcome' => $run, 'loop' => false];
    }

    /**
     * @param list<int> $ids
     * @return array{step_ids: list<int>, end: string|null, loop_to_step_id: int|null, missing_step_id: int|null}
     */
    private static function path(array $ids, ?string $end, ?int $loopTo = null, ?int $missing = null): array
    {
        return ['step_ids' => $ids, 'end' => $end, 'loop_to_step_id' => $loopTo, 'missing_step_id' => $missing];
    }

    private static function step(
        int $id,
        string $type = 'job',
        ?int $success = null,
        ?int $failure = null,
        ?int $always = null,
        ?string $gated = null,
        ?string $problem = null,
        string $name = ''
    ): WorkflowRouteStep {
        return new WorkflowRouteStep($id, $name === '' ? "step {$id}" : $name, $type, $success, $failure, $always, $gated, $problem);
    }

    /**
     * @return list<int>
     */
    private static function ids(WorkflowRouteMap $map): array
    {
        return array_map(static fn (WorkflowRouteStep $step): int => $step->id, $map->steps());
    }

    /**
     * @return array{0: int|null, 1: int|null, 2: int|null, 3: list<int>}
     */
    private static function effects(WorkflowOrderEffects $effects): array
    {
        return [$effects->startBefore, $effects->startAfter, $effects->firstApprovalAfter, $effects->unguardedJobStepIds];
    }
}
