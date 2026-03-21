<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property User        $creator
 * @property TeamMember[] $teamMembers
 * @property User[]       $members
 * @property TeamProject[] $teamProjects
 * @property Project[]    $projects
 */
class Team extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%team}}';
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
            [['name'], 'unique'],
            [['description'], 'string'],
            [['created_by'], 'integer'],
        ];
    }

    public function getCreator(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getTeamMembers(): \yii\db\ActiveQuery
    {
        return $this->hasMany(TeamMember::class, ['team_id' => 'id']);
    }

    public function getMembers(): \yii\db\ActiveQuery
    {
        return $this->hasMany(User::class, ['id' => 'user_id'])
            ->via('teamMembers');
    }

    public function getTeamProjects(): \yii\db\ActiveQuery
    {
        return $this->hasMany(TeamProject::class, ['team_id' => 'id']);
    }

    public function getProjects(): \yii\db\ActiveQuery
    {
        return $this->hasMany(Project::class, ['id' => 'project_id'])
            ->via('teamProjects');
    }

    public function hasMember(int $userId): bool
    {
        return TeamMember::find()
            ->where(['team_id' => $this->id, 'user_id' => $userId])
            ->exists();
    }
}
