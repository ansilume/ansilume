<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowRouteStep;
use app\components\WorkflowStepPosition;
use app\models\WorkflowStep;
use app\services\runstate\Texts;

/**
 * The steps table of the workflow template page
 * (views/workflow-template/_steps.php), and the texts that name steps by
 * their position: what each row shows, the success path above the table,
 * and the step actions with their Move to… targets.
 *
 * A step is named by its position in the run order (STEP_LABEL "3. Deploy",
 * STEP_REFERENCE 'step 3 "Deploy"'), never by its step_order, which is an
 * internal sort key. Every route cell names where the branch really leads,
 * worked out by the route map the way the engine routes a run
 * (WorkflowStepRouteCells): an empty branch goes on with the next step
 * (marked "(next)"), or ends the run after the last step; a branch back to
 * the same or an earlier step is a loop (↺); a branch the step never takes
 * reads "—" with the reason as its title.
 *
 * The step actions (Edit, + Insert below, ▲/▼, Move to…, Remove) are built
 * only for a table with actions, which the page shows only to users who may
 * change the steps. A Move to… target that would not move the step is left
 * out, and each target carries the warnings the move would cause, worked out
 * from the branches as runs follow them (WorkflowRouteMap::effectsSince()):
 * another start step, job steps that runs would reach without passing the
 * approval step they pass now, or an approval step off the success path.
 *
 * A table of more than MOVE_MENU_LIMIT steps has no Move to… menus: each
 * row's Move to… links to the page that edits the step, at its Position
 * field, which lists every place with the same warnings. Regression: every
 * row listed every other place with its warnings, so the page grew with the
 * square of the steps (1.2 MB for 50 steps), and worked them out from a
 * route map per place (about the cube).
 *
 * Every text is plain text: the view escapes it.
 *
 * @phpstan-import-type RouteCell from WorkflowStepRouteCells
 * @phpstan-import-type Target from WorkflowStepTargetCell
 * @phpstan-type MoveTarget array{value: string, label: string, hints: list<string>}
 * @phpstan-type Actions array{
 *     edit: string,
 *     insertBelow: string,
 *     moveUp: string,
 *     moveDown: string,
 *     moveTo: string,
 *     remove: string,
 *     confirm: string,
 *     moveToEditPage: bool,
 *     moveTargets: list<MoveTarget>
 * }
 * @phpstan-type Row array{
 *     id: int,
 *     position: int,
 *     name: string,
 *     type: string,
 *     typeLabel: string,
 *     isStart: bool,
 *     isFirst: bool,
 *     isLast: bool,
 *     reachable: bool,
 *     gatedTitle: string|null,
 *     waiting: string|null,
 *     target: Target,
 *     routes: array{success: RouteCell, failure: RouteCell, always: RouteCell},
 *     actions: Actions|null
 * }
 */
final class WorkflowStepsTable
{
    /** The heading of the steps section. */
    public const STEPS_HEADING = 'Steps';

    /** A workflow without steps. */
    public const NO_STEPS = 'No steps defined yet.';

    /** The column headings, without the actions column. */
    public const COLUMNS = ['#', 'Name', 'Type', 'Target', 'On Success', 'On Failure', 'Always'];

    /** The heading of the actions column, shown to users who may change the steps. */
    public const ACTIONS_COLUMN = 'Actions';

    /** The title of the "#" heading: the column holds positions. */
    public const POSITION_TITLE = 'Position: runs start with step 1.';

    /** The badge of the step at position 1. */
    public const START_BADGE = 'Start';

    public const START_TITLE = 'Runs start with this step.';

    /**
     * The badge of a step that no branch a run takes leads to: no branch of
     * a reachable step, nor of a step that cannot run, where runs fail
     * (WorkflowRouteMap::isReachable()).
     */
    public const UNREACHABLE_BADGE = 'unreachable';

    public const UNREACHABLE_TITLE = 'No branch that a run takes leads to this step, so runs never start it.';

    /** The badge of a job step whose job template, or that template's inventory, has an approval rule. */
    public const GATED_BADGE = 'needs approval';

    /** sprintf(): the approval rule's name. */
    public const GATED_TITLE = 'Its job template has the approval rule "%s": the job waits for an approval before it runs.';

    /** The badge's title when the inventory's rule gates the step; sprintf(): the approval rule's name. */
    public const GATED_INVENTORY_TITLE = 'Its job template\'s inventory has the approval rule "%s": the job waits '
        . 'for an approval before it runs.';

