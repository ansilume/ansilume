<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $credential_type
 * @property string|null $username
 * @property string|null $env_var_name  Optional env var name for TYPE_TOKEN injection.
 * @property string|null $secret_data   Encrypted JSON — never expose raw
 * @property int         $created_by
 * @property int         $created_at
 * @property int         $updated_at
 *
 * @property User        $creator
 */
class Credential extends ActiveRecord
{
    public const TYPE_SSH_KEY = 'ssh_key';
    public const TYPE_USERNAME_PASSWORD = 'username_password';
    public const TYPE_VAULT = 'vault';
    public const TYPE_TOKEN = 'token';

    /** Default env var name for a TYPE_TOKEN credential when none is configured. */
    public const DEFAULT_TOKEN_ENV_VAR = 'ANSILUME_CREDENTIAL_TOKEN';

    public static function tableName(): string
    {
        return '{{%credential}}';
    }

    public function behaviors(): array
    {
        return [\yii\behaviors\TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['name', 'credential_type'], 'required'],
            [['name'], 'string', 'max' => 128],
            [['description'], 'string', 'max' => 1000],
            [['credential_type'], 'in', 'range' => [
                self::TYPE_SSH_KEY,
                self::TYPE_USERNAME_PASSWORD,
                self::TYPE_VAULT,
                self::TYPE_TOKEN,
            ]],
            [['username'], 'string', 'max' => 128],
            [['env_var_name'], 'string', 'max' => 128],
            [['env_var_name'], 'match', 'pattern' => '/^[A-Z_][A-Z0-9_]*$/', 'message' =>
                'Env var name must use upper-case letters, digits, and underscores only, and start with a letter or underscore.'],
            [['created_by'], 'integer'],
        ];
    }

    /**
     * Resolve the runtime env var name for TYPE_TOKEN credentials.
     * Returns the configured name when set, otherwise the historical default.
     */
    public function resolveTokenEnvVarName(): string
    {
        $name = trim((string)($this->env_var_name ?? ''));
        return $name !== '' ? $name : self::DEFAULT_TOKEN_ENV_VAR;
    }

    public function getCreator(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'created_by']);
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_SSH_KEY => 'SSH Key',
            self::TYPE_USERNAME_PASSWORD => 'Username / Password',
            self::TYPE_VAULT => 'Vault Secret',
            self::TYPE_TOKEN => 'Token',
            default => $type,
        };
    }

    /**
     * Returns the list of field names that must never be logged or rendered.
     *
     * @return string[]
     */
    public static function sensitiveFields(): array
    {
        return ['secret_data', 'password', 'private_key', 'token', 'vault_password'];
    }
}
