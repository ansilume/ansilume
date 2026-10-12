<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\RunnerSecretVars;
use app\tests\unit\TraceArguments;
use PHPUnit\Framework\TestCase;
use yii\helpers\FileHelper;

/**
 * Every job gets a new directory that only the runner's user can open; the
 * runner writes the job's own files into it (task records, a static
 * inventory, the password answers of payload['secret_extra_vars'] for
 * `--extra-vars @file`), fails closed when it cannot, and removes the
 * directory with everything in it afterwards, without following links. At
 * start it removes the directories killed runner processes left behind, as
 * far as it may, and reports the rest, taking only names of its own format,
 * and it names the files that runners of 2.8.1 or older left in the temp
 * directory without removing them.
 */
class RunnerSecretVarsTest extends TestCase
{
    private const ANSWER = 'example-db-answer-4711';

    /** The sweep's reason without a known user, spelled out so the regression fails on the code before it. */
    private const UNKNOWN_USER = "the runner's user is unknown: the posix extension is missing";

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runner_secret_vars_' . uniqid('', true);
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        // Yii's helper removes links instead of following them.
        FileHelper::removeDirectory($this->dir);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function noAnswersProvider(): array
    {
        return [
            'older server without the field' => [['job_id' => 7, 'command' => ['ansible-playbook']]],
            'no answers' => [['secret_extra_vars' => []]],
            'not a list or map' => [['secret_extra_vars' => 'db_password']],
            'null' => [['secret_extra_vars' => null]],
        ];
    }

    /**
     * Without answers the command runs as sent and nothing is written.
     *
     * @dataProvider noAnswersProvider
     * @param array<string, mixed> $payload
     */
    public function testWithoutAnswersNothingIsWritten(array $payload): void
    {
        $vars = new RunnerSecretVars($this->dir);

        $this->assertSame([], $vars->prepare($payload));
        $this->assertSame([], $this->files());
        $vars->cleanup();
    }

    public function testTheAnswersGoToAPrivateFileInTheJobDirectory(): void
    {
        $answers = [
            'db_password' => self::ANSWER,
            'pin' => 4711,
            'ratio' => 0.25,
            'nested' => ['list' => [1, 'two'], 'flag' => true, 'none' => null],
            'unicode' => 'pässwört "quoted" \\ /',
        ];
        $vars = new RunnerSecretVars($this->dir);

        $args = $vars->prepare(['secret_extra_vars' => $answers]);

        $this->assertCount(2, $args);
        $this->assertSame('--extra-vars', $args[0]);
        $this->assertMatchesRegularExpression($this->jobDirectoryPattern('/' . RunnerSecretVars::ANSWERS_FILE, '@'), $args[1]);
        $path = substr($args[1], 1);
        $directory = dirname($path);
        $this->assertSame($vars->jobDirectory(), $directory, "the file is in the job's directory");
        $this->assertSame([$directory], $this->files());
        $this->assertSame([$path], glob($directory . '/*'));
        $this->assertSame(0o700, fileperms($directory) & 0o7777);
        $this->assertSame(0o600, fileperms($path) & 0o7777);
        $content = (string)file_get_contents($path);
        $this->assertSame($answers, json_decode($content, true), 'the values keep their JSON types');
        $this->assertStringContainsString('pässwört', $content, 'unicode as the inline JSON writes it');

        $vars->cleanup();
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertSame([], $this->files());
        $vars->cleanup();
    }

    /**
     * -e expects a map; answers whose names look like list indexes must not
     * turn into a JSON list.
     */
    public function testTheFileHoldsAJsonObjectEvenForListLikeNames(): void
    {
        $vars = new RunnerSecretVars($this->dir);

        $args = $vars->prepare(['secret_extra_vars' => ['first', ['nested', 'list']]]);

        $this->assertSame('{"0":"first","1":["nested","list"]}', file_get_contents(substr($args[1], 1)));
        $vars->cleanup();
    }

    /**
     * Fail closed: without the file the job must not run, and the answers
     * never go inline instead. The value stays out of the exception trace.
     */
    public function testAnUnusableDirectoryFailsWithAFixedMessage(): void
    {
        $umask = umask();
        $before = $this->jobDirectoriesInTheSystemTempDirectory();
        $vars = new RunnerSecretVars($this->dir . '/missing');

        try {
            $vars->prepare(['secret_extra_vars' => ['db_password' => self::ANSWER]]);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame(RunnerSecretVars::WRITE_FAILED, $e->getMessage());
            TraceArguments::assertSecretAbsent(self::ANSWER, $e);
        }

        $this->assertSame($umask, umask(), 'the umask is restored');
        $this->assertSame([], $this->files());
        $this->assertSame($before, $this->jobDirectoriesInTheSystemTempDirectory(), 'no fallback to the system temp directory');
        $vars->cleanup();
    }