    /** sprintf(): the position, the name ("3. Deploy"). */
    public const STEP_LABEL = '%1$d. %2$s';

    /** sprintf(): the position, the name ('step 3 "Deploy"'). */
    public const STEP_REFERENCE = 'step %1$d "%2$s"';

    /** After the label of an approval step in the success path and the step choices. */
    public const APPROVAL_STEP_SUFFIX = ' (approval step)';

    /** A branch to a later step; sprintf(): its STEP_LABEL. */
    public const ROUTE_TO = '→ %s';

    /** An empty branch, which goes on with the next step; sprintf(): its STEP_LABEL. */
    public const ROUTE_TO_NEXT = '→ %s (next)';

    /** A branch back to the step itself or an earlier one; sprintf(): its STEP_LABEL. */
    public const ROUTE_LOOP = '↺ %s';

    /** A branch the step never takes, and an empty Always branch. */
    public const ROUTE_UNUSED = '—';

    /** A branch to a step that no longer exists; sprintf(): its step ID. */
    public const ROUTE_MISSING = 'missing step (ID %d) · run fails';

    public const ROUTE_END_SUCCEEDS = 'end · run succeeds';

    public const ROUTE_END_FAILS = 'end · run fails';

    /** A job step's Always branch that ends the run: the run ends with the step's outcome. */
    public const ROUTE_END_WITH_STEP = 'end · run succeeds or fails with this step';

    /** After a job step's empty On Failure that leads where On Success leads. */
    public const ALSO_AFTER_FAILURE = ', also after a failure';

    /** After a gated job step's empty On Failure that leads where On Success leads. */
    public const ALSO_AFTER_FAILED_JOB = ', also after a failed job';

    /** After a gated job step's On Failure for a job that ran and failed. */
    public const AFTER_FAILED_JOB = ' after a failed job';

    /** After a gated job step's On Failure for a rejected, timed-out or canceled approval. */
    public const AFTER_REFUSED_APPROVAL = ' after a refused approval';

    /** Between the two parts of a gated job step's On Failure. */
    public const PART_SEPARATOR = '; ';

    /** After the Always branch of an approval step, which older versions saved: taken on approval only. */
    public const APPROVAL_ALWAYS_NOTE = '(only on approval)';

    /** After the Always branch of a gated job step: taken only after its job ran. */
    public const GATED_JOB_ALWAYS_NOTE = '(not after a rejected, timed-out or canceled approval)';

    /** After the Always branch of a pause step, which older versions saved: taken on Resume. */
    public const PAUSE_ALWAYS_NOTE = '(on Resume)';

    public const NEXT_TITLE = 'This branch is empty: the run goes on with the next step in the list, so where it '
        . 'leads changes when steps move.';

    public const LOOP_TITLE = 'Leads back to this step or an earlier one: the run repeats steps until a branch leads '
        . 'elsewhere.';

    public const ALWAYS_WINS_TITLE = 'Not used: the Always branch is taken instead.';

    public const PAUSE_FAILURE_TITLE = 'Not used: a pause step does not fail; Resume continues with On Success.';

    public const CANNOT_RUN_TITLE = 'Not used: this step cannot run, so a run fails when it reaches it.';

    public const MISSING_TITLE = 'The step this branch leads to no longer exists: the run fails here. Edit the step '
        . 'to choose another one.';

    public const REFUSED_APPROVAL_TITLE = 'A refused approval was rejected, timed out or canceled: the job never ran, '
        . 'only On Failure is followed, and Always is not.';

    /** The target of a pause step, which has none. */
    public const NO_TARGET = '—';

    /** sprintf(): the job template ID the step stores. */
    public const TARGET_TEMPLATE_GONE = 'job template ID %d no longer exists · runs fail here';

    public const TARGET_TEMPLATE_NONE = 'no job template · runs fail here';

    /** sprintf(): the approval rule ID the step, or its job template, stores. */
    public const TARGET_RULE_GONE = 'approval rule ID %d no longer exists · runs fail here';

    public const TARGET_RULE_NONE = 'no approval rule · runs fail here';

    /** A job step whose job template and its inventory have different approval rules: its launch is refused. */
    public const TARGET_RULES_DIFFER = 'job template and inventory have different approval rules · runs fail here';

    public const TARGET_UNKNOWN_TYPE = 'unknown step type · runs fail here';

    public const SUCCESS_PATH_LABEL = 'Success path:';

    public const SUCCESS_PATH_SEPARATOR = ' → ';

