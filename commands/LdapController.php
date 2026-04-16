<?php

declare(strict_types=1);

namespace app\commands;

use app\models\User;
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
 *                                            no longer recognises, re-enable ones it does.
 *   php yii ldap/sync --dry-run            — same as above, but reports without making changes.
 *
 * Run `php yii ldap/sync` from cron (e.g. nightly) so accounts that have been
 * removed or disabled in the directory lose their Ansilume access too.
 */
class LdapController extends Controller
{
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
        $users = User::find()->where(['auth_source' => User::AUTH_SOURCE_LDAP])->all();
        $this->stdout("[ldap] Syncing " . count($users) . " LDAP-backed user(s)"
            . ($this->dryRun ? ' (dry-run)' : '') . "\n");

        $counts = ['updated' => 0, 'disabled' => 0, 'reEnabled' => 0, 'errors' => 0];
        foreach ($users as $user) {
            /** @var User $user */
            $this->syncOne($user, $svc, $prov, $config, $counts);
        }

        $this->stdout("[ldap] Done. updated={$counts['updated']} disabled={$counts['disabled']} "
            . "re-enabled={$counts['reEnabled']} errors={$counts['errors']}\n");
        return $counts['errors'] === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Sync a single user against the directory. Mutates $counts in place.
     *
     * @param array{updated: int, disabled: int, reEnabled: int, errors: int} $counts
     */
    private function syncOne(
        User $user,
        LdapService $svc,
        LdapUserProvisioner $prov,
        \app\services\ldap\LdapConfig $config,
        array &$counts
    ): void {
        $username = (string)$user->username;
        $result = $svc->lookupByUsername($username);

        if ($result === null) {
            $this->handleMissingUser($user, $username, (string)($svc->getLastError() ?? 'not found in directory'), $prov, $counts);
            return;
        }

        $wasInactive = (int)$user->status !== User::STATUS_ACTIVE;
        if ($this->dryRun) {
            $rolesPreview = empty($result->roles) ? '(none)' : implode(',', $result->roles);
            $this->stdout("  - {$username}: present (dn={$result->dn}, roles={$rolesPreview})"
                . ($wasInactive ? ' → would RE-ENABLE' : '') . "\n");
            $counts['updated']++;
            if ($wasInactive) {
                $counts['reEnabled']++;
            }
            return;
        }

        if ($prov->provisionOrUpdate($result, $config) === null) {
            $this->stderr("  - {$username}: provisionOrUpdate failed\n");
            $counts['errors']++;
            return;
        }
        $counts['updated']++;
        if ($wasInactive) {
            $counts['reEnabled']++;
        }
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
