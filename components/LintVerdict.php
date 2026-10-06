<?php

declare(strict_types=1);

namespace app\components;

/**
 * Classifies a stored ansible-lint result for display.
 *
 * Lint never gets a vault password on the server, so a playbook that loads
 * vault-encrypted vars_files fails lint with a decryption error. That is not
 * a finding, so it gets its own neutral verdict. Classification happens at
 * display time from the stored exit code and output, so it also covers
 * results stored before this existed.
 */
final class LintVerdict
{
    public const NOT_RUN = 'not-run';
    public const CLEAN = 'clean';
    public const ISSUES = 'issues';
    public const VAULT_SKIPPED = 'vault-skipped';

    public const VAULT_NOTE = 'This playbook loads vault-encrypted files (vars_files or group_vars). '
        . 'Ansilume never decrypts vault content on the server, so ansible-lint could not check it. '
        . 'The playbook still runs normally on a runner with its vault credential. '
        . 'Other findings, if any, are in the output below.';

    public static function of(?int $exitCode, ?string $output): string
    {
        if ($exitCode === null) {
            return self::NOT_RUN;
        }
        if ($exitCode === 0) {
            return self::CLEAN;
        }

        return VaultIsolation::mentionsDecryptionFailure((string)$output) ? self::VAULT_SKIPPED : self::ISSUES;
    }

    public static function label(string $verdict): string
    {
        return match ($verdict) {
            self::CLEAN => 'clean',
            self::ISSUES => 'issues found',
            self::VAULT_SKIPPED => 'not lint-checked: vault-encrypted vars_files',
            default => 'not run',
        };
    }

    public static function badgeClass(string $verdict): string
    {
        return match ($verdict) {
            self::CLEAN => 'text-bg-success',
            self::ISSUES => 'text-bg-warning',
            default => 'text-bg-secondary',
        };
    }
}