    public function testAnswersThatCannotBeEncodedFailTheSameWay(): void
    {
        $vars = new RunnerSecretVars($this->dir);

        try {
            $vars->prepare(['secret_extra_vars' => ['db_password' => INF]]);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame(RunnerSecretVars::WRITE_FAILED, $e->getMessage());
        }
        $this->assertSame([], $this->files(), 'not even the directory');
    }

    /**
     * Warnings while writing stay local: Yii would turn them into an
     * exception whose trace shows the arguments.
     */
    public function testTheErrorHandlerAndUmaskAreRestoredAfterAWrite(): void
    {
        $umask = umask(0o022);
        $marker = static fn (): bool => false;
        set_error_handler($marker);
        try {
            $vars = new RunnerSecretVars($this->dir);
            $vars->prepare(['secret_extra_vars' => ['db_password' => self::ANSWER]]);
            $vars->cleanup();

            $this->assertSame(0o022, umask());
            $this->assertSame($marker, set_error_handler(static fn (): bool => false));
            restore_error_handler();
        } finally {
            restore_error_handler();
            umask($umask);
        }
        $this->assertSame([], $this->files());
    }

    public function testAnswersReadsOnlyTheField(): void
    {
        $this->assertSame(['db_password' => self::ANSWER], RunnerSecretVars::answers(['secret_extra_vars' => ['db_password' => self::ANSWER], 'extra_vars' => '{}']));
        $this->assertSame([], RunnerSecretVars::answers(['extra_vars' => '{"db_password":"x"}']));
    }

    // -- The job's directory --------------------------------------------------------

