<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * Quickstart gives the bundled Redis a password, but only once both sides
 * support it: the compose file must pass REDIS_PASSWORD to the redis service
 * and the pulled app image must know it. An external Redis gets an empty value,
 * because only the operator knows its password.
 */
class QuickstartRedisPasswordTest extends TestCase
{
    private const PROBE_MARKER = '/var/www/components/RedisSettings.php';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/quickstart_redis_' . uniqid('', true);
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->dir);
    }

    private function quickstart(): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/bin/quickstart');
        $this->assertNotFalse($content);

        return $content;
    }

    private function extractFunction(string $name): string
    {
        $this->assertSame(
            1,
            preg_match('/^' . preg_quote($name, '/') . '\(\) \{.*?\n\}/ms', $this->quickstart(), $m),
            "could not extract {$name}() from bin/quickstart"
        );

        return $m[0];
    }

    /**
     * Runs bash with the given quickstart functions against the scratch dir.
     *
     * @param list<string> $functions
     * @param array<string, string> $env
     */
    private function runFunctions(array $functions, string $call, array $env = []): string
    {
        $harness = "ok() { echo \"OK: \$1\"; }\nwarn() { echo \"WARN: \$1\"; }\ndebug() { echo \"DEBUG: \$1\"; }\n";
        foreach ($functions as $function) {
            $harness .= $this->extractFunction($function) . "\n";
        }
        $process = proc_open(
            ['bash', '-c', $harness . $call . "\n"],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + ['INSTALL_DIR' => $this->dir, 'PATH' => (string)getenv('PATH')]
        );
        $this->assertIsResource($process);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err);

        return $out;
    }

    /**
     * Runs the real ensure_redis_password() with a fake compose CLI whose
     * exit code says whether the app image ships the REDIS_PASSWORD support.
     *
     * @return array{env: string, output: string, composeArgs: string}
     */
    private function ensureRedisPassword(string $env, bool $composeSupports = true, bool $imageSupports = true): array
    {
        file_put_contents($this->dir . '/.env', $env);
        file_put_contents(
            $this->dir . '/docker-compose.yml',
            $composeSupports
                ? "services:\n  redis:\n    environment:\n      REDIS_PASSWORD: \${REDIS_PASSWORD:-}\n"
                : "services:\n  redis:\n    image: redis:7-alpine\n"
        );
        file_put_contents($this->dir . '/fake-compose', "#!/bin/sh\nprintf '%s ' \"\$@\" > \"\$FAKE_COMPOSE_LOG\"\nexit \"\$FAKE_COMPOSE_RC\"\n");
        chmod($this->dir . '/fake-compose', 0o700);

        $output = $this->runFunctions(
            ['gen_secret', 'compose_supports_redis_password', 'image_supports_redis_password', 'ensure_redis_password'],
            'ensure_redis_password',
            [
                'COMPOSE_CMD' => $this->dir . '/fake-compose',
                'COMPOSE_ARGS' => '',
                'COMPOSE_FILE' => 'docker-compose.yml',
                'FAKE_COMPOSE_LOG' => $this->dir . '/compose.log',
                'FAKE_COMPOSE_RC' => $imageSupports ? '0' : '1',
            ]
        );

        return [
            'env' => (string)file_get_contents($this->dir . '/.env'),
            'output' => $output,
            'composeArgs' => is_file($this->dir . '/compose.log') ? trim((string)file_get_contents($this->dir . '/compose.log')) : '',
        ];
    }

    public function testBundledRedisGetsAGeneratedPasswordWhenComposeAndImageSupportIt(): void
    {
        $result = $this->ensureRedisPassword("DB_HOST=db\nREDIS_HOST=redis\n");

        $this->assertSame(1, preg_match_all('/^REDIS_PASSWORD=[0-9A-Za-z]{32}$/m', $result['env']), $result['env']);
        $this->assertStringContainsString('OK: Enabled Redis authentication', $result['output']);
        $this->assertSame('run --rm --no-deps -T --entrypoint test app -f ' . self::PROBE_MARKER, $result['composeArgs']);
    }

    /**
     * The image probe looks for this file; renaming it would silently stop
     * quickstart from ever enabling Redis authentication.
     */
    public function testTheProbedFileShipsWithTheImage(): void
    {
        $this->assertFileExists(dirname(__DIR__, 3) . '/components/RedisSettings.php');
        $this->assertStringContainsString('-f ' . self::PROBE_MARKER, $this->extractFunction('image_supports_redis_password'));
    }

    public function testAMissingRedisHostCountsAsTheBundledRedis(): void
    {
        $result = $this->ensureRedisPassword("DB_HOST=db\n");

        $this->assertMatchesRegularExpression('/^REDIS_PASSWORD=[0-9A-Za-z]{32}$/m', $result['env']);
    }

    public function testTheLastRedisHostLineWins(): void
    {
        $result = $this->ensureRedisPassword("REDIS_HOST=redis.example.com\nREDIS_HOST=redis\n");

        $this->assertMatchesRegularExpression('/^REDIS_PASSWORD=[0-9A-Za-z]{32}$/m', $result['env']);
    }

    /**
     * Regression: --update enabled REDIS_PASSWORD before pulling, so an app
     * image older than the compose file lost its Redis connection (NOAUTH).
     */
    public function testAnOlderAppImageLeavesRedisWithoutAPassword(): void
    {
        $result = $this->ensureRedisPassword("REDIS_HOST=redis\n", true, false);

        $this->assertStringNotContainsString('REDIS_PASSWORD', $result['env']);
        $this->assertStringContainsString('DEBUG: Redis authentication not enabled yet', $result['output']);
    }

    public function testAnOlderComposeFileLeavesRedisWithoutAPassword(): void
    {
        $result = $this->ensureRedisPassword("REDIS_HOST=redis\n", false);

        $this->assertStringNotContainsString('REDIS_PASSWORD', $result['env']);
        $this->assertSame('', $result['composeArgs'], 'no image probe without compose support');
    }

    public function testAnExternalRedisGetsAnEmptyPasswordAndAWarning(): void
    {
        $result = $this->ensureRedisPassword("REDIS_HOST=redis.example.com\n");

        $this->assertMatchesRegularExpression('/^REDIS_PASSWORD=$/m', $result['env']);
        $this->assertStringContainsString('WARN: Added REDIS_PASSWORD= (external Redis)', $result['output']);
        $this->assertSame('', $result['composeArgs']);
    }

    public function testAnExistingPasswordIsKept(): void
    {
        $result = $this->ensureRedisPassword("REDIS_HOST=redis\nREDIS_PASSWORD=keep-me\n");

        $this->assertSame(1, preg_match_all('/^REDIS_PASSWORD=/m', $result['env']));
        $this->assertStringContainsString("REDIS_PASSWORD=keep-me\n", $result['env']);
        $this->assertSame('', $result['composeArgs']);
    }

    public function testMergeEnvNoLongerAddsTheRedisPassword(): void
    {
        file_put_contents($this->dir . '/.env', "DB_HOST=db\nREDIS_HOST=redis\n");

        $this->runFunctions(['gen_secret', 'merge_env'], 'merge_env');

        $this->assertStringNotContainsString('REDIS_PASSWORD', (string)file_get_contents($this->dir . '/.env'));
    }

    public function testFreshInstallsNoLongerWriteThePasswordUpFront(): void
    {
        $quickstart = $this->quickstart();

        $this->assertStringNotContainsString('REDIS_PASSWORD="$(gen_secret', $quickstart);
        $this->assertStringNotContainsString('REDIS_PASSWORD=${REDIS_PASSWORD}', $quickstart);
    }

    /**
     * The password may only be enabled after the images are pulled (so the
     * probe sees the image that will run) and before the containers start.
     */
    public function testThePasswordIsEnabledBetweenPullAndUp(): void
    {
        $lines = explode("\n", $this->quickstart());
        $calls = array_keys(array_filter($lines, static fn (string $l): bool => trim($l) === 'ensure_redis_password'));

        $this->assertCount(2, $calls, 'update path and fresh install');
        foreach ($calls as $index) {
            $this->assertStringContainsString(' pull ', $this->nearestComposeCommand($lines, $index, -1));
            $this->assertStringContainsString(' up -d', $this->nearestComposeCommand($lines, $index, 1));
        }
    }

    /**
     * @param list<string> $lines
     */
    private function nearestComposeCommand(array $lines, int $from, int $step): string
    {
        for ($i = $from + $step; isset($lines[$i]); $i += $step) {
            if (str_contains($lines[$i], '$COMPOSE_CMD $COMPOSE_ARGS')) {
                return $lines[$i];
            }
        }
        $this->fail('no compose command found next to line ' . ($from + 1));
    }
}
