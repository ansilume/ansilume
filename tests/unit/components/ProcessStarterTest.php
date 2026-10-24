<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\ProcessStarter;
use app\tests\unit\ProcessStartFailure;
use PHPUnit\Framework\TestCase;
use yii\log\Logger;

/**
 * Regression (PHP 8.3): proc_open() starts an array command with
 * posix_spawn() and reports a program it cannot run, or a working
 * directory it cannot enter, with a warning and false. The error handler of
 * the application, which these tests run under too, turned the warning into
 * a yii\base\ErrorException, so no caller's handling of a process that did
 * not start ever ran: a runner died with the job it had claimed. PHP 8.2
 * started such a process, which exited with 127. ProcessStarter::open()
 * gives null and the reason instead, and every proc_open() call of the
 * application goes through it.
 */
class ProcessStarterTest extends TestCase
{
    private const MISSING = 'ansilume-no-such-program-4711';

    /**
     * The regression tests of the callers fail without ProcessStarter only
     * because a warning throws here as in production.
     */
    public function testTheTestsRunUnderTheErrorHandlerOfTheApplication(): void
    {
        $handler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        $this->assertIsArray($handler, 'a warning in these tests must reach the error handler of the application');
        $this->assertInstanceOf(\yii\base\ErrorHandler::class, $handler[0]);
    }

    public function testAStartedProcessHasNoFailure(): void
    {
        $failure = 'left from before';
        $process = ProcessStarter::open(['sh', '-c', 'exit 3'], [], $pipes, null, null, $failure);

        $this->assertIsResource($process);
        $this->assertNull($failure);
        $this->assertSame(3, proc_close($process));
    }

    public function testAProgramThatIsNotOnThePathIsAReasonInsteadOfAWarning(): void
    {
        $process = ProcessStarter::open([self::MISSING, '--version'], [1 => ['pipe', 'w']], $pipes, null, null, $failure);

        $this->assertNull($process);
        $this->assertSame(sprintf(ProcessStarter::NOT_STARTED, self::MISSING, ProcessStartFailure::NO_SUCH_FILE), $failure);
    }

    /**
     * posix_spawn() fails the same way for the working directory, and PHP
     * 8.2 ran the process in PHP's own working directory instead.
     */
    public function testAWorkingDirectoryThatIsGoneIsNamedInTheReason(): void
    {
        $cwd = '/nonexistent/ansilume-4711';
        $process = ProcessStarter::open(['pwd'], [1 => ['pipe', 'w']], $pipes, $cwd, null, $failure);

        $this->assertNull($process);
        $this->assertSame(sprintf(ProcessStarter::NOT_STARTED_IN, 'pwd', $cwd, ProcessStartFailure::NO_SUCH_FILE), $failure);
    }

    /**
     * Review finding: a runner job runs in its project directory, which
     * exists after the sync, so a playbook missing from the PATH was blamed
     * on the directory. A directory that can be entered is not named.
     */
    public function testAWorkingDirectoryThatCanBeEnteredIsNotBlamedForAMissingProgram(): void
    {
        $process = ProcessStarter::open([self::MISSING], [1 => ['pipe', 'w']], $pipes, sys_get_temp_dir(), null, $failure);

        $this->assertNull($process);
        $this->assertSame(sprintf(ProcessStarter::NOT_STARTED, self::MISSING, ProcessStartFailure::NO_SUCH_FILE), $failure);
    }

    /**
     * Review finding: proc_open() throws a ValueError on every PHP version
     * for a command element with a NUL byte, which a job's limit or a
     * project's branch can hold, and the runner died with its job. It is a
     * reason now, and the caller's error handler is back afterwards.
     */
    public function testANulByteInTheCommandIsAReasonAndTheCallersHandlerIsRestored(): void
    {
        $caller = static fn (): bool => false;
        set_error_handler($caller);
        try {
            $process = ProcessStarter::open(['echo', "web\0x"], [], $pipes, null, null, $failure);

            $this->assertSame($caller, set_error_handler(static fn (): bool => false));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }

        $this->assertNull($process);
        $this->assertSame(sprintf(ProcessStarter::NOT_STARTED, 'echo', 'Command array element 2 contains a null byte'), $failure);
    }

    /**
     * Review finding: the reason checked a working directory with a NUL
     * byte with clearstatcache(), which throws a ValueError for one.
     * proc_open() cuts it off at the byte; the reason names it, the byte
     * shown as \0.
     */
    public function testAWorkingDirectoryWithANulByteIsNamedInTheReason(): void
    {
        $process = ProcessStarter::open(['true'], [], $pipes, "/nonexistent/ansilume\0x", null, $failure);

        $this->assertNull($process);
        $this->assertSame(sprintf(ProcessStarter::NOT_STARTED_IN, 'true', '/nonexistent/ansilume\0x', ProcessStartFailure::NO_SUCH_FILE), $failure);
    }

