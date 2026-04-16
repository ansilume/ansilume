<?php

declare(strict_types=1);

namespace app\services\ldap;

/**
 * Contract for an LDAP/AD client.
 *
 * Two implementations exist:
 *  - {@see PhpLdapClient}  — production, wraps PHP's ldap_* functions.
 *  - {@see FakeLdapClient} — used by unit/integration tests so a real
 *    directory is never required in CI.
 *
 * Authentication failures (wrong password, no such user) are NOT exceptions.
 * They are signalled via the boolean / null return values so callers can
 * report a generic "invalid credentials" message without leaking timing or
 * directory existence information. Truly broken states (PHP ldap extension
 * missing, malformed config) throw {@see LdapException}.
 */
interface LdapClientInterface
{
    /**
     * Open the connection (if not already open) and bind as the configured
     * service account. Returns true on success. On failure, the last error
     * is available via {@see getLastError()}.
     */
    public function bindServiceAccount(): bool;

    /**
     * Look up a user by the username submitted at login. Returns the entry
     * or null when no user matches the configured filter.
     *
     * Must be called after {@see bindServiceAccount()}.
     */
    public function findUser(string $username): ?LdapEntry;

    /**
     * Verify a user's password by re-binding as their DN. Returns true only
     * if the directory accepts the credentials.
     */
    public function bindAsUser(string $dn, string $password): bool;

    /**
     * Return the names of groups the user (identified by their DN) is a
     * direct or indirect member of. Names come from the configured
     * groupNameAttr (typically `cn`).
     *
     * @return list<string>
     */
    public function findUserGroups(string $userDn): array;

    /**
     * Disconnect / unbind. Safe to call multiple times.
     */
    public function close(): void;

    /**
     * Most recent error message from the underlying LDAP layer, or null
     * if no error has occurred. Used for diagnostics, never shown to
     * end users at login (would leak directory state).
     */
    public function getLastError(): ?string;

    /**
     * Configuration this client was built with. Used by the diagnostic
     * endpoint and for sanity-checking before issuing operations.
     */
    public function getConfig(): LdapConfig;
}
