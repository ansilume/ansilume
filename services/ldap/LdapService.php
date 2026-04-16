<?php

declare(strict_types=1);

namespace app\services\ldap;

use yii\base\Component;

/**
 * Orchestrates LDAP authentication: locate user → verify password →
 * fetch group membership → resolve Ansilume roles.
 *
 * Returns a neutral {@see LdapAuthResult} with no side effects on the
 * local DB. Local account provisioning lives in {@see LdapUserProvisioner}
 * (Phase 6) so this service is testable without touching ActiveRecord.
 *
 * Failure handling — anything short of a complete successful flow returns
 * null. The reason is exposed via {@see getLastError()} for diagnostics
 * (logged + audited by the LoginForm) but never surfaced to the user, so
 * we do not leak directory state at the login screen.
 */
class LdapService extends Component
{
    /**
     * Either an instance of {@see LdapClientInterface}, a Yii2 config
     * array (`['class' => SomeClient::class, ...]`), or null to fall
     * back to {@see PhpLdapClient}.
     *
     * Tests inject a {@see FakeLdapClient} here.
     *
     * @var LdapClientInterface|array<string, mixed>|null
     */
    public $client;

    private ?LdapClientInterface $resolvedClient = null;

    private ?LdapConfig $resolvedConfig = null;

    private ?string $lastError = null;

    public function init(): void
    {
        parent::init();
        // Resolve config eagerly so isEnabled() / config() never depend on
        // first authentication call having happened. Client stays lazy —
        // PhpLdapClient throws if the ldap extension is missing, which
        // would crash the bootstrap when LDAP is disabled.
        $params = \Yii::$app->params['ldap'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }
        $this->resolvedConfig = LdapConfig::fromArray($params);
    }

    public function getConfig(): LdapConfig
    {
        if ($this->resolvedConfig === null) {
            $this->init();
        }
        /** @var LdapConfig $cfg PHPStan: init() always sets it. */
        $cfg = $this->resolvedConfig;
        return $cfg;
    }

