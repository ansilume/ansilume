<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\AuditLog;
use app\models\LoginForm;
use app\models\User;
use app\services\ldap\FakeLdapClient;
use app\services\ldap\LdapAuthResult;
use app\services\ldap\LdapConfig;
use app\services\ldap\LdapService;
use app\services\ldap\LdapUserProvisioner;
use app\tests\integration\DbTestCase;

/**
 * Signing in with an inactive account while LDAP is enabled, against a
 * directory held by FakeLdapClient (the E2E stack has no directory, so this
 * stays PHPUnit-only).
 *
 * An account deactivated in Ansilume stays inactive whatever the directory
 * says (CLAUDE.md security rule 15): only an account that ldap/sync
 * deactivated because the directory no longer had the user
 * (user.ldap_sync_disabled) is activated again by the user's LDAP sign-in.
 * Up to 2.10.1 every inactive LDAP account the directory accepted was
 * activated again (ldap.user.reenabled), so an admin could not lock out an
 * LDAP user who was still in the directory.
 */
class LoginFormInactiveAccountTest extends DbTestCase
{
    /** The password the directory holds for its users; a test value, not a secret. */
    private const DIRECTORY_PASSWORD = 'directory-pass-4711';

    /** The password of the local users; a test value, not a secret. */
    private const LOCAL_PASSWORD = 'local-pass-4711';

    /** @var array<string, mixed>|null */
    private ?array $originalLdapParams = null;

    /** @var array<string, mixed> the component definitions this test replaces */
    private array $originalComponents = [];

    private FakeLdapClient $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $params = \Yii::$app->params['ldap'] ?? null;
        $this->originalLdapParams = is_array($params) ? $params : null;
        $components = \Yii::$app->getComponents(true);
        foreach (['ldapService', 'ldapUserProvisioner'] as $id) {
            $this->originalComponents[$id] = $components[$id] ?? null;
        }
        $this->directory = $this->enableLdap();
        $auth = \Yii::$app->authManager;
        if ($auth->getRole('viewer') === null) {
            $auth->add($auth->createRole('viewer'));
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalLdapParams !== null) {
            \Yii::$app->params['ldap'] = $this->originalLdapParams;
        } else {
            unset(\Yii::$app->params['ldap']);
        }
        foreach ($this->originalComponents as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        parent::tearDown();
    }

    /**
     * regression: the user's next LDAP sign-in with the right password
     * activated an account an admin had deactivated. It is refused with the
     * generic error, nothing is written to the account, and
     * ldap.login.failed records why.
     */
    public function testRegressionAnLdapAccountDeactivatedInAnsilumeStaysInactiveAtSignIn(): void
    {
        $user = $this->ldapUser('admin-off-');
        $this->assignViewer($user);
        $this->deactivateInAnsilume($user);
        $this->addToDirectory($user, 'moved@example.com');
        $before = $this->reload($user)->getAttributes();
        $auditId = $this->lastAuditId();

        $form = $this->form((string)$user->username, self::DIRECTORY_PASSWORD);

        $this->assertFalse($form->validate());
        $this->assertSame([LoginForm::INCORRECT_CREDENTIALS], $form->getErrors('password'));
        $this->assertSame($before, $this->reload($user)->getAttributes(), 'nothing is written to the account');
        $this->assertSame(['viewer'], array_keys(\Yii::$app->authManager->getAssignments($user->id)), 'its roles stay');
        $this->assertSame([], $this->reenabledSince($auditId, $user));
        $failures = $this->failuresSince($auditId, $user);
        $this->assertCount(1, $failures);
        $this->assertSame(
            ['username' => $user->username, 'reason' => LoginForm::DEACTIVATED_IN_ANSILUME],
            json_decode((string)$failures[0]->metadata, true)
        );
        $this->assertFalse($this->form((string)$user->username, self::DIRECTORY_PASSWORD)->login());
        $this->assertSame(User::STATUS_INACTIVE, (int)$this->reload($user)->status);
    }

