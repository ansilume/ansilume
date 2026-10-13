<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalRequest;

/**
 * One run of approval timeouts (ApprovalService::processTimeouts()): the
 * expired pending requests, the longest expired first (expires_at, then
 * id), each settled on its own (ApprovalSettlement::timeOut()) with its
 * follow-ups after its commit (ApprovalEffects).
 *
 * A run is bounded twice: the limit bounds how many requests it reads and
 * settles (memory and work), the deadline bounds how long it keeps
 * starting them (time: slow notification endpoints or workflow advances
 * after each timeout). The deadline is checked before each request; a
 * request already started is finished. What is left waits for the next
 * run, the longest expired first again.
 *
 * Failures are logged in ApprovalService's category, without values
 * (ApprovalService::logFailure()): TIMEOUT_FAILED when the settlement
 * rolled back (the request stays pending and the next run tries it again),
 * TIMEOUT_EFFECTS_FAILED when only a follow-up after the commit failed (the
 * request stays settled; nothing tries the follow-up again).
 */
final class ApprovalTimeouts
{
    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock the time the deadline is compared with, in
     *     seconds as microtime(true) gives it (the default); tests pass their own
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param int $limit the most requests to read and settle; 0 for all
     * @param float|null $deadline no request is started once the clock reaches it
     *     (microtime(true) based); null for no time bound
     * @return int the number of requests this run resolved (timed out, or
     *     closed because their job no longer waited)
     */
    public function process(int $limit, ?float $deadline): int
    {
        $now = time();
        $resolved = 0;
        foreach ($this->expiredRequestIds($now, $limit) as $requestId) {
            if ($deadline !== null && ($this->clock)() >= $deadline) {
                break;
            }
            if ($this->timeOut($requestId, $now)) {
                $resolved++;
            }
        }

        return $resolved;
    }

    /**
     * @return list<int>
     */
    private function expiredRequestIds(int $now, int $limit): array
    {
        $query = ApprovalRequest::find()
            ->select('id')
            ->where(['status' => ApprovalRequest::STATUS_PENDING])
            ->andWhere(['not', ['expires_at' => null]])
            ->andWhere(['<=', 'expires_at', $now])
            ->orderBy(['expires_at' => SORT_ASC, 'id' => SORT_ASC]);
        if ($limit > 0) {
            $query->limit($limit);
        }

        return array_values(array_map('intval', $query->column()));
    }

    /**
     * Settle one request, then run its follow-ups.
     *
     * @return bool whether the request was resolved
     */
    private function timeOut(int $requestId, int $now): bool
    {
        try {
            /** @var ApprovalSettlement $settlement */
            $settlement = \Yii::$app->get('approvalSettlement');
            $outcome = $settlement->timeOut($requestId, $now);
        } catch (\Throwable $e) {
            ApprovalService::logFailure(ApprovalService::TIMEOUT_FAILED, [$requestId], $e);
            return false;
        }
        if ($outcome === null) {
            return false;
        }
        try {
            (new ApprovalEffects())->settled($outcome, null);
        } catch (\Throwable $e) {
            ApprovalService::logFailure(ApprovalService::TIMEOUT_EFFECTS_FAILED, [$requestId], $e);
        }

        return true;
    }
}
