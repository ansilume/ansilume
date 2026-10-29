<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use app\helpers\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The log directory and its files belong to www-data when php-fpm, a worker
 * or a runner starts: they run as www-data and cannot append to a log file
 * that root created.
 *
 * Regression: the app container's entrypoint (docker/php/entrypoint-prod.sh
 * of the prebuilt image, docker/php/entrypoint.sh of the dev image and the
 * deploy role) handed runtime/ to www-data and then ran the migrations as
 * root. The console log target wrote "Opening DB connection" to
 * runtime/logs/app.log, so each new container had app.log as root:root 0644
 * before php-fpm started. php-fpm's workers could not append to it, Yii
 * disabled the log target of config/web.php without a word, and every error
 * and warning of the web UI and the API was lost until a restart handed the
 * file over. A prebuilt update recreates the container, so it came back with
 * every update. The health check of the compose files ran as root too and
 * could create the file first (HealthcheckUserTest).
 *
 * The entrypoints run under /bin/sh with set -e, as the containers run them
 * (/bin/sh is dash in php:8.5-fpm), with /var/www and /tmp/ansible moved
 * into a scratch directory and stand-ins for php, gosu, chown, flock,
 * composer and php-fpm. Each stand-in notes its call and the user it runs as:
 * root, or the user a gosu before it named.
 */
class EntrypointLogOwnershipTest extends TestCase
{
    private const ENTRYPOINTS = [
        'dev image' => 'docker/php/entrypoint.sh',
        'prebuilt image' => 'docker/php/entrypoint-prod.sh',
    ];

