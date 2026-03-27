<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property int         $runner_group_id
 * @property string      $name
 * @property string      $token_hash       SHA-256 of the raw token
 * @property string|null $description
 * @property int|null    $last_seen_at
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property RunnerGroup $group
 * @property User        $creator
 * @property Job[]       $jobs
 */
class Runner extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%runner}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['runner_group_id', 'name'], 'required'],
            [['runner_group_id', 'created_by'], 'integer'],
            [['name'], 'string', 'max' => 128],
            [['description'], 'string'],
            [['token_hash'], 'string', 'max' => 64],
        ];
    }

    public function getGroup(): \yii\db\ActiveQuery
    {
        return $this->hasOne(RunnerGroup::class, ['id' => 'runner_group_id']);
    }

    public function getCreator(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public function getJobs(): \yii\db\ActiveQuery
    {
        return $this->hasMany(Job::class, ['runner_id' => 'id']);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && (time() - $this->last_seen_at) < RunnerGroup::STALE_AFTER;
    }

    /**
     * Generate a new token. Returns ['raw' => ..., 'hash' => ...].
     * Store only the hash; show raw once to the user.
     */
    public static function generateToken(): array
    {
        $raw = bin2hex(random_bytes(32)); // 64-char hex string
        $hash = hash('sha256', $raw);
        return ['raw' => $raw, 'hash' => $hash];
    }

    /**
     * Find a runner by its raw token (hashes and compares).
     */
    public static function findByToken(string $rawToken): ?self
    {
        if ($rawToken === '') {
            return null;
        }
        return static::findOne(['token_hash' => hash('sha256', $rawToken)]);
    }
}
