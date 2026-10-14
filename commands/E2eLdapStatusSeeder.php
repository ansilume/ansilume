<?php

declare(strict_types=1);

namespace app\commands;

use app\models\User;

/**
 * Seeds two inactive LDAP accounts for the note under the status of the
 * user page (tests/e2e/tests/ldap/deactivation.spec.ts):
 * - e2e-dir-gone, deactivated by ldap/sync because the directory no longer
 *   had the user (user.ldap_sync_disabled): an LDAP sign-in or an ldap/sync
 *   that finds the user activates it again;
 * - e2e-dir-locked, deactivated in Ansilume: the directory does not activate
 *   it again (CLAUDE.md security rule 15).
 *
 * Restored on every seed, since the spec activates and deactivates
 * e2e-dir-gone. Nobody signs in as them: the E2E stack has no directory.
 * They hold no role. e2e/teardown removes them with every other e2e- user.
 */
final class E2eLdapStatusSeeder
{
    /** The account ldap/sync deactivated: the mark is set. */
    public const SYNC_DISABLED = 'e2e-dir-gone';

    /** The account deactivated in Ansilume: no mark. */
    public const ANSILUME_DISABLED = 'e2e-dir-locked';

    /** What the seed prints for each account: the username, its ID, then how it was deactivated. */
    public const RESTORED = "  Restored inactive LDAP user %s (ID %d), deactivated %s.\n";

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(): void
    {
        foreach ([self::SYNC_DISABLED => true, self::ANSILUME_DISABLED => false] as $username => $bySync) {
            $user = $this->restore($username, $bySync);
            ($this->logger)(sprintf(self::RESTORED, $username, (int)$user->id, $bySync ? 'by ldap/sync' : 'in Ansilume'));
        }
    }

    /**
     * The account, created or reset: inactive, LDAP-managed, the mark as
     * $bySync says.
     */
    private function restore(string $username, bool $bySync): User
    {
        $user = User::findOne(['username' => $username]) ?? new User();
        if ($user->isNewRecord) {
            $user->username = $username;
            $user->generateAuthKey();
        }
        $user->email = $username . '@example.com';
        $user->markAsLdapManaged();
        $user->ldap_dn = 'uid=' . $username . ',dc=e2e,dc=test';
        $user->ldap_uid = 'guid-' . $username;
        $user->last_synced_at = time();
        $user->is_superadmin = false;
        $user->status = User::STATUS_INACTIVE;
        $user->save(false);
        // Written after the status, without the events of a save: the mark is
        // as $bySync says, whatever state an earlier seed or the spec left.
        $user->updateAttributes(['ldap_sync_disabled' => $bySync]);

        return $user;
    }
}
