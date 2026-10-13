<?php

declare(strict_types=1);

namespace app\components;

use app\models\WorkflowStep;
use app\services\JobLaunchSecrets;
use yii\base\Model;

/**
 * The "steps" list of a POST or PUT /api/v1/workflow-templates body, as
 * unsaved WorkflowStep models in request order, or why it is refused (the
 * API answers 422 with that message).
 *
 * Every step needs a name (max 128) and a step_type of job (default),
 * approval or pause; step_order is the step's index in the list. A job step
 * needs job_template_id, an approval step approval_rule_id. Only the target
 * of the step's type is read, so a pause step never carries a job template
 * that would restrict who may see the workflow. extra_vars_template is JSON
 * text or a JSON object. Routes (on_success, on_failure, on_always) are not
 * read: the API cannot set them.
 */
final class WorkflowStepsInput
{
    /**
     * The steps of the request as unsaved models in request order, or why
     * they are refused.
     *
     * @return list<WorkflowStep>|string
     */
    public static function read(mixed $raw): array|string
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return 'steps must be a list of steps.';
        }
        $steps = [];
        foreach ($raw as $index => $data) {
            $step = is_array($data) ? self::readStep($data, $index) : 'A step must be an object.';
            if (is_string($step)) {
                return sprintf('steps[%d]: %s', $index, $step);
            }
            $steps[] = $step;
        }

        return $steps;
    }

    /**
     * A request value as text; anything that is not a scalar becomes empty
     * and fails validation instead of raising a conversion error.
     */
    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * The first validation error of $model.
     */
    public static function firstError(Model $model): string
    {
        foreach ($model->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }

    /**
     * One step of the request as an unsaved model, or why it is refused.
     *
     * @param array<array-key, mixed> $data
     */
    private static function readStep(array $data, int $index): WorkflowStep|string
    {
        $step = new WorkflowStep();
        $step->name = self::text($data['name'] ?? '');
        $step->step_type = array_key_exists('step_type', $data) ? self::text($data['step_type']) : WorkflowStep::TYPE_JOB;
        $step->step_order = $index;
        $unencodable = self::readExtraVarsTemplate($step, $data['extra_vars_template'] ?? null);
        $step->validate(['name', 'step_type', 'step_order', 'extra_vars_template']);
        if ($unencodable !== null) {
            // After the errors of the other attributes, as for JSON text.
            $step->addError('extra_vars_template', $unencodable);
        }
        if ($step->hasErrors()) {
            return self::firstError($step);
        }

        return self::readTarget($step, $data);
    }

    /**
     * Read the target of the step's type: the job template of a job step,
     * the approval rule of an approval step. Other IDs are ignored, so a
     * pause step never carries a job template that would restrict who may
     * see the workflow.
     *
     * @param array<array-key, mixed> $data
     */
    private static function readTarget(WorkflowStep $step, array $data): WorkflowStep|string
    {
        if ($step->step_type === WorkflowStep::TYPE_JOB) {
            $step->job_template_id = self::toId($data['job_template_id'] ?? null);
            return $step->job_template_id !== null ? $step : 'A job step needs job_template_id, the ID of a job template.';
        }
        if ($step->step_type === WorkflowStep::TYPE_APPROVAL) {
            $step->approval_rule_id = self::toId($data['approval_rule_id'] ?? null);
            return $step->approval_rule_id !== null ? $step : 'An approval step needs approval_rule_id, the ID of an approval rule.';
        }

        return $step;
    }

    /**
     * Set the step's extra_vars_template from the request: JSON text as
     * stored, or a JSON object, encoded. Returns why an object cannot be
     * encoded, or null: 1e999 arrives as infinity, and a form-encoded body
     * can carry text that is not UTF-8. The message is the launch's, which
     * WorkflowStep::validateJson() gives such a number in JSON text too.
     */
    private static function readExtraVarsTemplate(WorkflowStep $step, mixed $value): ?string
    {
        if (!is_array($value)) {
            $step->extra_vars_template = $value === null ? null : self::text($value);
            return null;
        }
        $json = json_encode($value);
        if ($json === false) {
            return SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, json_last_error());
        }
        $step->extra_vars_template = $json;

        return null;
    }

    /**
     * A positive ID from the request: integers and digit strings only, as
     * filter_var() alone also takes true and 1.0 for 1.
     */
    private static function toId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
