<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;

/**
 * Regression: the released app image ghcr.io/ansilume/ansilume, which
 * .github/workflows/release.yml builds from docker/php/Dockerfile.prod, had
 * no PHP ldap extension up to 2.10.1. The dev image (docker/php/Dockerfile),
 * in which every test runs, has had it since 2.1.11, which brought LDAP
 * sign-in. PhpLdapClient cannot be built without it
 * (PhpLdapClient::EXTENSION_NOT_LOADED), so on installations from the
 * prebuilt images with LDAP enabled every LDAP sign-in, ldap/sync,
 * ldap/test-user and the LDAP diagnose endpoint failed.
 *
 * Every PHP extension the dev image installs (docker-php-ext-install,
 * pecl install, docker-php-ext-enable) is installed by the final stage of
 * the prod image too, unless DEV_ONLY lists it with the reason.
 */
class PhpExtensionParityTest extends TestCase
{
    /** The extensions that only the dev image installs, with the reason. */
    private const DEV_ONLY = [
        'pcov' => 'it measures code coverage for PHPUnit (bin/tests-phpunit.sh), and the prod image runs no tests',
    ];

    private const DEV_DOCKERFILE = 'docker/php/Dockerfile';

    /** A simple command that installs or enables extensions; its arguments are captured. */
    private const INSTALLS = '~(?:^|[\s/])(?:docker-php-ext-install|docker-php-ext-enable|pecl\s+install)\s(.*)$~';

    public function testTheProdImageInstallsEveryExtensionOfTheDevImage(): void
    {
        $dev = $this->extensionsOf(self::DEV_DOCKERFILE);
        // Not vacuous: both ways the dev image installs an extension are read.
        $this->assertContains('ldap', $dev, 'docker-php-ext-install of ' . self::DEV_DOCKERFILE . ' is read');
        $this->assertContains('redis', $dev, 'pecl install of ' . self::DEV_DOCKERFILE . ' is read');

        $prod = $this->prodDockerfile();
        $missing = array_values(array_diff($dev, $this->extensionsOf($prod), array_keys(self::DEV_ONLY)));

        $this->assertSame([], $missing, sprintf(
            '%s (the app image ghcr.io/ansilume/ansilume) lacks PHP extensions that %s installs: %s. '
            . 'Install each in its final stage, or list it in DEV_ONLY with the reason.',
            $prod,
            self::DEV_DOCKERFILE,
            implode(', ', $missing),
        ));
    }

    /**
     * An extension stays on the list only while the dev image installs it,
     * and the prod image does not.
     */
    public function testEveryDevOnlyExtensionIsInstalledByTheDevImageOnly(): void
    {
        $dev = $this->extensionsOf(self::DEV_DOCKERFILE);
        $prod = $this->extensionsOf($this->prodDockerfile());

        foreach (self::DEV_ONLY as $extension => $reason) {
            $this->assertContains($extension, $dev, "{$extension} is not installed by the dev image: drop it from DEV_ONLY");
            $this->assertNotContains($extension, $prod, "{$extension} is dev-only: {$reason}");
        }
    }

    public function testTheParserReadsEveryWayAnImageInstallsAnExtension(): void
    {
        $dockerfile = <<<'DOCKERFILE'
            FROM composer:2.6 AS composer
            RUN docker-php-ext-install of_an_earlier_stage

            FROM php:8.2-fpm
            # RUN docker-php-ext-install commented_out
            RUN docker-php-ext-install -j$(nproc) \
                pdo_mysql \
            # Docker drops comment lines inside an instruction too.
                LDAP \
                && docker-php-ext-configure configured_only --with-jpeg \
                && docker-php-ext-install gd
            RUN pecl install -o -f redis-6.0.2 && docker-php-ext-enable redis
            RUN printf "\n" | pecl install apcu; /usr/local/bin/docker-php-ext-enable opcache || true
            COPY --from=composer /usr/bin/composer /usr/bin/composer
            DOCKERFILE;

        $this->assertSame(['apcu', 'gd', 'ldap', 'opcache', 'pdo_mysql', 'redis'], $this->extensionsIn($dockerfile));
    }

    /**
     * The Dockerfile of the prod image, relative to the repository root.
     */
    protected function prodDockerfile(): string
    {
        return 'docker/php/Dockerfile.prod';
    }

    /**
     * @return list<string>
     */
    private function extensionsOf(string $path): array
    {
        $file = dirname(__DIR__, 3) . '/' . $path;
        $this->assertFileExists($file);

        return $this->extensionsIn((string)file_get_contents($file));
    }

    /**
     * The PHP extensions the final stage of a Dockerfile installs, sorted.
     *
     * @return list<string>
     */
    private function extensionsIn(string $dockerfile): array
    {
        $extensions = [];
        foreach ($this->instructions($dockerfile) as $instruction) {
            if (preg_match('/^FROM\s/i', $instruction) === 1) {
                $extensions = [];
            } elseif (preg_match('/^RUN\s(.*)$/is', $instruction, $match) === 1) {
                $extensions = array_merge($extensions, $this->extensionsOfCommand($match[1]));
            }
        }
        $extensions = array_values(array_unique($extensions));
        sort($extensions);

        return $extensions;
    }

    /**
     * The instructions of a Dockerfile, one line each: comment lines left out,
     * as Docker leaves them out, and continued lines joined.
     *
     * @return list<string>
     */
    private function instructions(string $dockerfile): array
    {
        $lines = preg_split('/\R/', $dockerfile) ?: [];
        $code = array_filter($lines, static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1);
        $joined = (string)preg_replace('/\\\\[ \t]*\n/', ' ', implode("\n", $code));

        return array_values(array_filter(
            array_map('trim', explode("\n", $joined)),
            static fn (string $instruction): bool => $instruction !== ''
        ));
    }

    /**
     * The extensions a RUN instruction's shell command installs, by each of
     * its simple commands.
     *
     * @return list<string>
     */
    private function extensionsOfCommand(string $command): array
    {
        $extensions = [];
        foreach (preg_split('/&&|\|\||[;|]/', $command) ?: [] as $simpleCommand) {
            if (preg_match(self::INSTALLS, $simpleCommand, $match) !== 1) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (!str_starts_with($word, '-')) {
                    // pecl takes a version after the name: redis-6.0.2.
                    $extensions[] = strtolower((string)preg_replace('/-\d[\w.]*$/', '', $word));
                }
            }
        }

        return $extensions;
    }
}
