<?php

declare(strict_types=1);

namespace app\tests\unit\services\runstate;

use app\models\Job;
use app\models\WorkflowJob;
use app\services\runstate\ApprovalWait;
use app\services\runstate\Display;
use app\services\runstate\RunState;
use app\services\runstate\StepState;
use app\services\runstate\Texts;
use PHPUnit\Framework\TestCase;

class DisplayTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function stateData(): array
    {
        return [
            'pending' => [Display::STATE_PENDING, 'Pending', 'secondary'],
            'queued' => [Display::STATE_QUEUED, 'Queued', 'secondary'],
            'running' => [Display::STATE_RUNNING, 'Running', 'primary'],
            'awaiting approval' => [Display::STATE_AWAITING_APPROVAL, 'Awaiting Approval', 'info'],
            'paused' => [Display::STATE_PAUSED, 'Paused', 'warning'],
            'succeeded' => [Display::STATE_SUCCEEDED, 'Succeeded', 'success'],
            'failed' => [Display::STATE_FAILED, 'Failed', 'danger'],
            'canceled' => [Display::STATE_CANCELED, 'Canceled', 'warning'],
            'skipped' => [Display::STATE_SKIPPED, 'Skipped', 'info'],
        ];
    }

    /**
     * @dataProvider stateData
     */
    public function testEveryStateHasItsLabelAndCss(string $state, string $label, string $css): void
    {
        $this->assertSame($label, Display::label($state));
        $this->assertSame($css, Display::css($state));
    }

    public function testTheStateTableCoversEveryStateConstant(): void
    {
        $constants = [];
        foreach ((new \ReflectionClass(Display::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'STATE_')) {
                $constants[] = $value;
            }
        }
        $covered = array_column(self::stateData(), 0);
        sort($constants);
        sort($covered);

        $this->assertSame($covered, $constants);
    }

    public function testWaitingStatesReadAsTheJobStatusTheyComeFrom(): void
    {
        $this->assertSame(Job::statusLabel(Job::STATUS_PENDING_APPROVAL), Display::label(Display::STATE_AWAITING_APPROVAL));
        $this->assertSame(Job::statusCssClass(Job::STATUS_PENDING_APPROVAL), Display::css(Display::STATE_AWAITING_APPROVAL));
        $this->assertSame(Job::statusLabel(Job::STATUS_QUEUED), Display::label(Display::STATE_QUEUED));
        $this->assertSame(Job::statusCssClass(Job::STATUS_QUEUED), Display::css(Display::STATE_QUEUED));
        $this->assertSame(WorkflowJob::statusLabel(WorkflowJob::STATUS_CANCELED), Display::label(Display::STATE_CANCELED));
        $this->assertSame(WorkflowJob::statusCssClass(WorkflowJob::STATUS_CANCELED), Display::css(Display::STATE_CANCELED));
    }

    public function testAnUnknownStateReadsAsItIs(): void
    {
        $this->assertSame('frozen', Display::label('frozen'));
        $this->assertSame('secondary', Display::css('frozen'));
        $this->assertNull(Display::waitingFor('frozen'));
    }

    /**
     * The layout's dark theme draws text-bg-dark on a background of the
     * same colour, as the canceled approval badge was.
     */
    public function testNoStateUsesAColourTheThemeHides(): void
    {
        foreach (array_column(self::stateData(), 0) as $state) {
            $this->assertNotContains(Display::css($state), ['dark', 'light'], $state);
        }
    }

    public function testOnlyTheWaitingStatesWaitForSomething(): void
    {
        $this->assertSame(Display::WAITING_FOR_APPROVAL, Display::waitingFor(Display::STATE_AWAITING_APPROVAL));
        $this->assertSame(Display::WAITING_FOR_RESUME, Display::waitingFor(Display::STATE_PAUSED));
        $this->assertSame(Display::WAITING_FOR_RUNNER, Display::waitingFor(Display::STATE_QUEUED));
        foreach ([Display::STATE_PENDING, Display::STATE_RUNNING, Display::STATE_SUCCEEDED, Display::STATE_FAILED, Display::STATE_CANCELED, Display::STATE_SKIPPED] as $state) {
            $this->assertNull(Display::waitingFor($state), $state);
        }
    }

    public function testWaitingForValuesAreWhatTheFiltersTake(): void
    {
        $this->assertSame(['approval', 'resume', 'runner'], Display::waitingForValues());
        $this->assertSame('approval', Display::WAITING_FOR_APPROVAL);
        $this->assertSame('resume', Display::WAITING_FOR_RESUME);
        $this->assertSame('runner', Display::WAITING_FOR_RUNNER);
    }

    public function testTheValuesOfTheStatesAndRelationsAreStable(): void
    {
        $this->assertSame(
            ['pending', 'queued', 'running', 'awaiting_approval', 'paused', 'succeeded', 'failed', 'canceled', 'skipped'],
            array_column(self::stateData(), 0)
        );
        $this->assertSame('may_decide', Display::RELATION_MAY_DECIDE);
        $this->assertSame('launcher', Display::RELATION_LAUNCHER);
        $this->assertSame('voted', Display::RELATION_VOTED);
        $this->assertSame('not_eligible', Display::RELATION_NOT_ELIGIBLE);
        $this->assertSame('not_approver', Display::RELATION_NOT_APPROVER);
    }

    public function testDurationLabelSaysWhetherAStepRunsOrWaits(): void
    {
        $this->assertNull(Display::durationLabel(null, Display::STATE_RUNNING, false));
        $this->assertNull(Display::durationLabel(null, Display::STATE_SUCCEEDED, true));
        $this->assertSame('running 47s', Display::durationLabel(47, Display::STATE_RUNNING, false));
        $this->assertSame('waiting 2h 13m', Display::durationLabel(7980, Display::STATE_AWAITING_APPROVAL, false));
        $this->assertSame('waiting 1d 2h', Display::durationLabel(93600, Display::STATE_PAUSED, false));
        $this->assertSame('waiting 1m 15s', Display::durationLabel(75, Display::STATE_QUEUED, false));
        $this->assertSame('2h 13m', Display::durationLabel(7980, Display::STATE_SUCCEEDED, true));
        $this->assertSame('1m 15s', Display::durationLabel(75, Display::STATE_CANCELED, true));
        $this->assertSame('running 1m 15s', sprintf(Display::DURATION_RUNNING, '1m 15s'));
        $this->assertSame('waiting 1m 15s', sprintf(Display::DURATION_WAITING, '1m 15s'));
    }

    public function testTheLaunchFlashOfARunThatGoesOn(): void
    {
        $this->assertSame('Workflow "Deploy" launched.', Display::launchFlash('Deploy', $this->runIn(Display::STATE_RUNNING, null)));
        $this->assertSame(
            'Workflow "Deploy" launched.',
            Display::launchFlash('Deploy', $this->runIn(Display::STATE_RUNNING, $this->step('Build', Display::STATE_RUNNING, null)))
        );
        $this->assertSame(
            'Workflow "Deploy" launched.',
            Display::launchFlash('Deploy', $this->runIn(Display::STATE_QUEUED, $this->step('Build', Display::STATE_QUEUED, null)))
        );
    }

    public function testTheLaunchFlashNamesTheStepThatWaitsForApproval(): void
    {
        $approvalStep = $this->runIn(Display::STATE_AWAITING_APPROVAL, $this->step('Approve', Display::STATE_AWAITING_APPROVAL, $this->wait(41)));
        $gatedJob = $this->runIn(Display::STATE_AWAITING_APPROVAL, $this->step('Deploy prod', Display::STATE_AWAITING_APPROVAL, $this->wait(42)));

        $this->assertSame(
            'Workflow "Release" launched. Step "Approve" waits for approval (Approval Request #41).',
            Display::launchFlash('Release', $approvalStep)
        );
        $this->assertSame(
            'Workflow "Release" launched. Step "Deploy prod" waits for approval (Approval Request #42).',
            Display::launchFlash('Release', $gatedJob)
        );
        $this->assertSame(
            sprintf(Texts::LAUNCHED_AWAITING_APPROVAL, 'Release', 'Approve', 41),
            Display::launchFlash('Release', $approvalStep)
        );
    }

    public function testTheLaunchFlashOfAWaitWhoseRequestTheViewerMayNotSeeNamesNoRequest(): void
    {
        $hidden = $this->runIn(Display::STATE_AWAITING_APPROVAL, $this->step('Approve', Display::STATE_AWAITING_APPROVAL, null));

        $this->assertSame('Workflow "Release" launched.', Display::launchFlash('Release', $hidden));
    }

    public function testTheLaunchFlashOfAPausedRun(): void
    {
        $paused = $this->runIn(Display::STATE_PAUSED, $this->step('Check the logs', Display::STATE_PAUSED, null));

        $this->assertSame('Workflow "Release" launched. It is paused at step "Check the logs".', Display::launchFlash('Release', $paused));
    }

    public function testTheLaunchFlashOfARunThatAlreadyFinished(): void
    {
        $finished = new RunState(7, WorkflowJob::STATUS_FAILED, Display::STATE_FAILED, null, null, []);

        $this->assertSame('Workflow "Release" launched.', Display::launchFlash('Release', $finished));
    }

    public function testNoTextHoldsHtml(): void
    {
        foreach ([Display::class, Texts::class] as $class) {
            foreach ((new \ReflectionClass($class))->getConstants() as $name => $value) {
                $this->assertIsString($value, "{$class}::{$name}");
                $this->assertDoesNotMatchRegularExpression('/[<>]/', $value, "{$class}::{$name}");
            }
        }
    }

    public function testTheTextsKeepTheirWording(): void
    {
        $this->assertSame('Waiting for approval at step "Approve".', sprintf(Texts::BANNER_APPROVAL_STEP, 'Approve'));
        $this->assertSame('Job #12 of step "Deploy" waits for approval.', sprintf(Texts::BANNER_GATED_JOB, 12, 'Deploy'));
        $this->assertSame('Job #12 of step "Deploy" is queued: it waits for a runner.', sprintf(Texts::BANNER_QUEUED, 12, 'Deploy'));
        $this->assertSame('Paused at step "Check" until someone resumes the run.', sprintf(Texts::BANNER_PAUSED, 'Check'));
        $this->assertSame(
            'Its approval request no longer exists: the next maintenance run cancels the wait, and the step fails.',
            Texts::BANNER_REQUEST_MISSING
        );
        $this->assertSame('Approval Request #5', sprintf(Texts::FACT_REQUEST, 5));
        $this->assertSame('Rule "Prod"', sprintf(Texts::FACT_RULE, 'Prod'));
        $this->assertSame('Rule deleted', Texts::FACT_RULE_DELETED);
        $this->assertSame('0 of 2 approval(s)', sprintf(Texts::FACT_APPROVALS, 0, 2));
        $this->assertSame('3 approver(s) can still decide', sprintf(Texts::FACT_OPEN_APPROVERS, 3));
        $this->assertSame('waiting since 10:00:00', sprintf(Texts::FACT_SINCE, '10:00:00'));
        $this->assertSame('expires 11:00:00 (then rejected)', sprintf(Texts::FACT_EXPIRES, '11:00:00', Texts::THEN_REJECTED));
        $this->assertSame('never expires', Texts::FACT_NEVER_EXPIRES);
        $this->assertSame(
            'expired 11:00:00; the next maintenance run applies its timeout (then approved)',
            sprintf(Texts::FACT_EXPIRED, '11:00:00', Texts::THEN_APPROVED)
        );
        $this->assertSame('You can decide on this request.', Texts::PERSONAL_MAY_DECIDE);
        $this->assertSame('You launched this run, so another approver must decide.', Texts::PERSONAL_LAUNCHER);
        $this->assertSame('You have voted on this request; it waits for more approvals.', Texts::PERSONAL_VOTED);
        $this->assertSame('You are not an approver of this request.', Texts::PERSONAL_NOT_APPROVER);
        $this->assertSame('You are on the rule\'s approver list, but may not decide on this request.', Texts::PERSONAL_NOT_ELIGIBLE);
        $this->assertSame('Review & decide', Texts::REVIEW_BUTTON);
        $this->assertSame(
            'Too few of the rule\'s approvers can still decide, so this request can no longer be approved. '
            . 'An admin can add approvers to the rule, or cancel the run.',
            Texts::WARNING_UNDECIDABLE
        );
        $this->assertSame('Approved by alice, bob at 10:00:00.', sprintf(Texts::DECIDED_APPROVED_BY, 'alice, bob', '10:00:00'));
        $this->assertSame('Approved at 10:00:00.', sprintf(Texts::DECIDED_APPROVED, '10:00:00'));
        $this->assertSame('Rejected by carol at 10:00:00.', sprintf(Texts::DECIDED_REJECTED_BY, 'carol', '10:00:00'));
        $this->assertSame(
            'Approved by its timeout at 10:00:00, without a decision.',
            sprintf(Texts::DECIDED_TIMEOUT_APPROVED, '10:00:00')
        );
        $this->assertSame('Comment: "ok"', sprintf(Texts::DECIDED_COMMENT, 'ok'));
        $this->assertSame('Workflow "W" launched.', sprintf(Texts::LAUNCHED, 'W'));
        $this->assertSame('Workflow "W" launched. It is paused at step "S".', sprintf(Texts::LAUNCHED_PAUSED, 'W', 'S'));
        $this->assertSame('W › S (Workflow Job #3)', sprintf(Texts::FOR_WORKFLOW_STEP, 'W', 'S', 3));
        $this->assertSame('T (job template)', sprintf(Texts::FOR_JOB_TEMPLATE, 'T'));
        $this->assertSame('T (deleted job template)', sprintf(Texts::FOR_DELETED_JOB_TEMPLATE, 'T'));
        $this->assertSame('This job waits for Approval Request #4.', sprintf(Texts::JOB_APPROVAL_NOTE, 4));
        $this->assertSame('#3, step "S"', sprintf(Texts::JOB_WORKFLOW_ORIGIN, 3, 'S'));
        $this->assertSame('Approval step "S" of Workflow Job #3', sprintf(Texts::PLACEHOLDER_SUBTITLE, 'S', 3));
        $this->assertSame('none (placeholder of an approval step)', Texts::PLACEHOLDER_TEMPLATE);
        $this->assertSame('Approval step "S" (Workflow Job #3)', sprintf(Texts::PLACEHOLDER_LABEL, 'S', 3));
        $this->assertSame(
            'This job only holds the approval request of step "S" in Workflow Job #3 and never runs: approved, the run '
            . 'goes on; rejected, timed out or canceled, the step fails.',
            sprintf(Texts::PLACEHOLDER_NOTE, 'S', 3)
        );
        $this->assertSame('Active runs', Texts::ACTIVE_RUNS_TITLE);
        $this->assertSame('at step "S" since 10:00:00', sprintf(Texts::ACTIVE_RUN_AT, 'S', '10:00:00'));
        $this->assertSame('launched by alice', sprintf(Texts::ACTIVE_RUN_BY, 'alice'));
        $this->assertSame('and 2 more', sprintf(Texts::ACTIVE_RUNS_MORE, 2));
        $this->assertSame('All runs of this workflow', Texts::ACTIVE_RUNS_ALL);
        $this->assertSame('2 run(s) waiting', sprintf(Texts::STEP_WAITING_MARKER, 2));
    }

    private function runIn(string $state, ?StepState $current): RunState
    {
        return new RunState(7, WorkflowJob::STATUS_RUNNING, $state, Display::waitingFor($state), $current, []);
    }

    private function step(string $name, string $state, ?ApprovalWait $approval): StepState
    {
        return new StepState(
            executionId: 70,
            workflowStepId: 700,
            stepName: $name,
            stepType: 'job',
            status: 'running',
            state: $state,
            waitingFor: Display::waitingFor($state),
            jobId: 7000,
            jobStatus: null,
            jobTemplateName: null,
            startedAt: 1760000000,
            finishedAt: null,
            waitingSince: null,
            errorMessage: null,
            approval: $approval,
            decisionNote: null,
        );
    }

    private function wait(int $requestId): ApprovalWait
    {
        return new ApprovalWait(
            requestId: $requestId,
            jobId: 7000,
            status: 'pending',
            ruleId: 3,
            ruleName: 'Prod',
            requiredApprovals: 1,
            approvalCount: 0,
            rejectionCount: 0,
            requestedAt: 1760000000,
            expiresAt: null,
            resolvedAt: null,
            timeoutAction: null,
            linkable: true,
        );
    }
}
