<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\CredentialsController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\CredentialService;
use app\services\CredentialWriteService;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * REST API for credentials: secrets never leave the server, the secret of
 * the credential's type is required, updates keep the stored secret unless
 * a new one is sent, and deleting a credential in use needs force=1.
 */
class CredentialsControllerTest extends WebControllerTestCase
{
    private const SERIALIZED_KEYS = ['id', 'name', 'description', 'credential_type', 'username', 'env_var_name', 'created_at', 'updated_at'];

    private CredentialsController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new CredentialsController('api/v1/credentials', \Yii::$app);
    }

    /** One test swaps in a stub write service; restore the real one. */
    protected function tearDown(): void
    {
        \Yii::$app->set('credentialWriteService', ['class' => CredentialWriteService::class]);
        parent::tearDown();
    }

    // -- Index / view ---------------------------------------------------------

    public function testIndexListsCredentialsWithoutSecretMaterial(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $credential = $this->storedCredential($admin, Credential::TYPE_TOKEN, ['token' => 'tok-index-secret']);

        $result = $this->ctrl->actionIndex();

        $this->assertArrayHasKey('data', $result);
        $items = array_values(array_filter($result['data'], static fn (array $item): bool => $item['id'] === $credential->id));
        $this->assertCount(1, $items);
        $this->assertSame(self::SERIALIZED_KEYS, array_keys($items[0]));
        $this->assertStringNotContainsString('tok-index-secret', (string)json_encode($result));
        $this->assertStringNotContainsString('secret_data', (string)json_encode($result));
    }

    public function testViewReportsTheSecretStatusAndEveryUsage(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $userId = (int)$admin->id;
        $credential = $this->storedCredential($admin, Credential::TYPE_TOKEN, ['token' => 'tok-view-secret']);
        $primary = $this->template($userId, 'cred-api-primary');
        $primary->credential_id = $credential->id;
        $primary->save(false);
        $additional = $this->template($userId, 'cred-api-additional');
        $this->attach($additional, $credential, 0);
        $project = $this->createProject($userId);
        $project->scm_credential_id = $credential->id;
        $project->save(false);
        $this->waitingJob($primary, $userId, [(int)$credential->id]);

        $data = $this->dataOf($this->ctrl->actionView((int)$credential->id));

        $this->assertSame(CredentialService::SECRET_STATUS_OK, $data['secret_status']);
        $usage = $data['used_by'];
        $this->assertTrue($usage['in_use']);
        $roles = array_column($usage['job_templates'], 'role', 'id');
        $this->assertSame(Credential::ROLE_PRIMARY, $roles[$primary->id]);
        $this->assertSame(Credential::ROLE_ADDITIONAL, $roles[$additional->id]);
        $this->assertSame([['id' => (int)$project->id, 'name' => $project->name]], $usage['projects']);
        $this->assertSame(1, $usage['pending_job_count']);
        $this->assertSame(0, $usage['hidden_job_template_count']);
        $this->assertStringNotContainsString('tok-view-secret', (string)json_encode($data));
    }

    public function testViewFlagsIncompleteAndUndecryptableSecrets(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $legacy = $this->createCredential((int)$admin->id, Credential::TYPE_VAULT);
        $broken = $this->createCredential((int)$admin->id, Credential::TYPE_TOKEN);
        $broken->secret_data = 'not-a-ciphertext';
        $broken->save(false);

        $this->assertSame(CredentialService::SECRET_STATUS_INCOMPLETE, $this->dataOf($this->ctrl->actionView((int)$legacy->id))['secret_status']);
        $this->assertSame(CredentialService::SECRET_STATUS_UNDECRYPTABLE, $this->dataOf($this->ctrl->actionView((int)$broken->id))['secret_status']);
    }

    public function testViewOnlyNamesTemplatesTheViewerMaySee(): void
    {
        $owner = $this->createUser('cred-api-owner');
        $credential = $this->createCredential((int)$owner->id);
        $hidden = $this->template((int)$owner->id, 'cred-api-hidden');
        $hidden->credential_id = $credential->id;
        $hidden->save(false);
        $team = $this->createTeam((int)$owner->id);
        $this->createTeamProject((int)$team->id, (int)$hidden->project_id);

        $this->authenticateWithRoles(['viewer']);
        $usage = $this->dataOf($this->ctrl->actionView((int)$credential->id))['used_by'];

        $this->assertTrue($usage['in_use']);
        $this->assertSame([], $usage['job_templates']);
        $this->assertSame(1, $usage['hidden_job_template_count']);
    }

    public function testViewOfAMissingCredentialIs404(): void
    {
        $this->authenticateWithRoles(['admin']);
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionView(999999);
    }

    // -- Create ---------------------------------------------------------------

    public function testCreateStoresTheSecretEncryptedAndAudits(): void
    {
        $this->authenticateWithRoles(['admin']);
        $this->setBody([
            'name' => 'api-token-' . uniqid('', true),
            'credential_type' => Credential::TYPE_TOKEN,
            'env_var_name' => 'DEPLOY_TOKEN',
            'description' => '',
            'secrets' => ['token' => 'tok-create-secret', 'password' => 'ignored-other-type'],
        ]);

        $data = $this->dataOf($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame(self::SERIALIZED_KEYS, array_keys($data));
        $this->assertSame('DEPLOY_TOKEN', $data['env_var_name']);
        $this->assertNull($data['description']);
        $stored = Credential::findOne($data['id']);
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('tok-create-secret', (string)$stored->secret_data);
        $this->assertSame(['token' => 'tok-create-secret'], $this->credentialService()->getSecrets($stored));
        $audit = $this->lastAudit(AuditLog::ACTION_CREDENTIAL_CREATED, (int)$stored->id);
        $this->assertSame('api', $audit['source']);
        $this->assertStringNotContainsString('tok-create-secret', (string)json_encode($audit));
    }

    public function testCreateWithoutTheTypesSecretIs422(): void
    {
        $this->authenticateWithRoles(['admin']);
        $before = Credential::find()->count();
        $this->setBody([
            'name' => 'api-vault-' . uniqid('', true),
            'credential_type' => Credential::TYPE_VAULT,
            'secrets' => ['token' => 'wrong-type-secret', 'vault_password' => '   '],
        ]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Vault password is required for Vault Secret credentials.']], $result);
        $this->assertSame($before, Credential::find()->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidCreateBodyProvider(): array
    {
        return [
            'unknown type' => [['name' => 'api-bad-type', 'credential_type' => 'kerberos', 'secrets' => ['token' => 'x']], 'Credential Type is invalid.'],
            'name is not a string' => [['name' => ['nested'], 'credential_type' => Credential::TYPE_TOKEN, 'secrets' => ['token' => 'x']], 'Name cannot be blank.'],
            'lower-case env var' => [['name' => 'api-bad-env', 'credential_type' => Credential::TYPE_TOKEN, 'env_var_name' => 'deploy_token', 'secrets' => ['token' => 'x']], 'Env var name must use upper-case letters, digits, and underscores only, and start with a letter or underscore.'],
            'secrets is not an object' => [['name' => 'api-bad-secrets', 'credential_type' => Credential::TYPE_TOKEN, 'secrets' => 'tok'], 'Token is required for Token credentials.'],
        ];
    }

    /**
     * @dataProvider invalidCreateBodyProvider
     * @param array<string, mixed> $body
     */
    public function testInvalidCreateBodiesAre422(array $body, string $message): void
    {
        $this->authenticateWithRoles(['admin']);
        $this->setBody($body);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $message]], $result);
    }

    public function testARejectedWriteWithoutAMessageIsAGeneric422(): void
    {
        $this->authenticateWithRoles(['admin']);
        \Yii::$app->set('credentialWriteService', new class extends CredentialWriteService {
            public function create(Credential $credential, array $secretInput, array $auditContext = []): bool
            {
                return false;
            }
        });
        $this->setBody(['name' => 'api-generic-422', 'credential_type' => Credential::TYPE_TOKEN, 'secrets' => ['token' => 'x']]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Validation failed.']], $result);
    }

    // -- Update ---------------------------------------------------------------

    public function testUpdateWithoutSecretsKeepsTheStoredSecret(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $credential = $this->storedCredential($admin, Credential::TYPE_USERNAME_PASSWORD, ['password' => 'pw-keep'], 'deploy');
        $newName = 'api-renamed-' . uniqid('', true);
        $this->setBody(['name' => $newName, 'username' => '', 'description' => null, 'secrets' => ['password' => '']]);

        $data = $this->dataOf($this->ctrl->actionUpdate((int)$credential->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame($newName, $data['name']);
        $this->assertNull($data['username']);
        $credential->refresh();
        $this->assertSame(['password' => 'pw-keep'], $this->credentialService()->getSecrets($credential));
        $audit = $this->lastAudit(AuditLog::ACTION_CREDENTIAL_UPDATED, (int)$credential->id);
        $this->assertFalse($audit['secret_changed']);
        $this->assertSame(['name', 'username'], $audit['changed_fields']);
        $this->assertSame('api', $audit['source']);
    }

    public function testUpdateWithANewSecretReplacesIt(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $credential = $this->storedCredential($admin, Credential::TYPE_TOKEN, ['token' => 'tok-old']);
        $this->setBody(['secrets' => ['token' => 'tok-new']]);

        $this->dataOf($this->ctrl->actionUpdate((int)$credential->id));

        $credential->refresh();
        $this->assertSame(['token' => 'tok-new'], $this->credentialService()->getSecrets($credential));
        $this->assertTrue($this->lastAudit(AuditLog::ACTION_CREDENTIAL_UPDATED, (int)$credential->id)['secret_changed']);
    }

    public function testATypeChangeWithoutTheNewSecretIs422AndChangesNothing(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $credential = $this->storedCredential($admin, Credential::TYPE_TOKEN, ['token' => 'tok-stays']);
        $this->setBody(['credential_type' => Credential::TYPE_VAULT]);

        $result = $this->ctrl->actionUpdate((int)$credential->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Changing the type to Vault Secret requires a new vault password.']], $result);
        $credential->refresh();
        $this->assertSame(Credential::TYPE_TOKEN, $credential->credential_type);
        $this->assertSame(['token' => 'tok-stays'], $this->credentialService()->getSecrets($credential));
    }

    public function testATypeChangeWithTheNewSecretDropsTheOldOne(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $credential = $this->storedCredential($admin, Credential::TYPE_TOKEN, ['token' => 'tok-gone']);
        $this->setBody(['credential_type' => Credential::TYPE_VAULT, 'secrets' => ['vault_password' => 'vault-new']]);

        $data = $this->dataOf($this->ctrl->actionUpdate((int)$credential->id));

        $this->assertSame(Credential::TYPE_VAULT, $data['credential_type']);
        $credential->refresh();
        $this->assertSame(['vault_password' => 'vault-new'], $this->credentialService()->getSecrets($credential));
        $this->assertSame(Credential::TYPE_TOKEN, $this->lastAudit(AuditLog::ACTION_CREDENTIAL_UPDATED, (int)$credential->id)['previous_type']);
    }

    public function testUpdateValidationErrorsAre422(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $credential = $this->storedCredential($admin, Credential::TYPE_TOKEN, ['token' => 'tok-valid']);
        $this->setBody(['name' => '']);

        $result = $this->ctrl->actionUpdate((int)$credential->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Name cannot be blank.']], $result);
    }

    public function testUpdateOfAMissingCredentialIs404(): void
    {
        $this->authenticateWithRoles(['admin']);
        $this->setBody(['name' => 'nope']);
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionUpdate(999999);
    }

    // -- Delete ---------------------------------------------------------------

    public function testDeletingAnUnusedCredential(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $credential = $this->createCredential((int)$admin->id);

        $this->assertSame(['deleted' => true], $this->dataOf($this->ctrl->actionDelete((int)$credential->id)));

        $this->assertNull(Credential::findOne($credential->id));
        $audit = $this->lastAudit(AuditLog::ACTION_CREDENTIAL_DELETED, (int)$credential->id);
        $this->assertArrayNotHasKey('forced', $audit);
        $this->assertSame('api', $audit['source']);
    }

    /**
     * Contract change in 2.6.0: deleting a credential in use used to detach it
     * silently. It now answers 409 and lists where the credential is used.
     */
    public function testDeletingACredentialInUseIs409WithTheUsage(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $credential = $this->createCredential((int)$admin->id);
        $credential->name = 'deploy-key';
        $credential->save(false);
        $template = $this->template((int)$admin->id, 'cred-api-guarded');
        $template->credential_id = $credential->id;
        $template->save(false);

        $result = $this->ctrl->actionDelete((int)$credential->id);

        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(
            'Credential "deploy-key" is in use by 1 job template(s). Pass force=1 to delete it anyway; it will be detached from all of them.',
            $result['error']['message']
        );
        $this->assertSame([(int)$template->id], array_column($result['error']['used_by']['job_templates'], 'id'));
        $this->assertNotNull(Credential::findOne($credential->id));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonForcingValueProvider(): array
    {
        return ['zero' => ['0'], 'false' => ['false'], 'empty' => [''], 'garbage' => ['please']];
    }

    /**
     * @dataProvider nonForcingValueProvider
     */
    public function testOnlyATrueForceValueForcesTheDelete(string $force): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $credential = $this->createCredential((int)$admin->id);
        $this->attach($this->template((int)$admin->id, 'cred-api-force-' . $force), $credential, 0);
        $this->setQueryParams(['force' => $force]);

        $this->ctrl->actionDelete((int)$credential->id);

        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertNotNull(Credential::findOne($credential->id));
    }

    public function testForceDeleteDetachesTheCredentialEverywhere(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $userId = (int)$admin->id;
        $credential = $this->createCredential($userId);
        $primary = $this->template($userId, 'cred-api-forced-primary');
        $primary->credential_id = $credential->id;
        $primary->save(false);
        $additional = $this->template($userId, 'cred-api-forced-additional');
        $this->attach($additional, $credential, 0);
        $this->setQueryParams(['force' => '1']);

        $this->assertSame(['deleted' => true], $this->dataOf($this->ctrl->actionDelete((int)$credential->id)));

        $primary->refresh();
        $this->assertNull($primary->credential_id);
        $this->assertSame([], $additional->orderedCredentials());
        $audit = $this->lastAudit(AuditLog::ACTION_CREDENTIAL_DELETED, (int)$credential->id);
        $this->assertTrue($audit['forced']);
        $this->assertSame(2, $audit['job_templates']);
    }

    public function testDeleteOfAMissingCredentialIs404(): void
    {
        $this->authenticateWithRoles(['admin']);
        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->ctrl->actionDelete(999999);
    }

    // -- Authorization through the access gate --------------------------------

    public function testAViewerMayReadButNotCreate(): void
    {
        $this->authenticateWithRoles(['viewer']);
        $before = Credential::find()->count();

        $listed = (new CredentialsController('api/v1/credentials', \Yii::$app))->runAction('index');
        $this->assertIsArray($listed);
        $this->assertArrayHasKey('data', $listed);

        $this->setBody(['name' => 'viewer-cred', 'credential_type' => Credential::TYPE_TOKEN, 'secrets' => ['token' => 'x']]);
        $this->assertForbidden((new CredentialsController('api/v1/credentials', \Yii::$app))->runAction('create'));
        $this->assertSame($before, Credential::find()->count());
    }

    public function testAnOperatorMayUpdateButNotDelete(): void
    {
        $operator = $this->authenticateWithRoles(['operator']);
        $credential = $this->storedCredential($operator, Credential::TYPE_TOKEN, ['token' => 'tok-operator']);
        $newName = 'operator-renamed-' . uniqid('', true);
        $this->setBody(['name' => $newName]);

        $updated = (new CredentialsController('api/v1/credentials', \Yii::$app))->runAction('update', ['id' => $credential->id]);
        $this->assertIsArray($updated);
        $this->assertSame($newName, $updated['data']['name']);

        $this->assertForbidden((new CredentialsController('api/v1/credentials', \Yii::$app))->runAction('delete', ['id' => $credential->id]));
        $this->assertNotNull(Credential::findOne($credential->id));
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @param list<string> $roles
     */
    private function authenticateWithRoles(array $roles): User
    {
        $user = $this->createUser('cred-api');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        foreach ($roles as $roleName) {
            $role = $auth->getRole($roleName);
            $this->assertNotNull($role, $roleName);
            $auth->assign($role, (string)$user->id);
        }
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'cred-api-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);

        return $user;
    }

    /**
     * @param array<string, string> $secrets
     */
    private function storedCredential(User $owner, string $type, array $secrets, ?string $username = null): Credential
    {
        $credential = new Credential();
        $credential->name = 'api-cred-' . uniqid('', true);
        $credential->credential_type = $type;
        $credential->username = $username;
        $credential->created_by = (int)$owner->id;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($credential, $secrets), (string)json_encode($credential->errors));

        return $credential;
    }

    private function template(int $userId, string $name): JobTemplate
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        $template->name = $name;
        $template->save(false);

        return $template;
    }

    private function attach(JobTemplate $template, Credential $credential, int $sortOrder): void
    {
        \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
            'job_template_id' => $template->id,
            'credential_id' => $credential->id,
            'sort_order' => $sortOrder,
        ])->execute();
    }

    /**
     * @param list<int> $credentialIds
     */
    private function waitingJob(JobTemplate $template, int $userId, array $credentialIds): void
    {
        $job = $this->createJob((int)$template->id, $userId, Job::STATUS_PENDING);
        $job->runner_payload = (string)json_encode(['credential_ids' => $credentialIds]);
        $job->save(false);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function dataOf(array $result): array
    {
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    private function assertForbidden(mixed $result): void
    {
        $this->assertNull($result, 'the action must not run');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], \Yii::$app->response->data);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastAudit(string $action, int $id): array
    {
        $log = AuditLog::find()->where(['action' => $action, 'object_type' => 'credential', 'object_id' => $id])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($log, "no {$action} audit entry");
        $meta = json_decode((string)$log->metadata, true);
        $this->assertIsArray($meta);

        return $meta;
    }

    private function credentialService(): CredentialService
    {
        /** @var CredentialService $service */
        $service = \Yii::$app->get('credentialService');

        return $service;
    }
}
