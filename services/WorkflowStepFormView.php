<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowRouteStep;
use app\components\WorkflowStepCommand;
use app\components\WorkflowStepPosition;
use app\models\WorkflowStep;

/**
 * The step form (views/workflow-template/_step-form.php): Add Step on the
 * workflow template page, and the form of the page that edits one step. It
 * says what the form offers and selects, and every text the form shows.
 *
 * - Position: Add Step puts a new step at the end by default ("+ Insert
 *   below" selects after that step); Edit Step keeps the step where it is by
 *   default. Each choice carries where the step would run and the warnings
 *   it would cause, worked out from the branches as runs follow them
 *   (WorkflowStepFormPositions); the form shows those of the selected
 *   choice, and the page script those of the next one.
 * - Type: chosen on Add Step; fixed on Edit Step
 *   (WorkflowStepCommand::TYPE_LOCKED).
 * - Branches: "Next in the list" names the step it is for the selected
 *   Position; a new job step ends the workflow when it fails; an approval
 *   step's On Failure has one end choice; Edit Step offers every step,
 *   later ones and the step itself included (WorkflowStepFormRoutes).
 *
 * The form posts what WorkflowStepCommand::fromForm() reads: WorkflowStep[...]
 * and position (WorkflowStepPosition::fromWeb() values). Every text is plain
 * text: the view escapes it.
 *
 * @phpstan-import-type PositionOption from WorkflowStepFormPositions
 * @phpstan-import-type RouteSelect from WorkflowStepFormRoutes
 */
final class WorkflowStepFormView
{
    public const ADD_HEADING = 'Add Step';

    public const ADD_SUBMIT = 'Add Step';

    /** sprintf(): WorkflowStepsTable::STEP_REFERENCE. */
    public const EDIT_HEADING = 'Edit %s';

    public const EDIT_SUBMIT = 'Save Step';

    public const CANCEL = 'Cancel';

    public const NAME_LABEL = 'Name';

    public const NAME_PLACEHOLDER = 'Step name';

    public const TYPE_LABEL = 'Step Type';

    public const JOB_TEMPLATE_LABEL = 'Job Template';

    public const JOB_TEMPLATE_PROMPT = '— select a job template —';

    public const APPROVAL_RULE_LABEL = 'Approval Rule';

    public const APPROVAL_RULE_PROMPT = '— select an approval rule —';

    public const POSITION_LABEL = 'Position';

    /** The id of the Position field, which Move to… on a long steps table links to. */
    public const POSITION_FIELD_ID = 'ws-position-field';

    /** sprintf(): the last step's label (WorkflowStepsTable::STEP_LABEL). */
    public const POSITION_END = 'At the end (after %s)';

    public const POSITION_START = 'At the start (runs will start with this step)';

    /** sprintf(): the step's label (WorkflowStepsTable::STEP_LABEL). */
    public const POSITION_AFTER = 'After %s';

    /** sprintf(): the step's position. */
    public const POSITION_KEEP = 'Keep at position %d';

    /** Add Step on a workflow without steps, which has no Position. */
    public const FIRST_STEP_NOTE = 'The first step: runs will start with it.';

    /** sprintf(): the name of the step after it. */
    public const PREVIEW_START = 'Runs first, before "%s".';

    /** At the start, when its own success does not go on with the step after it. */
    public const PREVIEW_FIRST = 'Runs first.';

    /** sprintf(): the names of the steps before and after it. */
    public const PREVIEW_BETWEEN = 'Runs after "%1$s", before "%2$s".';

    /** sprintf(): the name of the step before it. */
    public const PREVIEW_END = 'Runs last, after "%s".';

    /** sprintf(): the name of the step before it; its own success does not go on with the step after it. */
    public const PREVIEW_AFTER = 'Runs after "%s".';

    public const PREVIEW_ONLY = 'Runs as the only step.';

    /** sprintf(): the name of the step before it, then where that step's success leads instead. */
    public const PREVIEW_SKIPPED_TO = 'Listed after "%1$s", but a successful "%1$s" goes on with "%2$s", '
        . 'skipping this step.';

    /** sprintf(): the name of the step before it, whose success ends the run (also at a step that no longer exists). */
    public const PREVIEW_SKIPPED_END = 'Listed after "%1$s", but runs end when "%1$s" succeeds, skipping this step.';

    /** sprintf(): the name of the step before it, which cannot run. */
    public const PREVIEW_AFTER_BROKEN = 'Listed after "%s", which cannot run: a run fails there instead of going on '
        . 'to this step.';

    /** sprintf(): the name of the current start step. */
    public const WILL_START_THIS = 'Runs will start with this step instead of "%s".';

