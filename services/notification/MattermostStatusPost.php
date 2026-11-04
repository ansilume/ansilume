<?php

declare(strict_types=1);

namespace app\services\notification;

use app\helpers\TimeHelper;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\services\runstate\Banner;
use app\services\runstate\Display;
use app\services\runstate\RunState;
use app\services\runstate\StepState;

/**
 * The status post of a workflow run on Mattermost: the props of one
 * attachment, rendered from the run's state as it is when the post is sent
 * (WorkflowRunStates::forRun() for the user the template delivers as), so
 * that every edit shows the run as the run page does, whatever event it
 * was sent for:
 * - the title: the run and its workflow, linked to the run's page;
 * - the colour of its state (STATE_COLORS);
 * - what it does now: what it waits for (the run page's banner title, with
 *   the link to the approval request, or the run), the step that runs, or,
 *   once it ended, its result;
 * - each step run with its position and state, the last MAX_STEP_LINES of
 *   a run that ran more;
 * - the fields: the state, the steps done of the workflow's steps, and the
 *   duration of a run that ended.
 * Every name and error is escaped (MattermostText), and the text is cut to
 * MattermostText::MAX_LENGTH with a link to the run.
 */
final class MattermostStatusPost
{
    /** The colour of the attachment by the run's display state (Display::STATE_*), as Mattermost takes it: #RRGGBB. */
    public const STATE_COLORS = [
        Display::STATE_PENDING => '#6C757D',
        Display::STATE_QUEUED => '#6C757D',
        Display::STATE_RUNNING => '#1C58D9',
        Display::STATE_AWAITING_APPROVAL => '#FFBC1F',
        Display::STATE_PAUSED => '#FFBC1F',
        Display::STATE_SUCCEEDED => '#3DB887',
        Display::STATE_FAILED => '#D24B4E',
        Display::STATE_CANCELED => '#6C757D',
        Display::STATE_SKIPPED => '#6C757D',
    ];

    /** The colour of a state not in STATE_COLORS. */
    public const DEFAULT_COLOR = '#1C58D9';

    /** The title; sprintf(): the run's ID, the workflow's name (escaped). */
    public const TITLE = 'Workflow Job #%d: %s';

    /** The fallback, which notifications show; sprintf(): the run's ID, the workflow's name, the state's label. */
    public const FALLBACK = 'Workflow Job #%d (%s): %s';

    /** The line of what the run does now; sprintf(): the text, the link. */
    public const NOW = '**Now:** %s %s';

    /** What a run that waits for nothing does now; sprintf(): the step (stepLabel()). */
    public const STEP_RUNS = 'Step %s runs.';

    /** What a running run does between two steps. */
    public const BETWEEN_STEPS = 'The run goes on to its next step.';

    /** The line of a run that ended; sprintf(): the state's label, the link. */
    public const RESULT = '**Result:** %s. %s';

    /** A step run; sprintf(): the step (stepLabel()), the state's label. */
    public const STEP_LINE = 'Step %s: %s';

    /** The line before the last MAX_STEP_LINES step runs; sprintf(): the step runs before them. */
    public const EARLIER = '… and %d earlier step runs';

    /** The most step runs listed. */
    public const MAX_STEP_LINES = 30;

    /** A step with its position; sprintf(): the position, the name (escaped). */
    public const STEP = '%d "%s"';

    /** A step without a position (removed from its workflow since); sprintf(): the name (escaped). */
    public const STEP_REMOVED = '"%s"';

    /** The labels of the fields and the links. */
    public const FIELD_STATUS = 'Status';
    public const FIELD_STEPS = 'Steps';
    public const FIELD_DURATION = 'Duration';
    public const STEPS_DONE = '%d of %d done';
    public const OPEN_RUN = 'Open the run';
    public const OPEN_APPROVAL = 'Open the approval request';

    /** The longest workflow name in the title. */
    public const NAME_LENGTH = 200;

    /** The statuses of a step run that ended, which count as done. */
    private const ENDED = [WorkflowJobStep::STATUS_SUCCEEDED, WorkflowJobStep::STATUS_FAILED, WorkflowJobStep::STATUS_SKIPPED];

