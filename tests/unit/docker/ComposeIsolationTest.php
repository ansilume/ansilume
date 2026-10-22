<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression: every service, the bundled runners included, shared one Docker
 * network. Playbooks run on runners, so a playbook could reach php-fpm
 * (FastCGI without authentication, i.e. PHP code execution in the app
 * container), the database and an unauthenticated Redis whose queue the
 * worker unserialized. Runners must reach nothing but nginx.
 */
class ComposeIsolationTest extends TestCase
{
    use RendersDeployRole;

    /**
     * @return array<string, array{0: string}>
     */
    public static function composeFileProvider(): array
    {
        return [
            'dev' => ['docker-compose.yml'],
            'prebuilt' => ['docker-compose.prebuilt.yml'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compose(string $file): array
    {
        $parsed = Yaml::parseFile(dirname(__DIR__, 3) . '/' . $file);
        $this->assertIsArray($parsed);

        return $parsed;
    }

    /**
     * @return list<string>
     */
    private function networksOf(array $service): array
    {
        $networks = $service['networks'] ?? [];

        return array_values(array_map('strval', array_is_list($networks) ? $networks : array_keys($networks)));
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function deployModeProvider(): array
    {
        return ['build' => [false], 'prebuilt' => [true]];
    }

    /**
     * @dataProvider composeFileProvider
     */
    public function testRunnersOnlyJoinTheRunnersNetwork(string $file): void
    {
        $compose = $this->compose($file);
        $runners = array_filter(
            $compose['services'],
            static fn (string $name): bool => str_starts_with($name, 'runner'),
            ARRAY_FILTER_USE_KEY
        );

        $this->assertNotEmpty($runners);
        foreach ($runners as $name => $service) {
            $this->assertSame(['runners'], $this->networksOf($service), "{$name} must only join 'runners'");
            $this->assertArrayNotHasKey('env_file', $service, "{$name} must not get the server's .env");
            if ($file === 'docker-compose.prebuilt.yml') {
                // Prebuilt runners keep their token in a named volume; the dev
                // compose file bind-mounts the checkout instead.
                $volume = str_replace('-', '_', (string)$name) . '_runtime';
                $this->assertSame([$volume . ':/var/www/runtime'], $service['volumes'] ?? [], "{$name} keeps its token in a named volume");
                $this->assertArrayHasKey($volume, $compose['volumes']);
            }
        }
        $this->assertArrayHasKey('runners', $compose['networks']);
        $this->assertNotTrue($compose['networks']['runners']['internal'] ?? false, 'playbooks need outbound access');
    }

    /**
     * @dataProvider composeFileProvider
     */
    public function testOnlyNginxBridgesBothNetworks(string $file): void
    {
        $compose = $this->compose($file);

        $this->assertSame(['ansilume', 'runners'], $this->networksOf($compose['services']['nginx']));
        foreach ($compose['services'] as $name => $service) {
            if ($name === 'nginx' || str_starts_with((string)$name, 'runner')) {
                continue;
            }
            $this->assertNotContains('runners', $this->networksOf($service), "{$name} must not join 'runners'");
        }
    }

    /**
     * @dataProvider composeFileProvider
     */
    public function testRedisRequiresThePasswordWhenOneIsSet(string $file): void
    {
        $redis = $this->compose($file)['services']['redis'];

        $this->assertSame('${REDIS_PASSWORD:-}', $redis['environment']['REDIS_PASSWORD'] ?? null);
        $this->assertArrayNotHasKey('env_file', $redis);
        $command = implode(' ', (array)$redis['command']);
        $this->assertStringContainsString('redis-server --requirepass "$$REDIS_PASSWORD"', $command);
        $this->assertStringContainsString('if [ -n "$$REDIS_PASSWORD" ]', $command);

        $healthcheck = implode(' ', (array)$redis['healthcheck']['test']);
        $this->assertStringContainsString('REDISCLI_AUTH', $healthcheck, 'the password stays off the redis-cli command line');
        $this->assertStringNotContainsString(' -a ', $healthcheck);
    }

    /**
     * Regression: the prebuilt and deploy schedule-runners never ran
     * maintenance/run, so projects stuck in "syncing" were never recovered.
     *
     * job/reclaim-stale must stay out of the loop until runners report
     * progress while a job runs: today a healthy job that prints nothing for
     * JOB_PROGRESS_TIMEOUT seconds would be failed by the sweep.
     *
     * @dataProvider composeFileProvider
     */
    public function testScheduleRunnerRunsTheSafePeriodicTasks(string $file): void
    {
        $command = implode(' ', (array)$this->compose($file)['services']['schedule-runner']['command']);

        $this->assertStringContainsString('php yii schedule/run && php yii maintenance/run && touch /tmp/schedule-alive', $command);
        $this->assertStringNotContainsString('reclaim-stale', $command);
    }

    public function testDevOnlyPortsBindToLoopbackByDefault(): void
    {
        $services = $this->compose('docker-compose.yml')['services'];

        foreach (['db', 'redis', 'adminer', 'mailhog', 'swagger'] as $name) {
            foreach ((array)$services[$name]['ports'] as $port) {
                $this->assertStringStartsWith('${DEV_BIND_ADDRESS:-127.0.0.1}:', (string)$port, $name);
            }
        }
        $this->assertSame(['${NGINX_PORT:-8080}:80'], $services['nginx']['ports']);
    }

    /**
     * Regression: in the deploy role's default build mode the runners
     * bind-mounted the install directory read-write, so a playbook could read
     * .env and change the PHP code that php-fpm executes.
     *
     * @dataProvider deployModeProvider
     */
    public function testDeployRunnersAreIsolated(bool $prebuilt): void
    {
        $compose = $this->renderDeployTemplate($prebuilt);
        $services = $compose['services'];
        $runners = array_filter($services, static fn (string $n): bool => str_starts_with($n, 'runner'), ARRAY_FILTER_USE_KEY);

        $this->assertCount(2, $runners, 'ansilume_runner_count defaults to 2');
        foreach ($runners as $name => $runner) {
            $this->assertSame(['runners'], $this->networksOf($runner), "{$name} must only join the runner network");
            $volume = str_replace('-', '_', $name) . '_runtime';
            $this->assertSame([$volume . ':/var/www/runtime'], $runner['volumes'] ?? [], "{$name} mounts only its own named volume, nothing from the host");
            $this->assertArrayHasKey($volume, $compose['volumes'] ?? []);
            $this->assertArrayNotHasKey('env_file', $runner, "{$name} must not get the server's .env");
            if (!$prebuilt) {
                $this->assertSame('docker/runner/Dockerfile', $runner['build']['dockerfile'] ?? null, $name);
            }
        }
        $this->assertSame(['ansilume', 'runners'], $this->networksOf($services['nginx']));
        foreach ($services as $name => $service) {
            if ($name !== 'nginx' && !str_starts_with((string)$name, 'runner')) {
                $this->assertNotContains('runners', $this->networksOf($service), "{$name} must not join 'runners'");
            }
        }
    }

    /**
     * The runner image copies its build context; the deploy role renders
     * docker-compose.yaml with secrets as fallback defaults next to .env.
     * A system user whose home is the install directory
     * (ansilume_create_user) keeps the keys and credentials for a private
     * repository there, and a playbook with become reads the runner image.
     */
    public function testRunnerImageBuildContextExcludesSecrets(): void
    {
        $ignored = array_map('trim', (array)file(dirname(__DIR__, 3) . '/.dockerignore'));

        foreach (['.env*', 'docker-compose.yaml', 'docker-compose.override.yml', 'docker-compose.override.yaml', 'compose.override.yml', 'compose.override.yaml', 'release.tar.gz', '.ssh', '.netrc', '.git-credentials', '.gnupg', '.docker', '.ansible', '.config', '.bash_history', '**/*.sql', '**/*.sql.gz', '**/*.bak', '**/*.backup'] as $secret) {
            $this->assertContains($secret, $ignored);
        }
        // Regression found in review: "Before you update" makes .env.backup
        // and backup.sql in the install directory, and the runner image of
        // the deploy role's build mode copied both in.
        foreach (['.env', '.env.backup', '.env.prod.example', 'backup.sql', 'dumps/ansilume.sql.gz'] as $file) {
            $this->assertTrue(self::ignored($file, $ignored), "{$file} stays out of the build context");
        }
        $this->assertFalse(self::ignored('composer.json', $ignored));
    }

    /**
     * Whether a .dockerignore line excludes $path: Docker matches each
     * pattern against the whole path, "**" for any number of directories.
     *
     * @param list<string> $patterns
     */
    private static function ignored(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '' || str_starts_with($pattern, '#') || str_starts_with($pattern, '!')) {
                continue;
            }
            $regex = '#^' . strtr(preg_quote($pattern, '#'), ['\\*\\*/' => '(?:.*/)?', '\\*' => '[^/]*']) . '(?:/.*)?$#';
            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Regression: in prebuilt mode nginx could not serve the assets the app
     * publishes, and the web UI did not see projects the queue-worker synced.
     */
    public function testDeployPrebuiltModeSharesAssetsAndProjects(): void
    {
        $compose = $this->renderDeployTemplate(true);
        $services = $compose['services'];

        $this->assertSame(['runtime_projects:/var/www/runtime/projects', 'web_assets:/var/www/web/assets'], $services['app']['volumes']);
        $this->assertSame(['web_assets:/var/www/web/assets:ro'], $services['nginx']['volumes']);
        $this->assertSame(['runtime_projects:/var/www/runtime/projects'], $services['queue-worker']['volumes']);
        $this->assertArrayHasKey('runtime_projects', $compose['volumes']);
        $this->assertArrayHasKey('web_assets', $compose['volumes']);
    }

    /**
     * @dataProvider deployModeProvider
     */
    public function testDeployRedisAndScheduleRunner(bool $prebuilt): void
    {
        $services = $this->renderDeployTemplate($prebuilt)['services'];

        $this->assertSame('${REDIS_PASSWORD:-}', $services['redis']['environment']['REDIS_PASSWORD'] ?? null);
        $this->assertStringContainsString('--requirepass "$$REDIS_PASSWORD"', implode(' ', $services['redis']['command']));
        $this->assertStringContainsString('REDISCLI_AUTH', implode(' ', $services['redis']['healthcheck']['test']));

        $loop = implode(' ', $services['schedule-runner']['command']);
        $this->assertStringContainsString('php yii schedule/run && php yii maintenance/run && touch /tmp/schedule-alive', $loop);
        $this->assertStringNotContainsString('reclaim-stale', $loop);
    }

    public function testDeployEnvTemplatePassesTheRedisPassword(): void
    {
        $env = (string)file_get_contents(dirname(__DIR__, 3) . '/deploy/roles/ansilume/templates/env');

        $this->assertStringContainsString('REDIS_PASSWORD={{ ansilume_redis_password }}', $env);
        $this->assertStringNotContainsString('RUNNER_MODE', $env);
    }
}
