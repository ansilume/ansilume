<?php

declare(strict_types=1);

namespace app\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every exec that runs Yii in a container of the app image runs it as
 * www-data, the user of php-fpm's workers, the queue-worker and the
 * schedule-runner: in the scripts (bin/), the documents (docs/, README.md),
 * the deploy role (deploy/), the workflows (.github/), the comments of the
 * compose files and the description of the API spec (web/openapi.yaml).
 * YiiExecScan says what counts as such an exec and what the scan cannot
 * see. The YAML files are read as scripts: a command in a comment ends with
 * the line, one in a code span of the spec's Markdown at its backtick.
 *
 * Regression: the quickstart created the first admin with
 * `docker compose exec app php yii setup/admin`, bin/diagnose ran
 * health/check, migrate/history and its PHP_BOOT probes, bin/tests-phpunit.sh
 * the migrations of the test databases, and about 25 commands of the
 * documents told operators to run console commands, the nightly ldap/sync
 * among them, as did the header of docker-compose.prebuilt.yml (the first
 * admin) and the spec (an API token); CI migrated its test database and the
 * release's smoke test created its admin the same way: all as root, the
 * user of the app image. A console command logs to runtime/logs/app.log
 * (its log target keeps info, such as "Opening DB connection"). Root
 * appends to an app.log that exists and leaves it www-data's. But a root
 * process that rotates a full app.log (10 MB) leaves app.log.1 to app.log.5
 * as root's copies, which www-data cannot overwrite: its later rotations
 * lose the rotated lines without a word (FileTarget suppresses the failed
 * copy) until the next start of the container hands runtime/logs back to
 * www-data. And one that finds no app.log creates it as root, so that
 * php-fpm's workers log no error of the web UI and the API.
 *
 * AS_ROOT lists the execs that run Yii as root on purpose, each with its
 * reason. An entry that matches no such exec any more fails, so that the
 * list says what is true.
 */
final class YiiExecUserTest extends TestCase
{
    /**
     * Where commands are copied from, from the repository root: every file of
     * these directories (in docs/ the documents), README.md, the compose files
     * and the API spec.
     */
    private const SCANNED = [
        'bin',
        'docs',
        'deploy',
        '.github',
        'README.md',
        'docker-compose.yml',
        'docker-compose.prebuilt.yml',
        'web/openapi.yaml',
    ];

    /** Why the E2E data commands run as root. */
    private const E2E = 'The E2E data commands run only where YII_ENV is dev or test (E2eController refuses them'
        . ' elsewhere, exit 78), never on an installation, and the seed must run as root: it makes the files and'
        . ' directories that php-fpm\'s user may not read (E2eUnreadableArtifactSeeder, E2eUnreadableProjectSeeder),'
        . ' which only root can make.';

    /**
     * The execs that run Yii as root on purpose: the file, a part of the
     * exec that names it, and why.
     *
     * @var list<array{file: string, exec: string, reason: string}>
     */
    private const AS_ROOT = [
        ['file' => 'bin/tests-e2e.sh', 'exec' => 'dc php yii e2e/', 'reason' => self::E2E],
        ['file' => 'docs/superpowers/plans/2026-04-17-e2e-tranche1.md', 'exec' => 'php yii e2e/seed', 'reason' => self::E2E],
        [
            'file' => 'docs/superpowers/plans/2026-04-17-e2e-tranche2-rbac.md',
            'exec' => 'php -r "require \"/var/www/yii\"; exit;"',
            'reason' => 'A plan of 2026-04, kept as it was written, that drops this step in the same sentence'
                . ' ("actually just skip the pre-verification").',
        ],
        [
            'file' => 'docs/runners.md',
            'exec' => 'docker compose exec runner-1 php yii runner/start',
            'reason' => 'Names a runner started by hand in a git checkout, where it runs as root, to say what that'
                . ' leaves behind: no instruction.',
        ],
    ];

    /**
     * The execs of the repository that run Yii in the app image, once for
     * all tests (repository()).
     *
     * @var list<array{file: string, where: string, user: string, exec: string}>|null
     */
    private static ?array $repository = null;

