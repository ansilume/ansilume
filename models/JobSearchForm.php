<?php

declare(strict_types=1);

namespace app\models;

use app\components\SubmittedInteger;
use app\services\RecoveredFailures;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\ActiveQuery;

/**
 * Search/filter form for the job list (the web list and GET /api/v1/jobs).
 * Every filter is optional, and none is refused: a value a filter cannot
 * use is no filter, a list (?status[]=failed) included, which answered 500
 * up to 2.14.0.
 */
class JobSearchForm extends Model
{
    /** The values of the filter recovered: the failures that a later run recovered, or those that none recovered. */
    public const RECOVERED_YES = '1';
    public const RECOVERED_NO = '0';

    /** What the web job list says while the filter finished_since is active; sprintf(): its time, as the list shows a time. */
    public const FINISHED_SINCE_NOTE = 'Only jobs that finished since %s.';

    /** The link of that note, which removes the filter and keeps the others. */
    public const FINISHED_SINCE_CLEAR = 'Remove this filter';

    public ?string $status = null;
    public ?string $template_id = null;
    public ?string $launched_by = null;
    public ?string $runner_group_id = null;
    public ?string $date_from = null;
    public ?string $date_to = null;
    public ?string $has_changes = null;

    /**
     * RECOVERED_YES: only the failed and timed-out jobs that a later run of
     * the same job template recovered (RecoveredFailures); RECOVERED_NO:
     * only those that none recovered. Any other value is no filter.
     */
    public ?string $recovered = null;

    /**
     * A Unix time: only the jobs that finished then or later, so not one
     * that has not finished (finishedSince()). The dashboard's link to the
     * recovered failures of its last 24 hours passes the window's start. A
     * value that is no whole number of 0 or more is no filter.
     */
    public ?string $finished_since = null;

    /**
     * Team-based access filter. When set, restricts jobs to those whose
     * job_template belongs to an accessible project.
     *
     * @var array<int|string, mixed>|null
     */
    public ?array $teamFilter = null;

    public function rules(): array
    {
        return [
            [['status'], 'in', 'range' => array_merge([''], Job::statuses())],
            [['template_id', 'launched_by', 'runner_group_id'], 'integer'],
            // Trimmed first: a date of only whitespace is empty, no filter, on
            // every PHP version. strtotime() read " " as now on PHP 8.3 (the
            // job list was empty) and as no date on PHP 8.4 and later, and
            // reads a NUL byte as now on every version.
            [['date_from', 'date_to'], 'trim', 'skipOnEmpty' => true],
            // Before the date rule, whose DateTime::createFromFormat() throws
            // a ValueError for a NUL byte; the date rule skips such a date.
            [['date_from', 'date_to'], 'match', 'pattern' => '/\x00/', 'not' => true],
            [['date_from', 'date_to'], 'date', 'format' => 'php:Y-m-d'],
            [['has_changes'], 'in', 'range' => ['', '1', '0']],
            // Strict, as search() reads it: " 1" and "1.0" are no value of it.
            [['recovered'], 'in', 'range' => ['', self::RECOVERED_YES, self::RECOVERED_NO], 'strict' => true],
            // The rule finishedSince() reads it by (SubmittedInteger).
            [['finished_since'], 'integer', 'min' => 0],
        ];
    }

    /**
     * The filter finished_since, Unix seconds; null when it is no filter:
     * not set, empty, or no whole number of 0 or more ("1.5", " 1", "-1"),
     * as its rule says.
     */
    public function finishedSince(): ?int
    {
        $since = SubmittedInteger::of($this->finished_since);

        return $since !== null && $since >= 0 ? $since : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function search(array $params): ActiveDataProvider
    {
        // A list is no value of any filter; load() would assign it to a
        // string property (a TypeError).
        $this->load(array_filter($params, static fn (mixed $value): bool => !is_array($value)), '');
        // Don't throw on invalid filter input — just ignore it
        $this->validate();

        // The step execution (and its step) names the placeholder of a workflow approval step.
        $query = Job::find()
            ->with(['jobTemplate', 'launcher', 'hostSummaries', 'workflowJobStep.workflowStep'])
            ->orderBy(['id' => SORT_DESC]);

        if ($this->teamFilter !== null) {
            $query->andWhere($this->teamFilter);
        }
        if (!empty($this->status)) {
            $query->andWhere(['status' => $this->status]);
        }
        if (!empty($this->template_id)) {
            $query->andWhere(['job_template_id' => (int)$this->template_id]);
        }
        if (!empty($this->launched_by)) {
            $query->andWhere(['launched_by' => (int)$this->launched_by]);
        }
        if (!empty($this->runner_group_id)) {
            $query->andWhere(['job_template_id' => JobTemplate::find()
                ->select('id')
                ->where(['runner_group_id' => (int)$this->runner_group_id])]);
        }
        $this->applyDateFilters($query);
        $this->applyOutcomeFilters($query);

        return new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);
    }

    /**
     * What came of the jobs: whether they changed something, and whether a
     * later run recovered a failure.
     *
     * @param ActiveQuery<Job> $query
     */
    private function applyOutcomeFilters(ActiveQuery $query): void
    {
        if ($this->has_changes !== null && $this->has_changes !== '') {
            $query->andWhere(['has_changes' => (int)$this->has_changes]);
        }
        if ($this->recovered === self::RECOVERED_YES || $this->recovered === self::RECOVERED_NO) {
            RecoveredFailures::filterRecovered($query, $this->recovered === self::RECOVERED_YES);
        }
    }

    /**
     * When the jobs were created (date_from, date_to: days) and when they
     * finished (finished_since).
     *
     * @param ActiveQuery<Job> $query
     */
    private function applyDateFilters(ActiveQuery $query): void
    {
        $finishedSince = $this->finishedSince();
        if ($finishedSince !== null) {
            $query->andWhere(['>=', 'finished_at', $finishedSince]);
        }
        if (!empty($this->date_from)) {
            $ts = strtotime($this->date_from);
            if ($ts !== false) {
                $query->andWhere(['>=', 'created_at', $ts]);
            }
        }
        if (!empty($this->date_to)) {
            // Inclusive: end of the given day
            $ts = strtotime($this->date_to . ' 23:59:59');
            if ($ts !== false) {
                $query->andWhere(['<=', 'created_at', $ts]);
            }
        }
    }
}
