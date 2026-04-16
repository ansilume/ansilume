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
 * @property string $auth_source            'local' = bcrypt password; 'ldap' = external directory bind
 * @property string|null $ldap_dn           Distinguished name from the directory (set after bind)
 * @property string|null $ldap_uid          Stable directory identifier (objectGUID/entryUUID)
 * @property int|null    $last_synced_at    Unix timestamp of the last LDAP attribute sync
 * @property int    $status
 * @property bool   $is_superadmin
 * @property int    $created_at
 * @property int    $updated_at
 */
class User extends ActiveRecord implements IdentityInterface
{
    public const STATUS_INACTIVE = 0;
    public const STATUS_ACTIVE = 10;

    public const AUTH_SOURCE_LOCAL = 'local';
    public const AUTH_SOURCE_LDAP = 'ldap';

    /**
     * Sentinel value stored in password_hash for LDAP users. Bcrypt's
     * password_verify() returns false for any input against this string,
     * so even if auth_source is tampered with, no plaintext can ever
     * unlock the account through the local password path.
     */
    public const LDAP_PASSWORD_SENTINEL = '!ldap';

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
            [['auth_source'], 'in', 'range' => [self::AUTH_SOURCE_LOCAL, self::AUTH_SOURCE_LDAP]],
            [['ldap_dn'], 'string', 'max' => 512],
            [['ldap_uid'], 'string', 'max' => 255],
            [['last_synced_at'], 'integer'],
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
        /** @var self|null $result */
        $result = static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
        return $result;
    }

    public static function findIdentityByAccessToken($token, $type = null): ?self
    {
        $apiToken = \app\models\ApiToken::findByRawToken((string)$token);
        if ($apiToken === null) {
            return null;
        }
        /** @var self|null $result */
        $result = static::findOne(['id' => $apiToken->user_id, 'status' => self::STATUS_ACTIVE]);
        return $result;
    }

    public static function findByUsername(string $username): ?self
    {
        /** @var self|null $result */
        $result = static::findOne(['username' => $username, 'status' => self::STATUS_ACTIVE]);
        return $result;
    }

    public function getId(): int
    {
        /** @var int $id */
        $id = $this->id;
        return $id;
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
        // LDAP-backed users never authenticate through the local bcrypt path.
        // The stored password_hash is the LDAP_PASSWORD_SENTINEL, against
        // which password_verify() always returns false anyway, but we
        // short-circuit here for defense in depth.
        if ($this->isLdap()) {
            return false;
        }
        /** @var \yii\base\Security $security */
        $security = \Yii::$app->security;
        return $security->validatePassword($password, $this->password_hash);
    }

    public function setPassword(string $password): void
    {
        // Local-account-only operation. LDAP users have their password
        // managed in the directory; setting one here would either be
        // discarded by the sentinel or — worse — open a parallel local
        // login path. Callers that handle LDAP users must check isLdap()
        // first and refuse password changes via the application UI/API.
        if ($this->isLdap()) {
            throw new \LogicException('Cannot set password for LDAP-backed user.');
        }
        /** @var \yii\base\Security $security */
        $security = \Yii::$app->security;
        $this->password_hash = $security->generatePasswordHash($password);
        // Rotate auth_key so any existing session cookies (bound to the old
        // auth_key) become invalid on password change. Protects against a
        // stolen cookie surviving a password reset.
        $this->auth_key = $security->generateRandomString();
    }

    /**
     * Mark the password_hash column with the sentinel value so that the
     * local password path can never authenticate this user, even if
     * auth_source is later tampered with. Use when provisioning an
     * LDAP-backed account.
     */
    public function markAsLdapManaged(): void
    {
        $this->auth_source = self::AUTH_SOURCE_LDAP;
        $this->password_hash = self::LDAP_PASSWORD_SENTINEL;
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

    /**
     * True for accounts authenticated against the local bcrypt password.
     */
    public function isLocal(): bool
    {
        return $this->auth_source === self::AUTH_SOURCE_LOCAL;
    }

    /**
     * True for accounts authenticated against an external LDAP/AD directory.
     */
    public function isLdap(): bool
    {
        return $this->auth_source === self::AUTH_SOURCE_LDAP;
    }

    // --- Password reset token ---

    /** Token validity in seconds (60 minutes). */
    public const PASSWORD_RESET_TOKEN_EXPIRE = 3600;

    /**
     * Generate a time-stamped password reset token and persist it.
     *
     * Disallowed for LDAP-backed accounts — their password is managed in
     * the directory, so a local reset would be misleading and the token
     * would unlock a sentinel-protected hash anyway.
     */
    public function generatePasswordResetToken(): void
    {
        if ($this->isLdap()) {
            throw new \LogicException('Cannot generate password reset token for LDAP-backed user.');
        }
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

        /** @var self|null $result */
        $result = static::findOne([
            'password_reset_token' => $token,
            'status' => self::STATUS_ACTIVE,
        ]);
        return $result;
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
