<?php

declare(strict_types=1);

namespace app\components;

/**
 * Starts a process with proc_open() and gives the reason when it does not
 * start, instead of a PHP warning or error. Every proc_open() call of the
 * application goes through open() (ProcessStarterTest); LintService and
 * AnsibleInventoryRunner run their `which` checks through PHP's `exec`.
 *
 * Since PHP 8.3, proc_open() starts an array command with posix_spawn()
 * where libc has posix_spawn_file_actions_addchdir_np() (glibc 2.29 or
 * newer, as in the images): when its program is not on the PATH, cannot be
 * executed or has no interpreter, or its working directory is gone or may
 * not be entered, proc_open() raises a warning, such as "proc_open():
 * posix_spawn() failed: No such file or directory", and returns false. The
 * error handlers of the web and console applications, the tests' included,
 * turn every warning into a yii\base\ErrorException, so the callers' own
 * handling of a process that did not start never ran: a runner died with
 * the job it had claimed, and an SSH key credential could not be saved.
 * PHP 8.2 started such a process, which exited with 127, or ran it in PHP's
 * own working directory. On every version proc_open() also returns false
 * with a warning for other reasons, such as "Unable to create pipe Too many
 * open files", and throws a ValueError for a command element with a NUL
 * byte, which a job's limit or a project's branch can hold.
 *
 * PHP 8.3 looks the program up in the PATH of the PHP process
 * (posix_spawnp()), no longer in the PATH of the environment the process
 * gets; what the process starts itself still uses that environment.
 */
final class ProcessStarter
{
    /** Why a process did not start; sprintf(): its program (the command's first element), then PHP's message. */
    public const NOT_STARTED = '%s could not be started: %s';

    /**
     * Why a process did not start in a working directory that is gone or
     * may not be entered; sprintf(): its program, the directory, then PHP's
     * message. posix_spawn() reports such a directory as it reports a
     * program it cannot run, so the reason names both.
     */
    public const NOT_STARTED_IN = '%s could not be started in %s: %s';

    /**
     * Logged when a process started, but PHP warned while it started it;
     * sprintf(): its program, then the warning.
     */
    public const STARTED_WITH_WARNING = 'ProcessStarter: %s started, but PHP warned: %s';

    /**
     * proc_open() with an array command; null instead of a warning or a
     * ValueError when the process did not start, and the reason in $failure
     * (NOT_STARTED, NOT_STARTED_IN).
     *
     * @param array<int, string> $command The command, a repository URL with its credentials included.
     * @param array<int, mixed> $descriptors
     * @param array<int, resource>|null $pipes
     * @param-out array<int, resource> $pipes
     * @param array<string, string>|null $env
     * @param string|null $failure set to the reason when the process did not start, else to null
     * @return resource|null the process, null when it did not start
     */
    public static function open(
        #[\SensitiveParameter] array $command,
        array $descriptors,
        ?array &$pipes,
        ?string $cwd = null,
        #[\SensitiveParameter] ?array $env = null,
        ?string &$failure = null
    ) {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        }, E_WARNING);
        try {
            $process = proc_open($command, $descriptors, $pipes, $cwd, $env);
        } catch (\ValueError $e) {
            $process = false;
            $warning = $e->getMessage();
        } finally {
            restore_error_handler();
        }
        $program = (string)reset($command);
        if (is_resource($process)) {
            $failure = null;
            if ($warning !== null) {
                \Yii::warning(sprintf(self::STARTED_WITH_WARNING, $program, $warning), __CLASS__);
            }

            return $process;
        }
        $failure = self::reason($program, $cwd, $warning ?? 'proc_open() failed');

        return null;
    }

    /**
     * The reason a process did not start: with its working directory only
     * when the directory can be at fault, as it is gone or may not be
     * entered, so that a program missing from the PATH is not blamed on an
     * existing project directory. proc_open() cuts a directory off at a NUL
     * byte, and the stat functions refuse one, so such a directory is named
     * with the byte shown as \0.
     */
    private static function reason(string $program, ?string $cwd, string $message): string
    {
        if ($cwd === null || (!str_contains($cwd, "\0") && self::canEnter($cwd))) {
            return sprintf(self::NOT_STARTED, $program, $message);
        }

        return sprintf(self::NOT_STARTED_IN, $program, str_replace("\0", '\0', $cwd), $message);
    }

    private static function canEnter(string $directory): bool
    {
        clearstatcache(true, $directory);

        return is_dir($directory) && is_executable($directory);
    }
}
