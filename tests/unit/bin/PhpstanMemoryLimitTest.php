<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;

/**
 * Regression: the CI run of v2.10.0 failed in bin/tests-phpstan.sh with
 * "Child process error ... PHPStan process crashed because it reached
 * configured PHP memory limit: 256M". PHPStan's parallel workers inherit the
 * CLI memory_limit of the app container (256M, docker/php/php.ini). On the
 * 4-core CI runner each of the four workers analyses a quarter of the files,
 * and one of them ran out of memory; a 16-core development machine splits
 * the files over 16 workers and passed. The script now passes --memory-limit,
 * which PHPStan hands to every worker. Measured on 2026-10-13: one worker over
 * the whole tree passes with 512M; the script sets 1G for the code to grow.
 */
class PhpstanMemoryLimitTest extends TestCase
{
    /** One worker analysing every file passes with this much (2026-10-13). */
    private const MEASURED_SINGLE_WORKER_BYTES = 536870912;

    public function testTheAnalyseCallGivesTheWorkersMoreThanTheMeasuredNeed(): void
    {
        $limit = $this->memoryLimitOf($this->analyseCall());

        $this->assertNotNull($limit, 'bin/tests-phpstan.sh must pass --memory-limit to phpstan analyse');
        $this->assertGreaterThan(
            self::MEASURED_SINGLE_WORKER_BYTES,
            $limit,
            'the limit must leave room above what one worker needed for the whole tree'
        );
    }

    public function testTheContainerLimitAloneWouldNotDo(): void
    {
        $ini = (string)file_get_contents(dirname(__DIR__, 3) . '/docker/php/php.ini');
        $this->assertSame(1, preg_match('/^memory_limit\s*=\s*(\S+)/m', $ini, $m));

        $containerLimit = $this->bytes($m[1]);
        $this->assertNotNull($containerLimit);
        $this->assertLessThan(
            self::MEASURED_SINGLE_WORKER_BYTES,
            $containerLimit,
            'if the container limit is raised past the measured need, this guard and its comment can go'
        );
    }

    private function analyseCall(): string
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/tests-phpstan.sh');
        $this->assertSame(1, preg_match('~vendor/bin/phpstan analyse\b(.*?)2>&1~s', $script, $m), 'the analyse call');

        return $m[1];
    }

    private function memoryLimitOf(string $call): ?int
    {
        if (preg_match('/--memory-limit[= ](\S+)/', $call, $m) !== 1) {
            return null;
        }

        return $this->bytes($m[1]);
    }

    /**
     * Bytes of a PHP shorthand size such as 256M or 1G; -1 (no limit) counts
     * as unlimited.
     */
    private function bytes(string $size): ?int
    {
        if ($size === '-1') {
            return PHP_INT_MAX;
        }
        if (preg_match('/^(\d+)([KMG]?)$/i', $size, $m) !== 1) {
            return null;
        }
        $factor = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($m[2])];

        return (int)$m[1] * $factor;
    }
}
