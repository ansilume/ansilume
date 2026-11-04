<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The quickstart checks, after an install and after an update, that the
 * services that work in the background run: the queue-worker (project
 * syncs), the notification-worker (notifications) and the schedule-runner
 * (schedules, maintenance). None answers HTTP, so verify_install() cannot
 * see one that does not run: a notification-worker that cannot start, such
 * as the one of a compose file of this release with images pinned to an
 * older release ("Unknown option: --queue"), would leave every notification
 * unsent behind "Ansilume is ready!".
 *
 * Runs the real verify_workers() of bin/quickstart in bash, with a stand-in
 * for docker that answers for the containers of STUB_STATES
 * ("<service>=<state> …"); a service it does not name has no container.
 */
class QuickstartVerifyWorkersTest extends TestCase
{
    private const HARNESS = <<<'BASH'
        ok()   { echo "OK: $1"; }
        warn() { echo "WARN: $1"; }
        fail() { echo "FAIL: $1"; }
        info() { echo "INFO: $1"; }
        # docker compose [args] ps -q <service> prints the container of a service
        # of STUB_STATES (its name with -cid), docker inspect its state. Every call
        # is noted in STUB_CALLS.
        docker() {
            printf '%s\n' "$*" >> "$STUB_CALLS"
            local entry service
            case "$*" in
                "compose ${STUB_EXPECT_ARGS:+$STUB_EXPECT_ARGS }ps -q "*)
                    service=${!#}
                    for entry in $STUB_STATES; do
                        if [ "${entry%%=*}" = "$service" ]; then
                            echo "${service}-cid"
                        fi
                    done ;;
                "inspect --format {{.State.Status}} "*)
                    service=${!#}
                    service=${service%-cid}
                    for entry in $STUB_STATES; do
                        if [ "${entry%%=*}" = "$service" ]; then
                            echo "${entry#*=}"
                        fi
                    done ;;
                *) return 1 ;;
            esac
        }
        COMPOSE_CMD="docker compose"
        COMPOSE_ARGS="${STUB_EXPECT_ARGS:-}"

        BASH;

    /** The services the check covers, as it names them. */
    private const WORKERS = ['queue-worker', 'notification-worker', 'schedule-runner'];

    private string $calls = '';

    protected function setUp(): void
    {
        $this->calls = (string)tempnam(sys_get_temp_dir(), 'quickstart-verify-workers-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->calls)) {
            unlink($this->calls);
        }
    }

    public function testEveryWorkerThatRunsPasses(): void
    {
        [$exit, $output] = $this->runVerifyWorkers('queue-worker=running notification-worker=running schedule-runner=running');

        $this->assertSame(0, $exit, $output);
        $this->assertSame(
            "OK: queue-worker: running\nOK: notification-worker: running\nOK: schedule-runner: running\n",
            $output
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function notificationWorkerDownProvider(): array
    {
        return [
            'it restarts without end, as with images of an older release' => [
                'queue-worker=running notification-worker=restarting schedule-runner=running',
                'FAIL: notification-worker: not running (restarting) — see docker compose logs notification-worker',
            ],
            'it exited' => [
                'queue-worker=running notification-worker=exited schedule-runner=running',
                'FAIL: notification-worker: not running (exited) — see docker compose logs notification-worker',
            ],
            'it has no container, as with a compose file of an older release' => [
                'queue-worker=running schedule-runner=running',
                'FAIL: notification-worker: not running — see docker compose logs notification-worker',
            ],
        ];
    }

    #[DataProvider('notificationWorkerDownProvider')]
    public function testAWorkerThatDoesNotRunFailsTheCheckWithWhereToLook(string $states, string $line): void
    {
        [$exit, $output] = $this->runVerifyWorkers($states);

        $this->assertSame(1, $exit, $output);
        $this->assertSame("OK: queue-worker: running\n{$line}\nOK: schedule-runner: running\n", $output);
    }

    /**
     * An installation the quickstart made from source runs
     * docker-compose.prebuilt.yml: the check asks that file, and its hint
     * names it.
     */
    public function testTheCheckAsksTheComposeFileOfTheInstallation(): void
    {
        [$exit, $output] = $this->runVerifyWorkers('queue-worker=running schedule-runner=exited', '-f docker-compose.prebuilt.yml');

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('FAIL: notification-worker: not running — see docker compose -f docker-compose.prebuilt.yml logs notification-worker', $output);
        $this->assertStringContainsString('FAIL: schedule-runner: not running (exited) — see docker compose -f docker-compose.prebuilt.yml logs schedule-runner', $output);
        foreach (self::WORKERS as $worker) {
            $this->assertStringContainsString("compose -f docker-compose.prebuilt.yml ps -q {$worker}\n", (string)file_get_contents($this->calls));
        }
    }

    /**
     * The install and the update run the check after verify_install(), and
     * a worker that does not run fails them as a failed HTTP check does.
     */
    public function testTheInstallAndTheUpdateRunTheCheck(): void
    {
        $quickstart = $this->quickstart();

        $this->assertStringContainsString("verify_install || VERIFY_OK=0\nverify_workers || VERIFY_OK=0\n", $quickstart);
        $this->assertStringContainsString("    verify_install || UPDATE_OK=0\n    verify_workers || UPDATE_OK=0\n", $quickstart);
        $this->assertStringNotContainsString('HTTP checks above did not pass', $quickstart, 'the failure banner names no HTTP checks only');
    }

    /**
     * Each service the check covers is one that every compose file the
     * quickstart runs has.
     */
    public function testTheCheckedServicesAreThoseOfTheComposeFiles(): void
    {
        foreach (['docker-compose.yml', 'docker-compose.prebuilt.yml'] as $file) {
            $compose = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 3) . '/' . $file);
            $this->assertIsArray($compose);
            foreach (self::WORKERS as $worker) {
                $this->assertArrayHasKey($worker, (array)($compose['services'] ?? []), "{$file} has {$worker}");
            }
        }
    }

    /**
     * Runs verify_workers() of bin/quickstart with the stand-in for docker.
     *
     * @return array{0: int, 1: string} exit code, output
     */
    private function runVerifyWorkers(string $states, string $composeArgs = ''): array
    {
        $this->assertSame(1, preg_match('/^verify_workers\(\) \{.*?\n\}/ms', $this->quickstart(), $function), 'bin/quickstart defines verify_workers()');
        $process = proc_open(
            ['bash', '-c', self::HARNESS . ($function[0] ?? '') . "\nverify_workers\n"],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            null,
            ['PATH' => (string)getenv('PATH'), 'STUB_STATES' => $states, 'STUB_CALLS' => $this->calls, 'STUB_EXPECT_ARGS' => $composeArgs]
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    private function quickstart(): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/bin/quickstart');
        $this->assertNotFalse($content);

        return $content;
    }
}