    /**
     * The props of the status post of the run in $state.
     *
     * @param string $workflowName the name of the run's workflow template
     * @param string $runUrl the run's page (AppLinks::workflowRun())
     * @param array<int, int> $positions the index (position - 1) of each step of the workflow by its ID
     * @param string|null $approvalUrl the page of the approval request the run waits for, when the user may see it
     * @return array{attachments: list<array<string, mixed>>}
     */
    public static function props(
        RunState $state,
        string $workflowName,
        string $runUrl,
        array $positions,
        ?string $approvalUrl,
        ?int $startedAt,
        ?int $finishedAt
    ): array {
        $name = mb_substr($workflowName, 0, self::NAME_LENGTH);
        $label = Display::label($state->state);
        $fields = [
            self::field(self::FIELD_STATUS, $label),
            self::field(self::FIELD_STEPS, sprintf(self::STEPS_DONE, self::stepsDone($state, $positions), count($positions))),
        ];
        if ($state->status !== WorkflowJob::STATUS_RUNNING && $startedAt !== null && $finishedAt !== null) {
            $fields[] = self::field(self::FIELD_DURATION, TimeHelper::duration($finishedAt - $startedAt));
        }
        $attachment = [
            'fallback' => MattermostText::plain(sprintf(self::FALLBACK, $state->runId, $name, $label)),
            'color' => self::STATE_COLORS[$state->state] ?? self::DEFAULT_COLOR,
            'title' => sprintf(self::TITLE, $state->runId, MattermostText::escape($name)),
            'text' => MattermostText::truncate(self::text($state, $runUrl, $positions, $approvalUrl), $runUrl),
            'fields' => $fields,
        ];
        if (MattermostText::isAddress($runUrl)) {
            $attachment['title_link'] = $runUrl;
        }

        return ['attachments' => [$attachment]];
    }

    /**
     * How a message names a step: its position and name, or its name
     * alone once it was removed from its workflow ($position '').
     */
    public static function stepLabel(string $position, string $name): string
    {
        return $position === '' ? sprintf(self::STEP_REMOVED, MattermostText::escape($name)) : sprintf(self::STEP, (int)$position, MattermostText::escape($name));
    }

    /**
     * @param array<int, int> $positions
     */
    private static function text(RunState $state, string $runUrl, array $positions, ?string $approvalUrl): string
    {
        $lines = [self::now($state, $runUrl, $positions, $approvalUrl), ''];
        $steps = $state->steps;
        $earlier = count($steps) - self::MAX_STEP_LINES;
        if ($earlier > 0) {
            $lines[] = sprintf(self::EARLIER, $earlier);
            $steps = array_slice($steps, $earlier);
        }
        foreach ($steps as $step) {
            $lines[] = sprintf(self::STEP_LINE, self::labelOf($step, $positions), Display::label($step->state));
        }

        return implode("\n", $lines);
    }

    /**
     * What the run does now (NOW), or its result once it ended (RESULT).
     *
     * @param array<int, int> $positions
     */
    private static function now(RunState $state, string $runUrl, array $positions, ?string $approvalUrl): string
    {
        $runLink = MattermostText::link(self::OPEN_RUN, $runUrl);
        if ($state->status !== WorkflowJob::STATUS_RUNNING) {
            return sprintf(self::RESULT, Display::label($state->state), $runLink);
        }
        $current = $state->current;
        if ($current === null) {
            return sprintf(self::NOW, self::BETWEEN_STEPS, $runLink);
        }
        $wait = Banner::title($state->waitingFor, $current->stepType, $current->stepName, $current->jobId);
        if ($wait === null) {
            return sprintf(self::NOW, sprintf(self::STEP_RUNS, self::labelOf($current, $positions)), $runLink);
        }
        $link = $approvalUrl !== null && $state->waitingFor === Display::WAITING_FOR_APPROVAL
            ? MattermostText::link(self::OPEN_APPROVAL, $approvalUrl)
            : $runLink;

        return sprintf(self::NOW, MattermostText::escape($wait), $link);
    }

    /**
     * @param array<int, int> $positions
     */
    private static function labelOf(StepState $step, array $positions): string
    {
        $index = $step->workflowStepId === null ? null : ($positions[$step->workflowStepId] ?? null);

        return self::stepLabel($index === null ? '' : (string)($index + 1), $step->stepName);
    }

    /**
     * The steps of the workflow ($positions) that ended in the run, each
     * once however often a loop ran it.
     *
     * @param array<int, int> $positions
     */
    private static function stepsDone(RunState $state, array $positions): int
    {
        $done = [];
        foreach ($state->steps as $step) {
            if ($step->workflowStepId !== null && isset($positions[$step->workflowStepId]) && in_array($step->status, self::ENDED, true)) {
                $done[$step->workflowStepId] = true;
            }
        }

        return count($done);
    }

    /**
     * @return array{title: string, value: string, short: bool}
     */
    private static function field(string $title, string $value): array
    {
        return ['title' => $title, 'value' => $value, 'short' => true];
    }
}
