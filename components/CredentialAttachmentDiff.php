<?php

declare(strict_types=1);

namespace app\components;

/**
 * Which credentials a job template change attached or detached, for the
 * audit log. A pure reorder is not a change.
 */
final class CredentialAttachmentDiff
{
    /**
     * @param list<int> $attached
     * @param list<int> $detached
     */
    private function __construct(
        private readonly array $attached,
        private readonly array $detached,
        private readonly ?int $primaryBefore,
        private readonly ?int $primaryAfter,
    ) {
    }

    /**
     * @param list<int> $before credential ids before the change
     * @param list<int> $after credential ids after the change
     */
    public static function between(array $before, array $after, ?int $primaryBefore, ?int $primaryAfter): self
    {
        return new self(
            array_values(array_diff($after, $before)),
            array_values(array_diff($before, $after)),
            $primaryBefore,
            $primaryAfter,
        );
    }

    public function isEmpty(): bool
    {
        return $this->attached === [] && $this->detached === [] && $this->primaryBefore === $this->primaryAfter;
    }

    /**
     * @return list<int>
     */
    public function involvedIds(): array
    {
        $ids = array_merge($this->attached, $this->detached, array_filter([$this->primaryBefore, $this->primaryAfter]));

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, array{name: string, credential_type: string}> $labels by credential id
     * @return array{attached?: list<array{id: int, name: string, credential_type: string}>, detached?: list<array{id: int, name: string, credential_type: string}>, primary?: array{from: int|null, to: int|null}}
     */
    public function toAuditArray(array $labels): array
    {
        $describe = static fn (int $id): array => [
            'id' => $id,
            'name' => $labels[$id]['name'] ?? '',
            'credential_type' => $labels[$id]['credential_type'] ?? '',
        ];
        $audit = [];
        if ($this->attached !== []) {
            $audit['attached'] = array_map($describe, $this->attached);
        }
        if ($this->detached !== []) {
            $audit['detached'] = array_map($describe, $this->detached);
        }
        if ($this->primaryBefore !== $this->primaryAfter) {
            $audit['primary'] = ['from' => $this->primaryBefore, 'to' => $this->primaryAfter];
        }

        return $audit;
    }
}
