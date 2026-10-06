<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\VaultIsolation;
use PHPUnit\Framework\TestCase;

class VaultIsolationTest extends TestCase
{
    /** @var list<VaultIsolation> */
    private array $isolations = [];

    protected function tearDown(): void
    {
        foreach ($this->isolations as $isolation) {
            $isolation->cleanup();
        }
    }

    private function isolation(?string $directory = null): VaultIsolation
    {
        return $this->isolations[] = new VaultIsolation($directory);
    }

    public function testOverridesNeutraliseEveryVaultSetting(): void
    {
        $overrides = $this->isolation()->overrides();

        $decoy = $overrides[VaultIsolation::ENV_PASSWORD_FILE];
        $this->assertFileExists($decoy);
        $this->assertSame([
            'ANSIBLE_VAULT_PASSWORD_FILE' => $decoy,
            'ANSIBLE_VAULT_IDENTITY_LIST' => $decoy,
            'ANSIBLE_ASK_VAULT_PASS' => 'False',
        ], $overrides);
    }

    public function testTheDecoyIsPrivateRandomAndNotExecutable(): void
    {
        $first = $this->isolation()->overrides()[VaultIsolation::ENV_PASSWORD_FILE];
        $second = $this->isolation()->overrides()[VaultIsolation::ENV_PASSWORD_FILE];

        $this->assertSame('600', substr(sprintf('%o', fileperms($first)), -3));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)file_get_contents($first));
        $this->assertNotSame(file_get_contents($first), file_get_contents($second));
    }

    public function testOneDecoyPerRunAndCleanupIsIdempotent(): void
    {
        $isolation = $this->isolation();
        $decoy = $isolation->overrides()[VaultIsolation::ENV_PASSWORD_FILE];

        $this->assertSame($decoy, $isolation->overrides()[VaultIsolation::ENV_IDENTITY_LIST]);
        $isolation->cleanup();
        $this->assertFileDoesNotExist($decoy);
        $isolation->cleanup();
        $this->assertFileExists($isolation->overrides()[VaultIsolation::ENV_PASSWORD_FILE], 'a new run gets a new decoy');
    }

    public function testAMissingDirectoryFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refusing to run Ansible without it');

        $this->isolation('/nonexistent/' . uniqid('', true))->overrides();
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function outputProvider(): array
    {
        return [
            'wrong password' => ['ERROR! Decryption failed (no vault secrets were found that could decrypt) on /p/group_vars/all.yml', true],
            'no secrets at all' => ['ERROR! Attempting to decrypt but no vault secrets found', true],
            'older wording' => ['Decryption failed on /p/vault.yml', true],
            'coloured' => ["\e[0;31mDecryption failed (no vault\e[0m secrets were found)", true],
            'hyperlink' => ["\e]8;;file:///p/vault.yml\e\\Decryption failed\e]8;;\e\\ (no vault secrets were found)", true],
            'wrapped by rich' => ["Decryption\nfailed (no vault\nsecrets were found that could decrypt)", true],
            'unrelated failure' => ['syntax-check[specific]: conflicting action statements', false],
            'mentions vault only' => ['Using a vault password file', false],
            'empty' => ['', false],
        ];
    }

    /**
     * @dataProvider outputProvider
     */
    public function testDecryptionFailuresAreRecognised(string $output, bool $expected): void
    {
        $this->assertSame($expected, VaultIsolation::mentionsDecryptionFailure($output));
    }
}
