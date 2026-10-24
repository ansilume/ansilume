<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\ProcessStarter;
use app\components\RunnerProcessExecutor;
use app\components\RunnerProjectSync;
use app\services\LintService;
use app\tests\unit\ProcessStartFailure;
use PHPUnit\Framework\TestCase;

/**
 * Contract: the update notes for PHP 8.3 (docs/updating.md) and the runner
 * docs (docs/runners.md) say who must act before the update, and quote what
 * a program that does not start leaves in a job's log, the project's error
 * and the lint result, in the words the code writes (ProcessStarter and its
 * callers).
 */
class PhpUpdateDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    public const UPDATE_SECTION = '## After updating from 2.12.2 or older: PHP 8.3, cron expressions, unreadable files';

    private const SECTION_ANCHOR = 'after-updating-from-2122-or-older-php-83-cron-expressions-unreadable-files';

    private const UPDATING = 'docs/updating.md';

    private const RUNNERS = 'docs/runners.md';

    /** What Parse Inventory answers when ansible-inventory is missing (InventoryService::resolve()). */
    private const INVENTORY_MISSING = 'ansible-inventory is not installed on this server.';

    /** What Generate Ed25519 Key Pair answers when the key cannot be generated (CredentialController). */
    private const KEY_GENERATION_FAILED = 'Key generation failed.';

    /**
     * The section is the newest, the one of 2.12.2 follows, and the manual
     * source update links to it under the anchor GitHub gives its heading.
     */
    public function testTheSectionIsTheNewest(): void
    {
        preg_match_all('/^## After updating from .*$/m', $this->read(self::UPDATING), $headings);

        $this->assertSame(self::UPDATE_SECTION, $headings[0][0] ?? null, 'The newest section comes first.');
        $this->assertSame(DeployRoleUpdateDocsContractTest::UPDATE_SECTION, $headings[0][1] ?? null, 'The section of 2.12.2 follows.');
        $this->assertSame(
            self::SECTION_ANCHOR,
            str_replace(' ', '-', (string)preg_replace('/[^a-z0-9 -]/', '', strtolower(substr(self::UPDATE_SECTION, 3)))),
            'The anchor GitHub gives the heading.'
        );
        $this->assertMentions('(#' . self::SECTION_ANCHOR . ')', $this->section(self::UPDATING, '## Manual update — source (git)'), 'the manual source update');
    }

    /**
     * Source installations must build right after git pull: a container of
     * 2.12.2 that starts again refuses the new composer.lock on PHP 8.2, as
     * composer install does on a runner installed without Docker. The deploy
     * role goes back with its own variable, the quickstart's installations
     * from source only pull, and the role's variable that never had an
     * effect is gone. Review findings: the deploy role and the quickstart
     * were sent to the manual steps, and the setups that worked with PHP 8.2
     * but not with 8.3 were not named.
     */
    public function testTheSectionSaysWhoMustAct(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);

        foreach (
            [
                '`php:8.3-fpm`',
                '`php:8.3-cli`',
                '`[entrypoint] ERROR: composer install failed`',
                'Run steps 2 and 3 right after `git pull`',
                'needs PHP 8.3 or newer before you update its code',
                '`ansilume_php_image`',
                '`ansilume_version: "v2.12.2"`',
                'an installation the quickstart made from source too: it runs `docker-compose.prebuilt.yml`',
                'a wrapper script without a `#!` line',
                'a Token credential named `PATH`',
                '`Exec format error`',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        $this->assertStringContainsString('"[entrypoint] ERROR: composer install failed"', $this->read('docker/php/entrypoint.sh'));
        $this->assertStringNotContainsString('ansilume_php_image', $this->read('deploy/roles/ansilume/defaults/main.yaml'));
    }

    /**
     * Review findings: a missing ansible-inventory never reaches the process
     * (a `which` check answers first), and Generate Ed25519 Key Pair answers
     * with a fixed message; the notes said otherwise.
     */
    public function testTheSectionQuotesWhatAProgramThatDoesNotStartLeaves(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);

        foreach (
            [
                self::jobLog(sprintf(ProcessStarter::NOT_STARTED, 'ansible-playbook', ProcessStartFailure::NO_SUCH_FILE)),
                sprintf(RunnerProjectSync::GIT_NOT_STARTED, sprintf(ProcessStarter::NOT_STARTED, 'git', '…')),
                sprintf(ProcessStarter::NOT_STARTED, 'git', '…'),
                sprintf(LintService::NOT_RUN, sprintf(ProcessStarter::NOT_STARTED_IN, 'ansible-lint', '<directory>', '…')),
                sprintf(ProcessStarter::NOT_STARTED, 'ansible-inventory', '…'),
                self::INVENTORY_MISSING,
                self::KEY_GENERATION_FAILED,
            ] as $message
        ) {
            $this->assertMentions("`{$message}`", $section, 'the update notes');
        }
        $this->assertStringContainsString("'" . self::INVENTORY_MISSING . "'", $this->read('services/InventoryService.php'));
        $this->assertStringContainsString("'" . self::KEY_GENERATION_FAILED . "'", $this->read('controllers/CredentialController.php'));
    }

    /**
     * runners.md names every way a job fails whose program does not start,
     * and that the programs must be on the runner process's own PATH.
     */
    public function testRunnersMdSaysWhyAJobDidNotStart(): void
    {
        $issues = $this->flat(self::RUNNERS, '### Common issues');

        foreach (
            [
                self::jobLog(sprintf(ProcessStarter::NOT_STARTED, 'ansible-playbook', ProcessStartFailure::NO_SUCH_FILE)),
                self::jobLog(sprintf(ProcessStarter::NOT_STARTED_IN, '<program>', '<directory>', 'proc_open(): posix_spawn() failed: Permission denied')),
                self::jobLog(sprintf(ProcessStarter::NOT_STARTED, '<program>', 'proc_open(): posix_spawn() failed: Permission denied')),
                '… failed: Exec format error',
                sprintf(RunnerProjectSync::GIT_NOT_STARTED, sprintf(ProcessStarter::NOT_STARTED, 'git', ProcessStartFailure::NO_SUCH_FILE)),
                'git: unavailable',
            ] as $message
        ) {
            $this->assertMentions("`{$message}`", $issues, 'the common issues of runners.md');
        }
        $this->assertMentions('must be on the `PATH` of the runner process itself', $this->flat(self::RUNNERS, '### Prerequisites'), 'the prerequisites of runners.md');
    }

    /** The line RunnerProcessExecutor writes to the job's log for $reason, without its line break. */
    private static function jobLog(string $reason): string
    {
        return rtrim(sprintf(RunnerProcessExecutor::NOT_STARTED, $reason), "\n");
    }
}
