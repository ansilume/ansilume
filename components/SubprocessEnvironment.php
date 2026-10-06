<?php

declare(strict_types=1);

namespace app\components;

/**
 * Builds the environment for subprocesses that can execute code controlled
 * by a project repository: ansible-playbook, ansible-lint and
 * ansible-inventory.
 *
 * Such a subprocess must never inherit Ansilume's own environment. The app,
 * queue-worker and runner processes carry APP_SECRET_KEY, DB_PASSWORD,
 * RUNNER_BOOTSTRAP_SECRET and similar values, while a repository can make
 * Ansible run arbitrary code: a vault password script referenced from its
 * ansible.cfg, inventory scripts, plugins, ansible-lint rule directories.
 * Copying getenv() into the child handed those master secrets to anyone who
 * controls a repository.
 *
 * Only an explicit allowlist of operational variables is forwarded: PATH,
 * locale, host identity, proxy and CA settings, and ANSIBLE_* settings an
 * operator may have configured on the host or container. Everything Ansilume
 * itself needs on top is passed in as overrides by the caller.
 */
final class SubprocessEnvironment
{
    /** Variables forwarded unchanged from the parent environment when set. */
    public const PASSTHROUGH = [
        'PATH',
        'LANG',
        'LANGUAGE',
        'TZ',
        'TMPDIR',
        'HOSTNAME',
        'USER',
        'LOGNAME',
        'http_proxy',
        'https_proxy',
        'no_proxy',
        'HTTP_PROXY',
        'HTTPS_PROXY',
        'NO_PROXY',
        'SSL_CERT_FILE',
        'SSL_CERT_DIR',
        'REQUESTS_CA_BUNDLE',
        'CURL_CA_BUNDLE',
    ];

    /**
     * Name prefixes forwarded from the parent environment: operator-configured
     * Ansible settings and the locale categories.
     */
    public const PASSTHROUGH_PREFIXES = ['ANSIBLE_', 'LC_'];

    /**
     * Ansilume's own secrets. They are never forwarded, not even when an
     * operator lists one of them as an extra name by mistake.
     */
    public const NEVER_FORWARD = [
        'APP_SECRET_KEY',
        'COOKIE_VALIDATION_KEY',
        'DB_PASSWORD',
        'DB_ROOT_PASSWORD',
        'REDIS_PASSWORD',
        'RUNNER_BOOTSTRAP_SECRET',
        'RUNNER_TOKEN',
        'SMTP_PASSWORD',
        'LDAP_BIND_PASSWORD',
    ];

    /** Used when the parent process has no PATH at all. */
    public const DEFAULT_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    /**
     * @param array<string, string> $parentEnv Usually getenv().
     * @param array<string, string> $overrides Values Ansilume sets itself; they win over forwarded ones.
     * @param list<string> $extraNames Additional names an operator explicitly allowed.
     * @return array<string, string>
     */
    public static function build(array $parentEnv, array $overrides = [], array $extraNames = []): array
    {
        $fromRequest = self::isCgiRequestEnvironment($parentEnv);
        $env = [];
        foreach ($parentEnv as $name => $value) {
            $name = (string)$name;
            // httpoxy: under php-fpm, getenv() also returns the request's
            // FastCGI params, and a client "Proxy:" header arrives as
            // HTTP_PROXY. Never let a request choose the subprocess proxy.
            if ($fromRequest && $name === 'HTTP_PROXY') {
                continue;
            }
            if (self::isForwarded($name, $extraNames)) {
                $env[$name] = (string)$value;
            }
        }
        $env['PATH'] ??= self::DEFAULT_PATH;

        return array_merge($env, $overrides);
    }

    /**
     * @param list<string> $extraNames
     */
    public static function isForwarded(string $name, array $extraNames = []): bool
    {
        if (in_array($name, self::NEVER_FORWARD, true)) {
            return false;
        }
        if (in_array($name, self::PASSTHROUGH, true) || in_array($name, $extraNames, true)) {
            return true;
        }
        foreach (self::PASSTHROUGH_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse an operator-supplied list of variable names, separated by commas
     * and/or whitespace. Entries that are not valid variable names are dropped.
     *
     * @return list<string>
     */
    public static function parseNameList(string $list): array
    {
        $names = preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $names,
            static fn (string $name): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1
        ));
    }

    /**
     * True when the environment belongs to a CGI/FastCGI request, i.e. HTTP_*
     * entries may come from client-controlled request headers.
     *
     * @param array<string, string> $env
     */
    private static function isCgiRequestEnvironment(array $env): bool
    {
        return isset($env['GATEWAY_INTERFACE']) || isset($env['REQUEST_METHOD']);
    }
}
