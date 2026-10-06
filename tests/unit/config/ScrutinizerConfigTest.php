<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression: Scrutinizer's phpcs-run installs phpcs 3.7.1 when it finds no
 * phpcs of its own, and Composer refuses that version because of a security
 * advisory (GHSA-hmqg-cxww-wqhq). Every build failed with "phpcs-run", exit
 * 127, before any check ran. Removing the php_code_sniffer tool was not
 * enough: the default analysis node runs phpcs-run as well. The analysis
 * node must be defined explicitly, and PSR-12 must run with the project's
 * own phpcs.
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

    /**
     * @param list<mixed> $steps
     * @return list<string>
     */
    private static function commands(array $steps): array
    {
        return array_map(
            static fn (mixed $step): string => is_array($step) ? (string)($step['command'] ?? '') : (string)$step,
            $steps
        );
    }

    public function testScrutinizersManagedPhpcsNeverRuns(): void
    {
        $config = $this->config();

        $this->assertArrayNotHasKey('php_code_sniffer', $config['tools'] ?? []);
        $this->assertSame(['php-scrutinizer-run'], self::commands($config['build']['nodes']['analysis']['tests']['override']));
    }

    public function testPsr12RunsWithTheProjectsPhpcsAndTheStyleSuiteScope(): void
    {
        $commands = self::commands($this->config()['build']['nodes']['phpunit']['tests']['override']);
        $phpcs = array_values(array_filter($commands, static fn (string $c): bool => str_starts_with($c, 'vendor/bin/phpcs ')));

        $this->assertCount(1, $phpcs);
        $this->assertStringContainsString('--standard=PSR12', $phpcs[0]);
        $style = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/tests-style.sh');
        $this->assertSame(1, preg_match('/--ignore=(\S+)/', $style, $m));
        $this->assertStringContainsString('--ignore=' . $m[1] . ' ', $phpcs[0], 'same scope as bin/tests-style.sh');
        $this->assertContains('vendor/bin/phpunit --testsuite=Unit --colors=never --coverage-clover clover.xml', $commands);
    }

    public function testEveryNodeInstallsTheDependencies(): void
    {
        $build = $this->config()['build'];

        $this->assertContains('composer install --no-interaction --prefer-dist', $build['dependencies']['override']);
        foreach ($build['nodes'] as $name => $node) {
            $this->assertArrayNotHasKey('dependencies', $node, "{$name} inherits the build dependencies");
        }
    }
}
