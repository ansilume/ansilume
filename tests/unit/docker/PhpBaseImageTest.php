<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every image runs the PHP line that composer.json requires, and Scrutinizer
 * analyses the code with a release of that line: the development image
 * (docker/php/Dockerfile, which the development stack, source installations
 * and the deploy role build), the app image (docker/php/Dockerfile.prod,
 * ghcr.io/ansilume/ansilume) and the runner image (docker/runner/Dockerfile,
 * ghcr.io/ansilume/ansilume-runner). A line that differs fails one of them:
 * composer install refuses an older PHP, and on a newer one the code would
 * be tested with a PHP it does not require. The build stages that run
 * composer install for the app and runner images need a PHP the floor
 * allows as well.
 *
 * Raised from 8.2 to 8.3, as PHP 8.2 gets no security fixes after
 * 2026-12-31 (8.3 after 2027-12-31). Raise OLDEST_LINE with the next step,
 * together with every place this test reads.
 */
class PhpBaseImageTest extends TestCase
{
    /** The oldest PHP line that still gets security fixes and that Ansilume runs on. */
    private const OLDEST_LINE = '8.3';

    /** The Dockerfiles of the images, with the variant of their PHP base image. */
    private const DOCKERFILES = [
        'docker/php/Dockerfile' => 'fpm',
        'docker/php/Dockerfile.prod' => 'fpm',
        'docker/runner/Dockerfile' => 'cli',
    ];

    /**
     * The PHP line of each composer image that a build stage runs composer
     * install on (docker run --rm --entrypoint php composer:2.6 -v: 8.3.10).
     * A new composer image goes here with its line.
     */
    private const COMPOSER_IMAGE_PHP = ['composer:2.6' => '8.3'];

    public function testComposerRequiresALineThatGetsSecurityFixes(): void
    {
        $line = $this->requiredLine();

        $this->assertTrue(
            version_compare($line, self::OLDEST_LINE, '>='),
            "composer.json allows PHP {$line}, older than " . self::OLDEST_LINE
        );
    }

    public function testEveryImageRunsTheLineComposerRequires(): void
    {
        $line = $this->requiredLine();

        foreach (self::DOCKERFILES as $dockerfile => $variant) {
            $this->assertSame(
                "php:{$line}-{$variant}",
                self::finalBaseIn($this->read($dockerfile)),
                "The final stage of {$dockerfile} runs PHP {$line}, the line composer.json requires"
            );
        }
    }

    public function testTheLockRequiresTheSameLine(): void
    {
        $lock = json_decode($this->read('composer.lock'), true);
        $this->assertIsArray($lock);

        $this->assertSame('>=' . $this->requiredLine(), $lock['platform']['php'] ?? null, 'run composer update --lock after changing require.php');
    }

    /**
     * Review finding: the app and runner images run composer install in a
     * build stage of their own, on the PHP of its composer image, which
     * refuses a lock that requires a newer line: with every FROM line and
     * the floor raised in step, both release images failed to build, and
     * only the release pipeline would have shown it.
     */
    public function testComposerInstallsOnAPhpTheFloorAllows(): void
    {
        $line = $this->requiredLine();

        foreach (array_keys(self::DOCKERFILES) as $dockerfile) {
            foreach (self::stagesThatInstall($this->read($dockerfile)) as $image) {
                $stageLine = self::phpLineOf($image);
                $this->assertNotNull($stageLine, "{$dockerfile}: record the PHP line of {$image} in COMPOSER_IMAGE_PHP (docker run --rm --entrypoint php {$image} -v)");
                $this->assertTrue(
                    version_compare($stageLine, $line, '>='),
                    "{$dockerfile}: composer install runs on {$image} with PHP {$stageLine}, which refuses the floor {$line}"
                );
            }
        }
    }

