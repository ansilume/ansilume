<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\VaultValueMasker;
use PHPUnit\Framework\TestCase;

class VaultValueMaskerTest extends TestCase
{
    public function testNestedVaultValuesAreMaskedAndCounted(): void
    {
        $masker = new VaultValueMasker();

        $masked = $masker->mask([
            'web1' => [
                'ansible_host' => '192.0.2.10',
                'db_password' => ['__ansible_vault' => "\$ANSIBLE_VAULT;1.1;AES256\n6162"],
                'nested' => ['list' => [1, ['__ansible_vault' => 'x']]],
            ],
        ]);

        $this->assertSame([
            'web1' => [
                'ansible_host' => '192.0.2.10',
                'db_password' => VaultValueMasker::MARKER,
                'nested' => ['list' => [1, VaultValueMasker::MARKER]],
            ],
        ], $masked);
        $this->assertSame(2, $masker->maskedCount());
    }

    public function testLookalikesAndScalarsStayUntouched(): void
    {
        $masker = new VaultValueMasker();
        $value = [
            'two_keys' => ['__ansible_vault' => 'x', 'other' => 1],
            'other_key' => ['__ansible_vault2' => 'x'],
            'string' => '__ansible_vault',
            'number' => 42,
            'null' => null,
        ];

        $this->assertSame($value, $masker->mask($value));
        $this->assertSame('plain', $masker->mask('plain'));
        $this->assertSame(0, $masker->maskedCount());
    }
}
