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
     * Renders the deploy role's compose template with the role defaults, the
     * way Ansible would, and returns the parsed result.
     *
     * @return array<string, mixed>
     */
    private function renderDeployTemplate(bool $prebuilt): array
    {
        if ($this->python(['-c', 'import jinja2, yaml'])['rc'] !== 0) {
            $this->markTestSkipped('python3 with jinja2 and PyYAML is needed to render the deploy template.');
        }
        $script = <<<'PY'
            import json, sys, yaml, jinja2
            role = sys.argv[1]
            variables = yaml.safe_load(open(role + '/defaults/main.yaml')) or {}
            variables.update({'ansible_managed': 'Ansible managed', 'ansilume_use_prebuilt_images': sys.argv[2] == '1'})
            template = jinja2.Environment(undefined=jinja2.StrictUndefined).from_string(open(role + '/templates/docker-compose.yaml').read())
            json.dump(yaml.safe_load(template.render(**variables)), sys.stdout)
            PY;
        $result = $this->python(['-c', $script, dirname(__DIR__, 3) . '/deploy/roles/ansilume', $prebuilt ? '1' : '0']);
        $this->assertSame(0, $result['rc'], $result['err']);
        $compose = json_decode($result['out'], true);
        $this->assertIsArray($compose);

        return $compose;
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
     */
    public function testRunnerImageBuildContextExcludesSecrets(): void
    {
        $ignored = array_map('trim', (array)file(dirname(__DIR__, 3) . '/.dockerignore'));

        $this->assertContains('.env', $ignored);
        $this->assertContains('docker-compose.yaml', $ignored);
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
