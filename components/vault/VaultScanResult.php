<?php

declare(strict_types=1);

namespace app\components\vault;

/**
 * What VaultContentScanner found in a checkout.
 */
final class VaultScanResult
{
    /**
     * @param list<VaultScanEntry> $entries sorted by path, then line
     * @param bool $truncated the scan stopped at a file, entry or time limit
     * @param int $filesScanned regular files looked at, read or skipped
     */
    public function __construct(
        public readonly array $entries,
        public readonly bool $truncated,
        public readonly int $filesScanned,
    ) {
    }
}
