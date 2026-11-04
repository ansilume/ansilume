<?php

declare(strict_types=1);

namespace app\components;

use app\models\JobTemplate;
use app\models\Project;

/**
 * The lint state of a job template or a project, as the lint card of its
 * page, the card's poll and the API show it: the stored result of the last
 * lint run (lint_output, lint_exit_code, lint_at) and whether a run is
 * queued (lint_queued_at: a save or Run Lint queued a run whose result is
 * not stored yet; LintService).
 *
 * The lint output is shown to whoever may view the template or the project:
 * it never holds secrets, as ansible-lint runs without the server's
 * environment (SubprocessEnvironment) and without vault passwords
 * (VaultIsolation).
 */
final class LintState
{
    /** The badge of a queued lint run. */
    public const QUEUED = 'Lint queued';

    /** Next to the badge: when the queued run was requested; sprintf(): the date and time. */
    public const QUEUED_SINCE = 'since %s';

    /** Above the stored result while a run is queued; sprintf(): when it was linted. */
    public const PREVIOUS_RUN = 'Previous run, %s. The page shows the new result once the queued run has stored it.';

    /** In place of the output while the first run is queued. */
    public const FIRST_RUN_QUEUED = 'The lint run is queued: its output appears here once the queue worker has run it.';

    /**
     * The note of the lint card once its poll was refused: 401 or 403
     * (signed out, or no access any more) or 404 (the record is gone). The
     * page stops polling.
     */
    public const LIVE_STOPPED = 'Live updates stopped. Reload the page to see the lint result.';

    private function __construct(
        private readonly ?string $output,
        private readonly ?int $exitCode,
        private readonly ?int $checkedAt,
        private readonly ?int $queuedAt,
    ) {
    }

    public static function of(JobTemplate|Project $record): self
    {
        return new self(
            $record->lint_output === null ? null : (string)$record->lint_output,
            self::intOrNull($record->lint_exit_code),
            self::intOrNull($record->lint_at),
            self::intOrNull($record->lint_queued_at),
        );
    }

    /** Whether a lint run is queued whose result is not stored yet. */
    public function isQueued(): bool
    {
        return $this->queuedAt !== null;
    }

    /** When the queued run was requested, Unix seconds; null when none is queued. */
    public function queuedAt(): ?int
    {
        return $this->queuedAt;
    }

    /**
     * The verdict of the stored result (LintVerdict: not-run, clean, issues,
     * vault-skipped); null when no result is stored.
     */
    public function status(): ?string
    {
        return $this->checkedAt === null ? null : LintVerdict::of($this->exitCode, $this->output);
    }

    /**
     * What the lint card's poll answers (lint-status): whether a run is
     * still queued, and when the stored result was linted.
     *
     * @return array{queued: bool, lint_at: int|null}
     */
    public function poll(): array
    {
        return ['queued' => $this->isQueued(), 'lint_at' => $this->checkedAt];
    }

    /**
     * The `lint` object of the API: the verdict of the stored result, its
     * exit code, when it was linted and when a queued run was requested;
     * with its output for one record ($withOutput), not in lists.
     *
     * @return array{status: string|null, exit_code: int|null, checked_at: int|null, queued_at: int|null, output?: string|null}
     */
    public function toApi(bool $withOutput): array
    {
        $lint = [
            'status' => $this->status(),
            'exit_code' => $this->exitCode,
            'checked_at' => $this->checkedAt,
            'queued_at' => $this->queuedAt,
        ];
        if ($withOutput) {
            $lint['output'] = $this->output;
        }

        return $lint;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int)$value;
    }
}