    public function testRegressionEveryYiiCommandInTheAppImageRunsAsWwwData(): void
    {
        $found = [];
        foreach (self::repository() as $exec) {
            if ($exec['user'] !== YiiExecScan::WWW_DATA && self::asRootOnPurpose($exec) === null) {
                $found[] = "{$exec['where']}: {$exec['exec']}";
            }
        }

        $this->assertSame(
            [],
            $found,
            'Run Yii in the app container as www-data, the user of php-fpm\'s workers:'
            . ' docker compose exec -u www-data app php yii …, docker exec -u www-data "$app_container" php …'
            . ' (root rotates runtime/logs/app.log into copies that www-data cannot write, or creates it as'
            . ' root\'s), or list the exec in AS_ROOT with the reason why it must run as root'
        );
    }

    public function testEveryExecThatRunsAsRootOnPurposeIsStillThere(): void
    {
        $unused = [];
        foreach (self::AS_ROOT as $index => $entry) {
            $used = array_filter(
                self::repository(),
                static fn (array $exec): bool => $exec['user'] !== YiiExecScan::WWW_DATA
                    && self::asRootOnPurpose($exec) === $index
            );
            if ($used === []) {
                $unused[] = "{$entry['file']}: {$entry['exec']}";
            }
        }

        $this->assertSame([], $unused, 'These entries of AS_ROOT match no exec that runs Yii as root: remove them');
    }

    /**
     * The scan reads the places that hold commands, so that it cannot pass
     * because it read nothing: the quickstart and bin/diagnose, the suites,
     * the documents, the deploy role's tasks, the workflows, the compose
     * files and the API spec.
     */
    public function testTheScanReadsEveryPlaceACommandIsCopiedFrom(): void
    {
        $files = self::scannedFiles();

        foreach (
            [
                'bin/quickstart',
                'bin/diagnose',
                'bin/tests-phpunit.sh',
                'docs/installation.md',
                'docs/ldap.md',
                'docs/updating.md',
                'README.md',
                'deploy/roles/ansilume/tasks/main.yaml',
                'deploy/roles/ansilume/templates/docker-compose.yaml',
                '.github/workflows/ci.yaml',
                '.github/workflows/release.yml',
                'docker-compose.yml',
                'docker-compose.prebuilt.yml',
                'web/openapi.yaml',
            ] as $file
        ) {
            $this->assertContains($file, $files);
        }
        $this->assertNotContains('docs/screenshots/dashboard.png', $files, 'no images');
    }

    /**
     * The commands an operator runs, as www-data: the quickstart's first
     * admin, bin/diagnose's health check, the nightly ldap/sync of
     * docs/ldap.md, the migrations of bin/tests-phpunit.sh, the first admin
     * of docker-compose.prebuilt.yml's header and the API token of the spec;
     * and those of the workflows: CI's migration of the test database and
     * the admin of the release's smoke test, which so proves the documented
     * command on the released image.
     */
    public function testTheCommandsOfTheQuickstartTheDiagnosticsAndTheDocsRunAsWwwData(): void
    {
        $byWhere = [];
        foreach (self::repository() as $exec) {
            $byWhere[$exec['file']][] = $exec['user'] . ': ' . $exec['exec'];
        }

        foreach (
            [
                'bin/quickstart' => 'www-data: $COMPOSE_CMD $COMPOSE_ARGS exec -T -u www-data app php yii setup/admin',
                'bin/diagnose' => 'www-data: docker exec -u www-data "$app_container" php /var/www/yii health/check',
                'docs/ldap.md' => 'www-data: docker compose exec -T -u www-data app php yii ldap/sync >> /var/log/ansilume/ldap-sync.log',
                'bin/tests-phpunit.sh' => "www-data: dc_www_data sh -c 'DB_NAME=ansilume_test php yii migrate --interactive=0 2>&1'",
                'docker-compose.prebuilt.yml' => 'www-data: docker compose exec -u www-data app php yii setup/admin admin admin@example.com',
                'web/openapi.yaml' => 'www-data: docker compose exec -u www-data app php yii api-token/create admin "my-token" --raw',
                '.github/workflows/ci.yaml' => 'www-data: docker compose exec -T -u www-data app bash -c "DB_NAME=${DB_TEST_NAME} php yii migrate',
                '.github/workflows/release.yml' => 'www-data: docker compose -f docker-compose.prebuilt.yml exec -T -u www-data app php yii setup/admin smoke-admin',
            ] as $file => $exec
        ) {
            $this->assertNotEmpty(
                array_filter($byWhere[$file] ?? [], static fn (string $found): bool => str_starts_with($found, $exec)),
                "{$file}: {$exec}\n" . implode("\n", $byWhere[$file] ?? [])
            );
        }
    }

