<?php

declare(strict_types=1);

namespace app\services\ldap;

/**
 * Single directory entry returned by an LDAP search.
 *
 * Attribute names are lowercased so callers can match against the configured
 * attribute names case-insensitively (LDAP is case-insensitive on attribute
 * names but PHP keys are not).
 */
final class LdapEntry
{
    /**
     * @param array<string, list<string>> $attributes Lowercased attribute name → list of string values.
     */
    public function __construct(
        public readonly string $dn,
        public readonly array $attributes,
    ) {
    }

    /**
     * First value for an attribute, or null if absent.
     */
    public function first(string $name): ?string
    {
        $key = strtolower($name);
        return $this->attributes[$key][0] ?? null;
    }

    /**
     * All values for an attribute, or empty list if absent.
     *
     * @return list<string>
     */
    public function all(string $name): array
    {
        $key = strtolower($name);
        return $this->attributes[$key] ?? [];
    }

    public function has(string $name): bool
    {
        $key = strtolower($name);
        return isset($this->attributes[$key]);
    }
}
