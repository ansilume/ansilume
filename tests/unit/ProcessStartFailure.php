<?php

declare(strict_types=1);

namespace app\tests\unit;

/**
 * For the regression tests of processes that do not start
 * (app\components\ProcessStarter): a PATH on which the PHP process finds no
 * program, as on a runner host whose tools are not on the runner's PATH,
 * and what PHP 8.3 then warns.
 */
final class ProcessStartFailure
{
    /** A PATH without programs. PHP 8.3 looks a program up in the PATH of the PHP process. */
    public const NO_PROGRAMS = '/nonexistent/ansilume-no-programs';

    /** PHP's warning when posix_spawn() finds no program, or no working directory. */
    public const NO_SUCH_FILE = 'proc_open(): posix_spawn() failed: No such file or directory';

    /** Sets the PATH of the PHP process to NO_PROGRAMS until restore(). */
    public static function emptyPath(): TemporaryEnvironment
    {
        return new TemporaryEnvironment(['PATH' => self::NO_PROGRAMS]);
    }
}