    /** The last step of a success path that reaches a step that cannot run; sprintf(): its label. */
    public const SUCCESS_PATH_CANNOT_RUN = '%s cannot run · run fails';

    public const SUCCESS_PATH_LOOP_NOTE = 'When every step succeeds, the run repeats these steps until one fails or '
        . 'the run is canceled.';

    public const LEGEND = 'A run starts with step 1. An empty branch goes on with the next step in the list, marked '
        . '(next), and changes when steps move. ↺ marks a branch back to the same or an earlier step.';

    public const EDIT = 'Edit';

    /** sprintf(): STEP_REFERENCE. */
    public const EDIT_LABEL = 'Edit %s';

    public const INSERT_BELOW = '+ Insert below';

    /** sprintf(): STEP_REFERENCE. */
    public const INSERT_BELOW_LABEL = 'Insert a step below %s';

    /** The text of the ▲ button. */
    public const MOVE_UP_BUTTON = '▲';

    /** The text of the ▼ button. */
    public const MOVE_DOWN_BUTTON = '▼';

    public const MOVE_UP_TITLE = 'Move up';

    public const MOVE_DOWN_TITLE = 'Move down';

    /** sprintf(): STEP_REFERENCE. */
    public const MOVE_UP_LABEL = 'Move %s up';

    /** sprintf(): STEP_REFERENCE. */
    public const MOVE_DOWN_LABEL = 'Move %s down';

    public const MOVE_TO = 'Move to…';

    /** sprintf(): STEP_REFERENCE. */
    public const MOVE_TO_LABEL = 'Move %s to another position';

    /** Above this many steps, Move to… links to the step's edit page instead of listing every place in each row. */
    public const MOVE_MENU_LIMIT = 20;

    /** The title of Move to… as a link to the step's edit page. */
    public const MOVE_TO_EDIT_PAGE_TITLE = 'Choose the place on the page of the step: its Position field says what each '
        . 'place would change.';

    public const MOVE_TO_START = 'To the start';

    /** sprintf(): STEP_LABEL. */
    public const MOVE_AFTER = 'After %s';

    public const MOVE_TO_END = 'To the end';

    public const REMOVE = 'Remove';

    /** sprintf(): STEP_REFERENCE. */
    public const REMOVE_LABEL = 'Remove %s';

    /** sprintf(): STEP_REFERENCE. */
    public const REMOVE_CONFIRM = 'Remove %s?';

    /** A Move to… warning; sprintf(): the new start step's name, the old one's. */
    public const WILL_START = 'Runs will start with "%1$s" instead of "%2$s".';

    /** A Move to… warning; sprintf(): the job step's name, the approval step's. */
    public const WILL_PRECEDE_APPROVAL = 'Job step "%1$s" will run before the approval step "%2$s", without waiting for it.';

    /** A Move to… warning; sprintf(): the name of an approval step no success would lead to any more. */
    public const WILL_SKIP_APPROVAL = 'The approval step "%s" will not be on the success path: '
        . 'runs whose steps all succeed will not wait for it.';

    /** A step of the route map without its model (a caller error); sprintf(): its ID. */
    private const MODEL_MISSING = 'The steps table has no model of step ID %d.';

    /** @var array<int, WorkflowStep> step ID => model */
    private readonly array $models;

    private readonly WorkflowStepRouteCells $cells;

    /**
     * @param WorkflowRouteMap $map the workflow's steps (WorkflowRouteMap::fromModels($models))
     * @param list<WorkflowStep> $models the steps, with their job templates and approval rules
     *     loaded (WorkflowRouteMap::stepsOf()), for the Target cells
     * @param bool $withActions whether the rows carry the step actions
     * @param array<int, int> $waitingSteps step ID => how many active runs wait at it
     * @throws \InvalidArgumentException when a step of $map has no model in $models
     */
    public function __construct(
        private readonly WorkflowRouteMap $map,
        array $models,
        private readonly bool $withActions,
        private readonly array $waitingSteps,
    ) {
        $byId = [];
        foreach ($models as $model) {
            $byId[(int)$model->id] = $model;
        }
        foreach ($map->steps() as $step) {
            if (!isset($byId[$step->id])) {
                throw new \InvalidArgumentException(sprintf(self::MODEL_MISSING, $step->id));
            }
        }
        $this->models = $byId;
        $this->cells = new WorkflowStepRouteCells($map);
    }

