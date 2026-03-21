<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int  $id
 * @property int  $team_id
 * @property int  $user_id
 * @property int  $created_at
 *
 * @property Team $team
 * @property User $user
 */
class TeamMember extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%team_member}}';
    }

    public function rules(): array
    {
        return [
            [['team_id', 'user_id'], 'required'],
            [['team_id', 'user_id'], 'integer'],
            [['team_id', 'user_id'], 'unique', 'targetAttribute' => ['team_id', 'user_id']],
        ];
    }

    public function getTeam(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Team::class, ['id' => 'team_id']);
    }

    public function getUser(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
