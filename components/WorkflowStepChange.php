<?php

declare(strict_types=1);

namespace app\components;

use app\models\WorkflowStep;

/**
 * What one change of a workflow's steps did, as the step write path reports
 * it: the kind of change, the step, where it stood before and stands after
 * (1-based positions in the run order), the workflow's steps before and after
 * the change in run order (read under the workflow's row lock), and, for an
 * update, the fields that changed. The change messages and the API's change
 * answer are built from it.
 */
final class WorkflowStepChange
{
    /** A step was added: from is 0. */
    public const ADDED = 'added';

    /** A step was saved with other values; when it moved too, changes holds position. */
    public const UPDATED = 'updated';

    /** A step was moved to another position. */
    public const MOVED = 'moved';

    /** A step was removed: to is 0, and step is the removed model. */
    public const REMOVED = 'removed';

    /** An update or move that would change nothing: nothing was written. */
    public const UNCHANGED = 'unchanged';

    /**
     * @param 'added'|'updated'|'moved'|'removed'|'unchanged' $kind
     * @param WorkflowStep $step the step after the change; for REMOVED, the removed model
     * @param int $from the step's position before the change; 0 when it was added
     * @param int $to the step's position after the change; 0 when it was removed
     * @param list<WorkflowStep> $stepsBefore the workflow's steps before the change, in run order
     * @param list<WorkflowStep> $stepsAfter the workflow's steps after the change, in run order
     * @param array<string, array{from: mixed, to: mixed}> $changes for UPDATED only, by field:
     *     its value before and after the change, with position when the step moved; empty for
     *     the other kinds
     * @param string $source where the change came from: 'web' or 'api'
     */
    public function __construct(
        public readonly string $kind,
        public readonly WorkflowStep $step,
        public readonly int $from,
        public readonly int $to,
        public readonly array $stepsBefore,
        public readonly array $stepsAfter,
        public readonly array $changes,
        public readonly string $source,
    ) {
    }

    /**
     * How many steps the workflow has after the change.
     */
    public function count(): int
    {
        return count($this->stepsAfter);
    }

    /**
     * Whether the step stands at another position than before: a move, or an
     * update that moved it. An added step was placed and a removed one taken
     * out; neither of them moved, and neither did an unchanged one.
     */
    public function moved(): bool
    {
        return ($this->kind === self::MOVED || $this->kind === self::UPDATED) && $this->from !== $this->to;
    }
}
