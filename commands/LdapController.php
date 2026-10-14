<?php

declare(strict_types=1);

namespace app\commands;

use app\models\User;
use app\services\ldap\LdapConfig;
use app\services\ldap\LdapLookupResult;
use app\services\ldap\LdapService;
use app\services\ldap\LdapUserProvisioner;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * LDAP maintenance commands.
 *
 * Usage:
 *   php yii ldap/test-connection           — verify the directory is reachable and the service bind works
 *   php yii ldap/test-user <username>      — look up a single user (no password needed)
 *   php yii ldap/sync                      — reconcile every LDAP-backed user with the directory:
 *                                            update attributes/roles, disable accounts the directory
 *                                            no longer has, re-enable the ones it disabled once the
 *                                            directory has them again.
 *   php yii ldap/sync --dry-run            — same as above, but reports without making changes.
 *
 * Run `php yii ldap/sync` from cron (e.g. nightly) so accounts that have been
 * removed or disabled in the directory lose their Ansilume access too.
 *
 * Only a lookup whose user search ran and found no entry disables an account.
 * A lookup that fails says nothing about the user: sync leaves that account,
 * its roles and its API tokens as they are and counts an error. A failed
 * service bind stops the run, since every further lookup would fail too, and
 * LDAP that is enabled but not fully configured stops it before the first
 * user. The command exits non-zero whenever it counted an error.
 *
 * An inactive account the directory has is activated again only when
 * ldap/sync deactivated it (user.ldap_sync_disabled). One deactivated in
 * Ansilume stays inactive: sync updates what the directory says of it but
 * prints SYNC_LEFT_INACTIVE instead of re-enabling it (CLAUDE.md security
 * rule 15).
 */
class LdapController extends Controller
{
    /**
     * ldap/sync while LDAP is enabled but its host or base DN is not set: it
     * looks up no user, changes nothing and exits with ExitCode::CONFIG (78).
     */
    public const SYNC_NOT_USABLE = "[ldap] LDAP is enabled but not fully configured (host or base DN missing): sync stopped, no user changed.\n";

    /**
     * The service bind failed, so no lookup can succeed: ldap/sync stops
     * before the user it was about to sync (the reason, then the number of
     * users it did not sync), counts one error and exits non-zero.
     */
    public const SYNC_BIND_FAILED = "[ldap] Service bind failed (%s): sync stopped, %d user(s) left unchanged.\n";

    /**
     * A user whose lookup failed (the username, then the reason): ldap/sync
     * leaves the account, its roles and its API tokens as they are, and
     * counts an error.
     */
    public const SYNC_LOOKUP_FAILED = "  - %s: lookup failed → left unchanged (%s)\n";

    /**
     * A user the directory has whose account was deactivated in Ansilume, not
     * by ldap/sync (the username; LdapUserProvisioner::staysInactive()):
     * ldap/sync updates what the directory says of it (DN, email, roles) but
     * leaves it inactive, and counts it as updated, not as re-enabled.
     * --dry-run prints the same line.
     */
    public const SYNC_LEFT_INACTIVE = "  - %s: deactivated in Ansilume, not by ldap/sync → left inactive\n";

