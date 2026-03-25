<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int    $id
 * @property int    $job_id
 * @property string $filename       Original filename on disk
 * @property string $display_name   User-facing name
 * @property string $mime_type
 * @property int    $size_bytes
 * @property string $storage_path   Absolute path to the stored file
 * @property int    $created_at
 *
 * @property Job $job
 */
class JobArtifact extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%job_artifact}}';
    }

    public function rules(): array
    {
        return [
            [['job_id', 'filename', 'display_name', 'storage_path'], 'required'],
            [['job_id', 'size_bytes', 'created_at'], 'integer'],
            [['filename', 'display_name'], 'string', 'max' => 255],
            [['mime_type'], 'string', 'max' => 128],
            [['storage_path'], 'string', 'max' => 512],
        ];
    }

    public function getJob(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Job::class, ['id' => 'job_id']);
    }
}