    /**
     * Review finding: a warning PHP raises while the process still starts
     * was dropped; the application's handler had thrown it before. It is
     * logged now.
     */
    public function testAWarningWhileTheProcessStartsIsLogged(): void
    {
        $logger = new Logger();
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $process = ProcessStarter::open(['true'], [], $pipes, null, ['ANSILUME_PROBE' => ['not a string']], $failure);
        } finally {
            \Yii::setLogger($previous);
        }

        $this->assertIsResource($process);
        $this->assertSame(0, proc_close($process));
        $this->assertNull($failure);
        $own = array_values(array_filter($logger->messages, static fn (array $message): bool => $message[2] === ProcessStarter::class));
        $this->assertSame(
            [[sprintf(ProcessStarter::STARTED_WITH_WARNING, 'true', 'Array to string conversion'), Logger::LEVEL_WARNING]],
            array_map(static fn (array $message): array => [$message[0], $message[1]], $own)
        );
    }

    /**
     * proc_open() also warns and returns false on every PHP version: without
     * a free descriptor for the pipes, no process starts.
     *
     * @requires extension posix
     */
    public function testNoFreeDescriptorIsAReasonToo(): void
    {
        $limits = posix_getrlimit();
        $this->assertIsArray($limits);
        $hard = $limits['hard openfiles'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int)$limits['hard openfiles'];
        $soft = $limits['soft openfiles'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int)$limits['soft openfiles'];
        $open = count(scandir('/proc/self/fd') ?: []) - 2;
        $this->assertTrue(posix_setrlimit(POSIX_RLIMIT_NOFILE, $open, $hard), 'cannot lower the limit of open files');
        try {
            $process = ProcessStarter::open(['true'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, $failure);
        } finally {
            posix_setrlimit(POSIX_RLIMIT_NOFILE, $soft, $hard);
        }

        $this->assertNull($process);
        $this->assertSame(sprintf(ProcessStarter::NOT_STARTED, 'true', 'proc_open(): Unable to create pipe Too many open files'), $failure);
    }

    public function testTheErrorHandlerOfTheCallerIsRestored(): void
    {
        $caller = static fn (): bool => false;
        set_error_handler($caller);
        try {
            ProcessStarter::open([self::MISSING], [], $pipes);

            $this->assertSame($caller, set_error_handler(static fn (): bool => false));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Every proc_open() call of the application goes through
     * ProcessStarter::open(): a new direct call would bring back the runner
     * that dies with its job. The tests' own helpers are not part of the
     * application.
     */
    public function testNothingElseCallsProcOpen(): void
    {
        $calls = [];
        foreach (self::applicationFiles() as $path) {
            $tokens = array_values(array_filter(
                token_get_all((string)file_get_contents(self::root() . '/' . $path)),
                static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ));
            foreach ($tokens as $i => $token) {
                if (self::callsProcOpen($tokens, $i)) {
                    $calls[] = $path . ':' . $token[2];
                }
            }
        }

        $elsewhere = array_values(array_filter($calls, static fn (string $call): bool => !str_starts_with($call, 'components/ProcessStarter.php:')));
        $this->assertSame([], $elsewhere, 'Start processes with ProcessStarter::open(), not proc_open()');
        $this->assertCount(1, $calls, 'The scan finds the call in ProcessStarter.');
    }

    /**
     * Whether $tokens[$i] is a call of the function proc_open(), not a
     * method of that name.
     *
     * @param list<mixed> $tokens without whitespace and comments
     */
    private static function callsProcOpen(array $tokens, int $i): bool
    {
        $token = $tokens[$i];
        if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }
        if (strtolower(ltrim((string)$token[1], '\\')) !== 'proc_open' || ($tokens[$i + 1] ?? null) !== '(') {
            return false;
        }
        $before = $tokens[$i - 1] ?? null;

        return !is_array($before) || !in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
    }

    /**
     * The PHP files of the application, relative to the repository root.
     *
     * @return list<string>
     */
    private static function applicationFiles(): array
    {
        $root = self::root();
        $files = ['yii'];
        foreach (['commands', 'components', 'config', 'controllers', 'helpers', 'jobs', 'mail', 'migrations', 'models', 'services', 'views', 'web'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($files);

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