    /**
     * Whatever the process umask, the directory is new and only the
     * runner's user can open it; the job keeps one directory, and every job
     * gets its own.
     */
    public function testTheJobDirectoryIsNewAndOnlyTheRunnersUserCanOpenIt(): void
    {
        $umask = umask(0);
        try {
            $vars = new RunnerSecretVars($this->dir);
            $directory = $vars->jobDirectory();
            $this->assertSame(0, umask(), 'the umask is restored');
        } finally {
            umask($umask);
        }

        $this->assertIsString($directory);
        $this->assertMatchesRegularExpression($this->jobDirectoryPattern(), $directory);
        $this->assertSame(0o700, fileperms($directory) & 0o7777);
        $this->assertSame([], glob($directory . '/*'));
        $this->assertSame($directory, $vars->jobDirectory(), 'one directory per job');
        $other = new RunnerSecretVars($this->dir);
        $this->assertNotSame($directory, $other->jobDirectory(), 'every job gets its own');
        $other->cleanup();
        $vars->cleanup();
        $this->assertSame([], $this->files());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function plantedPathProvider(): array
    {
        return [
            'a directory' => ['directory'],
            'a file' => ['file'],
            'a link to a directory' => ['link'],
            'a dangling link' => ['dangling'],
        ];
    }

    /**
     * Whatever someone put at the path first (in a shared temp directory
     * anyone can), it is neither reused nor followed nor removed: the job
     * gets no directory, so it fails.
     *
     * @dataProvider plantedPathProvider
     */
    public function testAPathThatExistsIsNeverReusedFollowedOrRemoved(string $planted): void
    {
        $path = $this->dir . '/' . RunnerSecretVars::DIRECTORY_PREFIX . getmypid() . '_planted';
        $target = $this->dir . '/target';
        mkdir($target . '/group_vars', 0o755, true);
        file_put_contents($target . '/group_vars/all.yml', "planted: true\n");
        match ($planted) {
            'directory' => rename($target, $path),
            'file' => file_put_contents($path, "planted: true\n"),
            'link' => symlink($target, $path),
            'dangling' => symlink($this->dir . '/nowhere', $path),
        };
        $before = $this->tree();
        $vars = new RunnerSecretVars($this->dir, null, static fn (): string => 'planted');

        $this->assertNull($vars->jobDirectory());
        try {
            $vars->prepare(['secret_extra_vars' => ['db_password' => self::ANSWER]]);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame(RunnerSecretVars::WRITE_FAILED, $e->getMessage());
        }
        $vars->cleanup();

        $this->assertSame($before, $this->tree(), 'nothing was written, followed or removed');
        $this->assertFileDoesNotExist($this->dir . '/nowhere');
    }

    public function testWithoutRandomnessThereIsNoDirectory(): void
    {
        $vars = new RunnerSecretVars($this->dir, null, static function (): string {
            throw new \Random\RandomException('no source of randomness');
        });

        $this->assertNull($vars->jobDirectory());
        $this->assertSame([], $this->files());
    }

    /**
     * A missing temp directory gives no directory, and the warning of
     * mkdir() stays local.
     */
    public function testAMissingTempDirectoryGivesNoDirectory(): void
    {
        $marker = static fn (): bool => false;
        set_error_handler($marker);
        try {
            $this->assertNull((new RunnerSecretVars($this->dir . '/missing'))->jobDirectory());
            $this->assertSame($marker, set_error_handler(static fn (): bool => false));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
        $this->assertFileDoesNotExist($this->dir . '/missing');
    }

    /**
     * The playbook runs as the runner's user and can put anything into the
     * directory, links included: everything goes, and nothing a link points
     * to.
     */
    public function testCleanupRemovesEverythingInTheDirectoryWithoutFollowingLinks(): void
    {
        [$outsideDirectory, $outsideFile] = $this->outside();
        $vars = new RunnerSecretVars($this->dir . '/tmp');
        mkdir($this->dir . '/tmp');
        $directory = (string)$vars->jobDirectory();
        file_put_contents($directory . '/inventory.yml', "web-1 ansible_password=example-host-pass\n");
        mkdir($directory . '/sub/deeper', 0o700, true);
        file_put_contents($directory . '/sub/deeper/file', 'x');
        symlink($outsideDirectory, $directory . '/link-to-a-directory');
        symlink($outsideDirectory, $directory . '/sub/link-to-a-directory');
        symlink($outsideFile, $directory . '/link-to-a-file');
        symlink($directory . '/nowhere', $directory . '/dangling');

        $vars->cleanup();

        $this->assertSame([], glob($this->dir . '/tmp/*'));
        $this->assertSame('keep', file_get_contents($outsideDirectory . '/keep.txt'));
        $this->assertSame('keep', file_get_contents($outsideFile));
        $vars->cleanup();
    }

    /**
     * A playbook that replaced the directory with a link to another one:
     * the link goes, the other directory's content stays.
     */
    public function testCleanupRemovesALinkInPlaceOfTheDirectoryButNotWhatItPointsTo(): void
    {
        [$outsideDirectory] = $this->outside();
        $vars = new RunnerSecretVars($this->dir . '/tmp');
        mkdir($this->dir . '/tmp');
        $directory = (string)$vars->jobDirectory();
        rmdir($directory);
        symlink($outsideDirectory, $directory);

        $vars->cleanup();

        $this->assertFalse(is_link($directory));
        $this->assertSame('keep', file_get_contents($outsideDirectory . '/keep.txt'));
    }

    // -- The start-up sweep -----------------------------------------------------------

    /**
     * A runner killed during a job (docker stop after the grace period)
     * never cleans up, and a container keeps /tmp across restarts. At start
     * the runner removes the directories of processes that are gone, and of
     * its own process ID, which a restarted container reuses (PID 1).
     * Directories of runners that still run stay.
     */
    public function testRemoveStaleRemovesOnlyTheDirectoriesOfProcessesThatAreGone(): void
    {
        $dead = $this->deadPid();
        $stale = [
            $this->jobDirectoryNamed(self::jobName($dead, 'gone')),
            $this->jobDirectoryNamed(self::jobName((int)getmypid(), 'own process ID')),
        ];
        $kept = [
            $this->jobDirectoryNamed(self::jobName(1, 'still runs')),
            $this->jobDirectoryNamed(RunnerSecretVars::DIRECTORY_PREFIX . 'unknown'),
            $this->touch('ansilume_key_' . $dead . '_' . md5('other prefix')),
        ];

        $this->assertSame(['removed' => 2, 'skipped' => []], (new RunnerSecretVars($this->dir))->removeStale());

        foreach ($stale as $path) {
            $this->assertFileDoesNotExist($path);
        }
        foreach ($kept as $path) {
            $this->assertFileExists($path);
        }
        $this->assertSame(['removed' => 0, 'skipped' => []], (new RunnerSecretVars($this->dir))->removeStale());
    }

    /**
     * Without procfs nothing tells whose a directory is: nothing is removed.
     */
    public function testRemoveStaleRemovesNothingWithoutProcfs(): void
    {
        $path = $this->jobDirectoryNamed(self::jobName($this->deadPid(), 'gone'));

        $this->assertSame(['removed' => 0, 'skipped' => []], (new RunnerSecretVars($this->dir))->removeStale($this->dir . '/no-proc'));
        $this->assertDirectoryExists($path);
    }

    /**
     * A match that is not a directory (someone put a file there) is
     * reported and stays; the directories still go. It must never throw:
     * the sweep runs before the runner polls (review RUN-1).
     */
    public function testRemoveStaleReportsAMatchThatIsNotADirectory(): void
    {
        $dead = $this->deadPid();
        $file = $this->touch(self::jobName($dead, 'file'));
        $directory = $this->jobDirectoryNamed(self::jobName($dead, 'directory'));

        $sweep = (new RunnerSecretVars($this->dir))->removeStale();

        $this->assertSame(['removed' => 1, 'skipped' => [$file => RunnerSecretVars::SKIP_NOT_A_DIRECTORY]], $sweep);
        $this->assertFileExists($file);
        $this->assertDirectoryDoesNotExist($directory);
    }

    /**
     * A link with a matching name stays, and so does what it points to;
     * links inside a stale directory go without being followed.
     */
    public function testRemoveStaleNeverFollowsLinks(): void
    {
        $dead = $this->deadPid();
        [$outsideDirectory, $outsideFile] = $this->outside();
        $link = $this->dir . '/' . self::jobName($dead, 'link');
        $dangling = $this->dir . '/' . self::jobName($dead, 'dangling');
        symlink($outsideDirectory, $link);
        symlink($this->dir . '/missing-target', $dangling);
        $stale = $this->jobDirectoryNamed(self::jobName($dead, 'directory'));
        symlink($outsideDirectory, $stale . '/link-to-a-directory');
        symlink($outsideFile, $stale . '/link-to-a-file');
        symlink($outsideDirectory, $stale . '/sub/link-to-a-directory');
        symlink($outsideFile, $stale . '/sub/link-to-a-file');
        $skipped = [$link => RunnerSecretVars::SKIP_NOT_A_DIRECTORY, $dangling => RunnerSecretVars::SKIP_NOT_A_DIRECTORY];
        ksort($skipped, SORT_STRING);

        $sweep = (new RunnerSecretVars($this->dir))->removeStale();

        $this->assertSame(['removed' => 1, 'skipped' => $skipped], $sweep, 'scandir() lists the matches sorted by name');
        $this->assertTrue(is_link($link) && is_link($dangling), 'the links stay');
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertSame('keep', file_get_contents($outsideDirectory . '/keep.txt'));
        $this->assertSame('keep', file_get_contents($outsideFile));
    }

    /**
     * Another user's directory (a runner started as root, a second runner
     * service, a directory someone put there) is not the runner's to
     * remove; it is reported, because it can hold answers its owner can
     * read.
     */
    public function testRemoveStaleLeavesTheDirectoriesOfAnotherUser(): void
    {
        $path = $this->jobDirectoryNamed(self::jobName($this->deadPid(), 'directory'));
        $owner = (int)fileowner($path);

        $sweep = (new RunnerSecretVars($this->dir, static fn (): ?int => $owner + 1))->removeStale();

        $this->assertSame(['removed' => 0, 'skipped' => [$path => RunnerSecretVars::SKIP_OTHER_OWNER]], $sweep);
        $this->assertDirectoryExists($path);

        $this->assertSame(['removed' => 1, 'skipped' => []], (new RunnerSecretVars($this->dir, static fn (): ?int => $owner))->removeStale());
        $this->assertDirectoryDoesNotExist($path);
    }

    /**
     * Regression (review R2V2-3): without posix_geteuid() the runner cannot
     * tell its own user, and the sweep left the owner check out: it went
     * into other users' directories with such a name and removed what it
     * could there. Now it removes nothing and names every stale match with
     * the reason, whatever the match is; matches of runners that still run
     * are not named.
     */
    public function testRemoveStaleRemovesNothingWhenTheRunnersUserIsUnknown(): void
    {
        $dead = $this->deadPid();
        [$outsideDirectory] = $this->outside();
        $link = $this->dir . '/' . self::jobName($dead, 'link');
        symlink($outsideDirectory, $link);
        $stale = [
            $this->jobDirectoryNamed(self::jobName($dead, 'directory')),
            $this->jobDirectoryNamed(self::jobName((int)getmypid(), 'own process ID')),
            $this->touch(self::jobName($dead, 'file')),
            $link,
        ];
        sort($stale, SORT_STRING);
        $this->jobDirectoryNamed(self::jobName(1, 'still runs'));
        $before = $this->tree();

        $sweep = (new RunnerSecretVars($this->dir, static fn (): ?int => null))->removeStale();

        $this->assertSame(['removed' => 0, 'skipped' => array_fill_keys($stale, self::UNKNOWN_USER)], $sweep);
        $this->assertSame($before, $this->tree(), 'nothing was removed or followed');
    }

    /**
     * Regression (review R2V2-3): in a stale directory of its own user, the
     * sweep went into every directory, whoever owned it. Now it enters only
     * directories of its own user, told without following links: another
     * user's directory stays with what it holds, and so do the directories
     * above it, so the stale directory is named. Everything else goes, and
     * links go without being followed, also links to directories of its own
     * user. The sweep runs as user 4711 here (the effective-uid closure);
     * root, which may remove anything, gives that user the directories it
     * owns, so only the owner check can keep the other ones.
     */
    public function testRemoveStaleEntersOnlyDirectoriesOfTheRunnersUser(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('Needs root to chown directories to another user.');
        }
        [$outsideDirectory, $outsideFile] = $this->outside();
        $stale = $this->jobDirectoryNamed(self::jobName($this->deadPid(), 'directory'));
        mkdir($stale . '/sub/deeper');
        file_put_contents($stale . '/sub/deeper/file', 'x');
        symlink($outsideDirectory, $stale . '/sub/link-to-a-directory');
        symlink($outsideFile, $stale . '/sub/deeper/link-to-a-file');
        symlink($stale . '/nowhere', $stale . '/dangling');
        mkdir($stale . '/foreign/nested', 0o777, true);
        file_put_contents($stale . '/foreign/nested/answers.json', '{"db_password":"stale"}');
        mkdir($stale . '/sub/foreign');
        file_put_contents($stale . '/sub/foreign/inventory.yml', "web-1 ansible_password=example-host-pass\n");
        foreach ([$stale, $stale . '/sub', $stale . '/sub/deeper', $outsideDirectory] as $own) {
            $this->assertTrue(chown($own, 4711));
        }

        $sweep = (new RunnerSecretVars($this->dir, static fn (): int => 4711))->removeStale();

        $this->assertSame(['removed' => 0, 'skipped' => [$stale => RunnerSecretVars::SKIP_NOT_REMOVED]], $sweep);
        $left = [
            $stale => 'directory',
            $stale . '/foreign' => 'directory',
            $stale . '/foreign/nested' => 'directory',
            $stale . '/foreign/nested/answers.json' => 'file: {"db_password":"stale"}',
            $stale . '/sub' => 'directory',
            $stale . '/sub/foreign' => 'directory',
            $stale . '/sub/foreign/inventory.yml' => "file: web-1 ansible_password=example-host-pass\n",
            $outsideDirectory => 'directory',
            $outsideDirectory . '/keep.txt' => 'file: keep',
            $outsideFile => 'file: keep',
        ];
        ksort($left);
        $this->assertSame($left, $this->tree());
    }

    /**
     * The default owner check uses the runner's effective user ID: as root,
     * which may remove anything, a directory chowned to another user stays.
     */
    public function testByDefaultADirectoryOfAnotherUserStaysEvenForRoot(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('Needs root to chown a directory to another user.');
        }
        $path = $this->jobDirectoryNamed(self::jobName($this->deadPid(), 'directory'));
        $this->assertTrue(chown($path, 4711));

        $sweep = (new RunnerSecretVars($this->dir))->removeStale();

        $this->assertSame(['removed' => 0, 'skipped' => [$path => RunnerSecretVars::SKIP_OTHER_OWNER]], $sweep);
        $this->assertDirectoryExists($path);
    }

