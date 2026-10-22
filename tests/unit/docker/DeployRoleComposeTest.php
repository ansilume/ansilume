<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The deploy role (deploy/roles/ansilume) renders its own compose file,
 * docker-compose.yaml, into the install directory and starts the stack from
 * it.
 *
 * Regression: in a git checkout, the default mode, the install directory
 * also holds the repository's docker-compose.yml, and Compose prefers
 * docker-compose.yml to docker-compose.yaml. The role started the
 * development stack: runners with the install directory, .env included,
 * mounted read-write, mailhog and swagger, and without the internal-db
 * profile no database at all. Now it names its files and removes the
 * containers of the project that they do not have. Its prebuilt mode
 * pulled images tagged with the git tag (v2.12.1) or the branch (main),
 * tags the release never pushes: the images are tagged 2.12.1.
 *
 * Found by the review of that change: with an external database and an
 * external Redis the rendered file was invalid (an empty depends_on), a new
 * installation from git never got past the clone (the role had created
 * docker/ in the install directory first), an operator's
 * docker-compose.override.yml would no longer have been read, and an
 * unquoted ansilume_version such as 2.10 is the number 2.1.
 */
class DeployRoleComposeTest extends TestCase
{
    use RendersDeployRole;

    private const ROLE = 'deploy/roles/ansilume';

    private const FILES = "{{ ansilume_compose_files | default(['docker-compose.yaml']) }}";

    public function testRegressionTheRoleStartsTheStackFromItsRenderedFile(): void
    {
        $tasks = [
            ...$this->tasks('tasks/stack.yaml'),
            ...$this->tasks('handlers/main.yaml'),
        ];
        $compose = array_values(array_filter($tasks, static fn (array $task): bool => isset($task['community.docker.docker_compose_v2'])));

        $this->assertCount(2, $compose, 'The stack task and the restart handler.');
        foreach ($compose as $task) {
            $module = $task['community.docker.docker_compose_v2'];
            $this->assertSame('{{ ansilume_install_dir }}', $module['project_src'] ?? null, (string)$task['name']);
            $this->assertSame(self::FILES, $module['files'] ?? null, (string)$task['name']);
            $this->assertTrue($module['remove_orphans'] ?? null, (string)$task['name'] . ': removes what the development stack left behind');
        }
        $rendered = array_values(array_filter(
            $this->tasks('tasks/config.yaml'),
            static fn (array $task): bool => ($task['ansible.builtin.template']['src'] ?? null) === 'docker-compose.yaml',
        ));
        $this->assertSame('{{ ansilume_install_dir }}/docker-compose.yaml', $rendered[0]['ansible.builtin.template']['dest'] ?? null);
    }

    /** The override names Compose looks for, in its order. */
    private const OVERRIDES = ['compose.override.yml', 'compose.override.yaml', 'docker-compose.override.yml', 'docker-compose.override.yaml'];

    /**
     * @return array<string, array{0: list<string>, 1: list<string>}>
     */
    public static function overrideProvider(): array
    {
        return [
            'no override' => [[], ['docker-compose.yaml']],
            'docker-compose.override.yml' => [['docker-compose.override.yml'], ['docker-compose.yaml', 'docker-compose.override.yml']],
            'docker-compose.override.yaml, next to the rendered file' => [['docker-compose.override.yaml'], ['docker-compose.yaml', 'docker-compose.override.yaml']],
            'compose.override.yml' => [['compose.override.yml'], ['docker-compose.yaml', 'compose.override.yml']],
            'two of them: the first, as Compose picks it' => [['docker-compose.override.yml', 'compose.override.yaml'], ['docker-compose.yaml', 'compose.override.yaml']],
        ];
    }

