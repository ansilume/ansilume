<?php

declare(strict_types=1);

namespace app\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;
use yii\db\ActiveQuery;

/**
 * Search/filter form for the job list.
 */
class JobSearchForm extends Model
{
    public ?string $status = null;
    public ?string $template_id = null;
    public ?string $launched_by = null;
    public ?string $runner_group_id = null;
    public ?string $date_from = null;
    public ?string $date_to = null;
    public ?string $has_changes = null;

    public function rules(): array
    {
        return [
            [['status'], 'in', 'range' => array_merge([''], Job::statuses())],
            [['template_id', 'launched_by', 'runner_group_id'], 'integer'],
            [['date_from', 'date_to'], 'date', 'format' => 'php:Y-m-d'],
            [['has_changes'], 'in', 'range' => ['', '1', '0']],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function search(array $params): ActiveDataProvider
    {
        $this->load($params, '');
        // Don't throw on invalid filter input — just ignore it
        $this->validate();

        $query = Job::find()
            ->with(['jobTemplate', 'launcher', 'hostSummaries'])
            ->orderBy(['id' => SORT_DESC]);

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
        if ($this->has_changes !== null && $this->has_changes !== '') {
            $query->andWhere(['has_changes' => (int)$this->has_changes]);
        }

        return new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);
    }

    /** @param ActiveQuery<Job> $query */
    private function applyDateFilters(ActiveQuery $query): void
    {
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
