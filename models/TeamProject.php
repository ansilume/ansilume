<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int     $id
 * @property int     $team_id
 * @property int     $project_id
 * @property string  $role       'viewer' or 'operator'
 * @property int     $created_at
 *
 * @property Team    $team
 * @property Project $project
 */
class TeamProject extends ActiveRecord
{
    public const ROLE_VIEWER   = 'viewer';
    public const ROLE_OPERATOR = 'operator';

    public static function tableName(): string
    {
        return '{{%team_project}}';
    }

    public function rules(): array
    {
        return [
            [['team_id', 'project_id', 'role'], 'required'],
            [['team_id', 'project_id'], 'integer'],
            [['team_id', 'project_id'], 'unique', 'targetAttribute' => ['team_id', 'project_id']],
            [['role'], 'in', 'range' => [self::ROLE_VIEWER, self::ROLE_OPERATOR]],
        ];
    }

    public function getTeam(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Team::class, ['id' => 'team_id']);
    }

    public function getProject(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Project::class, ['id' => 'project_id']);
    }
}
