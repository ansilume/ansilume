<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\CredentialInjectionResult;
use app\components\CredentialInjector;
use app\models\Credential;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for CredentialInjector.
 *
 * The injector converts decrypted credential data into CLI args, env vars,
 * and temp files for ansible-playbook execution. These tests exercise every
 * credential type, edge cases (empty secrets, missing fields), and cleanup.
 */
class CredentialInjectorTest extends DbTestCase
{
    private CredentialInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->injector = new CredentialInjector();
    }

    // -------------------------------------------------------------------------
    // inject() — null / empty input
    // -------------------------------------------------------------------------

    public function testInjectNullReturnsEmpty(): void
    {
        $result = $this->injector->inject(null);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->env);
        $this->assertSame([], $result->tempFiles);
    }

    public function testInjectEmptyCredentialTypeReturnsEmpty(): void
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

    public function testInjectUnknownCredentialTypeReturnsEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => 'some_future_type',
            'username' => null,
            'secrets' => ['foo' => 'bar'],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->env);
        $this->assertSame([], $result->tempFiles);
    }

    // -------------------------------------------------------------------------
    // inject() — SSH key
    // -------------------------------------------------------------------------

    public function testInjectSshKeyCreatesPrivateKeyFileAndArgs(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_SSH_KEY,
            'username' => null,
            'secrets' => ['private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----"],
        ]);

        $this->assertCount(2, $result->args);
        $this->assertSame('--private-key', $result->args[0]);
        $this->assertFileExists($result->args[1]);
        $this->assertSame([], $result->env);
        $this->assertCount(1, $result->tempFiles);
        $this->assertSame($result->args[1], $result->tempFiles[0]);

        // Verify temp file has the key content.
        $this->assertStringContainsString('OPENSSH PRIVATE KEY', (string)file_get_contents($result->tempFiles[0]));

        // Verify file permissions are 0600.
        $perms = fileperms($result->tempFiles[0]) & 0777;
        $this->assertSame(0600, $perms);

        CredentialInjector::cleanup($result->tempFiles);
        $this->assertFileDoesNotExist($result->tempFiles[0]);
    }

    public function testInjectSshKeyWithUsernameAddsUserFlag(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_SSH_KEY,
            'username' => 'deploy',
            'secrets' => ['private_key' => 'FAKE_KEY'],
        ]);

        $this->assertContains('--private-key', $result->args);
        $this->assertContains('--user', $result->args);
        $this->assertContains('deploy', $result->args);
        $this->assertCount(4, $result->args);

        CredentialInjector::cleanup($result->tempFiles);
    }

    public function testInjectSshKeyWithEmptyPrivateKeyReturnsEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_SSH_KEY,
            'username' => 'admin',
            'secrets' => ['private_key' => ''],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->tempFiles);
    }

    public function testInjectSshKeyWithMissingPrivateKeyReturnsEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_SSH_KEY,
            'username' => 'admin',
            'secrets' => [],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->tempFiles);
    }

    // -------------------------------------------------------------------------
    // inject() — Username/Password
    // -------------------------------------------------------------------------

    public function testInjectUsernamePasswordSetsEnvAndArgs(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_USERNAME_PASSWORD,
            'username' => 'operator',
            'secrets' => ['password' => 'secret123'],
        ]);

        $this->assertContains('--user', $result->args);
        $this->assertContains('operator', $result->args);
        $this->assertArrayHasKey('ANSIBLE_SSH_PASS', $result->env);
        $this->assertSame('secret123', $result->env['ANSIBLE_SSH_PASS']);
        $this->assertSame([], $result->tempFiles);
    }

    public function testInjectUsernamePasswordWithEmptyPasswordNoEnv(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_USERNAME_PASSWORD,
            'username' => 'user1',
            'secrets' => ['password' => ''],
        ]);

        $this->assertArrayNotHasKey('ANSIBLE_SSH_PASS', $result->env);
        $this->assertContains('--user', $result->args);
        $this->assertContains('user1', $result->args);
    }

    public function testInjectUsernamePasswordWithNoUsername(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_USERNAME_PASSWORD,
            'username' => null,
            'secrets' => ['password' => 'pw'],
        ]);

        $this->assertNotContains('--user', $result->args);
        $this->assertArrayHasKey('ANSIBLE_SSH_PASS', $result->env);
    }

    public function testInjectUsernamePasswordWithEmptyUsername(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_USERNAME_PASSWORD,
            'username' => '',
            'secrets' => ['password' => 'pw'],
        ]);

        $this->assertNotContains('--user', $result->args);
    }

    public function testInjectUsernamePasswordBothEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_USERNAME_PASSWORD,
            'username' => '',
            'secrets' => ['password' => ''],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->env);
        $this->assertSame([], $result->tempFiles);
    }

    // -------------------------------------------------------------------------
    // inject() — Vault
    // -------------------------------------------------------------------------

    public function testInjectVaultCreatesVaultPasswordFile(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_VAULT,
            'username' => null,
            'secrets' => ['vault_password' => 'my_vault_pw'],
        ]);

        $this->assertCount(2, $result->args);
        $this->assertSame('--vault-password-file', $result->args[0]);
        $this->assertFileExists($result->args[1]);
        $this->assertSame('my_vault_pw', file_get_contents($result->args[1]));
        $this->assertCount(1, $result->tempFiles);

        // Verify file permissions are 0600.
        $perms = fileperms($result->tempFiles[0]) & 0777;
        $this->assertSame(0600, $perms);

        CredentialInjector::cleanup($result->tempFiles);
        $this->assertFileDoesNotExist($result->tempFiles[0]);
    }

    public function testInjectVaultWithEmptyPasswordReturnsEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_VAULT,
            'username' => null,
            'secrets' => ['vault_password' => ''],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->tempFiles);
    }

    public function testInjectVaultWithMissingPasswordReturnsEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_VAULT,
            'username' => null,
            'secrets' => [],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->tempFiles);
    }

    // -------------------------------------------------------------------------
    // inject() — Token
    // -------------------------------------------------------------------------

    public function testInjectTokenSetsEnvVar(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_TOKEN,
            'username' => null,
            'secrets' => ['token' => 'tok_abc123'],
        ]);

        $this->assertSame([], $result->args);
        $this->assertArrayHasKey('ANSILUME_CREDENTIAL_TOKEN', $result->env);
        $this->assertSame('tok_abc123', $result->env['ANSILUME_CREDENTIAL_TOKEN']);
        $this->assertSame([], $result->tempFiles);
    }

    public function testInjectTokenWithEmptyTokenReturnsEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_TOKEN,
            'username' => null,
            'secrets' => ['token' => ''],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->env);
    }

    public function testInjectTokenWithMissingTokenReturnsEmpty(): void
    {
        $result = $this->injector->inject([
            'credential_type' => Credential::TYPE_TOKEN,
            'username' => null,
            'secrets' => [],
        ]);

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->env);
    }

    // -------------------------------------------------------------------------
    // cleanup()
    // -------------------------------------------------------------------------

    public function testCleanupRemovesTempFiles(): void
    {
        $file1 = tempnam(sys_get_temp_dir(), 'ansilume_test_');
        $file2 = tempnam(sys_get_temp_dir(), 'ansilume_test_');
        file_put_contents($file1, 'data1');
        file_put_contents($file2, 'data2');

        $this->assertFileExists($file1);
        $this->assertFileExists($file2);

        CredentialInjector::cleanup([$file1, $file2]);

        $this->assertFileDoesNotExist($file1);
        $this->assertFileDoesNotExist($file2);
    }

    public function testCleanupWithEmptyArrayDoesNothing(): void
    {
        // Should not throw.
        CredentialInjector::cleanup([]);
        $this->assertTrue(true);
    }

    public function testCleanupWithNonExistentFileDoesNotThrow(): void
    {
        // Should not throw even if files are already gone.
        CredentialInjector::cleanup(['/tmp/ansilume_nonexistent_' . uniqid('', true)]);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // CredentialInjectionResult::empty()
    // -------------------------------------------------------------------------

    public function testEmptyResultHasAllEmptyArrays(): void
    {
        $result = CredentialInjectionResult::empty();

        $this->assertSame([], $result->args);
        $this->assertSame([], $result->env);
        $this->assertSame([], $result->tempFiles);
    }
}
