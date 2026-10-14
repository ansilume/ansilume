<?php

declare(strict_types=1);

namespace app\services\ldap;

/**
 * In-memory LDAP client for tests and the local dev environment.
 *
 * Holds a fixed set of users + group memberships keyed by username.
 * No network access, no external server required. Tests configure the
 * directory by calling addUser() / addGroupMembership() before the
 * code under test runs.
 *
 * Behaviour parity with PhpLdapClient is intentional: same return
 * types, same null-on-not-found semantics, same "wrong password
 * returns false (not exception)" contract, and the same last error after
 * a search that fails ({@see failSearch()}, {@see failGroupSearch()}).
 */
class FakeLdapClient implements LdapClientInterface
{
    /** The last error of a service bind that {@see failServiceBind()} makes fail. */
    public const SERVICE_BIND_FAILURE = 'Simulated service bind failure.';

    /** The last error of a user search that {@see failSearch()} makes fail. */
    public const SEARCH_FAILURE = 'Simulated user search failure.';

    /** The last error of a group search that {@see failGroupSearch()} makes fail. */
    public const GROUP_SEARCH_FAILURE = 'Simulated group search failure.';

    private LdapConfig $config;

    /** @var array<string, array{dn: string, password: string, attributes: array<string, list<string>>}> */
    private array $users = [];

    /** @var array<string, list<string>> Map of user DN → list of group names. */
    private array $groupMemberships = [];

    private bool $serviceBound = false;

    private bool $serviceBindShouldFail = false;

    private bool $searchShouldFail = false;

    private bool $groupSearchShouldFail = false;

    private ?string $lastError = null;

    public function __construct(LdapConfig $config)
    {
        $this->config = $config;
    }

    public function getConfig(): LdapConfig
    {
        return $this->config;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Register a user in the fake directory.
     *
     * @param array<string, list<string>|string> $attributes Extra attributes;
     *   single strings are wrapped into a one-element list. The configured
     *   username/email/displayName/uid attributes should be supplied here.
     */
    public function addUser(
        string $username,
        string $dn,
        #[\SensitiveParameter] string $password,
        array $attributes = []
    ): void {
        $normalised = [];
        foreach ($attributes as $name => $value) {
            $key = strtolower((string)$name);
            $normalised[$key] = is_array($value) ? array_values(array_map('strval', $value)) : [(string)$value];
        }
        $this->users[strtolower($username)] = [
            'dn' => $dn,
            'password' => $password,
            'attributes' => $normalised,
        ];
    }

    /**
     * Register that the given user DN is a member of the given group.
     */
    public function addGroupMembership(string $userDn, string $groupName): void
    {
        $this->groupMemberships[$userDn] ??= [];
        $this->groupMemberships[$userDn][] = $groupName;
    }

    /**
     * Remove a user (simulates an admin deleting them in the directory).
     */
    public function removeUser(string $username): void
    {
        unset($this->users[strtolower($username)]);
    }

    /**
     * Force the next service-account bind to fail. Used by tests to
     * exercise connection-error handling.
     */
    public function failServiceBind(bool $fail = true): void
    {
        $this->serviceBindShouldFail = $fail;
    }

    /**
     * Make every user search fail after a successful service bind, as a
     * directory does that cannot answer it. Used by tests to tell a failed
     * search from a user the directory does not have.
     */
    public function failSearch(bool $fail = true): void
    {
        $this->searchShouldFail = $fail;
    }

    /**
     * Make every group search fail after a successful service bind. Used by
     * tests to tell a failed group search from a user in no group.
     */
    public function failGroupSearch(bool $fail = true): void
    {
        $this->groupSearchShouldFail = $fail;
    }

    public function bindServiceAccount(): bool
    {
        if (!$this->config->isUsable()) {
            $this->lastError = 'LDAP not configured (host or base DN missing).';
            return false;
        }
        if ($this->serviceBindShouldFail) {
            $this->lastError = self::SERVICE_BIND_FAILURE;
            $this->serviceBound = false;
            return false;
        }
        $this->serviceBound = true;
        $this->lastError = null;
        return true;
    }

    public function findUser(string $username): ?LdapEntry
    {
        $this->lastError = null;
        if (!$this->serviceBound && !$this->bindServiceAccount()) {
            return null;
        }
        if ($this->searchShouldFail) {
            $this->lastError = self::SEARCH_FAILURE;
            return null;
        }
        $key = strtolower($username);
        if (!isset($this->users[$key])) {
            return null;
        }
        $row = $this->users[$key];
        return new LdapEntry($row['dn'], $row['attributes']);
    }

    public function bindAsUser(string $dn, #[\SensitiveParameter] string $password): bool
    {
        if ($password === '' || $dn === '') {
            $this->lastError = 'Empty DN or password rejected.';
            return false;
        }
        foreach ($this->users as $row) {
            if ($row['dn'] === $dn) {
                if (hash_equals($row['password'], $password)) {
                    $this->serviceBound = false;
                    $this->lastError = null;
                    return true;
                }
                $this->lastError = 'Invalid credentials.';
                return false;
            }
        }
        $this->lastError = 'No such DN.';
        return false;
    }

    public function findUserGroups(string $userDn): array
    {
        $this->lastError = null;
        if (!$this->serviceBound && !$this->bindServiceAccount()) {
            return [];
        }
        if ($this->groupSearchShouldFail) {
            $this->lastError = self::GROUP_SEARCH_FAILURE;
            return [];
        }
        return $this->groupMemberships[$userDn] ?? [];
    }

    public function close(): void
    {
        $this->serviceBound = false;
    }
}
