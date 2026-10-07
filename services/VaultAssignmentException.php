<?php

declare(strict_types=1);

namespace app\services;

/**
 * A bulk vault assignment that was rejected before anything was written:
 * HTTP-style status (422 or 403) and, where it applies, the job template ids
 * the problem is about.
 */
final class VaultAssignmentException extends \RuntimeException
{
    /**
     * @param list<int> $templateIds
     */
    public function __construct(string $message, public readonly int $status, public readonly array $templateIds = [])
    {
        parent::__construct($message);
    }
}
