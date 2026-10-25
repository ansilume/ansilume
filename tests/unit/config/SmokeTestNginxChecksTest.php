<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the steps of the smoke test of .github/workflows/release.yml that ask
 * nginx for /health and /openapi.yaml, in every job that has them, as GitHub
 * runs them: the job names no shell, so GitHub runs each run script from a
 * file with bash -e {0}, without pipefail (RunsWorkflowSteps::githubShell()).
 * A stand-in for curl answers as curl does: a connection refused (exit 7) or
 * closed without an answer (52) writes no -o file and 000 as the status, a
 * body cut off ends with exit 18 after the 200. The steps keep the body in
 * /tmp; each run here keeps it in a directory of its own instead, the only
 * change to the run scripts, which hold no ${{ }} expression.
 *
 * Regression: both steps kept the status with
 * `code=$(curl -sS -o … -w '%{http_code}' …)` and tested it after the
 * assignment, so when nginx did not answer, set -e ended the step at the
 * assignment with curl's exit code: its ::error:: annotation ("/health
 * returned HTTP 000"), the body and the first lines of the spec never
 * appeared, only curl's own line. Fixing the assignment alone still ended
 * the /health step at `cat`, as curl wrote no body file. A body cut off
 * (curl exit 18) ended the steps the same way; they now report it with
 * their own message, and still fail on it.
 *
 * @phpstan-type Answer array{status: string, exit: int, body: ?string, error: string}
 */
class SmokeTestNginxChecksTest extends TestCase
{
    use RunsWorkflowSteps;

    private const RELEASE = '.github/workflows/release.yml';

    private const HEALTH = 'Check /health through nginx';

    private const SPEC = 'Check /openapi.yaml through nginx';

    /** The body file and the URL of the curl call of each step. */
    private const CALLS = [
        self::HEALTH => ['health.json', 'http://localhost:8080/health'],
        self::SPEC => ['openapi.yaml', 'http://localhost:8080/openapi.yaml'],
    ];

    private const HEALTHY = '{"status":"ok","checks":{"database":{"ok":true},"redis":{"ok":true}}}';

    private const DEGRADED = '{"status":"degraded","checks":{"database":{"ok":true},'
        . '"redis":{"ok":false,"error":"Redis unreachable"}}}';

    private const HEALTHY_CUT = '{"status":"ok","checks":{"data';

    private const OPENAPI = "openapi: 3.1.0\ninfo:\n  title: Ansilume REST API\n  version: 2.13.0\n";

    private const OPENAPI_CUT = "openapi: 3.1.0\ninfo:\n  title: Ansil";

    /** The first five lines of nginx's own page for a 503. */
    private const UNAVAILABLE_HEAD = "<html>\n<head><title>503 Service Temporarily Unavailable</title></head>\n"
        . "<body>\n<center><h1>503 Service Temporarily Unavailable</h1></center>\n<hr><center>nginx</center>\n";

    private const UNAVAILABLE = self::UNAVAILABLE_HEAD . "</body>\n</html>\n";

    private const REFUSED = "curl: (7) Failed to connect to localhost port 8080 after 0 ms: Couldn't connect to server";

    private const TRUNCATED = 'curl: (18) transfer closed with 57 bytes remaining to read';

