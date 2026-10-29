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
 * composer install for the app and runner images run on the php:<line>-cli
 * image of that line too, with the binary of a composer image copied in.
 *
 * Raised from 8.3 to 8.5, as PHP 8.3 gets no security fixes after
 * 2027-12-31 (8.5 after 2029-12-31). Raise OLDEST_LINE with the next step,
 * together with every place this test reads.
 */
class PhpBaseImageTest extends TestCase
{
    /** The oldest PHP line that still gets security fixes and that Ansilume runs on. */
    private const OLDEST_LINE = '8.5';

    /** The Dockerfiles of the images, with the variant of their PHP base image. */
    private const DOCKERFILES = [
        'docker/php/Dockerfile' => 'fpm',
        'docker/php/Dockerfile.prod' => 'fpm',
        'docker/runner/Dockerfile' => 'cli',
    ];

    /** The Dockerfiles that install the dependencies in a build stage of their own. */
    private const INSTALLING_DOCKERFILES = ['docker/php/Dockerfile.prod', 'docker/runner/Dockerfile'];

    /**
     * An image of Composer's own, with or without a tag or digest:
     * composer:2.10, docker.io/library/composer@sha256:…, composer/composer:2.
     */
    private const COMPOSER_IMAGE = '~^(?:docker\.io/)?(?:library/|composer/)?composer(?:[:@]\S+)?$~i';

    /** What composerStageProblems() says about a use of a composer image beyond its binary. */
    private const BINARY_ONLY = 'a composer image gives the composer binary only (COPY --from=composer:<version> /usr/bin/composer /usr/bin/composer), not: ';

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
     * build stage of their own, on the PHP of its image, which refuses a
     * lock that requires a newer line: with every FROM line and the floor
     * raised in step, both release images failed to build, and only the
     * release pipeline would have shown it.
     *
     * Regression: that stage ran FROM composer:2.6, whose PHP 8.3 refuses
     * the floor 8.5, and a newer composer image is no fix: composer:2.10 is
     * built FROM php:8-alpine and moves to PHP 8.6 once that is released
     * (due 2026-11-19), a PHP the lock refuses, as ezyang/htmlpurifier
     * allows at most ~8.5.0. Both images would have stopped building again
     * without a change here, and the PHP line recorded for each composer
     * image could not notice. So the stage runs FROM php:<the line
     * composer.json requires>-cli, exactly that line, and a composer image
     * gives the composer binary only
     * (COPY --from=composer:<version> /usr/bin/composer /usr/bin/composer).
     */
    public function testComposerInstallsOnTheLineComposerRequires(): void
    {
        $line = $this->requiredLine();

        foreach (array_keys(self::DOCKERFILES) as $dockerfile) {
            $this->assertSame([], self::composerStageProblems($this->read($dockerfile), $line), $dockerfile);
        }
        foreach (self::INSTALLING_DOCKERFILES as $dockerfile) {
            $this->assertCount(1, self::stagesThatInstall($this->read($dockerfile)), "{$dockerfile} runs composer install in one build stage");
        }
    }

    /**
     * Scrutinizer's default-jammy build image takes releases such as 8.5.8,
     * while builds of other projects with a plain 8.5 errored (docs/ci.md).
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

    public function testTheStagesThatInstallAreRead(): void
    {
        $dockerfile = "FROM composer:2.6 AS composer\nRUN composer install --no-dev\n\nFROM php:8.9-cli AS build\n"
            . "RUN apt-get update\n  && composer install\n\nFROM php:8.9-fpm\n# RUN composer install\nCOPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer\n";

        $this->assertSame(['composer:2.6', 'php:8.9-cli'], self::stagesThatInstall($dockerfile));
    }

    /**
     * The checks of testComposerInstallsOnTheLineComposerRequires refuse the
     * stage the app and runner images had up to 2.13.1, and a composer image
     * of a newer Composer in its place, and accept the stage they have now,
     * for its own line only.
     */
    public function testTheComposerStageChecksRefuseAComposerImage(): void
    {
        $vendor = "WORKDIR /build\nCOPY composer.json composer.lock ./\n"
            . "RUN composer install --no-dev --no-interaction --no-scripts --optimize-autoloader --prefer-dist\n\n"
            . "FROM php:8.5-fpm\nCOPY --from=composer /build/vendor /var/www/vendor\n";
        $binary = "# The composer image itself runs the newest PHP (it is built FROM php:8-alpine).\n"
            . "COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer\nRUN apt-get update && apt-get install -y unzip \\\n"
            . "    && apt-get clean && rm -rf /var/lib/apt/lists/*\n\n";

        $this->assertSame(
            [
                'composer install runs on composer:2.6, not on php:8.5-cli',
                self::BINARY_ONLY . 'FROM composer:2.6 AS composer',
            ],
            self::composerStageProblems("FROM composer:2.6 AS composer\n\n" . $vendor, '8.5')
        );
        $this->assertSame(
            [
                'composer install runs on composer:2.10, not on php:8.5-cli',
                self::BINARY_ONLY . 'FROM composer:2.10 AS composer',
            ],
            self::composerStageProblems("FROM composer:2.10 AS composer\n\n" . $vendor, '8.5')
        );
        $this->assertSame([], self::composerStageProblems("FROM php:8.5-cli AS composer\n\n" . $binary . $vendor, '8.5'));
        $this->assertSame(
            ['composer install runs on php:8.5-cli, not on php:8.6-cli'],
            self::composerStageProblems("FROM php:8.5-cli AS composer\n\n" . $binary . $vendor, '8.6')
        );
        $this->assertSame(
            ['composer install runs on php:8.5-fpm, not on php:8.5-cli'],
            self::composerStageProblems("FROM php:8.5-fpm AS composer\n\n" . $binary . $vendor, '8.5')
        );
    }

