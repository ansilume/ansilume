<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use app\tests\unit\YiiExecScan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * No process that a stack keeps running holds a database connection while
 * it waits: every service of an image with Ansilume's code (the app image,
 * the runner image) runs one of the processes of PROCESSES, which says
 * what it does with its connection, and each of them is one that a stack
 * runs: the compose files and the deploy role, its command or the command
 * of its image.
 *
 * Written for the class of a bug: the queue-worker (worker/start) kept its
 * connection for its whole life. Once the queue had sat idle longer than
 * the database's wait_timeout (28800 seconds by default), the database had
 * closed it, and every project sync failed with 2006 or 4031 until the
 * queue-worker restarted (the regression test: WorkerJobDbConnectionTest).
 * A new service, or another command for one, such as yii2-queue's own
 * queue/listen in place of worker/start, fails here until it goes on the
 * list with its reason, once it holds no connection while it waits.
 */
final class IdleDatabaseConnectionTest extends TestCase
{
    use RendersDeployRole;

    /**
     * What each process that a stack keeps running does with its database
     * connection while it waits: a console route, or the command of an
     * image that runs no console route.
     */
    private const PROCESSES = [
        'php-fpm' => 'The web UI and the API: a request opens a connection when it needs one, and PHP closes it when'
            . ' the request ends; config/db.php asks for no persistent connection.',
        'worker/start' => 'The queue-worker: it closes the connection before and after each queue job, so that each'
            . ' job opens its own (WorkerController::attachWorkerEvents(), WorkerJobDbConnectionTest).',
        'runner/start' => 'A runner: it opens none, as it reaches the server through the runner API only.',
        'schedule/run' => 'The schedule-runner: a process of its own for each run, which its loop starts every minute.',
        'maintenance/run' => 'The schedule-runner: a process of its own for each run, after schedule/run.',
    ];

    /** The Dockerfiles of the images with Ansilume's code. */
    private const DOCKERFILES = ['docker/php/Dockerfile', 'docker/php/Dockerfile.prod', 'docker/runner/Dockerfile'];

