<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\components\WorkflowStepChange;
use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\services\WorkflowStepChangeSummary;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowStepChangeSummary (contract C8): the flash and the API's change
 * answer for every kind of step change. The message says where the step
 * landed (or which fields an edit changed), then how the empty branches of
 * the other steps now lead elsewhere; explicit branches never move and are
 * not mentioned. The warnings name a changed start step, the job steps that
 * runs now reach without passing an approval step, and the approval steps
 * off the success path, all worked out from the branches as runs follow
 * them.
 *
 * The step models are built in memory with their relations loaded (a job
 * step has its job template, an approval step its approval rule), so the
 * summary reads nothing from the database.
 */
class WorkflowStepChangeSummaryTest extends TestCase
{
    public function testTheTextsKeepTheirWording(): void
    {
        $this->assertSame(
            [
                'ADDED' => 'Step "%1$s" added at position %2$d of %3$d%4$s.',
                'MOVED' => 'Step "%1$s" moved to position %2$d of %3$d%4$s.',
                'SAVED' => 'Step "%1$s" saved: %2$s changed.',
                'SAVED_MOVED' => 'Step "%1$s" saved: %2$s changed; it is now at position %3$d of %4$d%5$s.',
                'REMOVED' => 'Step "%1$s" removed; it was at position %2$d of %3$d.',
                'UNCHANGED' => 'Step "%s" is unchanged.',
                'STAYS' => 'Step "%1$s" stays at position %2$d.',
                'AT_START' => ', at the start',
                'AFTER' => ', after "%s"',
                'FIELD_LABELS' => [
                    'name' => 'name',
                    'job_template_id' => 'job template',
                    'approval_rule_id' => 'approval rule',
                    'on_success_step_id' => 'On Success',
                    'on_failure_step_id' => 'On Failure',
                    'on_always_step_id' => 'Always',
                    'extra_vars_template' => 'variable mapping',
                ],
                'FIELD_SEPARATOR' => ', ',
                'NOW_CONTINUES' => '"%1$s" now continues with "%2$s"%3$s (was %4$s).',
                'NOW_ENDS' => '"%1$s" now ends the run%2$s (was "%3$s").',
                'ALSO_AFTER_FAILURE' => ', also after a failure',
                'AFTER_FAILURE' => ' after a failure',
                'AFTER_SUCCESS' => ' after a success',
                'WAS_STEP' => '"%s"',
                'WAS_END' => 'the end of the run',
                'START_CHANGED' => 'Runs now start with "%1$s" (was "%2$s").',
                'JOB_BEFORE_APPROVAL' => 'Job step "%1$s" now runs before the approval step "%2$s": '
                    . 'runs start it without waiting for that approval.',
                'JOB_WITHOUT_APPROVAL' => 'Job step "%s" no longer comes after an approval step: '
                    . 'runs start it without waiting for an approval.',
                'APPROVAL_OFF_SUCCESS_PATH' => 'The approval step "%s" is not on the success path: '
                    . 'runs whose steps all succeed do not wait for it.',
            ],
            (new \ReflectionClass(WorkflowStepChangeSummary::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC)
        );
    }

    // -- Added --------------------------------------------------------------------------

    public function testTheFirstStepAddedNamesNoNeighbourAndNoNewStart(): void
    {
        $build = self::model(1, 'Build');

        $summary = self::summary(WorkflowStepChange::ADDED, $build, 0, 1, [], [$build]);

        $this->assertSame('Step "Build" added at position 1 of 1.', $summary->message());
        $this->assertSame([], $summary->warnings());
        $this->assertFalse($summary->isInfo());
    }

    public function testAStepAddedAtTheEndTakesOverTheEmptyBranchesOfTheLastStep(): void
    {
        $build = self::model(1, 'Build');
        $test = self::model(2, 'Test');

        $summary = self::summary(WorkflowStepChange::ADDED, $test, 0, 2, [$build], [$build, $test]);

        $this->assertSame(
            'Step "Test" added at position 2 of 2, after "Build". '
            . '"Build" now continues with "Test", also after a failure (was the end of the run).',
            $summary->message()
        );
        $this->assertSame([], $summary->warnings());
    }

    public function testAStepAddedAtTheStartIsTheNewStartStep(): void
    {
        $build = self::model(1, 'Build');
        $deploy = self::model(2, 'Deploy');
        $lint = self::model(3, 'Lint');

        $summary = self::summary(WorkflowStepChange::ADDED, $lint, 0, 1, [$build, $deploy], [$lint, $build, $deploy]);

        $this->assertSame('Step "Lint" added at position 1 of 3, at the start.', $summary->message());
        $this->assertSame(['Runs now start with "Lint" (was "Build").'], $summary->warnings());
    }

    public function testAStepAddedAfterAnotherNamesWhatThatStepLedTo(): void
    {
        $build = self::model(1, 'Build', 'job', null, 0);
        $deploy = self::model(2, 'Deploy');
        $check = self::model(3, 'Check');

        $summary = self::summary(WorkflowStepChange::ADDED, $check, 0, 2, [$build, $deploy], [$build, $check, $deploy]);

        $this->assertSame(
            'Step "Check" added at position 2 of 3, after "Build". "Build" now continues with "Check" (was "Deploy").',
            $summary->message()
        );
        $this->assertSame(
            ['message' => $summary->message(), 'warnings' => []],
            $summary->toArray()
        );
    }

    public function testAJobStepAddedBeforeTheApprovalStepIsWarnedAbout(): void
    {
        $approve = self::model(1, 'Approve', 'approval');
        $deploy = self::model(2, 'Deploy');
        $pre = self::model(3, 'Pre');

        $summary = self::summary(WorkflowStepChange::ADDED, $pre, 0, 1, [$approve, $deploy], [$pre, $approve, $deploy]);

        $this->assertSame('Step "Pre" added at position 1 of 3, at the start.', $summary->message());
        $this->assertSame(
            [
                'Runs now start with "Pre" (was "Approve").',
                'Job step "Pre" now runs before the approval step "Approve": runs start it without waiting for that approval.',
            ],
            $summary->warnings()
        );
        $this->assertSame(['message' => $summary->message(), 'warnings' => $summary->warnings()], $summary->toArray());
    }

    /**
     * regression: an approval step added after a step whose On Success names
     * the next step is skipped by every run that succeeds; the flash and the
     * API's change.warnings said nothing.
     */
    public function testAnApprovalStepAddedWhereNoSuccessLeadsIsWarnedAbout(): void
    {
        $build = self::model(1, 'Build', 'job', 2);
        $deploy = self::model(2, 'Deploy');
        $approve = self::model(3, 'Approve', 'approval');

        $summary = self::summary(WorkflowStepChange::ADDED, $approve, 0, 2, [$build, $deploy], [$build, $approve, $deploy]);

        $this->assertSame(
            'Step "Approve" added at position 2 of 3, after "Build". "Build" now continues with "Approve" after a failure (was "Deploy").',
            $summary->message()
        );
        $this->assertSame(
            ['The approval step "Approve" is not on the success path: runs whose steps all succeed do not wait for it.'],
            $summary->warnings()
        );
        $this->assertSame(['message' => $summary->message(), 'warnings' => $summary->warnings()], $summary->toArray());
    }

    public function testAnApprovalStepAddedOnTheSuccessPathWarnsAboutNothing(): void
    {
        $build = self::model(1, 'Build');
        $deploy = self::model(2, 'Deploy');
        $approve = self::model(3, 'Approve', 'approval');

        $summary = self::summary(WorkflowStepChange::ADDED, $approve, 0, 2, [$build, $deploy], [$build, $approve, $deploy]);

        $this->assertSame([], $summary->warnings());
    }

    // -- Moved --------------------------------------------------------------------------

    public function testAMoveNamesEveryEmptyBranchThatNowLeadsElsewhere(): void
    {
        $build = self::model(1, 'Build');
        $test = self::model(2, 'Test');
        $lint = self::model(3, 'Lint');

        $summary = self::summary(WorkflowStepChange::MOVED, $lint, 3, 2, [$build, $test, $lint], [$build, $lint, $test]);

        $this->assertSame(
            'Step "Lint" moved to position 2 of 3, after "Build". '
            . '"Build" now continues with "Lint", also after a failure (was "Test"). '
            . '"Lint" now continues with "Test", also after a failure (was the end of the run). '
            . '"Test" now ends the run (was "Lint").',
            $summary->message()
        );
        $this->assertSame([], $summary->warnings());
        $this->assertFalse($summary->isInfo());
    }

    public function testAnApprovalStepMovedAfterJobStepsNamesEachOfThem(): void
    {
        $approve = self::model(1, 'Approve', 'approval');
        $deploy = self::model(2, 'Deploy');
        $verify = self::model(3, 'Verify');

        $summary = self::summary(
            WorkflowStepChange::MOVED,
            $approve,
            1,
            3,
            [$approve, $deploy, $verify],
            [$deploy, $verify, $approve]
        );

        $this->assertSame(
            'Step "Approve" moved to position 3 of 3, after "Verify". '
            . '"Verify" now continues with "Approve", also after a failure (was the end of the run). '
            . '"Approve" now ends the run (was "Deploy").',
            $summary->message(),
            'an approval step cannot fail: no "after a success"'
        );
        $this->assertSame(
            [
                'Runs now start with "Deploy" (was "Approve").',
                'Job step "Deploy" now runs before the approval step "Approve": runs start it without waiting for that approval.',
                'Job step "Verify" now runs before the approval step "Approve": runs start it without waiting for that approval.',
            ],
            $summary->warnings()
        );
    }

    public function testOnlyTheFirstApprovalStepCounts(): void
    {
        $first = self::model(1, 'A1', 'approval');
        $job = self::model(2, 'Job');
        $second = self::model(3, 'A2', 'approval');

        $summary = self::summary(WorkflowStepChange::MOVED, $first, 1, 3, [$first, $job, $second], [$job, $second, $first]);

        $this->assertSame(
            'Step "A1" moved to position 3 of 3, after "A2". '
            . '"A2" now continues with "A1" (was the end of the run). "A1" now ends the run (was "Job").',
            $summary->message()
        );
        $this->assertSame(
            [
                'Runs now start with "Job" (was "A1").',
                'Job step "Job" now runs before the approval step "A2": runs start it without waiting for that approval.',
            ],
            $summary->warnings()
        );
    }

    /**
     * regression: an approval step moved after a step whose On Success
     * names another step was skipped by every run that succeeded, and the
     * move warned about nothing.
     */
    public function testAnApprovalStepMovedWhereNoSuccessLeadsIsWarnedAbout(): void
    {
        $build = self::model(1, 'Build', 'job', 2, 0);
        $deploy = self::model(2, 'Deploy');
        $approve = self::model(3, 'Approve', 'approval');

        $summary = self::summary(WorkflowStepChange::MOVED, $approve, 3, 2, [$build, $deploy, $approve], [$build, $approve, $deploy]);

        $this->assertSame(
            ['The approval step "Approve" is not on the success path: runs whose steps all succeed do not wait for it.'],
            $summary->warnings()
        );
    }

    // -- Removed ------------------------------------------------------------------------

    public function testARemovedStepHandsItsPlaceToTheNextOne(): void
    {
        $build = self::model(1, 'Build');
        $lint = self::model(2, 'Lint');
        $test = self::model(3, 'Test');

        $summary = self::summary(WorkflowStepChange::REMOVED, $lint, 2, 0, [$build, $lint, $test], [$build, $test]);

        $this->assertSame(
            'Step "Lint" removed; it was at position 2 of 3. "Build" now continues with "Test", also after a failure (was "Lint").',
            $summary->message()
        );
        $this->assertSame([], $summary->warnings());
    }

    public function testRemovingTheLastStepEndsTheRunEarlier(): void
    {
        $build = self::model(1, 'Build');
        $lint = self::model(2, 'Lint');

        $summary = self::summary(WorkflowStepChange::REMOVED, $lint, 2, 0, [$build, $lint], [$build]);

        $this->assertSame('Step "Lint" removed; it was at position 2 of 2. "Build" now ends the run (was "Lint").', $summary->message());
    }

    public function testRemovingTheOnlyStepWarnsAboutNothing(): void
    {
        $build = self::model(1, 'Build');

        $summary = self::summary(WorkflowStepChange::REMOVED, $build, 1, 0, [$build], []);

        $this->assertSame('Step "Build" removed; it was at position 1 of 1.', $summary->message());
        $this->assertSame([], $summary->warnings());
    }

    public function testRemovingTheStartStepNamesTheNewOne(): void
    {
        $build = self::model(1, 'Build');
        $test = self::model(2, 'Test');

        $summary = self::summary(WorkflowStepChange::REMOVED, $build, 1, 0, [$build, $test], [$test]);

        $this->assertSame('Step "Build" removed; it was at position 1 of 2.', $summary->message());
        $this->assertSame(['Runs now start with "Test" (was "Build").'], $summary->warnings());
    }

    public function testRemovingTheOnlyApprovalStepUnguardsTheJobStepsAfterIt(): void
    {
        $approve = self::model(1, 'Approve', 'approval');
        $deploy = self::model(2, 'Deploy');

        $summary = self::summary(WorkflowStepChange::REMOVED, $approve, 1, 0, [$approve, $deploy], [$deploy]);

        $this->assertSame(
            [
                'Runs now start with "Deploy" (was "Approve").',
                'Job step "Deploy" no longer comes after an approval step: runs start it without waiting for an approval.',
            ],
            $summary->warnings()
        );
    }

    // -- Updated ------------------------------------------------------------------------

    /**
     * regression: an edit of a branch that leads past the approval step let
     * runs start the job step after it without the approval, and took the
     * approval step off the success path, and no warning said so.
     */
    public function testABranchPastTheApprovalStepWarnsAboutTheJobStepAndTheApprovalStep(): void
    {
        $build = self::model(1, 'Build', 'job', null, 0);
        $edited = self::model(1, 'Build', 'job', 3, 0);
        $approve = self::model(2, 'Approve', 'approval');
        $deploy = self::model(3, 'Deploy');

        $summary = self::summary(
            WorkflowStepChange::UPDATED,
            $edited,
            1,
            1,
            [$build, $approve, $deploy],
            [$edited, $approve, $deploy],
            ['on_success_step_id' => ['from' => null, 'to' => 3]]
        );

        $this->assertSame('Step "Build" saved: On Success changed.', $summary->message());
        $this->assertSame(
            [
                'Job step "Deploy" now runs before the approval step "Approve": runs start it without waiting for that approval.',
                'The approval step "Approve" is not on the success path: runs whose steps all succeed do not wait for it.',
            ],
            $summary->warnings()
        );
    }

    public function testAnEditThatLetsAFailedJobPassTheApprovalStepWarnsAboutTheJobStepOnly(): void
    {
        $build = self::model(1, 'Build', 'job', null, 0);
        $edited = self::model(1, 'Build', 'job', null, 3);
        $approve = self::model(2, 'Approve', 'approval');
        $deploy = self::model(3, 'Deploy');

        $summary = self::summary(
            WorkflowStepChange::UPDATED,
            $edited,
            1,
            1,
            [$build, $approve, $deploy],
            [$edited, $approve, $deploy],
            ['on_failure_step_id' => ['from' => 0, 'to' => 3]]
        );

        $this->assertSame(
            ['Job step "Deploy" now runs before the approval step "Approve": runs start it without waiting for that approval.'],
            $summary->warnings(),
            'a run that succeeds still waits for the approval'
        );
    }

    public function testRenamingAnApprovalStepOffTheSuccessPathSaysItIsOff(): void
    {
        $build = self::model(1, 'Build', 'job', 3);
        $approve = self::model(2, 'Approve', 'approval');
        $renamed = self::model(2, 'Gate', 'approval');
        $deploy = self::model(3, 'Deploy');

        $summary = self::summary(
            WorkflowStepChange::UPDATED,
            $renamed,
            2,
            2,
            [$build, $approve, $deploy],
            [$build, $renamed, $deploy],
            ['name' => ['from' => 'Approve', 'to' => 'Gate']]
        );

        $this->assertSame(
            ['The approval step "Gate" is not on the success path: runs whose steps all succeed do not wait for it.'],
            $summary->warnings()
        );
    }

    public function testASavedStepListsTheChangedFieldsInTheirOrder(): void
    {
        $build = self::model(1, 'Build');
        $before = self::model(2, 'Deploy');
        $after = self::model(2, 'Ship', 'job', null, 0);

        $summary = self::summary(
            WorkflowStepChange::UPDATED,
            $after,
            2,
            2,
            [$build, $before],
            [$build, $after],
            ['on_failure_step_id' => ['from' => null, 'to' => 0], 'name' => ['from' => 'Deploy', 'to' => 'Ship']]
        );

        $this->assertSame('Step "Ship" saved: name, On Failure changed.', $summary->message(), 'an explicit branch never moves');
        $this->assertSame([], $summary->warnings());
        $this->assertFalse($summary->isInfo());
    }

    public function testEveryTrackedFieldHasALabel(): void
    {
        $deploy = self::model(1, 'Deploy');
        $changes = [];
        foreach (array_reverse(array_keys(WorkflowStepChangeSummary::FIELD_LABELS)) as $field) {
            $changes[$field] = ['from' => 1, 'to' => 2];
        }

        $summary = self::summary(WorkflowStepChange::UPDATED, $deploy, 1, 1, [$deploy], [$deploy], $changes);

        $this->assertSame(
            'Step "Deploy" saved: name, job template, approval rule, On Success, On Failure, Always, variable mapping changed.',
            $summary->message()
        );
    }

    public function testAFieldWithoutALabelIsListedByItsName(): void
    {
        $deploy = self::model(1, 'Deploy');

        $summary = self::summary(
            WorkflowStepChange::UPDATED,
            $deploy,
            1,
            1,
            [$deploy],
            [$deploy],
            ['step_type' => ['from' => 'job', 'to' => 'pause'], 'name' => ['from' => 'Build', 'to' => 'Deploy']]
        );

        $this->assertSame('Step "Deploy" saved: name, step_type changed.', $summary->message());
    }

    public function testASavedStepThatMovedSaysWhereItIsNow(): void
    {
        $build = self::model(1, 'Build');
        $deploy = self::model(2, 'Deploy');
        $ship = self::model(2, 'Ship');
        $test = self::model(3, 'Test');

        $summary = self::summary(
            WorkflowStepChange::UPDATED,
            $ship,
            2,
            1,
            [$build, $deploy, $test],
            [$ship, $build, $test],
            ['name' => ['from' => 'Deploy', 'to' => 'Ship'], 'position' => ['from' => 2, 'to' => 1]]
        );

        $this->assertSame(
            'Step "Ship" saved: name changed; it is now at position 1 of 3, at the start. '
            . '"Ship" now continues with "Build", also after a failure (was "Test"). '
            . '"Build" now continues with "Test", also after a failure (was "Ship").',
            $summary->message(),
            'every step is named as the table names it now'
        );
        $this->assertSame(['Runs now start with "Ship" (was "Build").'], $summary->warnings());
    }

    /**
     * A step that was renamed is named as it is called now, also where it
     * was the old start step; a removed step keeps the only name it had.
     */
    public function testARenamedStartStepIsNamedAsItIsCalledNow(): void
    {
        $build = self::model(1, 'Build');
        $make = self::model(1, 'Make');
        $test = self::model(2, 'Test');

        $summary = self::summary(
            WorkflowStepChange::UPDATED,
            $make,
            1,
            2,
            [$build, $test],
            [$test, $make],
            ['name' => ['from' => 'Build', 'to' => 'Make'], 'position' => ['from' => 1, 'to' => 2]]
        );

        $this->assertSame(
            'Step "Make" saved: name changed; it is now at position 2 of 2, after "Test". '
            . '"Test" now continues with "Make", also after a failure (was the end of the run). '
            . '"Make" now ends the run (was "Test").',
            $summary->message()
        );
        $this->assertSame(['Runs now start with "Test" (was "Make").'], $summary->warnings());
    }

    /**
     * W0's open issue: an edit that changed only the position has no field
     * to list, so it reads as a move.
     */
    public function testASavedStepThatOnlyMovedReadsAsAMove(): void
    {
        $build = self::model(1, 'Build');
        $deploy = self::model(2, 'Deploy');

        $summary = self::summary(
            WorkflowStepChange::UPDATED,
            $deploy,
            2,
            1,
            [$build, $deploy],
            [$deploy, $build],
            ['position' => ['from' => 2, 'to' => 1]]
        );

        $this->assertSame(
            'Step "Deploy" moved to position 1 of 2, at the start. '
            . '"Deploy" now continues with "Build", also after a failure (was the end of the run). '
            . '"Build" now ends the run (was "Deploy").',
            $summary->message()
        );
        $this->assertSame(['Runs now start with "Deploy" (was "Build").'], $summary->warnings());
        $this->assertFalse($summary->isInfo());
    }

    // -- Nothing changed ----------------------------------------------------------------

    public function testAnUnchangedStepIsAnInfo(): void
    {
        $build = self::model(1, 'Build');
        $deploy = self::model(2, 'Deploy');

        $summary = self::summary(WorkflowStepChange::UNCHANGED, $build, 1, 1, [$build, $deploy], [$build, $deploy]);

        $this->assertSame('Step "Build" is unchanged.', $summary->message());
        $this->assertSame([], $summary->warnings());
        $this->assertTrue($summary->isInfo());
        $this->assertSame(['message' => 'Step "Build" is unchanged.', 'warnings' => []], $summary->toArray());
    }

    public function testAMoveThatKeptItsPlaceStays(): void
    {
        $build = self::model(1, 'Build');
        $deploy = self::model(2, 'Deploy');

        $summary = self::summary(WorkflowStepChange::MOVED, $build, 1, 1, [$build, $deploy], [$build, $deploy]);

        $this->assertSame('Step "Build" stays at position 1.', $summary->message());
        $this->assertSame([], $summary->warnings());
        $this->assertTrue($summary->isInfo());
    }

    public function testAnUpdateWithoutAnyChangeIsUnchanged(): void
    {
        $build = self::model(1, 'Build');

        $summary = self::summary(WorkflowStepChange::UPDATED, $build, 1, 1, [$build], [$build], ['position' => ['from' => 1, 'to' => 1]]);

        $this->assertSame('Step "Build" is unchanged.', $summary->message());
        $this->assertTrue($summary->isInfo());
    }

    // -- Which branches a sentence is about ---------------------------------------------

    public function testOnlyTheFailureBranchCanLeadElsewhere(): void
    {
        $build = self::model(1, 'Build', 'job', 3);
        $test = self::model(2, 'Test');
        $deploy = self::model(3, 'Deploy');
        $lint = self::model(4, 'Lint');

        $summary = self::summary(WorkflowStepChange::ADDED, $lint, 0, 2, [$build, $test, $deploy], [$build, $lint, $test, $deploy]);

        $this->assertSame(
            'Step "Lint" added at position 2 of 4, after "Build". "Build" now continues with "Lint" after a failure (was "Test").',
            $summary->message()
        );
    }

    public function testOnlyTheFailureBranchCanEndTheRun(): void
    {
        $build = self::model(1, 'Build', 'job', 0);
        $last = self::model(2, 'Last');

        $summary = self::summary(WorkflowStepChange::REMOVED, $last, 2, 0, [$build, $last], [$build]);

        $this->assertSame(
            'Step "Last" removed; it was at position 2 of 2. "Build" now ends the run after a failure (was "Last").',
            $summary->message()
        );
    }

    public function testOnlyTheSuccessBranchOfAStepThatCanFailEndsTheRun(): void
    {
        $build = self::model(1, 'Build', 'job', null, 0);
        $last = self::model(2, 'Last');

        $summary = self::summary(WorkflowStepChange::REMOVED, $last, 2, 0, [$build, $last], [$build]);

        $this->assertSame(
            'Step "Last" removed; it was at position 2 of 2. "Build" now ends the run after a success (was "Last").',
            $summary->message()
        );
    }

    public function testAPauseStepThatNowEndsTheRunNeedsNoOutcome(): void
    {
        $wait = self::model(1, 'Wait', 'pause');
        $last = self::model(2, 'Last');

        $summary = self::summary(WorkflowStepChange::REMOVED, $last, 2, 0, [$wait, $last], [$wait]);

        $this->assertSame('Step "Last" removed; it was at position 2 of 2. "Wait" now ends the run (was "Last").', $summary->message());
    }

    public function testExplicitBranchesAreNotMentioned(): void
    {
        $build = self::model(1, 'Build', 'job', 2, 0);
        $deploy = self::model(2, 'Deploy');
        $lint = self::model(3, 'Lint');
        $always = self::model(4, 'Always', 'job', null, null, 2);

        $added = self::summary(WorkflowStepChange::ADDED, $lint, 0, 2, [$build, $deploy], [$build, $lint, $deploy]);
        $alwaysAdded = self::summary(WorkflowStepChange::ADDED, $lint, 0, 2, [$always, $deploy], [$always, $lint, $deploy]);

        $this->assertSame('Step "Lint" added at position 2 of 3, after "Build".', $added->message());
        $this->assertSame('Step "Lint" added at position 2 of 3, after "Always".', $alwaysAdded->message(), 'Always decides both');
    }

    public function testNamesAreQuotedAsTheyAre(): void
    {
        $build = self::model(1, '<b>"Build"</b>');

        $summary = self::summary(WorkflowStepChange::ADDED, $build, 0, 1, [], [$build]);

        $this->assertSame('Step "<b>"Build"</b>" added at position 1 of 1.', $summary->message(), 'the layout and JSON encode them');
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * @param 'added'|'updated'|'moved'|'removed'|'unchanged' $kind
     * @param list<WorkflowStep> $before
     * @param list<WorkflowStep> $after
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    private static function summary(
        string $kind,
        WorkflowStep $step,
        int $from,
        int $to,
        array $before,
        array $after,
        array $changes = []
    ): WorkflowStepChangeSummary {
        return WorkflowStepChangeSummary::of(new WorkflowStepChange($kind, $step, $from, $to, $before, $after, $changes, 'web'));
    }

    /**
     * A step as the write path reads it, with its relations loaded: a job
     * step has its job template (without an approval rule), an approval
     * step its approval rule, so that runs can run them.
     */
    private static function model(
        int $id,
        string $name,
        string $type = 'job',
        ?int $success = null,
        ?int $failure = null,
        ?int $always = null
    ): WorkflowStep {
        $step = new WorkflowStep();
        $step->id = $id;
        $step->name = $name;
        $step->step_type = $type;
        $step->on_success_step_id = $success;
        $step->on_failure_step_id = $failure;
        $step->on_always_step_id = $always;
        $template = null;
        if ($type === WorkflowStep::TYPE_JOB) {
            $template = new JobTemplate();
            $template->id = 100 + $id;
            $template->approval_rule_id = null;
            $template->populateRelation('approvalRule', null);
        }
        $rule = null;
        if ($type === WorkflowStep::TYPE_APPROVAL) {
            $rule = new ApprovalRule();
            $rule->id = 200 + $id;
        }
        $step->job_template_id = $template?->id;
        $step->approval_rule_id = $rule?->id;
        $step->populateRelation('jobTemplate', $template);
        $step->populateRelation('approvalRule', $rule);

        return $step;
    }
}