    /**
     * A directory it cannot empty (here: a tree deeper than the longest path
     * PHP takes, which a playbook could create) is reported and nothing
     * throws.
     */
    public function testADirectoryThatCannotBeRemovedIsReported(): void
    {
        $path = $this->jobDirectoryNamed(self::jobName($this->deadPid(), 'deep'));
        // mkdir -p and rm -rf walk the tree relative to each directory, which
        // PHP's path functions cannot.
        $this->assertSame(0, $this->runIn($path, ['mkdir', '-p', implode('/', array_fill(0, 18, str_repeat('d', 250)))]));
        try {
            $sweep = (new RunnerSecretVars($this->dir))->removeStale();

            $this->assertSame(['removed' => 0, 'skipped' => [$path => RunnerSecretVars::SKIP_NOT_REMOVED]], $sweep);
            $this->assertDirectoryExists($path);
        } finally {
            $this->assertSame(0, $this->runIn($this->dir, ['rm', '-rf', $path]));
        }
    }

    /**
     * A match that is gone by the time it is checked (another runner's sweep
     * was faster) is neither counted nor reported.
     */
    public function testAMatchThatIsGoneIsNeitherCountedNorReported(): void
    {
        $path = $this->jobDirectoryNamed(self::jobName($this->deadPid(), 'directory'));
        $checked = 0;
        $vanish = static function () use ($path, &$checked): ?int {
            $checked++;
            FileHelper::removeDirectory($path);

            return 0;
        };

        $this->assertSame(['removed' => 0, 'skipped' => []], (new RunnerSecretVars($this->dir, $vanish))->removeStale());
        $this->assertSame(1, $checked, 'the sweep took the directory for a match');
    }