    /**
     * php: the autoload check and the database check of the entrypoints pass
     * (the database check answers "ok"). Any other call is a yii command: it
     * is noted with whether app.log exists, and writes a line to app.log as
     * the console log target does.
     */
    private const STUB_PHP = <<<'SH'
        #!/bin/sh
        # php for EntrypointLogOwnershipTest.
        if [ "$1" = "-r" ]; then
            case "$2" in
                *PDO*) printf '%s: php -r (database check)\n' "${AS_USER:-root}" >> "$STUB_DIR/calls"; printf 'ok' ;;
                *) printf '%s: php -r (autoload check)\n' "${AS_USER:-root}" >> "$STUB_DIR/calls" ;;
            esac
            exit 0
        fi
        if [ -f "$STUB_LOGS/app.log" ]; then state='app.log exists'; else state='no app.log'; fi
        printf '%s: php %s (%s)\n' "${AS_USER:-root}" "$*" "$state" >> "$STUB_DIR/calls"
        printf 'a log line of php %s\n' "$*" >> "$STUB_LOGS/app.log"
        SH;

    /** gosu: notes the call, then runs the command as the user it names. */
    private const STUB_GOSU = <<<'SH'
        #!/bin/sh
        # gosu for EntrypointLogOwnershipTest.
        printf '%s: gosu %s\n' "${AS_USER:-root}" "$*" >> "$STUB_DIR/calls"
        AS_USER=$1
        export AS_USER
        shift
        exec "$@"
        SH;

    /** chown: notes the call and changes nothing. */
    private const STUB_CHOWN = <<<'SH'
        #!/bin/sh
        # chown for EntrypointLogOwnershipTest.
        printf '%s: chown %s\n' "${AS_USER:-root}" "$*" >> "$STUB_DIR/calls"
        SH;

    /** flock: notes the lock file, then runs the command it locks. */
    private const STUB_FLOCK = <<<'SH'
        #!/bin/sh
        # flock for EntrypointLogOwnershipTest.
        printf '%s: flock %s\n' "${AS_USER:-root}" "$1" >> "$STUB_DIR/calls"
        shift
        exec "$@"
        SH;

    /** composer: notes the call. */
    private const STUB_COMPOSER = <<<'SH'
        #!/bin/sh
        # composer for EntrypointLogOwnershipTest.
        printf '%s: composer %s\n' "${AS_USER:-root}" "$*" >> "$STUB_DIR/calls"
        SH;

    /** touch, for a log directory that www-data may not write to. */
    private const STUB_FAILING_TOUCH = <<<'SH'
        #!/bin/sh
        # touch for EntrypointLogOwnershipTest: fails as touch does.
        printf '%s: touch %s\n' "${AS_USER:-root}" "$*" >> "$STUB_DIR/calls"
        printf "touch: cannot touch '%s': Permission denied\n" "$1" >&2
        exit 1
        SH;

    /** php-fpm: notes the call with whether app.log exists. */
    private const STUB_PHP_FPM = <<<'SH'
        #!/bin/sh
        # php-fpm for EntrypointLogOwnershipTest.
        if [ -f "$STUB_LOGS/app.log" ]; then state='app.log exists'; else state='no app.log'; fi
        printf '%s: php-fpm (%s)\n' "${AS_USER:-root}" "$state" >> "$STUB_DIR/calls"
        SH;

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/entrypoint-log-ownership-' . uniqid('', true);
        mkdir($this->directory . '/bin', 0o700, true);
        mkdir($this->directory . '/var/www', 0o700, true);
        $stubs = [
            'php' => self::STUB_PHP,
            'gosu' => self::STUB_GOSU,
            'chown' => self::STUB_CHOWN,
            'flock' => self::STUB_FLOCK,
            'composer' => self::STUB_COMPOSER,
            'php-fpm' => self::STUB_PHP_FPM,
        ];
        foreach ($stubs as $name => $stub) {
            file_put_contents($this->directory . '/bin/' . $name, $stub . "\n");
            chmod($this->directory . '/bin/' . $name, 0o755);
        }
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function entrypointProvider(): array
    {
        $cases = [];
        foreach (self::ENTRYPOINTS as $image => $entrypoint) {
            $cases[$image] = [$entrypoint];
        }

        return $cases;
    }

    /**
     * The app container: the migrations run as www-data, and then the log
     * directory goes to www-data and app.log is created as www-data, right
     * before php-fpm starts as root (its master; the pool runs the workers
     * as www-data).
     */
    #[DataProvider('entrypointProvider')]
    public function testRegressionTheLogsGoToWwwDataAfterTheMigrationsAndBeforePhpFpm(string $entrypoint): void
    {
        $run = $this->runEntrypoint($entrypoint, ['php-fpm']);

        $this->assertSame(0, $run['status'], "the entrypoint ended with an error:\n{$run['out']}{$run['err']}");
        $yii = $this->directory . '/var/www/yii';
        $migrate = $this->lastCallMatching($run['calls'], '#^\S+: php ' . preg_quote($yii, '#') . ' migrate #');
        $this->assertSame(
            [
                'root: chown -R www-data:www-data ' . $this->logs(),
                'root: gosu www-data touch ' . $this->logs() . '/app.log',
                'root: php-fpm (app.log exists)',
            ],
            array_slice($run['calls'], $migrate + 1),
            'after the migrations the entrypoint hands the log directory to www-data and starts php-fpm'
        );
        $this->assertSame(
            "root: gosu www-data php {$yii} migrate --interactive=0",
            $run['calls'][$migrate - 1] ?? null,
            'gosu starts the migrations'
        );
        $this->assertSame(
            "www-data: php {$yii} migrate --interactive=0 (no app.log)",
            $run['calls'][$migrate],
            'the migrations run as www-data, as php-fpm\'s workers, so a log file they create is theirs'
        );
    }

    /**
     * Any other command (the queue-worker, the schedule-runner, the runners
     * of the dev stack, a one-off command) runs as www-data, after the log
     * directory went to www-data and app.log was created as www-data: a root
     * process that writes the log later appends to the file instead of
     * creating it.
     */
    #[DataProvider('entrypointProvider')]
    public function testAnyOtherCommandStartsAsWwwDataWithTheLogsHandedToIt(string $entrypoint): void
    {
        $run = $this->runEntrypoint($entrypoint, ['php', 'yii', 'worker/start']);

        $this->assertSame(0, $run['status'], "the entrypoint ended with an error:\n{$run['out']}{$run['err']}");
        $this->assertSame(
            [
                'root: chown -R www-data:www-data ' . $this->logs(),
                'root: gosu www-data touch ' . $this->logs() . '/app.log',
                'root: gosu www-data php yii worker/start',
                'www-data: php yii worker/start (app.log exists)',
            ],
            array_slice($run['calls'], -4)
        );
        $this->assertSame(
            [],
            preg_grep('# migrate #', $run['calls']),
            'only the app container (php-fpm) runs the migrations'
        );
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function commandProvider(): array
    {
        $cases = [];
        foreach (self::ENTRYPOINTS as $image => $entrypoint) {
            $cases[$image . ', php-fpm'] = [$entrypoint, ['php-fpm']];
            $cases[$image . ', a worker'] = [$entrypoint, ['php', 'yii', 'worker/start']];
        }

        return $cases;
    }

    /**
     * When www-data cannot create app.log (a log directory nobody may write
     * to, a full or read-only disk, an app.log that links to a file of
     * root), set -e ends the entrypoint with the error of touch, before the
     * command starts: php-fpm or a worker would lose every log line without
     * a word.
     *
     * @param list<string> $command
     */
    #[DataProvider('commandProvider')]
    public function testALogFileThatWwwDataCannotCreateEndsTheEntrypointBeforeTheCommand(string $entrypoint, array $command): void
    {
        $touch = $this->directory . '/bin/touch';
        file_put_contents($touch, self::STUB_FAILING_TOUCH . "\n");
        chmod($touch, 0o755);

        $run = $this->runEntrypoint($entrypoint, $command);

        $this->assertSame(1, $run['status']);
        $this->assertSame("touch: cannot touch '" . $this->logs() . "/app.log': Permission denied\n", $run['err']);
        $this->assertSame(
            [
                'root: chown -R www-data:www-data ' . $this->logs(),
                'root: gosu www-data touch ' . $this->logs() . '/app.log',
                'www-data: touch ' . $this->logs() . '/app.log',
            ],
            array_slice($run['calls'], -3),
            'the command never starts'
        );
    }

    /**
     * The runner image runs its entrypoint as www-data: what it creates, the
     * log directory included, is www-data's, so it has nothing to hand over,
     * and a root step there would need the hand-over of the app image's
     * entrypoints.
     */
    public function testTheRunnerImageCreatesItsLogDirectoryAsWwwData(): void
    {
        $dockerfile = $this->read('docker/runner/Dockerfile');
        $finalStage = substr($dockerfile, (int)strrpos($dockerfile, "\nFROM "));
        $this->assertSame(1, preg_match_all('/^USER\s+(\S+)\s*$/m', $finalStage, $users), 'the final stage sets one user');
        $this->assertSame(['www-data'], $users[1]);
        $this->assertLessThan(
            (int)strpos($finalStage, "\nENTRYPOINT "),
            (int)strpos($finalStage, "\nUSER www-data"),
            'the user is set before the entrypoint'
        );

        $commands = (string)preg_replace('/^\s*#.*\n/m', '', $this->read('docker/runner/entrypoint.sh'));
        $this->assertMatchesRegularExpression('#^mkdir -p .*/var/www/runtime/logs( |$)#m', $commands, 'it creates the log directory');
        $this->assertDoesNotMatchRegularExpression('/\b(chown|gosu|su|sudo|setpriv)\b/', $commands, 'it changes no user');
    }

    private function logs(): string
    {
        return $this->directory . '/var/www/runtime/logs';
    }

    /**
     * Runs $entrypoint with the arguments $command under /bin/sh, its paths
     * in the scratch directory, and returns its exit status, its output and
     * the calls the stand-ins noted, one a line.
     *
     * @param list<string> $command
     * @return array{status: int, out: string, err: string, calls: list<string>}
     */
    private function runEntrypoint(string $entrypoint, array $command): array
    {
        $script = strtr($this->read($entrypoint), [
            '/var/www' => $this->directory . '/var/www',
            '/tmp/ansible' => $this->directory . '/tmp/ansible',
        ]);
        file_put_contents($this->directory . '/entrypoint.sh', $script);

        $process = proc_open(
            ['/bin/sh', $this->directory . '/entrypoint.sh', ...$command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
            [
                'PATH' => $this->directory . '/bin:' . (string)getenv('PATH'),
                'STUB_DIR' => $this->directory,
                'STUB_LOGS' => $this->logs(),
                // As the compose files of the deploy role and the prebuilt
                // images set it: the dev entrypoint then reads no .env.
                'DB_HOST' => 'db',
            ]
        );
        $this->assertIsResource($process);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $calls = is_file($this->directory . '/calls') ? (string)file_get_contents($this->directory . '/calls') : '';

        return [
            'status' => $status,
            'out' => $out,
            'err' => $err,
            'calls' => $calls === '' ? [] : explode("\n", rtrim($calls, "\n")),
        ];
    }

    /**
     * The index of the last call that matches $pattern.
     *
     * @param list<string> $calls
     */
    private function lastCallMatching(array $calls, string $pattern): int
    {
        $matching = preg_grep($pattern, $calls);
        $this->assertIsArray($matching);
        $this->assertNotSame([], $matching, "no call matches {$pattern}:\n" . implode("\n", $calls));

        return (int)array_key_last($matching);
    }

    private function read(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }
}
