<?php

declare(strict_types=1);

namespace app\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * Search/filter form for the job list.
 */
class JobSearchForm extends Model
{
    public ?string $status       = null;
    public ?int    $template_id  = null;
    public ?int    $launched_by  = null;
    public ?string $date_from    = null;
    public ?string $date_to      = null;

    public function rules(): array
    {
        return [
            [['status'],      'in', 'range' => array_merge([''], Job::statuses())],
            [['template_id', 'launched_by'], 'integer'],
            [['date_from', 'date_to'], 'date', 'format' => 'php:Y-m-d'],
        ];
    }

    public function search(array $params): ActiveDataProvider
    {
        $this->load($params, '');
        // Don't throw on invalid filter input — just ignore it
        $this->validate();

        $query = Job::find()
            ->with(['jobTemplate', 'launcher'])
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

        return new ActiveDataProvider([
            'query'      => $query,
            'pagination' => ['pageSize' => 25],
        ]);
    }
}
