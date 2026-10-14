<?php

declare(strict_types=1);

namespace app\components;

use app\models\JobTemplate;
use app\models\WorkflowStep;

/**
 * One step of a workflow as the route map (app\services\WorkflowRouteMap)
 * reads it: its ID, name and raw type, its three branches as stored, whether
 * its job waits for an approval, and why a run that reaches it fails there
 * without following a branch.
 *
 * A branch is null (the next step in the run order), 0
 * (WorkflowStep::END_WORKFLOW, the end of the run) or a step ID, which may
 * be the step itself or an earlier step (a loop).
 *
 * The type is kept as stored and compared exactly, as dispatch compares it
 * (WorkflowStepDispatcher): "JOB" is no job step but an unknown type.
 */
final class WorkflowRouteStep
{
    /** The ID of a step that is not saved yet, such as the new step of an add preview. */
    public const NEW_STEP_ID = -1;

    /** A job step without a job template, or whose job template was deleted. */
    public const PROBLEM_JOB_TEMPLATE_MISSING = 'job_template_missing';

    /**
     * An approval step without its approval rule, or a job step whose job
     * template names an approval rule that no longer exists (its launch is
     * refused).
     */
    public const PROBLEM_APPROVAL_RULE_MISSING = 'approval_rule_missing';

    /** A type other than job, approval and pause, compared exactly. */
    public const PROBLEM_UNKNOWN_TYPE = 'unknown_type';

    /** The relations of a step model that fromModel() reads. */
    public const RELATIONS = ['jobTemplate.approvalRule', 'approvalRule'];

    /**
     * @param int $id the step's ID; NEW_STEP_ID for a step that is not saved yet
     * @param string $type the type as stored: job, approval, pause, or any other text (an unknown type)
     * @param int|null $onSuccess the On Success branch: null, 0 or a step ID
     * @param int|null $onFailure the On Failure branch: null, 0 or a step ID
     * @param int|null $onAlways the Always branch: null, 0 or a step ID
     * @param string|null $gatedRuleName for a job step whose (not deleted) job template has an
     *     approval rule: the rule's name, '' when that rule no longer exists; null otherwise
     * @param string|null $problem why a run that reaches the step fails there: one of the
     *     PROBLEM_* values, or null when it can run
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $onSuccess,
        public readonly ?int $onFailure,
        public readonly ?int $onAlways,
        public readonly ?string $gatedRuleName = null,
        public readonly ?string $problem = null,
    ) {
    }

    /**
     * The step $step as stored. It reads the step's job template with that
     * template's approval rule, and its approval rule (RELATIONS): load them
     * with with(RELATIONS) when reading many steps, or use fromModels(). A
     * step that is not saved yet gets NEW_STEP_ID.
     */
    public static function fromModel(WorkflowStep $step): self
    {
        $type = (string)$step->step_type;
        [$gatedRuleName, $problem] = self::targetOf($step, $type);

        return new self(
            $step->id === null ? self::NEW_STEP_ID : (int)$step->id,
            (string)$step->name,
            $type,
            self::branch($step->on_success_step_id),
            self::branch($step->on_failure_step_id),
            self::branch($step->on_always_step_id),
            $gatedRuleName,
            $problem,
        );
    }

    /**
     * The steps $models, in their order (fromModel()). What fromModel()
     * reads is loaded in one batch for the models that do not have it loaded
     * yet, so the cost does not grow with the number of steps; models loaded
     * with with(RELATIONS) cost no query.
     *
     * @param list<WorkflowStep> $models
     * @return list<self>
     */
    public static function fromModels(array $models): array
    {
        $models = array_values($models);
        $missing = array_values(array_filter(
            $models,
            static fn (WorkflowStep $model): bool => !self::relationsLoaded($model)
        ));
        if ($missing !== []) {
            WorkflowStep::find()->findWith(self::RELATIONS, $missing);
        }

        return array_map(static fn (WorkflowStep $model): self => self::fromModel($model), $models);
    }

    /**
     * Whether $model has every relation of RELATIONS loaded.
     */
    private static function relationsLoaded(WorkflowStep $model): bool
    {
        if (!$model->isRelationPopulated('jobTemplate') || !$model->isRelationPopulated('approvalRule')) {
            return false;
        }
        $template = $model->jobTemplate;

        return $template === null || $template->isRelationPopulated('approvalRule');
    }

    /**
     * The gated rule name and the problem of $step, by its exact type.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function targetOf(WorkflowStep $step, string $type): array
    {
        $ruleMissing = $type === WorkflowStep::TYPE_APPROVAL && $step->approvalRule === null;

        return match ($type) {
            WorkflowStep::TYPE_JOB => self::jobTargetOf($step->jobTemplate),
            WorkflowStep::TYPE_APPROVAL => [null, $ruleMissing ? self::PROBLEM_APPROVAL_RULE_MISSING : null],
            WorkflowStep::TYPE_PAUSE => [null, null],
            default => [null, self::PROBLEM_UNKNOWN_TYPE],
        };
    }

    /**
     * A job step's gated rule name and problem, from its job template (null
     * when it has none or it was deleted: the relation leaves deleted job
     * templates out, and such a step runs nothing).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function jobTargetOf(?JobTemplate $template): array
    {
        if ($template === null) {
            return [null, self::PROBLEM_JOB_TEMPLATE_MISSING];
        }
        if ($template->approval_rule_id === null) {
            return [null, null];
        }
        $rule = $template->approvalRule;

        return $rule === null ? ['', self::PROBLEM_APPROVAL_RULE_MISSING] : [(string)$rule->name, null];
    }

    /**
     * A stored branch: null, or its value as an int.
     */
    private static function branch(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int)$value;
    }
}