    public function isEnabled(): bool
    {
        return $this->getConfig()->enabled;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Run the full authentication flow against the directory.
     *
     * Returns null when LDAP is disabled, the user does not exist, the
     * password is wrong, or any directory operation fails. Inspect
     * {@see getLastError()} for diagnostic detail (NEVER show to the
     * end user — see class docblock).
     */
    public function authenticate(string $username, string $password): ?LdapAuthResult
    {
        $this->lastError = null;

        $config = $this->getConfig();
        if (!$config->isUsable()) {
            $this->lastError = 'LDAP is not enabled or not fully configured.';
            return null;
        }
        // Reject empty credentials early — also done in the client, but
        // doing it here avoids opening a connection at all.
        if ($username === '' || $password === '') {
            $this->lastError = 'Empty credentials.';
            return null;
        }

        $client = $this->getClient();
        if (!$client->bindServiceAccount()) {
            $this->lastError = $client->getLastError() ?? 'Service bind failed.';
            return null;
        }

        $entry = $client->findUser($username);
        if ($entry === null) {
            // Treat "not found" the same as "wrong password" externally.
            $this->lastError = 'User not found in directory.';
            return null;
        }

        if (!$client->bindAsUser($entry->dn, $password)) {
            $this->lastError = 'User bind failed (invalid credentials).';
            return null;
        }

        // Re-bind as service account so the upcoming group lookup uses
        // the privileged identity. The user's bind already proved they
        // know the password — we don't need it any more.
        // @phpstan-ignore-next-line booleanNot.alwaysFalse — bindAsUser() above clears serviceBound
        if ($client->bindServiceAccount() !== true) {
            $this->lastError = 'Re-bind as service account failed: '
                . ($client->getLastError() ?? 'unknown');
            return null;
        }

        $groups = $client->findUserGroups($entry->dn);
        $roles = $this->mapGroupsToRoles($groups);

        return new LdapAuthResult(
            dn: $entry->dn,
            uid: $this->extractUid($entry, $config),
            username: $entry->first($config->attrUsername) ?? $username,
            email: $entry->first($config->attrEmail) ?? '',
            displayName: $entry->first($config->attrDisplayName) ?? '',
            groups: $groups,
            roles: $roles,
        );
    }

    /**
     * Look up a user without verifying a password. Used by lifecycle sync
     * (Phase 14) and the diagnostic endpoint (Phase 12). Returns null if
     * the user is no longer present in the directory.
     */
    public function lookupByUsername(string $username): ?LdapAuthResult
    {
        $this->lastError = null;
        $config = $this->getConfig();
        if (!$config->isUsable()) {
            $this->lastError = 'LDAP is not enabled or not fully configured.';
            return null;
        }
        $client = $this->getClient();
        if (!$client->bindServiceAccount()) {
            $this->lastError = $client->getLastError() ?? 'Service bind failed.';
            return null;
        }
        $entry = $client->findUser($username);
        if ($entry === null) {
            $this->lastError = 'User not found in directory.';
            return null;
        }
        $groups = $client->findUserGroups($entry->dn);
        return new LdapAuthResult(
            dn: $entry->dn,
            uid: $this->extractUid($entry, $config),
            username: $entry->first($config->attrUsername) ?? $username,
            email: $entry->first($config->attrEmail) ?? '',
            displayName: $entry->first($config->attrDisplayName) ?? '',
            groups: $groups,
            roles: $this->mapGroupsToRoles($groups),
        );
    }

    /**
     * Translate a list of directory group names into Ansilume role names.
     *
     * Group matching is case-insensitive — directory servers normalise
     * CN inconsistently. Configuration keys win on collision so admins
     * can express "any of these spellings → admin".
     *
     * Falls back to {@see LdapConfig::$defaultRole} when no group matches
     * AND a default is set; otherwise returns an empty list (the user
     * can log in but has no permissions until an admin grants them).
     *
     * @param list<string> $groups
     * @return list<string>
     */
    public function mapGroupsToRoles(array $groups): array
    {
        $config = $this->getConfig();
        if ($config->roleMapping === [] && $config->defaultRole === '') {
            return [];
        }
        $lowerMap = [];
        foreach ($config->roleMapping as $groupName => $role) {
            $lowerMap[strtolower($groupName)] = $role;
        }
        $matched = [];
        foreach ($groups as $g) {
            $key = strtolower($g);
            if (isset($lowerMap[$key])) {
                $matched[$lowerMap[$key]] = true;
            }
        }
        if ($matched === [] && $config->defaultRole !== '') {
            $matched[$config->defaultRole] = true;
        }
        return array_keys($matched);
    }

    /**
     * Diagnostic snapshot of LDAP connectivity for the test endpoint
     * (Phase 12). Returns connection-level results; never includes the
     * bind password or any secret.
     *
     * @return array{
     *     enabled: bool,
     *     host: string,
     *     encryption: string,
     *     base_dn: string,
     *     bind_dn_configured: bool,
     *     service_bind: bool,
     *     error: ?string,
     * }
     */
    public function diagnose(): array
    {
        $config = $this->getConfig();
        $bindOk = false;
        $error = null;
        if ($config->isUsable()) {
            $client = $this->getClient();
            $bindOk = $client->bindServiceAccount();
            if (!$bindOk) {
                $error = $client->getLastError();
            }
            $client->close();
        } else {
            $error = 'LDAP not enabled or not fully configured.';
        }
        return [
            'enabled' => $config->enabled,
            'host' => $config->host,
            'encryption' => $config->encryption,
            'base_dn' => $config->baseDn,
            'bind_dn_configured' => $config->bindDn !== '',
            'service_bind' => $bindOk,
            'error' => $error,
        ];
    }

    /**
     * Resolve / cache the LdapClient instance. Lazy because PhpLdapClient
     * checks for the ldap extension in its constructor.
     */
    private function getClient(): LdapClientInterface
    {
        if ($this->resolvedClient !== null) {
            return $this->resolvedClient;
        }
        $config = $this->getConfig();
        if ($this->client instanceof LdapClientInterface) {
            $this->resolvedClient = $this->client;
        } elseif (is_array($this->client)) {
            $params = $this->client;
            $class = (string)($params['class'] ?? PhpLdapClient::class);
            unset($params['class']);
            // We pass the resolved config as the first ctor arg so client
            // configs in web.php don't have to repeat the params block.
            /** @var array{class: class-string<LdapClientInterface>} $definition */
            $definition = ['class' => $class] + $params;
            /** @var LdapClientInterface $obj */
            $obj = \Yii::createObject($definition, [$config]);
            $this->resolvedClient = $obj;
        } else {
            $this->resolvedClient = new PhpLdapClient($config);
        }
        return $this->resolvedClient;
    }

    /**
     * Extract the configured UID attribute as a non-empty string. Falls
     * back to the DN — not ideal but stable enough that re-login still
     * resolves the same local account when the directory does not return
     * the configured UID attribute (rare misconfiguration).
     */
    private function extractUid(LdapEntry $entry, LdapConfig $config): string
    {
        $uid = $entry->first($config->attrUid);
        if ($uid !== null && $uid !== '') {
            return $uid;
        }
        return $entry->dn;
    }
}
