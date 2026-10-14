<?php

declare(strict_types=1);

namespace app\services;

use app\components\WorkflowRoute;
use app\components\WorkflowRouteStep;
use app\components\WorkflowStepChange;

/**
 * A change of a workflow's steps in words, for the success flash of the web
 * and the change answer of the API ({message, warnings}).
 *
 * The message says where the step landed (or which fields an edit changed),
 * then, separated by spaces, how the empty branches of the other steps now
 * lead elsewhere: an empty branch goes on with the next step in the list, so
 * placing, moving or removing a step changes where it leads. Explicit
 * branches never move, so they are not mentioned.
 *
 * The warnings (one warning flash each) say what runs now do differently,
 * from the branches as the engine follows them
 * (WorkflowRouteMap::effectsSince()): runs start with another step; runs
 * reach a job step without passing the approval step they passed before,
 * so that they start it without waiting for an approval; an approval step
 * is not on the success path, so that runs whose steps all succeed do not
 * wait for it.
 *
 * Names are quoted plainly; the layout and the API's JSON encode them.
 */
final class WorkflowStepChangeSummary
{
    /** A step was added: its name, position, the number of steps, then AT_START or AFTER ('' for the only step). */
    public const ADDED = 'Step "%1$s" added at position %2$d of %3$d%4$s.';

    /** A step was moved: its name, position, the number of steps, then AT_START or AFTER. */
    public const MOVED = 'Step "%1$s" moved to position %2$d of %3$d%4$s.';

    /** A step was saved in place: its name and the changed fields (FIELD_LABELS). */
    public const SAVED = 'Step "%1$s" saved: %2$s changed.';

    /** A step was saved and moved: its name, the changed fields, position, the number of steps, then AT_START or AFTER. */
    public const SAVED_MOVED = 'Step "%1$s" saved: %2$s changed; it is now at position %3$d of %4$d%5$s.';

    /** A step was removed: its name, its position and the number of steps before. */
    public const REMOVED = 'Step "%1$s" removed; it was at position %2$d of %3$d.';

    /** An edit that would change nothing: the step's name. */
    public const UNCHANGED = 'Step "%s" is unchanged.';

    /** A move that would leave the step where it is: its name and position. */
    public const STAYS = 'Step "%1$s" stays at position %2$d.';

    /** The step is at position 1 of several. */
    public const AT_START = ', at the start';

    /** The step comes after another one: that step's name. */
    public const AFTER = ', after "%s"';

    /** The fields an edit can change (WorkflowStepWriteService::TRACKED_FIELDS), as SAVED lists them. */
    public const FIELD_LABELS = [
        'name' => 'name',
        'job_template_id' => 'job template',
        'approval_rule_id' => 'approval rule',
        'on_success_step_id' => 'On Success',
        'on_failure_step_id' => 'On Failure',
        'on_always_step_id' => 'Always',
        'extra_vars_template' => 'variable mapping',
    ];

    /** Joins the changed fields. */
    public const FIELD_SEPARATOR = ', ';

    /**
     * An empty branch of a step now leads to another step: the step's name,
     * the step it leads to, ALSO_AFTER_FAILURE or AFTER_FAILURE or '', and
     * where it led (WAS_STEP or WAS_END).
     */
    public const NOW_CONTINUES = '"%1$s" now continues with "%2$s"%3$s (was %4$s).';

    /**
     * An empty branch of a step now ends the run (the step is last): the
     * step's name, AFTER_SUCCESS or AFTER_FAILURE or '', and the step it led
     * to.
     */
    public const NOW_ENDS = '"%1$s" now ends the run%2$s (was "%3$s").';

    /** Both empty branches of a job step changed. */
    public const ALSO_AFTER_FAILURE = ', also after a failure';

    /** Only the On Failure branch changed. */
    public const AFTER_FAILURE = ' after a failure';

    /** Only the On Success branch of a step that can fail changed. */
    public const AFTER_SUCCESS = ' after a success';

    /** Where an empty branch led: a step's name. */
    public const WAS_STEP = '"%s"';

    /** Where an empty branch led: the end of the run. */
    public const WAS_END = 'the end of the run';

    /** Runs start with another step now: its name, then the name of the step they started with. */
    public const START_CHANGED = 'Runs now start with "%1$s" (was "%2$s").';

    /** A job step no longer comes after the first approval step: its name and that approval step's name. */
    public const JOB_BEFORE_APPROVAL = 'Job step "%1$s" now runs before the approval step "%2$s": '
        . 'runs start it without waiting for that approval.';

    /** A job step no longer comes after an approval step, and the workflow has none left: its name. */
    public const JOB_WITHOUT_APPROVAL = 'Job step "%s" no longer comes after an approval step: '
        . 'runs start it without waiting for an approval.';

    /**
     * An approval step that is not on the success path, though it was
     * before or the change placed or edited it: its name.
     */
    public const APPROVAL_OFF_SUCCESS_PATH = 'The approval step "%s" is not on the success path: '
        . 'runs whose steps all succeed do not wait for it.';

