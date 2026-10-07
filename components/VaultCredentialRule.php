<?php

declare(strict_types=1);

namespace app\components;

use app\models\Credential;

/**
 * A job template carries at most one vault password. Ansilume hands
 * ansible-playbook a single --vault-password-file, and the runner used to
 * drop any further vault credential without a word.
 *
 * Works on credential descriptions in precedence order (primary first,
 * then the additional ones), as JobTemplate::credentialSnapshot() and
 * Credential::describeInOrder() return them.
 */
final class VaultCredentialRule
{
    /**
     * The vault credentials, in precedence order.
     *
     * @param list<array{id: int, name: string|null, credential_type: string|null}> $ordered
     * @return list<array{id: int, name: string|null, credential_type: string|null}>
     */
    public static function vaults(array $ordered): array
    {
        return array_values(array_filter(
            $ordered,
            static fn (array $entry): bool => $entry['credential_type'] === Credential::TYPE_VAULT
        ));
    }

    /**
     * The vault credentials after the first one: those Ansible never gets.
     *
     * @param list<array{id: int, name: string|null, credential_type: string|null}> $ordered
     * @return list<array{id: int, name: string|null, credential_type: string|null}>
     */
    public static function excess(array $ordered): array
    {
        return array_slice(self::vaults($ordered), 1);
    }

    /**
     * @param list<array{id: int, name: string|null, credential_type: string|null}> $vaults at least two
     */
    public static function conflictMessage(array $vaults): string
    {
        $verb = count($vaults) === 2 ? 'are both' : 'are all';

        return 'Only one vault password can be attached to a job template. '
            . self::names($vaults) . " {$verb} vault passwords; keep one of them.";
    }

    /**
     * "A", "A" and "B", or "A", "B" and "C".
     *
     * @param list<array{id: int, name: string|null, credential_type: string|null}> $entries
     */
    public static function names(array $entries): string
    {
        $quoted = array_map(
            static fn (array $entry): string => '"' . ($entry['name'] ?? ('Credential #' . $entry['id'])) . '"',
            $entries
        );
        $last = array_pop($quoted);

        return $quoted === [] ? (string)$last : implode(', ', $quoted) . ' and ' . $last;
    }
}
