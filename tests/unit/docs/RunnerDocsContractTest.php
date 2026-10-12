<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\commands\RunnerController;
use app\components\RunnerSecretVars;
use PHPUnit\Framework\TestCase;

/**
 * docs/runners.md and the runner notes of docs/updating.md describe what
 * the runner does with the files of a job: where they go, what it prints
 * when it sweeps those of a killed process, and why the systemd unit still
 * gives it a /tmp of its own. The tests run the runner's code, so a change
 * on one side without the other fails here.
 *
 * Regressions (findings in brackets), each test fails on the docs as they
 * were before the fix:
 * - [RUN-1, SEC-EXP-3] the sweep's warning, the systemd unit's PrivateTmp
 *   and the runner fixes were missing.
 * - [V2-INV] runners now keep a job's files in a private directory of the
 *   job; the docs still named the old temp files, the old sweep messages
 *   and the old JOB_FILES_FAILED wording.
 *
 * From fix round 3:
 * - [R2V2-3] without the posix extension the runner cannot tell its user,
 *   so the sweep removes nothing and says why; the docs did not list posix
 *   among the prerequisites, nor this reason.
 * - [R2V2-5] runners of 2.8.1 or older left static inventories and task
 *   records in the temp directory, readable by every local user; the
 *   runner names them now, and the docs say how to remove them.
 *
 * From fix round 4:
 * - [R3V1-1] the sweep took any name that starts like a job directory's
 *   for one; it takes only the runner's own names now, and runners.md did
 *   not say which names it takes.
 */
class RunnerDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATE_SECTION = '## After updating from 2.8.1 or older';

    public function testTheRunnerSweepIsDescribedAsTheRunnerReportsIt(): void
    {
        if (!is_dir('/proc/self')) {
            $this->markTestSkipped('The sweep needs procfs.');
        }
        $sweep = $this->sweepAStaleDirectoryAndFile();

        $reasons = [
            RunnerSecretVars::SKIP_NOT_A_DIRECTORY,
            RunnerSecretVars::SKIP_OTHER_OWNER,
            RunnerSecretVars::SKIP_NOT_REMOVED,
            RunnerSecretVars::SKIP_UNKNOWN_USER,
        ];
        $warning = trim($sweep['errors']);
        $this->assertStringContainsString(RunnerSecretVars::SKIP_NOT_A_DIRECTORY, $warning, 'The sweep names the file it left.');
        $pattern = str_replace(
            ['PATH', 'REASON'],
            ['\S+', '(?:' . implode('|', array_map(static fn (string $reason): string => preg_quote($reason, '~'), $reasons)) . ')'],
            preg_quote(str_replace([$sweep['left'], RunnerSecretVars::SKIP_NOT_A_DIRECTORY], ['PATH', 'REASON'], $warning), '~')
        );
        $this->assertSame(1, preg_match('~' . $pattern . '~', $this->read('docs/runners.md')), "runners.md does not show the warning the runner prints: {$warning}");
        $flat = $this->flat('docs/runners.md');
        foreach ($reasons as $reason) {
            $this->assertMentions("`{$reason}`", $flat, 'runners.md');
        }
        $removed = trim($sweep['printed']);
        $this->assertMatchesRegularExpression('/ 1 job\(s\) /', $removed, 'The sweep removed the stale job directory.');
        $this->assertMentions(str_replace(' 1 ', ' N ', $removed), $flat, 'runners.md, quoting a sweep that removed a directory,');
    }

    /**
     * Regression (review R3V1-1): the sweep takes only the names the runner
     * gives job directories, as runners.md says. The name in the documented
     * warning, with the ID of a process that does not run, is named; that
     * name with more, fewer or upper case digits, another suffix or a line
     * break after it is neither removed nor named. The sentence speaks of the
     * job directory check only (review R4V2-1): the runner also names the
     * files of older runners.
     */
    public function testTheSweepIsDescribedAsTakingOnlyTheRunnersOwnNames(): void
    {
        if (!is_dir('/proc/self')) {
            $this->markTestSkipped('The sweep needs procfs.');
        }
        $this->assertMentions(
            'This check considers only entries named exactly `ansilume_job_<pid>_<32 hexadecimal digits>`, with the digits in lower case,'
            . ' as the runner names job directories, and neither removes nor reports any other entry;'
            . ' the files of older runners are reported separately (see **Files of older runners** below).',
            $this->flat('docs/runners.md'),
            'runners.md, on the sweep,'
        );
        if (preg_match('~^WARNING: Left /tmp/' . RunnerSecretVars::DIRECTORY_PREFIX . '\d+_(\S+) in place ~m', $this->read('docs/runners.md'), $match) !== 1) {
            $this->fail('runners.md shows no warning about a job directory.');
        }
        $random = $match[1];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $random, 'The documented name ends in 32 hexadecimal digits in lower case.');
        $others = [$random . '0', substr($random, 0, -1), strtoupper($random), $random . '_x', $random . "\n"];

        $directory = $this->scratchDirectory('ansilume-docs-names-');
        $stale = $directory . '/' . RunnerSecretVars::DIRECTORY_PREFIX . self::deadPid() . '_';
        foreach ([$random, ...$others] as $suffix) {
            file_put_contents($stale . $suffix, '{}');
        }
        try {
            $output = $this->startCheck($directory);
            foreach ($others as $suffix) {
                $this->assertFileExists($stale . $suffix, 'The sweep leaves every other name alone.');
            }
        } finally {
            self::removeTree($directory);
        }
        $this->assertSame('', $output['printed'], 'The sweep removed nothing.');
        $lines = preg_split('/\R/', trim($output['errors'])) ?: [];
        $this->assertCount(1, $lines, 'The sweep names the documented name only.');
        $this->assertStringStartsWith("WARNING: Left {$stale}{$random} in place (", $lines[0]);
    }

    /**
     * Without the posix extension the runner cannot tell its own user: the
     * sweep removes nothing and names every stale match, on every start, as
     * runners.md says, and the prerequisites and the update steps name the
     * extension.
     */
    public function testARunnerThatCannotTellItsUserIsDescribedAsTheSweepReportsIt(): void
    {
        if (!is_dir('/proc/self')) {
            $this->markTestSkipped('The sweep needs procfs.');
        }
        $directory = $this->scratchDirectory('ansilume-docs-nouser-');
        $stale = $directory . '/' . self::staleName();
        mkdir($stale);
        file_put_contents($stale . '/' . RunnerController::INVENTORY_FILE, "localhost\n");
        try {
            $output = $this->startCheck($directory, static fn (): ?int => null);
            $this->assertDirectoryExists($stale, 'Nothing is removed.');
        } finally {
            self::removeTree($directory);
        }
        $this->assertSame('', $output['printed'], 'Nothing is removed.');
        $this->assertStringContainsString('(' . RunnerSecretVars::SKIP_UNKNOWN_USER . ')', $output['errors']);

        $this->assertMentions(
            'Without the PHP extension `posix`, the runner cannot tell whose a directory is, so it removes nothing and names every match at each start',
            $this->flat('docs/runners.md'),
            'runners.md, on the sweep,'
        );
        $prerequisites = $this->flat('docs/runners.md', '### Prerequisites');
        $this->assertMentions('`pcntl`, `posix`, `zip`, `curl`, and `ffi`', $prerequisites, 'runners.md, on the prerequisites,');
        $this->assertMentions('`php -m | grep -x posix`', $prerequisites, 'runners.md, on the prerequisites,');
        $this->assertMentions('check that PHP has the `posix` extension: `php -m | grep -x posix`', $this->flat('docs/updating.md', self::UPDATE_SECTION), 'updating.md, on updating runners without Docker,');
    }

    /**
     * The runner names the files runners of 2.8.1 or older left in its temp
     * directory, at every start, and removes none: the line runners.md shows
     * is the one it prints, and the command the docs give matches the names.
     */
    public function testTheFilesOfOlderRunnersAreDescribedAsTheRunnerNamesThem(): void
    {
        $directory = $this->scratchDirectory('ansilume-docs-older-');
        // The names runners of 2.8.1 or older gave them.
        $inventory = $directory . '/ansilume_inv_' . uniqid('', true) . '.yml';
        $tasks = $directory . '/ansilume_tasks_42_' . uniqid('', true) . '.ndjson';
        // Runners older than 1.0 left out .yml; the runner does not name these.
        $unnamed = $directory . '/ansilume_inv_' . uniqid('', true);
        file_put_contents($inventory, "localhost\n");
        file_put_contents($tasks, "{}\n");
        file_put_contents($unnamed, "localhost\n");
        try {
            $first = $this->startCheck($directory);
            $second = $this->startCheck($directory);
            $this->assertFileExists($inventory, 'The runner removes none of them.');
            $this->assertFileExists($tasks, 'The runner removes none of them.');
            $this->assertFileExists($unnamed, 'The runner removes none of them.');
        } finally {
            self::removeTree($directory);
        }
        $this->assertSame($first, $second, 'The runner names them at every start.');
        $lines = preg_split('/\R/', trim($first['errors'])) ?: [];
        $this->assertCount(2, $lines, 'One line per file.');

        $runners = $this->read('docs/runners.md');
        $flat = $this->flat('docs/runners.md');
        foreach ([$inventory, $tasks] as $index => $path) {
            $this->assertStringContainsString($path, $lines[$index]);
            $pattern = str_replace('PATH', '\S+', preg_quote(str_replace($path, 'PATH', $lines[$index]), '~'));
            $this->assertSame(1, preg_match('~^' . $pattern . '$~m', $runners), "runners.md does not show the line the runner prints: {$lines[$index]}");
        }
        foreach (['`ansilume_inv_<unique>.yml`', '`ansilume_tasks_<job id>_<unique>.ndjson`'] as $name) {
            $this->assertMentions($name, $flat, 'runners.md, on the files of older runners,');
        }
        $this->assertStringNotContainsString($unnamed . ' ', $first['errors'], 'The inventory of a runner older than 1.0 is not named.');
        $this->assertMentions('as `ansilume_inv_<unique>`, which the runner does not name; remove such files too', $flat, 'runners.md');

        $command = 'sudo rm -f -- /tmp/ansilume_inv_*.yml /tmp/ansilume_tasks_*.ndjson';
        $this->assertMentions('`' . $command . '`', $flat, 'runners.md, on the files of older runners,');
        $this->assertMentions('`' . $command . '`', $this->flat('docs/updating.md', self::UPDATE_SECTION), 'updating.md, on updating runners without Docker,');
        preg_match_all('~/tmp/(\S+)~', $command, $globs);
        foreach ([$inventory, $tasks] as $index => $path) {
            $this->assertTrue(fnmatch($globs[1][$index], basename($path)), "The command removes {$path}.");
        }
        $fixes = $this->between($this->flat('docs/updating.md', self::UPDATE_SECTION), '**Fixes that change behaviour:**', '**API contract change:**');
        $this->assertMentions('Updated runners name such files that they find in their temp directory at start, but do not remove them', $fixes, 'The fixes of updating.md');
    }

    /**
     * The runner creates the directory the docs describe: named after its
     * process ID and a random part, 0700, with the answers file in it, and
     * gone after the job.
     */
    public function testTheJobDirectoryIsDescribedAsTheRunnerCreatesIt(): void
    {
        $base = sys_get_temp_dir() . '/ansilume-docs-job-' . bin2hex(random_bytes(4));
        mkdir($base, 0o700);
        $jobFiles = new RunnerSecretVars($base);
        try {
            $directory = $jobFiles->jobDirectory();
            $this->assertNotNull($directory);
            $this->assertMatchesRegularExpression(
                '/^' . RunnerSecretVars::DIRECTORY_PREFIX . getmypid() . '_[0-9a-f]{32}$/D',
                basename($directory),
                'named after the process ID and a random part of 32 lowercase hex digits'
            );
            $this->assertSame(0o700, fileperms($directory) & 0o777);
            $answersFile = $directory . '/' . RunnerSecretVars::ANSWERS_FILE;
            $this->assertSame(['--extra-vars', '@' . $answersFile], $jobFiles->prepare([RunnerSecretVars::PAYLOAD_KEY => ['db_password' => 'docs-contract-marker-5512']]));
            $this->assertSame(0o600, fileperms($answersFile) & 0o777);
            $jobFiles->cleanup();
            $this->assertDirectoryDoesNotExist($directory, 'The directory goes when the job ends.');
        } finally {
            $jobFiles->cleanup();
            rmdir($base);
        }

        $runners = $this->flat('docs/runners.md');
        $names = [RunnerSecretVars::DIRECTORY_PREFIX . '<pid>_<random>', RunnerController::INVENTORY_FILE, RunnerController::TASKS_FILE, RunnerSecretVars::ANSWERS_FILE];
        foreach ([...$names, '0700', '0600', 'inventory_dir'] as $name) {
            $this->assertMentions("`{$name}`", $runners, 'runners.md, on the private directory of a job,');
        }
    }

    public function testTheDocumentedSystemdUnitGivesTheRunnerItsOwnTmp(): void
    {
        preg_match_all('/```ini\R(.*?)```/s', $this->read('docs/runners.md'), $blocks);
        $units = array_values(array_filter($blocks[1], static fn (string $block): bool => str_contains($block, 'yii runner/start')));
        $this->assertCount(1, $units, 'runners.md has one systemd unit for the runner.');
        $section = null;
        $service = [];
        foreach (preg_split('/\R/', $units[0]) ?: [] as $line) {
            if (preg_match('/^\[(\w+)\]$/', trim($line), $match) === 1) {
                $section = $match[1];
            } elseif ($section === 'Service' && trim($line) !== '') {
                $service[] = trim($line);
            }
        }
        $this->assertContains('PrivateTmp=true', $service, 'The runner\'s other temp files need a /tmp of the runner\'s own.');

        // What it still protects, now that a job's own files are private anyway.
        $why = array_values(array_filter($this->units('docs/runners.md'), static fn (string $unit): bool => str_starts_with($unit, '`PrivateTmp=true` gives')));
        $this->assertCount(1, $why, 'runners.md says what PrivateTmp=true does.');
        foreach (['credential files', 'older than this release'] as $reason) {
            $this->assertMentions($reason, $why[0], 'runners.md, on PrivateTmp=true,');
        }
    }

    public function testTheRunnerFixesAreListedAmongTheFixes(): void
    {
        $fixes = $this->between($this->flat('docs/updating.md', self::UPDATE_SECTION), '**Fixes that change behaviour:**', '**API contract change:**');
        $this->assertMentions(RunnerController::JOB_FILES_FAILED, $fixes, 'The fixes, for the private directory of a job (SEC-EXP-3, V2-INV),');
        $this->assertMentions('`0700`', $fixes, 'The fixes, for the private directory of a job (V2-INV),');
        $this->assertMentions('`inventory_dir`', $fixes, 'The fixes, for what playbooks see (V2-INV),');
        $this->assertMentions('U+FFFD', $fixes, 'The fixes, for job output cut inside a character (RUN-2),');
    }

    /**
     * The runner's start-up sweep over a directory with two stale matches
     * of a process that no longer runs: a job directory, which it removes,
     * and a file, which it leaves and names.
     *
     * @return array{printed: string, errors: string, left: string} what the runner printed, and the file it left
     */
    private function sweepAStaleDirectoryAndFile(): array
    {
        $directory = $this->scratchDirectory('ansilume-docs-sweep-');
        $jobDirectory = $directory . '/' . self::staleName();
        $file = $directory . '/' . self::staleName();
        mkdir($jobDirectory);
        file_put_contents($jobDirectory . '/' . RunnerController::INVENTORY_FILE, "localhost\n");
        file_put_contents($file, '{}');
        try {
            $output = $this->startCheck($directory);
        } finally {
            self::removeTree($directory);
        }

        return [...$output, 'left' => $file];
    }

    /**
     * A name the runner gives job directories, of a process that does not
     * run: the prefix, the process ID and 32 lowercase hex digits.
     */
    private static function staleName(): string
    {
        return RunnerSecretVars::DIRECTORY_PREFIX . self::deadPid() . '_' . bin2hex(random_bytes(16));
    }

    /**
     * What the runner prints when it checks $directory at start
     * (RunnerController::removeStaleSecretFiles()), with its effective user
     * ID from $effectiveUid when given.
     *
     * @param (\Closure(): ?int)|null $effectiveUid
     * @return array{printed: string, errors: string} stdout and stderr
     */
    private function startCheck(string $directory, ?\Closure $effectiveUid = null): array
    {
        $app = \Yii::$app;
        assert($app instanceof \yii\base\Module);
        $runner = new class ('runner', $app, $directory, $effectiveUid) extends RunnerController {
            public string $printed = '';
            public string $errors = '';

            public function __construct(
                string $id,
                \yii\base\Module $module,
                private readonly string $directory,
                private readonly ?\Closure $effectiveUid
            ) {
                parent::__construct($id, $module);
            }

            public function sweep(): void
            {
                $this->removeStaleSecretFiles();
            }

            protected function tempDirectory(): string
            {
                return $this->directory;
            }

            protected function secretVars(): RunnerSecretVars
            {
                return new RunnerSecretVars($this->directory, $this->effectiveUid);
            }

            public function stdout($string): int
            {
                $this->printed .= (string)$string;

                return 0;
            }

            public function stderr($string): int
            {
                $this->errors .= (string)$string;

                return 0;
            }
        };
        $runner->sweep();

        return ['printed' => $runner->printed, 'errors' => $runner->errors];
    }

    /**
     * A new directory of the test's own, mode 0700, in the system temp
     * directory.
     */
    private function scratchDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($directory, 0o700));

        return $directory;
    }

    /**
     * The highest process ID that runs no process now.
     */
    private static function deadPid(): int
    {
        $pid = 4194303;
        while (is_dir('/proc/' . $pid)) {
            $pid--;
        }

        return $pid;
    }

    /**
     * Removes the test's own directory tree, which holds no links.
     */
    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (file_exists($path)) {
                unlink($path);
            }

            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
