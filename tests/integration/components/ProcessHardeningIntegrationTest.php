<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\ProcessHardening;
use app\components\ProcessHardeningBootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real prctl() call through FFI and its actual security effect.
 *
 * Regression: the queue-worker and the runner run as www-data and start child
 * processes with repository code under the same user. Even with a scrubbed
 * child environment, such a child could read the worker's own environment
 * (APP_SECRET_KEY, DB_PASSWORD, RUNNER_BOOTSTRAP_SECRET) from
 * /proc/<worker-pid>/environ, because a process started via gosu is dumpable.
 */
class ProcessHardeningIntegrationTest extends TestCase
{
    private const CANARY = 'leak-canary-worker-env';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !extension_loaded('ffi')) {
            $this->markTestSkipped('Needs Linux and the PHP FFI extension.');
        }
    }

    public function testRealPrctlTogglesTheDumpableFlag(): void
    {
        $hardening = new ProcessHardening();
        $this->assertTrue($hardening->isDumpable(), 'a fresh PHP process is dumpable');

        try {
            $this->assertTrue($hardening->disableDumpable());
            $this->assertFalse($hardening->isDumpable());
        } finally {
            $this->restoreDumpable();
        }
        $this->assertTrue($hardening->isDumpable());
    }

    /**
     * The production wiring: the console application creates the bootstrap
     * through Yii's container with its default ProcessHardening.
     */
    public function testConsoleBootstrapAsCreatedByYiiHardensTheProcess(): void
    {
        /** @var ProcessHardeningBootstrap $bootstrap */
        $bootstrap = \Yii::createObject(ProcessHardeningBootstrap::class);
        try {
            $bootstrap->bootstrap(\Yii::$app);
            $this->assertTrue($bootstrap->applied);
            $this->assertFalse((new ProcessHardening())->isDumpable());
        } finally {
            $this->restoreDumpable();
        }
    }

    public function testUnloadableLibraryIsCaught(): void
    {
        $this->assertNull(ProcessHardening::ffiLoader('libansilume-does-not-exist.so.0')());
        $hardening = new ProcessHardening(null, ProcessHardening::ffiLoader('libnope.so.0'));
        $this->assertFalse($hardening->disableDumpable());
    }

    public function testSameUserCanReadTheEnvironmentOfAnUnhardenedWorker(): void
    {
        // Control case: proves the attack works, so the next test is meaningful.
        $this->assertStringContainsString(self::CANARY, $this->readWorkerEnvironmentAsSameUser(false));
    }

    public function testSameUserCannotReadTheEnvironmentOfAHardenedWorker(): void
    {
        $this->assertStringNotContainsString(self::CANARY, $this->readWorkerEnvironmentAsSameUser(true));
    }

    /**
     * Starts a www-data PHP "worker" holding a canary in its environment and,
     * once the worker reports it is ready (hardened or not), reads
     * /proc/<pid>/environ from a second www-data process.
     */
    private function readWorkerEnvironmentAsSameUser(bool $hardened): string
    {
        $gosu = $this->findGosu();
        if (posix_geteuid() !== 0 || $gosu === null) {
            $this->markTestSkipped('Needs root and gosu to switch to www-data.');
        }

        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $harden = $hardened
            ? 'require ' . var_export($autoload, true) . ';'
                . ' if (!(new app\components\ProcessHardening())->disableDumpable()) { exit(3); }'
            : '';
        $worker = proc_open(
            [$gosu, 'www-data', PHP_BINARY, '-r', $harden . ' echo "READY\n"; fflush(STDOUT); sleep(20);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'WORKER_CANARY' => self::CANARY]
        );
        $this->assertIsResource($worker);
        $pid = (int)proc_get_status($worker)['pid'];

        try {
            $this->waitForReadyLine($pipes[1]);
            if ($hardened) {
                clearstatcache();
                $owner = fileowner("/proc/{$pid}/environ");
                $this->assertSame(0, $owner, 'a hardened worker has root-owned /proc entries');
            }
            return $this->readAsWwwData($gosu, "/proc/{$pid}/environ");
        } finally {
            proc_terminate($worker, 9);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($worker);
        }
    }

    /**
     * @param resource $stdout
     */
    private function waitForReadyLine($stdout): void
    {
        $read = [$stdout];
        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, 10);
        $this->assertSame(1, $ready, 'worker did not report readiness in time');
        $this->assertSame("READY\n", fgets($stdout), 'worker failed before reporting readiness');
    }

    private function readAsWwwData(string $gosu, string $path): string
    {
        $reader = proc_open(
            [$gosu, 'www-data', 'cat', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($reader);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($reader);

        return str_replace("\0", "\n", $out);
    }

    private function restoreDumpable(): void
    {
        $libc = ProcessHardening::ffiLoader()();
        /** @phpstan-ignore method.notFound (prctl is declared through FFI::cdef) */
        $libc?->prctl(4, 1, 0, 0, 0);
    }

    private function findGosu(): ?string
    {
        foreach (['/usr/sbin/gosu', '/usr/bin/gosu', '/usr/local/bin/gosu'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
