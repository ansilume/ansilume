<?php

declare(strict_types=1);

namespace app\services\ldap;

/**
 * Immutable LDAP configuration value object.
 *
 * Built from the `params['ldap']` config block (which itself comes from
 * environment variables). Passed into LdapClient implementations and
 * services so they never reach into globals.
 */
final class LdapConfig
{
    public const ENCRYPTION_NONE = 'none';
    public const ENCRYPTION_STARTTLS = 'starttls';
    public const ENCRYPTION_LDAPS = 'ldaps';

    /**
     * @param array<string, string> $roleMapping Map of directory group name → Ansilume role name.
     * @param list<string>          $binaryAttributes Attribute names (lowercased) that are returned as binary
     *                                            and must be hex-encoded before use as identifiers.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Immutable DTO; splitting reduces cohesion without benefit.
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        public readonly bool $verifyPeer,
        public readonly int $timeout,
        public readonly string $baseDn,
        public readonly string $bindDn,
        public readonly string $bindPassword,
        public readonly string $userFilter,
        public readonly string $attrUsername,
        public readonly string $attrEmail,
        public readonly string $attrDisplayName,
        public readonly string $attrUid,
        public readonly string $groupFilter,
        public readonly string $groupNameAttr,
        public readonly array $roleMapping,
        public readonly string $defaultRole,
        public readonly bool $autoProvision,
        public readonly array $binaryAttributes = ['objectguid'],
    ) {
    }

    /**
     * Build from the params['ldap'] array. Missing keys fall back to safe
     * defaults so a partially-configured params block does not crash.
     *
     * @param array<string, mixed> $params
     */
    public static function fromArray(array $params): self
    {
        /** @var array<string, string> $roleMapping */
        $roleMapping = is_array($params['roleMapping'] ?? null) ? $params['roleMapping'] : [];
        return new self(
            enabled: (bool)($params['enabled'] ?? false),
            host: (string)($params['host'] ?? ''),
            port: (int)($params['port'] ?? 389),
            encryption: strtolower((string)($params['encryption'] ?? self::ENCRYPTION_NONE)),
            verifyPeer: (bool)($params['verifyPeer'] ?? true),
            timeout: (int)($params['timeout'] ?? 5),
            baseDn: (string)($params['baseDn'] ?? ''),
            bindDn: (string)($params['bindDn'] ?? ''),
            bindPassword: (string)($params['bindPassword'] ?? ''),
            userFilter: (string)($params['userFilter'] ?? '(&(objectClass=user)(sAMAccountName=%s))'),
            attrUsername: (string)($params['attrUsername'] ?? 'sAMAccountName'),
            attrEmail: (string)($params['attrEmail'] ?? 'mail'),
            attrDisplayName: (string)($params['attrDisplayName'] ?? 'displayName'),
            attrUid: (string)($params['attrUid'] ?? 'objectGUID'),
            groupFilter: (string)($params['groupFilter'] ?? '(&(objectClass=group)(member=%s))'),
            groupNameAttr: (string)($params['groupNameAttr'] ?? 'cn'),
            roleMapping: $roleMapping,
            defaultRole: (string)($params['defaultRole'] ?? ''),
            autoProvision: (bool)($params['autoProvision'] ?? true),
        );
    }

    /**
     * Build the full URI ldap_connect() expects, e.g. ldaps://dc.example.com:636.
     */
    public function uri(): string
    {
        $scheme = $this->encryption === self::ENCRYPTION_LDAPS ? 'ldaps' : 'ldap';
        return $scheme . '://' . $this->host . ':' . $this->port;
    }

    /**
     * True when integration is configured well enough to attempt a bind.
     * Used by the master switch + diagnostics endpoint to avoid surprising
     * "connect to empty host" errors.
     */
    public function isUsable(): bool
    {
        return $this->enabled && $this->host !== '' && $this->baseDn !== '';
    }
}
