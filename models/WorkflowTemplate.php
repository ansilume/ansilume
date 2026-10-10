<?php

declare(strict_types=1);

namespace app\models;

use app\models\traits\TriggerTokenTrait;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string|null $trigger_token  SHA-256 hex of the raw inbound-trigger token; null = trigger disabled
 * @property int         $created_by
 * @property int|null    $trigger_token_created_by  user who generated the current trigger token; null = created before tokens recorded it
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
    use TriggerTokenTrait;

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

    /**
     * Soft-delete this template by setting deleted_at. The trigger token goes
     * with it: the template's page answers 404 from now on, so nobody could
     * revoke the token, and the user who generated it could not be deleted.
     */
    public function softDelete(): bool
    {
        $this->deleted_at = time();
        $this->clearTriggerToken();
        return $this->save(false, ['deleted_at', 'trigger_token', 'trigger_token_created_by']);
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
            [['description'], 'string', 'max' => 1000],
            // Set in code only: a form must not choose the token or whom the
            // workflow and its trigger run as.
            [['!trigger_token'], 'string', 'max' => 64],
            [['!created_by', '!trigger_token_created_by'], 'integer'],
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
