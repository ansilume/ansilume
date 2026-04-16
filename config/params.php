<?php

declare(strict_types=1);

// Decode the LDAP_ROLE_MAPPING env var into a { groupName => ansilumeRole } map.
// Invalid / missing JSON yields an empty array so a typo never silently
// grants privileges.
$ldapRoleMapping = (static function (): array {
    $raw = (string)($_ENV['LDAP_ROLE_MAPPING'] ?? '');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $mapping = [];
    foreach ($decoded as $groupName => $role) {
        if (is_string($groupName) && is_string($role) && $groupName !== '' && $role !== '') {
            $mapping[$groupName] = $role;
        }
    }
    return $mapping;
})();

return [
    'version' => (function () {
        $path = dirname(__DIR__) . '/VERSION';
        return file_exists($path) ? trim((string)file_get_contents($path)) ?: 'dev' : 'dev';
    })(),
    'appBaseUrl' => rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/'),
    'adminEmail' => $_ENV['ADMIN_EMAIL'] ?? 'admin@example.com',
    'senderEmail' => $_ENV['SENDER_EMAIL'] ?? 'noreply@example.com',
    'senderName' => 'Ansilume',
    'jobWorkspacePath' => $_ENV['JOB_WORKSPACE_PATH'] ?? '/tmp/ansilume/jobs',
    'jobLogPath' => $_ENV['JOB_LOG_PATH'] ?? '/var/www/runtime/job-logs',
    'ldap' => [
        'enabled' => filter_var($_ENV['LDAP_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'host' => (string)($_ENV['LDAP_HOST'] ?? ''),
        'port' => (int)($_ENV['LDAP_PORT'] ?? 389),
        // 'none' = plain 389, 'starttls' = upgrade on 389, 'ldaps' = implicit TLS on 636.
        'encryption' => strtolower((string)($_ENV['LDAP_ENCRYPTION'] ?? 'none')),
        'verifyPeer' => filter_var($_ENV['LDAP_VERIFY_PEER'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'timeout' => (int)($_ENV['LDAP_TIMEOUT'] ?? 5),
        'baseDn' => (string)($_ENV['LDAP_BASE_DN'] ?? ''),
        // Service-account credentials used for user lookups and group membership queries.
        'bindDn' => (string)($_ENV['LDAP_BIND_DN'] ?? ''),
        'bindPassword' => (string)($_ENV['LDAP_BIND_PASSWORD'] ?? ''),
        // Filter applied when searching for a user by submitted username. Use %s as
        // placeholder (escaped by the client). Typical values:
        //   AD:       (&(objectClass=user)(sAMAccountName=%s))
        //   OpenLDAP: (&(objectClass=inetOrgPerson)(uid=%s))
        'userFilter' => (string)($_ENV['LDAP_USER_FILTER'] ?? '(&(objectClass=user)(sAMAccountName=%s))'),
        // Directory attributes — the defaults target Active Directory; override for OpenLDAP.
        'attrUsername' => (string)($_ENV['LDAP_ATTR_USERNAME'] ?? 'sAMAccountName'),
        'attrEmail' => (string)($_ENV['LDAP_ATTR_EMAIL'] ?? 'mail'),
        'attrDisplayName' => (string)($_ENV['LDAP_ATTR_DISPLAY_NAME'] ?? 'displayName'),
        // Stable identifier — objectGUID on AD, entryUUID on OpenLDAP. Survives renames.
        'attrUid' => (string)($_ENV['LDAP_ATTR_UID'] ?? 'objectGUID'),
        // Group lookup — use %s for the user DN returned by the initial search.
        'groupFilter' => (string)($_ENV['LDAP_GROUP_FILTER'] ?? '(&(objectClass=group)(member=%s))'),
        'groupNameAttr' => (string)($_ENV['LDAP_GROUP_NAME_ATTR'] ?? 'cn'),
        // JSON-decoded map of directory group names to Ansilume roles.
        'roleMapping' => $ldapRoleMapping,
        // Fallback role assigned when no group mapping matches. Empty = no role.
        'defaultRole' => (string)($_ENV['LDAP_DEFAULT_ROLE'] ?? ''),
        // Create a local account automatically on first successful bind.
        'autoProvision' => filter_var($_ENV['LDAP_AUTO_PROVISION'] ?? true, FILTER_VALIDATE_BOOLEAN),
    ],
];
