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
 * returns false (not exception)" contract.
 */
class FakeLdapClient implements LdapClientInterface
{
    private LdapConfig $config;

    /** @var array<string, array{dn: string, password: string, attributes: array<string, list<string>>}> */
    private array $users = [];

    /** @var array<string, list<string>> Map of user DN → list of group names. */
    private array $groupMemberships = [];

    private bool $serviceBound = false;

    private bool $serviceBindShouldFail = false;

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
    public function addUser(string $username, string $dn, string $password, array $attributes = []): void
    {
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

    public function bindServiceAccount(): bool
    {
        if (!$this->config->isUsable()) {
            $this->lastError = 'LDAP not configured (host or base DN missing).';
            return false;
        }
        if ($this->serviceBindShouldFail) {
            $this->lastError = 'Simulated service bind failure.';
            $this->serviceBound = false;
            return false;
        }
        $this->serviceBound = true;
        $this->lastError = null;
        return true;
    }

    public function findUser(string $username): ?LdapEntry
    {
        if (!$this->serviceBound && !$this->bindServiceAccount()) {
            return null;
        }
        $key = strtolower($username);
        if (!isset($this->users[$key])) {
            return null;
        }
        $row = $this->users[$key];
        return new LdapEntry($row['dn'], $row['attributes']);
    }

    public function bindAsUser(string $dn, string $password): bool
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
        if (!$this->serviceBound && !$this->bindServiceAccount()) {
            return [];
        }
        return $this->groupMemberships[$userDn] ?? [];
    }

    public function close(): void
    {
        $this->serviceBound = false;
    }
}