    private const STUB_CURL = <<<'BASH'
        #!/usr/bin/env bash
        # curl -sS -o <file> -w '%{http_code}' <url> for SmokeTestNginxChecksTest,
        # answered as curl answers it: the body in the -o file (no file without
        # an answer), the status on stdout, curl's error on stderr, its exit code.
        echo "$*" >> "$STUB_CALLS"
        while [ $# -gt 0 ]; do
            if [ "$1" = -o ]; then
                output="$2"
            fi
            shift
        done
        if [ -n "${STUB_BODY+set}" ]; then
            printf '%s' "$STUB_BODY" > "$output"
        fi
        if [ -n "$STUB_ERROR" ]; then
            echo "$STUB_ERROR" >&2
        fi
        printf '%s' "$STUB_STATUS"
        exit "$STUB_EXIT"
        BASH;

    /**
     * @return array<string, array{0: string, 1: Answer, 2: int, 3: string}>
     */
    public static function answerProvider(): array
    {
        $refused = self::answer('000', 7, null, self::REFUSED);
        $closed = self::answer('000', 52, null, 'curl: (52) Empty reply from server');

        return [
            '/health: nginx refuses the connection' => [
                self::HEALTH,
                $refused,
                1,
                "\n::error::/health returned HTTP 000 (curl exit 7)\n",
            ],
            '/health: nginx closes it without an answer' => [
                self::HEALTH,
                $closed,
                1,
                "\n::error::/health returned HTTP 000 (curl exit 52)\n",
            ],
            '/health: a 503 with a body' => [
                self::HEALTH,
                self::answer('503', 0, self::DEGRADED),
                1,
                self::DEGRADED . "\n::error::/health returned HTTP 503 (curl exit 0)\n",
            ],
            '/health: a 200 whose body is cut off' => [
                self::HEALTH,
                self::answer('200', 18, self::HEALTHY_CUT, self::TRUNCATED),
                1,
                self::HEALTHY_CUT . "\n::error::/health returned HTTP 200 (curl exit 18)\n",
            ],
            '/health: a good 200 passes' => [
                self::HEALTH,
                self::answer('200', 0, self::HEALTHY),
                0,
                self::HEALTHY . "\n",
            ],
            '/openapi.yaml: nginx refuses the connection' => [
                self::SPEC,
                $refused,
                1,
                "::error::/openapi.yaml returned HTTP 000 without the spec (curl exit 7)\n",
            ],
            '/openapi.yaml: nginx closes it without an answer' => [
                self::SPEC,
                $closed,
                1,
                "::error::/openapi.yaml returned HTTP 000 without the spec (curl exit 52)\n",
            ],
            '/openapi.yaml: a 503 with a body' => [
                self::SPEC,
                self::answer('503', 0, self::UNAVAILABLE),
                1,
                "::error::/openapi.yaml returned HTTP 503 without the spec (curl exit 0)\n" . self::UNAVAILABLE_HEAD,
            ],
            '/openapi.yaml: a 200 whose spec is cut off after its first line' => [
                self::SPEC,
                self::answer('200', 18, self::OPENAPI_CUT, self::TRUNCATED),
                1,
                "::error::/openapi.yaml returned HTTP 200 without the spec (curl exit 18)\n" . self::OPENAPI_CUT,
            ],
            '/openapi.yaml: a good 200 passes' => [
                self::SPEC,
                self::answer('200', 0, self::OPENAPI),
                0,
                '',
            ],
        ];
    }

    /**
     * @param Answer $answer
     */
    #[DataProvider('answerProvider')]
    public function testRegressionTheStepReportsWhatNginxAnswered(
        string $name,
        array $answer,
        int $exitCode,
        string $stdout,
    ): void {
        [$file, $url] = self::CALLS[$name];
        foreach ($this->jobsWith($name) as $job => $step) {
            [$code, $out, $stderr, $calls] = $this->runCheck($job, $step, $answer);

            $this->assertSame(["-sS -o DIR/{$file} -w %{http_code} {$url}"], $calls, "{$job}: the call of the step");
            $this->assertSame([$exitCode, $stdout], [$code, $out], "{$job}, stderr: {$stderr}");
        }
    }

    /**
     * @return Answer
     */
    private static function answer(string $status, int $exit, ?string $body, string $error = ''): array
    {
        return ['status' => $status, 'exit' => $exit, 'body' => $body, 'error' => $error];
    }

    /**
     * Runs the run script of $step of $job as GitHub does, with curl
     * answering $answer.
     *
     * @param array<string, mixed> $step
     * @param Answer $answer
     * @return array{0: int, 1: string, 2: string, 3: list<string>} exit code, stdout, stderr, curl calls
     */
    private function runCheck(string $job, array $step, array $answer): array
    {
        $directory = $this->directory();
        $script = (string)($step['run'] ?? '');
        $this->assertStringContainsString(' -o /tmp/', $script, "{$job}: the step keeps the body in /tmp");
        $this->assertStringNotContainsString('${{', $script, "{$job}: replace an expression as GitHub does");
        $env = [
            'STUB_CALLS' => $directory . '/curl-calls',
            'STUB_STATUS' => $answer['status'],
            'STUB_EXIT' => (string)$answer['exit'],
            'STUB_ERROR' => $answer['error'],
        ];
        if ($answer['body'] !== null) {
            $env['STUB_BODY'] = $answer['body'];
        }

        [$code, $stdout, $stderr] = $this->runScriptWithShell(
            $this->githubShell($this->workflow(self::RELEASE), $job, $step),
            str_replace('/tmp/', $directory . '/', $script),
            $env,
            ['curl' => self::STUB_CURL],
        );
        $log = $directory . '/curl-calls';
        $calls = is_file($log) ? explode("\n", trim((string)file_get_contents($log))) : [];

        return [$code, $stdout, $stderr, str_replace($directory, 'DIR', $calls)];
    }

    /**
     * The jobs of release.yml with a step named $name, each with that step:
     * the arm64 smoke test, and any other smoke test that copies it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function jobsWith(string $name): array
    {
        $found = [];
        foreach ($this->workflow(self::RELEASE)['jobs'] as $job => $definition) {
            foreach ($definition['steps'] ?? [] as $step) {
                if (($step['name'] ?? null) === $name) {
                    $found[(string)$job] = $step;
                }
            }
        }
        $this->assertArrayHasKey('smoke-test-arm64', $found, "The arm64 smoke test has the step {$name}.");

        return $found;
    }
}
