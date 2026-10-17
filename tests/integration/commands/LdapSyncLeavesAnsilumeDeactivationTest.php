<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\LdapController;
use app\models\AuditLog;
use app\models\User;
use app\services\ldap\FakeLdapClient;
use app\services\ldap\LdapConfig;
use app\services\ldap\LdapService;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use yii\console\ExitCode;

/**
 * ldap/sync and an inactive account the directory has (CLAUDE.md security
 * rule 15): it activates again only an account it deactivated itself
 * because the directory no longer had the user (user.ldap_sync_disabled).
 * One deactivated in Ansilume stays inactive: sync takes what the directory
 * says of it (DN, email, roles), prints LdapController::SYNC_LEFT_INACTIVE
 * and counts it as updated, not as re-enabled; --dry-run says the same.
 *
 * Up to 2.10.1 the next ldap/sync activated every inactive LDAP account the
 * directory had, also one an admin had deactivated (ldap.user.reenabled).
 *
 * The test database holds LDAP users of other tests that the directory of
 * these tests does not have: sync disables them inside the test's
 * transaction, so only the counts of updated and re-enabled users are exact.
 */
class LdapSyncLeavesAnsilumeDeactivationTest extends DbTestCase
{
    use RestoresComponents;

    /** @var array<string, string> */
    private const ROLE_MAPPING = ['Ops' => 'operator'];

    /** @var array<string, mixed>|null */
    private ?array $originalLdapParams = null;

    private FakeLdapClient $directory;

    protected function setUp(): void
    {
        parent::setUp();
        // enableLdap() replaces it; tearDown() puts back only what setUp() kept.
        $this->keepComponent('ldapService');
        $params = \Yii::$app->params['ldap'] ?? null;
        $this->originalLdapParams = is_array($params) ? $params : null;
        $auth = \Yii::$app->authManager;
        foreach (['operator', 'viewer'] as $name) {
            if ($auth->getRole($name) === null) {
                $auth->add($auth->createRole($name));
            }
        }
        $this->directory = $this->enableLdap();
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        if ($this->originalLdapParams !== null) {
            \Yii::$app->params['ldap'] = $this->originalLdapParams;
        } else {
            unset(\Yii::$app->params['ldap']);
        }
        parent::tearDown();
    }

    /**
     * regression: ldap/sync activated an account an admin had deactivated.
     * It stays inactive and unmarked, and takes the DN, email and roles the
     * directory gives it, as every user the directory has.
     */
    public function testRegressionSyncLeavesAnAccountDeactivatedInAnsilumeInactive(): void
    {
        $user = $this->ldapUser('admin-off-');
        $this->assignRole($user, 'viewer');
        $this->deactivateInAnsilume($user);
        $this->addToDirectory($user, 'moved@example.com');
        $auditId = $this->lastAuditId();

        [$exit, $out] = $this->sync();

        $this->assertSame(ExitCode::OK, $exit);
        $stored = $this->reload($user);
        $this->assertSame(User::STATUS_INACTIVE, (int)$stored->status);
        $this->assertFalse((bool)$stored->ldap_sync_disabled);
        $this->assertSame(0, $this->reenabledSince($auditId, $user));
        $this->assertMatchesRegularExpression('/\[ldap\] Done\. updated=1 disabled=\d+ re-enabled=0 errors=0\n/', $out);
        $this->assertSame('moved@example.com', $stored->email, 'what the directory says is taken');
        $this->assertSame(['operator'], $this->rolesOf($user), 'the roles follow the directory');
        $this->assertStringContainsString(sprintf(LdapController::SYNC_LEFT_INACTIVE, $user->username), $out);
    }

    public function testADryRunSaysTheSameAndChangesNothing(): void
    {
        $user = $this->ldapUser('admin-off-dry-');
        $this->deactivateInAnsilume($user);
        $this->addToDirectory($user, 'moved@example.com');
        $before = $this->reload($user)->getAttributes();

        [$exit, $out] = $this->sync(true);

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertStringContainsString(
            "  - {$user->username}: present (dn={$user->ldap_dn}, roles=operator)\n" . sprintf(LdapController::SYNC_LEFT_INACTIVE, $user->username),
            $out
        );
        $this->assertStringNotContainsString('would RE-ENABLE', $out);
        $this->assertMatchesRegularExpression('/\[ldap\] Done\. updated=1 disabled=\d+ re-enabled=0 errors=0\n/', $out);
        $this->assertSame($before, $this->reload($user)->getAttributes());
    }

