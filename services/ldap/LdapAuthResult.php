<?php

declare(strict_types=1);

namespace app\services\ldap;

/**
 * Outcome of a successful LDAP authentication, ready for the local
 * provisioning layer to consume.
 *
 * The directory work is done — we now know who the user is, what their
 * stable identifier is, what their attributes are, and which Ansilume
 * roles their group memberships map to. The local-account creation /
 * update step happens elsewhere (see Phase 6 — LdapUserProvisioner).
 *
 * Authentication failure is signalled with a null result, NEVER with
 * this object — there is no "failed" state here.
 */
final class LdapAuthResult
{
    /**
     * @param string       $dn          Distinguished name returned by the directory.
     * @param string       $uid         Stable identifier (objectGUID hex / entryUUID). Persisted to user.ldap_uid.
     * @param string       $username    Username attribute value (e.g. sAMAccountName / uid).
     * @param string       $email       mail attribute, may be empty if directory has no value.
     * @param string       $displayName displayName / cn, may be empty.
     * @param list<string> $groups      Directory group names the user is a member of.
     * @param list<string> $roles       Ansilume role names derived from group mapping (empty if no mapping matched).
     */
    public function __construct(
        public readonly string $dn,
        public readonly string $uid,
        public readonly string $username,
        public readonly string $email,
        public readonly string $displayName,
        public readonly array $groups,
        public readonly array $roles,
    ) {
    }
}
