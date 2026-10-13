<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\services\ApprovalOutcome;
use app\services\ApprovalSettlement;
use app\services\ApprovalTimeouts;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The time bound of a timeout run (ApprovalService::processTimeouts($limit,
 * $deadline), contract K2 with the maintenance run): no request is started
 * once the deadline has passed, a request already started is finished,
 * and what is left waits for the next run, the longest expired first.
 */
class ApprovalTimeoutBudgetTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;

    /** The fake clock of the tests that drive one; seconds. */
    private float $clock = 1000.0;

    /** @var array{0: string, 1: mixed}|null the approvalSettlement component a test swapped */
    private ?array $swapped = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        if ($this->swapped !== null) {
            \Yii::$app->set($this->swapped[0], $this->swapped[1]);
            $this->swapped = null;
        }
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Regression: one maintenance tick applied every expired request of its
     * batch, however long their follow-ups took (slow notification
     * endpoints, workflow advances), and held up the schedule runner. A
     * deadline that has passed starts nothing; the next run without one
     * applies them all.
     */
    public function testRegressionOneMaintenanceTickAppliedEveryExpiredRequestHoweverLongItTook(): void
    {
        $requests = $this->expiredRequests(3);

        $this->assertSame(0, $this->approvals()->processTimeouts(100, microtime(true) - 1.0));

        $this->assertSame(array_fill(0, 3, ApprovalRequest::STATUS_PENDING), $this->statusesOf($requests), 'none was started');
        $this->assertSame(3, $this->approvals()->processTimeouts(100));
        $this->assertSame(array_fill(0, 3, ApprovalRequest::STATUS_TIMED_OUT), $this->statusesOf($requests));
    }

    public function testADeadlineThatIsNotReachedBoundsNothing(): void
    {
        $requests = $this->expiredRequests(2);

        $this->assertSame(2, $this->approvals()->processTimeouts(100, microtime(true) + 3600.0));

        $this->assertSame(array_fill(0, 2, ApprovalRequest::STATUS_TIMED_OUT), $this->statusesOf($requests));
    }

    /**
     * The deadline passes while the first request is settled: that request
     * is finished (settled, and its follow-ups run), the others are not
     * started. The next run takes the rest, the longest expired first.
     */
    public function testARequestThatWasStartedIsFinishedAndTheRestWaitForTheNextRun(): void
    {
        $requests = $this->expiredRequests(3);
        $deadline = $this->clock + 10.0;
        $this->advanceClockWhenSettling((int)$requests[0]->id, $deadline + 1.0);

        $resolved = (new ApprovalTimeouts(fn (): float => $this->clock))->process(0, $deadline);

        $this->assertSame(1, $resolved);
        $this->assertSame(
            [ApprovalRequest::STATUS_TIMED_OUT, ApprovalRequest::STATUS_PENDING, ApprovalRequest::STATUS_PENDING],
            $this->statusesOf($requests)
        );
        $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', (int)$requests[0]->id);
        $this->assertSame([], $this->auditsOf(AuditLog::ACTION_APPROVAL_TIMED_OUT, 'approval_request', (int)$requests[1]->id));

        $this->assertSame(1, (new ApprovalTimeouts(fn (): float => $this->clock))->process(1, $this->clock + 10.0));
        $this->assertSame(
            [ApprovalRequest::STATUS_TIMED_OUT, ApprovalRequest::STATUS_TIMED_OUT, ApprovalRequest::STATUS_PENDING],
            $this->statusesOf($requests),
            'the longest expired of the rest first'
        );
    }

    /**
     * The deadline is checked before each request, not only before the
     * first: here it passes while the second one is settled.
     */
    public function testTheDeadlineIsCheckedBeforeEachRequest(): void
    {
        $requests = $this->expiredRequests(4);
        $deadline = $this->clock + 10.0;
        $this->advanceClockWhenSettling((int)$requests[1]->id, $deadline);

        $this->assertSame(2, (new ApprovalTimeouts(fn (): float => $this->clock))->process(0, $deadline));

        $this->assertSame(
            [ApprovalRequest::STATUS_TIMED_OUT, ApprovalRequest::STATUS_TIMED_OUT, ApprovalRequest::STATUS_PENDING, ApprovalRequest::STATUS_PENDING],
            $this->statusesOf($requests),
            'a clock at the deadline starts nothing more'
        );
    }

    /**
     * Without a deadline the clock is never asked.
     */
    public function testWithoutADeadlineTheClockIsNotAsked(): void
    {
        $requests = $this->expiredRequests(2);
        $asked = 0;

        $resolved = (new ApprovalTimeouts(function () use (&$asked): float {
            $asked++;
            return 0.0;
        }))->process(0, null);

        $this->assertSame(2, $resolved);
        $this->assertSame(0, $asked);
        $this->assertSame(array_fill(0, 2, ApprovalRequest::STATUS_TIMED_OUT), $this->statusesOf($requests));
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * $count pending requests that expired, the first one longest ago.
     *
     * @return list<ApprovalRequest>
     */
    private function expiredRequests(int $count): array
    {
        $requests = [];
        for ($i = 0; $i < $count; $i++) {
            ['request' => $request] = $this->waitingRequest([$this->approverUser('budget-' . $i)]);
            $this->expireRequest($request, 600 - $i * 60);
            $requests[] = $request;
        }

        return $requests;
    }

    /**
     * Settle as usual, but move the fake clock to $to while request
     * #$requestId is settled.
     */
    private function advanceClockWhenSettling(int $requestId, float $to): void
    {
        $test = $this;
        $this->swapped = ['approvalSettlement', \Yii::$app->get('approvalSettlement')];
        \Yii::$app->set('approvalSettlement', new class ($requestId, $to, $test) extends ApprovalSettlement {
            public function __construct(
                private readonly int $requestId,
                private readonly float $to,
                private readonly ApprovalTimeoutBudgetTest $test
            ) {
                parent::__construct();
            }

            public function timeOut(int $requestId, int $now): ?ApprovalOutcome
            {
                $outcome = parent::timeOut($requestId, $now);
                if ($requestId === $this->requestId) {
                    $this->test->setClock($this->to);
                }

                return $outcome;
            }
        });
    }

    public function setClock(float $seconds): void
    {
        $this->clock = $seconds;
    }

    /**
     * @param list<ApprovalRequest> $requests
     * @return list<string>
     */
    private function statusesOf(array $requests): array
    {
        return array_map(fn (ApprovalRequest $r): string => (string)$this->reloadRequest($r)->status, $requests);
    }
}
