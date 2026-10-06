<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;

/**
 * Contract test for bin/quickstart post-install verification.
 *
 * Regression: quickstart finished with "Ansilume is ready!" without ever
 * proving the stack answers HTTP, and printed a hardcoded
 * http://localhost:<port> URL even when the operator configured an external
 * APP_URL. Operators then tested with a bare `curl` (empty 302 body) or an
 * https:// URL (no TLS listener) and concluded the install was broken.
 */
class QuickstartVerificationTest extends TestCase
{
    private function quickstartContent(): string
    {
        $content = file_get_contents($this->quickstartPath());
        $this->assertNotFalse($content, 'Could not read bin/quickstart');
        return $content;
    }

    private function quickstartPath(): string
    {
        return dirname(__DIR__, 3) . '/bin/quickstart';
    }

    public function testDefinesVerifyInstallFunction(): void
    {
        $this->assertStringContainsString(
            'verify_install() {',
            $this->quickstartContent(),
            'quickstart must define a verify_install() helper that curls the running stack'
        );
    }

    public function testVerifyInstallChecksHealthLoginAndAssets(): void
    {
        $content = $this->quickstartContent();

        $this->assertSame(
            1,
            preg_match('/verify_install\(\) \{(.+?)\n\}/s', $content, $m),
            'Could not extract the verify_install() function body'
        );
        $body = $m[1];

        $this->assertStringContainsString('/health', $body, 'verify_install must check the health endpoint');
        $this->assertStringContainsString('/login', $body, 'verify_install must check the login page');
        $this->assertStringContainsString('/assets/', $body, 'verify_install must fetch a published asset');
        $this->assertStringContainsString('curl', $body, 'verify_install must use curl for its checks');
    }

    public function testVerifyInstallIsCalledOnFreshInstallAndUpdate(): void
    {
        $content = $this->quickstartContent();

        $calls = preg_match_all('/^\s*verify_install\s*(?:\|\||&&|;|$)/m', $content);
        $this->assertGreaterThanOrEqual(
            2,
            (int)$calls,
            'verify_install must run on both the fresh-install path and the --update path'
        );
    }

    public function testFinalBannerPrintsConfiguredAppUrl(): void
    {
        $content = $this->quickstartContent();

        $this->assertStringContainsString(
            'ok "URL:      ${APP_URL}"',
            $content,
            'The final banner must print the configured APP_URL, not a hardcoded localhost URL'
        );
    }

    public function testVerificationFailureMakesQuickstartExitNonZero(): void
    {
        $content = $this->quickstartContent();

        $this->assertStringContainsString('verify_install || VERIFY_OK=0', $content);
        $this->assertStringContainsString('if [ "$VERIFY_OK" != "1" ]', $content);
        $this->assertStringContainsString('verify_install || UPDATE_OK=0', $content);
    }

    /**
     * Regression: the green "Ansilume is ready!" box was printed
     * unconditionally — and then the script exited 1 because verification
     * had failed. Operators read the banner, not the exit code. The success
     * banner must only appear when every verification check passed, and the
     * failure case must get its own, clearly different banner.
     */
    public function testSuccessBannerIsOnlyPrintedWhenVerificationPassed(): void
    {
        $content = $this->quickstartContent();

        $this->assertMatchesRegularExpression(
            '/if \[ "\$VERIFY_OK" = "1" \]; then\n(?:(?!\nelse\n).)*Ansilume is ready!/s',
            $content,
            'the "Ansilume is ready!" banner must be inside the VERIFY_OK = 1 branch'
        );
        $this->assertSame(
            1,
            preg_match_all('/Ansilume is ready!/', $content),
            'the success banner must not be printed anywhere outside the VERIFY_OK branch'
        );
        $this->assertStringContainsString('verification FAILED', $content, 'a distinct failure banner is required');
    }

    /**
     * Regression: verify_install probed the port from NGINX_PORT (.env) only;
     * when the live compose binding differed, a healthy install failed
     * verification. The published port must be asked from compose first.
     */
    public function testVerifyInstallAsksComposeForThePublishedPort(): void
    {
        $content = $this->quickstartContent();

        $this->assertSame(1, preg_match('/verify_install\(\) \{(.+?)\n\}/s', $content, $m));
        $this->assertStringContainsString('$COMPOSE_CMD port nginx 80', $m[1]);
        $this->assertStringContainsString('${port:-${NGINX_PORT##*:}}', $m[1], 'NGINX_PORT must remain the fallback');
    }

    /**
     * Regression: the --update path consumed .env values raw. A CRLF-edited
     * .env or duplicate keys (compose uses the LAST occurrence) made the
     * verification probe a wrong or invalid URL and fail a healthy install.
     */
    public function testUpdatePathEnvReadsSanitizeCrlfAndUseLastOccurrence(): void
    {
        $content = $this->quickstartContent();

        $this->assertMatchesRegularExpression(
            '/NGINX_PORT=\$\(grep \'\^NGINX_PORT=\' \.env[^)]*tail -1[^)]*tr -d[^)]*\)/',
            $content
        );
        $this->assertMatchesRegularExpression(
            '/APP_URL=\$\(grep \'\^APP_URL=\' \.env[^)]*tail -1[^)]*tr -d[^)]*\)/',
            $content
        );
        $this->assertStringContainsString(
            '${NGINX_PORT##*:}',
            $content,
            'verify_install must strip a compose bind-address prefix like 127.0.0.1:8080'
        );
    }

    public function testFinalOutputWarnsAboutHttpsAndBareCurl(): void
    {
        $content = $this->quickstartContent();

        $this->assertStringContainsString(
            'HTTPS is not configured',
            $content,
            'quickstart must tell operators that only http:// is served out of the box'
        );
        $this->assertStringContainsString(
            'curl -IL',
            $content,
            'quickstart must show curl -IL as the way to verify manually (bare curl prints an empty 302 body)'
        );
    }
}
