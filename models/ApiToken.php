<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property int         $user_id
 * @property string      $name
 * @property string      $token_hash   SHA-256 hex of the raw token
 * @property int|null    $last_used_at
 * @property int|null    $expires_at
 * @property int         $created_at
 *
 * @property User        $user
 */
class ApiToken extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%api_token}}';
    }

    public function rules(): array
    {
        return [
            [['user_id', 'name'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['user_id'], 'integer'],
            [['expires_at'], 'integer'],
        ];
    }

    public function getUser(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at < time();
    }

    /**
     * Find an active (non-expired) token by raw token string.
     */
    public static function findByRawToken(string $raw): ?self
    {
        $hash = hash('sha256', $raw);
        $token = static::findOne(['token_hash' => $hash]);
        if ($token === null || $token->isExpired()) {
            return null;
        }
        return $token;
    }

    /**
     * Generate a new raw token, store its hash, return the raw value.
     * The raw value is shown once and must not be stored by the application.
     *
     * @return array{token: self, raw: string}
     */
    public static function generate(int $userId, string $name, ?int $expiresAt = null): array
    {
        $raw = bin2hex(random_bytes(32)); // 64-char hex = 256 bits of entropy
        $hash = hash('sha256', $raw);

        $token = new self();
        $token->user_id = $userId;
        $token->name = $name;
        $token->token_hash = $hash;
        $token->expires_at = $expiresAt;
        $token->created_at = time();
        $token->save(false);

        return ['token' => $token, 'raw' => $raw];
    }
}