    /** sprintf(): the name of the step that would start the runs. */
    public const WILL_START_OTHER = 'Runs will start with "%s" instead of this step.';

    /** sprintf(): the approval step's name. */
    public const THIS_WILL_PRECEDE_APPROVAL = 'This job step will run before the approval step "%s", without waiting for it.';

    /** sprintf(): the job step's name. */
    public const WILL_PRECEDE_THIS_APPROVAL = 'Job step "%s" will run before this approval step, without waiting for it.';

    /** An approval step placed where no success leads to it. */
    public const THIS_APPROVAL_OFF_PATH = 'This approval step will not be on the success path: '
        . 'runs whose steps all succeed will not wait for it.';

    public const SUCCESS_LABEL = 'On Success → go to';

    /** The label of a pause step's On Success: Resume continues there. */
    public const SUCCESS_LABEL_PAUSE = 'After Resume → go to';

    public const FAILURE_LABEL = 'On Failure → go to';

    public const ALWAYS_LABEL = 'Always → go to (overrides success/failure)';

    /** sprintf(): the name of the next step in the list. */
    public const NEXT_OPTION = 'Next in the list: "%s"';

    public const NEXT_OPTION_NONE_SUCCEEDS = 'Next in the list: none, the run succeeds';

    /** sprintf(): the name of the next step in the list. */
    public const NEXT_FAILURE_OPTION = 'Next in the list: "%s", also after a failure';

    public const NEXT_OPTION_NONE_FAILS = 'Next in the list: none, the run fails';

    public const END_SUCCEEDS_OPTION = 'End the workflow: the run succeeds';

    public const END_FAILS_OPTION = 'End the workflow: the run fails';

    public const ALWAYS_NONE_OPTION = 'Not set: On Success or On Failure decides';

    /** sprintf(): the step's label (WorkflowStepsTable::STEP_LABEL). */
    public const THIS_STEP_OPTION = '%s (this step)';

    public const FAILURE_DEFAULT_NOTE = 'A new job step ends the workflow when it fails. Choose "Next in the list" to go '
        . 'on instead.';

    public const TEMPLATE_GONE_HINT = 'The job template of this step no longer exists: choose another one.';

    public const LEGACY_ALWAYS_NOTE = 'This step has an Always branch from an older version, which runs follow when it '
        . 'succeeds. It shows under On Success now and is saved there.';

    /** The edit page's list of the steps whose branches lead to the step. */
    public const ROUTED_BY_TITLE = 'Steps that route here';

    public const ROUTED_BY_NONE = 'No other step routes here.';

    /** Under a step of that list whose branch here the Always branch overrides. */
    public const ROUTED_BY_ALWAYS_WINS = 'Not used: its Always branch is taken instead.';

    /** Under a pause step of that list whose On Failure, from an older version, leads here. */
    public const ROUTED_BY_PAUSE_FAILURE = 'Not used: a pause step does not fail. Saving that step clears its On '
        . 'Failure.';

    /** Under a step of that list that cannot run (a missing job template or approval rule). */
    public const ROUTED_BY_CANNOT_RUN = 'Not used: that step cannot run.';

    /** Under a step of that list of a type that is not known, which no save accepts. */
    public const ROUTED_BY_UNKNOWN_TYPE = 'Not used: that step has an unknown type and cannot be saved. Remove it '
        . 'first.';

    /** Before the edit page's read-only variable mapping. */
    public const EXTRA_VARS_READONLY = 'Variable mapping (change it through the API):';

    /** The routing help of each step type: a title, then the text. */
    public const HELP_JOB_TITLE = 'Job step:';

    public const HELP_JOB = 'success = exit code 0. Failure = any non-zero exit, timeout, or unreachable host. Always '
        . 'overrides both. If the job template has an approval rule and the approval is rejected, times out or is '
        . 'canceled, the job does not run: then only On Failure is followed (left empty, the run ends as failed), and '
        . 'Always is not.';

    public const HELP_APPROVAL_TITLE = 'Approval step:';

    public const HELP_APPROVAL = 'success = approved; failure = rejected, timed out or canceled. After a failure the '
        . 'run ends as failed unless On Failure names a step. An approval step has no Always route: it would continue '
        . 'past a rejection.';

    public const HELP_PAUSE_TITLE = 'Pause step:';

    public const HELP_PAUSE = 'pauses only resume via the operator clicking Resume, which counts as success — so only '
        . 'the "After Resume → go to" field applies. On Failure and Always are ignored for pause.';

    /** The id of the Add Step form. */
    public const ADD_FORM_ID = 'add-step-form';

    /** The id of the Edit Step form. */
    public const EDIT_FORM_ID = 'edit-step-form';

