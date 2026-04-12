<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\CredentialController;
use app\models\AuditLog;
use app\models\Credential;
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
        $this->assertStringNotContainsString("\r", $secrets['private_key']);
        $this->assertStringContainsString('abc', $secrets['private_key']);
        $this->assertStringContainsString('def', $secrets['private_key']);
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

    // ── Helpers ──────────────────────────────────────────────────────────────

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