    /**
     * A wrong password is refused by the directory before the account is
     * looked at: ldap.login.failed records the directory's reason.
     */
    public function testAWrongPasswordOfADeactivatedAccountRecordsTheDirectorysReason(): void
    {
        $user = $this->ldapUser('admin-off-wrong-');
        $this->deactivateInAnsilume($user);
        $this->addToDirectory($user);
        $auditId = $this->lastAuditId();

        $form = $this->form((string)$user->username, 'not-the-password');

        $this->assertFalse($form->validate());
        $this->assertSame([LoginForm::INCORRECT_CREDENTIALS], $form->getErrors('password'));
        $failures = $this->failuresSince($auditId, $user);
        $this->assertCount(1, $failures);
        $this->assertNotSame(LoginForm::DEACTIVATED_IN_ANSILUME, json_decode((string)$failures[0]->metadata, true)['reason'] ?? null);
    }

    /**
     * The account ldap/sync deactivated because the directory no longer had
     * the user signs in once the directory has them again, and is active
     * again with its mark cleared.
     */
    public function testAnAccountLdapSyncDeactivatedSignsInAndIsActivatedAgain(): void
    {
        $user = $this->ldapUser('gone-');
        $this->assertTrue((new LdapUserProvisioner())->disableMissingUser($user, LdapService::USER_NOT_FOUND));
        $this->addToDirectory($user);
        $auditId = $this->lastAuditId();

        $form = $this->form((string)$user->username, self::DIRECTORY_PASSWORD);

        $this->assertTrue($form->validate(), implode(' ', $form->getErrorSummary(true)));
        $this->assertSame((int)$user->id, (int)$form->getUserModel()?->id);
        $stored = $this->reload($user);
        $this->assertSame(User::STATUS_ACTIVE, (int)$stored->status);
        $this->assertFalse((bool)$stored->ldap_sync_disabled);
        $this->assertCount(1, $this->reenabledSince($auditId, $user));
        $this->assertSame([], $this->failuresSince($auditId, $user));
    }

    /**
     * regression: while LDAP is enabled the form resolves inactive users,
     * and an inactive local user's right password passed (the session died
     * on the next request). Refused as without LDAP.
     */
    public function testRegressionAnInactiveLocalUserIsRefusedWhileLdapIsEnabled(): void
    {
        $user = $this->localUser('local-off-');
        $user->status = User::STATUS_INACTIVE;
        $user->save(false);

        $form = $this->form((string)$user->username, self::LOCAL_PASSWORD);

        $this->assertFalse($form->validate());
        $this->assertSame([LoginForm::INCORRECT_CREDENTIALS], $form->getErrors('password'));
        $this->assertFalse($this->form((string)$user->username, self::LOCAL_PASSWORD)->login());
    }

    public function testAnActiveLocalUserStillSignsInWhileLdapIsEnabled(): void
    {
        $user = $this->localUser('local-on-');

        $form = $this->form((string)$user->username, self::LOCAL_PASSWORD);

        $this->assertTrue($form->validate(), implode(' ', $form->getErrorSummary(true)));
        $this->assertSame((int)$user->id, (int)$form->getUserModel()?->id);
    }

    /**
     * An account deactivated in Ansilume after the form looked at it, and
     * before the provisioner saved it, comes back inactive: refused as well.
     */
    public function testAUserTheProvisionerReturnsInactiveIsRefused(): void
    {
        $user = $this->ldapUser('meanwhile-');
        $this->addToDirectory($user);
        $this->deactivateInAnsilume($user);
        \Yii::$app->set('ldapUserProvisioner', new class ($user) extends LdapUserProvisioner {
            public function __construct(private readonly User $deactivated)
            {
                parent::__construct();
            }

            public function resolveUser(LdapAuthResult $result): ?User
            {
                return null;
            }

            public function provisionOrUpdate(LdapAuthResult $result, LdapConfig $config): ?User
            {
                return $this->deactivated;
            }
        });
        $auditId = $this->lastAuditId();

        $form = $this->form((string)$user->username, self::DIRECTORY_PASSWORD);

        $this->assertFalse($form->validate());
        $this->assertSame([LoginForm::INCORRECT_CREDENTIALS], $form->getErrors('password'));
        $failures = $this->failuresSince($auditId, $user);
        $this->assertCount(1, $failures);
        $this->assertSame(LoginForm::DEACTIVATED_IN_ANSILUME, json_decode((string)$failures[0]->metadata, true)['reason'] ?? null);
    }

