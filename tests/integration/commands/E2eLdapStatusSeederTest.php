<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eLdapStatusSeeder;
use app\commands\E2eTeardownHelper;
use app\controllers\UserController;
use app\models\User;
use app\tests\integration\DbTestCase;

/**
 * The fixtures of tests/e2e/tests/ldap/deactivation.spec.ts: an inactive
 * LDAP account that ldap/sync deactivated (user.ldap_sync_disabled) and one
 * deactivated in Ansilume, restored on every seed. The test database holds
 * no "e2e-" rows outside a test, and the rollback removes them.
 */
class E2eLdapStatusSeederTest extends DbTestCase
{
    /** @var list<string> */
    private array $log = [];

    public function testSeedsAnAccountLdapSyncDeactivatedAndOneDeactivatedInAnsilume(): void
    {
        $this->seed();

        $bySync = $this->seeded(E2eLdapStatusSeeder::SYNC_DISABLED);
        $inAnsilume = $this->seeded(E2eLdapStatusSeeder::ANSILUME_DISABLED);
        foreach ([$bySync, $inAnsilume] as $user) {
            $this->assertTrue($user->isLdap(), $user->username);
            $this->assertSame(User::LDAP_PASSWORD_SENTINEL, $user->password_hash, 'nobody signs in with a local password');
            $this->assertSame(User::STATUS_INACTIVE, (int)$user->status);
            $this->assertFalse((bool)$user->is_superadmin);
            $this->assertSame('uid=' . $user->username . ',dc=e2e,dc=test', $user->ldap_dn);
            $this->assertSame([], \Yii::$app->authManager->getAssignments($user->id), 'no role');
        }
        $this->assertTrue((bool)$bySync->ldap_sync_disabled);
        $this->assertFalse((bool)$inAnsilume->ldap_sync_disabled);
        $this->assertSame([
            sprintf(E2eLdapStatusSeeder::RESTORED, E2eLdapStatusSeeder::SYNC_DISABLED, (int)$bySync->id, 'by ldap/sync'),
            sprintf(E2eLdapStatusSeeder::RESTORED, E2eLdapStatusSeeder::ANSILUME_DISABLED, (int)$inAnsilume->id, 'in Ansilume'),
        ], $this->log);
    }

    /**
     * The spec activates e2e-dir-gone and deactivates it again, which clears
     * its mark; the next seed restores both accounts instead of adding them,
     * whatever state they were left in.
     */
    public function testSeedingAgainRestoresTheAccountsWhateverStateTheyWereLeftIn(): void
    {
        $this->seed();
        $bySync = $this->seeded(E2eLdapStatusSeeder::SYNC_DISABLED);
        $bySync->status = User::STATUS_ACTIVE;
        $bySync->save(false);
        $bySync->status = User::STATUS_INACTIVE;
        $bySync->save(false);
        $inAnsilume = $this->seeded(E2eLdapStatusSeeder::ANSILUME_DISABLED);
        $inAnsilume->status = User::STATUS_ACTIVE;
        $inAnsilume->ldap_sync_disabled = true;
        $inAnsilume->save(false);

        $this->seed();

        foreach ([E2eLdapStatusSeeder::SYNC_DISABLED, E2eLdapStatusSeeder::ANSILUME_DISABLED] as $username) {
            $this->assertSame(1, (int)User::find()->where(['username' => $username])->count(), $username);
        }
        $again = $this->seeded(E2eLdapStatusSeeder::SYNC_DISABLED);
        $this->assertSame((int)$bySync->id, (int)$again->id);
        $this->assertSame([User::STATUS_INACTIVE, true], [(int)$again->status, (bool)$again->ldap_sync_disabled]);
        $locked = $this->seeded(E2eLdapStatusSeeder::ANSILUME_DISABLED);
        $this->assertSame((int)$inAnsilume->id, (int)$locked->id);
        $this->assertSame([User::STATUS_INACTIVE, false], [(int)$locked->status, (bool)$locked->ldap_sync_disabled]);
    }

    /**
     * Other specs pick rows of the users list by text they contain
     * (E2eAdminRoleSeederTest lists them), and the LDAP user page checks
     * the directory UID of e2e-ldap-user: the usernames, e-mail addresses
     * and directory UIDs contain none of it.
     */
    public function testTheFixturesContainNoTextOtherSpecsPickRowsBy(): void
    {
        $this->seed();

        $picked = ['admin', 'e2e-team', 'e2e-viewer', 'e2e-newuser', 'e2e-ldap-user', 'e2e-referenced-user', 'e2e-crud-role', 'e2e-custom-role', 'guid-e2e-ldap'];
        foreach ([E2eLdapStatusSeeder::SYNC_DISABLED, E2eLdapStatusSeeder::ANSILUME_DISABLED] as $username) {
            $user = $this->seeded($username);
            foreach ([$user->username, $user->email, (string)$user->ldap_uid] as $text) {
                foreach ($picked as $needle) {
                    $this->assertStringNotContainsString($needle, $text);
                }
            }
        }
    }

    /**
     * yii e2e/teardown removes both accounts with every other user named
     * with the prefix "e2e-". The teardown runs here with the fixtures' own
     * prefix, so it touches nothing else.
     */
    public function testTheTeardownRemovesBothAccounts(): void
    {
        $this->seed();
        foreach ([E2eLdapStatusSeeder::SYNC_DISABLED, E2eLdapStatusSeeder::ANSILUME_DISABLED] as $username) {
            $this->assertStringStartsWith('e2e-dir-', $username);
        }

        $problems = (new E2eTeardownHelper('e2e-dir-', static function (string $message): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        $this->assertNull(User::findOne(['username' => E2eLdapStatusSeeder::SYNC_DISABLED]));
        $this->assertNull(User::findOne(['username' => E2eLdapStatusSeeder::ANSILUME_DISABLED]));
    }

    /**
     * The spec quotes the notes of the user page and the fixtures' usernames
     * as the code has them.
     */
    public function testTheSpecQuotesTheNotesAndTheFixturesAsTheCodeHasThem(): void
    {
        $spec = file_get_contents(dirname(__DIR__, 2) . '/e2e/tests/ldap/deactivation.spec.ts');
        $this->assertIsString($spec);

        $quoted = [
            'DEACTIVATED_BY_LDAP_SYNC' => UserController::DEACTIVATED_BY_LDAP_SYNC,
            'DEACTIVATED_IN_ANSILUME' => UserController::DEACTIVATED_IN_ANSILUME,
            'SYNC_DISABLED' => E2eLdapStatusSeeder::SYNC_DISABLED,
            'ANSILUME_DISABLED' => E2eLdapStatusSeeder::ANSILUME_DISABLED,
        ];
        foreach ($quoted as $name => $value) {
            $this->assertStringNotContainsString("'", $value, $name);
            $this->assertStringContainsString("const {$name} = '{$value}';", $spec, $name);
        }
    }

    private function seed(): void
    {
        (new E2eLdapStatusSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed();
    }

    private function seeded(string $username): User
    {
        $user = User::findOne(['username' => $username]);
        $this->assertNotNull($user, $username);

        return $user;
    }
}