    /** The step types the form shows a target and routing help for. */
    private const KNOWN_TYPES = [WorkflowStep::TYPE_JOB, WorkflowStep::TYPE_APPROVAL, WorkflowStep::TYPE_PAUSE];

    /** Edit Step for a step the route map does not have (a caller error); sprintf(): its ID. */
    private const NOT_IN_MAP = 'The route map has no step ID %d.';

    /**
     * @param WorkflowRouteStep|null $editing the step Edit Step changes; null for Add Step
     * @param WorkflowStep|null $stored that step as stored
     * @param array<string, PositionOption> $positions the Position choices; none without a Position
     * @param string $selected the selected Position choice; '' without a Position
     */
    private function __construct(
        private readonly WorkflowRouteMap $map,
        private readonly ?WorkflowRouteStep $editing,
        private readonly ?WorkflowStep $stored,
        private readonly array $positions,
        private readonly string $selected,
        private readonly bool $autofocus,
    ) {
    }

    /**
     * Add Step on the workflow of $map. $after: the step after which "+
     * Insert below" places the new step (the end after the last step); the
     * name then has the focus. A step $map does not have is ignored.
     */
    public static function forAdd(WorkflowRouteMap $map, ?int $after): self
    {
        $positions = WorkflowStepFormPositions::forAdd($map);
        $anchor = $after === null ? null : $map->find($after);
        $selected = $positions === [] ? '' : WorkflowStepPosition::KIND_END;
        $afterValue = WorkflowStepPosition::WEB_AFTER_PREFIX . $anchor?->id;
        if ($anchor !== null && isset($positions[$afterValue])) {
            $selected = $afterValue;
        }

        return new self($map, null, null, $positions, $selected, $anchor !== null);
    }

    /**
     * Edit Step for $step as stored, a step of $map. $position: the Position
     * posted when the form is shown again after a refused save; null, or a
     * choice the form does not offer, keeps the step where it is.
     *
     * @throws \InvalidArgumentException when $map does not have $step
     */
    public static function forEdit(WorkflowRouteMap $map, WorkflowStep $step, ?string $position = null): self
    {
        $stepId = (int)$step->id;
        $editing = $map->find($stepId) ?? throw new \InvalidArgumentException(sprintf(self::NOT_IN_MAP, $stepId));
        $positions = WorkflowStepFormPositions::forEdit($map, $editing);
        $selected = $position !== null && isset($positions[$position]) ? $position : WorkflowStepPosition::WEB_KEEP;

        return new self($map, $editing, $step, $positions, $selected, false);
    }

    public function isEdit(): bool
    {
        return $this->editing !== null;
    }

    public function formId(): string
    {
        return $this->editing === null ? self::ADD_FORM_ID : self::EDIT_FORM_ID;
    }

    /**
     * "Add Step", or 'Edit step 2 "Test"'.
     */
    public function heading(): string
    {
        $step = $this->editing;

        return $step === null
            ? self::ADD_HEADING
            : sprintf(self::EDIT_HEADING, WorkflowStepsTable::stepReference((int)$this->map->position($step->id), $step->name));
    }

    public function submitLabel(): string
    {
        return $this->editing === null ? self::ADD_SUBMIT : self::EDIT_SUBMIT;
    }

    /**
     * Where Cancel leads on Edit Step: the step's row on the template page;
     * null for Add Step, which has no Cancel.
     *
     * @return array<int|string, int|string>|null
     */
    public function cancelRoute(): ?array
    {
        $step = $this->stored;
        if ($step === null) {
            return null;
        }

        return ['/workflow-template/view', 'id' => (int)$step->workflow_template_id, '#' => 'step-' . (int)$step->id];
    }

    /**
     * Whether the name field has the focus ("+ Insert below").
     */
    public function hasAutofocus(): bool
    {
        return $this->autofocus;
    }

    /**
     * The type the form shows for $model: the stored type on Edit Step, else
     * the type of $model, a job step by default.
     */
    public function type(WorkflowStep $model): string
    {
        if ($this->stored !== null) {
            return (string)$this->stored->step_type;
        }

        return in_array($model->step_type, self::KNOWN_TYPES, true) ? (string)$model->step_type : WorkflowStep::TYPE_JOB;
    }

    /**
     * The step types to choose: on Add Step those the user may add (approval
     * steps are for users who may assign approval rules); on Edit Step,
     * where the type is fixed, every type and an unknown stored one.
     *
     * @return array<string, string>
     */
    public function typeItems(bool $mayAssignRules, WorkflowStep $model): array
    {
        $types = WorkflowStep::typeLabels();
        if ($this->stored === null) {
            return $mayAssignRules ? $types : array_diff_key($types, [WorkflowStep::TYPE_APPROVAL => true]);
        }
        $type = $this->type($model);

        return $types + [$type => $type];
    }

