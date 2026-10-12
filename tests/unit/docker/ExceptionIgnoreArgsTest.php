<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every PHP process of Ansilume's images runs with
 * zend.exception_ignore_args = On, which leaves function arguments out of
 * exception traces. #[\SensitiveParameter] keeps the values of Ansilume's
 * own parameters out, but frames of PHP's built-in functions and of Yii
 * still printed theirs: the first 15 characters of each in app.log, all of
 * them on the debug error page. Among them were file_put_contents() of a
 * credential's temporary file, Security::validatePassword() and Redis AUTH.
 *
 * Regression: php.ini-production sets it, but the images load no such file,
 * and none of their ini files set it: PHP in the app container had it off.
 *
 * Where each PHP service gets the setting:
 * - docker/php/php.ini, bind-mounted at conf.d/local.ini by every service
 *   built from docker/php/Dockerfile (the dev compose file, and the deploy
 *   role's build mode, where the role renders it from templates/php.ini);
 * - the server image ghcr.io/ansilume/ansilume, which docker/php/Dockerfile.prod
 *   builds with a copy of docker/php/php.ini;
 * - the runner image ghcr.io/ansilume/ansilume-runner, which
 *   docker/runner/Dockerfile builds with a copy of docker/runner/php.ini.
 */
class ExceptionIgnoreArgsTest extends TestCase
{
    private const SETTING = 'zend.exception_ignore_args';

    /** The directory PHP's official images scan for ini files. */
    private const CONF_D = '/usr/local/etc/php/conf.d/';

    private const MOUNT = './docker/php/php.ini:/usr/local/etc/php/conf.d/local.ini';

    private const FROM_MOUNT = 'docker/php/php.ini, bind-mounted';
    private const FROM_SERVER_IMAGE = 'server image: docker/php/Dockerfile.prod copies docker/php/php.ini';
    private const FROM_RUNNER_IMAGE = 'runner image: docker/runner/Dockerfile copies docker/runner/php.ini';
    private const FROM_NOWHERE = 'nowhere';

    public function testTheServerIniSetsIt(): void
    {
        $this->assertSetsIt($this->read('docker/php/php.ini'), 'docker/php/php.ini');
    }

    public function testTheDeployRolesIniSetsIt(): void
    {
        $this->assertSetsIt($this->renderDeployTemplate('php.ini', false), 'deploy/roles/ansilume/templates/php.ini');
    }

    public function testTheServerImageCopiesTheServerIni(): void
    {
        $this->assertContains(
            ['docker/php/php.ini', self::CONF_D . 'local.ini'],
            $this->copiesOfTheFinalStage('docker/php/Dockerfile.prod')
        );
    }

    /**
     * The runner image has its own ini file: docker/php/php.ini sets
     * error_log, which would move the runner's PHP errors from stderr (the
     * container log) into a file inside the container.
     */
    public function testTheRunnerImageCopiesItsOwnIni(): void
    {
        $ini = $this->read('docker/runner/php.ini');
        $this->assertSetsIt($ini, 'docker/runner/php.ini');
        $this->assertArrayNotHasKey('error_log', $this->parseIni($ini), 'the runner logs PHP errors to stderr');

        $destinations = [];
        foreach ($this->copiesOfTheFinalStage('docker/runner/Dockerfile') as [$source, $destination]) {
            $this->assertNotSame('docker/php/php.ini', $source, 'the runner image must not copy docker/php/php.ini');
            if ($source === 'docker/runner/php.ini') {
                $destinations[] = $destination;
            }
        }
        $this->assertCount(1, $destinations, 'docker/runner/Dockerfile copies docker/runner/php.ini once');
        $this->assertMatchesRegularExpression(
            '#^' . preg_quote(self::CONF_D, '#') . '[\w.-]+\.ini$#',
            $destinations[0],
            'PHP loads only the *.ini files of conf.d'
        );
    }

    /**
     * @return array<string, array{0: string|null, 1: bool, 2: array<string, string>}>
     */
    public static function composeProvider(): array
    {
        $servers = ['app', 'queue-worker', 'schedule-runner'];
        $runners = ['runner-1', 'runner-2'];
        $mounted = array_fill_keys($servers, self::FROM_MOUNT);
        $images = array_fill_keys($servers, self::FROM_SERVER_IMAGE);
        $runnerImages = array_fill_keys($runners, self::FROM_RUNNER_IMAGE);
        // The dev runners use the server's dev image and mount its ini.
        $devRunners = array_fill_keys($runners, self::FROM_MOUNT);
        $prebuiltRunner = ['runner-1' => self::FROM_RUNNER_IMAGE];

        return [
            'docker-compose.yml' => ['docker-compose.yml', false, $mounted + $devRunners],
            'docker-compose.prebuilt.yml' => ['docker-compose.prebuilt.yml', false, $images + $prebuiltRunner],
            'deploy role, build mode' => [null, false, $mounted + $runnerImages],
            'deploy role, prebuilt images' => [null, true, $images + $runnerImages],
        ];
    }

    /**
     * Every service that runs Ansilume's PHP gets the setting; a service
     * that runs PHP from anywhere else fails the test.
     *
     * @dataProvider composeProvider
     * @param array<string, string> $expected
     */
    public function testEveryPhpServiceGetsIt(?string $file, bool $prebuilt, array $expected): void
    {
        $yaml = $file === null ? $this->renderDeployTemplate('docker-compose.yaml', $prebuilt) : $this->read($file);
        $compose = Yaml::parse($yaml);
        $this->assertIsArray($compose);
        $this->assertIsArray($compose['services'] ?? null);

        $sources = [];
        foreach ($compose['services'] as $name => $service) {
            $this->assertIsArray($service, (string)$name);
            $source = $this->settingSource($service);
            if ($source !== null) {
                $sources[(string)$name] = $source;
            }
        }
        ksort($sources);
        ksort($expected);

        $this->assertSame($expected, $sources);
    }

    private function assertSetsIt(string $ini, string $file): void
    {
        // PHP's normal ini scanner, as for php.ini: On, 1, yes and true read as "1".
        $value = $this->parseIni($ini)[self::SETTING] ?? null;
        $this->assertSame('1', $value, $file . ' sets ' . self::SETTING . ' = On');
    }

    /**
     * Where $service gets the setting from, or null when it runs no PHP.
     *
     * @param array<mixed> $service
     */
    private function settingSource(array $service): ?string
    {
        $build = $service['build'] ?? null;
        $dockerfile = is_array($build) ? (string)($build['dockerfile'] ?? 'Dockerfile') : null;
        if ($dockerfile === 'docker/php/Dockerfile') {
            $mounted = in_array(self::MOUNT, (array)($service['volumes'] ?? []), true);

            return $mounted ? self::FROM_MOUNT : self::FROM_NOWHERE;
        }
        $source = $this->imageSource($dockerfile, (string)($service['image'] ?? ''));
        if ($source === null && $this->runsPhp($service)) {
            return self::FROM_NOWHERE;
        }

        return $source;
    }

    private function imageSource(?string $dockerfile, string $image): ?string
    {
        if ($dockerfile === 'docker/php/Dockerfile.prod' || str_starts_with($image, 'ghcr.io/ansilume/ansilume:')) {
            return self::FROM_SERVER_IMAGE;
        }
        $runnerImage = str_starts_with($image, 'ghcr.io/ansilume/ansilume-runner:');
        if ($dockerfile === 'docker/runner/Dockerfile' || $runnerImage) {
            return self::FROM_RUNNER_IMAGE;
        }

        return null;
    }

    /**
     * A service of another image that starts PHP: an official PHP image, or
     * php in its entrypoint, command or health check.
     *
     * @param array<mixed> $service
     */
    private function runsPhp(array $service): bool
    {
        if (str_starts_with((string)($service['image'] ?? ''), 'php:')) {
            return true;
        }
        $healthcheck = $service['healthcheck'] ?? [];
        $parts = [$service['entrypoint'] ?? [], $service['command'] ?? []];
        $parts[] = is_array($healthcheck) ? ($healthcheck['test'] ?? []) : [];
        $words = [];
        foreach ($parts as $part) {
            foreach ((array)$part as $word) {
                $words[] = (string)$word;
            }
        }

        return preg_match('/\bphp\b/', implode(' ', $words)) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseIni(string $ini): array
    {
        $parsed = parse_ini_string($ini);
        $this->assertIsArray($parsed, 'the ini file parses');

        return $parsed;
    }

    /**
     * The COPY instructions of the image's last stage, as [source, destination].
     *
     * @return list<array{0: string, 1: string}>
     */
    private function copiesOfTheFinalStage(string $dockerfile): array
    {
        $lines = preg_split('/\R/', $this->read($dockerfile));
        $this->assertIsArray($lines);
        $copies = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*FROM\s/i', $line) === 1) {
                $copies = [];
            } elseif (preg_match('/^\s*COPY\s+(?:--\S+\s+)*(\S+)\s+(\S+)\s*$/i', $line, $match) === 1) {
                $copies[] = [$match[1], $match[2]];
            }
        }

        return $copies;
    }

    private function read(string $path): string
    {
        $file = $this->root() . '/' . $path;
        $this->assertFileExists($file);

        return (string)file_get_contents($file);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * A template of the deploy role, rendered with the role's defaults the
     * way Ansible renders it.
     */
    private function renderDeployTemplate(string $template, bool $prebuilt): string
    {
        $script = <<<'PY'
            import sys, yaml, jinja2
            role, template, prebuilt = sys.argv[1], sys.argv[2], sys.argv[3] == '1'
            variables = yaml.safe_load(open(role + '/defaults/main.yaml')) or {}
            variables['ansible_managed'] = 'Ansible managed'
            variables['ansilume_use_prebuilt_images'] = prebuilt
            environment = jinja2.Environment(undefined=jinja2.StrictUndefined, keep_trailing_newline=True)
            source = open(role + '/templates/' + template).read()
            sys.stdout.write(environment.from_string(source).render(**variables))
            PY;
        if ($this->python(['-c', 'import jinja2, yaml'])['rc'] !== 0) {
            $this->markTestSkipped('python3 with jinja2 and PyYAML is needed to render the deploy role templates.');
        }
        $role = $this->root() . '/deploy/roles/ansilume';
        $result = $this->python(['-c', $script, $role, $template, $prebuilt ? '1' : '0']);
        $this->assertSame(0, $result['rc'], $result['err']);

        return $result['out'];
    }

    /**
     * @param list<string> $args
     * @return array{rc: int, out: string, err: string}
     */
    private function python(array $args): array
    {
        $process = proc_open(['python3', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return ['rc' => 127, 'out' => '', 'err' => 'python3 not available'];
        }
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['rc' => proc_close($process), 'out' => $out, 'err' => $err];
    }
}
