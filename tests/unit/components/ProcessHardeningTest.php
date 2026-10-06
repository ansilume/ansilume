<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\ProcessHardening;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProcessHardening with injected prctl()/libc doubles. The real
 * FFI path and its effect on /proc are covered by
 * tests/integration/components/ProcessHardeningIntegrationTest.php.
 */
class ProcessHardeningTest extends TestCase
{
    public function testDisableDumpableCallsPrSetDumpableWithZero(): void
    {
        $calls = [];
        $hardening = new ProcessHardening(static function (int $option, int $arg) use (&$calls): int {
            $calls[] = [$option, $arg];
            return 0;
        });

        $this->assertTrue($hardening->disableDumpable());
        $this->assertSame([[4, 0]], $calls, 'PR_SET_DUMPABLE (4) must be called with 0');
    }

    public function testDisableDumpableReportsFailureWhenPrctlFails(): void
    {
        $hardening = new ProcessHardening(static fn (int $option, int $arg): int => -1);

        $this->assertFalse($hardening->disableDumpable());
    }

    public function testIsDumpableMapsPrGetDumpableResult(): void
    {
        $calls = [];
        $dumpable = new ProcessHardening(static function (int $option, int $arg) use (&$calls): int {
            $calls[] = $option;
            return 1;
        });
        $this->assertTrue($dumpable->isDumpable());
        $this->assertSame([3], $calls, 'PR_GET_DUMPABLE (3) must be queried');

        $hardened = new ProcessHardening(static fn (int $option, int $arg): int => 0);
        $this->assertFalse($hardened->isDumpable());

        $unknown = new ProcessHardening(static fn (int $option, int $arg): int => -1);
        $this->assertNull($unknown->isDumpable());
    }

    public function testLoadedLibcIsCalledWithFullPrctlArguments(): void
    {
        $libc = new class () {
            /** @var list<list<int>> */
            public array $calls = [];

            public function prctl(int $option, int $arg2, int $arg3, int $arg4, int $arg5): int
            {
                $this->calls[] = [$option, $arg2, $arg3, $arg4, $arg5];
                return 0;
            }
        };
        $loads = 0;
        $hardening = new ProcessHardening(null, static function () use ($libc, &$loads): object {
            $loads++;
            return $libc;
        });

        $this->assertTrue($hardening->disableDumpable());
        $this->assertFalse($hardening->isDumpable());
        $this->assertSame([[4, 0, 0, 0, 0], [3, 0, 0, 0, 0]], $libc->calls);
        $this->assertSame(1, $loads, 'the library is loaded once and reused');
    }

    public function testUnavailableLibcIsReportedAsNotHardened(): void
    {
        $hardening = new ProcessHardening(null, static fn (): ?object => null);

        $this->assertFalse($hardening->disableDumpable());
        $this->assertNull($hardening->isDumpable());
    }

    public function testFfiLoaderReturnsNothingWhenFfiIsUnavailable(): void
    {
        $this->assertNull(ProcessHardening::ffiLoader(null, false)());
        $this->assertFalse((new ProcessHardening(null, ProcessHardening::ffiLoader(null, false)))->disableDumpable());
    }

    public function testFfiLoaderNeverThrows(): void
    {
        // Without FFI the extension check returns null; with FFI a library
        // that cannot be loaded is caught and also reported as null.
        $this->assertNull(ProcessHardening::ffiLoader('libansilume-does-not-exist.so.0')());

        $default = ProcessHardening::ffiLoader()();
        if (PHP_OS_FAMILY === 'Linux' && extension_loaded('ffi')) {
            $this->assertInstanceOf(\FFI::class, $default);
        } else {
            $this->assertNull($default);
        }
    }
}
