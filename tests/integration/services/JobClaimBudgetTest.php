<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Credential;
use app\models\Job;
use app\models\Webhook;
use app\services\JobClaimService;
use app\services\WebhookService;
use app\tests\integration\DbTestCase;
use yii\db\Query;

/**
 * The time budget of a claim request (JobClaimService::CLAIM_BUDGET_SECONDS):
 * once a job it claimed has failed before execution, a claim request claims
 * no further job when the budget has passed since the request began, and
 * answers that there is none; the runner gets the next job at its next poll.
 *
 * A failure's effects (the job.failure webhooks, the job.failed
 * notifications, the next step of a workflow) run within the request, each
 * up to its endpoint's timeout, and a runner waits 30 seconds for the answer
 * (RunnerHttpClient). A job claimed after the runner gave up reached no
 * runner and stayed running. Time passes on a clock of the test
 * (JobClaimService::now()): a failure's webhook moves it on, as a webhook
 * whose endpoint does not answer takes its timeout.
 */
class JobClaimBudgetTest extends DbTestCase
{
    use JobClaimFixtures;

    /** How long a runner waits for the answer to its claim, in seconds (RunnerHttpClient). */
    private const RUNNER_WAITS = 30.0;

    /** The kinds of failure before execution that failingJob() writes. */
    private const NOT_APPROVED = 'not approved';
    private const CREDENTIAL_GONE = 'credential gone';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaimFixtures();
    }

    protected function tearDown(): void
    {
        $this->restoreClaimServices();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function failureProvider(): array
    {
        return [
            'a job that was never approved (JobClaimRefusedException)' => [self::NOT_APPROVED],
            'a job whose credential is gone (CredentialResolutionException)' => [self::CREDENTIAL_GONE],
        ];
    }

    /**
     * Regression (2.12.0 review, OPS-2): a claim request failed a job before
     * execution, whose job.failure webhook waited for an endpoint that did
     * not answer, then claimed the next job and answered with it after its
     * runner had stopped waiting. That job stayed running with nobody
     * running it: the reclaim takes back only the jobs of a runner that went
     * offline, and this one kept polling.
     *
     * @dataProvider failureProvider
     */
    public function testRegressionAJobClaimedAfterASlowFailureWasHandedOutAfterItsRunnerGaveUp(string $failure): void
    {
        $claims = $this->claimsOnATestClock();
        $failed = $this->failingJob($failure);
        $runnable = $this->runnableJob();
        $webhooks = $this->slowFailureEffects($claims, self::RUNNER_WAITS + 1.0);

        $payload = $claims->claimNextPayload($this->group, $this->runner);

        $this->assertNull($payload, 'no job is handed out after the runner stopped waiting');
        $stored = $this->reloadJob($runnable);
        $this->assertSame(Job::STATUS_QUEUED, $stored->status, 'the runnable job is still queued');
        $this->assertNull($stored->runner_id, 'and no runner has it');
        $this->assertSame(Job::STATUS_FAILED, $this->reloadJob($failed)->status, 'the job that may not run failed before execution');
        $this->assertSame([Webhook::EVENT_JOB_FAILURE], $webhooks->events);

        $next = $claims->claimNextPayload($this->group, $this->runner);

        $this->assertSame((int)$runnable->id, $next['job_id'] ?? null, 'the next poll gets the runnable job');
    }

    /**
     * The case of the review: two jobs that may not run, whose failures take
     * 16 seconds each, then a runnable one. One claim request failed both
     * and handed out the third job after 32 seconds, when its runner had
     * stopped waiting. Now each poll fails one of them, and the third poll
     * hands out the runnable job.
     */
    public function testEachPollFailsOneSlowJobUntilTheRunnableOneIsHandedOut(): void
    {
        $claims = $this->claimsOnATestClock();
        $first = $this->failingJob(self::NOT_APPROVED);
        $second = $this->launchedJob($this->template);
        $runnable = $this->runnableJob();
        $webhooks = $this->slowFailureEffects($claims, 16.0);

        $this->assertNull($claims->claimNextPayload($this->group, $this->runner), 'the first poll gets no job');
        $this->assertSame([Job::STATUS_FAILED, Job::STATUS_QUEUED, Job::STATUS_QUEUED], $this->statusesOf($first, $second, $runnable));

        $this->assertNull($claims->claimNextPayload($this->group, $this->runner), 'the second poll gets no job');
        $this->assertSame([Job::STATUS_FAILED, Job::STATUS_FAILED, Job::STATUS_QUEUED], $this->statusesOf($first, $second, $runnable));

        $payload = $claims->claimNextPayload($this->group, $this->runner);
        $this->assertSame((int)$runnable->id, $payload['job_id'] ?? null, 'the third poll gets the runnable job');
        $this->assertSame([Job::STATUS_FAILED, Job::STATUS_FAILED, Job::STATUS_RUNNING], $this->statusesOf($first, $second, $runnable));
        $this->assertSame([Webhook::EVENT_JOB_FAILURE, Webhook::EVENT_JOB_FAILURE], $webhooks->events);
    }

    /**
     * @return array<string, array{0: float, 1: bool}>
     */
    public static function failureDurationProvider(): array
    {
        return [
            'a moment less than the budget' => [JobClaimService::CLAIM_BUDGET_SECONDS - 0.001, true],
            'exactly the budget' => [(float)JobClaimService::CLAIM_BUDGET_SECONDS, false],
            'longer than the runner waits' => [self::RUNNER_WAITS + 1.0, false],
        ];
    }

    /**
     * After a failure the claim request goes on to the next job only while
     * less than the budget has passed since the request began.
     *
     * @dataProvider failureDurationProvider
     */
    public function testAfterAFailureTheClaimGoesOnOnlyWithinTheBudget(float $seconds, bool $goesOn): void
    {
        $claims = $this->claimsOnATestClock();
        $failed = $this->failingJob(self::NOT_APPROVED);
        $runnable = $this->runnableJob();
        $this->slowFailureEffects($claims, $seconds);

        $payload = $claims->claimNextPayload($this->group, $this->runner);

        $this->assertSame($goesOn ? (int)$runnable->id : null, $payload['job_id'] ?? null);
        $this->assertSame([Job::STATUS_FAILED, $goesOn ? Job::STATUS_RUNNING : Job::STATUS_QUEUED], $this->statusesOf($failed, $runnable));
    }

    /**
     * The budget counts from the start of the request, not per failure: two
     * failures of 6 seconds each use it up, and the runnable job after them
     * waits for the next poll.
     */
    public function testTheBudgetCountsFromTheStartOfTheRequest(): void
    {
        $claims = $this->claimsOnATestClock();
        $first = $this->failingJob(self::NOT_APPROVED);
        $second = $this->launchedJob($this->template);
        $runnable = $this->runnableJob();
        $this->slowFailureEffects($claims, 6.0);

        $this->assertNull($claims->claimNextPayload($this->group, $this->runner));

        $this->assertSame([Job::STATUS_FAILED, Job::STATUS_FAILED, Job::STATUS_QUEUED], $this->statusesOf($first, $second, $runnable));
    }

    /**
     * Without a failure the budget does not apply: however long the claim
     * of a runnable job takes, the job is handed out.
     */
    public function testARunnableJobIsHandedOutWhateverTheTime(): void
    {
        $claims = $this->claimsOnATestClock();
        $runnable = $this->runnableJob();
        $claims->seconds += self::RUNNER_WAITS + 1.0;

        $payload = $claims->claimNextPayload($this->group, $this->runner);

        $this->assertSame((int)$runnable->id, $payload['job_id'] ?? null);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * The claim service on a clock of the test, which stands still until a
     * test or a failure's effects move it on.
     *
     * @return JobClaimService&object{seconds: float}
     */
    private function claimsOnATestClock(): JobClaimService
    {
        return new class extends JobClaimService {
            public float $seconds = 1000.0;

            protected function now(): float
            {
                return $this->seconds;
            }
        };
    }

    /**
     * Each failure's job.failure webhook takes $seconds on the clock of
     * $claims, as a webhook whose endpoint does not answer takes its timeout;
     * notifications are recorded, not sent. The webhook events, in order.
     *
     * @param JobClaimService&object{seconds: float} $claims
     * @return WebhookService&object{events: list<string>}
     */
    private function slowFailureEffects(JobClaimService $claims, float $seconds): WebhookService
    {
        $this->recordClaimNotifications();
        $webhooks = new class ($claims, $seconds) extends WebhookService {
            /** @var list<string> */
            public array $events = [];

            public function __construct(private readonly object $claims, private readonly float $seconds)
            {
                parent::__construct();
            }

            public function dispatch(string $event, Job $job): void
            {
                $this->events[] = $event;
                $this->claims->seconds += $this->seconds;
            }
        };
        $this->swapClaimService('webhookService', $webhooks);

        return $webhooks;
    }

    /**
     * A queued job of the fixtures' template that fails before execution:
     * never approved, its template having got a rule after the launch, or
     * launched with a credential that is gone.
     */
    private function failingJob(string $failure): Job
    {
        if ($failure === self::CREDENTIAL_GONE) {
            $gone = (int)(new Query())->from(Credential::tableName())->max('id') + 1000;

            return $this->launchedJob($this->template, Job::STATUS_QUEUED, null, ['credential_id' => $gone]);
        }
        $job = $this->launchedJob($this->template);
        $this->giveTemplateRule($this->template, $this->approvalRule());

        return $job;
    }

    /**
     * A queued job of another template of the runner group, which runs.
     */
    private function runnableJob(): Job
    {
        return $this->launchedJob($this->templateOn($this->createInventory((int)$this->launcher->id)));
    }

    /**
     * @return list<string> the statuses of $jobs as stored, in order
     */
    private function statusesOf(Job ...$jobs): array
    {
        return array_map(fn (Job $job): string => $this->reloadJob($job)->status, array_values($jobs));
    }
}
