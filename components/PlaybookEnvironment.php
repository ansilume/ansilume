<?php

declare(strict_types=1);

namespace app\components;

/**
 * Environment for ansible-playbook runs on the pull runner.
 *
 * Playbooks only see the allowlist from {@see SubprocessEnvironment}, the
 * settings Ansilume needs for its callback plugin, and the variables an
 * operator explicitly lists in RUNNER_ENV_PASSTHROUGH. Before this, every
 * playbook inherited the runner's full environment, which includes
 * RUNNER_BOOTSTRAP_SECRET (and in the dev setup the whole .env).
 */
final class PlaybookEnvironment
{
    /**
     * Writable HOME for lookup plugins that shell out to CLIs needing
     * ~/.config or ~/.cache (op, hcloud, aws, gh, ...). The default home of
     * www-data (/var/www) is root-owned. The entrypoints create and chown it.
     */
    public const ANSIBLE_HOME = '/var/www/runtime/ansible-home';

    /** Runner setting: names of extra variables to forward to playbooks. */
    public const PASSTHROUGH_VAR = 'RUNNER_ENV_PASSTHROUGH';

    /**
     * Parent variables nobody expects inside a playbook: shell/image noise and
     * Ansilume's own configuration (see .env.example). They are not forwarded
     * and get no start-up notice.
     */
    private const QUIET_NAMES = [
        'API_URL',
        'GPG_KEYS',
        'HOME',
        'OLDPWD',
        'PWD',
        'SHELL',
        'SHLVL',
        'TERM',
        '_',
        'ADMIN_EMAIL',
        'SENDER_EMAIL',
        'NGINX_PORT',
        'ADMINER_PORT',
        'MAILHOG_PORT',
    ];

    /** Parent variable prefixes that get no start-up notice, for the same reasons. */
    private const QUIET_PREFIXES = [
        'PHP',
        'RUNNER_',
        'APP_',
        'YII_',
        'DB_',
        'REDIS_',
        'SMTP_',
        'LDAP_',
        'COOKIE_',
        'SESSION_',
        'ARTIFACT_',
        'AUDIT_',
        'JOB_',
        'MAINTENANCE_',
        'COMPOSE_',
    ];

    /**
     * @param array<string, string> $parentEnv Usually getenv().
     * @return array<string, string>
     */
    public static function build(array $parentEnv, string $callbackFile): array
    {
        $overrides = [
            'ANSIBLE_CALLBACK_PLUGINS' => dirname(__DIR__) . '/ansible/callback_plugins',
            'ANSIBLE_CALLBACKS_ENABLED' => 'ansilume_callback',
            'ANSIBLE_CALLBACK_WHITELIST' => 'ansilume_callback',
            'ANSILUME_CALLBACK_FILE' => $callbackFile,
            'ANSIBLE_FORCE_COLOR' => '1',
            'PYTHONUNBUFFERED' => '1',
            'HOME' => self::ANSIBLE_HOME,
        ];

        return SubprocessEnvironment::build($parentEnv, $overrides, self::extraNames($parentEnv));
    }

    /**
     * Names the operator listed in RUNNER_ENV_PASSTHROUGH.
     *
     * @param array<string, string> $parentEnv
     * @return list<string>
     */
    public static function extraNames(array $parentEnv): array
    {
        return SubprocessEnvironment::parseNameList((string)($parentEnv[self::PASSTHROUGH_VAR] ?? ''));
    }

    /**
     * Sorted names of parent variables that playbooks will not see and that
     * an operator might have meant for them. Ansilume's own secrets are left
     * out because they can never be forwarded anyway.
     *
     * @param array<string, string> $parentEnv
     * @return list<string>
     */
    public static function droppedNames(array $parentEnv): array
    {
        $extra = self::extraNames($parentEnv);
        $dropped = [];
        foreach (array_keys($parentEnv) as $name) {
            $name = (string)$name;
            if (!SubprocessEnvironment::isForwarded($name, $extra) && !self::isQuiet($name)) {
                $dropped[] = $name;
            }
        }
        sort($dropped);

        return $dropped;
    }

    /**
     * Operator-facing notices for the runner start-up log.
     *
     * @param array<string, string> $parentEnv
     * @return list<string>
     */
    public static function startupNotices(array $parentEnv, ProcessHardening $hardening): array
    {
        $notices = [];
        $dropped = self::droppedNames($parentEnv);
        if ($dropped !== []) {
            $notices[] = 'Environment variables not forwarded to playbooks: ' . implode(', ', $dropped)
                . '. List the ones playbooks need in ' . self::PASSTHROUGH_VAR
                . ', or attach secrets as Token credentials.';
        }
        $reserved = array_values(array_intersect(self::extraNames($parentEnv), SubprocessEnvironment::NEVER_FORWARD));
        if ($reserved !== []) {
            $notices[] = 'Ignored in ' . self::PASSTHROUGH_VAR . ' (reserved for Ansilume, never forwarded): '
                . implode(', ', $reserved) . '.';
        }
        if ($hardening->isDumpable() !== false) {
            $notices[] = 'WARNING: the runner process could not be marked non-dumpable ('
                . ProcessHardening::UNAVAILABLE_REASON . "). Playbooks running as the same user can read the "
                . "runner's environment, including RUNNER_BOOTSTRAP_SECRET, via /proc.";
        }

        return $notices;
    }

    private static function isQuiet(string $name): bool
    {
        if (in_array($name, self::QUIET_NAMES, true) || in_array($name, SubprocessEnvironment::NEVER_FORWARD, true)) {
            return true;
        }
        foreach (self::QUIET_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
