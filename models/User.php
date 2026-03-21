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
 * @property int    $status
 * @property bool   $is_superadmin
 * @property int    $created_at
 * @property int    $updated_at
 */
class User extends ActiveRecord implements IdentityInterface
{
    public const STATUS_INACTIVE = 0;
    public const STATUS_ACTIVE   = 10;

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
            'id'           => 'ID',
            'username'     => 'Username',
            'email'        => 'Email',
            'status'       => 'Status',
            'is_superadmin' => 'Superadmin',
            'created_at'   => 'Created',
            'updated_at'   => 'Updated',
        ];
    }

    // --- IdentityInterface ---

    public static function findIdentity($id): ?static
    {
        return static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
    }

    public static function findIdentityByAccessToken($token, $type = null): ?static
    {
        $apiToken = \app\models\ApiToken::findByRawToken((string)$token);
        if ($apiToken === null) {
            return null;
        }
        return static::findOne(['id' => $apiToken->user_id, 'status' => self::STATUS_ACTIVE]);
    }

    public static function findByUsername(string $username): ?static
    {
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
        return \Yii::$app->security->validatePassword($password, $this->password_hash);
    }

    public function setPassword(string $password): void
    {
        $this->password_hash = \Yii::$app->security->generatePasswordHash($password);
    }

    public function generateAuthKey(): void
    {
        $this->auth_key = \Yii::$app->security->generateRandomString();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    // --- Timestamps ---

    public function behaviors(): array
    {
        return [
            \yii\behaviors\TimestampBehavior::class,
        ];
    }
}
