<?php

declare(strict_types=1);

namespace app\services;

use app\components\SubmittedId;
use app\components\WorkflowStepCommand;
use app\components\WorkflowStepsInput;
use app\models\WorkflowStep;

/**
 * A request applied to a workflow step, and the checks of the step it makes,
 * for the step write path (WorkflowStepWriteService):
 * - apply(): the fields the command sets, with only the target of the step's
 *   type (a pause step never gets a job template, which would restrict who
 *   may see the workflow), and the IDs read as the model's integer rule
 *   reads them (SubmittedId: "05" and "+5" are 5), so that the checks and
 *   the save use one value;
 * - typedFor(): an edit keeps the step's type, and a pause step its unused
 *   routes;
 * - assertJobTemplateUsable(): the job template exists for the user, and
 *   they may operate it;
 * - assertValid() and assertSaved(): the model's rules.
 * Each refusal is a WorkflowStepChangeRefusedException.
 */
final class WorkflowStepReferences
{
    /** The target of each step type that has one. */
    private const TARGETS = [
        WorkflowStep::TYPE_JOB => 'job_template_id',
        WorkflowStep::TYPE_APPROVAL => 'approval_rule_id',
    ];

    public function __construct(private readonly WorkflowAccessChecker $workflows)
    {
    }

    /**
     * Set what $command sets on $step, except a target its type does not
     * have (the step's type, or the type the command sets on a new step),
     * then read the job template and the approval rule as the step stores
     * them (SubmittedId::normalize()). A value that is no ID is left as it
     * is, for the model's rules to refuse.
     */
    public static function apply(WorkflowStep $step, WorkflowStepCommand $command): void
    {
        $type = $command->has('step_type') ? $command->value('step_type') : $step->step_type;
        $target = is_string($type) ? (self::TARGETS[$type] ?? null) : null;
        foreach ($command->fields() as $field => $value) {
            if ($field === $target || !in_array($field, self::TARGETS, true)) {
                $step->setAttribute($field, $value);
            }
        }
        SubmittedId::normalize($step, 'job_template_id');
        SubmittedId::normalize($step, 'approval_rule_id');
    }

    /**
     * $command for an edit of $stored: refused when it sends another step
     * type than $stored has (WorkflowStepCommand::TYPE_LOCKED, compared
     * strictly), then read for the stored type
     * (WorkflowStepCommand::forStepType(): a pause step's form ignores On
     * Failure and Always, the API refuses them).
     *
     * @throws WorkflowStepChangeRefusedException (422)
     */
    public static function typedFor(WorkflowStep $stored, WorkflowStepCommand $command): WorkflowStepCommand
    {
        if ($command->has('step_type') && $command->value('step_type') !== $stored->step_type) {
            throw self::refused(WorkflowStepCommand::TYPE_LOCKED);
        }
        $typed = $command->forStepType($stored->step_type);
        if (is_string($typed)) {
            throw self::refused($typed);
        }

        return $typed;
    }

    /**
     * $step's model rules: refused with the first error.
     *
     * @throws WorkflowStepChangeRefusedException (422)
     */
    public static function assertValid(WorkflowStep $step): void
    {
        if (!$step->validate()) {
            throw self::refused(WorkflowStepsInput::firstError($step));
        }
    }

    /**
     * Save $step, validated again: a step it routes to may have been removed
     * since it was checked. Refused with the first error, or, when an event
     * vetoes the save without one, with "Validation failed.".
     *
     * @throws WorkflowStepChangeRefusedException (422)
     */
    public static function assertSaved(WorkflowStep $step): void
    {
        if (!$step->save()) {
            throw self::refused(WorkflowStepsInput::firstError($step));
        }
    }

    /**
     * The job template of $step, when it has one as an ID, must exist for
     * $userId: 422 (WorkflowStep::JOB_TEMPLATE_NOT_FOUND) when it is
     * unknown, deleted or in a project they may not see, so the answer does
     * not tell whether such a template exists; 403
     * (WorkflowStepWriteService::JOB_TEMPLATE_FORBIDDEN) when they may see
     * but not operate it. Both name it in job_template_ids.
     *
     * @throws WorkflowStepChangeRefusedException (422, 403)
     */
    public function assertJobTemplateUsable(int $userId, WorkflowStep $step): void
    {
        $id = $step->job_template_id;
        if (!is_int($id)) {
            return;
        }
        $found = $this->workflows->classifyStepTemplateIds($userId, [$id]);
        if ($found['missing'] !== []) {
            throw new WorkflowStepChangeRefusedException(
                WorkflowStep::JOB_TEMPLATE_NOT_FOUND,
                WorkflowStepChangeRefusedException::INVALID,
                ['job_template_ids' => $found['missing']]
            );
        }
        if ($found['forbidden'] !== []) {
            throw new WorkflowStepChangeRefusedException(
                WorkflowStepWriteService::JOB_TEMPLATE_FORBIDDEN,
                WorkflowStepChangeRefusedException::FORBIDDEN,
                ['job_template_ids' => $found['forbidden']]
            );
        }
    }

    private static function refused(string $message): WorkflowStepChangeRefusedException
    {
        return new WorkflowStepChangeRefusedException($message, WorkflowStepChangeRefusedException::INVALID);
    }
}
