<?php

declare(strict_types=1);

namespace app\tests\unit;

/**
 * Runs code as a user who may not read what the tests' files deny to
 * others, as the web server's user (www-data) may not. The tests run as
 * root in the containers, and root reads every file whatever its mode, so
 * there the code runs with the effective user ID of nobody; run as another
 * user, the modes alone deny it.
 *
 * posix_seteuid() changes the effective user only, which open(), opendir()
 * and stat() check; access(), and so is_readable() and file_exists(),
 * checks the real user, root. Root's groups stay, so the files under test
 * give their group no more than others. The log is flushed first: nobody
 * may not append to runtime/logs/test.log, and a flush of the buffered
 * messages while the code runs would fail. Tests that use it need the
 * posix extension.
 */
trait RunsUnprivileged
{
    /**
     * @template T
     * @param callable(): T $code
     * @return T
     */
    private static function unprivileged(callable $code): mixed
    {
        if (posix_geteuid() !== 0) {
            return $code();
        }
        \Yii::getLogger()->flush(true);
        clearstatcache();
        if (!posix_seteuid(65534)) {
            throw new \RuntimeException('Cannot run as nobody.');
        }
        try {
            return $code();
        } finally {
            posix_seteuid(0);
            clearstatcache();
        }
    }
}