    /**
     * An override next to the rendered file (labels for a TLS proxy, say)
     * stays part of the stack: with named files Compose reads no other, and
     * remove_orphans would remove its services. Regression found in review:
     * only docker-compose.override.yml was kept.
     *
     * @dataProvider overrideProvider
     * @param list<string> $present
     * @param list<string> $files
     */
    public function testAnOverrideOfTheOperatorStaysPartOfTheStack(array $present, array $files): void
    {
        $tasks = $this->tasks('tasks/main.yaml');
        $stat = $this->task($tasks, 'Look for a compose override');
        $names = $this->task($tasks, 'Name the compose files of the stack');

        $this->assertSame('{{ ansilume_install_dir }}/{{ item }}', $stat['ansible.builtin.stat']['path'] ?? null);
        $this->assertSame(self::OVERRIDES, $stat['loop'] ?? null);
        $this->assertSame('ansilume_compose_overrides', $stat['register'] ?? null);
        $this->assertSame(['always'], $stat['tags'] ?? null);
        $this->assertSame(['always'], $names['tags'] ?? null);
        $results = array_map(static fn (string $name): array => ['item' => $name, 'stat' => ['exists' => in_array($name, $present, true)]], self::OVERRIDES);
        $expression = trim((string)($names['ansible.builtin.set_fact']['ansilume_compose_files'] ?? ''));
        $this->assertSame($files, json_decode(
            $this->renderDeployText('{{ (' . trim(substr($expression, 2, -2)) . ') | to_json }}', ['ansilume_compose_overrides' => ['results' => $results]]),
            true,
        ));
        $this->assertSame(
            "\nCOMPOSE_FILE=" . implode(':', array_map(static fn (string $file): string => '/srv/ansilume/' . $file, $files)) . "\n",
            $this->line($this->renderDeployFile('env', ['ansilume_install_dir' => '/srv/ansilume', 'ansilume_compose_files' => $files]), 'COMPOSE_FILE='),
        );
    }

