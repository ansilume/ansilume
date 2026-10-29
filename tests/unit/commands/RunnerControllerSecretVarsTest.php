<?php

declare(strict_types=1);

namespace app\tests\unit\commands;

use app\commands\RunnerController;
use app\components\OutputMasker;
use app\components\RunnerHttpClient;
use app\components\RunnerSecretVars;
use app\components\RunnerTokenResolver;
use PHPUnit\Framework\TestCase;
use yii\console\ExitCode;
use yii\helpers\FileHelper;

/**
 * RunnerController::executeJob with the job's private directory: it runs a
 * real process (sh instead of ansible-playbook) and records what the runner
 * posts back. Every job gets a new directory that only the runner's user can
 * open, and its own files go there: the task records, a static inventory
 * and the answers of payload['secret_extra_vars'] for `--extra-vars @file`.
 * The tests check the directory and file modes, the place of the file
 * argument, the masking of logs and task records, the removal on every path
 * and the fail-closed paths end to end, the start-up sweep of the
 * directories killed runner processes left behind, the start-up report of
 * the files runners of 2.8.1 or older left in the temp directory, and that
 * each path the start-up check prints is one line of the log.
 */
class RunnerControllerSecretVarsTest extends TestCase
{
    private const ANSWER = 'example-db-answer-4711';

    /**
     * Prints the arguments the runner appended, the modes of the file and of
     * its directory, the file's hash and content, and writes a task record
     * whose name and host carry the answer, as a templated task name would.
     */
    private const PROBE = <<<'SH'
        f="${1#@}"
        printf 'args=%s %s;mode=%s;dir=%s;sha=%s;content=%s\n' "$0" "$1" "$(stat -c %a "$f")" "$(stat -c %a "${f%/*}")" "$(sha256sum "$f" | cut -d' ' -f1)" "$(cat "$f")"
        v=$(sed -e 's/.*"db_password":"\([^"]*\)".*/\1/' "$f")
        printf '{"seq":0,"name":"Use %s","host":"%s","action":"debug","status":"ok","changed":false}\n' "$v" "$v" > "$ANSILUME_CALLBACK_FILE"
        SH;

    /**
     * Run with the static inventory as $0 and the answers file argument as
     * $1 and $2: prints the inventory's directory and every job file with
     * its mode, what the directory holds, then writes a task record the way
     * the callback plugin's open(path, 'w') does and prints its mode again.
     */
    private const FILES_PROBE = <<<'SH'
        d="${0%/*}"
        printf 'dir=%s %s\n' "$(stat -c %a "$d")" "$d"
        for f in "$0" "$ANSILUME_CALLBACK_FILE" "${2#@}"; do printf 'file=%s %s\n' "$(stat -c %a "$f")" "$f"; done
        printf 'holds=%s\n' "$(ls -A "$d" | tr '\n' ' ')"
        printf 'inventory=%s\n' "$(sha256sum "$0" | cut -d' ' -f1)"
        printf '{"seq":0,"name":"Ping","host":"localhost","action":"ping","status":"ok","changed":false}\n' > "$ANSILUME_CALLBACK_FILE"
        printf 'tasks-after-write=%s\n' "$(stat -c %a "$ANSILUME_CALLBACK_FILE")"
        SH;

    /** Name prefix of a job's directory, spelled out so the regressions fail on the code before it. */
    private const JOB_DIRECTORY = 'ansilume_job_';

    private string $dir;

    /** The runner's temp directory in these tests. */
    private string $temp;

    /** @var list<array{path: string, body: array<string, mixed>}> */
    private array $posts = [];

    /** @var list<array<string, mixed>> bodies of posts that were made to fail */
    private array $failedPosts = [];

    /** @var list<string> */
    private array $printed = [];

    /** @var list<string> what the runner wrote to stderr */
    private array $errors = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/runner_secret_job_' . uniqid('', true);
        $this->temp = $this->dir . '/tmp';
        mkdir($this->temp, 0o700, true);
    }

    protected function tearDown(): void
    {
        // Yii's helper removes links instead of following them.
        FileHelper::removeDirectory($this->dir);
        unset($_ENV['API_URL']);
    }

    public function testTheAnswersReachTheCommandAsAPrivateFileInTheJobDirectory(): void
    {
        $answers = ['db_password' => self::ANSWER];

        $this->executeJob($this->controller(), $this->payload(['secret_extra_vars' => $answers]));

        $pattern = '#^args=--extra-vars @(' . $this->jobDirectoryPattern() . ')/secret_vars\.json;mode=600;dir=700;sha=(\w+);#';
        $this->assertSame(1, preg_match($pattern, $this->stdout(), $match), $this->stdout());
        $this->assertSame(hash('sha256', '{"db_password":"' . self::ANSWER . '"}'), $match[2], 'the file holds the answers as JSON');
        $this->assertDirectoryDoesNotExist($match[1], 'the directory is removed after the run');
        $this->assertSame([], $this->leftInTheTempDirectory());
        $this->assertSame(['exit_code' => 0, 'has_changes' => false, 'timed_out' => false], $this->completion());
    }

