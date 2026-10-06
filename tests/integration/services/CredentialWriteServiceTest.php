<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\Credential;
use app\models\JobTemplate;
use app\services\CredentialService;
use app\services\CredentialWriteService;
use app\tests\integration\DbTestCase;

class CredentialWriteServiceTest extends DbTestCase
{
    private CredentialWriteService $service;
    private CredentialService $credentials;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CredentialWriteService();
        $this->credentials = \Yii::$app->get('credentialService');
        $this->userId = (int)$this->createUser('write')->id;
    }

    private function newCredential(string $type, string $name = ''): Credential
    {
        $credential = new Credential();
        $credential->name = $name !== '' ? $name : 'write-' . uniqid('', true);
        $credential->credential_type = $type;
        $credential->created_by = $this->userId;

        return $credential;
    }

    private function lastAudit(string $action, int $id): array
    {
        $log = AuditLog::find()->where(['action' => $action, 'object_type' => 'credential', 'object_id' => $id])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($log);
        $meta = json_decode((string)$log->metadata, true);
        $this->assertIsArray($meta);

        return $meta;
    }

    public function testCreateStoresOnlyTheTypesSecret(): void
    {
        $credential = $this->newCredential(Credential::TYPE_VAULT);

        $this->assertTrue($this->service->create($credential, ['vault_password' => 'v-1', 'token' => 'ignored'], ['source' => 'api']));

        $this->assertSame(['vault_password' => 'v-1'], $this->credentials->getSecrets($credential));
        $audit = $this->lastAudit(AuditLog::ACTION_CREDENTIAL_CREATED, (int)$credential->id);
        $this->assertSame(['name' => $credential->name, 'type' => Credential::TYPE_VAULT, 'source' => 'api'], $audit);
    }

    /**
     * Regression: a credential could be created without its secret (for
     * example a vault credential without a vault password).
     */
    public function testCreateWithoutTheSecretIsRejected(): void
    {
        $credential = $this->newCredential(Credential::TYPE_VAULT);

        $this->assertFalse($this->service->create($credential, ['vault_password' => '   ']));

        $this->assertSame('Vault password is required for Vault Secret credentials.', $credential->getFirstError('secrets'));
        $this->assertTrue($credential->isNewRecord);
    }

    public function testAnSshKeyStoresItsDerivedMetadata(): void
    {
        $pair = $this->credentials->generateSshKeyPair();
        $credential = $this->newCredential(Credential::TYPE_SSH_KEY);

        $this->assertTrue($this->service->create($credential, ['private_key' => str_replace("\n", "\r\n", $pair['private_key'])]));

        $secrets = $this->credentials->getSecrets($credential);
        $this->assertStringNotContainsString("\r", (string)$secrets['private_key']);
        $this->assertSame('ed25519', $secrets['algorithm']);
        $this->assertSame(trim($pair['public_key']), trim((string)$secrets['public_key']));
    }

    public function testUpdateWithoutASecretKeepsTheStoredOne(): void
    {
        $credential = $this->newCredential(Credential::TYPE_TOKEN);
        $this->service->create($credential, ['token' => 'keep-me']);
        $credential->description = 'new description';

        $this->assertTrue($this->service->update($credential, ['token' => '']));

        $this->assertSame(['token' => 'keep-me'], $this->credentials->getSecrets(Credential::findOne($credential->id)));
        $audit = $this->lastAudit(AuditLog::ACTION_CREDENTIAL_UPDATED, (int)$credential->id);
        $this->assertFalse($audit['secret_changed']);
        $this->assertSame(['description'], $audit['changed_fields']);
        $this->assertStringNotContainsString('keep-me', (string)json_encode($audit));
    }

    public function testUpdateWithASecretReplacesTheBlob(): void
    {
        $credential = $this->newCredential(Credential::TYPE_TOKEN);
        $this->service->create($credential, ['token' => 'old']);

        $this->assertTrue($this->service->update($credential, ['token' => 'new']));

        $this->assertSame(['token' => 'new'], $this->credentials->getSecrets(Credential::findOne($credential->id)));
        $this->assertTrue($this->lastAudit(AuditLog::ACTION_CREDENTIAL_UPDATED, (int)$credential->id)['secret_changed']);
    }

    /**
     * Regression: changing the type without a new secret kept the old type's
     * secret, so a "vault" credential carried a token.
     */
    public function testATypeChangeNeedsTheNewTypesSecret(): void
    {
        $credential = $this->newCredential(Credential::TYPE_TOKEN);
        $this->service->create($credential, ['token' => 'tok']);
        $credential->credential_type = Credential::TYPE_VAULT;

        $this->assertFalse($this->service->update($credential, ['token' => 'tok-again']));
        $this->assertSame('Changing the type to Vault Secret requires a new vault password.', $credential->getFirstError('secrets'));
        $this->assertSame(Credential::TYPE_TOKEN, Credential::findOne($credential->id)?->credential_type);

        $credential->clearErrors();
        $this->assertTrue($this->service->update($credential, ['vault_password' => 'v']));
        $this->assertSame(['vault_password' => 'v'], $this->credentials->getSecrets(Credential::findOne($credential->id)));
        $this->assertSame(Credential::TYPE_TOKEN, $this->lastAudit(AuditLog::ACTION_CREDENTIAL_UPDATED, (int)$credential->id)['previous_type']);
    }

    public function testDeletingAnUnusedCredential(): void
    {
        $credential = $this->newCredential(Credential::TYPE_TOKEN);
        $this->service->create($credential, ['token' => 't']);

        $result = $this->service->delete($credential, false, $this->userId, ['source' => 'api']);

        $this->assertTrue($result['deleted']);
        $this->assertNull(Credential::findOne($credential->id));
        $this->assertArrayNotHasKey('forced', $this->lastAudit(AuditLog::ACTION_CREDENTIAL_DELETED, (int)$credential->id));
    }

    public function testACredentialInUseIsOnlyDeletedWhenForced(): void
    {
        $credential = $this->newCredential(Credential::TYPE_TOKEN);
        $this->service->create($credential, ['token' => 't']);
        $template = $this->createJobTemplate(
            $this->createProject($this->userId)->id,
            $this->createInventory($this->userId)->id,
            $this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        \Yii::$app->get('jobTemplateCredentialService')->saveWithCredentials($template, [$credential->id]);

        $refused = $this->service->delete($credential, false, $this->userId);
        $this->assertFalse($refused['deleted']);
        $this->assertSame(1, $refused['usage']->jobTemplateTotal);
        $this->assertNotNull(Credential::findOne($credential->id));

        $forced = $this->service->delete($credential, true, $this->userId);
        $this->assertTrue($forced['deleted']);
        $this->assertSame([], \Yii::$app->get('jobTemplateCredentialService')->additionalIds(JobTemplate::findOne($template->id)), 'detached');
        $audit = $this->lastAudit(AuditLog::ACTION_CREDENTIAL_DELETED, (int)$credential->id);
        $this->assertTrue($audit['forced']);
        $this->assertSame(1, $audit['job_templates']);
    }

    public function testValidationErrorsWriteNothing(): void
    {
        $credential = $this->newCredential(Credential::TYPE_TOKEN, '');
        $credential->name = '';

        $this->assertFalse($this->service->create($credential, ['token' => 't']));
        $this->assertTrue($credential->hasErrors('name'));
    }

    public function testAnInvalidUpdateKeepsTheStoredCredential(): void
    {
        $credential = $this->newCredential(Credential::TYPE_TOKEN, 'update-invalid');
        $this->assertTrue($this->service->create($credential, ['token' => 'tok-stays']));
        $auditsBefore = AuditLog::find()->where(['action' => AuditLog::ACTION_CREDENTIAL_UPDATED, 'object_id' => $credential->id])->count();
        $credential->name = '';

        $this->assertFalse($this->service->update($credential, ['token' => 'tok-new']));

        $this->assertTrue($credential->hasErrors('name'));
        $stored = Credential::findOne($credential->id);
        $this->assertNotNull($stored);
        $this->assertSame('update-invalid', $stored->name);
        $this->assertSame(['token' => 'tok-stays'], $this->credentials->getSecrets($stored));
        $this->assertSame($auditsBefore, AuditLog::find()->where(['action' => AuditLog::ACTION_CREDENTIAL_UPDATED, 'object_id' => $credential->id])->count());
    }
}
