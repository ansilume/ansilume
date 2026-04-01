<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\CredentialInjector;
use app\models\Credential;
use PHPUnit\Framework\TestCase;

class CredentialInjectorTest extends TestCase
{
    private CredentialInjector $injector;

    protected function setUp(): void
    {
        $this->injector = new CredentialInjector();
    }

    protected function tearDown(): void
    {
        // Clean up any temp files left by failed tests
        foreach (glob(sys_get_temp_dir() . '/ansilume_key_*') as $f) {
            unlink($f);
        }
        foreach (glob(sys_get_temp_dir() . '/ansilume_vault_*') as $f) {
            unlink($f);
        }
    }

    // ── Null / empty / unknown ──────────────────────────────────────────

    public function testInjectNullReturnsEmpty(): void
    {
        $result = $this->injector->inject(null);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->env);
        $this->assertSame([], $result->tempFiles);
    }

    public function testInjectEmptyArrayReturnsEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => '',
            'username' => null,
            'secrets' => [],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->env);
        $this->assertSame([], $result->tempFiles);
    }

    public function testInjectUnknownTypeReturnsEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => 'unknown_type',
            'username' => null,
            'secrets' => ['foo' => 'bar'],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->env);
        $this->assertSame([], $result->tempFiles);
    }

    // ── SSH Key ─────────────────────────────────────────────────────────

    public function testSshKeyCreatesPrivateKeyFile(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_SSH_KEY,
            'username' => null,
            'secrets' => ['private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----"],
        ]);

        $this->assertCount(2, $result->args);
        $this->assertSame('--private-key', $result->args[0]);
        $this->assertFileExists($result->args[1]);
        $this->assertStringContainsString('-----BEGIN OPENSSH PRIVATE KEY-----', file_get_contents($result->args[1]));
        $this->assertSame([], $result->env);
        $this->assertCount(1, $result->tempFiles);
        $this->assertSame($result->args[1], $result->tempFiles[0]);

        CredentialInjector::cleanup($result->tempFiles);
    }

    public function testSshKeyFileHasRestrictedPermissions(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_SSH_KEY,
            'username' => null,
            'secrets' => ['private_key' => 'test-key-content'],
        ]);

        $perms = fileperms($result->tempFiles[0]) & 0777;
        $this->assertSame(0600, $perms);

        CredentialInjector::cleanup($result->tempFiles);
    }

    public function testSshKeyWithUsernameAddsUserFlag(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_SSH_KEY,
            'username' => 'deploy',
            'secrets' => ['private_key' => 'test-key'],
        ]);

        $this->assertSame('--private-key', $result->args[0]);
        $this->assertSame('--user', $result->args[2]);
        $this->assertSame('deploy', $result->args[3]);

        CredentialInjector::cleanup($result->tempFiles);
    }

    public function testSshKeyWithoutUsernameOmitsUserFlag(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_SSH_KEY,
            'username' => null,
            'secrets' => ['private_key' => 'test-key'],
        ]);

        $this->assertNotContains('--user', $result->args);

        CredentialInjector::cleanup($result->tempFiles);
    }

    public function testSshKeyWithEmptyPrivateKeyReturnsEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_SSH_KEY,
            'username' => 'deploy',
            'secrets' => ['private_key' => ''],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->tempFiles);
    }

    public function testSshKeyWithMissingPrivateKeyReturnsEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_SSH_KEY,
            'username' => null,
            'secrets' => [],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->tempFiles);
    }

    // ── Username/Password ───────────────────────────────────────────────

    public function testUsernamePasswordSetsEnvVar(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_USERNAME_PASSWORD,
            'username' => null,
            'secrets' => ['password' => 's3cret'],
        ]);

        $this->assertSame('s3cret', $result->env['ANSIBLE_SSH_PASS']);
        $this->assertSame([], $result->tempFiles);
    }

    public function testUsernamePasswordWithUsernameSetsUserFlag(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_USERNAME_PASSWORD,
            'username' => 'admin',
            'secrets' => ['password' => 'pass'],
        ]);

        $this->assertContains('--user', $result->args);
        $this->assertContains('admin', $result->args);
        $this->assertSame('pass', $result->env['ANSIBLE_SSH_PASS']);
    }

    public function testUsernamePasswordWithoutPasswordSetsNoEnv(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_USERNAME_PASSWORD,
            'username' => 'admin',
            'secrets' => [],
        ]);

        $this->assertArrayNotHasKey('ANSIBLE_SSH_PASS', $result->env);
        $this->assertContains('--user', $result->args);
    }

    public function testUsernamePasswordWithEmptyUsernameOmitsUserFlag(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_USERNAME_PASSWORD,
            'username' => null,
            'secrets' => ['password' => 'pass'],
        ]);

        $this->assertNotContains('--user', $result->args);
    }

    // ── Vault ───────────────────────────────────────────────────────────

    public function testVaultCreatesPasswordFile(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_VAULT,
            'username' => null,
            'secrets' => ['vault_password' => 'vault-secret'],
        ]);

        $this->assertSame('--vault-password-file', $result->args[0]);
        $this->assertFileExists($result->args[1]);
        $this->assertSame('vault-secret', file_get_contents($result->args[1]));
        $this->assertCount(1, $result->tempFiles);

        CredentialInjector::cleanup($result->tempFiles);
    }

    public function testVaultFileHasRestrictedPermissions(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_VAULT,
            'username' => null,
            'secrets' => ['vault_password' => 'vault-secret'],
        ]);

        $perms = fileperms($result->tempFiles[0]) & 0777;
        $this->assertSame(0600, $perms);

        CredentialInjector::cleanup($result->tempFiles);
    }

    public function testVaultWithEmptyPasswordReturnsEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_VAULT,
            'username' => null,
            'secrets' => ['vault_password' => ''],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->tempFiles);
    }

    // ── Token ───────────────────────────────────────────────────────────

    public function testTokenSetsEnvVar(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_TOKEN,
            'username' => null,
            'secrets' => ['token' => 'api-key-123'],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame('api-key-123', $result->env['ANSILUME_CREDENTIAL_TOKEN']);
        $this->assertSame([], $result->tempFiles);
    }

    public function testTokenWithEmptyTokenReturnsEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_TOKEN,
            'username' => null,
            'secrets' => ['token' => ''],
        ]);

        $this->assertSame([], $result->env);
    }

    // ── Cleanup helper ──────────────────────────────────────────────────

    public function testCleanupRemovesTempFiles(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_SSH_KEY,
            'username' => null,
            'secrets' => ['private_key' => 'test-key'],
        ]);

        $this->assertFileExists($result->tempFiles[0]);
        CredentialInjector::cleanup($result->tempFiles);
        $this->assertFileDoesNotExist($result->tempFiles[0]);
    }

    public function testCleanupHandlesNonexistentFiles(): void
    {
        // Should not throw
        CredentialInjector::cleanup(['/tmp/nonexistent_ansilume_test_file']);
        $this->assertTrue(true);
    }
}