    /**
     * The hint under the type: on Edit Step, that it cannot change.
     */
    public function typeHint(): ?string
    {
        return $this->editing === null ? null : WorkflowStepCommand::TYPE_LOCKED;
    }

    /**
     * The Position select for a step of $model's type, null without one (Add
     * Step on a workflow without steps): its choices, the attributes of
     * each choice (data-preview, data-warn, data-warn-job for a job step,
     * data-warn-approval for an approval step, data-success-prompt,
     * data-failure-prompt), the selected choice, and its preview and warning
     * as shown before the page script runs.
     *
     * @return array{
     *     items: array<string, string>,
     *     options: array<string, array<string, string>>,
     *     selected: string,
     *     preview: string,
     *     warning: string
     * }|null
     */
    public function position(WorkflowStep $model): ?array
    {
        $selected = $this->positions[$this->selected] ?? null;
        if ($selected === null) {
            return null;
        }
        $warnings = match ($this->type($model)) {
            WorkflowStep::TYPE_JOB => [$selected['warn'], $selected['warnJob']],
            WorkflowStep::TYPE_APPROVAL => [$selected['warn'], $selected['warnApproval']],
            default => [$selected['warn']],
        };

        return [
            'items' => array_map(static fn (array $option): string => $option['label'], $this->positions),
            'options' => array_map(static fn (array $option): array => [
                'data-preview' => $option['preview'],
                'data-warn' => $option['warn'],
                'data-warn-job' => $option['warnJob'],
                'data-warn-approval' => $option['warnApproval'],
                'data-success-prompt' => $option['successPrompt'],
                'data-failure-prompt' => $option['failurePrompt'],
            ], $this->positions),
            'selected' => $this->selected,
            'preview' => $selected['preview'],
            'warning' => trim(implode(' ', $warnings)),
        ];
    }

    /**
     * The branch selects for $model (WorkflowStepFormRoutes), with "Next in
     * the list" for the selected Position.
     *
     * @return array{success: RouteSelect, failure: RouteSelect, always: RouteSelect}
     */
    public function routes(WorkflowStep $model): array
    {
        $selected = $this->positions[$this->selected] ?? null;

        return (new WorkflowStepFormRoutes($this->map, $this->editing))->of(
            $model,
            $this->type($model),
            $selected['successPrompt'] ?? self::NEXT_OPTION_NONE_SUCCEEDS,
            $selected['failurePrompt'] ?? self::NEXT_OPTION_NONE_FAILS
        );
    }

    /**
     * Which notes the form shows for $model: the failure default of a new
     * job step (Add Step; the page script shows it for job steps only), an
     * Always branch from an older version moved to On Success, and a job
     * template that no longer exists (Edit Step).
     *
     * @return array{failureDefault: bool, legacyAlways: bool, templateGone: bool}
     */
    public function notes(WorkflowStep $model): array
    {
        $type = $this->type($model);
        $stored = $this->stored;

        return [
            'failureDefault' => $stored === null && $type === WorkflowStep::TYPE_JOB,
            'legacyAlways' => $stored !== null && WorkflowStepFormRoutes::hasLegacyAlways($stored, $type),
            'templateGone' => $stored !== null
                && $this->editing?->problem === WorkflowRouteStep::PROBLEM_JOB_TEMPLATE_MISSING
                && (int)$stored->job_template_id > 0,
        ];
    }

    /**
     * Which parts of the form a step of $model's type shows (the page script
     * keeps them so when the type changes): its target, the branches it
     * takes, and its routing help, by element id. A part not shown is hidden
     * and its select disabled, so the browser neither checks nor posts it.
     *
     * @return array<string, bool>
     */
    public function shows(WorkflowStep $model): array
    {
        $type = $this->type($model);

        return [
            'ws-target-job' => $type === WorkflowStep::TYPE_JOB,
            'ws-target-approval' => $type === WorkflowStep::TYPE_APPROVAL,
            'ws-route-failure' => $type !== WorkflowStep::TYPE_PAUSE,
            'ws-route-always' => $type !== WorkflowStep::TYPE_PAUSE && $type !== WorkflowStep::TYPE_APPROVAL,
            'ws-routing-help-job' => $type === WorkflowStep::TYPE_JOB,
            'ws-routing-help-approval' => $type === WorkflowStep::TYPE_APPROVAL,
            'ws-routing-help-pause' => $type === WorkflowStep::TYPE_PAUSE,
        ];
    }
}
