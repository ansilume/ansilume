<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;

/**
 * Executes the real verify_install() from bin/quickstart in bash with a
 * stubbed curl, locking its pass/fail exit semantics: exit 0 when the stack
 * answers correctly, exit 1 when any check (health status, login page,
 * published asset) fails. String-presence contract tests alone cannot catch
 * a verify_install that reports failures but returns 0.
 */
class QuickstartVerifyInstallBehaviorTest extends TestCase
{
    private const CURL_STUB = <<<'BASH'
ok()   { echo "OK: $1"; }
warn() { echo "WARN: $1"; }
fail() { echo "FAIL: $1"; }
info() { echo "INFO: $1"; }
# Stand-in for `docker compose port nginx 80`: answers with STUB_COMPOSE_PORT
# when set, otherwise behaves like a stopped stack / missing docker.
docker() {
    if [ -n "${STUB_COMPOSE_PORT:-}" ]; then
        printf '%s\n' "$STUB_COMPOSE_PORT"
        return 0
    fi
    return 1
}
COMPOSE_CMD="docker compose"
curl() {
    local url="" has_o=0 has_w=0 a
    for a in "$@"; do
        case "$a" in
            http*) url="$a" ;;
            -o) has_o=1 ;;
            -w) has_w=1 ;;
        esac
    done
    # When the test pins the expected port, any probe against another port
    # is a transport failure — exactly what an operator sees when the script
    # guesses the wrong published port.
    if [ -n "${STUB_EXPECT_PORT:-}" ] && [[ "$url" != *":${STUB_EXPECT_PORT}/"* ]]; then
        printf '000'
        return 0
    fi
    case "$url" in
        */health)
            if [ "$has_w" = "1" ] && [ "$has_o" = "0" ]; then
                printf '%s\n%s' "$STUB_HEALTH_BODY" "$STUB_HEALTH_CODE"
            else
                printf '%s' "$STUB_HEALTH_CODE"
            fi ;;
        */login)
            if [ "$has_o" = "1" ]; then printf '%s' "$STUB_LOGIN_CODE"; else printf '%s' "$STUB_LOGIN_BODY"; fi ;;
        */assets/*)
            printf '%s' "$STUB_ASSET_CODE" ;;
        *)
            printf '%s' "$STUB_ROOT_CODE" ;;
    esac
    return 0
}
NGINX_PORT=8080

BASH;

    /**
     * @param array<string, string> $stubEnv
     * @return array{0: int, 1: string}
     */
    private function runVerifyInstall(array $stubEnv): array
    {
        $quickstart = file_get_contents(dirname(__DIR__, 3) . '/bin/quickstart');
        $this->assertNotFalse($quickstart, 'Could not read bin/quickstart');

        $this->assertSame(
            1,
            preg_match('/^http_code_label\(\) \{.*?\n\}/ms', $quickstart, $label),
            'Could not extract http_code_label() from bin/quickstart'
        );
        $this->assertSame(
            1,
            preg_match('/^verify_install\(\) \{.*?\n\}/ms', $quickstart, $verify),
            'Could not extract verify_install() from bin/quickstart'
        );

        $harness = self::CURL_STUB . $label[0] . "\n" . $verify[0] . "\nverify_install\n";

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['bash', '-c', $harness], $descriptors, $pipes, null, $stubEnv);
        $this->assertIsResource($process);

        $output = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, $output];
    }

    /**
     * @return array<string, string>
     */
    private function healthyStub(): array
    {
        return [
            'STUB_HEALTH_CODE' => '200',
            'STUB_HEALTH_BODY' => '{"status":"ok","checks":{}}',
            'STUB_ROOT_CODE' => '302',
            'STUB_LOGIN_CODE' => '200',
            'STUB_LOGIN_BODY' => '<script src="/assets/c2fd326f/jquery.js"></script>',
            'STUB_ASSET_CODE' => '200',
        ];
    }

    public function testHealthyStackPassesWithExitZero(): void
    {
        [$exitCode, $output] = $this->runVerifyInstall($this->healthyStub());

        $this->assertSame(0, $exitCode, "verify_install must exit 0 on a healthy stack:\n{$output}");
        $this->assertStringNotContainsString('FAIL:', $output);
    }

    /**
     * Regression: the probe port was read from .env (NGINX_PORT) only. When
     * the live compose binding differed — environment override, stale .env
     * after a port change — verification hit the wrong port and failed a
     * healthy install. The published port reported by compose must win.
     */
    public function testLiveComposePortBindingWinsOverNginxPortVariable(): void
    {
        $stub = $this->healthyStub();
        $stub['STUB_COMPOSE_PORT'] = '0.0.0.0:9911';
        $stub['STUB_EXPECT_PORT'] = '9911';

        [$exitCode, $output] = $this->runVerifyInstall($stub);

        $this->assertSame(0, $exitCode, "verify_install must probe the port compose reports:\n{$output}");
        $this->assertStringNotContainsString('FAIL:', $output);
    }

    public function testIpv6ComposeBindingIsReducedToBarePort(): void
    {
        $stub = $this->healthyStub();
        $stub['STUB_COMPOSE_PORT'] = '[::]:9911';
        $stub['STUB_EXPECT_PORT'] = '9911';

        [$exitCode, $output] = $this->runVerifyInstall($stub);

        $this->assertSame(0, $exitCode, "an [::]:port binding must be reduced to the bare port:\n{$output}");
    }

    public function testFallsBackToNginxPortWhenComposeCannotReportTheBinding(): void
    {
        $stub = $this->healthyStub();
        // No STUB_COMPOSE_PORT → the docker stub fails like a stopped stack.
        $stub['STUB_EXPECT_PORT'] = '8080';

        [$exitCode, $output] = $this->runVerifyInstall($stub);

        $this->assertSame(0, $exitCode, "verify_install must fall back to NGINX_PORT (8080 in the stub):\n{$output}");
    }

    public function testWrongPortIsReportedAsUnreachableNotAsSuccess(): void
    {
        $stub = $this->healthyStub();
        $stub['STUB_COMPOSE_PORT'] = '0.0.0.0:9911';
        $stub['STUB_EXPECT_PORT'] = '8080';

        [$exitCode, $output] = $this->runVerifyInstall($stub);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('unreachable', $output);
    }

    public function testMissingAssetFailsWithExitOne(): void
    {
        $stub = $this->healthyStub();
        $stub['STUB_ASSET_CODE'] = '404';

        [$exitCode, $output] = $this->runVerifyInstall($stub);

        $this->assertSame(1, $exitCode, "a 404 asset (missing web_assets volume) must fail verification:\n{$output}");
        $this->assertStringContainsString('web_assets', $output);
    }

    public function testDegradedHealthFailsWithExitOne(): void
    {
        $stub = $this->healthyStub();
        $stub['STUB_HEALTH_BODY'] = '{"status":"degraded","checks":{}}';

        [$exitCode, $output] = $this->runVerifyInstall($stub);

        $this->assertSame(1, $exitCode, "a degraded health body must fail verification even with HTTP 200:\n{$output}");
    }
}
