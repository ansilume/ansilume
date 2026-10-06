<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression: Scrutinizer's managed php_code_sniffer tool installs phpcs
 * 3.7.1, which Composer refuses because of a security advisory
 * (GHSA-hmqg-cxww-wqhq). Every build failed with "phpcs-run", exit 127, before
 * any check ran. PSR-12 must run with the project's own phpcs instead.
 */
class ScrutinizerConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $config = Yaml::parseFile(dirname(__DIR__, 3) . '/.scrutinizer.yml');
        $this->assertIsArray($config);

        return $config;
    }

    public function testScrutinizersManagedPhpcsIsNotUsed(): void
    {
        $this->assertArrayNotHasKey('php_code_sniffer', $this->config()['tools'] ?? []);
    }

    public function testPsr12RunsWithTheProjectsPhpcsAndTheStyleSuiteScope(): void
    {
        $commands = array_map(
            static fn (mixed $step): string => is_array($step) ? (string)($step['command'] ?? '') : (string)$step,
            $this->config()['build']['tests']['override']
        );
        $phpcs = array_values(array_filter($commands, static fn (string $c): bool => str_starts_with($c, 'vendor/bin/phpcs ')));

        $this->assertCount(1, $phpcs);
        $this->assertStringContainsString('--standard=PSR12', $phpcs[0]);
        $style = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/tests-style.sh');
        $this->assertSame(1, preg_match('/--ignore=(\S+)/', $style, $m));
        $this->assertStringContainsString('--ignore=' . $m[1] . ' ', $phpcs[0], 'same scope as bin/tests-style.sh');
    }
}