    private function enableLdap(): FakeLdapClient
    {
        $params = [
            'enabled' => true,
            'host' => 'fake',
            'baseDn' => 'dc=test',
            'attrUsername' => 'uid',
            'attrEmail' => 'mail',
            'attrUid' => 'entryUUID',
            'autoProvision' => true,
        ];
        \Yii::$app->params['ldap'] = $params;
        $client = new FakeLdapClient(LdapConfig::fromArray($params));
        $service = new LdapService();
        $service->client = $client;
        \Yii::$app->set('ldapService', $service);
        \Yii::$app->set('ldapUserProvisioner', new LdapUserProvisioner());

        return $client;
    }

    private function addToDirectory(User $user, string $email = ''): void
    {
        $this->directory->addUser((string)$user->username, (string)$user->ldap_dn, self::DIRECTORY_PASSWORD, [
            'uid' => (string)$user->username,
            'mail' => $email !== '' ? $email : (string)$user->email,
            'entryUUID' => (string)$user->ldap_uid,
        ]);
    }

    private function form(string $username, string $password): LoginForm
    {
        $form = new LoginForm();
        $form->username = $username;
        $form->password = $password;

        return $form;
    }

    private function ldapUser(string $prefix): User
    {
        $user = new User();
        $user->username = 'login-ldap-' . $prefix . uniqid('', true);
        $user->email = $user->username . '@ldap.local';
        $user->markAsLdapManaged();
        $user->ldap_uid = 'guid-' . $user->username;
        $user->ldap_dn = 'uid=' . $user->username . ',dc=test';
        $user->last_synced_at = 1700000000;
        $user->generateAuthKey();
        $user->status = User::STATUS_ACTIVE;
        $user->save(false);

        return $user;
    }

    private function localUser(string $prefix): User
    {
        $user = new User();
        $user->username = 'login-local-' . $prefix . uniqid('', true);
        $user->email = $user->username . '@example.com';
        $user->auth_source = User::AUTH_SOURCE_LOCAL;
        $user->setPassword(self::LOCAL_PASSWORD);
        $user->status = User::STATUS_ACTIVE;
        $user->save(false);

        return $user;
    }

    /**
     * Deactivated as the status toggle does it: a save of the status.
     */
    private function deactivateInAnsilume(User $user): void
    {
        $user->status = User::STATUS_INACTIVE;
        $user->save(false);
    }

    private function assignViewer(User $user): void
    {
        $auth = \Yii::$app->authManager;
        $role = $auth->getRole('viewer');
        $this->assertNotNull($role);
        $auth->assign($role, $user->id);
    }

    private function reload(User $user): User
    {
        $fresh = User::findOne($user->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    /**
     * The ldap.login.failed entries of $user's username after $auditId.
     *
     * @return list<AuditLog>
     */
    private function failuresSince(int $auditId, User $user): array
    {
        /** @var list<AuditLog> $entries */
        $entries = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_LDAP_LOGIN_FAILED])
            ->andWhere(['>', 'id', $auditId])
            ->andWhere(['like', 'metadata', (string)$user->username])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return $entries;
    }

    /**
     * The ldap.user.reenabled entries of $user after $auditId.
     *
     * @return list<AuditLog>
     */
    private function reenabledSince(int $auditId, User $user): array
    {
        /** @var list<AuditLog> $entries */
        $entries = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_LDAP_USER_REENABLED, 'object_type' => 'user', 'object_id' => $user->id])
            ->andWhere(['>', 'id', $auditId])
            ->all();

        return $entries;
    }
}
