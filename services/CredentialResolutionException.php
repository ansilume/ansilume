<?php

declare(strict_types=1);

namespace app\services;

/**
 * A job's credential could not be used: it was deleted after the job was
 * launched, or it cannot be decrypted. The job must fail before it runs,
 * not run without the credential.
 *
 * The message is meant for operators and lands in the job log. It never
 * contains secret material or decryption internals.
 */
final class CredentialResolutionException extends \RuntimeException
{
    public const REASON_MISSING = 'missing';
    public const REASON_UNDECRYPTABLE = 'undecryptable';

    /**
     * @param list<array{id: int, name: string|null, role: string, reason: string}> $failures
     */
    private function __construct(string $message, public readonly array $failures)
    {
        parent::__construct($message);
    }

    /**
     * @param list<array{id: int, name: string|null, role: string, reason: string}> $failures
     */
    public static function fromFailures(array $failures): self
    {
        $lines = ['Job aborted before execution: ' . count($failures) . ' credential(s) could not be used.'];
        foreach ($failures as $failure) {
            $label = 'Credential #' . $failure['id']
                . ($failure['name'] !== null ? ' "' . $failure['name'] . '"' : '')
                . ' (' . $failure['role'] . ')';
            $lines[] = $failure['reason'] === self::REASON_MISSING
                ? $label . ' no longer exists. It was deleted after the job was launched. '
                    . 'Attach a replacement to the job template and relaunch.'
                : $label . ' cannot be decrypted. APP_SECRET_KEY has probably changed since it was saved. '
                    . 'Re-enter its secret on the credential page, then relaunch.';
        }
        $lines[] = 'The job did not start; no credentials were used.';

        return new self(implode("\n", $lines), $failures);
    }
}
