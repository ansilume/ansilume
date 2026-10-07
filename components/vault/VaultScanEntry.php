<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * One piece of vault content found in a checkout: a whole encrypted file or
 * one inline `!vault` value. Holds what the scan stores about it, never the
 * encrypted content itself (a scan of many large vaults would otherwise keep
 * all of it in memory) and never plaintext.
 */
final class VaultScanEntry
{
    public const KIND_FILE = 'file';
    public const KIND_INLINE = 'inline';

    /**
     * @param string $path relative to the checkout root, '/' separated, no leading './'
     * @param string $kind KIND_FILE or KIND_INLINE
     * @param int|null $line 1-based line of the `!vault` tag (inline only)
     * @param string|null $key mapping key of the value, null for a list item (inline only)
     * @param string|null $version format version from the header, e.g. '1.1'; null when Ansible cannot read the vault
     * @param string|null $vaultId vault ID from the header (format 1.2), '' when present but empty
     * @param string|null $fingerprint VaultEnvelope::fingerprint(); null when Ansible cannot read the vault
     * @param string|null $error why Ansible cannot read it, null when it can
     */
    public function __construct(
        public readonly string $path,
        public readonly string $kind,
        public readonly ?int $line,
        public readonly ?string $key,
        public readonly ?string $version,
        public readonly ?string $vaultId,
        public readonly ?string $fingerprint,
        public readonly ?string $error,
    ) {
    }
}
