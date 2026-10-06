<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\ProcessHardening;
use app\components\ProcessHardeningBootstrap;
use PHPUnit\Framework\TestCase;
use yii\log\Logger;

class ProcessHardeningBootstrapTest extends TestCase
{
    public function testBootstrapMarksTheProcessNonDumpable(): void
    {
        $calls = [];
        $bootstrap = new ProcessHardeningBootstrap(new ProcessHardening(
            static function (int $option, int $arg) use (&$calls): int {
                $calls[] = [$option, $arg];
                return 0;
            }
        ));

        $bootstrap->bootstrap(\Yii::$app);

        $this->assertTrue($bootstrap->applied);
        $this->assertSame([[4, 0]], $calls);
    }

    public function testBootstrapLogsWhenHardeningIsUnavailable(): void
    {
        $bootstrap = new ProcessHardeningBootstrap(new ProcessHardening(static fn (int $option, int $arg): int => -1));
        $previous = \Yii::getLogger();
        $logger = new Logger();
        \Yii::setLogger($logger);
        try {
            $bootstrap->bootstrap(\Yii::$app);
        } finally {
            \Yii::setLogger($previous);
        }

        $this->assertFalse($bootstrap->applied);
        $texts = array_map(static fn (array $m): string => (string)$m[0], $logger->messages);
        $this->assertNotEmpty(
            array_filter($texts, static fn (string $t): bool => str_contains($t, ProcessHardening::UNAVAILABLE_REASON)),
            'an unavailable hardening must leave an operator-visible log entry naming the reason'
        );
    }

    /**
     * The real default wiring: with FFI on Linux the bootstrap must actually
     * make the process non-dumpable; without FFI it must report that it could not.
     */
    public function testDefaultBootstrapUsesRealProcessHardening(): void
    {
        $bootstrap = new ProcessHardeningBootstrap();
        $bootstrap->bootstrap(\Yii::$app);

        $ffi = PHP_OS_FAMILY === 'Linux' && extension_loaded('ffi');
        try {
            $this->assertSame($ffi, $bootstrap->applied);
            if ($ffi) {
                $this->assertFalse((new ProcessHardening())->isDumpable());
            }
        } finally {
            if ($bootstrap->applied) {
                $libc = ProcessHardening::ffiLoader()();
                /** @phpstan-ignore method.notFound (prctl is declared through FFI::cdef) */
                $libc?->prctl(4, 1, 0, 0, 0);
            }
        }
    }

    /**
     * Regression guard: the hardening only protects console workers if the
     * console application actually bootstraps it.
     */
    public function testConsoleConfigRegistersTheBootstrap(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/console.php';

        $this->assertContains(ProcessHardeningBootstrap::class, $config['bootstrap']);
    }
}