    /** The Dockerfile of each released image with Ansilume's code, by its name (the release workflow builds them). */
    private const RELEASED_IMAGES = [
        'ghcr.io/ansilume/ansilume' => 'docker/php/Dockerfile.prod',
        'ghcr.io/ansilume/ansilume-runner' => 'docker/runner/Dockerfile',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function stackProvider(): array
    {
        return [
            'docker-compose.yml' => ['dev'],
            'docker-compose.prebuilt.yml' => ['prebuilt'],
            'the deploy role, building the images' => ['deploy build'],
            'the deploy role, with prebuilt images' => ['deploy prebuilt'],
        ];
    }

    /**
     * Every process that a stack keeps running is on the list, and the
     * queue-worker's worker/start is among those found, so that the scan
     * cannot pass because it found nothing.
     */
    #[DataProvider('stackProvider')]
    public function testEveryProcessThatAStackKeepsRunningIsListedWithWhatItDoesWithItsConnection(string $stack): void
    {
        $processes = $this->processesOf($stack);
        $unlisted = [];
        foreach ($processes as $service => $runs) {
            foreach (array_diff($runs, array_keys(self::PROCESSES)) as $process) {
                $unlisted[] = "{$service}: {$process}";
            }
        }

        $this->assertSame(
            [],
            $unlisted,
            'These processes are not in IdleDatabaseConnectionTest::PROCESSES: make sure that each holds no database'
                . ' connection while it waits (CLAUDE.md, Database), then add it with what it does with its connection'
        );
        $this->assertSame(['worker/start'], $processes['queue-worker'] ?? [], "the queue-worker of {$stack}");
    }

    /**
     * Each process of the list is one that the compose files run, so that a
     * reason for one that no stack runs any more is not kept.
     */
    public function testEveryListedProcessIsOneThatAStackRuns(): void
    {
        $run = [];
        foreach (['dev', 'prebuilt'] as $stack) {
            foreach ($this->processesOf($stack) as $runs) {
                $run = array_merge($run, $runs);
            }
        }
        $run = array_values(array_unique($run));
        sort($run);
        $listed = array_keys(self::PROCESSES);
        sort($listed);

        $this->assertSame($listed, $run);
    }

    /**
     * The scan reads a service's own command, and the command of its image
     * where it has none: the app runs php-fpm, the runner of the prebuilt
     * stack the runner image's runner/start; the schedule-runner runs both
     * of its routes.
     */
    public function testTheScanReadsTheCommandOfTheServiceOrOfItsImage(): void
    {
        $prebuilt = $this->processesOf('prebuilt');

        $this->assertSame(['php-fpm'], $prebuilt['app'] ?? []);
        $this->assertSame(['runner/start'], $prebuilt['runner-1'] ?? []);
        $this->assertSame(['schedule/run', 'maintenance/run'], $prebuilt['schedule-runner'] ?? []);
        $this->assertArrayNotHasKey('nginx', $prebuilt, 'nginx runs no code of Ansilume');
        $this->assertArrayNotHasKey('db', $prebuilt);
    }

    /**
     * The processes each service of an image with Ansilume's code runs in
     * $stack: the console routes of its command, or the command itself
     * when it runs none.
     *
     * @return array<string, list<string>> by service
     */
    private function processesOf(string $stack): array
    {
        $processes = [];
        foreach ($this->services($stack) as $name => $service) {
            $dockerfile = $this->dockerfileOf($service);
            if ($dockerfile === null) {
                continue;
            }
            $command = $this->wordsOf($service['command'] ?? null) ?: $this->commandOf($dockerfile);
            $routes = array_column(YiiExecScan::routesIn(implode(' ', $command)), 'route');
            $processes[$name] = $routes === [] ? [$command[0] ?? ''] : $routes;
        }

        return $processes;
    }

    /**
     * The Dockerfile of the image that $service runs, when it is one with
     * Ansilume's code: the one it builds, or the one the release builds its
     * image from.
     *
     * @param array<string, mixed> $service
     */
    private function dockerfileOf(array $service): ?string
    {
        $build = $service['build'] ?? null;
        if (is_array($build)) {
            $dockerfile = (string)($build['dockerfile'] ?? 'Dockerfile');

            return in_array($dockerfile, self::DOCKERFILES, true) ? $dockerfile : null;
        }
        $image = (string)($service['image'] ?? '');
        $name = (string)preg_replace('/:[^\/]*$/', '', $image);

        return self::RELEASED_IMAGES[$name] ?? null;
    }

    /**
     * The words of the last CMD of $dockerfile, the command of its image.
     *
     * @return list<string>
     */
    private function commandOf(string $dockerfile): array
    {
        $text = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $dockerfile);
        $this->assertGreaterThan(0, (int)preg_match_all('/^CMD (\[.*\])\s*$/m', $text, $matches), "{$dockerfile} has a CMD");
        $words = json_decode((string)end($matches[1]), true, 2, JSON_THROW_ON_ERROR);
        $this->assertIsArray($words);

        return $this->wordsOf($words);
    }

    /**
     * The words of a command of a compose file: a list, or a string of words.
     *
     * @return list<string>
     */
    private function wordsOf(mixed $command): array
    {
        if (is_string($command)) {
            return preg_split('/\s+/', trim($command), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $words = [];
        foreach (is_array($command) ? $command : [] as $word) {
            $this->assertIsString($word);
            $words[] = $word;
        }

        return $words;
    }

    /**
     * The services of $stack, by name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function services(string $stack): array
    {
        $compose = match ($stack) {
            'dev' => Yaml::parseFile(dirname(__DIR__, 3) . '/docker-compose.yml'),
            'prebuilt' => Yaml::parseFile(dirname(__DIR__, 3) . '/docker-compose.prebuilt.yml'),
            'deploy build' => $this->renderDeployTemplate(false),
            default => $this->renderDeployTemplate(true),
        };
        $this->assertIsArray($compose);
        $this->assertIsArray($compose['services'] ?? null);

        /** @var array<string, array<string, mixed>> $services */
        $services = $compose['services'];

        return $services;
    }
}
