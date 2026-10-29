<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real bin/tests-phpunit.sh with a stand-in for docker that notes
 * each call: the migrations of the scratch database and of the test
 * database run in the app container as www-data, PHPUnit as root.
 *
 * Regression: both migrations ran as root (`docker compose exec -T app`,
 * dc). A console command logs to runtime/logs/app.log, which in a git
 * checkout is the file php-fpm's workers (www-data) log to: as root, a run
 * that rotated a full log left copies that they cannot write, and one that
 * found no log created it as root's (YiiExecUserTest). PHPUnit stays root,
 * as the tests that drop privileges need (RunsUnprivileged), and logs to
 * runtime/logs/test.log.
 */
final class PhpunitSuiteMigrationUserTest extends TestCase
{
    private const STUB_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        # docker for PhpunitSuiteMigrationUserTest: notes each call in STUB_CALLS.
        printf '%s\n' "$*" >> "$STUB_CALLS"
        case "$*" in
            "compose ps app --quiet") echo stubbed-app ;;
            "compose exec -T db mariadb "*) ;;
            *"--testsuite=Unit,Integration "*)
                printf 'OK (2 tests, 2 assertions)\n\nCode Coverage Report:\n\n Summary:\n  Classes: 75.43%% (436/578)\n'
                printf '  Methods: 90.54%% (3456/3817)\n  Lines:   95.18%% (23456/24644)\n' ;;
            *"--testsuite=Unit "*) echo "OK (1 test, 1 assertion)" ;;
            *"php yii migrate "*) printf '*** applied m000001_000000_init\nMigrated up successfully.\n' ;;
            *) echo "unexpected: docker $*" >&2; exit 1 ;;
        esac
        BASH;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/phpunit-suite-migration-user-' . uniqid('', true);
        mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/docker', self::STUB_DOCKER . "\n");
        chmod($this->directory . '/docker', 0o755);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    public function testRegressionTheMigrationsRunAsWwwDataAndPhpunitAsRoot(): void
    {
        $process = proc_open(
            ['bash', dirname(__DIR__, 3) . '/bin/tests-phpunit.sh'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->directory,
            [
                'PATH' => $this->directory . ':' . (string)getenv('PATH'),
                'HOME' => $this->directory,
                'STUB_CALLS' => $this->directory . '/calls',
            ]
        );
        $this->assertIsResource($process);
        $output = (string)preg_replace('/\e\[[0-9;]*m/', '', (string)stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        $status = proc_close($process);

        $calls = file($this->directory . '/calls', FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertSame(
            [
                'compose exec -T -u www-data app bash -c DB_NAME=ansilume_migrate_test php yii migrate --interactive=0 2>&1',
                'compose exec -T -u www-data app sh -c DB_NAME=ansilume_test php yii migrate --interactive=0 2>&1',
                'compose exec -T app php -d memory_limit=1G vendor/bin/phpunit --testsuite=Unit --colors=never',
                'compose exec -T app php -d pcov.enabled=1 -d memory_limit=1G vendor/bin/phpunit --testsuite=Unit,Integration --coverage-text --colors=never',
            ],
            array_values(array_filter($calls, static fn (string $call): bool => str_starts_with($call, 'compose exec -T ') && !str_starts_with($call, 'compose exec -T db '))),
            'the execs in the app container: the migrations as www-data, PHPUnit as root'
        );
        $this->assertStringContainsString("✔  All migrations applied cleanly on a fresh schema (1 migrations)\n", $output);
        $this->assertStringContainsString("✔  Test database migrations up to date\n", $output);
        $this->assertStringContainsString('Results: 5 passed  0 failed  0 skipped', $output);
        $this->assertSame(0, $status, $output);
    }
}
