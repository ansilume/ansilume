<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Credential;
use app\models\Job;
use app\services\CredentialResolutionException;
use app\services\JobCredentialResolver;
use app\tests\integration\DbTestCase;

class JobCredentialResolverTest extends DbTestCase
{
    private JobCredentialResolver $resolver;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new JobCredentialResolver();
        $this->userId = (int)$this->createUser('resolver')->id;
    }

    /**
     * @param array<string, string> $secrets
     */
    private function credential(string $type, array $secrets): Credential
    {
        $credential = $this->createCredential($this->userId, $type);
        $credential->secret_data = \Yii::$app->get('credentialService')->encryptSecrets($secrets);
        $credential->save(false);

        return $credential;
    }

    public function testIdsComeInPrecedenceOrderWithThePrimaryFirst(): void
    {
        $this->assertSame([5, 9, 7], JobCredentialResolver::credentialIds(['credential_id' => 5, 'credential_ids' => [9, 5, '7', 9, 0, 'x']]));
        $this->assertSame([3], JobCredentialResolver::credentialIds(['credential_id' => '3']));
        $this->assertSame([], JobCredentialResolver::credentialIds([]));
    }

    public function testCredentialsResolveInOrder(): void
    {
        $primary = $this->credential(Credential::TYPE_SSH_KEY, ['private_key' => 'k']);
        $vault = $this->credential(Credential::TYPE_VAULT, ['vault_password' => 'v']);

        $resolved = $this->resolver->resolveTemplateCredentials(['credential_id' => $primary->id, 'credential_ids' => [$vault->id, $primary->id]]);

        $this->assertSame(['private_key' => 'k'], $resolved['credential']['secrets'] ?? null);
        $this->assertSame([Credential::TYPE_SSH_KEY, Credential::TYPE_VAULT], array_column($resolved['credentials'], 'credential_type'));
        $this->assertSame(['credential' => null, 'credentials' => []], $this->resolver->resolveTemplateCredentials([]));
    }

    /**
     * Regression: a credential deleted after the launch was skipped, and the
     * job ran without it (for example without its vault password).
     */
    public function testADeletedCredentialAbortsTheJob(): void
    {
        $primary = $this->credential(Credential::TYPE_SSH_KEY, ['private_key' => 'k']);
        $gone = $this->credential(Credential::TYPE_VAULT, ['vault_password' => 'v']);
        $goneId = (int)$gone->id;
        $gone->delete();
        $payload = [
            'credential_id' => $primary->id,
            'credential_ids' => [$primary->id, $goneId],
            Job::PAYLOAD_CREDENTIAL_SNAPSHOT => [['id' => $goneId, 'name' => 'prod-vault', 'credential_type' => 'vault', 'role' => 'additional']],
        ];

        try {
            $this->resolver->resolveTemplateCredentials($payload);
            $this->fail('a deleted credential must abort the job');
        } catch (CredentialResolutionException $e) {
            $this->assertSame([[
                'id' => $goneId,
                'name' => 'prod-vault',
                'role' => Credential::ROLE_ADDITIONAL,
                'reason' => CredentialResolutionException::REASON_MISSING,
            ]], $e->failures);
            $this->assertStringContainsString('"prod-vault" (additional) no longer exists', $e->getMessage());
        }
    }

    public function testAnUndecryptableCredentialAbortsTheJob(): void
    {
        $broken = $this->createCredential($this->userId, Credential::TYPE_TOKEN);
        $broken->secret_data = base64_encode(random_bytes(64));
        $broken->save(false);

        $this->expectException(CredentialResolutionException::class);
        $this->expectExceptionMessage('cannot be decrypted');

        $this->resolver->resolveTemplateCredentials(['credential_id' => $broken->id]);
    }

    public function testACredentialWithoutStoredSecretStillResolves(): void
    {
        $legacy = $this->createCredential($this->userId, Credential::TYPE_USERNAME_PASSWORD);

        $resolved = $this->resolver->resolveTemplateCredentials(['credential_id' => $legacy->id]);

        $this->assertSame([], $resolved['credential']['secrets'] ?? null);
    }

    public function testTheScmCredentialResolvesOrFails(): void
    {
        $project = $this->createProject($this->userId);
        $this->assertNull($this->resolver->resolveScmCredential($project));
        $this->assertNull($this->resolver->resolveScmCredential(null));

        $scm = $this->credential(Credential::TYPE_TOKEN, ['token' => 't']);
        $project->scm_credential_id = $scm->id;
        $this->assertSame(['token' => 't'], $this->resolver->resolveScmCredential($project)['secrets'] ?? null);

        $scm->secret_data = base64_encode(random_bytes(64));
        $scm->save(false);
        try {
            $this->resolver->resolveScmCredential($project);
            $this->fail('an undecryptable SCM credential must abort the job');
        } catch (CredentialResolutionException $e) {
            $this->assertSame(Credential::ROLE_SCM, $e->failures[0]['role']);
        }
    }

    public function testDescribeNamesDeletedCredentialsFromTheSnapshot(): void
    {
        $live = $this->credential(Credential::TYPE_SSH_KEY, ['private_key' => 'k']);
        $payload = [
            'credential_id' => $live->id,
            'credential_ids' => [$live->id, 999_999_999],
            Job::PAYLOAD_CREDENTIAL_SNAPSHOT => [
                ['id' => 999_999_999, 'name' => 'old-token', 'credential_type' => 'token', 'role' => 'additional'],
                ['bogus'],
            ],
        ];

        $described = $this->resolver->describe($payload);

        $this->assertSame([
            ['id' => (int)$live->id, 'name' => $live->name, 'credential_type' => Credential::TYPE_SSH_KEY, 'role' => Credential::ROLE_PRIMARY, 'deleted' => false],
            ['id' => 999_999_999, 'name' => 'old-token', 'credential_type' => 'token', 'role' => Credential::ROLE_ADDITIONAL, 'deleted' => true],
        ], $described);
        $this->assertSame([], $this->resolver->describe([]));
    }

    public function testTheAuditDescriptionAddsTheScmCredential(): void
    {
        $scm = $this->credential(Credential::TYPE_TOKEN, ['token' => 't']);
        $project = $this->createProject($this->userId);
        $project->scm_credential_id = $scm->id;
        $project->save(false);

        $described = $this->resolver->describeForAudit(['project_id' => $project->id]);

        $this->assertSame([[
            'id' => (int)$scm->id,
            'name' => $scm->name,
            'credential_type' => Credential::TYPE_TOKEN,
            'role' => Credential::ROLE_SCM,
            'deleted' => false,
        ]], $described);
    }
}
