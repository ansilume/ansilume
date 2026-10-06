<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\CredentialUsage;
use app\controllers\CredentialController;
use app\models\AuditLog;
use app\models\Credential;
use app\services\CredentialService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Exercises every action in CredentialController.
 *
 * Security-critical surface: we specifically verify that raw secret material
 * is never re-rendered in responses after create/update.
 */
class CredentialControllerActionTest extends WebControllerTestCase
{
    /**
     * Some tests swap in a stub credentialService. Without this the stub
     * stayed on Yii::$app for every later test in the process.
     */
    protected function tearDown(): void
    {
        \Yii::$app->set('credentialService', ['class' => CredentialService::class]);
        parent::tearDown();
    }

    // ── actionIndex() ────────────────────────────────────────────────────────

    public function testIndexRendersDataProvider(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->createCredential($user->id, Credential::TYPE_TOKEN);

        $ctrl = $this->makeController();
        $result = $ctrl->actionIndex();

        $this->assertSame('rendered:index', $result);
        $this->assertArrayHasKey('dataProvider', $ctrl->capturedParams);
        $this->assertInstanceOf(ActiveDataProvider::class, $ctrl->capturedParams['dataProvider']);
        /** @var ActiveDataProvider $dp */
        $dp = $ctrl->capturedParams['dataProvider'];
        $this->assertNotEmpty($dp->getModels());
    }

    // ── actionView() ─────────────────────────────────────────────────────────

    public function testViewRendersNonSshCredential(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);

        $ctrl = $this->makeController();
        $result = $ctrl->actionView((int)$cred->id);

