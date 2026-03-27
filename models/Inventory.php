<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $inventory_type
 * @property string|null $content
 * @property string|null $source_path
 * @property int|null    $project_id
 * @property string|null $parsed_hosts  Cached JSON from ansible-inventory
 * @property string|null $parsed_error  Error from last parse attempt
 * @property int|null    $parsed_at     Unix timestamp of last parse
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property User        $creator
 * @property Project|null $project
 */
class Inventory extends ActiveRecord
{
    public const TYPE_STATIC  = 'static';
    public const TYPE_DYNAMIC = 'dynamic';
    public const TYPE_FILE    = 'file';

    public static function tableName(): string
    {
        return '{{%inventory}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['name', 'inventory_type'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['description', 'content'], 'string'],
            [['inventory_type'], 'in', 'range' => [self::TYPE_STATIC, self::TYPE_DYNAMIC, self::TYPE_FILE]],
            [['source_path'], 'string', 'max' => 512],
            [['content'], 'required',
                'when'       => fn($m) => $m->inventory_type === self::TYPE_STATIC,
                'whenClient' => "function(attr, val) { return $('#inventory-type').val() === 'static'; }",
            ],
            [['source_path', 'project_id'], 'required',
                'when'       => fn($m) => $m->inventory_type === self::TYPE_FILE,
                'whenClient' => "function(attr, val) { return $('#inventory-type').val() === 'file'; }",
            ],
            [['project_id', 'created_by'], 'integer'],
        ];
    }

    public function getCreator(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getProject(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Project::class, ['id' => 'project_id']);
    }
}
