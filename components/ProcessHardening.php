<?php

declare(strict_types=1);

namespace app\components;

/**
 * Marks the current process as non-dumpable (Linux prctl PR_SET_DUMPABLE).
 *
 * Long-running workers (queue-worker, runner) start child processes that run
 * repository-controlled code under the same Unix user. Scrubbing the child's
 * environment is not enough on its own: any process of the same user can read
 * /proc/<pid>/environ of a dumpable parent, and the worker's initial
 * environment holds APP_SECRET_KEY, DB_PASSWORD or RUNNER_BOOTSTRAP_SECRET.
 * A non-dumpable process has its /proc entries owned by root, and without
 * CAP_SYS_PTRACE (which Docker drops by default) not even root inside the
 * container can read them. php-fpm already does this for its workers;
 * console workers have to do it themselves.
 *
 * Uses the FFI extension. When FFI is unavailable or disabled the calls report
 * failure instead of throwing, so the caller can warn the operator.
 */
final class ProcessHardening
{
    public const PRCTL_DECLARATION = 'int prctl(int option, unsigned long arg2, unsigned long arg3, '
        . 'unsigned long arg4, unsigned long arg5);';

    /** Operator-facing reason used when hardening could not be applied. */
    public const UNAVAILABLE_REASON = 'PHP FFI unavailable (extension missing or disabled via ffi.enable) '
        . 'or prctl() failed';

    private const PR_GET_DUMPABLE = 3;
    private const PR_SET_DUMPABLE = 4;

    /** @var (callable(int, int): int)|null */
    private $prctl;

    /** @var \Closure(): ?object */
    private \Closure $libcLoader;

    /**
     * @param (callable(int, int): int)|null $prctl Test seam replacing prctl() entirely.
     * @param (\Closure(): ?object)|null $libcLoader Returns an object exposing prctl(), or null.
     */
    public function __construct(?callable $prctl = null, ?\Closure $libcLoader = null)
    {
        $this->prctl = $prctl;
        $this->libcLoader = $libcLoader ?? self::ffiLoader();
    }

    /**
     * Loader for libc's prctl() through FFI. Without a library name the symbol
     * is resolved from the C library the PHP process already uses (glibc or musl).
     *
     * @param bool|null $ffiAvailable Defaults to "Linux with the FFI extension loaded".
     * @return \Closure(): ?object
     */
    public static function ffiLoader(?string $library = null, ?bool $ffiAvailable = null): \Closure
    {
        $ffiAvailable ??= PHP_OS_FAMILY === 'Linux' && extension_loaded('ffi');

        return static function () use ($library, $ffiAvailable): ?object {
            if (!$ffiAvailable) {
                return null;
            }
            try {
                return $library === null
                    ? \FFI::cdef(self::PRCTL_DECLARATION)
                    : \FFI::cdef(self::PRCTL_DECLARATION, $library);
            } catch (\Throwable) {
                return null;
            }
        };
    }

    /**
     * Returns true when the process is now non-dumpable.
     */
    public function disableDumpable(): bool
    {
        $prctl = $this->resolvePrctl();
        if ($prctl === null) {
            return false;
        }

        return $prctl(self::PR_SET_DUMPABLE, 0) === 0;
    }

    /**
     * Returns whether the process is dumpable, or null when that cannot be determined.
     */
    public function isDumpable(): ?bool
    {
        $prctl = $this->resolvePrctl();
        if ($prctl === null) {
            return null;
        }
        $result = $prctl(self::PR_GET_DUMPABLE, 0);

        return $result < 0 ? null : $result !== 0;
    }

    /**
     * @return (callable(int, int): int)|null
     */
    private function resolvePrctl(): ?callable
    {
        if ($this->prctl !== null) {
            return $this->prctl;
        }
        $libc = ($this->libcLoader)();
        if ($libc === null) {
            return null;
        }

        $this->prctl = static function (int $option, int $arg) use ($libc): int {
            /** @phpstan-ignore method.notFound (prctl is declared through FFI::cdef in ffiLoader()) */
            return (int)$libc->prctl($option, $arg, 0, 0, 0);
        };

        return $this->prctl;
    }
}
