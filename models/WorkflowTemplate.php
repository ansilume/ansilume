<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 * @property int|null    $deleted_at
 *
 * @property User $creator
 * @property WorkflowStep[] $steps
 * @property WorkflowJob[] $workflowJobs
 */
class WorkflowTemplate extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%workflow_template}}';
    }

    /**
     * Default scope: exclude soft-deleted templates.
     */
    public static function find(): ActiveQuery
    {
        return parent::find()->andWhere(['{{%workflow_template}}.deleted_at' => null]);
    }

    /**
     * Query that includes soft-deleted templates.
     */
    public static function findWithDeleted(): ActiveQuery
    {
        return parent::find();
    }

    public function softDelete(): bool
    {
        $this->deleted_at = time();
        return $this->save(false, ['deleted_at']);
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['description'], 'string'],
            [['created_by'], 'integer'],
        ];
    }

    /**
     * Get the first step (lowest step_order).
     */
    public function getStartStep(): ?WorkflowStep
    {
        /** @var WorkflowStep|null $step */
        $step = WorkflowStep::find()
            ->where(['workflow_template_id' => $this->id])
            ->orderBy(['step_order' => SORT_ASC])
            ->one();
        return $step;
    }

    public function getCreator(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getSteps(): ActiveQuery
    {
        return $this->hasMany(WorkflowStep::class, ['workflow_template_id' => 'id'])
            ->orderBy(['step_order' => SORT_ASC]);
    }

    public function getWorkflowJobs(): ActiveQuery
    {
        return $this->hasMany(WorkflowJob::class, ['workflow_template_id' => 'id']);
    }
}
