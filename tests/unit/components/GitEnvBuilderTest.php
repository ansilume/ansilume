<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\GitEnvBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Direct unit coverage for GitEnvBuilder. The RunnerControllerTest also
 * exercises the same behaviour end-to-end through RunnerController's
 * delegate, but these tests keep the helper self-testable and pin the
 * exact SSH option set that the runner relies on.
 */
class GitEnvBuilderTest extends TestCase
{
    public function testBaseEnvAlwaysContainsSafeDirectoryConfig(): void
    {
        $builder = new GitEnvBuilder();
        $sshKeyFile = null;
        $env = $builder->build('', null, $sshKeyFile);

        $this->assertSame('0', $env['GIT_TERMINAL_PROMPT']);
        $this->assertSame('safe.directory', $env['GIT_CONFIG_KEY_0']);
        $this->assertSame('*', $env['GIT_CONFIG_VALUE_0']);
        $this->assertNull($sshKeyFile);
    }

    /**
     * Regression: the runner used to inherit HOME from `getenv('HOME')`,
     * which on the www-data fpm pool resolves to `/var/www` — root-owned,
     * not writable. Anything that probed `~/.gitconfig` or SSH's
     * `~/.ssh/known_hosts` then failed with EACCES. Pin the writable
     * location so this can never come back.
     */
    public function testBaseEnvHomePointsAtWritableRuntimeDirectory(): void
    {
        $builder = new GitEnvBuilder();
        $sshKeyFile = null;
        $env = $builder->build('', null, $sshKeyFile);

        $this->assertSame(GitEnvBuilder::GIT_HOME, $env['HOME']);
        $this->assertSame('/var/www/runtime/git-home', $env['HOME']);
        $this->assertNotSame('/var/www', $env['HOME']);
        $this->assertNotSame('/root', $env['HOME']);
    }

    public function testBaseEnvSetsPath(): void
    {
        $builder = new GitEnvBuilder();
        $sshKeyFile = null;
        $env = $builder->build('', null, $sshKeyFile);
        $this->assertArrayHasKey('PATH', $env);
        $this->assertNotSame('', $env['PATH']);
    }

