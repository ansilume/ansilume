<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\commands\SetupController;
use app\components\LdapSyncDisabledBehavior;
use app\models\AuditLog;
use app\models\User;
use app\services\UserChangePolicy;
use PHPUnit\Framework\TestCase;

/**
 * What the docs say about getting back a superadmin who can sign in is what
 * the code does: the troubleshooting entry "No superadmin can sign in any
 * more", the note of installation.md, and the intro, step 8 and the fixes
 * of the section of docs/updating.md for the update from 2.10.1, in the
 * words of SetupController (ACTIVE_SUPERADMIN_EXISTS) and of the refusal
 * they name. setup/admin acts while User::activeSuperadminExists() is
 * false; the accounts item is pinned by
 * DelegatedAdministrationDocsContractTest.
 *
 * Regression (wave 5e of 2.11.0): the only-superadmin checks counted
 * inactive superadmins, setup/admin aborted while any superadmin existed,
 * and from 2.11.0 on an admin no longer activates a superadmin, so nothing
 * brought back a superadmin who could sign in; the docs said to make
 * another user a superadmin first, which takes a superadmin. Each test
 * fails on the docs from before.
 *
 * Regression (wave 5f of 2.11.0): the entry said ldap/sync deactivates
 * every LDAP account while the service bind or the user search fails, as it
 * did; it deactivates only an account whose user search ran and found no
 * entry now (LdapLookupResult::MISSING), and changes nothing for a lookup
 * that fails. GET /health counted inactive superadmins. Fails on the docs
 * from before.
 *
 * Regression (wave 5g of 2.11.0): the fix said the sign-in of an LDAP
 * superadmin activates the account, whoever deactivated it; it activates
 * only an account that user.ldap_sync_disabled marks now. Fails on the
 * docs from before.
 */
class SuperadminRecoveryDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;
    use ReadsTheUpdateSection;

    private const ENTRY = '## No superadmin can sign in any more';

    /** The anchor GitHub gives the entry. */
    private const ANCHOR = 'no-superadmin-can-sign-in-any-more';

    /**
     * regression: nothing said how a superadmin signs in again. The entry
     * names what makes every superadmin inactive, in the words of the code
     * (UserChangePolicy::CHANGE_REFUSED, the audit actions of ldap/sync and
     * setup/admin), and quotes what setup/admin answers while an active
     * superadmin exists.
     */
    public function testRegressionTheTroubleshootingEntrySaysHowASuperadminSignsInAgain(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', self::ENTRY);
        $expected = [
            '**Symptom:** Nobody can sign in as a superadmin: every account with the superadmin flag is inactive, or no account has the flag any more.',
            '`PUT /api/v1/users/{id}` answers them `403` `' . UserChangePolicy::CHANGE_REFUSED . '`',
            '**Cause:** From 2.11.0 on, only superadmins change superadmins, so once no superadmin can sign in, nothing in the web UI or the API makes one active again.',
            '`php yii ldap/sync` deactivates an LDAP account, a superadmin\'s too, when the directory\'s user search runs and finds no entry for it, such as one removed from the directory.',
            'It revokes the account\'s roles and deletes its API tokens, keeps its superadmin flag, and records `' . AuditLog::ACTION_LDAP_USER_DISABLED . '` in the audit log.',
            'Up to 2.10.1 it did so for every LDAP account whose lookup failed as well: all of them while the service bind failed or LDAP was enabled without a host or base DN, and each one whose user search failed; from 2.11.0 on, a lookup that fails changes nothing.',
            'Up to 2.10.1, **Deactivate** (with `user.delete`) deactivated any other superadmin, and `PUT /api/v1/users/{id}` with `status` 0 (with `user.update`) any superadmin, the last active one too.',
            'An inactive superadmin counted as another superadmin, so the last active superadmin could also take their own flag while the only other superadmin was inactive.',
            'Two superadmins deactivate, demote or delete each other at the same moment: the checks take no lock.',
            '**Fix:** An LDAP superadmin whom `ldap/sync` deactivated while the directory could not be read (up to 2.10.1) signs in again once it can be read: the sign-in, like the next `ldap/sync` that finds them, activates the account (`'
                . AuditLog::ACTION_LDAP_USER_REENABLED . '`), with the roles their groups map to.',
            'That holds while `user.' . LdapSyncDisabledBehavior::COLUMN . '` marks the account, as the migration of 2.11.0 does for an account whose latest status entry in the audit log is `'
                . AuditLog::ACTION_LDAP_USER_DISABLED . '`; an account changed in Ansilume after that stays inactive whatever the directory says (see [An LDAP user cannot sign in: the account was deactivated in Ansilume](#an-ldap-user-cannot-sign-in-the-account-was-deactivated-in-ansilume)).',
            'Otherwise, while no active superadmin exists, run ```bash docker compose exec -u www-data app php yii setup/admin <username> <email> [password] ```',
            'It creates an active superadmin with the `admin` role, and prints a generated password when none is given.',
            'Use a username and an email that no user has, not those of an old superadmin: a taken one fails with `Failed to create user: …` and creates nothing.',
            'The run is audited as `' . AuditLog::ACTION_USER_CREATED . '` with the source `console:setup/admin`, and leaves the seeded Selftest and Demo content alone.',
            'While an active superadmin exists, `setup/admin` answers `' . SetupController::ACTIVE_SUPERADMIN_EXISTS . '` and changes nothing: sign in as that superadmin instead.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $entry, 'troubleshooting.md, on a lost superadmin');
        }
        $this->assertStringNotContainsString('but also every one while the service bind or the user search fails', $entry, 'A lookup that fails deactivates nobody now.');
        $this->assertStringNotContainsString('Fix the directory connection before the next', $entry, 'A sync that cannot read the directory changes nothing now.');

        $setup = $this->source('commands/SetupController.php');
        $this->assertStringContainsString('if (User::activeSuperadminExists()) {', $setup, 'setup/admin aborts only while an active superadmin exists.');
        $this->assertStringContainsString('Usage: php yii setup/admin [username] [email] [password]', $setup);
        $this->assertStringContainsString("\$role = \$auth->getRole('admin');", $setup, 'The new superadmin gets the admin role.');
        $this->assertStringContainsString("'source' => 'console:setup/admin'", $setup);
        $this->assertStringContainsString('$this->stderr("Failed to create user: "', $setup);
        $this->assertStringContainsString('$user->status = User::STATUS_ACTIVE;', $setup);
    }

    /**
     * regression (wave 5f): what the entry says ldap/sync does is what the
     * code does. A lookup answers LdapLookupResult::MISSING only for a user
     * search that ran and found no entry (LdapService::lookup(), and
     * PhpLdapClient, which takes a search that fails or does not run to the
     * end for a failure); a failed group search is a failure too. ldap/sync
     * disables only a missing user (LdapController::handleNoUser()), with
     * the reason LdapService::USER_NOT_FOUND, revoking their roles and API
     * tokens but not their flag (LdapUserProvisioner::disableMissingUser()),
     * counts a failed lookup as an error, stops at a failed service bind and
     * before the first user while LDAP is not fully configured. A sign-in
     * finds an inactive LDAP user and activates them again when
     * user.ldap_sync_disabled marks the account
     * (LdapUserProvisioner::staysInactive()).
     */
    public function testWhatTheEntrySaysOfLdapSyncIsWhatTheCodeDoes(): void
    {
        $lookup = $this->source('services/ldap/LdapService.php');
        $this->assertSame(
            1,
            preg_match('/private function noEntry\(\?string \$searchError\): LdapLookupResult\s*\{\s*if \(\$searchError !== null\) \{\s*return \$this->failedLookup\(LdapLookupResult::SEARCH_FAILED, \$searchError\);\s*\}\s*\$this->lastError = self::USER_NOT_FOUND;\s*return LdapLookupResult::missing\(\);/', $lookup),
            'Only a search that reports no error and finds no entry answers MISSING.'
        );
        $this->assertSame(
            1,
            preg_match('/\$groupError = \$client->getLastError\(\);\s*if \(\$groupError !== null\) \{\s*return \$this->failedLookup\(LdapLookupResult::GROUPS_FAILED, \$groupError\);/', $lookup),
            'A failed group search is a failed lookup.'
        );
        $this->assertSame(
            1,
            preg_match('/ldap_errno\(\$conn\)\) !== 0\) \{[^}]*\$this->captureError\(self::USER_SEARCH_FAILED\);\s*return null;/', $this->source('services/ldap/PhpLdapClient.php')),
            'A user search that fails or does not run to the end reports an error.'
        );
        $sync = $this->source('commands/LdapController.php');
        $this->assertSame(
            1,
            preg_match('/if \(\$lookup->outcome === LdapLookupResult::MISSING\) \{\s*\$this->handleMissingUser\(\$user, \$username, LdapService::USER_NOT_FOUND, \$prov, \$counts\);/', $sync),
            'Only a missing user is disabled.'
        );
        $this->assertSame(
            1,
            preg_match('/\$this->stderr\(sprintf\(self::SYNC_LOOKUP_FAILED, \$username, \(string\)\$lookup->error\)\);\s*\$counts\[\'errors\'\]\+\+;/', $sync),
            'A failed lookup is counted as an error.'
        );
        $this->assertSame(1, preg_match('/if \(\$lookup->outcome === LdapLookupResult::BIND_FAILED\) \{[^}]*break;/', $sync), 'A failed service bind stops the run.');
        $this->assertSame(
            1,
            preg_match('/if \(!\$config->isUsable\(\)\) \{\s*\$this->stderr\(self::SYNC_NOT_USABLE\);\s*return ExitCode::CONFIG;/', $sync),
            'LDAP that is not fully configured stops the run before the first user.'
        );
        $provisioner = $this->source('services/ldap/LdapUserProvisioner.php');
        $this->assertStringContainsString('AuditLog::ACTION_LDAP_USER_REENABLED,', $provisioner);
        $disable = $this->between($provisioner, 'public function disableMissingUser(', 'private function audit(');
        foreach (['\app\models\ApiToken::deleteAll([\'user_id\' => $user->id]);', '$auth?->revokeAll((int)$user->id);', 'AuditLog::ACTION_LDAP_USER_DISABLED,'] as $line) {
            $this->assertStringContainsString($line, $disable);
        }
        $this->assertStringNotContainsString('is_superadmin', $disable, 'ldap/sync keeps the superadmin flag.');
        $this->assertStringContainsString("\$any = User::find()->where(['username' => \$this->username])->one();", $this->source('models/LoginForm.php'), 'A sign-in finds an inactive LDAP user.');
        $this->assertStringContainsString(
            'return (int)$user->status !== User::STATUS_ACTIVE && !$user->ldap_sync_disabled;',
            $provisioner,
            'Only an account that user.ldap_sync_disabled marks is activated again.'
        );
    }

    /**
     * regression (wave 5f): GET /health counted inactive superadmins under
     * checks.users.superadmins. It counts the superadmins who can sign in,
     * as User::activeSuperadminExists() decides on them, and the fixes of
     * updating.md say so.
     */
    public function testRegressionTheFixesSayTheHealthCheckCountsOnlyActiveSuperadmins(): void
    {
        $this->assertMentions(
            '- `GET /health` counts under `checks.users.superadmins` only the superadmins who can sign in, those with an active account; it counted inactive superadmins too, so it could show superadmins while none could sign in. `ok` and `total` are unchanged.',
            $this->between($this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION), '**Fixes that change behaviour:**', '**API contract change:**'),
            'The fixes of updating.md'
        );
        $health = $this->source('controllers/HealthController.php');
        $this->assertStringContainsString("'users' => \$this->checkUsers(),", $health, 'The check the fix names.');
        $this->assertStringContainsString("\$admins = (int)User::find()->where(['is_superadmin' => true, 'status' => User::STATUS_ACTIVE])->count();", $health, 'Only active superadmins count.');
        $this->assertStringContainsString("return ['ok' => true, 'total' => \$total, 'superadmins' => \$admins];", $health, 'ok and total are as before.');
        $this->assertStringContainsString("static::find()->where(['is_superadmin' => true, 'status' => self::STATUS_ACTIVE]);", $this->source('models/User.php'), 'The condition of User::activeSuperadminExists().');
    }

    /**
     * The note of installation.md says when setup/admin seeds the demo
     * content and when it creates a superadmin; the fixes of updating.md say
     * that only the first setup seeds (SetupController runs the deferred
     * seed migrations only when no user existed before the run).
     */
    public function testTheInstallationNoteAndTheFixesSayWhenSetupAdminSeeds(): void
    {
        $this->assertMentions(
            '> Migrations run automatically on startup. The first `setup/admin`, run while no user exists, also seeds the demo project and selftest template. While no active superadmin exists, `setup/admin` creates a new superadmin, the way back in when no superadmin can sign in (see [troubleshooting.md](troubleshooting.md#'
                . self::ANCHOR . ')); while one exists it answers `' . SetupController::ACTIVE_SUPERADMIN_EXISTS . '` and changes nothing.',
            $this->flat('docs/installation.md'),
            'installation.md'
        );
        $fixes = $this->between($this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION), '**Fixes that change behaviour:**', '**API contract change:**');
        $this->assertMentions(
            '- `php yii setup/admin` ran the seed migrations of the Selftest and Demo content (m000020, m000035, m000036) again on every run that created a superadmin; only the first setup, run while no user exists, runs them now. A later run no longer recreates a removed Selftest template or Demo project, queues no clone of the demo repository, and gives no seeded template without a runner group the `default` group.',
            $fixes,
            'The fixes of updating.md'
        );
        $setup = $this->source('commands/SetupController.php');
        $this->assertStringContainsString('$firstSetup = !User::find()->exists();', $setup);
        $this->assertSame(1, preg_match('/if \(\$firstSetup\) \{\s*\$this->applyDeferredSeeds\(\);/', $setup), 'Only the first setup runs the seeds.');
        foreach (['m000020_000000_seed_selftest_template', 'm000035_000000_seed_demo_project', 'm000036_000000_assign_default_runner_group_to_seeded_templates'] as $migration) {
            $this->assertStringContainsString("'{$migration}'", $setup, 'The seed migrations the fix names.');
            $this->assertFileExists(dirname(__DIR__, 3) . '/migrations/' . $migration . '.php');
        }
        $this->assertStringContainsString('SyncProjectJob', $this->source('migrations/m000035_000000_seed_demo_project.php'), 'The demo seed queues a clone of its repository.');
        $this->assertStringContainsString('WHERE runner_group_id IS NULL', $this->source('migrations/m000036_000000_assign_default_runner_group_to_seeded_templates.php'));
    }

    /**
     * The intro and step 8 of the section's checklist say that setup/admin
     * creates a superadmin while none is active (status
     * User::STATUS_ACTIVE, which the query of step 8 lists), and the
     * entries link the troubleshooting entry by its anchor.
     */
    public function testTheIntroAndStep8SayWhenToRunSetupAdmin(): void
    {
        $this->assertSame(10, User::STATUS_ACTIVE, 'The status step 8 names.');
        $this->assertMentions(
            '`php yii setup/admin` creates a superadmin whenever no active superadmin exists, so that one can sign in again (see **Security: who may change other users\' accounts**).',
            $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION),
            'The intro of updating.md'
        );
        $this->assertMentions(
            'If no superadmin it lists is active (`status` 10), create one with `php yii setup/admin` (see **Security: who may change other users\' accounts**): from 2.11.0 on, only superadmins change superadmins, so an admin can no longer activate one.',
            $this->checklist(8),
            'Step 8 of the checklist'
        );
        $this->assertStringContainsString("\n" . self::ENTRY . "\n", $this->read('docs/troubleshooting.md'), 'The entry the links name.');
        $this->assertMentions('[No superadmin can sign in any more](#' . self::ANCHOR . ')', $this->flat('docs/troubleshooting.md', '## Changing a user is refused'), 'troubleshooting.md, on refused user changes');
    }

    private function source(string $file): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
    }
}
