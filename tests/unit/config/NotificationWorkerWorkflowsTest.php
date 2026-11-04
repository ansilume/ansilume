<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The workflows run the notification-worker: CI starts it with the services
 * the suites run against, so that the E2E suite checks the user of its main
 * process; the smoke test of a release waits until the released image runs
 * it healthy (a worker/start that refused --queue would restart without
 * end) and checks that the bundled runner cannot reach it, as it checks the
 * other containers of the server. The steps run as on GitHub, with a
 * stand-in for docker.
 */
final class NotificationWorkerWorkflowsTest extends TestCase
{
    use RunsWorkflowSteps;

    private const RELEASE = '.github/workflows/release.yml';

    private const CI = '.github/workflows/ci.yaml';

    /** docker for the release's steps: every container healthy, no backend resolvable from runner-1, Redis refuses. */
    private const DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        # docker for NotificationWorkerWorkflowsTest: notes each call in STUB_CALLS.
        printf '%s\n' "$*" >> "$STUB_CALLS"
        case "$*" in
            "inspect --format {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}} "*) echo healthy ;;
            *" ps --services --status running") printf 'app\nredis\n' ;;
            *" exec -T runner-1 getent hosts nginx.") echo "172.20.0.5 nginx" ;;
            *" exec -T runner-1 getent hosts "*) exit 2 ;;
            *" exec -T redis redis-cli ping") echo "NOAUTH Authentication required." ;;
        esac
        BASH;

    public function testCiStartsTheNotificationWorker(): void
    {
        $run = (string)($this->step(self::CI, 'test', 'Build and start services')['run'] ?? '');

        $this->assertSame(1, preg_match('/^\s*docker compose up -d app ([^\n]+)$/m', $run, $start), 'CI starts the app');
        $this->assertContains('notification-worker', preg_split('/\s+/', trim($start[1] ?? '')) ?: []);
    }

    public function testTheReleaseSmokeTestWaitsUntilTheReleasedImageRunsTheNotificationWorker(): void
    {
        $calls = $this->runStep('Wait for services to become healthy');

        $this->assertContains('inspect --format {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}} ansilume_notification_worker', $calls);
        $prebuilt = Yaml::parseFile(dirname(__DIR__, 3) . '/docker-compose.prebuilt.yml');
        $this->assertIsArray($prebuilt);
        $this->assertSame('ansilume_notification_worker', $prebuilt['services']['notification-worker']['container_name'] ?? null, 'the container the step waits for');
    }

    public function testTheReleaseSmokeTestChecksThatTheRunnerCannotReachTheNotificationWorker(): void
    {
        $calls = $this->runStep('Verify runner isolation and Redis authentication');

        $this->assertContains('compose -f docker-compose.prebuilt.yml exec -T runner-1 getent hosts notification-worker.', $calls);
    }

    /**
     * Runs the run script of a step of the smoke test with the stand-in for
     * docker; returns the docker calls.
     *
     * @return list<string>
     */
    private function runStep(string $name): array
    {
        $calls = $this->directory() . '/calls';
        touch($calls);
        $step = $this->step(self::RELEASE, 'smoke-test-arm64', $name);

        [$exit, $stdout, $stderr] = $this->runScript((string)($step['run'] ?? ''), ['STUB_CALLS' => $calls], ['docker' => self::DOCKER]);

        $this->assertSame(0, $exit, $stdout . $stderr);

        return array_values(array_filter(explode("\n", (string)file_get_contents($calls)), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $workflow, string $job, string $name): array
    {
        $jobs = $this->workflow($workflow)['jobs'] ?? null;
        $this->assertIsArray($jobs, "{$workflow} has jobs");
        $steps = is_array($jobs[$job] ?? null) ? ($jobs[$job]['steps'] ?? []) : [];
        foreach ((array)$steps as $step) {
            if (is_array($step) && ($step['name'] ?? null) === $name) {
                /** @var array<string, mixed> $step */
                return $step;
            }
        }
        $this->fail("Job {$job} of {$workflow} has no step named {$name}.");
    }
}
