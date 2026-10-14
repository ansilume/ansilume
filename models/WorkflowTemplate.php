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
 * @property WorkflowStep[] $steps in run order (RUN_ORDER)
 * @property WorkflowJob[] $workflowJobs
 */
class WorkflowTemplate extends ActiveRecord
{
    use TriggerTokenTrait;

    /**
     * The run order of a workflow's steps: by step_order, ties by ID. A
     * step's position is its 1-based index in this order, and position 1 is
     * the start step. step_order is an internal sort key: the web numbers the
     * steps 10, 20, 30… and the API's bulk list 0, 1, 2…, and steps saved by
     * older versions or edited in the database can share one.
     */
    public const RUN_ORDER = ['step_order' => SORT_ASC, 'id' => SORT_ASC];

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

    /**
     * Lock the template's row until the current transaction ends (SELECT ...
     * FOR UPDATE) and tell whether it is a live template: false when the row
     * does not exist or is soft-deleted. A step change and the delete lock
     * this row first, as a launch does, so a run cannot start between a step
     * change's checks and its write, and a launch cannot slip in between a
     * delete and the cancelation of the template's runs. Call it inside a
     * transaction: on its own, the lock ends with the statement.
     */
    public static function lockLive(int $id): bool
    {
        $row = \Yii::$app->db->createCommand(
            'SELECT [[deleted_at]] FROM {{%workflow_template}} WHERE [[id]] = :id FOR UPDATE',
            [':id' => $id]
        )->queryOne();

        return is_array($row) && $row['deleted_at'] === null;
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
     * The start step: the first step in the run order (RUN_ORDER), position
     * 1; of steps that share the lowest step_order, the one with the lowest
     * ID. Null for a workflow without steps.
     */
    public function getStartStep(): ?WorkflowStep
    {
        /** @var WorkflowStep|null $step */
        $step = WorkflowStep::find()
            ->where(['workflow_template_id' => $this->id])
            ->orderBy(self::RUN_ORDER)
            ->one();
        return $step;
    }

    public function getCreator(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    /**
     * The workflow's steps in run order (RUN_ORDER), so that their index is
     * their position. The order names its columns without a table, as a
     * query of the steps alone needs them: load the relation with with() or
     * lazily, not with joinWith(), which would make "id" ambiguous.
     */
    public function getSteps(): ActiveQuery
    {
        return $this->hasMany(WorkflowStep::class, ['workflow_template_id' => 'id'])
            ->orderBy(self::RUN_ORDER);
    }

    public function getWorkflowJobs(): ActiveQuery
    {
        return $this->hasMany(WorkflowJob::class, ['workflow_template_id' => 'id']);
    }
}
