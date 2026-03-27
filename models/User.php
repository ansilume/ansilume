<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

/**
 * @property int    $id
 * @property string $username
 * @property string $email
 * @property string $password_hash
 * @property string $auth_key
 * @property string|null $password_reset_token
 * @property string|null $totp_secret       Encrypted TOTP shared secret
 * @property bool        $totp_enabled      Whether TOTP 2FA is active
 * @property string|null $recovery_codes    JSON array of bcrypt-hashed recovery codes
 * @property int    $status
 * @property bool   $is_superadmin
 * @property int    $created_at
 * @property int    $updated_at
 */
class User extends ActiveRecord implements IdentityInterface
{
    public const STATUS_INACTIVE = 0;
    public const STATUS_ACTIVE = 10;

    public static function tableName(): string
    {
        return '{{%user}}';
    }

    public function rules(): array
    {
        return [
            [['username', 'email'], 'required'],
            [['username'], 'string', 'max' => 64],
            [['email'], 'email'],
            [['email'], 'string', 'max' => 255],
            [['username', 'email'], 'unique'],
            [['status'], 'in', 'range' => [self::STATUS_INACTIVE, self::STATUS_ACTIVE]],
            [['is_superadmin'], 'boolean'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'username' => 'Username',
            'email' => 'Email',
            'status' => 'Status',
            'is_superadmin' => 'Superadmin',
            'created_at' => 'Created',
            'updated_at' => 'Updated',
        ];
    }

    // --- IdentityInterface ---

    public static function findIdentity($id): ?self
    {
        /** @var self|null */
        return static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
    }

    public static function findIdentityByAccessToken($token, $type = null): ?self
    {
        $apiToken = \app\models\ApiToken::findByRawToken((string)$token);
        if ($apiToken === null) {
            return null;
        }
        /** @var self|null */
        return static::findOne(['id' => $apiToken->user_id, 'status' => self::STATUS_ACTIVE]);
    }

    public static function findByUsername(string $username): ?self
    {
        /** @var self|null */
        return static::findOne(['username' => $username, 'status' => self::STATUS_ACTIVE]);
    }

    public function getId(): int
    {
        return (int)$this->id;
    }

    public function getAuthKey(): string
    {
        return $this->auth_key;
    }

    public function validateAuthKey($authKey): bool
    {
        return $this->auth_key === $authKey;
    }

    // --- Password helpers ---

    public function validatePassword(string $password): bool
    {
        /** @var \yii\base\Security $security */
        $security = \Yii::$app->security;
        return $security->validatePassword($password, $this->password_hash);
    }

    public function setPassword(string $password): void
    {
        /** @var \yii\base\Security $security */
        $security = \Yii::$app->security;
        $this->password_hash = $security->generatePasswordHash($password);
    }

    public function generateAuthKey(): void
    {
        /** @var \yii\base\Security $security */
        $security = \Yii::$app->security;
        $this->auth_key = $security->generateRandomString();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    // --- Password reset token ---

    /** Token validity in seconds (60 minutes). */
    public const PASSWORD_RESET_TOKEN_EXPIRE = 3600;

    /**
     * Generate a time-stamped password reset token and persist it.
     */
    public function generatePasswordResetToken(): void
    {
        /** @var \yii\base\Security $security */
        $security = \Yii::$app->security;
        $this->password_reset_token = $security->generateRandomString() . '_' . time();
    }

    /**
     * Remove the password reset token.
     */
    public function removePasswordResetToken(): void
    {
        $this->password_reset_token = null;
    }

    /**
     * Find a user by a valid (non-expired) password reset token.
     */
    public static function findByPasswordResetToken(string $token): ?self
    {
        if (empty($token)) {
            return null;
        }

        $timestamp = (int)substr($token, strrpos($token, '_') + 1);
        $expire = self::PASSWORD_RESET_TOKEN_EXPIRE;
        if ($timestamp + $expire < time()) {
            return null;
        }

        /** @var self|null */
        return static::findOne([
            'password_reset_token' => $token,
            'status' => self::STATUS_ACTIVE,
        ]);
    }

    /**
     * Check whether the current reset token is still valid.
     */
    public function isPasswordResetTokenValid(): bool
    {
        if (empty($this->password_reset_token)) {
            return false;
        }
        $timestamp = (int)substr($this->password_reset_token, strrpos($this->password_reset_token, '_') + 1);
        return $timestamp + self::PASSWORD_RESET_TOKEN_EXPIRE >= time();
    }

    // --- Timestamps ---

    public function behaviors(): array
    {
        return [
            \yii\behaviors\TimestampBehavior::class,
        ];
    }
}
