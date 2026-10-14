<?php

declare(strict_types=1);

namespace app\services\runstate;

use app\helpers\TimeHelper;
use app\models\ApprovalRequest;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;

/**
 * What the run page shows of a workflow run, as one array: the page renders
 * it (views/workflow-job/view.php) and the poll answers it
 * (GET /workflow-job/status), so both read the same. Built from the run's
 * state with every execution (WorkflowRunStates::forRun()); reading it runs
 * no query but Banner's one for a request that is gone.
 *
 * The run and each step keep their stored status in status, status_label
 * and status_css; the display state (Display) is in state, state_label and
 * state_css, which the badges show, and waiting_for says what they wait
 * for. banner is Banner::of(); actions says whether the Resume and Cancel
 * forms are shown now: Resume only while the run is paused, both only to a
 * user who may use them and only until the run has finished. Each step
 * names the step as it was when it ran, links its job (not the placeholder
 * of an approval step, show_job_link) and its approval request (url only
 * for a viewer who may open it), and says how the request was decided.
 * Time labels are TimeHelper::stamp(), durations Display::durationLabel().
 *
 * is_current marks the newest execution of the run's current step, as the
 * page always has (current_execution_id).
 */
final class PollPayload
{
    /** The label of a step's approval request; sprintf(): the request ID. */
    public const REQUEST_LABEL = 'Request #%d';

    /** The title of the steps table's "#" column: the order in which the run reached its steps. */
    public const ORDER_TITLE = 'Order in this run';

    /** A cell without a value, and the type of a step whose type is unknown. */
    public const NO_VALUE = '—';

    /** Between a step's job link and its request link in the Job / Request cell. */
    public const LINK_SEPARATOR = ' · ';

    /** The run list's filter link that shows every run. */
    public const FILTER_ALL = 'All';

    /** The run list without any run. */
    public const NO_RUNS = 'No workflow jobs yet.';

    /** The run list when no run matches its filter. */
    public const NO_MATCHING_RUNS = 'No workflow jobs match this filter.';

    /**
     * @param bool $mayResume whether the viewer may resume the run: operator access to the
     *     workflow and workflow.launch
     * @param bool $mayCancel whether the viewer may cancel the run: operator access to the
     *     workflow and workflow.cancel
     * @param int|null $now the time durations, labels and expiry are computed for; the
     *     current time by default
     */
    public function __construct(
        private readonly bool $mayResume,
        private readonly bool $mayCancel,
        private readonly ?int $now = null,
    ) {
    }

    /**
     * The page data of $run, whose state with every execution is $state.
     *
     * @return array<string, mixed>
     */
    public function of(WorkflowJob $run, RunState $state): array
    {
        $now = $this->now ?? time();
        $finished = $run->isFinished();
        $currentExecutionId = self::currentExecutionId($run, $state);
        $startedAt = self::intOrNull($run->started_at);
        $finishedAt = self::intOrNull($run->finished_at);

        return [
            'id' => (int)$run->id,
            'status' => $state->status,
            'status_label' => WorkflowJob::statusLabel($state->status),
            'status_css' => WorkflowJob::statusCssClass($state->status),
            'state' => $state->state,
            'state_label' => Display::label($state->state),
            'state_css' => Display::css($state->state),
            'waiting_for' => $state->waitingFor,
            'is_finished' => $finished,
            'started_at' => $startedAt,
            'started_label' => self::stamp($startedAt, $now),
            'finished_at' => $finishedAt,
            'finished_label' => self::stamp($finishedAt, $now),
            'current_step_id' => self::intOrNull($run->current_step_id),
            'current_execution_id' => $currentExecutionId,
            'banner' => Banner::of($state, $now),
            'actions' => [
                'resume' => $this->mayResume && !$finished && $state->state === Display::STATE_PAUSED,
                'cancel' => $this->mayCancel && !$finished,
            ],
            'steps' => self::steps($state->steps, $currentExecutionId, $now),
        ];
    }

    /**
     * @param list<StepState> $steps oldest first
     * @return list<array<string, mixed>>
     */
    private static function steps(array $steps, ?int $currentExecutionId, int $now): array
    {
        $rows = [];
        foreach ($steps as $index => $step) {
            $rows[] = self::step($step, $index + 1) + self::times($step, $now) + [
                'is_current' => $step->executionId === $currentExecutionId,
                'error_message' => $step->errorMessage,
            ];
        }

        return $rows;
    }

    /**
     * What a step was and in which state it is: keyed by the execution, as a
     * step can run more than once in a run.
     *
     * @return array<string, mixed>
     */
    private static function step(StepState $step, int $index): array
    {
        $type = $step->stepType;

        return [
            'id' => $step->executionId,
            'workflow_step_id' => $step->workflowStepId,
            'step_name' => $step->stepName,
            'step_type' => $type,
            'step_type_label' => $type === null ? self::NO_VALUE : (WorkflowStep::typeLabels()[$type] ?? $type),
            'step_index' => $index,
            'job_id' => $step->jobId,
            // The job of an approval step is the placeholder that holds its request: the request is linked instead.
            'show_job_link' => $type !== WorkflowStep::TYPE_APPROVAL,
            'job_status' => $step->jobStatus,
            'status' => $step->status,
            'status_label' => WorkflowJobStep::statusLabel($step->status),
            'status_css' => WorkflowJobStep::statusCssClass($step->status),
            'state' => $step->state,
            'state_label' => Display::label($step->state),
            'state_css' => Display::css($step->state),
            'waiting_for' => $step->waitingFor,
            'approval' => self::approval($step->approval),
            'decision_note' => $step->decisionNote,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function times(StepState $step, int $now): array
    {
        $duration = $step->startedAt === null ? null : max(0, ($step->finishedAt ?? $now) - $step->startedAt);

        return [
            'started_at' => $step->startedAt,
            'started_label' => self::stamp($step->startedAt, $now),
            'finished_at' => $step->finishedAt,
            'finished_label' => self::stamp($step->finishedAt, $now),
            'duration_seconds' => $duration,
            'duration_label' => Display::durationLabel($duration, $step->state, $step->finishedAt !== null),
        ];
    }

    /**
     * @return array{id: int, label: string, status: string, status_label: string, url: string|null}|null
     */
    private static function approval(?ApprovalWait $wait): ?array
    {
        if ($wait === null) {
            return null;
        }

        return [
            'id' => $wait->requestId,
            'label' => sprintf(self::REQUEST_LABEL, $wait->requestId),
            'status' => $wait->status,
            'status_label' => ApprovalRequest::statusLabel($wait->status),
            'url' => $wait->linkable ? Banner::requestUrl($wait->requestId) : null,
        ];
    }

    /**
     * The execution the page highlights: the newest one of the run's
     * current step; null when no execution has that step (it was removed,
     * or the run has not started one).
     */
    private static function currentExecutionId(WorkflowJob $run, RunState $state): ?int
    {
        $currentStepId = self::intOrNull($run->current_step_id);
        $current = null;
        foreach ($state->steps as $step) {
            if ($currentStepId !== null && $step->workflowStepId === $currentStepId) {
                $current = $step->executionId;
            }
        }

        return $current;
    }

    private static function stamp(?int $timestamp, int $now): ?string
    {
        return $timestamp === null ? null : TimeHelper::stamp($timestamp, $now);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int)$value;
    }
}
