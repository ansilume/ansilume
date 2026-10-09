<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards .scrutinizer.yml. Scrutinizer only analyses the code; every test
 * suite runs in GitHub Actions. docs/ci.md explains why.
 *
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
        $config = Yaml::parseFile(self::root() . '/.scrutinizer.yml');
        $this->assertIsArray($config);

        return $config;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
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
        $this->assertSame(['php-scrutinizer-run', 'js-scrutinizer-run'], self::commands($config['build']['nodes']['analysis']['tests']['override']));
    }

    /**
     * Regression: Scrutinizer merges the repository settings from its web
     * UI with this file, and a list here replaces the list there. Defining
     * the analysis node here (to stop phpcs-run) silently dropped the web
     * config's js-scrutinizer-run, and the JavaScript analysis stopped on
     * 2026-10-06. This file therefore holds the complete config, and the
     * web settings stay empty.
     */
    public function testThisFileHoldsTheCompleteConfig(): void
    {
        $config = $this->config();
        $nodes = $config['build']['nodes'];

        $this->assertSame(['project_setup', 'tests'], array_keys($nodes['analysis']));
        $this->assertSame(['override' => ['true']], $nodes['analysis']['project_setup']);
        $this->assertSame(['tests'], array_keys($nodes['tests']));
        $this->assertTrue($config['checks']['javascript']);
        $this->assertSame(
            ['around_operators' => ['bitwise' => false, 'concatenation' => true], 'other' => ['after_type_cast' => false]],
            $config['coding_style']['php']['spaces'],
            'no space after a cast (CLAUDE.md), PSR-12 operators'
        );
        foreach (['vendor/*', 'tests/*', 'bin/*', '*.min.js', 'web/js/vendor/*'] as $path) {
            $this->assertContains($path, $config['filter']['excluded_paths']);
        }
    }

    /**
     * Scrutinizer's web UI config enables a "tests" node that runs
     * auto-detected PHPUnit without a database. Only a node of the same
     * name in this file replaces it.
     */
    public function testTheTestNodeReplacesTheWebConfigsTestsNode(): void
    {
        $nodes = $this->config()['build']['nodes'];

        $this->assertSame(['analysis', 'tests'], array_keys($nodes));
    }

    public function testPsr12RunsWithTheProjectsPhpcsAndTheStyleSuiteScope(): void
    {
        $commands = self::commands($this->config()['build']['nodes']['tests']['tests']['override']);
        $phpcs = array_values(array_filter($commands, static fn (string $c): bool => str_starts_with($c, 'vendor/bin/phpcs ')));

        $this->assertCount(1, $phpcs);
        $this->assertStringContainsString('--standard=PSR12', $phpcs[0]);
        $style = (string)file_get_contents(self::root() . '/bin/tests-style.sh');
        $this->assertSame(1, preg_match('/--ignore=(\S+)/', $style, $m));
        $this->assertStringContainsString('--ignore=' . $m[1] . ' ', $phpcs[0], 'same scope as bin/tests-style.sh');
    }

    /**
     * Regression: Scrutinizer ran the Unit suite against its own MySQL 5.7,
     * later MariaDB 10.11, and Redis 7 services. From May 2026 on, those
     * services often accepted connections without ever answering: the run
     * hung until Scrutinizer killed it, and about every second inspection
     * failed while GitHub Actions passed the same commit. A new database
     * image and a bounded wait (2026-10-07) only looked like a fix, because
     * the next two builds happened to get working services; v2.8.0 failed
     * the same way. Six new tests also failed there because Scrutinizer's
     * git is older than the `git init -b` they use. Every test runs in
     * GitHub Actions anyway, so Scrutinizer runs no tests and starts no
     * services.
     */
    public function testScrutinizerRunsNoTestSuiteAndStartsNoServices(): void
    {
        $build = $this->config()['build'];

        $this->assertArrayNotHasKey('services', $build);
        $this->assertSame(['php' => ['version' => '8.2']], $build['environment'], 'no PECL extensions, databases or Redis');
        $commands = self::commands($build['dependencies']['override']);
        foreach ($build['nodes'] as $name => $node) {
            $this->assertSame(['override'], array_keys($node['tests']), "{$name}: no setup steps before or after the checks");
            foreach ($node as $section) {
                foreach ($section as $steps) {
                    $commands = array_merge($commands, self::commands($steps));
                }
            }
        }
        foreach ($commands as $command) {
            $this->assertDoesNotMatchRegularExpression('/phpunit|\byii\b|\.env\b|mysql|mariadb|redis|\bdocker\s/i', $command);
        }
        $this->assertCount(1, $build['nodes']['tests']['tests']['override'], 'the tests node runs PSR-12 only');
    }

    public function testTheConfigPointsToTheCiDocumentation(): void
    {
        $this->assertFileExists(self::root() . '/docs/ci.md');
        $this->assertStringContainsString('docs/ci.md', (string)file_get_contents(self::root() . '/.scrutinizer.yml'));
    }

    /**
     * Regression: an unquoted step containing a colon followed by a space
     * ("$h: no such host") parsed as a mapping, and Scrutinizer aborted the
     * whole inspection with "Config Error: Unrecognized option".
     */
    public function testEveryStepIsACommandScrutinizerAccepts(): void
    {
        $allowed = ['analysis', 'background', 'command', 'coverage', 'cwd', 'environment', 'idle_timeout', 'not_if', 'on_node', 'only_if', 'record_video', 'stop_on_failure', 'title', 'use_website_config'];
        $build = $this->config()['build'];
        $lists = ['dependencies.override' => $build['dependencies']['override']];
        foreach ($build['nodes'] as $name => $node) {
            foreach ($node as $section => $phases) {
                foreach ($phases as $phase => $steps) {
                    $lists["nodes.{$name}.{$section}.{$phase}"] = $steps;
                }
            }
        }

        foreach ($lists as $where => $steps) {
            $this->assertIsArray($steps, $where);
            foreach ($steps as $index => $step) {
                if (is_string($step)) {
                    continue;
                }
                $this->assertIsArray($step, "{$where}.{$index}");
                $this->assertArrayHasKey('command', $step, "{$where}.{$index} is a mapping without a command: " . json_encode($step));
                $this->assertSame([], array_diff(array_keys($step), $allowed), "{$where}.{$index} has options Scrutinizer rejects");
            }
        }
    }

    public function testEveryNodeInstallsTheDependencies(): void
    {
        $build = $this->config()['build'];

        $this->assertContains('composer install --no-interaction --prefer-dist', $build['dependencies']['override']);
        foreach ($build['nodes'] as $name => $node) {
            $this->assertArrayNotHasKey('dependencies', $node, "{$name} inherits the build dependencies");
        }
    }

    /**
     * Regression: Scrutinizer's PHP analyzer predates numeric literal
     * separators. For `2_000_000_000` in commands/E2eCredentialUsageSeeder.php
     * it reported "A parse error occurred: Syntax error, unexpected T_STRING"
     * and skipped the whole file, so nothing in it was analysed.
     */
    public function testAnalysedCodeHasNoNumericLiteralSeparators(): void
    {
        $excluded = $this->config()['filter']['excluded_paths'];
        $this->assertIsArray($excluded);
        $found = [];
        foreach (self::analysedPhpFiles(array_map('strval', $excluded)) as $path) {
            foreach (token_get_all((string)file_get_contents(self::root() . '/' . $path)) as $token) {
                if (is_array($token) && in_array($token[0], [T_LNUMBER, T_DNUMBER], true) && str_contains($token[1], '_')) {
                    $found[] = "{$path}:{$token[2]} {$token[1]}";
                }
            }
        }

        $this->assertSame([], $found, 'Scrutinizer cannot parse numeric literal separators: write 1000000, not 1_000_000');
    }

    public function testTheSeparatorCheckReadsTheApplicationCode(): void
    {
        $files = self::analysedPhpFiles(['vendor/*', 'tests/*']);

        $this->assertContains('services/JobCompletionService.php', $files);
        $this->assertContains('commands/E2eCredentialUsageSeeder.php', $files);
        $this->assertNotContains('tests/unit/config/ScrutinizerConfigTest.php', $files);
    }

    /**
     * PHP files outside Scrutinizer's filter.excluded_paths, relative to the
     * repository root.
     *
     * @param list<string> $excluded
     * @return list<string>
     */
    private static function analysedPhpFiles(array $excluded): array
    {
        $root = self::root();
        $isExcluded = static function (string $path) use ($excluded): bool {
            foreach ($excluded as $pattern) {
                if (fnmatch($pattern, $path)) {
                    return true;
                }
            }

            return false;
        };
        $accept = static function (\SplFileInfo $file) use ($root, $isExcluded): bool {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if ($file->isDir()) {
                return !str_starts_with($file->getFilename(), '.')
                    && $file->getFilename() !== 'node_modules'
                    && !$isExcluded($path . '/');
            }

            return $file->getExtension() === 'php' && !$isExcluded($path);
        };
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            $accept
        ));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);

        return $files;
    }
}