    /**
     * Whether the rows carry the step actions.
     */
    public function hasActions(): bool
    {
        return $this->withActions;
    }

    /**
     * The rows, in run order.
     *
     * @return list<Row>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->map->steps() as $index => $step) {
            $rows[] = $this->row($step, $index + 1);
        }

        return $rows;
    }

    /**
     * The steps a run passes when every step succeeds, as one line ("1.
     * Build → 2. Approve (approval step) → end · run succeeds"), and whether
     * it loops (then the page explains SUCCESS_PATH_LOOP_NOTE); null without
     * steps.
     *
     * @return array{text: string, loops: bool}|null
     */
    public function successPath(): ?array
    {
        $path = $this->map->successPath();
        if ($path['end'] === null) {
            return null;
        }
        $labels = array_map(fn (int $stepId): string => $this->choiceLabel($stepId), $path['step_ids']);
        if ($path['end'] === WorkflowRouteMap::PATH_CANNOT_RUN) {
            $labels[] = sprintf(self::SUCCESS_PATH_CANNOT_RUN, (string)array_pop($labels));
        }
        $ending = match ($path['end']) {
            WorkflowRouteMap::PATH_SUCCEEDS => [self::ROUTE_END_SUCCEEDS],
            WorkflowRouteMap::PATH_LOOP => [sprintf(self::ROUTE_LOOP, $this->choiceLabel((int)$path['loop_to_step_id']))],
            WorkflowRouteMap::PATH_MISSING => [sprintf(self::ROUTE_MISSING, (int)$path['missing_step_id'])],
            default => [],
        };

        return [
            'text' => implode(self::SUCCESS_PATH_SEPARATOR, array_merge($labels, $ending)),
            'loops' => $path['end'] === WorkflowRouteMap::PATH_LOOP,
        ];
    }

    /**
     * "3. Deploy": the step at $position named $name.
     */
    public static function stepLabel(int $position, string $name): string
    {
        return sprintf(self::STEP_LABEL, $position, $name);
    }

    /**
     * 'step 3 "Deploy"': the step at $position named $name, in a sentence.
     */
    public static function stepReference(int $position, string $name): string
    {
        return sprintf(self::STEP_REFERENCE, $position, $name);
    }

    /**
     * The places that move $step, a step of $map, elsewhere, in this order
     * (the web placements of WorkflowStepPosition::fromWeb()): the start,
     * after each other step, the end; placement => the step it goes after
     * (null for the start and the end). Left out are the places that leave
     * it where it is (the start for the first step, after the step right
     * above it, the end for the last step) and after the last step, which is
     * the end.
     *
     * @return array<string, WorkflowRouteStep|null>
     */
    public static function movesOf(WorkflowRouteMap $map, WorkflowRouteStep $step): array
    {
        $steps = $map->steps();
        $count = count($steps);
        $position = (int)$map->position($step->id);
        $moves = $position > 1 ? [WorkflowStepPosition::KIND_START => null] : [];
        foreach ($steps as $index => $other) {
            $otherPosition = $index + 1;
            if ($other->id !== $step->id && $otherPosition !== $position - 1 && $otherPosition !== $count) {
                $moves[WorkflowStepPosition::WEB_AFTER_PREFIX . $other->id] = $other;
            }
        }
        if ($position < $count) {
            $moves[WorkflowStepPosition::KIND_END] = null;
        }

        return $moves;
    }

    /**
     * The label of the step with ID $stepId of $map where a step is chosen
     * or listed: its STEP_LABEL, with APPROVAL_STEP_SUFFIX for an approval
     * step. '' when $map has no such step.
     */
    public static function choiceLabelIn(WorkflowRouteMap $map, int $stepId): string
    {
        $step = $map->find($stepId);
        if ($step === null) {
            return '';
        }
        $suffix = $step->type === WorkflowStep::TYPE_APPROVAL ? self::APPROVAL_STEP_SUFFIX : '';

        return self::stepLabel((int)$map->position($stepId), $step->name) . $suffix;
    }

    /**
     * @return Row
     */
    private function row(WorkflowRouteStep $step, int $position): array
    {
        $count = count($this->map->steps());
        $waiting = $this->waitingSteps[$step->id] ?? 0;
        $target = WorkflowStepTargetCell::of($step, $this->models[$step->id]);

        return [
            'id' => $step->id,
            'position' => $position,
            'name' => $step->name,
            'type' => $step->type,
            'typeLabel' => WorkflowStep::typeLabels()[$step->type] ?? $step->type,
            'isStart' => $position === 1,
            'isFirst' => $position === 1,
            'isLast' => $position === $count,
            'reachable' => $this->map->isReachable($step->id),
            'gatedTitle' => self::gatedTitle($step, $target),
            'waiting' => $waiting > 0 ? sprintf(Texts::STEP_WAITING_MARKER, $waiting) : null,
            'target' => $target,
            'routes' => $this->cells->of($step),
            'actions' => $this->withActions ? $this->actions($step, $position) : null,
        ];
    }

