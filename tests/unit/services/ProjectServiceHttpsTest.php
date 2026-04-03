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
 * Tests for ProjectService HTTPS credential env var construction.
 *
 * Tests the private applyHttpsCredentialEnv method via reflection,
 * with stubbed credential resolution (no DB required).
 */
class ProjectServiceHttpsTest extends TestCase
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

    /**
     * @param array<string, string> $env
     * @param array<string, string> $secrets
     */
    private function applyHttpsEnv(
        Project $project,
        array &$env,
        ?Credential $credential,
        array $secrets,
    ): void {
        $credService = $this->createMock(CredentialService::class);
        $credService->method('getSecrets')->willReturn($secrets);
        \Yii::$app->set('credentialService', $credService);

        // Wire credential into the project's _related cache
        if ($credential !== null) {
            $relRef = new \ReflectionProperty(BaseActiveRecord::class, '_related');
            $relRef->setAccessible(true);
            $relRef->setValue($project, ['scmCredential' => $credential]);
        }

        $service = new ProjectService();
        $ref = new \ReflectionMethod(ProjectService::class, 'applyHttpsCredentialEnv');
        $ref->setAccessible(true);
        $args = [$project, &$env];
        $ref->invokeArgs($service, $args);
    }

    private function makeCredential(string $type, ?string $username = null): Credential
    {
        $cred = $this->getMockBuilder(Credential::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $cred->method('attributes')->willReturn(
            ['id', 'name', 'credential_type', 'username', 'secret_data', 'created_by', 'created_at', 'updated_at']
        );
        $cred->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($cred, [
            'id' => 1,
            'name' => 'Test Credential',
            'credential_type' => $type,
            'username' => $username,
            'secret_data' => 'encrypted',
            'created_by' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return $cred;
    }

    private function makeProject(?int $credentialId = null): Project
    {
        $project = $this->getMockBuilder(Project::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $project->method('attributes')->willReturn(
            ['id', 'name', 'scm_type', 'scm_url', 'scm_branch', 'scm_credential_id',
             'local_path', 'status', 'last_synced_at', 'last_sync_error',
             'created_by', 'created_at', 'updated_at']
        );
        $project->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($project, [
            'id' => 1,
            'name' => 'Test',
            'scm_type' => Project::SCM_TYPE_GIT,
            'scm_url' => 'https://github.com/org/repo.git',
            'scm_branch' => 'main',
            'scm_credential_id' => $credentialId,
            'local_path' => null,
            'status' => Project::STATUS_NEW,
            'last_synced_at' => null,
            'last_sync_error' => null,
            'created_by' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return $project;
    }

    // ── Token credentials ────────────────────────────────────────────────────

    public function testTokenCredentialSetsCredentialHelper(): void
    {
        $cred = $this->makeCredential(Credential::TYPE_TOKEN, 'x-access-token');
        $project = $this->makeProject(1);
        $env = ['GIT_CONFIG_COUNT' => '1'];

        $this->applyHttpsEnv($project, $env, $cred, ['token' => 'ghp_abc123']);

        $this->assertSame('2', $env['GIT_CONFIG_COUNT']);
        $this->assertSame('credential.helper', $env['GIT_CONFIG_KEY_1']);
        $this->assertStringContainsString('username=x-access-token', $env['GIT_CONFIG_VALUE_1']);
        $this->assertStringContainsString('password=ghp_abc123', $env['GIT_CONFIG_VALUE_1']);
    }

    public function testTokenCredentialDefaultsUsernameToXAccessToken(): void
    {
        $cred = $this->makeCredential(Credential::TYPE_TOKEN, null);
        $project = $this->makeProject(1);
        $env = ['GIT_CONFIG_COUNT' => '1'];

        $this->applyHttpsEnv($project, $env, $cred, ['token' => 'my-token']);

        $this->assertStringContainsString('username=x-access-token', $env['GIT_CONFIG_VALUE_1']);
        $this->assertStringContainsString('password=my-token', $env['GIT_CONFIG_VALUE_1']);
    }

    // ── Username/Password credentials ────────────────────────────────────────

    public function testUsernamePasswordCredentialSetsCredentialHelper(): void
    {
        $cred = $this->makeCredential(Credential::TYPE_USERNAME_PASSWORD, 'deploy');
        $project = $this->makeProject(1);
        $env = ['GIT_CONFIG_COUNT' => '1'];

        $this->applyHttpsEnv($project, $env, $cred, ['password' => 's3cret']);

        $this->assertSame('2', $env['GIT_CONFIG_COUNT']);
        $this->assertStringContainsString('username=deploy', $env['GIT_CONFIG_VALUE_1']);
        $this->assertStringContainsString('password=s3cret', $env['GIT_CONFIG_VALUE_1']);
    }

    // ── No credential ────────────────────────────────────────────────────────

    public function testNoCredentialDoesNotSetHelper(): void
    {
        $project = $this->makeProject(null);
        $env = ['GIT_CONFIG_COUNT' => '1'];

        $this->applyHttpsEnv($project, $env, null, []);

        $this->assertSame('1', $env['GIT_CONFIG_COUNT']);
        $this->assertArrayNotHasKey('GIT_CONFIG_KEY_1', $env);
    }

    // ── Empty secrets ────────────────────────────────────────────────────────

    public function testEmptyTokenDoesNotSetHelper(): void
    {
        $cred = $this->makeCredential(Credential::TYPE_TOKEN, 'bot');
        $project = $this->makeProject(1);
        $env = ['GIT_CONFIG_COUNT' => '1'];

        $this->applyHttpsEnv($project, $env, $cred, ['token' => '']);

        $this->assertSame('1', $env['GIT_CONFIG_COUNT']);
    }

    public function testEmptyPasswordDoesNotSetHelper(): void
    {
        $cred = $this->makeCredential(Credential::TYPE_USERNAME_PASSWORD, 'deploy');
        $project = $this->makeProject(1);
        $env = ['GIT_CONFIG_COUNT' => '1'];

        $this->applyHttpsEnv($project, $env, $cred, ['password' => '']);

        $this->assertSame('1', $env['GIT_CONFIG_COUNT']);
    }

    public function testEmptyUsernameOnPasswordCredDoesNotSetHelper(): void
    {
        $cred = $this->makeCredential(Credential::TYPE_USERNAME_PASSWORD, '');
        $project = $this->makeProject(1);
        $env = ['GIT_CONFIG_COUNT' => '1'];

        $this->applyHttpsEnv($project, $env, $cred, ['password' => 'secret']);

        $this->assertSame('1', $env['GIT_CONFIG_COUNT']);
    }
}