    /**
     * @param list<string> $warnings
     */
    private function __construct(
        private readonly string $message,
        private readonly array $warnings,
        private readonly bool $info,
    ) {
    }

    /**
     * The summary of $change. A change that wrote nothing gets UNCHANGED (or
     * STAYS for a move that kept its place) and no warnings. An update that
     * only moved the step reads as a move.
     */
    public static function of(WorkflowStepChange $change): self
    {
        $name = (string)$change->step->name;
        $fields = self::changedFields($change);
        if (self::changedNothing($change, $fields)) {
            return new self(
                $change->kind === WorkflowStepChange::MOVED
                    ? sprintf(self::STAYS, $name, $change->to)
                    : sprintf(self::UNCHANGED, $name),
                [],
                true
            );
        }
        $before = WorkflowRouteMap::fromModels($change->stepsBefore);
        $after = WorkflowRouteMap::fromModels($change->stepsAfter);
        $sentences = [self::placement($change, $name, $fields, $after), ...self::routeChanges($before, $after)];

        return new self(implode(' ', $sentences), self::warningsOf($change, $before, $after), false);
    }

    /**
     * Where the step landed, then how empty branches now lead elsewhere.
     */
    public function message(): string
    {
        return $this->message;
    }

    /**
     * START_CHANGED, then JOB_BEFORE_APPROVAL or JOB_WITHOUT_APPROVAL per job
     * step and APPROVAL_OFF_SUCCESS_PATH per approval step, each by
     * position; empty when there is nothing to warn about.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Whether the change wrote nothing (UNCHANGED, or a move that kept its
     * place): the web shows the message as an info flash.
     */
    public function isInfo(): bool
    {
        return $this->info;
    }

    /**
     * The summary as the API's write answers carry it (change).
     *
     * @return array{message: string, warnings: list<string>}
     */
    public function toArray(): array
    {
        return ['message' => $this->message, 'warnings' => $this->warnings];
    }

    /**
     * The labels of the fields an update changed, position left out (the
     * message says where the step is), in the order of FIELD_LABELS; a field
     * without a label is listed by its name.
     *
     * @return list<string>
     */
    private static function changedFields(WorkflowStepChange $change): array
    {
        if ($change->kind !== WorkflowStepChange::UPDATED) {
            return [];
        }
        $changed = array_diff_key($change->changes, ['position' => true]);

        return array_merge(
            array_values(array_intersect_key(self::FIELD_LABELS, $changed)),
            array_map('strval', array_keys(array_diff_key($changed, self::FIELD_LABELS)))
        );
    }

    /**
     * @param list<string> $fields
     */
    private static function changedNothing(WorkflowStepChange $change, array $fields): bool
    {
        return match ($change->kind) {
            WorkflowStepChange::UNCHANGED => true,
            WorkflowStepChange::MOVED => !$change->moved(),
            WorkflowStepChange::UPDATED => $fields === [] && !$change->moved(),
            default => false,
        };
    }

    /**
     * The first sentence: where the step landed, or which fields changed.
     *
     * @param list<string> $fields
     */
    private static function placement(WorkflowStepChange $change, string $name, array $fields, WorkflowRouteMap $after): string
    {
        if ($change->kind === WorkflowStepChange::REMOVED) {
            return sprintf(self::REMOVED, $name, $change->from, count($change->stepsBefore));
        }
        $where = self::where($change, $after);
        if ($change->kind === WorkflowStepChange::ADDED) {
            return sprintf(self::ADDED, $name, $change->to, $change->count(), $where);
        }
        if ($fields === []) {
            return sprintf(self::MOVED, $name, $change->to, $change->count(), $where);
        }
        $list = implode(self::FIELD_SEPARATOR, $fields);

        return $change->moved()
            ? sprintf(self::SAVED_MOVED, $name, $list, $change->to, $change->count(), $where)
            : sprintf(self::SAVED, $name, $list);
    }

    /**
     * AT_START, AFTER with the step before it, or '' for the only step.
     */
    private static function where(WorkflowStepChange $change, WorkflowRouteMap $after): string
    {
        if ($change->count() < 2) {
            return '';
        }
        if ($change->to <= 1) {
            return self::AT_START;
        }
        $previous = $after->steps()[$change->to - 2] ?? null;

        return $previous === null ? '' : sprintf(self::AFTER, $previous->name);
    }

    /**
     * A sentence for each step, by position, whose empty On Success or On
     * Failure branch now leads elsewhere.
     *
     * @return list<string>
     */
    private static function routeChanges(WorkflowRouteMap $before, WorkflowRouteMap $after): array
    {
        $sentences = [];
        foreach ($after->steps() as $step) {
            $old = $before->find($step->id);
            $sentence = $old === null ? null : self::routeChange($before, $after, $old, $step);
            if ($sentence !== null) {
                $sentences[] = $sentence;
            }
        }

        return $sentences;
    }

