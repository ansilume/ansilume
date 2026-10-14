<?php

declare(strict_types=1);

namespace app\tests\integration\services\runstate;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalSettlement;
use app\services\runstate\ApprovalWait;
use app\services\runstate\Banner;
use app\services\runstate\Display;
use app\services\runstate\RunState;
use app\services\runstate\StepState;
use app\services\runstate\Texts;
use app\services\runstate\Viewer;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\TeamScopeFixtures;

/**
 * The run page's banner: what a waiting run waits for, as the page renders
 * it and the poll answers it. Every text is quoted from Texts; times are
 * absolute, so the banner changes only when the wait does.
 */
class BannerTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use CountsQueries;

    /** 2025-10-09 10:00:00 local time: the clock of these tests. */
    private int $now = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = (int)mktime(10, 0, 0, 10, 9, 2025);
    }

    public function testAnApprovalStepThatWaitsNamesItsRequestRuleCountsAndExpiry(): void
    {
        $wait = $this->wait(requestId: 12, ruleName: 'Deploy <gate>', required: 2, approvals: 1, openCount: 3, expiresAt: $this->now + 3600);
        $banner = Banner::of($this->waiting($this->step(WorkflowStep::TYPE_APPROVAL, 'approve', $wait), Display::STATE_AWAITING_APPROVAL), $this->now);

        $this->assertSame([
            'kind' => Banner::KIND_APPROVAL,
            'tone' => Banner::TONE_INFO,
            'title' => 'Waiting for approval at step "approve".',
            'facts' => [
                ['text' => 'Approval Request #12', 'request' => true, 'href' => '/approval/view?id=12'],
                ['text' => 'Rule "Deploy <gate>"'],
                ['text' => '1 of 2 approval(s)'],
                ['text' => '3 approver(s) can still decide'],
                ['text' => 'waiting since ' . date('H:i:s', $this->now - 600)],
                ['text' => 'expires ' . date('H:i:s', $this->now + 3600) . ' (then rejected)'],
            ],
            'personal' => [
                'text' => Texts::PERSONAL_MAY_DECIDE,
                'action' => ['text' => 'Review & decide', 'href' => '/approval/view?id=12'],
            ],
            'warning' => null,
        ], $banner);
        $this->assertSame('approval', Banner::KIND_APPROVAL);
    }

    public function testTheJobOfAGatedJobStepIsNamedWithItsStep(): void
    {
        $step = $this->step(WorkflowStep::TYPE_JOB, 'deploy', $this->wait(), jobId: 34);

        $banner = Banner::of($this->waiting($step, Display::STATE_AWAITING_APPROVAL), $this->now);

        $this->assertNotNull($banner);
        $this->assertSame('Job #34 of step "deploy" waits for approval.', $banner['title']);
        $this->assertSame(sprintf(Texts::BANNER_GATED_JOB, 34, 'deploy'), $banner['title']);
    }

    /**
     * Without approval.view the request is named but not linked, and nobody
     * is sent to a page they may not open.
     */
    public function testTheRequestIsLinkedOnlyForAViewerWhoMayOpenItsPage(): void
    {
        $wait = $this->wait(linkable: false, relation: Display::RELATION_MAY_DECIDE);

        $banner = Banner::of($this->waiting($this->step(WorkflowStep::TYPE_APPROVAL, 'approve', $wait), Display::STATE_AWAITING_APPROVAL), $this->now);

        $this->assertNotNull($banner);
        $this->assertSame(['text' => 'Approval Request #12', 'request' => true], $banner['facts'][0]);
        $this->assertSame(['text' => Texts::PERSONAL_MAY_DECIDE], $banner['personal'], 'no Review & decide');
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function relationData(): array
    {
        return [
            'launcher' => [Display::RELATION_LAUNCHER, Texts::PERSONAL_LAUNCHER],
            'voted' => [Display::RELATION_VOTED, Texts::PERSONAL_VOTED],
            'on the list, may not decide' => [Display::RELATION_NOT_ELIGIBLE, Texts::PERSONAL_NOT_ELIGIBLE],
            'not an approver' => [Display::RELATION_NOT_APPROVER, Texts::PERSONAL_NOT_APPROVER],
            'no relation' => [null, null],
        ];
    }

    /**
     * Only a viewer who may decide now is offered Review & decide.
     *
     * @dataProvider relationData
     */
    public function testThePersonalLineSaysHowTheViewerStandsToTheRequest(?string $relation, ?string $text): void
    {
        $wait = $this->wait(relation: $relation);

        $banner = Banner::of($this->waiting($this->step(WorkflowStep::TYPE_APPROVAL, 'approve', $wait), Display::STATE_AWAITING_APPROVAL), $this->now);

        $this->assertNotNull($banner);
        $this->assertSame($text === null ? null : ['text' => $text], $banner['personal']);
    }

    /**
     * @return array<string, array{0: bool|null, 1: string, 2: int|null, 3: bool}>
     */
    public static function warningData(): array
    {
        $grace = ApprovalSettlement::APPROVE_TIMEOUT_GRACE_SECONDS;

        return [
            'too few approvers' => [false, ApprovalRule::TIMEOUT_ACTION_REJECT, 3600, true],
            'enough approvers' => [true, ApprovalRule::TIMEOUT_ACTION_REJECT, 3600, false],
            'not computed' => [null, ApprovalRule::TIMEOUT_ACTION_REJECT, 3600, false],
            'too few, but the timeout approves' => [false, ApprovalRule::TIMEOUT_ACTION_APPROVE, 3600, false],
            'too few, expired, the timeout still approves' => [false, ApprovalRule::TIMEOUT_ACTION_APPROVE, -$grace, false],
            'too few, expired past the grace period: the timeout rejects' => [false, ApprovalRule::TIMEOUT_ACTION_APPROVE, -$grace - 1, true],
            'too few, without a timeout' => [false, ApprovalRule::TIMEOUT_ACTION_APPROVE, null, true],
        ];
    }

    /**
     * @dataProvider warningData
     */
    public function testTheWarningWhenTooFewApproversCanStillDecide(?bool $approvable, string $timeoutAction, ?int $expiresIn, bool $warned): void
    {
        $wait = $this->wait(approvable: $approvable, timeoutAction: $timeoutAction, expiresAt: $expiresIn === null ? null : $this->now + $expiresIn);

        $banner = Banner::of($this->waiting($this->step(WorkflowStep::TYPE_APPROVAL, 'approve', $wait), Display::STATE_AWAITING_APPROVAL), $this->now);

        $this->assertNotNull($banner);
        $this->assertSame($warned ? Texts::WARNING_UNDECIDABLE : null, $banner['warning']);
    }

    /**
     * @return array<string, array{0: int|null, 1: string, 2: string}>
     */
    public static function expiryData(): array
    {
        $grace = ApprovalSettlement::APPROVE_TIMEOUT_GRACE_SECONDS;

        return [
            'no timeout' => [null, ApprovalRule::TIMEOUT_ACTION_REJECT, 'never expires'],
            'expires, rejects' => [60, ApprovalRule::TIMEOUT_ACTION_REJECT, 'expires %s (then rejected)'],
            'expires, approves' => [60, ApprovalRule::TIMEOUT_ACTION_APPROVE, 'expires %s (then approved)'],
            'expired now' => [0, ApprovalRule::TIMEOUT_ACTION_REJECT, 'expired %s; the next maintenance run applies its timeout (then rejected)'],
            'expired, approves within the grace period' => [-$grace, ApprovalRule::TIMEOUT_ACTION_APPROVE, 'expired %s; the next maintenance run applies its timeout (then approved)'],
            'expired past the grace period, rejects' => [-$grace - 1, ApprovalRule::TIMEOUT_ACTION_APPROVE, 'expired %s; the next maintenance run applies its timeout (then rejected)'],
        ];
    }

    /**
     * @dataProvider expiryData
     */
    public function testThePendingRequestSaysWhenItExpiresAndWhatItsTimeoutThenDoes(?int $expiresIn, string $timeoutAction, string $expected): void
    {
        $expiresAt = $expiresIn === null ? null : $this->now + $expiresIn;
        $wait = $this->wait(timeoutAction: $timeoutAction, expiresAt: $expiresAt);

        $facts = Banner::approvalFacts($wait, null, $this->now);

        $this->assertSame(['text' => sprintf($expected, date('H:i:s', (int)$expiresAt))], $facts[count($facts) - 1]);
    }

    public function testADecidedRequestHasNoExpiryAndADeletedRuleIsSaidSo(): void
    {
        $wait = $this->wait(status: ApprovalRequest::STATUS_APPROVED, ruleName: null, required: null, expiresAt: $this->now + 60);

        $this->assertSame([['text' => Texts::FACT_RULE_DELETED]], Banner::approvalFacts($wait, null, $this->now));
    }

    /**
     * The job page lists the same facts under its note, since the request
     * was opened, without the request itself.
     */
    public function testTheFactsOfTheJobPageStartWithTheRule(): void
    {
        $wait = $this->wait(ruleName: 'gate', required: 1, approvals: 0);

        $this->assertSame([
            ['text' => 'Rule "gate"'],
            ['text' => '0 of 1 approval(s)'],
            ['text' => 'waiting since ' . date('H:i:s', $this->now - 300)],
            ['text' => Texts::FACT_NEVER_EXPIRES],
        ], Banner::approvalFacts($wait, $this->now - 300, $this->now));
    }

    /**
     * Times are absolute: a wait that began on another day names its date.
     */
    public function testATimeOfAnotherDayNamesItsDate(): void
    {
        $since = $this->now - 86400 * 2;
        $step = $this->step(WorkflowStep::TYPE_PAUSE, 'hold', null, waitingSince: $since);

        $banner = Banner::of($this->waiting($step, Display::STATE_PAUSED), $this->now);

        $this->assertNotNull($banner);
        $this->assertSame([['text' => 'waiting since ' . date('Y-m-d H:i:s', $since)]], $banner['facts']);
    }

    public function testAPausedRunWaitsForSomeoneToResumeIt(): void
    {
        $banner = Banner::of($this->waiting($this->step(WorkflowStep::TYPE_PAUSE, 'hold <on>', null), Display::STATE_PAUSED), $this->now);

        $this->assertSame([
            'kind' => Banner::KIND_RESUME,
            'tone' => Banner::TONE_WARNING,
            'title' => 'Paused at step "hold <on>" until someone resumes the run.',
            'facts' => [['text' => 'waiting since ' . date('H:i:s', $this->now - 600)]],
            'personal' => null,
            'warning' => null,
        ], $banner);
    }

    public function testAQueuedJobWaitsForARunner(): void
    {
        $step = $this->step(WorkflowStep::TYPE_JOB, 'build', null, jobId: 56);

        $banner = Banner::of($this->waiting($step, Display::STATE_QUEUED), $this->now);

        $this->assertSame([
            'kind' => Banner::KIND_RUNNER,
            'tone' => Banner::TONE_SECONDARY,
            'title' => 'Job #56 of step "build" is queued: it waits for a runner.',
            'facts' => [['text' => 'waiting since ' . date('H:i:s', $this->now - 600)]],
            'personal' => null,
            'warning' => null,
        ], $banner);
    }

    public function testARunThatWaitsForNothingHasNoBanner(): void
    {
        $running = $this->step(WorkflowStep::TYPE_JOB, 'build', null, jobId: 56, waitingSince: null);

        $this->assertNull(Banner::of($this->waiting($running, Display::STATE_RUNNING), $this->now), 'it runs');
        $this->assertNull(Banner::of(new RunState(1, WorkflowJob::STATUS_SUCCEEDED, Display::STATE_SUCCEEDED, null, null, []), $this->now), 'it finished');
        $this->assertNull(Banner::of(new RunState(1, WorkflowJob::STATUS_RUNNING, Display::STATE_RUNNING, null, null, [])), 'between two steps');
    }

    /**
     * A wait whose request is gone is cancelled by the next maintenance run:
     * the banner says so.
     */
    public function testAWaitWhoseRequestNoLongerExistsSaysSo(): void
    {
        $admin = $this->createUserWithRole('banner-admin', 'admin');
        $job = $this->jobOf(null, (int)$admin->id, Job::STATUS_PENDING_APPROVAL);
        $step = $this->step(WorkflowStep::TYPE_APPROVAL, 'approve', null, jobId: (int)$job->id);

        $banner = Banner::of($this->waiting($step, Display::STATE_AWAITING_APPROVAL), $this->now);

        $this->assertNotNull($banner);
        $this->assertSame('Waiting for approval at step "approve".', $banner['title']);
        $this->assertSame([
            ['text' => Texts::BANNER_REQUEST_MISSING],
            ['text' => 'waiting since ' . date('H:i:s', $this->now - 600)],
        ], $banner['facts']);
        $this->assertNull($banner['personal']);
        $this->assertNull($banner['warning']);
    }

    /**
     * A request the viewer may not see (team scoping) is left out: it is not
     * named, and not called missing either.
     */
    public function testARequestTheViewerMayNotSeeIsLeftOutNotCalledMissing(): void
    {
        $admin = $this->createUserWithRole('banner-admin', 'admin');
        $job = $this->jobOf((int)$this->openJobTemplate((int)$admin->id)->id, (int)$admin->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($job, $this->ruleOf((int)$admin->id, [$admin]));
        $step = $this->step(WorkflowStep::TYPE_JOB, 'deploy', null, jobId: (int)$job->id);

        $banner = Banner::of($this->waiting($step, Display::STATE_AWAITING_APPROVAL), $this->now);

        $this->assertNotNull($banner);
        $this->assertSame([['text' => 'waiting since ' . date('H:i:s', $this->now - 600)]], $banner['facts']);
    }

    /**
     * Through the run states of a real run: the launcher learns that another
     * approver must decide, and an approver who may decide gets Review &
     * decide.
     */
    public function testTheBannerOfARealRunForItsLauncherAndForAnApprover(): void
    {
        $admin = $this->createUserWithRole('banner-launcher', 'admin');
        $approver = $this->createUserWithRole('banner-approver', 'operator');
        $rule = $this->ruleOf((int)$admin->id, [$admin, $approver]);
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        $gate = $this->namedStep($workflow, 10, 'sign off', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$admin->id);
        $placeholder = $this->jobOf(null, (int)$admin->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($placeholder, $rule, ApprovalRequest::STATUS_PENDING, $this->now - 120);
        $this->executionOf($run, $gate, WorkflowJobStep::STATUS_RUNNING, $placeholder, null, $this->now - 130);

        $launcherBanner = Banner::of($this->runStates()->forRun($run, Viewer::of((int)$admin->id), true), $this->now);
        $approverBanner = Banner::of($this->runStates()->forRun($run, Viewer::of((int)$approver->id), true), $this->now);

        $this->assertNotNull($launcherBanner);
        $this->assertSame(['text' => Texts::PERSONAL_LAUNCHER], $launcherBanner['personal']);
        $this->assertSame('Waiting for approval at step "sign off".', $launcherBanner['title']);
        $this->assertNotNull($approverBanner);
        $this->assertSame([
            'text' => Texts::PERSONAL_MAY_DECIDE,
            'action' => ['text' => Texts::REVIEW_BUTTON, 'href' => '/approval/view?id=' . $request->id],
        ], $approverBanner['personal']);
        $this->assertSame([
            ['text' => 'Approval Request #' . $request->id, 'request' => true, 'href' => '/approval/view?id=' . $request->id],
            ['text' => sprintf(Texts::FACT_RULE, $rule->name)],
            ['text' => '0 of 1 approval(s)'],
            ['text' => '1 approver(s) can still decide'],
            ['text' => 'waiting since ' . date('H:i:s', $this->now - 120)],
            ['text' => Texts::FACT_NEVER_EXPIRES],
        ], $approverBanner['facts'], 'the launcher is no eligible approver');
    }

    /**
     * The banner of a visible request reads what the run state carries: it
     * costs as much as a pause's banner, however many facts it lists. A
     * request that is hidden or gone costs the same either way.
     */
    public function testTheBannerCostsTheSameWhateverItShows(): void
    {
        $admin = $this->createUserWithRole('banner-admin', 'admin');
        $hidden = $this->jobOf((int)$this->openJobTemplate((int)$admin->id)->id, (int)$admin->id, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($hidden, $this->ruleOf((int)$admin->id, [$admin]));
        $gone = $this->jobOf(null, (int)$admin->id, Job::STATUS_PENDING_APPROVAL);
        $paused = $this->waiting($this->step(WorkflowStep::TYPE_PAUSE, 'hold', null), Display::STATE_PAUSED);
        $few = $this->waiting($this->step(WorkflowStep::TYPE_APPROVAL, 'approve', $this->wait()), Display::STATE_AWAITING_APPROVAL);
        $many = $this->waiting(
            $this->step(WorkflowStep::TYPE_APPROVAL, 'approve', $this->wait(required: 3, openCount: 4, expiresAt: $this->now + 60, approvable: false)),
            Display::STATE_AWAITING_APPROVAL
        );
        $hiddenState = $this->waiting($this->step(WorkflowStep::TYPE_JOB, 'deploy', null, jobId: (int)$hidden->id), Display::STATE_AWAITING_APPROVAL);
        $goneState = $this->waiting($this->step(WorkflowStep::TYPE_APPROVAL, 'approve', null, jobId: (int)$gone->id), Display::STATE_AWAITING_APPROVAL);
        Banner::of($goneState, $this->now);
        $cost = fn (RunState $state): int => $this->queriesOf(fn () => Banner::of($state, $this->now));

        $this->assertSame($cost($paused), $cost($few));
        $this->assertSame($cost($few), $cost($many));
        $this->assertSame($cost($hiddenState), $cost($goneState));
    }

    // -- Helpers --------------------------------------------------------------

    private function wait(
        int $requestId = 12,
        string $status = ApprovalRequest::STATUS_PENDING,
        ?string $ruleName = 'gate',
        ?int $required = 1,
        int $approvals = 0,
        ?int $openCount = null,
        ?int $expiresAt = null,
        string $timeoutAction = ApprovalRule::TIMEOUT_ACTION_REJECT,
        bool $linkable = true,
        ?string $relation = Display::RELATION_MAY_DECIDE,
        ?bool $approvable = null
    ): ApprovalWait {
        return new ApprovalWait(
            requestId: $requestId,
            jobId: 34,
            status: $status,
            ruleId: $ruleName === null ? null : 5,
            ruleName: $ruleName,
            requiredApprovals: $required,
            approvalCount: $approvals,
            rejectionCount: 0,
            requestedAt: $this->now - 600,
            expiresAt: $expiresAt,
            resolvedAt: null,
            timeoutAction: $expiresAt === null ? null : $timeoutAction,
            linkable: $linkable,
            relation: $relation,
            eligibleCount: $openCount,
            openCount: $openCount,
            approvable: $approvable,
        );
    }

    private function step(string $type, string $name, ?ApprovalWait $approval, int $jobId = 34, ?int $waitingSince = -1): StepState
    {
        return new StepState(
            executionId: 7,
            workflowStepId: 3,
            stepName: $name,
            stepType: $type,
            status: WorkflowJobStep::STATUS_RUNNING,
            state: Display::STATE_RUNNING,
            waitingFor: null,
            jobId: $jobId,
            jobStatus: Job::STATUS_PENDING_APPROVAL,
            jobTemplateName: null,
            startedAt: $this->now - 700,
            finishedAt: null,
            waitingSince: $waitingSince === -1 ? $this->now - 600 : $waitingSince,
            errorMessage: null,
            approval: $approval,
            decisionNote: null,
        );
    }

    /**
     * A running run whose current execution is $step, in $state.
     */
    private function waiting(StepState $step, string $state): RunState
    {
        $current = new StepState(
            $step->executionId,
            $step->workflowStepId,
            $step->stepName,
            $step->stepType,
            $step->status,
            $state,
            Display::waitingFor($state),
            $step->jobId,
            $step->jobStatus,
            $step->jobTemplateName,
            $step->startedAt,
            $step->finishedAt,
            $step->waitingSince,
            $step->errorMessage,
            $step->approval,
            $step->decisionNote,
        );

        return new RunState(1, WorkflowJob::STATUS_RUNNING, $state, Display::waitingFor($state), $current, [$current]);
    }
}
