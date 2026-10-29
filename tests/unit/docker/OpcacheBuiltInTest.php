<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;

/**
 * OPcache is part of PHP 8.5: it is built into PHP and on in PHP-FPM, as it
 * was, so no image installs, enables or loads it. On PHP 8.5
 * `docker-php-ext-install opcache` and `docker-php-ext-enable opcache` fail
 * a build, and `zend_extension=opcache` warns at every start ("Failed
 * loading Zend extension 'opcache'"). docs/updating.md tells images of one's
 * own to remove such lines; this keeps them out of the Dockerfiles and the
 * php.ini files of Ansilume's images and of the deploy role.
 *
 * Moved here from the docs test of the update to PHP 8.5, which read the
 * Dockerfiles for the history of a release (review finding).
 */
class OpcacheBuiltInTest extends TestCase
{
    /** The Dockerfiles of the development, app and runner images. */
    private const DOCKERFILES = ['docker/php/Dockerfile', 'docker/php/Dockerfile.prod', 'docker/runner/Dockerfile'];

    /** The php.ini files of the images and of the deploy role. */
    private const INI_FILES = ['docker/php/php.ini', 'docker/runner/php.ini', 'deploy/roles/ansilume/templates/php.ini'];

    /** An instruction that installs, configures or enables OPcache as an extension of its own. */
    private const INSTALLS = '/\b(?:docker-php-ext-(?:install|configure|enable)|pecl\s+install)\b[^&;|]*opcache/i';

    /** A line of a php.ini, or of an instruction that writes one, that loads OPcache as a Zend extension. */
    private const LOADS = '/^[^;#\r\n]*\bzend_extension[ \t]*=[ \t]*["\']?[^"\'\s]*opcache/im';

    public function testThePhpOfTheImagesHasOpcacheBuiltIn(): void
    {
        $this->assertTrue(extension_loaded('Zend OPcache'), 'OPcache is part of the PHP the tests run on, the PHP of the images.');
    }

    public function testNoDockerfileInstallsOrLoadsOpcache(): void
    {
        foreach (self::DOCKERFILES as $dockerfile) {
            $this->assertSame([], self::opcacheInstructions($this->read($dockerfile)), "{$dockerfile} installs no OPcache of its own.");
        }
    }

    public function testNoPhpIniLoadsOpcache(): void
    {
        foreach (self::INI_FILES as $ini) {
            preg_match_all(self::LOADS, $this->read($ini), $loads);
            $this->assertSame([], $loads[0], "{$ini} loads no OPcache of its own.");
        }
    }

    /**
     * The checks find each line that fails or warns on PHP 8.5, also one
     * continued over lines, and neither a comment nor a setting of OPcache.
     */
    public function testTheChecksFindWhatFailsOnPhp85(): void
    {
        $dockerfile = "FROM php:8.5-fpm\n# OPcache is built in: no docker-php-ext-install opcache\n"
            . "RUN docker-php-ext-install pdo_mysql \\\n    opcache zip\n"
            . "RUN apt-get update && docker-php-ext-enable opcache\n"
            . "RUN docker-php-ext-configure opcache --enable-opcache\n"
            . "RUN pecl install zendopcache\n"
            . "RUN echo 'zend_extension=opcache' > /usr/local/etc/php/conf.d/opcache.ini\n"
            . "RUN echo 'opcache.validate_timestamps=0' > /usr/local/etc/php/conf.d/settings.ini\n"
            . "RUN docker-php-ext-install zip && echo opcache\n";
        $ini = "; zend_extension=opcache\nopcache.enable = 1\nzend_extension=opcache\nzend_extension = \"opcache.so\"\n"
            . "zend_extension=/usr/local/lib/php/extensions/no-debug-non-zts-20250925/opcache.so\nextension=redis\n";

        $this->assertSame(
            [
                'RUN docker-php-ext-install pdo_mysql opcache zip',
                'RUN apt-get update && docker-php-ext-enable opcache',
                'RUN docker-php-ext-configure opcache --enable-opcache',
                'RUN pecl install zendopcache',
                "RUN echo 'zend_extension=opcache' > /usr/local/etc/php/conf.d/opcache.ini",
            ],
            self::opcacheInstructions($dockerfile)
        );
        preg_match_all(self::LOADS, $ini, $loads);
        $this->assertSame(
            ['zend_extension=opcache', 'zend_extension = "opcache', 'zend_extension=/usr/local/lib/php/extensions/no-debug-non-zts-20250925/opcache'],
            $loads[0]
        );
    }

    /**
     * The instructions of $dockerfile, without comments and with their
     * continued lines joined, that install, configure, enable or load OPcache.
     *
     * @return list<string>
     */
    private static function opcacheInstructions(string $dockerfile): array
    {
        $lines = array_filter(preg_split('/\R/', $dockerfile) ?: [], static fn (string $line): bool => !str_starts_with(ltrim($line), '#'));
        $joined = (string)preg_replace('/[ \t]*\\\\[ \t]*\n\s*/', ' ', implode("\n", $lines));
        $instructions = array_map('trim', explode("\n", $joined));

        return array_values(array_filter(
            $instructions,
            static fn (string $instruction): bool => preg_match(self::INSTALLS, $instruction) === 1 || preg_match(self::LOADS, $instruction) === 1
        ));
    }

    private function read(string $file): string
    {
        $text = file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        $this->assertIsString($text, "{$file} cannot be read");

        return $text;
    }
}