    /**
     * The playbook prints what it read, and the task name is templated with
     * it: the runner posts neither.
     */
    public function testTheAnswerIsMaskedInTheLogAndInTheTaskRecords(): void
    {
        $this->executeJob($this->controller(), $this->payload(['secret_extra_vars' => ['db_password' => self::ANSWER]]));

        $this->assertStringContainsString('content={"db_password":"' . OutputMasker::MASK . '"}', $this->stdout());
        $tasks = $this->postsTo('/api/runner/v1/jobs/7/tasks');
        $this->assertCount(1, $tasks);
        $this->assertSame('Use ' . OutputMasker::MASK, $tasks[0]['tasks'][0]['name']);
        $this->assertSame(OutputMasker::MASK, $tasks[0]['tasks'][0]['host']);
        $this->assertSame('debug', $tasks[0]['tasks'][0]['action']);
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode($this->posts));
    }

    /**
     * The file argument goes after everything else, also after the env(1)
     * prefix of the vault isolation, which must keep working.
     */
    public function testTheFileArgumentFollowsTheVaultIsolationPrefix(): void
    {
        $this->executeJob(
            $this->controller(),
            $this->payload(['secret_extra_vars' => ['db_password' => self::ANSWER], 'vault_password_source' => 'ansilume'])
        );

        $this->assertStringStartsWith('args=--extra-vars @' . $this->temp . '/' . RunnerSecretVars::DIRECTORY_PREFIX, $this->stdout());
        $this->assertSame([], $this->leftInTheTempDirectory());
        $this->assertSame(0, $this->completion()['exit_code']);
    }

    /**
     * An older server sends no answers field: the command runs as sent,
     * without arguments appended.
     */
    public function testWithoutTheFieldTheCommandRunsAsSent(): void
    {
        $payload = $this->payload([]);
        $payload['command'] = ['sh', '-c', 'printf "argc=%s;zero=%s;answer=%s" "$#" "$0" "' . self::ANSWER . '"'];

        $this->executeJob($this->controller(), $payload);

        $this->assertSame('argc=0;zero=sh;answer=' . self::ANSWER, $this->stdout(), 'nothing appended, nothing masked');
        $this->assertSame([], $this->leftInTheTempDirectory());
    }

    /**
     * Fail closed: when the answers file cannot be written the job fails
     * with the reason and the command never runs, neither without the
     * answers nor with them inline. The job's directory is gone.
     */
    public function testAnswersThatCannotBeWrittenFailTheJobWithoutRunningIt(): void
    {
        $this->executeJob($this->controller(), $this->payload(['secret_extra_vars' => ['db_password' => INF]]));

        $this->assertSame('', $this->stdout(), 'the command must not run');
        $this->assertSame(
            [['stream' => 'stderr', 'content' => RunnerSecretVars::WRITE_FAILED, 'sequence' => 0]],
            $this->postsTo('/api/runner/v1/jobs/7/logs')
        );
        $this->assertSame(['exit_code' => 1, 'has_changes' => false], $this->completion());
        $this->assertSame([], $this->postsTo('/api/runner/v1/jobs/7/tasks'));
        $this->assertSame([], $this->leftInTheTempDirectory());
    }

    /**
     * The directory is removed even when posting the output fails mid-run.
     */
    public function testTheJobDirectoryIsRemovedWhenTheRunThrows(): void
    {
        $payload = $this->payload(['secret_extra_vars' => ['db_password' => self::ANSWER], 'inventory_type' => 'static']);
        $payload['command'] = ['sh', '-c', 'test -f "${2#@}" && test -f "$0" && test -f "$ANSILUME_CALLBACK_FILE" && printf "files present"', '__INVENTORY_TMP__'];

        try {
            $this->executeJob($this->controller('/api/runner/v1/jobs/7/logs'), $payload);
            $this->fail('expected the log post to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('log post failed', $e->getMessage());
        }

        $this->assertSame([['stream' => 'stdout', 'content' => 'files present', 'sequence' => 0]], $this->failedPosts);
        $this->assertSame([], $this->leftInTheTempDirectory());
    }

    /**
     * The callback plugin's task records hold templated task names; they
     * are removed even when the run throws before they were posted.
     */
    public function testTheTaskRecordsFileIsRemovedWhenTheRunThrows(): void
    {
        $payload = $this->payload(['secret_extra_vars' => ['db_password' => self::ANSWER]]);
        $payload['command'] = ['sh', '-c', 'printf "{\"name\":\"x\"}\n" > "$ANSILUME_CALLBACK_FILE"; printf "cb=%s" "$ANSILUME_CALLBACK_FILE"'];

        try {
            $this->executeJob($this->controller('/api/runner/v1/jobs/7/logs'), $payload);
            $this->fail('expected the log post to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('log post failed', $e->getMessage());
        }

        $this->assertCount(1, $this->failedPosts);
        $this->assertSame(1, preg_match('#^cb=(' . $this->jobDirectoryPattern() . '/tasks\.ndjson)$#', (string)$this->failedPosts[0]['content'], $match));
        $this->assertFileDoesNotExist($match[1]);
    }

    /**
     * The directory exists before the credentials are injected: when the
     * injection throws, the directory with the static inventory and the
     * answers file goes all the same, and nothing ran.
     */
    public function testTheJobDirectoryIsRemovedWhenCredentialInjectionThrows(): void
    {
        $payload = $this->payload([
            'secret_extra_vars' => ['db_password' => self::ANSWER],
            'inventory_type' => 'static',
            'inventory_content' => "web-1 ansible_password=example-host-pass\n",
        ]);
        $payload['credentials'] = [[
            'credential_type' => 'ssh_key',
            'username' => null,
            'secrets' => ['private_key' => ['not', 'a', 'key']],
        ]];

        try {
            $this->executeJob($this->controller(), $payload);
            $this->fail('expected a TypeError from the credential injection');
        } catch (\TypeError) {
            $this->assertSame([], $this->posts, 'nothing ran');
        }

        $this->assertSame([], $this->leftInTheTempDirectory());
    }

    /**
     * A playbook that fails costs nothing more: the directory goes.
     */
    public function testTheJobDirectoryIsRemovedWhenTheCommandFails(): void
    {
        $payload = $this->payload(['inventory_type' => 'static']);
        $payload['command'] = ['sh', '-c', 'test -f "$0" && printf "failing"; exit 3', '__INVENTORY_TMP__'];

        $this->executeJob($this->controller(), $payload);

        $this->assertSame('failing', $this->stdout());
        $this->assertSame(['exit_code' => 3, 'has_changes' => false, 'timed_out' => false], $this->completion());
        $this->assertSame([], $this->leftInTheTempDirectory());
    }

    /**
     * Regression (review V2-INV): the runner wrote the static inventory into
     * the shared temp directory, where Ansible loads group_vars/ and
     * host_vars/ next to it, whoever put them there. Now the inventory, the
     * task records and the answers file are in a new directory only the
     * runner's user can open, 0600 each whatever the umask (review
     * SEC-EXP-3), and the plugin's open(path, 'w') keeps that mode.
     */
    public function testTheJobFilesAreInADirectoryOnlyTheRunnersUserCanOpen(): void
    {
        $inventory = "web-1 ansible_password=example-host-pass\n";
        $payload = $this->payload([
            'inventory_type' => 'static',
            'inventory_content' => $inventory,
            'secret_extra_vars' => ['db_password' => self::ANSWER],
        ]);
        $payload['command'] = ['sh', '-c', self::FILES_PROBE, '__INVENTORY_TMP__'];

        $umask = umask(0o022);
        try {
            $this->executeJob($this->controller(), $payload);
        } finally {
            umask($umask);
        }

        $this->assertSame(1, preg_match('#^dir=700 (' . $this->jobDirectoryPattern() . ')\n#', $this->stdout(), $match), $this->stdout());
        $directory = $match[1];
        $this->assertSame(
            "dir=700 {$directory}\n"
            . "file=600 {$directory}/inventory.yml\n"
            . "file=600 {$directory}/tasks.ndjson\n"
            . "file=600 {$directory}/secret_vars.json\n"
            . "holds=inventory.yml secret_vars.json tasks.ndjson \n"
            . 'inventory=' . hash('sha256', $inventory) . "\n"
            . "tasks-after-write=600\n",
            $this->stdout()
        );
        $this->assertDirectoryDoesNotExist($directory, 'the directory is removed after the job');
        $this->assertSame([], $this->leftInTheTempDirectory());
        $tasks = $this->postsTo('/api/runner/v1/jobs/7/tasks');
        $this->assertCount(1, $tasks, 'the task record still reaches the server');
        $this->assertSame('Ping', $tasks[0]['tasks'][0]['name']);
        $this->assertSame(0, $this->completion()['exit_code']);
    }

    /**
     * Fail closed: when the job's directory cannot be created (the temp
     * directory is missing, read-only or full) the job fails with the
     * reason and the command never runs.
     */
    public function testAJobWhoseDirectoryCannotBeCreatedFailsWithoutRunning(): void
    {
        $controller = $this->controller();
        $controller->temp = $this->dir . '/missing';

        $this->executeJob($controller, $this->payload([
            'inventory_type' => 'static',
            'inventory_content' => "localhost\n",
            'secret_extra_vars' => ['db_password' => self::ANSWER],
        ]));

        $this->assertSame('', $this->stdout(), 'the command must not run');
        $this->assertSame(
            [['stream' => 'stderr', 'content' => RunnerController::JOB_FILES_FAILED, 'sequence' => 0]],
            $this->postsTo('/api/runner/v1/jobs/7/logs')
        );
        $this->assertSame(['exit_code' => 1, 'has_changes' => false], $this->completion());
        $this->assertSame(['Job #7 failed: ' . RunnerController::JOB_FILES_FAILED . "\n"], $this->errors);
        $this->assertFileDoesNotExist($this->dir . '/missing');
    }

    /**
     * Fail closed: when the inventory cannot be written into the job's
     * directory (here at the file size limit, as on a full disk) the job
     * fails with the reason, the command never runs, and the directory with
     * the empty task records file goes. The limit is lowered for this
     * process only for the one call, with SIGXFSZ ignored so the write fails
     * instead of the process ending.
     */
    public function testAJobWhoseFilesCannotBeWrittenFailsWithoutRunning(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_setrlimit')) {
            $this->markTestSkipped('Needs the pcntl and posix extensions (the app image has both).');
        }
        $payload = $this->payload(['inventory_type' => 'static', 'inventory_content' => "web-1 ansible_password=example-host-pass\n"]);
        pcntl_signal(SIGXFSZ, SIG_IGN);
        $this->assertTrue(posix_setrlimit(POSIX_RLIMIT_FSIZE, 4, POSIX_RLIMIT_INFINITY));
        try {
            $this->executeJob($this->controller(), $payload);
        } finally {
            $restored = posix_setrlimit(POSIX_RLIMIT_FSIZE, POSIX_RLIMIT_INFINITY, POSIX_RLIMIT_INFINITY);
            pcntl_signal(SIGXFSZ, SIG_DFL);
        }

        $this->assertTrue($restored, 'the file size limit is back');
        $this->assertSame('', $this->stdout(), 'the command must not run');
        $this->assertSame(
            [['stream' => 'stderr', 'content' => RunnerController::JOB_FILES_FAILED, 'sequence' => 0]],
            $this->postsTo('/api/runner/v1/jobs/7/logs')
        );
        $this->assertSame(['exit_code' => 1, 'has_changes' => false], $this->completion());
        $this->assertSame([], $this->leftInTheTempDirectory());
    }

    /**
     * Whatever is at the directory's path already (in a shared temp
     * directory anyone can put a directory with group_vars/ there) is
     * neither used nor removed: the job fails without running.
     */
    public function testAJobWhoseDirectoryPathExistsFailsWithoutRunning(): void
    {
        $planted = $this->temp . '/' . RunnerSecretVars::DIRECTORY_PREFIX . getmypid() . '_planted';
        mkdir($planted . '/group_vars', 0o755, true);
        file_put_contents($planted . '/group_vars/all.yml', "ansible_host: elsewhere.example\n");
        $controller = $this->controller();
        $controller->randomPart = 'planted';

        $this->executeJob($controller, $this->payload(['inventory_type' => 'static', 'secret_extra_vars' => ['db_password' => self::ANSWER]]));

        $this->assertSame('', $this->stdout(), 'the command must not run');
        $this->assertSame(
            [['stream' => 'stderr', 'content' => RunnerController::JOB_FILES_FAILED, 'sequence' => 0]],
            $this->postsTo('/api/runner/v1/jobs/7/logs')
        );
        $this->assertSame(['exit_code' => 1, 'has_changes' => false], $this->completion());
        $this->assertSame([$planted . '/group_vars'], glob($planted . '/*'), 'nothing was written into it');
        $this->assertSame("ansible_host: elsewhere.example\n", file_get_contents($planted . '/group_vars/all.yml'));
    }

    /**
     * A playbook that removes the task records file costs only the records:
     * the job still completes.
     */
    public function testARunThatRemovesTheTaskRecordsFileStillCompletes(): void
    {
        $payload = $this->payload([]);
        $payload['command'] = ['sh', '-c', 'rm "$ANSILUME_CALLBACK_FILE" && printf removed'];

        $this->executeJob($this->controller(), $payload);

        $this->assertSame('removed', $this->stdout());
        $this->assertSame([], $this->postsTo('/api/runner/v1/jobs/7/tasks'));
        $this->assertSame(0, $this->completion()['exit_code']);
        $this->assertSame([], $this->leftInTheTempDirectory());
    }

    // -- The start-up sweep -----------------------------------------------------------

    /**
     * At start the runner removes the job directories a killed runner
     * process left behind, and says so.
     */
    public function testTheRunnerRemovesStaleJobDirectoriesWhenItStarts(): void
    {
        $_ENV['API_URL'] = 'http://test-server';
        $stale = $this->staleJobDirectory(self::jobName((int)getmypid(), 'directory'));
        $controller = $this->controller();
        $resolver = $this->createMock(RunnerTokenResolver::class);
        $resolver->method('resolve')->willReturn('runner-token');
        $controller->setTokenResolver($resolver);

        $this->assertSame(ExitCode::OK, $controller->actionStart());

        $this->assertDirectoryDoesNotExist($stale);
        $this->assertContains("Removed the private directories of 1 job(s) that an earlier runner process left behind.\n", $this->printed);
    }

    /**
     * A runner that cannot reach the server, and restarts over and over,
     * still removes them.
     */
    public function testStaleJobDirectoriesAreRemovedEvenWhenTheRunnerCannotStart(): void
    {
        $_ENV['API_URL'] = 'http://test-server';
        $stale = $this->staleJobDirectory(self::jobName((int)getmypid(), 'directory'));
        $controller = $this->controller();
        $resolver = $this->createMock(RunnerTokenResolver::class);
        $resolver->method('resolve')->willReturn('');
        $controller->setTokenResolver($resolver);

        $this->assertSame(ExitCode::CONFIG, $controller->actionStart());

        $this->assertDirectoryDoesNotExist($stale);
        $this->assertSame([], $this->posts);
    }

    /**
     * Nothing to remove, nothing to say.
     */
    public function testAStartWithoutStaleDirectoriesSaysNothingAboutThem(): void
    {
        $_ENV['API_URL'] = 'http://test-server';
        $controller = $this->controller();
        $resolver = $this->createMock(RunnerTokenResolver::class);
        $resolver->method('resolve')->willReturn('runner-token');
        $controller->setTokenResolver($resolver);

        $this->assertSame(ExitCode::OK, $controller->actionStart());

        $this->assertSame([], preg_grep('/earlier runner process/', $this->printed));
        $this->assertSame([], $this->errors);
    }

    /**
     * Regression (review RUN-1): a stale match the sweep could not remove
     * threw out of actionStart(), so the runner exited before it polled, and
     * again on every restart. Now the runner names it once, leaves it, and
     * goes on to authenticate; here a file someone put where a job
     * directory would be.
     */
    public function testAStaleMatchTheRunnerCannotRemoveDoesNotStopTheStart(): void
    {
        $_ENV['API_URL'] = 'http://test-server';
        $file = $this->temp . '/' . self::jobName($this->deadPid(), 'file');
        file_put_contents($file, "planted\n");
        $controller = $this->controller();
        $resolver = $this->createMock(RunnerTokenResolver::class);
        $resolver->expects($this->once())->method('resolve')->willReturn('runner-token');
        $controller->setTokenResolver($resolver);

        $this->assertSame(ExitCode::OK, $controller->actionStart());

        $this->assertFileExists($file);
        $this->assertSame(
            ["WARNING: Left {$file} in place (not a directory). It may hold survey answers or host passwords of an earlier runner process; remove it by hand.\n"],
            $this->errors
        );
        $this->assertCount(1, $this->postsTo('/api/runner/v1/heartbeat'), 'it authenticated');
    }

    /**
     * Regression (review R2V2-3): a runner without the posix extension cannot
     * tell its own user, and its sweep removed job directories without the
     * owner check, other users' included. Now it removes none, names each
     * one with the reason, and starts.
     */
    public function testARunnerThatCannotTellItsUserRemovesNoJobDirectory(): void
    {
        $_ENV['API_URL'] = 'http://test-server';
        $stale = $this->staleJobDirectory(self::jobName((int)getmypid(), 'directory'));
        $controller = $this->controller();
        $controller->effectiveUid = static fn (): ?int => null;
        $resolver = $this->createMock(RunnerTokenResolver::class);
        $resolver->expects($this->once())->method('resolve')->willReturn('runner-token');
        $controller->setTokenResolver($resolver);

        $this->assertSame(ExitCode::OK, $controller->actionStart());

        $this->assertSame(
            ["WARNING: Left {$stale} in place (the runner's user is unknown: the posix extension is missing). It may hold survey answers or host passwords of an earlier runner process; remove it by hand.\n"],
            $this->errors
        );
        $this->assertSame([], preg_grep('/^Removed /', $this->printed));
        $this->assertDirectoryExists($stale);
        $this->assertSame('{"db_password":"stale"}', file_get_contents($stale . '/' . RunnerSecretVars::ANSWERS_FILE), 'the directory stays as it was');
        $this->assertCount(1, $this->postsTo('/api/runner/v1/heartbeat'), 'it authenticated');
    }

    /**
     * Regression (review R2V2-5): runners of 2.8.1 or older wrote the static
     * inventory and the task records straight into the temp directory and
     * left them there when a credential could not be set up or the runner
     * was killed; an updated runner said nothing about them. Now it names
     * each one at start, sorted by name, and removes none: a runner of 2.8.1
     * or older on the same host may still be using one.
     */
    public function testTheRunnerNamesTheFilesOfOlderRunnersAndRemovesNone(): void
    {
        $_ENV['API_URL'] = 'http://test-server';
        $tasks = $this->temp . '/ansilume_tasks_7_' . uniqid('', true) . '.ndjson';
        file_put_contents($tasks, '{"seq":0,"name":"Use example-db-answer"}' . "\n");
        $inventory = $this->temp . '/ansilume_inv_' . uniqid('', true) . '.yml';
        file_put_contents($inventory, "web-1 ansible_password=example-host-pass\n");
        $controller = $this->controller();
        $resolver = $this->createMock(RunnerTokenResolver::class);
        $resolver->expects($this->once())->method('resolve')->willReturn('runner-token');
        $controller->setTokenResolver($resolver);

        $this->assertSame(ExitCode::OK, $controller->actionStart());

        $this->assertSame("web-1 ansible_password=example-host-pass\n", file_get_contents($inventory));
        $this->assertFileExists($tasks);
        $note = ' in place (a runner of 2.8.1 or older left it). It may hold host passwords or survey answers;'
            . " remove it by hand once no runner of 2.8.1 or older runs on this host.\n";
        $this->assertSame(["WARNING: Left {$inventory}{$note}", "WARNING: Left {$tasks}{$note}"], $this->errors);
        $this->assertCount(1, $this->postsTo('/api/runner/v1/heartbeat'), 'it authenticated');
    }

    /**
     * Regression (review R3V1-1): the sweep took every name that starts with
     * ansilume_job_<digits>_ for a job directory, and the runner printed each
     * one it left in place as it was. Another user of a shared temp
     * directory could add lines to the runner's log at every start with a
     * line break in such a name, here a forged count line. Now the runner
     * takes only names of its own format: that entry is neither removed nor
     * named, the real match beside it is named in one WARNING line, and the
     * only count line is the real one.
     */
    public function testANameWithALineBreakAddsNoLineToTheRunnersLog(): void
    {
        $_ENV['API_URL'] = 'http://test-server';
        $dead = $this->deadPid();
        $forged = $this->temp . '/' . self::JOB_DIRECTORY . $dead
            . "_x\nRemoved the private directories of 99 job(s) that an earlier runner process left behind.";
        file_put_contents($forged, "planted\n");
        $file = $this->temp . '/' . self::jobName($dead, 'file');
        file_put_contents($file, "planted\n");
        $stale = $this->staleJobDirectory(self::jobName($dead, 'directory'));
        $controller = $this->controller();
        $resolver = $this->createMock(RunnerTokenResolver::class);
        $resolver->expects($this->once())->method('resolve')->willReturn('runner-token');
        $controller->setTokenResolver($resolver);

        $this->assertSame(ExitCode::OK, $controller->actionStart());

        $this->assertSame(
            ["WARNING: Left {$file} in place (not a directory). It may hold survey answers or host passwords of an earlier runner process; remove it by hand.\n"],
            $this->errors,
            'one WARNING line, for the real match'
        );
        $lines = explode("\n", implode('', [...$this->printed, ...$this->errors]));
        $this->assertSame(
            ['Removed the private directories of 1 job(s) that an earlier runner process left behind.'],
            array_values(preg_grep('/^Removed /', $lines) ?: []),
            'the only count line is the real one'
        );
        $this->assertSame([], preg_grep('/99 job/', $lines) ?: [], 'no forged line');
        $this->assertSame("planted\n", file_get_contents($forged), 'the entry stays');
        $this->assertFileExists($file);
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertCount(1, $this->postsTo('/api/runner/v1/heartbeat'), 'it authenticated');
    }

    /**
     * Regression (review R3V1-1, defense in depth): the runner printed the
     * paths of its start-up check as they were. Only the temp directory's
     * own name, which the operator chooses, can still hold control
     * characters, but no path may break a line of the log or send the
     * terminal a sequence: the runner prints them escaped as in C. Here it
     * names a job directory in such a temp directory, because it cannot
     * tell its own user.
     */
    public function testAJobDirectoryIsNamedWithTheControlCharactersOfItsPathEscaped(): void
    {
        $_ENV['API_URL'] = 'http://test-server';
        $printedTemp = $this->useATempDirectoryWithControlCharacters();
        $name = self::jobName($this->deadPid(), 'directory');
        $stale = $this->staleJobDirectory($name);
        $controller = $this->controller();
        $controller->effectiveUid = static fn (): ?int => null;
        $resolver = $this->createMock(RunnerTokenResolver::class);
        $resolver->expects($this->once())->method('resolve')->willReturn('runner-token');
        $controller->setTokenResolver($resolver);

        $this->assertSame(ExitCode::OK, $controller->actionStart());

        $this->assertSame(
            ["WARNING: Left {$printedTemp}/{$name} in place (the runner's user is unknown: the posix extension is missing). It may hold survey answers or host passwords of an earlier runner process; remove it by hand.\n"],
            $this->errors
        );
        $this->assertDirectoryExists($stale);
    }

    /**
     * Regression (review R3V1-1, defense in depth): the same for a file that
     * a runner of 2.8.1 or older left in such a temp directory.
     */
    public function testAFileOfAnOlderRunnerIsNamedWithTheControlCharactersOfItsPathEscaped(): void
    {
        $_ENV['API_URL'] = 'http://test-server';
        $printedTemp = $this->useATempDirectoryWithControlCharacters();
        $name = 'ansilume_inv_' . uniqid('', true) . '.yml';
        file_put_contents($this->temp . '/' . $name, "web-1 ansible_password=example-host-pass\n");
        $controller = $this->controller();
        $resolver = $this->createMock(RunnerTokenResolver::class);
        $resolver->expects($this->once())->method('resolve')->willReturn('runner-token');
        $controller->setTokenResolver($resolver);

        $this->assertSame(ExitCode::OK, $controller->actionStart());

        $this->assertSame(
            ["WARNING: Left {$printedTemp}/{$name} in place (a runner of 2.8.1 or older left it). It may hold host passwords or survey answers; remove it by hand once no runner of 2.8.1 or older runs on this host.\n"],
            $this->errors
        );
        $this->assertFileExists($this->temp . '/' . $name);
    }

    /**
     * Whatever else goes wrong in the sweep, the runner says so in one line,
     * without the exception's message, and goes on to authenticate.
     */
    public function testTheRunnerStartsEvenWhenTheSweepFails(): void
    {
        $_ENV['API_URL'] = 'http://test-server';
        $controller = $this->controller();
        $controller->sweepFailure = new \RuntimeException('sweep failed at /tmp/ansilume_job_1_x');
        $resolver = $this->createMock(RunnerTokenResolver::class);
        $resolver->expects($this->once())->method('resolve')->willReturn('runner-token');
        $controller->setTokenResolver($resolver);

        $this->assertSame(ExitCode::OK, $controller->actionStart());

        $this->assertSame(["WARNING: The check for job directories left behind failed (RuntimeException).\n"], $this->errors);
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * @param string|null $failingPath a path whose post throws
     */
    private function controller(?string $failingPath = null): TestableSecretVarsRunnerController
    {
        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('post')->willReturnCallback(function (string $path, array $body) use ($failingPath): array {
            if ($path === $failingPath) {
                $this->failedPosts[] = $body;
                throw new \RuntimeException('log post failed');
            }
            $this->posts[] = ['path' => $path, 'body' => $body];

            return ['ok' => true, 'data' => ['runner_name' => 'runner-1', 'group_name' => 'default']];
        });

        $controller = new TestableSecretVarsRunnerController('runner', \Yii::$app, $http, $this->temp);
        $controller->printed = &$this->printed;
        $controller->errors = &$this->errors;

        return $controller;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function executeJob(RunnerController $controller, array $payload): void
    {
        $method = new \ReflectionMethod(RunnerController::class, 'executeJob');
        $method->invoke($controller, 7, $payload);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra): array
    {
        return $extra + [
            'job_id' => 7,
            'scm_type' => 'manual',
            'project_path' => $this->dir,
            'inventory_type' => 'file',
            'command' => ['sh', '-c', self::PROBE],
            'credentials' => [],
            'timeout_minutes' => 1,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function postsTo(string $path): array
    {
        $bodies = [];
        foreach ($this->posts as $post) {
            if ($post['path'] === $path) {
                $bodies[] = $post['body'];
            }
        }

        return $bodies;
    }

    private function stdout(): string
    {
        $out = '';
        foreach ($this->postsTo('/api/runner/v1/jobs/7/logs') as $body) {
            if (($body['stream'] ?? '') === 'stdout') {
                $out .= (string)$body['content'];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function completion(): array
    {
        $completions = $this->postsTo('/api/runner/v1/jobs/7/complete');
        $this->assertCount(1, $completions);

        return $completions[0];
    }

    /**
     * A regex (without delimiters, # is quoted) for a job directory of this
     * process in the runner's temp directory.
     */
    private function jobDirectoryPattern(): string
    {
        return preg_quote($this->temp . '/' . self::JOB_DIRECTORY . getmypid() . '_', '#') . '[0-9a-f]{32}';
    }

    /**
     * What is left in the runner's temp directory, links and dotfiles
     * included.
     *
     * @return list<string>
     */
    private function leftInTheTempDirectory(): array
    {
        return array_values(array_diff(scandir($this->temp) ?: [], ['.', '..']));
    }

    /**
     * Makes the runner's temp directory one whose name holds control
     * characters: the bytes 0x01, 0x1F and 0x7F, a tab, a carriage return,
     * a line break with a forged WARNING after it, and an escape sequence.
     *
     * @return string the directory's path as the runner prints it, the control characters escaped as in C
     */
    private function useATempDirectoryWithControlCharacters(): string
    {
        $this->temp = $this->dir . "/tmp\001\t\r\nWARNING: forged\033[2J\037\177";
        $this->assertTrue(mkdir($this->temp, 0o700));

        return $this->dir . '/tmp\001\t\r\nWARNING: forged\033[2J\037\177';
    }

    /**
     * The name the runner gives a job directory of process $pid: the
     * prefix, the process ID and 32 lowercase hex digits, here derived from
     * $label so that the names in a test differ.
     */
    private static function jobName(int $pid, string $label): string
    {
        return self::JOB_DIRECTORY . $pid . '_' . md5($label);
    }

    /**
     * A job directory named $name in the runner's temp directory as a
     * killed runner process leaves it.
     */
    private function staleJobDirectory(string $name): string
    {
        $path = $this->temp . '/' . $name;
        mkdir($path, 0o700);
        file_put_contents($path . '/' . RunnerSecretVars::ANSWERS_FILE, '{"db_password":"stale"}');
        file_put_contents($path . '/' . RunnerController::INVENTORY_FILE, "web-1 ansible_password=example-host-pass\n");

        return $path;
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

/**
 * RunnerController with a recording HTTP client, the jobs' directories in a
 * test directory and no poll loop.
 */
class TestableSecretVarsRunnerController extends RunnerController
{
    /** @var list<string> */
    public array $printed = [];

    /** @var list<string> */
    public array $errors = [];

    /** The random part of the next job directory's name; a random one when null. */
    public ?string $randomPart = null;

    /** @var (\Closure(): ?int)|null the runner's effective user ID as the job files see it; posix_geteuid() when null */
    public ?\Closure $effectiveUid = null;

    /** Thrown by the start-up sweep when set. */
    public ?\Throwable $sweepFailure = null;

    /**
     * @param string $temp the runner's temp directory
     */
    public function __construct($id, $module, RunnerHttpClient $http, public string $temp)
    {
        parent::__construct($id, $module);
        $this->http = $http;
    }

    public function setTokenResolver(RunnerTokenResolver $resolver): void
    {
        $this->tokenResolver = $resolver;
    }

    protected function secretVars(): RunnerSecretVars
    {
        $randomPart = $this->randomPart;
        if ($randomPart === null && $this->effectiveUid === null) {
            return parent::secretVars();
        }

        return new RunnerSecretVars(
            $this->tempDirectory(),
            $this->effectiveUid,
            $randomPart === null ? null : static fn (): string => $randomPart
        );
    }

    protected function tempDirectory(): string
    {
        return $this->temp;
    }

    protected function removeStaleSecretFiles(): void
    {
        if ($this->sweepFailure !== null) {
            throw $this->sweepFailure;
        }
        parent::removeStaleSecretFiles();
    }

    protected function registerSignalHandlers(): void
    {
    }

    protected function pollLoop(): void
    {
    }

    public function stdout($string): int
    {
        $this->printed[] = (string)$string;

        return 0;
    }

    public function stderr($string): int
    {
        $this->errors[] = (string)$string;

        return 0;
    }
}
