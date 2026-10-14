<?php

declare(strict_types=1);

namespace app\services\runstate;

use app\helpers\TimeHelper;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\WorkflowStep;
use app\services\ApprovalSettlement;
use yii\helpers\Url;

/**
 * The banner of the run page that says what a run waits for
 * (#wj-wait-banner), as one array that the page renders and the poll
 * (/workflow-job/status) answers alike: null while the run waits for
 * nothing.
 *
 * - kind approval (tone info): the approval step, or the job of a gated job
 *   step, waits for its approval request. The facts name the request (with
 *   its page's address only for a viewer who may open it, approval.view),
 *   the rule, the approvals that count, the approvers who can still decide,
 *   since when it waits and when it expires. The personal line says how the
 *   viewer stands to the request; only a viewer who may decide now gets the
 *   Review & decide action. The warning says that too few approvers can
 *   still decide, unless its timeout approves it anyway.
 * - kind resume (tone warning): the run is paused at a pause step.
 * - kind runner (tone secondary): the step's job waits for a runner.
 *
 * Every text is plain text (Texts), for Html::encode() and textContent; a
 * fact's href and an action's href are local addresses (Url::to()). Times
 * are absolute (TimeHelper::stamp()), so the banner changes only when the
 * wait does: the page redraws its live region only then.
 *
 * A request the viewer may not see (team scoping) is left out of the
 * facts; a request that no longer exists is named as missing
 * (Texts::BANNER_REQUEST_MISSING), which needs one query.
 */
final class Banner
{
    public const KIND_APPROVAL = Display::WAITING_FOR_APPROVAL;
    public const KIND_RESUME = Display::WAITING_FOR_RESUME;
    public const KIND_RUNNER = Display::WAITING_FOR_RUNNER;

    /** The alert colours of the banner (alert-<tone>). */
    public const TONE_INFO = 'info';
    public const TONE_WARNING = 'warning';
    public const TONE_SECONDARY = 'secondary';

    /** The job page's link to the request page of the job's approval request. */
    public const REVIEW_LINK = 'Review';

    /**
     * The banner of the run in $state; null when it waits for nothing (it
     * runs, or it has finished). $now decides whether a request has expired
     * and how times read; the current time by default.
     *
     * @return array{
     *     kind: string,
     *     tone: string,
     *     title: string,
     *     facts: list<array{text: string, href?: string, request?: true}>,
     *     personal: array{text: string, action?: array{text: string, href: string}}|null,
     *     warning: string|null
     * }|null
     */
    public static function of(RunState $state, ?int $now = null): ?array
    {
        $current = $state->current;
        if ($current === null) {
            return null;
        }
        $now ??= time();

        return match ($state->waitingFor) {
            Display::WAITING_FOR_APPROVAL => self::approvalBanner($current, $now),
            Display::WAITING_FOR_RESUME => self::banner(
                self::KIND_RESUME,
                self::TONE_WARNING,
                sprintf(Texts::BANNER_PAUSED, $current->stepName),
                self::sinceFacts($current->waitingSince, $now)
            ),
            Display::WAITING_FOR_RUNNER => self::banner(
                self::KIND_RUNNER,
                self::TONE_SECONDARY,
                sprintf(Texts::BANNER_QUEUED, (int)$current->jobId, $current->stepName),
                self::sinceFacts($current->waitingSince, $now)
            ),
            default => null,
        };
    }

    /**
     * The facts of an approval request after the request itself: its rule,
     * the approvals that count of those it needs, the approvers who can
     * still decide (when known), since when the job waits ($since, when
     * known) and, while the request is pending, when it expires and what
     * its timeout then does. The job page shows them under its approval
     * note.
     *
     * @return list<array{text: string}>
     */
    public static function approvalFacts(ApprovalWait $wait, ?int $since, int $now): array
    {
        $facts = [self::fact($wait->ruleName === null ? Texts::FACT_RULE_DELETED : sprintf(Texts::FACT_RULE, $wait->ruleName))];
        if ($wait->requiredApprovals !== null) {
            $facts[] = self::fact(sprintf(Texts::FACT_APPROVALS, $wait->approvalCount, $wait->requiredApprovals));
        }
        if ($wait->openCount !== null) {
            $facts[] = self::fact(sprintf(Texts::FACT_OPEN_APPROVERS, $wait->openCount));
        }
        $facts = array_merge($facts, self::sinceFacts($since, $now));
        if ($wait->status === ApprovalRequest::STATUS_PENDING) {
            $facts[] = self::fact(self::expiry($wait, $now));
        }

        return $facts;
    }

    /**
     * The address of the page of approval request #$requestId.
     */
    public static function requestUrl(int $requestId): string
    {
        return Url::to(['/approval/view', 'id' => $requestId]);
    }

