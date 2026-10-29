<?php

declare(strict_types=1);

namespace app\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every console route that the scripts (bin/), the documents (docs/,
 * README.md), the deploy role (deploy/), the workflows (.github/), the
 * images (docker/), the compose files and the API spec (web/openapi.yaml)
 * name exists: `php yii <route>` and the yii script by its path, as
 * YiiExecScan::routesIn() finds them. The console application says which
 * exist, not a list kept here: a fresh PHP process builds it from
 * config/console.php as the yii script does, so that Yii's own commands
 * (migrate, cache, help) and those of the components it bootstraps (queue)
 * count, and asks it with createController() and createAction().
 *
 * Regression: when the integration tests could not reach the test
 * database, bin/tests-phpunit.sh told whoever ran it to "run: php yii
 * setup/test-db", a command that never existed (SetupController has
 * setup/admin only). Nothing checked that a command that a hint, a document
 * or a workflow names exists: whoever copied it got "Unknown command"
 * instead of the fix.
 */
final class NamedConsoleRoutesExistTest extends TestCase
{
    /**
     * Where commands are named, from the repository root: every file of
     * these directories (in docs/ the documents), README.md, the compose files
     * and the API spec.
     */
    private const SCANNED = [
        'bin',
        'docs',
        'deploy',
        '.github',
        'docker',
        'README.md',
        'docker-compose.yml',
        'docker-compose.prebuilt.yml',
        'web/openapi.yaml',
    ];

    /** What the scan leaves out, by the start of its path, and why. */
    private const NOT_SCANNED = [
        'docs/superpowers/' => 'The plans and specs of past work, kept as they were written: they name the commands'
            . ' of their time, and nobody runs them as instructions.',
    ];

    /**
     * The child process. Its arguments: the repository root and the routes
     * to check. It builds the console application as the yii script does,
     * in production mode and without its log targets, so that it writes
     * nothing to runtime/logs, and prints as a JSON object each route that
     * the application does not have, with the reason.
     */
    private const CHECKER = <<<'PHP'
        <?php

        declare(strict_types=1);

        $root = $argv[1];
        require $root . '/vendor/autoload.php';
        \Dotenv\Dotenv::createUnsafeImmutable($root)->safeLoad();
        define('YII_DEBUG', false);
        define('YII_ENV', 'prod');
        require $root . '/vendor/yiisoft/yii2/Yii.php';
        $config = require $root . '/config/console.php';
        $config['components']['log']['targets'] = [];
        $application = new \yii\console\Application($config);
        $missing = [];
        foreach (array_slice($argv, 2) as $route) {
            try {
                $parts = $application->createController($route);
                if ($parts === false) {
                    $missing[$route] = 'no such command';
                } elseif ($parts[0]->createAction($parts[1]) === null) {
                    $action = $parts[1] === '' ? $parts[0]->defaultAction : $parts[1];
                    $missing[$route] = get_class($parts[0]) . ' has no action "' . $action . '"';
                }
            } catch (\Throwable $e) {
                $missing[$route] = get_class($e) . ': ' . $e->getMessage();
            }
        }
        echo json_encode((object)$missing, JSON_THROW_ON_ERROR);

        PHP;

    private string $checker = '';

    protected function tearDown(): void
    {
        if ($this->checker !== '' && is_file($this->checker)) {
            unlink($this->checker);
        }
        parent::tearDown();
    }

    public function testRegressionEveryConsoleRouteThatIsNamedExists(): void
    {
        $named = self::namedRoutes();
        $missing = $this->missingOf(array_values(array_unique(array_column($named, 'route'))));

        $found = [];
        foreach ($named as $name) {
            if (isset($missing[$name['route']])) {
                $found[] = "{$name['where']}: {$name['command']} ({$missing[$name['route']]})";
            }
        }

        $this->assertSame(
            [],
            $found,
            'These name a console command that does not exist: name one that does (php yii help lists them), or add it'
        );
    }

    /**
     * The scan finds the routes where they are named, so that it cannot pass
     * because it found none: the quickstart's first admin, bin/diagnose's
     * health check, the nightly ldap/sync, the workflows' migration and
     * admin, the images' migration and runner, the words of a compose file's
     * command and health check, and in the spec the API token and a route on
     * the next line of a code span that wraps. It leaves out the plans of
     * past work, which name routes too.
     */
    public function testTheScanFindsTheRoutesWhereTheyAreNamed(): void
    {
        $byFile = [];
        foreach (self::namedRoutes() as $name) {
            $byFile[$name['file']][] = $name['route'];
        }

        foreach (
            [
                ['bin/quickstart', 'setup/admin'],
                ['bin/diagnose', 'health/check'],
                ['docs/ldap.md', 'ldap/sync'],
                ['.github/workflows/ci.yaml', 'migrate'],
                ['.github/workflows/release.yml', 'setup/admin'],
                ['docker/php/entrypoint.sh', 'migrate'],
                ['docker/runner/Dockerfile', 'runner/start'],
                ['docker-compose.prebuilt.yml', 'worker/start'],
                ['docker-compose.prebuilt.yml', 'health/check'],
                ['deploy/roles/ansilume/templates/docker-compose.yaml', 'schedule/run'],
                ['web/openapi.yaml', 'api-token/create'],
                ['web/openapi.yaml', 'schedule/check'],
            ] as [$file, $route]
        ) {
            $this->assertContains($route, $byFile[$file] ?? [], $file);
        }
        $plan = 'docs/superpowers/plans/2026-04-17-e2e-tranche1.md';
        $this->assertNotSame([], YiiExecScan::routesIn((string)file_get_contents(self::root() . '/' . $plan)), "{$plan} names routes");
        $this->assertSame(
            [],
            array_values(array_filter(array_keys($byFile), static fn (string $file): bool => str_starts_with($file, 'docs/superpowers/'))),
            'The plans and specs of past work are left out (NOT_SCANNED).'
        );
    }