    /**
     * Scrutinizer's build image takes releases such as 8.3.3, while builds of
     * other projects with a plain 8.3 errored (docs/ci.md).
     */
    public function testScrutinizerAnalysesWithAReleaseOfTheLine(): void
    {
        $config = Yaml::parseFile($this->path('.scrutinizer.yml'));
        $this->assertIsArray($config);
        $version = (string)($config['build']['environment']['php']['version'] ?? '');

        $this->assertMatchesRegularExpression('/^' . preg_quote($this->requiredLine(), '/') . '\.\d+$/', $version);
    }

    public function testTheDocsNameTheLine(): void
    {
        $line = $this->requiredLine();

        $this->assertStringContainsString("badge/PHP-{$line}-", $this->read('README.md'));
        $this->assertStringContainsString("**PHP {$line}+**", $this->read('CLAUDE.md'));
        $this->assertStringContainsString("- PHP {$line}+ with extensions", $this->read('docs/runners.md'));
        $this->assertStringContainsString("Docker Hub (`php:{$line}-fpm`", $this->read('docs/updating.md'));
    }

    public function testTheBaseOfTheFinalStageIsRead(): void
    {
        $this->assertSame(
            'php:8.9-cli',
            self::finalBaseIn("FROM composer:2.6 AS composer\nRUN true\n\nfrom php:8.9-cli\n# FROM php:7.4-cli\n")
        );
    }

    public function testTheStagesThatInstallAndTheirPhpAreRead(): void
    {
        $dockerfile = "FROM composer:2.6 AS composer\nRUN composer install --no-dev\n\nFROM php:8.9-cli AS build\n"
            . "RUN apt-get update\n  && composer install\n\nFROM php:8.9-fpm\n# RUN composer install\nCOPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer\n";

        $this->assertSame(['composer:2.6', 'php:8.9-cli'], self::stagesThatInstall($dockerfile));
        $this->assertSame('8.3', self::phpLineOf('composer:2.6'));
        $this->assertSame('8.9', self::phpLineOf('php:8.9-cli'));
        $this->assertNull(self::phpLineOf('composer:2.9'));
    }

    /** The PHP line of composer.json's "php": ">=8.3" (or "^8.3", "~8.3.0"). */
    private function requiredLine(): string
    {
        $composer = json_decode($this->read('composer.json'), true);
        $this->assertIsArray($composer);
        $constraint = (string)($composer['require']['php'] ?? '');

        $this->assertSame(1, preg_match('/^(?:>=|\^|~)?(\d+\.\d+)(?:\.0)?$/', $constraint, $match), 'composer.json requires one PHP line');

        return $match[1];
    }

    private static function finalBaseIn(string $dockerfile): string
    {
        preg_match_all('/^\s*FROM\s+(\S+)/mi', $dockerfile, $from);

        return (string)end($from[1]);
    }

    /**
     * The base images of the stages of $dockerfile that run composer install.
     *
     * @return list<string>
     */
    private static function stagesThatInstall(string $dockerfile): array
    {
        $images = [];
        foreach (preg_split('/^(?=\s*FROM\s)/mi', $dockerfile) ?: [] as $stage) {
            $lines = array_filter(preg_split('/\R/', $stage) ?: [], static fn (string $line): bool => !str_starts_with(ltrim($line), '#'));
            if (preg_match('/^\s*FROM\s+(\S+)/i', $stage, $from) === 1 && str_contains(implode("\n", $lines), 'composer install')) {
                $images[] = $from[1];
            }
        }

        return $images;
    }

    /** The PHP line of $image: php:8.3-cli gives 8.3, a composer image its line in COMPOSER_IMAGE_PHP. */
    private static function phpLineOf(string $image): ?string
    {
        if (preg_match('/^php:(\d+\.\d+)-/', $image, $match) === 1) {
            return $match[1];
        }

        return self::COMPOSER_IMAGE_PHP[$image] ?? null;
    }

    private function read(string $file): string
    {
        $text = file_get_contents($this->path($file));
        $this->assertIsString($text, "{$file} cannot be read");

        return $text;
    }

    private function path(string $file): string
    {
        return dirname(__DIR__, 3) . '/' . $file;
    }
}