    /**
     * @return array{
     *     kind: string,
     *     tone: string,
     *     title: string,
     *     facts: list<array{text: string, href?: string, request?: true}>,
     *     personal: array{text: string, action?: array{text: string, href: string}}|null,
     *     warning: string|null
     * }
     */
    private static function approvalBanner(StepState $current, int $now): array
    {
        $title = $current->stepType === WorkflowStep::TYPE_APPROVAL
            ? sprintf(Texts::BANNER_APPROVAL_STEP, $current->stepName)
            : sprintf(Texts::BANNER_GATED_JOB, (int)$current->jobId, $current->stepName);
        $wait = $current->approval;
        if ($wait === null) {
            $missing = self::requestExists((int)$current->jobId) ? [] : [self::fact(Texts::BANNER_REQUEST_MISSING)];

            return self::banner(
                self::KIND_APPROVAL,
                self::TONE_INFO,
                $title,
                array_merge($missing, self::sinceFacts($current->waitingSince, $now))
            );
        }

        return self::banner(
            self::KIND_APPROVAL,
            self::TONE_INFO,
            $title,
            array_merge([self::requestFact($wait)], self::approvalFacts($wait, $current->waitingSince, $now)),
            self::personal($wait),
            $wait->approvable === false && !self::timeoutApproves($wait, $now) ? Texts::WARNING_UNDECIDABLE : null
        );
    }

    /**
     * The request as the first fact: a link to its page for a viewer who
     * may open it.
     *
     * @return array{text: string, href?: string, request: true}
     */
    private static function requestFact(ApprovalWait $wait): array
    {
        $fact = ['text' => sprintf(Texts::FACT_REQUEST, $wait->requestId), 'request' => true];
        if ($wait->linkable) {
            $fact['href'] = self::requestUrl($wait->requestId);
        }

        return $fact;
    }

    /**
     * The viewer's line (Texts::PERSONAL_*), with the Review & decide action
     * only for a viewer who may decide now and open the request page.
     *
     * @return array{text: string, action?: array{text: string, href: string}}|null
     */
    private static function personal(ApprovalWait $wait): ?array
    {
        $text = match ($wait->relation) {
            Display::RELATION_MAY_DECIDE => Texts::PERSONAL_MAY_DECIDE,
            Display::RELATION_LAUNCHER => Texts::PERSONAL_LAUNCHER,
            Display::RELATION_VOTED => Texts::PERSONAL_VOTED,
            Display::RELATION_NOT_ELIGIBLE => Texts::PERSONAL_NOT_ELIGIBLE,
            Display::RELATION_NOT_APPROVER => Texts::PERSONAL_NOT_APPROVER,
            default => null,
        };
        if ($text === null) {
            return null;
        }
        $personal = ['text' => $text];
        if ($wait->relation === Display::RELATION_MAY_DECIDE && $wait->linkable) {
            $personal['action'] = ['text' => Texts::REVIEW_BUTTON, 'href' => self::requestUrl($wait->requestId)];
        }

        return $personal;
    }

    /**
     * When a pending request expires, or expired, and what its timeout does
     * then (Texts::FACT_EXPIRES, FACT_EXPIRED, FACT_NEVER_EXPIRES).
     */
    private static function expiry(ApprovalWait $wait, int $now): string
    {
        $expiresAt = $wait->expiresAt;
        if ($expiresAt === null) {
            return Texts::FACT_NEVER_EXPIRES;
        }
        $then = self::timeoutApproves($wait, $now) ? Texts::THEN_APPROVED : Texts::THEN_REJECTED;

        return sprintf(
            $expiresAt > $now ? Texts::FACT_EXPIRES : Texts::FACT_EXPIRED,
            TimeHelper::stamp($expiresAt, $now),
            $then
        );
    }

    /**
     * Whether the request's timeout approves it, as the maintenance run
     * applies it at $now: its rule approves on timeout, and the request has
     * not expired more than ApprovalSettlement::APPROVE_TIMEOUT_GRACE_SECONDS
     * ago (a later timeout rejects).
     */
    private static function timeoutApproves(ApprovalWait $wait, int $now): bool
    {
        return $wait->timeoutAction === ApprovalRule::TIMEOUT_ACTION_APPROVE
            && $wait->expiresAt !== null
            && $now - $wait->expiresAt <= ApprovalSettlement::APPROVE_TIMEOUT_GRACE_SECONDS;
    }

    /**
     * @return list<array{text: string}>
     */
    private static function sinceFacts(?int $since, int $now): array
    {
        return $since === null ? [] : [self::fact(sprintf(Texts::FACT_SINCE, TimeHelper::stamp($since, $now)))];
    }

    /**
     * @return array{text: string}
     */
    private static function fact(string $text): array
    {
        return ['text' => $text];
    }

    /**
     * Whether job #$jobId has an approval request, also one the viewer may
     * not see: such a request is left out of the facts, never named missing.
     */
    private static function requestExists(int $jobId): bool
    {
        return ApprovalRequest::find()->where(['job_id' => $jobId])->exists();
    }

    /**
     * @param list<array{text: string, href?: string, request?: true}> $facts
     * @param array{text: string, action?: array{text: string, href: string}}|null $personal
     * @return array{
     *     kind: string,
     *     tone: string,
     *     title: string,
     *     facts: list<array{text: string, href?: string, request?: true}>,
     *     personal: array{text: string, action?: array{text: string, href: string}}|null,
     *     warning: string|null
     * }
     */
    private static function banner(
        string $kind,
        string $tone,
        string $title,
        array $facts,
        ?array $personal = null,
        ?string $warning = null
    ): array {
        return [
            'kind' => $kind,
            'tone' => $tone,
            'title' => $title,
            'facts' => $facts,
            'personal' => $personal,
            'warning' => $warning,
        ];
    }
}
