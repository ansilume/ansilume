<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\commands\SetupController;
use app\helpers\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real creation of the first admin from bin/quickstart (step 11,
 * from its debug line to the end of its case) in bash under the
 * quickstart's set -euo pipefail and with its own output helpers, with a
 * stand-in for docker on PATH that notes each call.
 *
 * Regression: the quickstart ran `docker compose exec -T app php yii
 * setup/admin` as root, the user of the app image, and the hint after a
 * failure named the same command. setup/admin logs to runtime/logs/app.log:
 * as root, a run that rotated a full log left copies that php-fpm's workers
 * (www-data) cannot write, and one that found no log created it as root's,
 * so that they logged no error of the web UI and the API. Both run as
 * www-data now, as php-fpm's workers do (YiiExecUserTest).
 */
final class QuickstartAdminUserTest extends TestCase
{
    /** The creation of the first admin, from its debug line to the end of its case. */
    private const STEP = '/^debug "Creating admin user: .*?^esac\n/ms';

    /**
     * docker for this test: notes each call in STUB_CALLS and answers the
     * exec of setup/admin with STUB_ANSWER, exiting with STUB_EXIT.
     */
    private const STUB_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        printf '%s\n' "$*" >> "$STUB_CALLS"
        case "$*" in
            "compose -f docker-compose.prebuilt.yml exec "*" app php yii setup/admin "*)
                printf '%s\n' "$STUB_ANSWER"
                exit "$STUB_EXIT" ;;
        esac
        echo "unexpected: docker $*" >&2
        exit 1
        BASH;

    /** The admin the quickstart creates. */
    private const ADMIN = ['ADMIN_USER' => 'admin', 'ADMIN_EMAIL' => 'admin@example.com', 'ADMIN_PASSWORD' => 'not-a-real-password-8d2c'];

    /** The exec of setup/admin as www-data. */
    private const EXEC = 'compose -f docker-compose.prebuilt.yml exec -T -u www-data app php yii setup/admin admin admin@example.com not-a-real-password-8d2c';

    /** What the harness prints once the step has let the quickstart go on. */
    private const GOES_ON = 'The quickstart goes on.';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quickstart-admin-user-' . uniqid('', true);
        mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/docker', self::STUB_DOCKER . "\n");
        chmod($this->directory . '/docker', 0o755);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function createdProvider(): array
    {
        return [
            'created' => ["Admin user 'admin' created with ID 1.", "  ✓ Admin user created\n"],
            'an active superadmin exists' => [SetupController::ACTIVE_SUPERADMIN_EXISTS, "  ✓ Admin user already exists — skipping\n"],
        ];
    }

    #[DataProvider('createdProvider')]
    public function testRegressionSetupAdminRunsAsWwwData(string $answer, string $reported): void
    {
        [$status, $output, $calls] = $this->runStep($answer, '0');

        $this->assertSame([self::EXEC], $calls, 'one exec of setup/admin, as www-data');
        $this->assertStringContainsString("  Creating admin user...\n" . $reported . self::GOES_ON . "\n", $output);
        $this->assertSame(0, $status, $output);
    }

    /**
     * The hint after a failure names the same command, as www-data, without
     * the password.
     */
    public function testRegressionTheHintAfterAFailureRunsAsWwwData(): void
    {
        [$status, $output, $calls] = $this->runStep('Failed to create user: {"email":["Email is not a valid email address."]}', '1');

        $this->assertSame([self::EXEC], $calls);
        $this->assertStringContainsString(
            "    Failed to create user: {\"email\":[\"Email is not a valid email address.\"]}\n",
            $output,
            'what setup/admin said'
        );
        $this->assertStringContainsString(
            "  Try waiting a moment and running manually:\n"
            . "    docker compose -f docker-compose.prebuilt.yml exec -u www-data app php yii setup/admin admin admin@example.com <password>\n",
            $output
        );
        $this->assertStringContainsString("Error: Admin user creation failed (exit code: 1).\n", $output);
        $this->assertStringNotContainsString(self::ADMIN['ADMIN_PASSWORD'], $output);
        $this->assertStringNotContainsString(self::GOES_ON, $output);
        $this->assertSame(1, $status, $output);
    }

    /**
     * Runs the step under the options of the quickstart, with its output
     * helpers, the stand-in for docker and the admin of ADMIN, and then the
     * line that shows the quickstart goes on.
     *
     * @return array{0: int, 1: string, 2: list<string>} exit status, output without colours, the calls of docker
     */
    private function runStep(string $answer, string $exit): array
    {
        $quickstart = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/quickstart');
        $this->assertStringContainsString("\nset -euo pipefail\n", $quickstart, 'The harness runs the step with the options of the quickstart.');
        $this->assertSame(1, preg_match('/^RED=.*?^die\(\)[^\n]*\n/ms', $quickstart, $helpers), 'Could not extract the output helpers');
        $this->assertSame(1, preg_match(self::STEP, $quickstart, $step), 'Could not extract the creation of the first admin');

        $process = proc_open(
            ['bash', '-c', "set -euo pipefail\n" . $helpers[0] . $step[0] . 'echo "' . self::GOES_ON . "\"\n"],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->directory,
            [
                'PATH' => $this->directory . ':' . (string)getenv('PATH'),
                'VERBOSE' => '0',
                'COMPOSE_CMD' => 'docker compose',
                'COMPOSE_ARGS' => '-f docker-compose.prebuilt.yml',
                'STUB_CALLS' => $this->directory . '/calls',
                'STUB_ANSWER' => $answer,
                'STUB_EXIT' => $exit,
            ] + self::ADMIN
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        $calls = is_file($this->directory . '/calls') ? file($this->directory . '/calls', FILE_IGNORE_NEW_LINES) : [];

        return [$status, (string)preg_replace('/\e\[[0-9;]*m/', '', $output), $calls === false ? [] : $calls];
    }
}
