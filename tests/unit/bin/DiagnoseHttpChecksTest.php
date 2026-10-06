<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;

/**
 * Contract test for bin/diagnose host-side HTTP checks.
 *
 * Regression: diagnose validated containers from the inside but never checked
 * what an operator actually experiences via the published port. A healthy
 * stack whose / answers with a 302 and an empty body ("curl printed
 * nothing"), or a browser forced to https:// with no TLS listener, both
 * looked like "Ansilume is down" and diagnose had nothing to say about it.
 */
class DiagnoseHttpChecksTest extends TestCase
{
    private function diagnoseContent(): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/bin/diagnose');
        $this->assertNotFalse($content, 'Could not read bin/diagnose');
        return $content;
    }

    public function testHasHostSideHttpReachabilitySection(): void
    {
        $this->assertStringContainsString(
            'HTTP Reachability',
            $this->diagnoseContent(),
            'diagnose must have a host-side HTTP reachability section using the published port'
        );
    }

    public function testChecksRootRedirectLoginHealthAndAssets(): void
    {
        $content = $this->diagnoseContent();

        $this->assertStringContainsString('"${base_url}/"', $content, 'diagnose must curl the root URL');
        $this->assertStringContainsString('expected 302', $content, 'diagnose must expect the 302 login redirect on /');
        $this->assertStringContainsString('${base_url}/login', $content, 'diagnose must curl the login page');
        $this->assertStringContainsString('${base_url}/health', $content, 'diagnose must curl the health endpoint');
        $this->assertStringContainsString('/assets/', $content, 'diagnose must fetch a published asset');
    }

    public function testHostSideResultIsWiredIntoDiagnosisSummary(): void
    {
        $this->assertMatchesRegularExpression(
            '/if \[ "\$http_reach_failed" = "true" \]; then\n'
            . '\s*fail "http: host-side reachability issues[^"]*"\n\s*all_ok=false/',
            $this->diagnoseContent(),
            'a failed host-side HTTP check must flip the Diagnosis Summary to "Issues detected"'
        );
    }

    /**
     * Regression (two rounds): first the .env value was consumed raw — a
     * CRLF-edited .env or a compose bind-address prefix (127.0.0.1:8080)
     * produced an invalid probe URL. Then it turned out .env itself can be
     * wrong: when the live compose binding differs (environment override,
     * stale .env after a port change) diagnose probed the wrong port and
     * raised a false "reachability issues" alarm on a healthy install. The
     * port must come from compose first and only fall back to .env.
     */
    public function testNginxPortComesFromLiveComposeBindingWithEnvFallback(): void
    {
        $content = $this->diagnoseContent();

        $this->assertSame(
            1,
            preg_match('/^published_nginx_port\(\) \{(.+?)\n\}/ms', $content, $m),
            'diagnose must define a published_nginx_port() helper'
        );
        $helper = $m[1];
        $this->assertStringContainsString('docker compose port nginx 80', $helper);
        $this->assertMatchesRegularExpression(
            '/grep \'\^NGINX_PORT=\' \.env[^)]*tail -1[^)]*tr -d/',
            $helper,
            'the .env fallback must take the LAST occurrence (compose semantics) and strip whitespace/CR'
        );
        $this->assertStringContainsString('${port##*:}', $helper, 'bind-address prefixes must be stripped');

        $this->assertStringContainsString('diag_port=$(published_nginx_port)', $content);
        $this->assertStringContainsString('nginx_port=$(published_nginx_port)', $content);
        $this->assertStringNotContainsString(
            "diag_port=$(grep '^NGINX_PORT='",
            $content,
            'the HTTP reachability probe must not read .env directly any more'
        );
    }

    /**
     * Regression: the HTTPS probe reported any local TLS listener as
     * "TLS termination is present" even when it was an unrelated service —
     * suppressing the HTTPS-First guidance in exactly the situation that
     * needs it. The probe must verify the responder actually serves this
     * install (health endpoint) before claiming TLS is configured.
     */
    public function testHttpsProbeVerifiesTheResponderIsThisInstall(): void
    {
        $content = $this->diagnoseContent();

        $this->assertStringContainsString('https://127.0.0.1/health', $content);
        $this->assertStringContainsString('does not look like this install', $content);
    }

    public function testExplainsEmptyRedirectBody(): void
    {
        $this->assertStringContainsString(
            'empty body is expected',
            $this->diagnoseContent(),
            'diagnose must explain that a bare curl on / prints nothing because the 302 body is empty'
        );
    }

    public function testWarnsAboutHttpsFirstBrowserTrap(): void
    {
        $this->assertStringContainsString(
            'HTTPS-First',
            $this->diagnoseContent(),
            'diagnose must warn that browsers forcing https:// hang when no TLS listener exists'
        );
    }

    /**
     * Regression: the migration check grepped the history output
     * case-insensitively for "error|exception|fail" — and matched the NAME of
     * migration m000069_000000_widen_inventory_parsed_error, reporting a
     * healthy schema as "Migration check failed". The verdict must rest on
     * the exit code and on error lines anchored at the start of a line.
     */
    public function testMigrationCheckDoesNotMatchErrorWordsInsideMigrationNames(): void
    {
        $content = $this->diagnoseContent();

        $this->assertStringNotContainsString('grep -qi "error\\|exception\\|fail"', $content);
        $this->assertStringContainsString('migrate/history 10 2>&1) || migration_rc=$?', $content);
        $this->assertStringContainsString('[ "$migration_rc" -ne 0 ] ||', $content);
        $this->assertMatchesRegularExpression(
            '/grep -qE \'\^\(Exception\|Error\|PHP Fatal error[^\']*\)\'/',
            $content,
            'error detection must be anchored to the start of a line'
        );
    }

    /**
     * Regression: the port-conflict check ran `ss -tlnp | grep -v docker` and
     * flagged the stack's own docker-proxy listener as a foreign process,
     * because a non-root `ss -p` prints no process names. When compose can
     * report the nginx binding, the listener is the stack by definition.
     */
    public function testPortConflictCheckTrustsTheLiveComposeBindingFirst(): void
    {
        $content = $this->diagnoseContent();

        $this->assertMatchesRegularExpression(
            '/compose_binding=\$\(docker compose port nginx 80[^\n]*\n'
            . 'if \[ -n "\$compose_binding" \]; then\n'
            . '\s*ok "Port \$\{nginx_port\} is published by the nginx container/',
            $content,
            'a live compose binding must short-circuit the ss/netstat probe'
        );
        $this->assertStringContainsString('elif command -v ss >/dev/null 2>&1; then', $content);
    }

    /**
     * Regression: every php -r deep probe required /var/www/config/bootstrap.php,
     * a file that exists neither in the repo nor in the prebuilt image. All
     * probes died silently and diagnose reported false alarms such as
     * "admin-user: no superadmin exists" on perfectly healthy installs.
     */
    public function testPhpProbesDoNotRequireNonexistentBootstrap(): void
    {
        $content = $this->diagnoseContent();

        $this->assertStringNotContainsString(
            'config/bootstrap.php',
            $content,
            'diagnose must not require config/bootstrap.php — the file does not exist'
        );
        $this->assertStringContainsString(
            'vendor/yiisoft/yii2/Yii.php',
            $content,
            'php -r probes must boot Yii via vendor/yiisoft/yii2/Yii.php like web/index.php and ./yii do'
        );
    }

    /**
     * Regression: the php -r probes booted Yii without loading .env. On a dev
     * checkout compose injects only APP_ENV into the app container — DB_*
     * comes from the bind-mounted .env, which ./yii loads via Dotenv. Without
     * it the DB connection failed ("Access denied ... using password: NO"),
     * every probe died and diagnose reported "no superadmin exists" on a
     * healthy install. All probes must share one prelude that loads .env
     * exactly like ./yii does (safeLoad: a no-op in prebuilt images).
     */
    public function testPhpProbesShareOneDotenvAwarePrelude(): void
    {
        $content = $this->diagnoseContent();

        $this->assertSame(
            1,
            preg_match('/^PHP_BOOT="(.+?)^"/ms', $content, $m),
            'PHP_BOOT prelude missing'
        );
        $prelude = $m[1];
        $this->assertStringContainsString("require '/var/www/vendor/autoload.php'", $prelude);
        $this->assertStringContainsString("Dotenv::createUnsafeImmutable('/var/www')->safeLoad()", $prelude);
        $this->assertStringContainsString("require '/var/www/vendor/yiisoft/yii2/Yii.php'", $prelude);
        $this->assertStringContainsString('new yii\\console\\Application', $prelude);

        // Dotenv must run before Yii boots, otherwise YII_ENV/DB_* are resolved too early.
        $this->assertLessThan(
            strpos($prelude, 'yii2/Yii.php'),
            strpos($prelude, 'safeLoad()'),
            '.env must be loaded before Yii is required'
        );

        $this->assertSame(
            1,
            preg_match_all('/new yii\\\\console\\\\Application/', $content),
            'every probe must use the shared PHP_BOOT prelude instead of booting Yii on its own'
        );
        $this->assertGreaterThanOrEqual(
            4,
            preg_match_all('/php -r "\s*\$\{PHP_BOOT\}/', $content),
            'the project-sync, RBAC, admin-user and summary probes must all start with ${PHP_BOOT}'
        );
    }
}
