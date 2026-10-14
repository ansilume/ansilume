<?php

declare(strict_types=1);

namespace app\services\ldap;

/**
 * What a lookup of one user without a password found
 * ({@see LdapService::lookup()}).
 *
 * Only {@see MISSING} says that the directory no longer has the user: the
 * service bind and the user search succeeded, and the search found no entry.
 * Every failure says nothing about the user, whether LDAP is not usable, the
 * service bind fails, the user search fails or answers more than one entry,
 * or the group search fails. ldap/sync disables a missing user, and changes
 * nothing for a user whose lookup failed.
 */
final class LdapLookupResult
{
    /** The directory answered the user. */
    public const FOUND = 'found';

    /** The search ran and found no entry: the directory no longer has the user. */
    public const MISSING = 'missing';

    /** LDAP is not enabled, or its host or base DN is not set. */
    public const NOT_USABLE = 'not_usable';

    /** The service account bind failed: no user can be looked up. */
    public const BIND_FAILED = 'bind_failed';

    /** The user search failed, or answered more than one entry. */
    public const SEARCH_FAILED = 'search_failed';

    /** The user was found, but the search for their groups failed. */
    public const GROUPS_FAILED = 'groups_failed';

    /**
     * @param string $outcome One of the constants of this class.
     * @param LdapAuthResult|null $user The user, set only for FOUND.
     * @param string|null $error Why the lookup failed, set only for a failure.
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?LdapAuthResult $user,
        public readonly ?string $error,
    ) {
    }

    public static function found(LdapAuthResult $user): self
    {
        return new self(self::FOUND, $user, null);
    }

    public static function missing(): self
    {
        return new self(self::MISSING, null, null);
    }

    /**
     * @param self::NOT_USABLE|self::BIND_FAILED|self::SEARCH_FAILED|self::GROUPS_FAILED $outcome
     */
    public static function failed(string $outcome, string $error): self
    {
        return new self($outcome, null, $error);
    }
}