    /**
     * The account ldap/sync deactivated because the directory no longer had
     * the user: marked, skipped while still missing with its mark kept, and
     * activated again by the sync that finds the user, its mark cleared.
     */
    public function testAnAccountSyncDeactivatedComesBackWithTheSyncThatFindsIt(): void
    {
        $user = $this->ldapUser('gone-');

        [, $first] = $this->sync();
        $this->assertStringContainsString("  - {$user->username}: missing in directory → DISABLE (" . LdapService::USER_NOT_FOUND . ")\n", $first);
        $this->assertSame([User::STATUS_INACTIVE, true], $this->stateOf($user), 'deactivated and marked');

        [, $second] = $this->sync();
        $this->assertStringContainsString("  - {$user->username}: missing, already inactive — skipped\n", $second);
        $this->assertSame([User::STATUS_INACTIVE, true], $this->stateOf($user), 'the mark stays');

        $this->addToDirectory($user);
        $auditId = $this->lastAuditId();
        [$exit, $third] = $this->sync();

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertMatchesRegularExpression('/\[ldap\] Done\. updated=1 disabled=\d+ re-enabled=1 errors=0\n/', $third);
        $this->assertStringNotContainsString(sprintf(LdapController::SYNC_LEFT_INACTIVE, $user->username), $third);
        $this->assertSame([User::STATUS_ACTIVE, false], $this->stateOf($user));
        $this->assertSame(1, $this->reenabledSince($auditId, $user));
    }

    /**
     * Runs ldap/sync and returns its exit code and what it printed to stdout
     * and stderr.
     *
     * @return array{0: int, 1: string}
     */
    private function sync(bool $dryRun = false): array
    {
        $controller = new class ('ldap', \Yii::$app) extends LdapController {
            public string $captured = '';

            public function stdout($string): int
            {
                $this->captured .= $string;
                return 0;
            }

            public function stderr($string): int
            {
                $this->captured .= $string;
                return 0;
            }
        };
        $controller->dryRun = $dryRun;
        $exit = $controller->actionSync();

        return [$exit, $controller->captured];
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
            'roleMapping' => self::ROLE_MAPPING,
        ];
        \Yii::$app->params['ldap'] = $params;
        $client = new FakeLdapClient(LdapConfig::fromArray($params));
        $service = new LdapService();
        $service->client = $client;
        \Yii::$app->set('ldapService', $service);

        return $client;
    }

    /**
     * The user in the directory, in the group that maps to operator.
     */
    private function addToDirectory(User $user, string $email = ''): void
    {
        $this->directory->addUser((string)$user->username, (string)$user->ldap_dn, 'pw', [
            'uid' => (string)$user->username,
            'mail' => $email !== '' ? $email : (string)$user->email,
            'entryUUID' => (string)$user->ldap_uid,
        ]);
        $this->directory->addGroupMembership((string)$user->ldap_dn, 'Ops');
    }

    private function ldapUser(string $prefix): User
    {
        $user = new User();
        $user->username = 'sync-stay-' . $prefix . uniqid('', true);
        $user->email = $user->username . '@ldap.local';
        $user->markAsLdapManaged();
        $user->ldap_uid = 'guid-' . $user->username;
        $user->ldap_dn = 'uid=' . $user->username . ',dc=test';
        $user->generateAuthKey();
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

    private function assignRole(User $user, string $name): void
    {
        $auth = \Yii::$app->authManager;
        $role = $auth->getRole($name);
        $this->assertNotNull($role);
        $auth->assign($role, $user->id);
    }

    /**
     * @return list<string>
     */
    private function rolesOf(User $user): array
    {
        $names = array_map('strval', array_keys(\Yii::$app->authManager->getAssignments($user->id)));
        sort($names);

        return $names;
    }

    private function reload(User $user): User
    {
        $fresh = User::findOne($user->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    /**
     * @return array{0: int, 1: bool} the stored status and mark
     */
    private function stateOf(User $user): array
    {
        $fresh = $this->reload($user);

        return [(int)$fresh->status, (bool)$fresh->ldap_sync_disabled];
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    private function reenabledSince(int $auditId, User $user): int
    {
        return (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_LDAP_USER_REENABLED, 'object_type' => 'user', 'object_id' => $user->id])
            ->andWhere(['>', 'id', $auditId])
            ->count();
    }
}