    /**
     * @param array<string, string> $wrappers
     * @param list<string> $expected "<line> <user>: <exec>"
     */
    #[DataProvider('shapeProvider')]
    public function testTheScanKnowsTheShapes(string $text, bool $markdown, array $wrappers, array $expected): void
    {
        $found = array_map(
            static fn (array $exec): string => "{$exec['line']} {$exec['user']}: {$exec['exec']}",
            (new YiiExecScan($wrappers))->yiiExecsIn($text, $markdown)
        );

        $this->assertSame($expected, $found, $text);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: array<string, string>, 3: list<string>}>
     */
    public static function shapeProvider(): array
    {
        $wrappers = ['dc' => 'root', 'dc_www' => 'www-data'];

        return [
            'compose exec, as root' => ["docker compose exec app php yii migrate\n", false, [], ['1 root: docker compose exec app php yii migrate']],
            '-T -u www-data, up to the redirection 2>&1' => [
                "0 3 * * * cd /opt && docker compose exec -T -u www-data app php yii ldap/sync >> /var/log/sync.log 2>&1\n",
                false,
                [],
                ['1 www-data: docker compose exec -T -u www-data app php yii ldap/sync >> /var/log/sync.log 2>'],
            ],
            '--user www-data, --user=www-data, -Tu www-data, -uwww-data, -u=www-data, -u www-data:www-data' => [
                "docker compose exec --user www-data app php yii a\ndocker compose exec --user=www-data app php yii b\n"
                    . "docker compose exec -Tu www-data app php yii c\ndocker compose exec -uwww-data app php yii d\n"
                    . "docker compose exec -u=www-data app php yii e\ndocker compose exec -u www-data:www-data app php yii f\n",
                false,
                [],
                [
                    '1 www-data: docker compose exec --user www-data app php yii a',
                    '2 www-data: docker compose exec --user=www-data app php yii b',
                    '3 www-data: docker compose exec -Tu www-data app php yii c',
                    '4 www-data: docker compose exec -uwww-data app php yii d',
                    '5 www-data: docker compose exec -u=www-data app php yii e',
                    '6 www-data: docker compose exec -u www-data:www-data app php yii f',
                ],
            ],
            'another user, an option with a value' => [
                "docker compose exec -u 33 app php yii a\ndocker compose exec -e DB_NAME=x -w /var/www app php yii b\n",
                false,
                [],
                ['1 33: docker compose exec -u 33 app php yii a', '2 root: docker compose exec -e DB_NAME=x -w /var/www app php yii b'],
            ],
            'gosu www-data' => ["docker compose exec app gosu www-data php yii migrate\n", false, [], ['1 www-data: docker compose exec app gosu www-data php yii migrate']],
            'docker exec of $app_container, in a command substitution' => [
                "out=$(docker exec \"\$app_container\" php /var/www/yii health/check 2>&1 || true)\n",
                false,
                [],
                ['1 root: docker exec "$app_container" php /var/www/yii health/check 2>'],
            ],
            'the PHP_BOOT prelude in a string over lines' => [
                "x=1\nout=$(docker exec -u www-data \"\$app_container\" php -r \"\n    \${PHP_BOOT}\n    echo 'a|b';\n\" 2>/dev/null || true)\n",
                false,
                [],
                ["2 www-data: docker exec -u www-data \"\$app_container\" php -r \" \${PHP_BOOT} echo 'a|b'; \" 2>/dev/null"],
            ],
            'a console application of its own, the yii script required' => [
                "docker exec ansilume_app php -r 'new yii\\console\\Application([]);'\ndocker compose exec app php -r \"require '/var/www/yii';\"\n",
                false,
                [],
                [
                    "1 root: docker exec ansilume_app php -r 'new yii\\console\\Application([]);'",
                    "2 root: docker compose exec app php -r \"require '/var/www/yii';\"",
                ],
            ],
            'compose in a variable with its arguments, a line continuation' => [
                "OUT=\$(\$COMPOSE_CMD \$COMPOSE_ARGS exec -T app \\\n    php yii setup/admin \"\$U\" < /dev/null 2>&1) || RC=\$?\n",
                false,
                [],
                ['1 root: $COMPOSE_CMD $COMPOSE_ARGS exec -T app php yii setup/admin "$U" < /dev/null 2>'],
            ],
            '${compose} -f, docker-compose, ./yii, php with options, bash -c' => [
                "\${compose} -f docker-compose.prebuilt.yml exec -T app php yii a\ndocker-compose exec app ./yii b\n"
                    . "docker compose exec app php -d memory_limit=1G yii c\ndocker compose exec -T app bash -c \"DB_NAME=x php yii d\"\n",
                false,
                [],
                [
                    '1 root: ${compose} -f docker-compose.prebuilt.yml exec -T app php yii a',
                    '2 root: docker-compose exec app ./yii b',
                    '3 root: docker compose exec app php -d memory_limit=1G yii c',
                    '4 root: docker compose exec -T app bash -c "DB_NAME=x php yii d"',
                ],
            ],
            'a hint in a string ends with the string, one in a comment with the line' => [
                "info \"  Fix: docker compose exec app php yii migrate\"\necho \"php yii\"\n# or: docker compose exec app php yii migrate/history\n",
                false,
                [],
                ['1 root: docker compose exec app php yii migrate', '3 root: docker compose exec app php yii migrate/history'],
            ],
            'the queue-worker, the schedule-runner, a runner, the container names' => [
                "docker compose exec queue-worker php yii a\ndocker compose exec schedule-runner php yii b\n"
                    . "docker compose exec runner-2 php yii c\ndocker exec ansilume_queue_worker php yii d\n",
                false,
                [],
                [
                    '1 root: docker compose exec queue-worker php yii a',
                    '2 root: docker compose exec schedule-runner php yii b',
                    '3 root: docker compose exec runner-2 php yii c',
                    '4 root: docker exec ansilume_queue_worker php yii d',
                ],
            ],
            'not the app image, not Yii, Yii after the exec, no exec' => [
                "docker compose exec db mariadb -e 'php yii'\ndocker exec \"\$nginx_container\" php yii\n"
                    . "docker compose exec app php -v\ndocker compose exec app composer install\n"
                    . "docker compose exec app php vendor/yiisoft/yii2/requirements.php\n"
                    . "docker compose exec app ls && php yii migrate\ndocker compose run --rm app php yii migrate\n"
                    . "docker compose ps -q app\nuse `docker compose exec` to run php yii\n",
                false,
                [],
                [],
            ],
            'a code span of a document' => [
                "Then run `docker compose exec app php yii migrate/down 1 --interactive=0`, which reverts it.\n",
                true,
                [],
                ['1 root: docker compose exec app php yii migrate/down 1 --interactive=0'],
            ],
            'quotes in a code span' => [
                "Via `docker compose exec -T app bash -c 'cd /var/www && php -r \"require \\\"/var/www/yii\\\";\"'` once.\n",
                true,
                [],
                ["1 root: docker compose exec -T app bash -c 'cd /var/www && php -r \"require \\\"/var/www/yii\\\";\"'"],
            ],
            'a code block of a document' => [
                "```bash\ndocker compose exec -u www-data app php yii setup/admin admin admin@example.com yourpassword\n```\n",
                true,
                [],
                ['2 www-data: docker compose exec -u www-data app php yii setup/admin admin admin@example.com yourpassword'],
            ],
            'quotes of a document do not span lines' => [
                "It's here (in the app container:\n`docker compose exec app php yii schedule/check`), that's all.\n",
                true,
                [],
                ['2 root: docker compose exec app php yii schedule/check'],
            ],
            'a wrapper, as root and as www-data' => [
                "OUT=\$(dc php yii e2e/seed 2>&1 || true)\nOUT=\$(dc_www sh -c 'DB_NAME=x php yii migrate 2>&1' || true)\n",
                false,
                $wrappers,
                ['1 root: dc php yii e2e/seed 2>', "2 www-data: dc_www sh -c 'DB_NAME=x php yii migrate 2>&1'"],
            ],
            'a wrapper in a string or a comment, one that runs no Yii, one in a document' => [
                "echo \"dc php yii\"\n# dc php yii migrate\nOUT=\$(dc php -d memory_limit=1G vendor/bin/phpunit || true)\n",
                false,
                $wrappers,
                [],
            ],
            'no wrapper in a document' => ["Run dc php yii migrate.\n", true, $wrappers, []],
            'a comment of a YAML file ends with its line' => [
                "#   docker compose up -d\n#   docker compose exec app php yii setup/admin admin admin@example.com yourpassword\n"
                    . "services:\n  app:\n",
                false,
                [],
                ['2 root: docker compose exec app php yii setup/admin admin admin@example.com yourpassword'],
            ],
            'the Markdown of a YAML block scalar: a code block, a code span after an apostrophe' => [
                "info:\n  description: |\n    From the console:\n\n    ```bash\n"
                    . "    docker compose exec -u www-data app php yii api-token/create admin \"my-token\" --raw\n    ```\n\n"
                    . "    The token's owner runs `docker compose exec app php yii api-token/list admin` to list them.\n",
                false,
                [],
                [
                    '6 www-data: docker compose exec -u www-data app php yii api-token/create admin "my-token" --raw',
                    '9 root: docker compose exec app php yii api-token/list admin',
                ],
            ],
            'the commands of a compose file are no execs' => [
                "    command: [\"php\", \"yii\", \"worker/start\"]\n"
                    . "      test: [\"CMD\", \"gosu\", \"www-data\", \"php\", \"/var/www/yii\", \"health/check\"]\n"
                    . "      - 'if [ -n \"\$\$REDIS_PASSWORD\" ]; then exec docker-entrypoint.sh redis-server; fi'\n",
                false,
                [],
                [],
            ],
        ];
    }

