<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real report_notifications(), report_app_url() and
 * summarize_notifications() of bin/diagnose in bash, with set -euo
 * pipefail like the script, on the lines its notifications probe
 * (NOTIFICATION_PROBE_PHP) prints: "app_url|<1 when APP_URL is set, else
 * 0>|<the base of the links>", "inactive|<id>|<name>|<user ID>" for each
 * notification template that delivers as an inactive user, and
 * "templates|<how many there are>".
 *
 * Written with plan Task 14 of the workflow notifications (spec answer 3:
 * a template whose user is inactive delivers nothing, and that is never
 * silent; spec section 9: diagnose warns while APP_URL is the default): up
 * to it diagnose said nothing of either.
 */
final class DiagnoseNotificationsTest extends TestCase
{
    /** What the section says for an APP_URL that others cannot reach, after why. */
    private const APP_URL_FIX = ': set APP_URL in .env to the address your users open (the deploy role: ansilume_app_url), then docker compose up -d';

    public function testAnAppUrlThatOthersReachIsOk(): void
    {
        $this->assertSame(
            ['  ✓ APP_URL: https://ansilume.example.com, the base of the links of notifications', '  ✓ Notification templates: 2, each delivering as an active user'],
            $this->report("app_url|1|https://ansilume.example.com\ntemplates|2\n")
        );
    }

    /**
     * Without APP_URL the links lead to the default, which only the host
     * reaches.
     */
    public function testAnAppUrlThatIsNotSetWarnsWithTheAddressTheLinksLeadTo(): void
    {
        $this->assertSame(
            ['  ⚠ APP_URL is not set, so the links of notifications lead to http://localhost:8080' . self::APP_URL_FIX],
            $this->report("app_url|0|http://localhost:8080\n", 'report_notifications', false)
        );
    }

