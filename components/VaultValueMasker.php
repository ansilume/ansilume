<?php

declare(strict_types=1);

namespace app\components;

/**
 * Replaces inline vault values in parsed inventory data with a marker.
 *
 * ansible-inventory --list prints an inline `!vault |` value as
 * {"__ansible_vault": "<ciphertext>"}. The ciphertext is useless to show and
 * should not be cached, so it becomes MARKER.
 */
final class VaultValueMasker
{
    public const MARKER = '[vault-encrypted]';

    private int $masked = 0;

    public function mask(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (self::isVaultObject($value)) {
            $this->masked++;
            return self::MARKER;
        }

        // array_map keeps the keys of a single input array.
        return array_map(fn (mixed $item): mixed => $this->mask($item), $value);
    }

    public function maskedCount(): int
    {
        return $this->masked;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isVaultObject(array $value): bool
    {
        return count($value) === 1 && array_key_exists('__ansible_vault', $value);
    }
}