    public function testEveryUseOfAComposerImageBeyondItsBinaryIsFound(): void
    {
        $withStage = "FROM php:8.9-cli AS composer\n"
            . "COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer\n"
            . "copy --chmod=755 --from=composer@sha256:0123abcd /usr/bin/composer /usr/local/bin/composer\n"
            . "COPY --from=composer /build/vendor /var/www/vendor\n"
            . "FROM composer AS second\n"
            . "# FROM composer:2.6\n"
            . "COPY --from=composer:2.10 /tmp /tmp\n"
            . "RUN --mount=type=bind,from=composer:2,target=/c \\\n    /c/usr/bin/composer install\n"
            . "ARG COMPOSER_IMAGE=docker.io/library/composer:2.10\n";
        $withoutStage = "FROM --platform=linux/amd64 composer AS deps\n"
            . "COPY --from=composer /usr/bin/composer /usr/bin/composer\n"
            . "COPY --from=composer /app /app\n"
            . "FROM composer/composer:2 AS other\n";

        $this->assertSame(
            [
                'COPY --from=composer:2.10 /tmp /tmp',
                'RUN --mount=type=bind,from=composer:2,target=/c /c/usr/bin/composer install',
                'ARG COMPOSER_IMAGE=docker.io/library/composer:2.10',
            ],
            self::composerImageUses($withStage),
            'the binary of a composer image and a stage named composer are no finding'
        );
        $this->assertSame(
            [
                'FROM --platform=linux/amd64 composer AS deps',
                'COPY --from=composer /app /app',
                'FROM composer/composer:2 AS other',
            ],
            self::composerImageUses($withoutStage),
            'without a stage of that name, composer is the image composer:latest'
        );
    }

    /** The PHP line of composer.json's "php": ">=8.5" (or "^8.5", "~8.5.0"). */
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
     * What keeps composer install in $dockerfile from running on PHP $line: a
     * stage that runs it on another image than php:<line>-cli, and every use
     * of a composer image beyond its binary.
     *
     * @return list<string>
     */
    private static function composerStageProblems(string $dockerfile, string $line): array
    {
        $problems = [];
        foreach (self::stagesThatInstall($dockerfile) as $image) {
            if ($image !== "php:{$line}-cli") {
                $problems[] = "composer install runs on {$image}, not on php:{$line}-cli";
            }
        }
        foreach (self::composerImageUses($dockerfile) as $instruction) {
            $problems[] = self::BINARY_ONLY . $instruction;
        }

        return $problems;
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

    /**
     * The instructions of $dockerfile that take more from a composer image
     * than its binary: a stage built on one, a copy of another path, a
     * mount, an ARG that names one. A stage named composer is no image.
     *
     * @return list<string>
     */
    private static function composerImageUses(string $dockerfile): array
    {
        $stages = [];
        $uses = [];
        foreach (self::instructions($dockerfile) as $instruction) {
            $composerImages = array_filter(
                self::imagesOf($instruction),
                static fn (string $image): bool => preg_match(self::COMPOSER_IMAGE, $image) === 1 && !in_array(strtolower($image), $stages, true)
            );
            if ($composerImages !== [] && preg_match('~^COPY(?:\s+--\S+)+\s+/usr/bin/composer\s+\S+$~i', $instruction) !== 1) {
                $uses[] = $instruction;
            }
            if (preg_match('/^FROM\s.*\sAS\s+(\S+)$/i', $instruction, $stage) === 1) {
                $stages[] = strtolower($stage[1]);
            }
        }

        return $uses;
    }

    /**
     * The images $instruction names: the base of a FROM line, the source of
     * COPY --from and of a mount (from=), and every image of Composer's own
     * with a tag or digest anywhere in it.
     *
     * @return list<string>
     */
    private static function imagesOf(string $instruction): array
    {
        preg_match('/^FROM\s+(?:--\S+\s+)*(\S+)/i', $instruction, $base);
        preg_match_all('/\bfrom=([^\s,]+)/i', $instruction, $sources);
        preg_match_all('~(?<![\w./-])(?:[\w.-]+/)*composer[:@][^\s,]+~i', $instruction, $tagged);

        return array_values(array_unique(array_merge(array_slice($base, 1), $sources[1], $tagged[0])));
    }

    /**
     * The instructions of $dockerfile without comments and empty lines, each
     * one with its continued lines joined into one line.
     *
     * @return list<string>
     */
    private static function instructions(string $dockerfile): array
    {
        $lines = array_filter(preg_split('/\R/', $dockerfile) ?: [], static fn (string $line): bool => !str_starts_with(ltrim($line), '#'));
        $joined = (string)preg_replace('/[ \t]*\\\\[ \t]*\n\s*/', ' ', implode("\n", $lines));

        return array_values(array_filter(array_map('trim', explode("\n", $joined)), static fn (string $line): bool => $line !== ''));
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