    /**
     * The title of a gated job step's badge: the problem its Target cell
     * names, when its rule no longer exists or its job template and
     * inventory have different rules; else the rule's name, as the rule of
     * its inventory (GATED_INVENTORY_TITLE) or of its job template
     * (GATED_TITLE). Null for any other step.
     *
     * @param Target $target
     */
    private static function gatedTitle(WorkflowRouteStep $step, array $target): ?string
    {
        if ($step->gatedRuleName === null) {
            return null;
        }
        if ($target['problem'] !== null) {
            return $target['problem'];
        }

        return sprintf(
            $step->gatedBy === JobTemplateGate::SOURCE_INVENTORY ? self::GATED_INVENTORY_TITLE : self::GATED_TITLE,
            $step->gatedRuleName
        );
    }

    /**
     * The actions of $step: Move to… links to its edit page
     * (moveToEditPage, no targets) when the table has more than
     * MOVE_MENU_LIMIT steps.
     *
     * @return Actions
     */
    private function actions(WorkflowRouteStep $step, int $position): array
    {
        $reference = self::stepReference($position, $step->name);
        $toEditPage = count($this->map->steps()) > self::MOVE_MENU_LIMIT;

        return [
            'edit' => sprintf(self::EDIT_LABEL, $reference),
            'insertBelow' => sprintf(self::INSERT_BELOW_LABEL, $reference),
            'moveUp' => sprintf(self::MOVE_UP_LABEL, $reference),
            'moveDown' => sprintf(self::MOVE_DOWN_LABEL, $reference),
            'moveTo' => sprintf(self::MOVE_TO_LABEL, $reference),
            'remove' => sprintf(self::REMOVE_LABEL, $reference),
            'confirm' => sprintf(self::REMOVE_CONFIRM, $reference),
            'moveToEditPage' => $toEditPage,
            'moveTargets' => $toEditPage ? [] : $this->moveTargets($step),
        ];
    }

    /**
     * Where Move to… can put $step (movesOf()).
     *
     * @return list<MoveTarget>
     */
    private function moveTargets(WorkflowRouteStep $step): array
    {
        $targets = [];
        foreach (self::movesOf($this->map, $step) as $placement => $anchor) {
            $label = match ($placement) {
                WorkflowStepPosition::KIND_START => self::MOVE_TO_START,
                WorkflowStepPosition::KIND_END => self::MOVE_TO_END,
                default => sprintf(self::MOVE_AFTER, self::stepLabel((int)$this->map->position((int)$anchor?->id), (string)$anchor?->name)),
            };
            $targets[] = $this->moveTarget($step, $placement, $label);
        }

        return $targets;
    }

    /**
     * A Move to… target, with the warnings the move would cause.
     *
     * @return MoveTarget
     */
    private function moveTarget(WorkflowRouteStep $step, string $placement, string $label): array
    {
        $effects = $this->map->withPlacement($step, $placement)->effectsSince($this->map, null, $step->id);
        $hints = [];
        if ($effects->startChanged()) {
            $hints[] = sprintf(self::WILL_START, $this->name($effects->startAfter), $this->name($effects->startBefore));
        }
        foreach ($effects->unguardedJobStepIds as $jobStepId) {
            $hints[] = sprintf(self::WILL_PRECEDE_APPROVAL, $this->name($jobStepId), $this->name($effects->firstApprovalAfter));
        }
        foreach ($effects->skippedApprovalStepIds as $approvalStepId) {
            $hints[] = sprintf(self::WILL_SKIP_APPROVAL, $this->name($approvalStepId));
        }

        return ['value' => $placement, 'label' => $label, 'hints' => $hints];
    }

    /**
     * The name of the step with ID $stepId; '' for none.
     */
    private function name(?int $stepId): string
    {
        return $stepId === null ? '' : (string)$this->map->find($stepId)?->name;
    }

    private function choiceLabel(int $stepId): string
    {
        return self::choiceLabelIn($this->map, $stepId);
    }
}
