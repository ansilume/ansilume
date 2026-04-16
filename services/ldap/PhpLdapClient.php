<?php

declare(strict_types=1);

namespace app\services\ldap;

/**
 * Production LDAP client backed by PHP's `ldap_*` functions.
 *
 * Connection lifecycle is owned by this class — callers are not expected
 * to know about \LDAP\Connection handles. The connection is opened lazily
 * on the first operation and closed via {@see close()} or destructor.
 *
 * The PHP ldap extension reports failures by returning false AND emitting
 * a PHP warning. Rather than sprinkle `@` everywhere (banned by the
 * project style guide), every call goes through {@see silently()} which
 * installs a no-op error handler for the duration of the call.
 */
class PhpLdapClient implements LdapClientInterface
{
    private LdapConfig $config;

    /** @var \LDAP\Connection|null Lazily opened connection handle. */
    private $connection = null;

    private bool $serviceBound = false;

    private ?string $lastError = null;

    public function __construct(LdapConfig $config)
    {
        if (!extension_loaded('ldap')) {
            throw new LdapException('PHP ldap extension is not loaded.');
        }
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

    public function bindServiceAccount(): bool
    {
        if (!$this->config->isUsable()) {
            $this->lastError = 'LDAP not configured (host or base DN missing).';
            return false;
        }
        if (!$this->openConnection()) {
            return false;
        }
        if ($this->serviceBound) {
            return true;
        }
        $bindDn = $this->config->bindDn !== '' ? $this->config->bindDn : null;
        $bindPwd = $this->config->bindDn !== '' ? $this->config->bindPassword : null;

        $conn = $this->requireConnection();
        $ok = (bool)$this->silently(static fn () => ldap_bind($conn, $bindDn, $bindPwd));
        if (!$ok) {
            $this->captureError('Service account bind failed');
            return false;
        }
        $this->serviceBound = true;
        return true;
    }

    public function findUser(string $username): ?LdapEntry
    {
        if (!$this->serviceBound && !$this->bindServiceAccount()) {
            return null;
        }
        $filter = sprintf($this->config->userFilter, $this->escapeFilter($username));
        $conn = $this->requireConnection();
        $base = $this->config->baseDn;
        $attrs = [
            $this->config->attrUsername,
            $this->config->attrEmail,
            $this->config->attrDisplayName,
            $this->config->attrUid,
        ];

        // sizelimit=2 — we want exactly one match; allow 2 so we can detect ambiguity.
        $result = $this->silently(static fn () => ldap_search($conn, $base, $filter, $attrs, 0, 2));
        if (!($result instanceof \LDAP\Result)) {
            $this->captureError('User search failed');
            return null;
        }
        $count = (int)$this->silently(static fn () => ldap_count_entries($conn, $result));
        if ($count !== 1) {
            // 0 = not found; >1 = ambiguous filter — refuse to guess.
            return null;
        }
        $entry = $this->silently(static fn () => ldap_first_entry($conn, $result));
        if (!($entry instanceof \LDAP\ResultEntry)) {
            return null;
        }
        return $this->buildEntry($conn, $entry);
    }

    public function bindAsUser(string $dn, string $password): bool
    {
        // Empty password protection: many directory servers grant an
        // "unauthenticated" bind for empty passwords (RFC 4513 §5.1.2)
        // which would let any known DN log in. Refuse outright.
        if ($password === '' || $dn === '') {
            $this->lastError = 'Empty DN or password rejected.';
            return false;
        }
        if (!$this->openConnection()) {
            return false;
        }
        $conn = $this->requireConnection();
        $ok = (bool)$this->silently(static fn () => ldap_bind($conn, $dn, $password));
        if (!$ok) {
            $this->captureError('User bind failed');
            $this->serviceBound = false;
            return false;
        }
        // After a successful user bind we lose service-account privileges.
        $this->serviceBound = false;
        return true;
    }

    public function findUserGroups(string $userDn): array
    {
        if (!$this->serviceBound && !$this->bindServiceAccount()) {
            return [];
        }
        $filter = sprintf($this->config->groupFilter, $this->escapeFilter($userDn));
        $conn = $this->requireConnection();
        $base = $this->config->baseDn;
        $attr = $this->config->groupNameAttr;

        $result = $this->silently(static fn () => ldap_search($conn, $base, $filter, [$attr]));
        if (!($result instanceof \LDAP\Result)) {
            $this->captureError('Group search failed');
            return [];
        }
        $entries = $this->silently(static fn () => ldap_get_entries($conn, $result));
        if (!is_array($entries) || (int)($entries['count'] ?? 0) === 0) {
            return [];
        }
        $names = [];
        $key = strtolower($attr);
        $count = (int)$entries['count'];
        for ($i = 0; $i < $count; $i++) {
            $row = $entries[$i];
            if (isset($row[$key][0])) {
                $names[] = (string)$row[$key][0];
            }
        }
        return $names;
    }

    public function close(): void
    {
        if ($this->connection !== null) {
            $conn = $this->connection;
            $this->silently(static fn () => ldap_unbind($conn));
            $this->connection = null;
            $this->serviceBound = false;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    // -------- internal helpers --------

    /**
     * Open a connection if one isn't already open. Configures protocol,
     * timeout, referrals, TLS verification, and STARTTLS.
     */
    private function openConnection(): bool
    {
        if ($this->connection !== null) {
            return true;
        }

        // TLS cert verification is process-global in OpenLDAP — set before connect.
        if (!$this->config->verifyPeer) {
            putenv('LDAPTLS_REQCERT=never');
        }

        $uri = $this->config->uri();
        $conn = $this->silently(static fn () => ldap_connect($uri));
        if (!($conn instanceof \LDAP\Connection)) {
            $this->lastError = 'Failed to initialise LDAP connection.';
            return false;
        }
        $this->connection = $conn;
        $timeout = $this->config->timeout;

        // LDAPv3 is required for STARTTLS, modern AD, and SASL.
        $protoOk = (bool)$this->silently(
            static fn () => ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3),
        );
        if (!$protoOk) {
            $this->captureError('Failed to set LDAPv3');
            $this->close();
            return false;
        }
        // Referrals on AD cause searches to follow back to root DC, which
        // hangs without auth on those servers. Almost everyone wants this off.
        $this->silently(static fn () => ldap_set_option($conn, LDAP_OPT_REFERRALS, 0));
        $this->silently(static fn () => ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $timeout));

        if ($this->config->encryption === LdapConfig::ENCRYPTION_STARTTLS) {
            $tlsOk = (bool)$this->silently(static fn () => ldap_start_tls($conn));
            if (!$tlsOk) {
                $this->captureError('STARTTLS failed');
                $this->close();
                return false;
            }
        }
        return true;
    }

    /**
     * Return the connection handle, asserting it is open.
     *
     * @return \LDAP\Connection
     */
    private function requireConnection()
    {
        if ($this->connection === null) {
            throw new LdapException('LDAP connection is not open.');
        }
        return $this->connection;
    }

    /**
     * Convert an ldap_first_entry / ldap_next_entry handle into our
     * neutral LdapEntry value object. Binary attributes (objectGUID etc.)
     * are detected by name and hex-encoded for stable storage.
     *
     * @param \LDAP\Connection $conn
     * @param \LDAP\ResultEntry $entry
     */
    private function buildEntry($conn, $entry): LdapEntry
    {
        $dn = (string)$this->silently(static fn () => ldap_get_dn($conn, $entry));
        $rawAttrs = $this->silently(static fn () => ldap_get_attributes($conn, $entry));
        if (!is_array($rawAttrs)) {
            return new LdapEntry($dn, []);
        }
        $attrs = [];
        $count = (int)($rawAttrs['count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $rawName = $rawAttrs[$i] ?? '';
            $name = is_string($rawName) ? $rawName : '';
            if ($name === '') {
                continue;
            }
            $lower = strtolower($name);
            $values = $this->collectValues($conn, $entry, $name);
            if (in_array($lower, $this->config->binaryAttributes, true)) {
                $values = array_map(static fn (string $v): string => bin2hex($v), $values);
            }
            $attrs[$lower] = $values;
        }
        return new LdapEntry($dn, $attrs);
    }

    /**
     * Read all values for a single attribute. Uses ldap_get_values_len so
     * binary data (objectGUID) is preserved verbatim.
     *
     * @param \LDAP\Connection $conn
     * @param \LDAP\ResultEntry $entry
     * @return list<string>
     */
    private function collectValues($conn, $entry, string $name): array
    {
        $values = $this->silently(static fn () => ldap_get_values_len($conn, $entry, $name));
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        $count = (int)($values['count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $out[] = (string)$values[$i];
        }
        return $out;
    }

    /**
     * Escape user input for safe inclusion in an LDAP filter.
     * Uses ldap_escape() with LDAP_ESCAPE_FILTER to neutralise *, (, ),
     * \ and NUL — preventing filter injection.
     */
    private function escapeFilter(string $value): string
    {
        return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
    }

    private function captureError(string $context): void
    {
        if ($this->connection === null) {
            $this->lastError = $context . ': no connection';
            return;
        }
        $conn = $this->connection;
        $detail = (string)$this->silently(static fn () => ldap_error($conn));
        $this->lastError = $context . ': ' . $detail;
    }

    /**
     * Run a callable with PHP warnings suppressed. Used to wrap ldap_*
     * calls so failures (which always emit warnings) don't pollute the
     * error log; the caller still inspects the return value.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function silently(callable $fn)
    {
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