    /**
     * Warnings during the sweep stay local and the caller's error handler
     * is back afterwards.
     */
    public function testTheSweepRestoresTheErrorHandler(): void
    {
        $marker = static fn (): bool => false;
        set_error_handler($marker);
        try {
            $file = $this->touch(self::jobName($this->deadPid(), 'file'));
            $sweep = (new RunnerSecretVars($this->dir))->removeStale();

            $this->assertSame([$file], array_keys($sweep['skipped']), 'the sweep took the file for a match');
            $this->assertSame($marker, set_error_handler(static fn (): bool => false));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The sweep takes the directories the runner makes: one that
     * jobDirectory() named with its default random part, left behind by a
     * process with this process ID, goes.
     */
    public function testRemoveStaleTakesTheDirectoriesTheRunnerMakes(): void
    {
        $left = (string)(new RunnerSecretVars($this->dir))->jobDirectory();
        file_put_contents($left . '/' . RunnerSecretVars::ANSWERS_FILE, '{"db_password":"stale"}');
        $owner = (int)fileowner($left);

        $this->assertSame(['removed' => 1, 'skipped' => []], (new RunnerSecretVars($this->dir, static fn (): int => $owner))->removeStale());
        $this->assertDirectoryDoesNotExist($left);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function namesTheRunnerNeverCreatesProvider(): array
    {
        $cases = [];
        foreach (['a directory', 'a file', 'a link to a directory', 'a dangling link'] as $kind) {
            foreach (['its own user', 'another user', 'an unknown user'] as $runner) {
                $cases["{$kind}, the runner running as {$runner}"] = [$kind, $runner];
            }
        }

        return $cases;
    }

    /**
     * Regression (review R3V1-1): the sweep took every name that starts with
     * ansilume_job_<digits>_ for a job directory, whatever followed, and the
     * runner printed each one it left in place as it was. Another user of a
     * shared temp directory could put a line break or terminal sequences
     * into such a name and so add forged lines to the runner's log at every
     * start. The runner names its directories
     * ansilume_job_<pid>_<32 lowercase hex digits> and nothing else, and the
     * sweep now takes only that form: every other name is neither removed
     * nor reported, whatever is at it and whoever the runner is. Names of
     * that form beside them still go, or are reported, as before.
     *
     * @dataProvider namesTheRunnerNeverCreatesProvider
     */
    public function testRemoveStaleNeitherRemovesNorReportsNamesTheRunnerNeverCreates(string $kind, string $runner): void
    {
        $dead = $this->deadPid();
        [$outsideDirectory] = $this->outside();
        foreach (self::suffixesTheRunnerNeverCreates() as $suffix) {
            $name = RunnerSecretVars::DIRECTORY_PREFIX . $dead . '_' . $suffix;
            match ($kind) {
                'a directory' => $this->jobDirectoryNamed($name),
                'a file' => $this->touch($name),
                'a link to a directory' => symlink($outsideDirectory, $this->dir . '/' . $name),
                'a dangling link' => symlink($this->dir . '/missing-target', $this->dir . '/' . $name),
                default => $this->fail("No such kind: {$kind}"),
            };
        }
        $directory = $this->jobDirectoryNamed(self::jobName($dead, 'directory'));
        $file = $this->touch(self::jobName($dead, 'file'));
        $owner = (int)fileowner($this->dir);
        [$effectiveUid, $skipped] = match ($runner) {
            'its own user' => [$owner, [$file => RunnerSecretVars::SKIP_NOT_A_DIRECTORY]],
            'another user' => [$owner + 1, [$directory => RunnerSecretVars::SKIP_OTHER_OWNER, $file => RunnerSecretVars::SKIP_NOT_A_DIRECTORY]],
            'an unknown user' => [null, [$directory => self::UNKNOWN_USER, $file => self::UNKNOWN_USER]],
            default => $this->fail("No such runner: {$runner}"),
        };
        ksort($skipped, SORT_STRING);
        $left = $this->tree();
        if ($runner === 'its own user') {
            $left = array_filter(
                $left,
                static fn (string $path): bool => $path !== $directory && !str_starts_with($path, $directory . '/'),
                ARRAY_FILTER_USE_KEY
            );
        }

        $sweep = (new RunnerSecretVars($this->dir, static fn (): ?int => $effectiveUid))->removeStale();

        $this->assertSame(
            ['removed' => $runner === 'its own user' ? 1 : 0, 'skipped' => $skipped],
            $sweep,
            "only the names of the runner's own format are removed or reported"
        );
        $this->assertSame($left, $this->tree(), 'every other name stays as it was');
    }

    // -- Files of older runners -----------------------------------------------------------

    /**
     * Regression (review R2V2-5): runners of 2.8.1 or older wrote the static
     * inventory (ansilume_inv_<uniqid>.yml, readable by every local user
     * under the usual umask) and the task records
     * (ansilume_tasks_<job id>_<uniqid>.ndjson) straight into the temp
     * directory, and left them there when a credential could not be set up
     * or the runner was killed; the sweep knew only job directories. Now
     * such files are named, links included, whoever owns them, and none is
     * removed or followed: a runner of 2.8.1 or older on the same host may
     * still be using one. Other names, and directories, are not named.
     */
    public function testTheFilesOfOlderRunnersAreNamedAndNoneIsRemoved(): void
    {
        [, $outsideFile] = $this->outside();
        $inventory = $this->touch('ansilume_inv_' . uniqid('', true) . '.yml');
        $tasks = $this->touch('ansilume_tasks_42_' . uniqid('', true) . '.ndjson');
        $link = $this->dir . '/ansilume_inv_6acbf370bedbd6.68500798.yml';
        symlink($outsideFile, $link);
        $dangling = $this->dir . '/ansilume_tasks_7_6acbf370bedcb9.69626376.ndjson';
        symlink($this->dir . '/missing-target', $dangling);
        $expected = [$inventory, $tasks, $link, $dangling];
        sort($expected, SORT_STRING);
        foreach ($this->namesOfOtherFiles() as $name) {
            $this->touch($name);
        }
        mkdir($this->dir . '/ansilume_inv_' . uniqid('', true) . '.yml');
        $this->jobDirectoryNamed(self::jobName($this->deadPid(), 'directory'));
        $before = $this->tree();

        $this->assertSame($expected, (new RunnerSecretVars($this->dir))->filesOfOlderRunners());
        $this->assertSame($before, $this->tree(), 'nothing was removed or followed');
    }

    public function testNoFilesOfOlderRunnersInAnEmptyOrMissingDirectory(): void
    {
        $this->assertSame([], (new RunnerSecretVars($this->dir))->filesOfOlderRunners());

        $marker = static fn (): bool => false;
        set_error_handler($marker);
        try {
            $this->assertSame([], (new RunnerSecretVars($this->dir . '/missing'))->filesOfOlderRunners());
            $this->assertSame($marker, set_error_handler(static fn (): bool => false), 'the warning of scandir() stays local');
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    // -- Private files ------------------------------------------------------------------

    /**
     * The task records and the static inventory are written through it:
     * whatever the process umask, the file is 0600 and holds the content.
     */
    public function testWritePrivateFileCreatesAFileOnlyItsOwnerCanRead(): void
    {
        $umask = umask(0);
        try {
            $path = $this->dir . '/inventory.yml';

            $this->assertTrue(RunnerSecretVars::writePrivateFile($path, "host ansible_password=example-host-pass\n"));
            $this->assertSame(0, umask(), 'the umask is restored');

            $this->assertSame(0o600, fileperms($path) & 0o777);
            $this->assertSame("host ansible_password=example-host-pass\n", file_get_contents($path));
            $this->assertTrue(RunnerSecretVars::writePrivateFile($this->dir . '/empty', ''));
            $this->assertSame('', file_get_contents($this->dir . '/empty'));
        } finally {
            umask($umask);
        }
    }

    /**
     * It never writes through a file or link that is already there, and
     * never throws.
     */
    public function testWritePrivateFileRefusesAPathThatExists(): void
    {
        $existing = $this->touch('existing');
        $target = $this->touch('target');
        $link = $this->dir . '/link';
        symlink($target, $link);

        $this->assertFalse(RunnerSecretVars::writePrivateFile($existing, 'secret'));
        $this->assertFalse(RunnerSecretVars::writePrivateFile($link, 'secret'));
        $this->assertFalse(RunnerSecretVars::writePrivateFile($this->dir . '/missing/file', 'secret'));

        $this->assertSame('{"db_password":"stale"}', file_get_contents($existing));
        $this->assertSame('{"db_password":"stale"}', file_get_contents($target));
        $this->assertFileDoesNotExist($this->dir . '/missing/file');
    }

    /**
     * A write that stops part way (here at the file size limit) leaves no
     * partial file behind. The limit is lowered for this process only for
     * the one call, with SIGXFSZ ignored so the write fails instead of the
     * process ending.
     */
    public function testWritePrivateFileRemovesAPartlyWrittenFile(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_setrlimit')) {
            $this->markTestSkipped('Needs the pcntl and posix extensions (the app image has both).');
        }
        $path = $this->dir . '/partial';
        pcntl_signal(SIGXFSZ, SIG_IGN);
        $this->assertTrue(posix_setrlimit(POSIX_RLIMIT_FSIZE, 4, POSIX_RLIMIT_INFINITY));
        try {
            $written = RunnerSecretVars::writePrivateFile($path, str_repeat('x', 64));
        } finally {
            $restored = posix_setrlimit(POSIX_RLIMIT_FSIZE, POSIX_RLIMIT_INFINITY, POSIX_RLIMIT_INFINITY);
            pcntl_signal(SIGXFSZ, SIG_DFL);
        }

        $this->assertTrue($restored, 'the file size limit is back');
        $this->assertFalse($written);
        $this->assertFileDoesNotExist($path);
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function files(): array
    {
        return glob($this->dir . '/*') ?: [];
    }

    /**
     * Every path below the test directory, links unresolved, with what it
     * is and holds.
     *
     * @return array<string, string>
     */
    private function tree(): array
    {
        $tree = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($entries as $path => $entry) {
            assert($entry instanceof \SplFileInfo);
            $tree[(string)$path] = match (true) {
                $entry->isLink() => 'link to ' . $entry->getLinkTarget(),
                $entry->isDir() => 'directory',
                default => 'file: ' . file_get_contents((string)$path),
            };
        }
        ksort($tree);

        return $tree;
    }

    /**
     * A regex for a job directory in the test directory, with $suffix after
     * it and $prefix before it.
     */
    private function jobDirectoryPattern(string $suffix = '', string $prefix = ''): string
    {
        return '#^' . preg_quote($prefix . $this->dir . '/' . RunnerSecretVars::DIRECTORY_PREFIX . getmypid() . '_', '#')
            . '[0-9a-f]{32}' . preg_quote($suffix, '#') . '$#';
    }

    /**
     * The name the runner gives a job directory of process $pid: the
     * prefix, the process ID and 32 lowercase hex digits, here derived from
     * $label so that the names in a test differ and stay the same between
     * runs.
     */
    private static function jobName(int $pid, string $label): string
    {
        return RunnerSecretVars::DIRECTORY_PREFIX . $pid . '_' . md5($label);
    }

    /**
     * A directory named $name in the test directory as a killed runner
     * leaves its job directory: with an answers file and an inventory in a
     * subdirectory.
     */
    private function jobDirectoryNamed(string $name): string
    {
        $path = $this->dir . '/' . $name;
        mkdir($path . '/sub', 0o700, true);
        file_put_contents($path . '/' . RunnerSecretVars::ANSWERS_FILE, '{"db_password":"stale"}');
        file_put_contents($path . '/sub/inventory.yml', "web-1 ansible_password=example-host-pass\n");

        return $path;
    }

    /**
     * A directory with a file and a file, outside every job directory.
     *
     * @return array{0: string, 1: string}
     */
    private function outside(): array
    {
        $directory = $this->dir . '/outside';
        mkdir($directory);
        file_put_contents($directory . '/keep.txt', 'keep');
        file_put_contents($this->dir . '/outside-file', 'keep');

        return [$directory, $this->dir . '/outside-file'];
    }

    /**
     * The job directories of this process in the system temp directory.
     *
     * @return list<string>
     */
    private function jobDirectoriesInTheSystemTempDirectory(): array
    {
        return glob(sys_get_temp_dir() . '/' . RunnerSecretVars::DIRECTORY_PREFIX . getmypid() . '_*') ?: [];
    }

    private function touch(string $name): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, '{"db_password":"stale"}');

        return $path;
    }

    /**
     * What follows "ansilume_job_<pid>_" in names that start like the name
     * of a job directory but that the runner never gives one: anything but
     * exactly 32 lowercase hex digits. Each one matched the sweep's pattern
     * before review R3V1-1.
     *
     * @return array<string, string> by what it is
     */
    private static function suffixesTheRunnerNeverCreates(): array
    {
        $hex = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';

        return [
            'nothing' => '',
            'a short random part' => 'x',
            '31 hex digits' => substr($hex, 0, 31),
            '33 hex digits' => $hex . 'a',
            '32 uppercase hex digits' => strtoupper($hex),
            'one uppercase hex digit' => substr($hex, 0, 31) . 'F',
            'a letter that is no hex digit' => substr($hex, 0, 31) . 'g',
            '32 hex digits and a line break' => $hex . "\n",
            'a line break and 32 hex digits' => "\n" . $hex,
            '32 hex digits and a space' => $hex . ' ',
            '32 hex digits and more' => $hex . '_more',
            'a line break and an escape sequence' => "x\n\033[2JRemoved the private directories of 99 job(s) that an earlier runner process left behind.",
        ];
    }

    /**
     * Names that only look like the files of a runner of 2.8.1 or older.
     *
     * @return list<string>
     */
    private function namesOfOtherFiles(): array
    {
        $unique = uniqid('', true);

        return [
            // The server's own static inventory while ansible-inventory parses it: tempnam()'s six characters.
            'ansilume_inv_AbC123.yml',
            'ansilume_inv_' . $unique . '.ndjson',
            'ansilume_inv_' . $unique . '.yml.bak',
            'ansilume_tasks_' . $unique . '.ndjson',
            'ansilume_tasks_42_' . $unique . '.yml',
            'ansilume_tasks_x_' . $unique . '.ndjson',
            'ansilume_tasks_42_' . $unique . ".ndjson\n",
            'old-ansilume_inv_' . $unique . '.yml',
            'ansilume_key_' . $unique,
        ];
    }

    /**
     * Runs $command in $directory, without a shell.
     *
     * @param list<string> $command
     * @return int the exit status
     */
    private function runIn(string $directory, array $command): int
    {
        $process = proc_open($command, [], $pipes, $directory);
        $this->assertIsResource($process);

        return proc_close($process);
    }

    /**
     * The ID of a process that just ended.
     */
    private function deadPid(): int
    {
        $process = proc_open(['true'], [], $pipes);
        $this->assertIsResource($process);
        $pid = (int)proc_get_status($process)['pid'];
        proc_close($process);
        $this->assertDirectoryDoesNotExist('/proc/' . $pid);

        return $pid;
    }
}