    /**
     * @var bool When true, sync prints planned changes but commits nothing.
     */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        $opts = parent::options($actionID);
        if ($actionID === 'sync') {
            $opts[] = 'dryRun';
        }
        return $opts;
    }

    /**
     * @return array<string, string>
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['dry-run' => 'dryRun']);
    }

    public function actionTestConnection(): int
    {
        $svc = $this->ldap();
        if ($svc === null) {
            $this->stderr("[ldap] Service not registered.\n");
            return ExitCode::CONFIG;
        }
        $diag = $svc->diagnose();
        $this->printDiagnostic($diag);

        if ($diag['error'] !== null) {
            $this->stderr("[ldap] Error:             {$diag['error']}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        return $diag['service_bind'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Render the LdapService::diagnose() output as aligned operator-readable lines.
     *
     * @param array<string, mixed> $diag
     */
    private function printDiagnostic(array $diag): void
    {
        $rows = [
            'Enabled'      => $this->yesNo((bool)$diag['enabled']),
            'Host'         => $this->orMissing((string)$diag['host']),
            'Encryption'   => (string)$diag['encryption'],
            'Base DN'      => $this->orMissing((string)$diag['base_dn']),
            'Bind DN set'  => $this->yesNo((bool)$diag['bind_dn_configured']),
            'Service bind' => $diag['service_bind'] ? 'OK' : 'FAILED',
        ];
        foreach ($rows as $label => $value) {
            $this->stdout(sprintf("[ldap] %-17s %s\n", $label . ':', $value));
        }
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function orMissing(string $value): string
    {
        return $value !== '' ? $value : '(not set)';
    }

    public function actionTestUser(string $username): int
    {
        $svc = $this->ldap();
        if ($svc === null) {
            $this->stderr("[ldap] Service not registered.\n");
            return ExitCode::CONFIG;
        }
        $result = $svc->lookupByUsername($username);
        if ($result === null) {
            $this->stderr("[ldap] Lookup failed: " . ($svc->getLastError() ?? 'unknown') . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("[ldap] DN:           {$result->dn}\n");
        $this->stdout("[ldap] UID:          {$result->uid}\n");
        $this->stdout("[ldap] Username:     {$result->username}\n");
        $this->stdout("[ldap] Email:        {$result->email}\n");
        $this->stdout("[ldap] Display name: {$result->displayName}\n");
        $this->stdout("[ldap] Groups:       " . (empty($result->groups) ? '(none)' : implode(', ', $result->groups)) . "\n");
        $this->stdout("[ldap] Mapped roles: " . (empty($result->roles) ? '(none)' : implode(', ', $result->roles)) . "\n");
        return ExitCode::OK;
    }

    public function actionSync(): int
    {
        $svc = $this->ldap();
        $prov = $this->provisioner();
        if ($svc === null || $prov === null) {
            $this->stderr("[ldap] LDAP services not registered.\n");
            return ExitCode::CONFIG;
        }
        if (!$svc->isEnabled()) {
            $this->stderr("[ldap] LDAP is disabled — nothing to sync.\n");
            return ExitCode::OK;
        }

        $config = $svc->getConfig();
        if (!$config->isUsable()) {
            $this->stderr(self::SYNC_NOT_USABLE);
            return ExitCode::CONFIG;
        }

        // By ID, so that the users a stopped run did not sync are the last ones.
        /** @var list<User> $users */
        $users = User::find()->where(['auth_source' => User::AUTH_SOURCE_LDAP])->orderBy(['id' => SORT_ASC])->all();
        $this->stdout("[ldap] Syncing " . count($users) . " LDAP-backed user(s)"
            . ($this->dryRun ? ' (dry-run)' : '') . "\n");

        $counts = $this->syncUsers($users, $svc, $prov, $config);

        $this->stdout("[ldap] Done. updated={$counts['updated']} disabled={$counts['disabled']} "
            . "re-enabled={$counts['reEnabled']} errors={$counts['errors']}\n");
        return $counts['errors'] === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Sync every user in turn. A failed service bind stops the run, since no
     * lookup can succeed then: the users not synced yet keep their state.
     *
     * @param list<User> $users
     * @return array{updated: int, disabled: int, reEnabled: int, errors: int}
     */
    private function syncUsers(array $users, LdapService $svc, LdapUserProvisioner $prov, LdapConfig $config): array
    {
        $counts = ['updated' => 0, 'disabled' => 0, 'reEnabled' => 0, 'errors' => 0];
        foreach ($users as $index => $user) {
            $lookup = $svc->lookup((string)$user->username);
            if ($lookup->outcome === LdapLookupResult::BIND_FAILED) {
                $this->stderr(sprintf(self::SYNC_BIND_FAILED, (string)$lookup->error, count($users) - $index));
                $counts['errors']++;
                break;
            }
            $this->syncOne($user, $lookup, $prov, $config, $counts);
        }
        return $counts;
    }

    /**
     * Sync a single user with what their lookup found. Mutates $counts in place.
     *
     * @param array{updated: int, disabled: int, reEnabled: int, errors: int} $counts
     */
    private function syncOne(
        User $user,
        LdapLookupResult $lookup,
        LdapUserProvisioner $prov,
        LdapConfig $config,
        array &$counts
    ): void {
        $username = (string)$user->username;
        $result = $lookup->user;

        if ($result === null) {
            $this->handleNoUser($user, $username, $lookup, $prov, $counts);
            return;
        }

        $wasInactive = (int)$user->status !== User::STATUS_ACTIVE;
        if ($this->dryRun) {
            $leftInactive = $prov->staysInactive($user);
            $rolesPreview = empty($result->roles) ? '(none)' : implode(',', $result->roles);
            $this->stdout("  - {$username}: present (dn={$result->dn}, roles={$rolesPreview})"
                . ($wasInactive && !$leftInactive ? ' → would RE-ENABLE' : '') . "\n");
            $this->countFound($username, $wasInactive, $leftInactive, $counts);
            return;
        }

        $persisted = $prov->provisionOrUpdate($result, $config);
        if ($persisted === null) {
            $this->stderr("  - {$username}: provisionOrUpdate failed\n");
            $counts['errors']++;
            return;
        }
        $this->countFound($username, $wasInactive, (int)$persisted->status !== User::STATUS_ACTIVE, $counts);
    }

    /**
     * Count a user the directory has as updated, and as re-enabled when the
     * account was inactive, unless it stays inactive because it was
     * deactivated in Ansilume (SYNC_LEFT_INACTIVE). Mutates $counts in place.
     *
     * @param array{updated: int, disabled: int, reEnabled: int, errors: int} $counts
     */
    private function countFound(string $username, bool $wasInactive, bool $leftInactive, array &$counts): void
    {
        $counts['updated']++;
        if ($leftInactive) {
            $this->stdout(sprintf(self::SYNC_LEFT_INACTIVE, $username));
            return;
        }
        if ($wasInactive) {
            $counts['reEnabled']++;
        }
    }

    /**
     * A user the lookup did not answer. Disabled when the directory no
     * longer has them; when the lookup failed, left as they are and counted
     * as an error, since a failed lookup says nothing about the user.
     *
     * @param array{updated: int, disabled: int, reEnabled: int, errors: int} $counts
     */
    private function handleNoUser(
        User $user,
        string $username,
        LdapLookupResult $lookup,
        LdapUserProvisioner $prov,
        array &$counts
    ): void {
        if ($lookup->outcome === LdapLookupResult::MISSING) {
            $this->handleMissingUser($user, $username, LdapService::USER_NOT_FOUND, $prov, $counts);
            return;
        }
        $this->stderr(sprintf(self::SYNC_LOOKUP_FAILED, $username, (string)$lookup->error));
        $counts['errors']++;
    }

    /**
     * Disable (or report as already inactive) a user the directory no longer recognises.
     *
     * @param array{updated: int, disabled: int, reEnabled: int, errors: int} $counts
     */
    private function handleMissingUser(
        User $user,
        string $username,
        string $reason,
        LdapUserProvisioner $prov,
        array &$counts
    ): void {
        if ((int)$user->status !== User::STATUS_ACTIVE) {
            $this->stdout("  - {$username}: missing, already inactive — skipped\n");
            return;
        }
        $this->stdout("  - {$username}: missing in directory → DISABLE ({$reason})\n");
        if ($this->dryRun) {
            $counts['disabled']++;
            return;
        }
        if ($prov->disableMissingUser($user, $reason)) {
            $counts['disabled']++;
        }
    }

    private function ldap(): ?LdapService
    {
        if (!\Yii::$app->has('ldapService')) {
            return null;
        }
        /** @var LdapService $svc */
        $svc = \Yii::$app->get('ldapService');
        return $svc;
    }

    private function provisioner(): ?LdapUserProvisioner
    {
        if (!\Yii::$app->has('ldapUserProvisioner')) {
            return null;
        }
        /** @var LdapUserProvisioner $svc */
        $svc = \Yii::$app->get('ldapUserProvisioner');
        return $svc;
    }
}