    /**
     * The wrappers of the scripts: dc() of bin/tests-common.sh runs as root,
     * dc_www_data() of bin/tests-phpunit.sh as www-data.
     */
    public function testTheScanKnowsTheWrappersOfTheScripts(): void
    {
        $this->assertSame(
            ['dc' => YiiExecScan::ROOT],
            YiiExecScan::wrappersIn("dc() {\n    if [[ \$D -eq 1 ]]; then\n        docker compose exec -T app \"\$@\"\n    else\n        \"\$@\"\n    fi\n}\n")
        );
        $this->assertSame(
            ['dc_www' => YiiExecScan::WWW_DATA],
            YiiExecScan::wrappersIn("dc_www() {\n    docker compose exec -T -u www-data app \"\$@\"\n}\n")
        );
        $this->assertSame([], YiiExecScan::wrappersIn("db() {\n    docker compose exec -T db \"\$@\"\n}\n"), 'another service');
        $this->assertSame([], YiiExecScan::wrappersIn("logs() {\n    docker compose exec -T app tail -f \"\$1\"\n}\n"), 'no "$@"');

        $this->assertSame(['dc' => YiiExecScan::ROOT, 'dc_www_data' => YiiExecScan::WWW_DATA], self::wrappers());
    }

    public function testTheScanFindsTheExecsOfAnsibleTasks(): void
    {
        $tasks = Yaml::parse(<<<'YAML'
            - name: Create the first admin
              community.docker.docker_container_exec:
                container: "{{ ansilume_compose_project }}_app"
                command: php yii setup/admin admin admin@example.com <password>
            - name: Sync the directory
              community.docker.docker_compose_v2_exec:
                project_src: /opt/ansilume
                service: app
                user: www-data
                argv: [php, yii, ldap/sync]
            - name: Migrations
              block:
                - name: Migrate
                  docker_container_exec:
                    container: ansilume_queue_worker
                    argv: [gosu, www-data, php, /var/www/yii, migrate]
            - name: Reload nginx
              community.docker.docker_container_exec:
                container: "{{ ansilume_compose_project }}_nginx"
                command: nginx -s reload
            - name: PHP's version
              community.docker.docker_container_exec:
                container: ansilume_app
                command: php -v
            YAML);

        $this->assertSame(
            [
                [
                    'task' => 'Create the first admin',
                    'user' => YiiExecScan::ROOT,
                    'exec' => 'community.docker.docker_container_exec {{ ansilume_compose_project }}_app: php yii setup/admin admin admin@example.com <password>',
                ],
                [
                    'task' => 'Sync the directory',
                    'user' => YiiExecScan::WWW_DATA,
                    'exec' => 'community.docker.docker_compose_v2_exec app: php yii ldap/sync',
                ],
                [
                    'task' => 'Migrate',
                    'user' => YiiExecScan::WWW_DATA,
                    'exec' => 'docker_container_exec ansilume_queue_worker: gosu www-data php /var/www/yii migrate',
                ],
            ],
            YiiExecScan::ansibleYiiExecsIn($tasks)
        );
    }

