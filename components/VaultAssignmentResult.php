<?php

declare(strict_types=1);

namespace app\components;

/**
 * Outcome of assigning one vault password to several job templates, one
 * entry per template in request order.
 */
final class VaultAssignmentResult
{
    public const ASSIGNED = 'assigned';
    public const REPLACED = 'replaced';
    public const UNCHANGED = 'unchanged';
    public const FAILED = 'failed';
    public const STATUSES = [self::ASSIGNED, self::REPLACED, self::UNCHANGED, self::FAILED];

    /**
     * @param list<array{job_template_id: int, name: string, status: string, replaced: list<array{id: int, name: string}>, error: string|null}> $items
     */
    public function __construct(
        public readonly int $credentialId,
        public readonly string $credentialName,
        public readonly array $items,
    ) {
    }

    /**
     * @return array<string, int> status => number of templates
     */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->items as $item) {
            $counts[$item['status']]++;
        }

        return $counts;
    }

    public function summary(): string
    {
        $counts = $this->counts();
        $parts = [];
        if ($counts[self::ASSIGNED] > 0) {
            $parts[] = "assigned to {$counts[self::ASSIGNED]} job template(s)";
        }
        if ($counts[self::REPLACED] > 0) {
            $parts[] = "replaced another vault password on {$counts[self::REPLACED]}";
        }
        if ($counts[self::UNCHANGED] > 0) {
            $parts[] = "{$counts[self::UNCHANGED]} already had it";
        }
        $failed = array_values(array_filter($this->items, static fn (array $item): bool => $item['status'] === self::FAILED));
        if ($failed !== []) {
            $parts[] = count($failed) . ' failed: ' . implode('; ', array_map(
                static fn (array $item): string => '"' . $item['name'] . '" (' . $item['error'] . ')',
                $failed
            ));
        }

        return "Vault password \"{$this->credentialName}\": " . implode(', ', $parts) . '.';
    }

    /**
     * @return array{credential_id: int, credential_name: string, results: list<array{job_template_id: int, name: string, status: string, replaced: list<array{id: int, name: string}>, error: string|null}>, summary: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'credential_id' => $this->credentialId,
            'credential_name' => $this->credentialName,
            'results' => $this->items,
            'summary' => $this->counts(),
        ];
    }
}
