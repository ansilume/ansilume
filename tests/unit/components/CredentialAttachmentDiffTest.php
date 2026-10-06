<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\CredentialAttachmentDiff;
use PHPUnit\Framework\TestCase;

class CredentialAttachmentDiffTest extends TestCase
{
    private const LABELS = [
        1 => ['name' => 'ssh', 'credential_type' => 'ssh_key'],
        2 => ['name' => 'vault', 'credential_type' => 'vault'],
        3 => ['name' => 'token', 'credential_type' => 'token'],
    ];

    public function testAttachedDetachedAndPrimaryChangesAreReported(): void
    {
        $diff = CredentialAttachmentDiff::between([1, 2], [1, 3], 1, 3);

        $this->assertFalse($diff->isEmpty());
        $this->assertSame([3, 2, 1], $diff->involvedIds());
        $this->assertSame([
            'attached' => [['id' => 3, 'name' => 'token', 'credential_type' => 'token']],
            'detached' => [['id' => 2, 'name' => 'vault', 'credential_type' => 'vault']],
            'primary' => ['from' => 1, 'to' => 3],
        ], $diff->toAuditArray(self::LABELS));
    }

    public function testAPureReorderIsNoChange(): void
    {
        $diff = CredentialAttachmentDiff::between([1, 2, 3], [3, 1, 2], 1, 1);

        $this->assertTrue($diff->isEmpty());
        $this->assertSame([], $diff->toAuditArray(self::LABELS));
    }

    public function testUnknownLabelsStayEmptyAndNullPrimariesAreSkipped(): void
    {
        $diff = CredentialAttachmentDiff::between([], [9], null, null);

        $this->assertSame([9], $diff->involvedIds());
        $this->assertSame(['attached' => [['id' => 9, 'name' => '', 'credential_type' => '']]], $diff->toAuditArray(self::LABELS));
    }
}