        $this->assertSame('rendered:view', $result);
        $this->assertSame($cred->id, $ctrl->capturedParams['model']->id);
        // sshInfo must be null for non-SSH credentials
        $this->assertNull($ctrl->capturedParams['sshInfo']);
    }

    public function testViewRendersSshCredentialWithKeyMetadata(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        // Create an SSH credential with a real stored secret so getSecrets() works.
        $cred = $this->createCredential($user->id, Credential::TYPE_SSH_KEY);
        /** @var \app\services\CredentialService $cs */
        $cs = \Yii::$app->get('credentialService');
        $cs->storeSecrets($cred, [
            'private_key' => "-----BEGIN FAKE KEY-----\nabc\n-----END FAKE KEY-----",
            'public_key' => 'ssh-ed25519 AAAA fake',
            'algorithm' => 'ed25519',
            'bits' => '256',
            'key_secure' => '1',
        ]);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$cred->id);

        $this->assertIsArray($ctrl->capturedParams['sshInfo']);
        $this->assertSame('ssh-ed25519 AAAA fake', $ctrl->capturedParams['sshInfo']['public_key']);
        $this->assertSame('ed25519', $ctrl->capturedParams['sshInfo']['algorithm']);
        $this->assertSame(256, $ctrl->capturedParams['sshInfo']['bits']);
    }

    public function testViewSshCredentialWithoutSecretDataReturnsNullSshInfo(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        // SSH type but no stored secret_data — sshInfo must remain null.
        $cred = $this->createCredential($user->id, Credential::TYPE_SSH_KEY);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$cred->id);

        $this->assertNull($ctrl->capturedParams['sshInfo']);
    }

    public function testViewThrowsNotFound(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionView(9999999);
    }

    // ── actionCreate() ───────────────────────────────────────────────────────

    public function testCreateRendersFormOnGet(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertInstanceOf(Credential::class, $ctrl->capturedParams['model']);
        $this->assertTrue($ctrl->capturedParams['model']->isNewRecord);
    }

    public function testCreateTokenCredentialPersistsAndRedirects(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $this->setPost([
            'Credential' => [
                'name' => 'unit-test-token',
                'credential_type' => Credential::TYPE_TOKEN,
                'description' => 'created by test',
            ],
            'secrets' => ['token' => 'super-secret-token-value'],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('redirected', $result->content);
        $this->assertSame('view', $ctrl->capturedRedirect[0]);

        $stored = Credential::findOne(['name' => 'unit-test-token']);
        $this->assertNotNull($stored);
        $this->assertSame($user->id, $stored->created_by);

        // Secret must be stored encrypted — raw value must never appear in secret_data.
        $this->assertNotEmpty($stored->secret_data);
        $this->assertStringNotContainsString('super-secret-token-value', (string)$stored->secret_data);

        // And round-trips correctly through the service.
        /** @var \app\services\CredentialService $cs */
        $cs = \Yii::$app->get('credentialService');
        $secrets = $cs->getSecrets($stored);
        $this->assertSame('super-secret-token-value', $secrets['token']);

        // Audit log entry was written.
        $audit = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_CREDENTIAL_CREATED, 'object_id' => $stored->id])
            ->one();
        $this->assertNotNull($audit);
    }

    public function testCreateVaultCredentialStoresVaultPassword(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $this->setPost([
            'Credential' => [
                'name' => 'unit-test-vault',
                'credential_type' => Credential::TYPE_VAULT,
            ],
            'secrets' => ['vault_password' => 'vault-pw'],
        ]);

        $ctrl = $this->makeController();
        $ctrl->actionCreate();

        /** @var Credential $stored */
        $stored = Credential::findOne(['name' => 'unit-test-vault']);
        /** @var \app\services\CredentialService $cs */
        $cs = \Yii::$app->get('credentialService');
        $this->assertSame('vault-pw', $cs->getSecrets($stored)['vault_password']);
    }

    public function testCreateUsernamePasswordCredentialStoresPassword(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $this->setPost([
            'Credential' => [
                'name' => 'unit-test-userpass',
                'credential_type' => Credential::TYPE_USERNAME_PASSWORD,
                'username' => 'deploy',
            ],
            'secrets' => ['password' => 'hunter2'],
        ]);

        $ctrl = $this->makeController();
        $ctrl->actionCreate();

        /** @var Credential $stored */
        $stored = Credential::findOne(['name' => 'unit-test-userpass']);
        /** @var \app\services\CredentialService $cs */
        $cs = \Yii::$app->get('credentialService');
        $this->assertSame('hunter2', $cs->getSecrets($stored)['password']);
        $this->assertSame('deploy', $stored->username);
    }

    public function testCreateSshCredentialNormalisesLineEndings(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        // CRLF private key (as a browser textarea would submit).
        $keyWithCrlf = "-----BEGIN FAKE KEY-----\r\nabc\r\ndef\r\n-----END FAKE KEY-----";

        $this->setPost([
            'Credential' => [
                'name' => 'unit-test-ssh',
                'credential_type' => Credential::TYPE_SSH_KEY,
            ],
            'secrets' => ['private_key' => $keyWithCrlf],
        ]);

        $ctrl = $this->makeController();
        $ctrl->actionCreate();

        /** @var Credential $stored */
        $stored = Credential::findOne(['name' => 'unit-test-ssh']);
        /** @var \app\services\CredentialService $cs */
        $cs = \Yii::$app->get('credentialService');
        $secrets = $cs->getSecrets($stored);
        // Regression: CR was replaced before CRLF, so every CRLF became a blank line.
        $this->assertSame("-----BEGIN FAKE KEY-----\nabc\ndef\n-----END FAKE KEY-----", $secrets['private_key']);
    }

    public function testCreateInvalidInputRendersFormWithErrors(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        // Missing required 'name' field.
        $this->setPost([
            'Credential' => [
                'credential_type' => Credential::TYPE_TOKEN,
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertTrue($ctrl->capturedParams['model']->hasErrors('name'));
    }

    // ── actionUpdate() ───────────────────────────────────────────────────────

    public function testUpdateRendersFormOnGet(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$cred->id);

        $this->assertSame('rendered:form', $result);
        $this->assertSame($cred->id, $ctrl->capturedParams['model']->id);
    }

    public function testUpdateWithNewSecretRewritesSecretData(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);
        /** @var \app\services\CredentialService $cs */
        $cs = \Yii::$app->get('credentialService');
        $cs->storeSecrets($cred, ['token' => 'old-token']);

        $this->setPost([
            'Credential' => [
                'name' => $cred->name,
                'credential_type' => Credential::TYPE_TOKEN,
            ],
            'secrets' => ['token' => 'new-token'],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$cred->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('redirected', $result->content);
        /** @var Credential $reloaded */
        $reloaded = Credential::findOne($cred->id);
        $this->assertSame('new-token', $cs->getSecrets($reloaded)['token']);
    }

    public function testUpdateWithoutSecretPreservesOldSecret(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);
        /** @var \app\services\CredentialService $cs */
        $cs = \Yii::$app->get('credentialService');
        $cs->storeSecrets($cred, ['token' => 'preserved-token']);

        // Posting empty secret — controller should fall through to save() and keep the old value.
        $this->setPost([
            'Credential' => [
                'name' => $cred->name . '-renamed',
                'credential_type' => Credential::TYPE_TOKEN,
            ],
            'secrets' => ['token' => ''],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$cred->id);
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('redirected', $result->content);

        /** @var Credential $reloaded */
        $reloaded = Credential::findOne($cred->id);
        $this->assertSame($cred->name . '-renamed', $reloaded->name);
        $this->assertSame('preserved-token', $cs->getSecrets($reloaded)['token']);
    }

    public function testUpdateInvalidInputRendersForm(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);

        $this->setPost([
            'Credential' => [
                'name' => '', // required → invalid
                'credential_type' => Credential::TYPE_TOKEN,
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$cred->id);

        $this->assertSame('rendered:form', $result);
        $this->assertTrue($ctrl->capturedParams['model']->hasErrors('name'));
    }

    public function testUpdateThrowsNotFoundForMissingId(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionUpdate(9999999);
    }

    // ── actionGenerateSshKey() ───────────────────────────────────────────────

    public function testGenerateSshKeyReturnsKeyPairJson(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        // Stub CredentialService so we don't shell out to ssh-keygen in tests.
        $fakeCs = new class extends \app\services\CredentialService {
            public function generateSshKeyPair(): array
            {
                return [
                    'private_key' => '-----BEGIN FAKE PRIVATE KEY-----',
                    'public_key'  => 'ssh-ed25519 AAAA fake',
                ];
            }
        };
        \Yii::$app->set('credentialService', $fakeCs);

        $ctrl = $this->makeController();
        $response = $ctrl->actionGenerateSshKey();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame([
            'ok' => true,
            'private_key' => '-----BEGIN FAKE PRIVATE KEY-----',
            'public_key'  => 'ssh-ed25519 AAAA fake',
        ], $response->data);
    }

    public function testGenerateSshKeyReturnsErrorOnFailure(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $fakeCs = new class extends \app\services\CredentialService {
            public function generateSshKeyPair(): array
            {
                throw new \RuntimeException('ssh-keygen missing');
            }
        };
        \Yii::$app->set('credentialService', $fakeCs);

        $ctrl = $this->makeController();
        $response = $ctrl->actionGenerateSshKey();

        $this->assertSame(500, \Yii::$app->response->statusCode);
        $this->assertIsArray($response->data);
        $this->assertFalse($response->data['ok']);
        $this->assertSame('Key generation failed.', $response->data['error']);
    }

    // ── actionDelete() ───────────────────────────────────────────────────────

    public function testDeleteRemovesCredentialAndAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);
        $id = (int)$cred->id;
        $name = $cred->name;

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete($id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('redirected', $result->content);
        $this->assertSame('index', $ctrl->capturedRedirect[0]);
        $this->assertNull(Credential::findOne($id));

        $audit = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_CREDENTIAL_DELETED, 'object_id' => $id])
            ->one();
        $this->assertNotNull($audit);
        $meta = json_decode((string)$audit->metadata, true);
        $this->assertSame($name, $meta['name']);
    }

    public function testDeleteThrowsNotFoundForMissingId(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);

        $ctrl = $this->makeController();
        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionDelete(9999999);
    }

    // ── Secret rules, usage and the guarded delete ───────────────────────────

    /**
     * Regression: the form stored a credential with an empty secret, so jobs
     * ran without the token, password or key and failed far from the cause.
     */
    public function testCreateWithoutTheSecretRendersTheFormWithASecretsError(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->setPost([
            'Credential' => ['name' => 'unit-test-blank-token', 'credential_type' => Credential::TYPE_TOKEN],
            'secrets' => ['token' => '   '],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $this->assertSame('Token is required for Token credentials.', $ctrl->capturedParams['model']->getFirstError('secrets'));
        $this->assertNull(Credential::findOne(['name' => 'unit-test-blank-token']));
    }

    public function testCreateIgnoresASecretsFieldThatIsNotAnArray(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $this->setPost([
            'Credential' => ['name' => 'unit-test-scalar-secrets', 'credential_type' => Credential::TYPE_TOKEN],
            'secrets' => 'tok',
        ]);

        $ctrl = $this->makeController();

        $this->assertSame('rendered:form', $ctrl->actionCreate());
        $this->assertTrue($ctrl->capturedParams['model']->hasErrors('secrets'));
    }

    /**
     * Regression: changing the type kept the old type's secret, for example a
     * token credential turned into a vault credential without a vault password.
     */
    public function testATypeChangeWithoutTheNewSecretIsRejected(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->storedCredential($user, Credential::TYPE_TOKEN, ['token' => 'tok-kept']);
        $this->setPost([
            'Credential' => ['name' => $cred->name, 'credential_type' => Credential::TYPE_VAULT],
            'secrets' => ['vault_password' => ''],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$cred->id);

        $this->assertSame('rendered:form', $result);
        $this->assertSame('Changing the type to Vault Secret requires a new vault password.', $ctrl->capturedParams['model']->getFirstError('secrets'));
        $cred->refresh();
        $this->assertSame(Credential::TYPE_TOKEN, $cred->credential_type);
        $this->assertSame(['token' => 'tok-kept'], $this->credentialService()->getSecrets($cred));
    }

    public function testUpdateAuditsWhatChangedButNeverTheSecret(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->storedCredential($user, Credential::TYPE_TOKEN, ['token' => 'tok-before']);
        $this->setPost([
            'Credential' => ['name' => $cred->name, 'credential_type' => Credential::TYPE_TOKEN, 'description' => 'rotated'],
            'secrets' => ['token' => 'tok-after'],
        ]);

        $this->makeController()->actionUpdate((int)$cred->id);

        $audit = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_CREDENTIAL_UPDATED, 'object_id' => $cred->id])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($audit);
        $meta = json_decode((string)$audit->metadata, true);
        $this->assertIsArray($meta);
        $this->assertTrue($meta['secret_changed']);
        $this->assertSame(['description'], $meta['changed_fields']);
        $this->assertStringNotContainsString('tok-', (string)$audit->metadata);
    }

    /**
     * Regression: an SSH key credential whose secret could not be decrypted
     * (APP_SECRET_KEY changed) made the credential page fail with a 500.
     */
    public function testViewOfAnUndecryptableSshKeyShowsTheStatusInsteadOfFailing(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->createCredential($user->id, Credential::TYPE_SSH_KEY);
        $cred->secret_data = 'not-a-ciphertext';
        $cred->save(false);

        $ctrl = $this->makeController();

        $this->assertSame('rendered:view', $ctrl->actionView((int)$cred->id));
        $this->assertSame(CredentialService::SECRET_STATUS_UNDECRYPTABLE, $ctrl->capturedParams['secretStatus']);
        $this->assertNull($ctrl->capturedParams['sshInfo']);
    }

    public function testViewDerivesThePublicKeyOfAKeyStoredWithoutIt(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $pair = $this->credentialService()->generateSshKeyPair();
        $cred = $this->createCredential($user->id, Credential::TYPE_SSH_KEY);
        $this->credentialService()->storeSecrets($cred, ['private_key' => $pair['private_key']]);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$cred->id);

        $this->assertSame(CredentialService::SECRET_STATUS_OK, $ctrl->capturedParams['secretStatus']);
        $this->assertIsArray($ctrl->capturedParams['sshInfo']);
        $this->assertSame(trim($pair['public_key']), trim($ctrl->capturedParams['sshInfo']['public_key']));
        $this->assertSame('ed25519', $ctrl->capturedParams['sshInfo']['algorithm']);
        $this->assertTrue($ctrl->capturedParams['sshInfo']['key_secure']);
    }

    public function testViewOfAUsableTokenCredentialHasNoSshInfo(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->storedCredential($user, Credential::TYPE_TOKEN, ['token' => 'tok-view']);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$cred->id);

        $this->assertSame(CredentialService::SECRET_STATUS_OK, $ctrl->capturedParams['secretStatus']);
        $this->assertNull($ctrl->capturedParams['sshInfo']);
    }

    public function testViewPassesWhereTheCredentialIsUsed(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);
        $template = $this->templateUsing($cred, (int)$user->id);

        $ctrl = $this->makeController();
        $ctrl->actionView((int)$cred->id);

        $usage = $ctrl->capturedParams['usage'];
        $this->assertInstanceOf(CredentialUsage::class, $usage);
        $this->assertSame([(int)$template->id], array_column($usage->jobTemplates, 'id'));
        $this->assertSame(CredentialService::SECRET_STATUS_INCOMPLETE, $ctrl->capturedParams['secretStatus']);
    }

    /**
     * Regression: deleting a credential in use silently detached it from its
     * job templates, whose next jobs then ran without it.
     */
    public function testDeletingACredentialInUseNeedsTheForcedDelete(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);
        $template = $this->templateUsing($cred, (int)$user->id);

        $ctrl = $this->makeController();
        $ctrl->actionDelete((int)$cred->id);

        $this->assertSame(['view', 'id' => (int)$cred->id], $ctrl->capturedRedirect);
        $this->assertNotNull(Credential::findOne($cred->id));
        $template->refresh();
        $this->assertSame($cred->id, $template->credential_id);
        $flash = (string)\Yii::$app->session->getFlash('danger');
        $this->assertStringContainsString('is in use by 1 job template(s).', $flash);
    }

    public function testTheForcedDeleteDetachesTheCredentialAndAudits(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);
        $template = $this->templateUsing($cred, (int)$user->id);
        $this->setPost(['force' => '1']);

        $ctrl = $this->makeController();
        $ctrl->actionDelete((int)$cred->id);

        $this->assertSame('index', $ctrl->capturedRedirect[0]);
        $this->assertNull(Credential::findOne($cred->id));
        $template->refresh();
        $this->assertNull($template->credential_id);
        $this->assertStringContainsString('detached', (string)\Yii::$app->session->getFlash('success'));
        $audit = AuditLog::find()->where(['action' => AuditLog::ACTION_CREDENTIAL_DELETED, 'object_id' => $cred->id])->one();
        $this->assertNotNull($audit);
        $meta = json_decode((string)$audit->metadata, true);
        $this->assertIsArray($meta);
        $this->assertTrue($meta['forced']);
    }

    public function testOnlyForceOneForcesTheDelete(): void
    {
        $user = $this->createUser();
        $this->loginAs($user);
        $cred = $this->createCredential($user->id, Credential::TYPE_TOKEN);
        $this->templateUsing($cred, (int)$user->id);
        $this->setPost(['force' => 'yes']);

        $this->makeController()->actionDelete((int)$cred->id);

        $this->assertNotNull(Credential::findOne($cred->id));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $secrets
     */
    private function storedCredential(\app\models\User $owner, string $type, array $secrets): Credential
    {
        $credential = new Credential();
        $credential->name = 'web-cred-' . uniqid('', true);
        $credential->credential_type = $type;
        $credential->created_by = (int)$owner->id;
        /** @var \app\services\CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($credential, $secrets));

        return $credential;
    }

    private function templateUsing(Credential $credential, int $userId): \app\models\JobTemplate
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        $template->credential_id = $credential->id;
        $template->save(false);

        return $template;
    }

    private function credentialService(): CredentialService
    {
        /** @var CredentialService $service */
        $service = \Yii::$app->get('credentialService');

        return $service;
    }

    /**
     * Anonymous CredentialController subclass that captures render/redirect
     * instead of actually rendering templates or performing HTTP redirects.
     */
    private function makeController(): CredentialController
    {
        return new class ('credential', \Yii::$app) extends CredentialController {
            public string $capturedView = '';
            /** @var array<string, mixed> */
            public array $capturedParams = [];
            /** @var array<int, mixed> */
            public array $capturedRedirect = [];

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                /** @var array<int, mixed> $url */
                $this->capturedRedirect = (array)$url;
                $r = new \yii\web\Response();
                $r->content = 'redirected';
                return $r;
            }

            // The controller returns the redirect() result; our stub returns a
            // Response whose string representation is "redirected". Normalise
            // the return so assertions can just compare to 'redirected'.
            public function asJson($data): \yii\web\Response
            {
                $response = \Yii::$app->response;
                $response->format = \yii\web\Response::FORMAT_JSON;
                $response->data = $data;
                return $response;
            }
        };
    }
}
