<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\CredentialInjector;
use app\models\Credential;
use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * Runs real ansible to prove that the SSH password of a username/password
 * credential reaches the ssh connection plugin.
 *
 * The target is a documentation address (192.0.2.1) that is never reached.
 * With password_mechanism=sshpass and no sshpass binary, ansible reports the
 * missing sshpass program only when it received a password, before it tries
 * to connect. That makes the hand-over observable without an SSH server.
 */
class CredentialInjectorAnsibleTest extends TestCase
{
    private const SSHPASS_MISSING = 'you must install the sshpass program';

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->hasBinary('ansible')) {
            $this->markTestSkipped('ansible is not installed');
        }
        if ($this->hasBinary('sshpass')) {
            $this->markTestSkipped('sshpass is installed, so the missing-sshpass check cannot be observed');
        }
        $this->home = sys_get_temp_dir() . '/ansilume-injector-' . uniqid('', true);
        mkdir($this->home, 0o700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->home)) {
            FileHelper::removeDirectory($this->home);
        }
        parent::tearDown();
    }

    public function testTheSshConnectionReceivesThePassword(): void
    {
        $result = (new CredentialInjector())->inject([
            'credential_type' => Credential::TYPE_USERNAME_PASSWORD,
            'username' => 'deploy',
            'secrets' => ['password' => 'not-a-real-password'],
        ]);
        try {
            $output = $this->ping($result->args, $result->env);
        } finally {
            CredentialInjector::cleanup($result->tempFiles);
        }

        $this->assertStringContainsString(self::SSHPASS_MISSING, $output);
    }

    /**
     * Why the fix was needed: a password only in ANSIBLE_SSH_PASS never
     * reaches the connection plugin.
     */
    public function testAPasswordOnlyInAnsibleSshPassIsIgnored(): void
    {
        $output = $this->ping(['--user', 'deploy'], ['ANSIBLE_SSH_PASS' => 'not-a-real-password']);

        $this->assertStringNotContainsString(self::SSHPASS_MISSING, $output);
        $this->assertStringContainsString('UNREACHABLE', $output);
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     */
    private function ping(array $args, array $env): string
    {
        $process = proc_open(
            ['ansible', 'all', '-i', '192.0.2.1,', '-m', 'ping', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->home,
            $env + [
                'PATH' => (string)getenv('PATH'),
                'HOME' => $this->home,
                'ANSIBLE_LOCAL_TEMP' => $this->home . '/tmp',
                'ANSIBLE_TIMEOUT' => '2',
                'ANSIBLE_HOST_KEY_CHECKING' => 'False',
                'ANSIBLE_SSH_PASSWORD_MECHANISM' => 'sshpass',
            ]
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }

    private function hasBinary(string $name): bool
    {
        foreach (explode(':', (string)getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable($dir . '/' . $name)) {
                return true;
            }
        }

        return false;
    }
}
