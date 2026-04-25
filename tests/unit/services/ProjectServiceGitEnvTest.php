<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Credential;
use app\models\Project;
use app\services\CredentialService;
use app\services\ProjectService;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Regression tests for the env shape that ProjectService hands to the git
 * subprocess. The original "stuck on syncing" report traced back to a
 * non-writable HOME — `getenv('HOME')` resolved to `/var/www` (root-owned)
 * inside the queue worker, so SSH couldn't even write its known_hosts and
 * surfaced confusing warnings before the actual fetch.
 *
 * Pin every load-bearing flag here so that class of bug can never quietly
 * come back.
 */
class ProjectServiceGitEnvTest extends TestCase
{
    private mixed $originalCredentialService = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (\Yii::$app->has('credentialService')) {
            $this->originalCredentialService = \Yii::$app->get('credentialService');
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalCredentialService !== null) {
            \Yii::$app->set('credentialService', $this->originalCredentialService);
        }
        parent::tearDown();
    }

    public function testGitHomeConstantPointsAtWritableRuntimeDir(): void
    {
        $this->assertSame('/var/www/runtime/git-home', ProjectService::GIT_HOME);
        $this->assertNotSame('/var/www', ProjectService::GIT_HOME);
        $this->assertNotSame('/root', ProjectService::GIT_HOME);
    }

    public function testBaseGitEnvUsesGitHomeNotInheritedHome(): void
    {
        $env = $this->callBaseGitEnv();
        $this->assertSame(ProjectService::GIT_HOME, $env['HOME']);
    }

    public function testBaseGitEnvDoesNotResolveHomeFromEnvVar(): void
    {
        // Even with HOME set to a bad path in the parent process, the env we
        // hand to git must use the pinned constant. This guards against the
        // exact fpm-pool inheritance that caused the original bug.
        $previous = getenv('HOME');
        putenv('HOME=/var/www');
        try {
            $env = $this->callBaseGitEnv();
            $this->assertSame(ProjectService::GIT_HOME, $env['HOME']);
            $this->assertNotSame('/var/www', $env['HOME']);
        } finally {
            if ($previous === false) {
                putenv('HOME');
            } else {
                putenv('HOME=' . $previous);
            }
        }
    }

    public function testBaseGitEnvAlwaysSilencesInteractiveGitPrompts(): void
    {
        $env = $this->callBaseGitEnv();
        $this->assertSame('0', $env['GIT_TERMINAL_PROMPT']);
    }

    public function testBaseGitEnvAlwaysAllowsAnySafeDirectory(): void
    {
        $env = $this->callBaseGitEnv();
        $this->assertSame('1', $env['GIT_CONFIG_COUNT']);
        $this->assertSame('safe.directory', $env['GIT_CONFIG_KEY_0']);
        $this->assertSame('*', $env['GIT_CONFIG_VALUE_0']);
    }

    /**
     * @dataProvider sshOptionProvider
     */
    public function testSshUrlBuildsGitSshCommandWithRequiredOption(string $expectedFragment): void
    {
        $env = $this->buildGitEnvForSshProject();
        $this->assertArrayHasKey('GIT_SSH_COMMAND', $env);
        $this->assertStringContainsString($expectedFragment, $env['GIT_SSH_COMMAND']);
    }

    public static function sshOptionProvider(): array
    {
        return [
            'identity flag'              => ['-i '],
            'no host-key prompt'         => ['StrictHostKeyChecking=accept-new'],
            'no known_hosts write'       => ['UserKnownHostsFile=/dev/null'],
            'batch mode (no terminal)'   => ['BatchMode=yes'],
        ];
    }

    public function testSshUrlInheritsHomeFromBaseEnv(): void
    {
        $env = $this->buildGitEnvForSshProject();
        $this->assertSame(ProjectService::GIT_HOME, $env['HOME']);
    }

    public function testHttpsUrlDoesNotEmitGitSshCommand(): void
    {
        $project = $this->makeProject('https://github.com/org/repo.git', null);
        $service = new ProjectService();

        $ref = new \ReflectionMethod(ProjectService::class, 'buildGitEnv');
        $ref->setAccessible(true);
        $keyFile = null;
        $args = [$project, &$keyFile];
        $env = $ref->invokeArgs($service, $args);

        $this->assertArrayNotHasKey('GIT_SSH_COMMAND', $env);
        $this->assertSame(ProjectService::GIT_HOME, $env['HOME']);
    }

    /**
     * @return array<string, string>
     */
    private function callBaseGitEnv(): array
    {
        $service = new ProjectService();
        $ref = new \ReflectionMethod(ProjectService::class, 'baseGitEnv');
        $ref->setAccessible(true);
        return (array)$ref->invoke($service);
    }

    /**
     * @return array<string, string>
     */
    private function buildGitEnvForSshProject(): array
    {
        $cred = $this->makeCredential(Credential::TYPE_SSH_KEY);

        // Stub out CredentialService so we don't touch real key material.
        $stub = $this->createMock(CredentialService::class);
        $stub->method('getSecrets')->willReturn([
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        \Yii::$app->set('credentialService', $stub);

        $project = $this->makeProject('git@github.com:example/repo.git', $cred);
        $service = new ProjectService();

        $ref = new \ReflectionMethod(ProjectService::class, 'buildGitEnv');
        $ref->setAccessible(true);
        $keyFile = null;
        $args = [$project, &$keyFile];
        $env = $ref->invokeArgs($service, $args);

        // Cleanup any temp key file the helper materialised.
        if (is_string($keyFile) && is_file($keyFile)) {
            unlink($keyFile);
        }

        return $env;
    }

    private function makeCredential(string $type): Credential
    {
        $cred = $this->getMockBuilder(Credential::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $cred->method('attributes')->willReturn(
            ['id', 'name', 'credential_type', 'username', 'secret_data', 'created_by', 'created_at', 'updated_at'],
        );
        $cred->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($cred, [
            'id' => 1,
            'name' => 'Test SSH Credential',
            'credential_type' => $type,
            'username' => null,
            'secret_data' => 'encrypted',
            'created_by' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return $cred;
    }

    private function makeProject(string $scmUrl, ?Credential $credential): Project
    {
        $project = $this->getMockBuilder(Project::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $project->method('attributes')->willReturn(
            ['id', 'name', 'scm_type', 'scm_url', 'scm_branch', 'scm_credential_id',
             'local_path', 'status', 'last_synced_at', 'sync_started_at', 'last_sync_error',
             'last_sync_event', 'created_by', 'created_at', 'updated_at'],
        );
        $project->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($project, [
            'id' => 1,
            'name' => 'Test',
            'scm_type' => Project::SCM_TYPE_GIT,
            'scm_url' => $scmUrl,
            'scm_branch' => 'main',
            'scm_credential_id' => $credential?->id,
            'local_path' => null,
            'status' => Project::STATUS_NEW,
            'last_synced_at' => null,
            'sync_started_at' => null,
            'last_sync_error' => null,
            'last_sync_event' => null,
            'created_by' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        if ($credential !== null) {
            $relRef = new \ReflectionProperty(BaseActiveRecord::class, '_related');
            $relRef->setAccessible(true);
            $relRef->setValue($project, ['scmCredential' => $credential]);
        }
        return $project;
    }
}