    public function testAnEmptyAppUrlWarns(): void
    {
        $this->assertSame(
            ['  ⚠ APP_URL is empty, so the links of notifications are paths without an address' . self::APP_URL_FIX],
            $this->report("app_url|1|\n", 'report_notifications', false)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function loopbackProvider(): array
    {
        return [
            'localhost with a port' => ['http://localhost:9911'],
            'localhost in capitals, with a path' => ['https://LOCALHOST/ansilume'],
            'a subdomain of localhost' => ['http://ansilume.localhost'],
            'the loopback address' => ['http://127.0.0.1:8080'],
            'another address of 127.0.0.0/8' => ['http://127.1.2.3'],
            'the IPv6 loopback with a port' => ['http://[::1]:8080'],
            'the IPv6 loopback' => ['http://[::1]'],
            'every address' => ['http://0.0.0.0:8080'],
        ];
    }

    /**
     * An APP_URL on the loopback reaches this host only: no user on another
     * computer, and no Mattermost server, opens its links.
     */
    #[DataProvider('loopbackProvider')]
    public function testAnAppUrlOnTheLoopbackWarns(string $url): void
    {
        $this->assertSame(
            ['  ⚠ APP_URL is ' . $url . ', which only this host reaches, so the links of notifications open nowhere else' . self::APP_URL_FIX],
            $this->report("app_url|1|{$url}\n", 'report_notifications', false)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reachableProvider(): array
    {
        return [
            'a name that starts like localhost' => ['https://localhost.example.com'],
            'a name that ends like localhost' => ['https://my-localhost:8443/ansilume'],
            'a name that starts with 127' => ['https://127.example.com'],
            'an address of the network' => ['http://10.1.42.102:9911'],
            'an IPv6 address of the network' => ['http://[fd00::2]:8080'],
            'a name with a path, a query and a fragment' => ['https://ops.example.com/ansilume?x=1#top'],
        ];
    }

    #[DataProvider('reachableProvider')]
    public function testAnAppUrlThatOnlyLooksLikeTheLoopbackIsOk(string $url): void
    {
        $this->assertSame(
            ['  ✓ APP_URL: ' . $url . ', the base of the links of notifications'],
            $this->report("app_url|1|{$url}\n", 'report_notifications', false)
        );
    }

    /**
     * Each template that delivers as an inactive user is named, with the
     * user's ID, and counted for the summary.
     */
    public function testEveryTemplateThatDeliversAsAnInactiveUserIsListed(): void
    {
        $this->assertSame(
            [
                '  ✓ APP_URL: https://ansilume.example.com, the base of the links of notifications',
                "  ⚠ Notification template #3 'Deploy alerts' delivers as user #12, who is inactive: it sends nothing until someone saves it, who then becomes the user it delivers as",
                "  ⚠ Notification template #9 'Nightly' delivers as user #4, who is inactive: it sends nothing until someone saves it, who then becomes the user it delivers as",
                '  ⚠ notifications: 2 template(s) deliver as an inactive user and send nothing (see Notifications)',
            ],
            $this->report(
                "app_url|1|https://ansilume.example.com\ninactive|3|Deploy alerts|12\n\ninactive|9|Nightly|4\ntemplates|5\n",
                "report_notifications\nsummarize_notifications"
            )
        );
    }

    public function testAnInstallationWithoutTemplatesSaysSo(): void
    {
        $this->assertSame(
            ['  ✓ APP_URL: https://ansilume.example.com, the base of the links of notifications', '  No notification templates'],
            $this->report("app_url|1|https://ansilume.example.com\ntemplates|0\n")
        );
    }

    /**
     * A probe that printed nothing (the app's console could not boot, the
     * database could not be read) is reported as such, never as fine.
     */
    public function testAProbeThatPrintedNothingIsReportedAsUnchecked(): void
    {
        $this->assertSame(['  ⚠ Could not check APP_URL and the notification templates'], $this->report(''));
    }

    public function testAProbeThatStoppedAfterTheAppUrlIsReportedAsUnchecked(): void
    {
        $this->assertSame(
            ['  ✓ APP_URL: https://ansilume.example.com, the base of the links of notifications', '  ⚠ Could not check the notification templates'],
            $this->report("app_url|1|https://ansilume.example.com\n")
        );
    }

    /**
     * The summary repeats both findings; nothing when there is none.
     */
    public function testTheSummaryRepeatsWhatTheSectionFound(): void
    {
        $found = $this->report("app_url|0|http://localhost:8080\ninactive|3|Deploy alerts|12\ntemplates|1\n", "report_notifications > /dev/null\nsummarize_notifications");
        $this->assertSame(
            [
                '  ⚠ notifications: 1 template(s) deliver as an inactive user and send nothing (see Notifications)',
                '  ⚠ notifications: APP_URL is not set, so the links of notifications lead to http://localhost:8080 (see Notifications)',
            ],
            $found
        );
        $this->assertSame([], $this->report("app_url|1|https://ansilume.example.com\ntemplates|1\n", "report_notifications > /dev/null\nsummarize_notifications"));
        $this->assertSame([], $this->report('', 'summarize_notifications'), 'Without the section, as without an app container.');
    }

    /**
     * The script captures the probe's output with $(...), which strips the
     * last newline, and feeds it through a here-string: the last line still
     * counts. It runs the probe as www-data, with the boot prelude, and the
     * summary calls summarize_notifications() where it checks the app.
     */
    public function testTheScriptsOwnCallsReportEveryLine(): void
    {
        $diagnose = $this->diagnose();
        if (preg_match('/^\s*(report_notifications <<< "\$notification_output")$/m', $diagnose, $call) !== 1) {
            self::fail('bin/diagnose no longer feeds report_notifications from $notification_output');
        }
        $this->assertStringContainsString("notification_output=\$(docker exec -u www-data \"\$app_container\" php -r \"\n        \${PHP_BOOT}\n        \${NOTIFICATION_PROBE_PHP}\n    \" 2>/dev/null || true)", $diagnose);
        $this->assertSame(1, preg_match('/^    summarize_notifications$/m', $diagnose), 'The summary repeats the findings.');

        $this->assertSame(
            ['  ✓ APP_URL: https://ansilume.example.com, the base of the links of notifications', '  ✓ Notification templates: 1, each delivering as an active user'],
            $this->runBash($this->harness('notification_output=$(cat)' . "\n" . $call[1]), "app_url|1|https://ansilume.example.com\ntemplates|1\n")
        );
    }

    /**
     * docs/troubleshooting.md quotes the lines as diagnose prints them.
     */
    public function testTroubleshootingQuotesWhatTheSectionPrints(): void
    {
        $lines = $this->report("app_url|0|http://localhost:8080\ninactive|3|Deploy alerts|12\ntemplates|4\n", 'report_notifications', false);
        $docs = (string)preg_replace('/\s+/', ' ', (string)file_get_contents(dirname(__DIR__, 3) . '/docs/troubleshooting.md'));

        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertStringContainsString('`' . substr($line, strlen('  ⚠ ')) . '`', $docs, 'troubleshooting.md quotes the line diagnose prints.');
        }
    }

    /**
     * The lines report_notifications() (or $call) prints for $stdin. With
     * $withTemplates false, the lines of the templates are left out, so a
     * case of APP_URL alone reads plainly.
     *
     * @return list<string>
     */
    private function report(string $stdin, string $call = 'report_notifications', bool $withTemplates = true): array
    {
        if (!$withTemplates) {
            $stdin .= "templates|1\n";
        }
        $lines = $this->runBash($this->harness($call), $stdin);

        return $withTemplates ? $lines : array_values(array_filter($lines, static fn (string $line): bool => !str_contains($line, 'Notification templates: 1')));
    }

    /**
     * The helpers and the functions of the section from bin/diagnose under
     * the script's shell options, then $call. Colours are blanked so the
     * assertions read plainly.
     */
    private function harness(string $call): string
    {
        $script = "set -euo pipefail\nRED='' GREEN='' YELLOW='' CYAN='' BOLD='' NC=''\n";
        foreach (['ok', 'warn', 'fail', 'info'] as $helper) {
            $script .= $this->extract('/^' . $helper . '\(\)\s*\{[^\n]*\}$/m', $helper . '()') . "\n";
        }
        foreach (['url_host', 'is_loopback_host', 'report_app_url', 'report_notifications', 'summarize_notifications'] as $function) {
            $script .= $this->extract('/^' . $function . '\(\) \{.*?\n\}/ms', $function . '()') . "\n";
        }

        return $script . $call . "\n";
    }

    /**
     * @return list<string> output lines
     */
    private function runBash(string $script, string $stdin): array
    {
        $process = proc_open(['bash', '-c', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), 'The functions must not end a set -e script: ' . $err);
        $this->assertSame('', $err, 'no shell errors');

        return $out === '' ? [] : explode("\n", rtrim($out, "\n"));
    }

    private function extract(string $pattern, string $what): string
    {
        $this->assertSame(1, preg_match($pattern, $this->diagnose(), $match), "could not extract {$what} from bin/diagnose");

        return $match[0];
    }

    private function diagnose(): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/bin/diagnose');
        $this->assertNotFalse($content, 'could not read bin/diagnose');

        return $content;
    }
}
