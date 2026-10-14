<?php

declare(strict_types=1);

namespace app\services\runstate;

use app\helpers\TimeHelper;
use app\models\Job;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;

/**
 * The display states of workflow runs and their step executions, and how
 * they read. A state is derived, never stored (WorkflowRunStates derives
 * it): the stored statuses stay as they are, and a run that waits at an
 * approval, a pause or for a runner is stored as running.
 *
 * Waiting states share the label and colour of the job status they come
 * from: Awaiting Approval and Queued read as on the job page. Canceled reads
 * as a canceled run. No state uses the css "dark", which the dark theme
 * hides.
 */
final class Display
{
    public const STATE_PENDING = 'pending';
    public const STATE_QUEUED = 'queued';
    public const STATE_RUNNING = 'running';
    public const STATE_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATE_PAUSED = 'paused';
    public const STATE_SUCCEEDED = 'succeeded';
    public const STATE_FAILED = 'failed';
    public const STATE_CANCELED = 'canceled';
    public const STATE_SKIPPED = 'skipped';

    /** The step's job waits for its approval request (awaiting_approval). */
    public const WAITING_FOR_APPROVAL = 'approval';

    /** The run is paused until someone resumes it (paused). */
    public const WAITING_FOR_RESUME = 'resume';

    /** The step's job waits for a runner (queued). */
    public const WAITING_FOR_RUNNER = 'runner';

    /** The viewer may vote on the request now. */
    public const RELATION_MAY_DECIDE = 'may_decide';

    /** The viewer launched the job or run, and so never decides. */
    public const RELATION_LAUNCHER = 'launcher';

    /** The viewer has voted, and the request still waits. */
    public const RELATION_VOTED = 'voted';

    /** The viewer is on the rule's approver list, but may not decide. */
    public const RELATION_NOT_ELIGIBLE = 'not_eligible';

    /** The viewer is not on the rule's approver list. */
    public const RELATION_NOT_APPROVER = 'not_approver';

    /** The duration of a step that runs; sprintf(): TimeHelper::duration(). */
    public const DURATION_RUNNING = 'running %s';

    /** The duration of a step that waits; sprintf(): TimeHelper::duration(). */
    public const DURATION_WAITING = 'waiting %s';

    /** The label of the paused state, which no stored status has. */
    public const PAUSED_LABEL = 'Paused';

    /** The css of the paused state. */
    public const PAUSED_CSS = 'warning';

    /**
     * How $state reads: Pending, Queued, Running, Awaiting Approval, Paused,
     * Succeeded, Failed, Canceled or Skipped; an unknown state as it is.
     */
    public static function label(string $state): string
    {
        return match ($state) {
            self::STATE_QUEUED => Job::statusLabel(Job::STATUS_QUEUED),
            self::STATE_AWAITING_APPROVAL => Job::statusLabel(Job::STATUS_PENDING_APPROVAL),
            self::STATE_PAUSED => self::PAUSED_LABEL,
            self::STATE_CANCELED => WorkflowJob::statusLabel(WorkflowJob::STATUS_CANCELED),
            default => WorkflowJobStep::statusLabel($state),
        };
    }

    /**
     * The Bootstrap colour of $state's badge (text-bg-<css>); secondary for
     * an unknown state.
     */
    public static function css(string $state): string
    {
        return match ($state) {
            self::STATE_QUEUED => Job::statusCssClass(Job::STATUS_QUEUED),
            self::STATE_AWAITING_APPROVAL => Job::statusCssClass(Job::STATUS_PENDING_APPROVAL),
            self::STATE_PAUSED => self::PAUSED_CSS,
            self::STATE_CANCELED => WorkflowJob::statusCssClass(WorkflowJob::STATUS_CANCELED),
            default => WorkflowJobStep::statusCssClass($state),
        };
    }

    /**
     * What a run or step in $state waits for (WAITING_FOR_*); null when it
     * does not wait.
     */
    public static function waitingFor(string $state): ?string
    {
        return match ($state) {
            self::STATE_AWAITING_APPROVAL => self::WAITING_FOR_APPROVAL,
            self::STATE_PAUSED => self::WAITING_FOR_RESUME,
            self::STATE_QUEUED => self::WAITING_FOR_RUNNER,
            default => null,
        };
    }

    /**
     * Every value of waiting_for, as the filters and the API take it.
     *
     * @return list<string>
     */
    public static function waitingForValues(): array
    {
        return [self::WAITING_FOR_APPROVAL, self::WAITING_FOR_RESUME, self::WAITING_FOR_RUNNER];
    }

    /**
     * How long a step ran or has run so far: TimeHelper::duration() for a
     * finished step, prefixed with "waiting " (DURATION_WAITING) while it
     * waits and "running " (DURATION_RUNNING) while it runs otherwise. Null
     * for a step that never started.
     */
    public static function durationLabel(?int $seconds, string $state, bool $finished): ?string
    {
        if ($seconds === null) {
            return null;
        }
        $duration = TimeHelper::duration($seconds);
        if ($finished) {
            return $duration;
        }

        return sprintf(self::waitingFor($state) === null ? self::DURATION_RUNNING : self::DURATION_WAITING, $duration);
    }

    /**
     * The flash of a launch of workflow $workflowName, whose run is in
     * $state right after the launch: it names the step that waits for an
     * approval (with its request, when the viewer may see it) or is paused.
     */
    public static function launchFlash(string $workflowName, RunState $state): string
    {
        $current = $state->current;
        if ($current === null) {
            return sprintf(Texts::LAUNCHED, $workflowName);
        }
        if ($state->state === self::STATE_PAUSED) {
            return sprintf(Texts::LAUNCHED_PAUSED, $workflowName, $current->stepName);
        }
        $approval = $current->approval;
        if ($approval !== null && $state->state === self::STATE_AWAITING_APPROVAL) {
            return sprintf(Texts::LAUNCHED_AWAITING_APPROVAL, $workflowName, $current->stepName, $approval->requestId);
        }

        return sprintf(Texts::LAUNCHED, $workflowName);
    }
}
