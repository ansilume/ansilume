<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;

/**
 * Regression: on 2026-10-13 the coverage run of bin/tests-phpunit.sh stopped
 * after about 10038 of 10444 tests with "Allowed memory size of 268435456
 * bytes exhausted", the CLI memory_limit of the app container (256M,
 * docker/php/php.ini). No test had failed. Coverage keeps, for every covered
 * line, the tests that ran it, so the run's memory grows with the number of
 * tests: with the limit raised, the same 10444 tests peaked at 254.5 MB
 * (PHPUnit's Memory line), and 2.10.1's 8588 tests had still fitted. CI runs
 * the same script in the same image. Every PHPUnit call of the script now
 * passes its own memory_limit, as bin/tests-phpstan.sh does for PHPStan
 * (PhpstanMemoryLimitTest).
 */
class PhpunitMemoryLimitTest extends TestCase
{
    /** The coverage run of 10444 tests peaked at 254.5 MB (2026-10-13). */
    private const MEASURED_COVERAGE_PEAK_BYTES = 266862592;

    public function testRegressionEveryPhpunitCallLeavesRoomAboveTheMeasuredPeak(): void
    {
        $calls = $this->phpunitCalls($this->scriptText());
        $this->assertGreaterThanOrEqual(2, count($calls), 'the unit run and the coverage run of bin/tests-phpunit.sh');

        foreach ($calls as $options) {
            $limit = $this->memoryLimitOf($options);
            $this->assertNotNull($limit, "bin/tests-phpunit.sh must pass -d memory_limit to php for: php{$options} vendor/bin/phpunit");
            $this->assertGreaterThanOrEqual(
                2 * self::MEASURED_COVERAGE_PEAK_BYTES,
                $limit,
                'the limit must leave room for the tests to grow above what the coverage run needed'
            );
        }
    }

    public function testTheContainerLimitAloneWouldNotDo(): void
    {
        $ini = (string)file_get_contents(dirname(__DIR__, 3) . '/docker/php/php.ini');
        $this->assertSame(1, preg_match('/^memory_limit\s*=\s*(\S+)/m', $ini, $m));

        $containerLimit = $this->bytes($m[1]);
        $this->assertNotNull($containerLimit);
        $this->assertLessThan(
            2 * self::MEASURED_COVERAGE_PEAK_BYTES,
            $containerLimit,
            'if the container limit leaves this much room, this guard and its comment can go'
        );
    }

    /** The text of bin/tests-phpunit.sh. */
    protected function scriptText(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/bin/tests-phpunit.sh');
    }

    /**
     * The php options of every call of vendor/bin/phpunit in $script, such
     * as " -d pcov.enabled=1 -d memory_limit=1G".
     *
     * @return list<string>
     */
    private function phpunitCalls(string $script): array
    {
        preg_match_all('~\bphp((?:\s+-d\s+\S+)*)\s+vendor/bin/phpunit\b~', $script, $m);

        return array_values($m[1]);
    }

    private function memoryLimitOf(string $options): ?int
    {
        if (preg_match('/-d\s+memory_limit=(\S+)/', $options, $m) !== 1) {
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