    public function testSshUrlWithSshKeyCredentialWritesKeyAndSetsGitSshCommand(): void
    {
        $builder = new GitEnvBuilder();
        $sshKeyFile = null;
        $env = $builder->build(
            'git@github.com:example/repo.git',
            [
                'credential_type' => 'ssh_key',
                'username' => 'git',
                'env_var_name' => null,
                'secrets' => ['private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n"],
            ],
            $sshKeyFile,
        );

        try {
            $this->assertArrayHasKey('GIT_SSH_COMMAND', $env);
            // Regression: the four options that together make batch-mode SSH
            // work against a previously-unknown host with a single key.
            // UserKnownHostsFile=/dev/null is the load-bearing one — without
            // it, ssh tries to update a known_hosts under HOME and fails
            // with "Permission denied" on a non-writable home directory.
            $this->assertStringContainsString('-i ', $env['GIT_SSH_COMMAND']);
            $this->assertStringContainsString('StrictHostKeyChecking=accept-new', $env['GIT_SSH_COMMAND']);
            $this->assertStringContainsString('UserKnownHostsFile=/dev/null', $env['GIT_SSH_COMMAND']);
            $this->assertStringContainsString('BatchMode=yes', $env['GIT_SSH_COMMAND']);
            $this->assertNotNull($sshKeyFile);
            $this->assertFileExists($sshKeyFile);
            $this->assertSame('0600', substr(sprintf('%o', fileperms($sshKeyFile)), -4));
        } finally {
            if ($sshKeyFile !== null && is_file($sshKeyFile)) {
                unlink($sshKeyFile);
            }
        }
    }

    public function testSshUrlWithEmptyPrivateKeyYieldsNoGitSshCommand(): void
    {
        $builder = new GitEnvBuilder();
        $sshKeyFile = null;
        $env = $builder->build(
            'git@github.com:example/repo.git',
            [
                'credential_type' => 'ssh_key',
                'username' => 'git',
                'env_var_name' => null,
                'secrets' => ['private_key' => ''],
            ],
            $sshKeyFile,
        );

        $this->assertArrayNotHasKey('GIT_SSH_COMMAND', $env);
        $this->assertNull($sshKeyFile);
    }

    public function testHttpsUrlWithTokenCredentialDefaultsUsernameToXAccessToken(): void
    {
        $builder = new GitEnvBuilder();
        $sshKeyFile = null;
        $env = $builder->build(
            'https://github.com/example/repo.git',
            [
                'credential_type' => 'token',
                'username' => null,
                'env_var_name' => null,
                'secrets' => ['token' => 'ghp_fake'],
            ],
            $sshKeyFile,
        );

        $this->assertNull($sshKeyFile);
        $helperIndex = $this->findCredentialHelperIndex($env);
        $this->assertNotNull($helperIndex);
        $this->assertStringContainsString('username=x-access-token', $env['GIT_CONFIG_VALUE_' . $helperIndex]);
        $this->assertStringContainsString('password=ghp_fake', $env['GIT_CONFIG_VALUE_' . $helperIndex]);
    }

    public function testHttpsUrlWithUsernamePasswordCredentialUsesProvidedUsername(): void
    {
        $builder = new GitEnvBuilder();
        $sshKeyFile = null;
        $env = $builder->build(
            'https://gitlab.example/team/repo.git',
            [
                'credential_type' => 'username_password',
                'username' => 'deploy-bot',
                'env_var_name' => null,
                'secrets' => ['password' => 'sekret'],
            ],
            $sshKeyFile,
        );

        $helperIndex = $this->findCredentialHelperIndex($env);
        $this->assertNotNull($helperIndex);
        $this->assertStringContainsString('username=deploy-bot', $env['GIT_CONFIG_VALUE_' . $helperIndex]);
        $this->assertStringContainsString('password=sekret', $env['GIT_CONFIG_VALUE_' . $helperIndex]);
    }

    public function testHttpsUrlWithoutTokenOrPasswordDoesNotInjectHelper(): void
    {
        $builder = new GitEnvBuilder();
        $sshKeyFile = null;
        $env = $builder->build(
            'https://github.com/example/repo.git',
            [
                'credential_type' => 'token',
                'username' => null,
                'env_var_name' => null,
                'secrets' => [], // no token
            ],
            $sshKeyFile,
        );

        $this->assertNull($this->findCredentialHelperIndex($env));
    }

    public function testIsSshUrlRecognisesScpAndSshSchemes(): void
    {
        $builder = new GitEnvBuilder();
        $this->assertTrue($builder->isSshUrl('git@github.com:foo/bar.git'));
        $this->assertTrue($builder->isSshUrl('ssh://git@host.example/foo.git'));
        $this->assertFalse($builder->isSshUrl('https://github.com/foo.git'));
        $this->assertFalse($builder->isSshUrl('http://example.com/foo.git'));
        $this->assertFalse($builder->isSshUrl('/local/path'));
    }

    public function testIsHttpsUrlRecognisesBothHttpSchemes(): void
    {
        $builder = new GitEnvBuilder();
        $this->assertTrue($builder->isHttpsUrl('https://github.com/foo.git'));
        $this->assertTrue($builder->isHttpsUrl('http://example.com/foo.git'));
        $this->assertFalse($builder->isHttpsUrl('git@github.com:foo/bar.git'));
    }

    /**
     * @param array<string, string> $env
     */
    private function findCredentialHelperIndex(array $env): ?int
    {
        $count = (int)($env['GIT_CONFIG_COUNT'] ?? '0');
        for ($i = 0; $i < $count; $i++) {
            if (($env['GIT_CONFIG_KEY_' . $i] ?? '') === 'credential.helper') {
                return $i;
            }
        }
        return null;
    }
}