    /**
     * docker compose run by hand in the install directory (logs, ps, exec)
     * reads COMPOSE_FILE and COMPOSE_PROJECT_NAME from .env. The files are
     * absolute: Compose resolves a relative one against the working
     * directory, not against the directory of .env.
     */
    public function testManualComposeCommandsUseTheRenderedFile(): void
    {
        $env = $this->renderDeployFile('env', ['ansilume_install_dir' => '/srv/ansilume', 'ansilume_compose_project' => 'automation']);

        $this->assertStringContainsString("\nCOMPOSE_FILE=/srv/ansilume/docker-compose.yaml\n", $env);
        $this->assertStringContainsString("\nCOMPOSE_PROJECT_NAME=automation\n", $env);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function imageTagProvider(): array
    {
        return [
            'git tag' => ['v2.12.2', '2.12.2'],
            'version, as the docs said before' => ['2.12.1', '2.12.1'],
            'floating minor tag' => ['2.12', '2.12'],
            'floating major tag' => ['v2', '2'],
            'release candidate' => ['v2.13.0-rc1', '2.13.0-rc1'],
            'default branch' => ['main', 'latest'],
            'other branch' => ['feature/v2', 'latest'],
            'commit that starts with a digit' => ['1aeeee0e1e58b4f0a2e97726a67442594203aef5', 'latest'],
            'short commit of digits only' => ['1234567', 'latest'],
        ];
    }

    /**
     * Regression: ghcr.io/ansilume/ansilume:v2.12.1 and :main do not exist.
     *
     * @dataProvider imageTagProvider
     */
    public function testRegressionPrebuiltImagesHaveTheTagsTheReleasePushes(string $version, string $tag): void
    {
        $services = $this->renderDeployTemplate(true, ['ansilume_version' => $version])['services'];

        $images = [];
        foreach ($services as $name => $service) {
            $image = (string)($service['image'] ?? '');
            if (str_starts_with($image, 'ghcr.io/ansilume/')) {
                $images[$name] = $image;
            }
            $this->assertArrayNotHasKey('build', $service, "{$name} is not built in prebuilt mode");
        }
        $this->assertSame([
            'app' => 'ghcr.io/ansilume/ansilume:' . $tag,
            'nginx' => 'ghcr.io/ansilume/ansilume-nginx:' . $tag,
            'schedule-runner' => 'ghcr.io/ansilume/ansilume:' . $tag,
            'queue-worker' => 'ghcr.io/ansilume/ansilume:' . $tag,
            'runner-1' => 'ghcr.io/ansilume/ansilume-runner:' . $tag,
            'runner-2' => 'ghcr.io/ansilume/ansilume-runner:' . $tag,
        ], $images);
    }

    public function testAnImageTagSetInTheInventoryWins(): void
    {
        $services = $this->renderDeployTemplate(true, ['ansilume_version' => 'main', 'ansilume_image_tag' => '2.11.1'])['services'];

        $this->assertSame('ghcr.io/ansilume/ansilume:2.11.1', $services['app']['image']);
        $this->assertSame('ghcr.io/ansilume/ansilume-runner:2.11.1', $services['runner-1']['image']);
    }

    /**
     * Unquoted, YAML reads ansilume_version: 2.10 as the number 2.1, whose
     * image tag 2.1 exists (2.1.x): the role refuses numbers.
     */
    public function testAnUnquotedVersionIsRefused(): void
    {
        $assert = $this->task($this->tasks('tasks/main.yaml'), 'Validate the version and the image tag are strings');
        $this->assertSame(['ansilume_version is string', 'ansilume_image_tag is string'], $assert['ansible.builtin.assert']['that'] ?? null);

        $unquoted = Yaml::parse('ansilume_version: 2.10');
        $this->assertSame('False', $this->renderDeployText('{{ ansilume_version is string }}', $unquoted));
        $this->assertSame('True', $this->renderDeployText('{{ ansilume_version is string and ansilume_image_tag is string }}', Yaml::parse('ansilume_version: "2.10"')));
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool}>
     */
    public static function backendProvider(): array
    {
        $cases = [];
        foreach ([false, true] as $prebuilt) {
            foreach ([true, false] as $db) {
                foreach ([true, false] as $redis) {
                    $cases[sprintf('%s, %s database, %s redis', $prebuilt ? 'prebuilt' : 'build', $db ? 'local' : 'external', $redis ? 'local' : 'external')] = [$prebuilt, $db, $redis];
                }
            }
        }

        return $cases;
    }

    /**
     * Regression: with an external database and an external Redis, app,
     * schedule-runner and queue-worker got an empty depends_on, which
     * Compose refuses ("services.app.depends_on must be a array").
     *
     * @dataProvider backendProvider
     */
    public function testRegressionEveryDependencyListNamesAService(bool $prebuilt, bool $db, bool $redis): void
    {
        $services = $this->renderDeployTemplate($prebuilt, ['ansilume_db_local' => $db, 'ansilume_redis_local' => $redis])['services'];

        $expected = array_keys(array_filter(['db' => $db, 'redis' => $redis]));
        foreach (['app', 'schedule-runner', 'queue-worker'] as $name) {
            $this->assertSame($expected, array_keys((array)($services[$name]['depends_on'] ?? [])), $name);
            if ($expected === []) {
                $this->assertArrayNotHasKey('depends_on', $services[$name], $name);
            }
        }
        foreach ($services as $name => $service) {
            if (array_key_exists('depends_on', $service)) {
                $this->assertNotEmpty($service['depends_on'], "{$name}: an empty depends_on is no list");
            }
        }
    }

    /**
     * Regression: system.yaml created docker/ and its subdirectories in the
     * install directory before the clone, and git clones into an empty
     * directory only, so every new installation from git stopped there.
     */
    public function testRegressionNothingIsCreatedInTheInstallDirectoryBeforeTheClone(): void
    {
        $includes = array_values(array_map(
            static fn (array $task): string => (string)$task['ansible.builtin.include_tasks'],
            array_filter($this->tasks('tasks/main.yaml'), static fn (array $task): bool => isset($task['ansible.builtin.include_tasks'])),
        ));
        $this->assertSame(['preflight.yaml', 'system.yaml', 'source.yaml', 'config.yaml', 'stack.yaml', 'healthcheck.yaml'], $includes);

        foreach ($this->tasksBeforeTheSource() as $task) {
            $path = (string)($task['ansible.builtin.file']['path'] ?? '');
            $this->assertStringNotContainsString('{{ ansilume_install_dir }}/', $path, (string)$task['name']);
        }
        $directories = $this->task($this->tasks('tasks/config.yaml'), 'Create Docker configuration subdirectories');
        $this->assertSame(['docker', 'docker/nginx', 'docker/mysql', 'docker/php'], $directories['loop'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tasks(string $file): array
    {
        $tasks = Yaml::parseFile(dirname(__DIR__, 3) . '/' . self::ROLE . '/' . $file);
        $this->assertIsArray($tasks);

        return array_values(array_filter($tasks, 'is_array'));
    }

    /**
     * The tasks that run before the source step, those of the files
     * main.yaml includes before it among them.
     *
     * @return list<array<string, mixed>>
     */
    private function tasksBeforeTheSource(): array
    {
        $before = [];
        foreach ($this->tasks('tasks/main.yaml') as $task) {
            $include = $task['ansible.builtin.include_tasks'] ?? null;
            if ($include === 'source.yaml') {
                return $before;
            }
            $before = [...$before, ...($include === null ? [$task] : $this->tasks('tasks/' . $include))];
        }
        $this->fail('main.yaml includes no source.yaml.');
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    private function task(array $tasks, string $name): array
    {
        foreach ($tasks as $task) {
            if (($task['name'] ?? null) === $name) {
                return $task;
            }
        }
        $this->fail("The role has no task named {$name}.");
    }

    /**
     * The line of $text that starts with $prefix, with the newlines around it.
     */
    private function line(string $text, string $prefix): string
    {
        $this->assertSame(1, preg_match('/\n' . preg_quote($prefix, '/') . '[^\n]*\n/', $text, $match), "No line {$prefix}");

        return $match[0];
    }
}
