<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\LintState;
use app\components\StoredLintOutput;
use app\components\LintVerdict;
use app\controllers\ProjectController;
use app\models\AuditLog;
use app\services\LintService;
use PHPUnit\Framework\TestCase;

/**
 * Contract: the update notes of the release after 2.14.0 and the
 * troubleshooting entry for a lint run that never comes say that
 * ansible-lint runs in the queue-worker, never in a web request, with the
 * texts of the lint card, the flash of Run Lint, the warning of a save that
 * could not queue its run, the API's lint state and the queue-worker's log
 * line on 2.14.0 for a lint run that is still queued.
 */
final class LintQueueDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    public const ENTRY = '## Lint stays "Lint queued"';

    private const UPDATING = 'docs/updating.md';

    private const TROUBLESHOOTING = 'docs/troubleshooting.md';

    /** What the queue-worker of 2.14.0 logs for a lint run that is still queued, as AllowlistQueueSerializer words it. */
    private const REJECTED = 'Rejected queue message for class not on the allowlist: app\jobs\LintTemplateJob';

    public function testTheUpdateNotesQuoteTheCardTheFlashAndTheWarning(): void
    {
        $notes = $this->flat(self::UPDATING, Redis810UpdateDocsContractTest::UPDATE_SECTION);
        foreach (
            [
                '**Saving a job template no longer waits for ansible-lint.**',
                '**' . LintState::QUEUED . '**',
                '`' . ProjectController::NO_QUEUE_WORKER . ' ' . ProjectController::START_QUEUE_WORKER . '`',
                '`' . sprintf(ProjectController::FLASH_LINT_QUEUED, '<project>') . '`',
                '`' . LintService::NOT_QUEUED . '`',
                '`' . sprintf(LintService::PATH_NOT_FOUND, '<path>') . '`',
                '**A manual project\'s directory must exist in the queue-worker.**',
            ] as $needle
        ) {
            $this->assertMentions($needle, $notes, 'the update notes');
        }
        $this->assertSame('Project path not found: %s', LintService::PATH_NOT_FOUND, 'the result the notes quote');
    }

    public function testTheUpdateNotesNameTheApiTheEnvironmentAndTheWayBack(): void
    {
        $notes = $this->flat(self::UPDATING, Redis810UpdateDocsContractTest::UPDATE_SECTION);
        $statuses = '`' . implode('`, `', [LintVerdict::NOT_RUN, LintVerdict::CLEAN, LintVerdict::ISSUES, LintVerdict::VAULT_SKIPPED]) . '`, or null without a result';
        foreach (
            [
                $statuses,
                '`exit_code`, `checked_at` and `queued_at`',
                '`POST /api/v1/projects/{id}/lint` (`project.update` and operator access)',
                'it answers `202` with the project\'s lint state, or `503` when the queue cannot be reached, and is audited as `' . AuditLog::ACTION_PROJECT_LINTED . '`',
                '`PRE_COMMIT=1`',
                '(no `--offline`)',
                '`m000094`: `lint_queued_at` of `job_template` and `project`.',
                '`' . self::REJECTED . '`',
                // Review finding: an output over the TEXT column failed the store, and the card showed "Lint queued" for good.
                '`' . str_replace('[0 bytes', '[<n> bytes', sprintf(StoredLintOutput::LEFT_OUT, 0, StoredLintOutput::MAX_BYTES)) . '`',
                '`' . sprintf(LintService::NOT_STORED, '<error>') . '`',
            ] as $needle
        ) {
            $this->assertMentions($needle, $notes, 'the update notes');
        }
        $this->assertSame(['PRE_COMMIT' => '1'], LintService::SKIP_VERSION_CHECK, 'the variable the notes name');
        $this->assertMentions('"Rejected queue message for class not on the allowlist: {$class}"', $this->read('components/AllowlistQueueSerializer.php'), 'AllowlistQueueSerializer, as the notes quote it');
    }

    public function testTheEntryQuotesTheCardAndTheApi(): void
    {
        $entry = $this->flat(self::TROUBLESHOOTING, self::ENTRY);
        foreach (
            [
                '**' . LintState::QUEUED . '**',
                '`⚠ ' . ProjectController::NO_QUEUE_WORKER . ' ' . ProjectController::START_QUEUE_WORKER . '`',
                '`lint.queued_at`',
                '`POST /api/v1/projects/{id}/lint`',
                'the notification-worker does not count',
                // Review finding: the entry pointed at `docker compose logs`, which never names a run, and gave a plain
                // `docker compose up -d queue-worker`, which starts the development worker in a quickstart source install.
                'An installation the quickstart made from source adds `-f docker-compose.prebuilt.yml` to each `docker compose` command',
                '`docker compose logs` shows only that the worker started.',
                "docker compose exec queue-worker tail -n 200 /var/www/runtime/logs/app.log | grep -E 'Lint(Template|Project)Job'",
            ] as $needle
        ) {
            $this->assertMentions($needle, $entry, self::ENTRY);
        }
        // The anchor GitHub gives ENTRY: lower case, the quotes dropped.
        $this->assertMentions(
            '[troubleshooting.md](troubleshooting.md#lint-stays-lint-queued)',
            $this->flat(self::UPDATING, Redis810UpdateDocsContractTest::UPDATE_SECTION),
            'the update notes'
        );
    }
}