    /**
     * The console application answers for itself: Yii's own commands and a
     * default action, the command of the queue it bootstraps and Ansilume's
     * commands exist; a command, an action or a default action that is not
     * there is named with the reason.
     */
    public function testTheConsoleApplicationSaysWhichRoutesExist(): void
    {
        $this->assertSame(
            [
                'setup/test-db' => 'app\commands\SetupController has no action "test-db"',
                'setup' => 'app\commands\SetupController has no action "index"',
                'migrate/sideways' => 'yii\console\controllers\MigrateController has no action "sideways"',
                'no-such-command' => 'no such command',
            ],
            $this->missingOf([
                'migrate',
                'migrate/history',
                'cache/flush-schema',
                'help',
                'queue/info',
                'setup/admin',
                'health/check',
                'e2e/seed',
                'setup/test-db',
                'setup',
                'migrate/sideways',
                'no-such-command',
            ])
        );
    }

    /**
     * @param list<string> $expected "<line> <route>"
     */
    #[DataProvider('shapeProvider')]
    public function testTheScanKnowsTheShapes(string $text, array $expected): void
    {
        $this->assertSame(
            $expected,
            array_map(static fn (array $route): string => "{$route['line']} {$route['route']}", YiiExecScan::routesIn($text)),
            $text
        );
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function shapeProvider(): array
    {
        return [
            'php yii, its options and arguments' => [
                "php yii migrate --interactive=0\nphp yii migrate/down 1\nphp yii api-token/create admin \"my-token\" --raw\n",
                ['1 migrate', '2 migrate/down', '3 api-token/create'],
            ],
            'php with options, the yii script by its path' => [
                "php -d memory_limit=1G yii cache/flush-schema db\nphp /var/www/yii health/check\n./yii schedule/check\n",
                ['1 cache/flush-schema', '2 health/check', '3 schedule/check'],
            ],
            'in an exec, a wrapper, a command substitution, after gosu' => [
                "docker compose exec -T -u www-data app php yii ldap/sync >> /var/log/sync.log 2>&1\n"
                    . "OUT=\$(dc php yii e2e/seed 2>&1 || true)\nexec gosu www-data php /var/www/yii migrate\n",
                ['1 ldap/sync', '2 e2e/seed', '3 migrate'],
            ],
            'a hint in a string, a code span, a code span that wraps' => [
                "skip \"Tests (database not available — run: php yii setup/test-db)\"\nRun `php yii schedule/check` once.\n"
                    . "is refused first. `php yii\n    schedule/check` lists them.\n",
                ['1 setup/test-db', '2 schedule/check', '3 schedule/check'],
            ],
            'the words of a YAML flow sequence' => [
                "command: [\"php\", \"yii\", \"worker/start\"]\n"
                    . "test: [\"CMD\", \"gosu\", \"www-data\", \"php\", \"/var/www/yii\", \"health/check\"]\n",
                ['1 worker/start', '2 health/check'],
            ],
            'no route: a placeholder, a variable, a pattern, prose, the script itself' => [
                "php yii …\nphp yii <command>\nphp yii \"\$@\"\nruns `php yii ldap/*`: the app container\n"
                    . "the same way ./yii does: load .env first\nfind . -path ./yii -print\nphp -r \"require '/var/www/yii';\"\n"
                    . "composer require yiisoft/yii2\nphp vendor/bin/phpunit\n`php yii` alone\n",
                [],
            ],
        ];
    }

    /**
     * The routes of $routes that the console application of
     * config/console.php does not have, with the reason (CHECKER).
     *
     * @param list<string> $routes
     * @return array<string, string>
     */
    private function missingOf(array $routes): array
    {
        $checker = tempnam(sys_get_temp_dir(), 'console_routes_');
        $this->assertIsString($checker);
        $this->checker = $checker;
        file_put_contents($checker, self::CHECKER);
        $process = proc_open(
            [PHP_BINARY, $checker, self::root(), ...$routes],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::root()
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), "The console application could not answer: {$stdout}{$stderr}");
        $missing = json_decode($stdout, true);
        $this->assertIsArray($missing, "The check printed no JSON object: {$stdout}{$stderr}");
        $reasons = [];
        foreach ($missing as $route => $reason) {
            $this->assertIsString($reason);
            $reasons[(string)$route] = $reason;
        }

        return $reasons;
    }

    /**
     * The routes that the files of SCANNED name, but those NOT_SCANNED
     * leaves out, each with its file, where (file:line) and its command.
     *
     * @return list<array{file: string, where: string, route: string, command: string}>
     */
    private static function namedRoutes(): array
    {
        $named = [];
        foreach (YiiExecScan::filesIn(self::root(), self::SCANNED) as $file) {
            if (self::leftOut($file)) {
                continue;
            }
            foreach (YiiExecScan::routesIn((string)file_get_contents(self::root() . '/' . $file)) as $route) {
                $named[] = ['file' => $file, 'where' => "{$file}:{$route['line']}", 'route' => $route['route'], 'command' => $route['command']];
            }
        }

        return $named;
    }

    private static function leftOut(string $file): bool
    {
        foreach (array_keys(self::NOT_SCANNED) as $start) {
            if (str_starts_with($file, $start)) {
                return true;
            }
        }

        return false;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