    /**
     * The index of the entry of AS_ROOT that $exec matches, or null.
     *
     * @param array{file: string, where: string, user: string, exec: string} $exec
     */
    private static function asRootOnPurpose(array $exec): ?int
    {
        foreach (self::AS_ROOT as $index => $entry) {
            if ($entry['file'] === $exec['file'] && str_contains($exec['exec'], $entry['exec'])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return list<array{file: string, where: string, user: string, exec: string}>
     */
    private static function repository(): array
    {
        if (self::$repository !== null) {
            return self::$repository;
        }
        $root = self::root();
        $execs = [];
        foreach (self::scannedFiles() as $file) {
            $text = (string)file_get_contents($root . '/' . $file);
            $scan = new YiiExecScan(str_starts_with($file, 'bin/') ? self::wrappers() : []);
            foreach ($scan->yiiExecsIn($text, str_ends_with($file, '.md')) as $exec) {
                $execs[] = ['file' => $file, 'where' => "{$file}:{$exec['line']}", 'user' => $exec['user'], 'exec' => $exec['exec']];
            }
            if (preg_match('#^deploy/(?!.*/templates/).*\.ya?ml$#', $file) === 1) {
                foreach (YiiExecScan::ansibleYiiExecsIn(Yaml::parse($text)) as $exec) {
                    $execs[] = ['file' => $file, 'where' => "{$file}: task \"{$exec['task']}\"", 'user' => $exec['user'], 'exec' => $exec['exec']];
                }
            }
        }

        return self::$repository = $execs;
    }

    /**
     * The functions of bin/ that exec their arguments in the app container.
     *
     * @return array<string, string>
     */
    private static function wrappers(): array
    {
        $wrappers = [];
        foreach (glob(self::root() . '/bin/*') ?: [] as $script) {
            $wrappers += YiiExecScan::wrappersIn((string)file_get_contents($script));
        }
        ksort($wrappers);

        return $wrappers;
    }

    /**
     * The files of SCANNED, from the repository root.
     *
     * @return list<string>
     */
    private static function scannedFiles(): array
    {
        return YiiExecScan::filesIn(self::root(), self::SCANNED);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
