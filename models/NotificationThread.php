<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * The status post of a notification template for a workflow run, one per
 * template and run: the post the run's later events edit and reply to.
 * The server writes every row; no form or request body does.
 *
 * @property int    $id
 * @property int    $notification_template_id
 * @property int    $workflow_job_id
 * @property string $root_post_id the status post
 * @property string $channel_id   the channel it was posted in
 * @property string $server       the server it lives on, to notice a changed server
 * @property int    $created_at
 * @property int    $updated_at
 *
 * @property NotificationTemplate $template
 * @property WorkflowJob $workflowJob
 */
class NotificationThread extends ActiveRecord
{
    /** The validation error of a second status post of a template for the same run. */
    public const ONE_PER_RUN = 'The template has a status post for this run already.';

    public static function tableName(): string
    {
        return '{{%notification_thread}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['notification_template_id', 'workflow_job_id', 'root_post_id', 'channel_id', 'server'], 'required'],
            [['notification_template_id', 'workflow_job_id'], 'integer'],
            [['root_post_id', 'channel_id'], 'string', 'max' => 64],
            [['server'], 'string', 'max' => 255],
            [
                ['notification_template_id'],
                'unique',
                'targetAttribute' => ['notification_template_id', 'workflow_job_id'],
                'message' => self::ONE_PER_RUN,
            ],
        ];
    }

    public function getTemplate(): ActiveQuery
    {
        return $this->hasOne(NotificationTemplate::class, ['id' => 'notification_template_id']);
    }

    public function getWorkflowJob(): ActiveQuery
    {
        return $this->hasOne(WorkflowJob::class, ['id' => 'workflow_job_id']);
    }
}
