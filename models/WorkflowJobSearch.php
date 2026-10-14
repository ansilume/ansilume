<?php

declare(strict_types=1);

namespace app\models;

use app\services\runstate\Display;
use app\services\runstate\WorkflowRunStates;
use yii\base\Model;
use yii\db\ActiveQuery;

/**
 * The filters of the workflow run lists: ?waiting_for=, ?workflow_template_id=
 * and ?status= on the web list (/workflow-job/index) and on the API
 * (GET /api/v1/workflow-jobs).
 *
 * - waiting_for: what the run waits for at its current step, one of
 *   Display::waitingForValues() (approval, resume, runner), as
 *   WorkflowRunStates derives it; only running runs wait.
 * - workflow_template_id: the runs of one workflow template, a whole number
 *   from 1 (leading zeros allowed).
 * - status: the stored status, one of WorkflowJob::statuses().
 * Values are compared exactly (lower case, as stored). A missing or empty
 * filter does not filter. Load the query parameters with load($params, '').
 * The web list ignores an invalid value, so an old or mistyped link still
 * opens: apply() filters only by valid values. The API calls validate()
 * first and answers 422 with the first error (INVALID_FILTER,
 * INVALID_WORKFLOW_TEMPLATE_ID), so a typo in a script never passes for
 * "no filter".
 */
class WorkflowJobSearch extends Model
{
    /** sprintf(): the filter's name, its valid values joined with ', ' (filterError()). */
    public const INVALID_FILTER = '%s must be one of: %s.';

    public const INVALID_WORKFLOW_TEMPLATE_ID = 'workflow_template_id must be a positive integer.';

    /** What the runs wait for, as sent (?waiting_for=); null or '' does not filter. */
    public mixed $waiting_for = null;

    /** The workflow template of the runs, as sent (?workflow_template_id=); null or '' does not filter. */
    public mixed $workflow_template_id = null;

    /** The stored status of the runs, as sent (?status=); null or '' does not filter. */
    public mixed $status = null;

    /**
     * @return array<int, array<int|string, mixed>>
     */
    public function rules(): array
    {
        return [
            [['waiting_for', 'workflow_template_id', 'status'], 'validateFilter'],
        ];
    }

    /**
     * Inline validator: the filter is valid when its active*() value is set.
     * Empty values are not validated.
     */
    public function validateFilter(string $attribute): void
    {
        $valid = match ($attribute) {
            'waiting_for' => $this->activeWaitingFor() !== null,
            'workflow_template_id' => $this->activeWorkflowTemplateId() !== null,
            default => $this->activeStatus() !== null,
        };
        if (!$valid) {
            $this->addError($attribute, self::errorOf($attribute));
        }
    }

    /**
     * The wait the list is filtered by: the requested one when it is
     * valid, else null.
     */
    public function activeWaitingFor(): ?string
    {
        return self::oneOf($this->waiting_for, Display::waitingForValues());
    }

    /**
     * The workflow template the list is filtered by: the requested ID when
     * it is a whole number from 1 (written in digits, leading zeros
     * allowed), else null.
     */
    public function activeWorkflowTemplateId(): ?int
    {
        $value = $this->workflow_template_id;
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/', $value) !== 1) {
            return null;
        }
        $id = filter_var(ltrim($value, '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($id) ? $id : null;
    }

    /**
     * The stored status the list is filtered by: the requested one when it
     * is valid, else null.
     */
    public function activeStatus(): ?string
    {
        return self::oneOf($this->status, WorkflowJob::statuses());
    }

    /**
     * $query (of WorkflowJob::find(), without a table alias), filtered by
     * every valid filter; a missing, empty or invalid one does not filter.
     *
     * @param ActiveQuery<WorkflowJob> $query
     * @return ActiveQuery<WorkflowJob>
     */
    public function apply(ActiveQuery $query): ActiveQuery
    {
        $waitingFor = $this->activeWaitingFor();
        if ($waitingFor !== null) {
            /** @var WorkflowRunStates $runStates */
            $runStates = \Yii::$app->get('workflowRunStates');
            $query->andWhere($runStates->waitingCondition($waitingFor));
        }
        $workflowTemplateId = $this->activeWorkflowTemplateId();
        if ($workflowTemplateId !== null) {
            $query->andWhere(['workflow_job.workflow_template_id' => $workflowTemplateId]);
        }
        $status = $this->activeStatus();
        if ($status !== null) {
            $query->andWhere(['workflow_job.status' => $status]);
        }

        return $query;
    }

    /**
     * The error of an invalid value of filter $attribute, e.g.
     * "waiting_for must be one of: approval, resume, runner."
     */
    public static function errorOf(string $attribute): string
    {
        return match ($attribute) {
            'waiting_for' => sprintf(self::INVALID_FILTER, 'waiting_for', implode(', ', Display::waitingForValues())),
            'workflow_template_id' => self::INVALID_WORKFLOW_TEMPLATE_ID,
            default => sprintf(self::INVALID_FILTER, 'status', implode(', ', WorkflowJob::statuses())),
        };
    }

    /**
     * @param array<string> $values
     */
    private static function oneOf(mixed $value, array $values): ?string
    {
        return is_string($value) && in_array($value, $values, true) ? $value : null;
    }
}
