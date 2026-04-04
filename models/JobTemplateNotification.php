<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Pivot: links a job template to a notification template.
 *
 * @property int $id
 * @property int $job_template_id
 * @property int $notification_template_id
 *
 * @property JobTemplate $jobTemplate
 * @property NotificationTemplate $notificationTemplate
 */
class JobTemplateNotification extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%job_template_notification}}';
    }

    public function rules(): array
    {
        return [
            [['job_template_id', 'notification_template_id'], 'required'],
            [['job_template_id', 'notification_template_id'], 'integer'],
            [
                ['notification_template_id'],
                'unique',
                'targetAttribute' => ['job_template_id', 'notification_template_id'],
                'message' => 'This notification template is already linked.',
            ],
        ];
    }

    public function getJobTemplate(): ActiveQuery
    {
        return $this->hasOne(JobTemplate::class, ['id' => 'job_template_id']);
    }

    public function getNotificationTemplate(): ActiveQuery
    {
        return $this->hasOne(NotificationTemplate::class, ['id' => 'notification_template_id']);
    }
}
