<?php

declare(strict_types=1);

namespace app\components;

use app\models\Credential;

/**
 * Which secret each credential type needs, and how submitted secrets are
 * read. Shared by the web form and the REST API.
 *
 * - Only the keys of the credential's own type count; keys of other types
 *   are ignored.
 * - Blank values mean "not provided" (on update: keep the stored secret).
 * - Private keys get LF line endings (browsers submit CRLF from textareas).
 */
final class CredentialSecretPolicy
{
    private const REQUIRED_KEYS = [
        Credential::TYPE_SSH_KEY => ['private_key'],
        Credential::TYPE_USERNAME_PASSWORD => ['password'],
        Credential::TYPE_VAULT => ['vault_password'],
        Credential::TYPE_TOKEN => ['token'],
    ];

    private const LABELS = [
        'private_key' => 'Private key',
        'password' => 'Password',
        'vault_password' => 'Vault password',
        'token' => 'Token',
    ];

    /**
     * @return list<string>
     */
    public static function requiredKeys(string $type): array
    {
        return self::REQUIRED_KEYS[$type] ?? [];
    }

    /**
     * The secrets of $type found in $input.
     *
     * @param array<array-key, mixed> $input
     * @return array<string, string>
     */
    public static function provided(string $type, array $input): array
    {
        $provided = [];
        foreach (self::requiredKeys($type) as $key) {
            $value = $input[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }
            $value = (string)$value;
            if ($key === 'private_key') {
                $value = str_replace(["\r\n", "\r"], "\n", $value);
            }
            if (trim($value) !== '') {
                $provided[$key] = $value;
            }
        }

        return $provided;
    }

    /**
     * @param array<string, string> $provided
     * @return list<string>
     */
    public static function missing(string $type, array $provided): array
    {
        return array_values(array_diff(self::requiredKeys($type), array_keys($provided)));
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    /**
     * @param list<string> $missing
     */
    public static function missingMessage(string $type, array $missing, bool $typeChange): string
    {
        $labels = implode(', ', array_map(static fn (string $key): string => self::label($key), $missing));
        $typeLabel = Credential::typeLabel($type);

        return $typeChange
            ? "Changing the type to {$typeLabel} requires a new " . strtolower($labels) . '.'
            : "{$labels} is required for {$typeLabel} credentials.";
    }
}