    private static function routeChange(
        WorkflowRouteMap $before,
        WorkflowRouteMap $after,
        WorkflowRouteStep $old,
        WorkflowRouteStep $now
    ): ?string {
        $success = self::fallThrough(
            $before->route($old, WorkflowRouteMap::OUTCOME_SUCCESS),
            $after->route($now, WorkflowRouteMap::OUTCOME_SUCCESS)
        );
        $failure = self::fallThrough(
            $before->route($old, WorkflowRouteMap::OUTCOME_FAILURE),
            $after->route($now, WorkflowRouteMap::OUTCOME_FAILURE)
        );
        $changed = $success ?? $failure;
        if ($changed === null) {
            return null;
        }
        [$was, $leads] = $changed;
        $continues = $leads->kind === WorkflowRoute::KIND_STEP;
        $canFail = in_array(WorkflowRouteMap::OUTCOME_FAILURE, $after->outcomes($now), true);
        $suffix = self::suffix($success !== null, $failure !== null, $continues, $canFail);

        if (!$continues) {
            return sprintf(self::NOW_ENDS, $now->name, $suffix, self::nameOf($before, $after, $was->stepId));
        }
        $target = self::nameOf($before, $after, $leads->stepId);

        return sprintf(self::NOW_CONTINUES, $now->name, $target, $suffix, self::was($before, $after, $was));
    }

    /**
     * The routes before and after, when both are empty branches that go on
     * with the next step and now lead elsewhere; null otherwise.
     *
     * @return array{0: WorkflowRoute, 1: WorkflowRoute}|null
     */
    private static function fallThrough(?WorkflowRoute $was, ?WorkflowRoute $now): ?array
    {
        if ($was === null || $now === null) {
            return null;
        }
        $bothNext = $was->via === WorkflowRoute::VIA_NEXT && $now->via === WorkflowRoute::VIA_NEXT;

        return $bothNext && !$was->sameTarget($now) ? [$was, $now] : null;
    }

    /**
     * Which branches a route sentence is about: both (ALSO_AFTER_FAILURE when
     * the step continues, nothing when it ends the run), only On Failure
     * (AFTER_FAILURE), or only On Success (AFTER_SUCCESS when the step ends
     * the run and could fail; nothing otherwise).
     */
    private static function suffix(bool $success, bool $failure, bool $continues, bool $canFail): string
    {
        if (!$success) {
            return self::AFTER_FAILURE;
        }
        if ($failure) {
            return $continues ? self::ALSO_AFTER_FAILURE : '';
        }

        return !$continues && $canFail ? self::AFTER_SUCCESS : '';
    }

    private static function was(WorkflowRouteMap $before, WorkflowRouteMap $after, WorkflowRoute $was): string
    {
        return $was->kind === WorkflowRoute::KIND_STEP
            ? sprintf(self::WAS_STEP, self::nameOf($before, $after, $was->stepId))
            : self::WAS_END;
    }

    /**
     * START_CHANGED, the job steps that runs now reach without passing an
     * approval step, and the approval steps off the success path. The step
     * the change added, moved or edited counts as changed (a removed one is
     * in no map after the change).
     *
     * @return list<string>
     */
    private static function warningsOf(WorkflowStepChange $change, WorkflowRouteMap $before, WorkflowRouteMap $after): array
    {
        $stepId = (int)$change->step->id;
        $effects = $after->effectsSince($before, $change->kind === WorkflowStepChange::ADDED ? $stepId : null, $stepId);
        $warnings = [];
        if ($effects->startChanged()) {
            $warnings[] = sprintf(
                self::START_CHANGED,
                self::nameOf($before, $after, $effects->startAfter),
                self::nameOf($before, $after, $effects->startBefore)
            );
        }
        $approval = $effects->firstApprovalAfter;
        foreach ($effects->unguardedJobStepIds as $jobStepId) {
            $job = self::nameOf($before, $after, $jobStepId);
            $warnings[] = $approval === null
                ? sprintf(self::JOB_WITHOUT_APPROVAL, $job)
                : sprintf(self::JOB_BEFORE_APPROVAL, $job, self::nameOf($before, $after, $approval));
        }
        foreach ($effects->skippedApprovalStepIds as $approvalStepId) {
            $warnings[] = sprintf(self::APPROVAL_OFF_SUCCESS_PATH, self::nameOf($before, $after, $approvalStepId));
        }

        return $warnings;
    }

    /**
     * The name of the step with ID $stepId as it is called now, or as it was
     * called when it is gone: all names of a summary are the ones the steps
     * table shows, also when the change renamed a step.
     */
    private static function nameOf(WorkflowRouteMap $before, WorkflowRouteMap $after, ?int $stepId): string
    {
        return $after->find((int)$stepId)?->name ?? $before->find((int)$stepId)?->name ?? '';
    }
}
