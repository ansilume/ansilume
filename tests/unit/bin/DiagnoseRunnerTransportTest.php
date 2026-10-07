<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;

/**
 * Runs the real report_runner_transport() from bin/diagnose in bash, with
 * set -euo pipefail like the script, on lines the in-container PHP probe
 * prints: "name|group|transport|remote_addr|online|plaintext_age".
 *
 * Plain HTTP from outside the trusted networks means claim responses carry
 * decrypted credentials in clear: diagnose must fail while such a runner is
 * online, warn while it is offline, and keep asking for credential rotation
 * for 7 days after the transport was fixed.
 */
class DiagnoseRunnerTransportTest extends TestCase
{
    private const ROTATE = 'used plain HTTP from outside within the last 7 days; rotate the credentials it received';

    public function testHttpsIsOk(): void
    {
        $this->assertSame(
            ["  ✓ Runner 'edge-1' (remote): HTTPS"],
            $this->report("edge-1|remote|https|198.51.100.7|1|\n")
        );
    }

    public function testPlainHttpFromATrustedNetworkIsInformational(): void
    {
        $this->assertSame(
            ["  Runner 'runner-1' (default): plain HTTP from a trusted network (172.18.0.5)"],
            $this->report("runner-1|default|http_internal|172.18.0.5|1|\n")
        );
    }

    /**
     * The runner's plaintext timestamp is fresh here as well, but the 7-day
     * rotation hint is left out: the failure already says it all.
     */
    public function testAnOnlineRunnerOnPlainHttpFromOutsideFails(): void
    {
        $this->assertSame(
            ["  ✗ Runner 'edge-2' (remote): plain HTTP from 203.0.113.10, outside the trusted networks; credentials travel in clear"],
            $this->report("edge-2|remote|http_external|203.0.113.10|1|12\n")
        );
    }

    public function testAnOfflineRunnerLastSeenOnPlainHttpFromOutsideWarns(): void
    {
        $this->assertSame(
            ["  ⚠ Runner 'edge-3' (remote, offline): last connected over plain HTTP from 203.0.113.10"],
            $this->report("edge-3|remote|http_external|203.0.113.10|0|90000\n")
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownTransportProvider(): array
    {
        return ['not seen since the upgrade' => [''], 'unexpected value' => ['carrier-pigeon']];
    }

    /**
     * @dataProvider unknownTransportProvider
     */
    public function testAnUnknownTransportIsInformational(string $transport): void
    {
        $this->assertSame(
            ["  Runner 'old-runner' (default): transport unknown, no request since the upgrade"],
            $this->report("old-runner|default|{$transport}||0|\n")
        );
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function plaintextAgeProvider(): array
    {
        return [
            'an hour ago' => ['3600', true],
            'one second before the 7 days are up' => ['604799', true],
            'exactly 7 days ago' => ['604800', false],
            'a month ago' => ['2592000', false],
            'never' => ['', false],
        ];
    }

    /**
     * @dataProvider plaintextAgeProvider
     */
    public function testRecentPlainHttpAsksForCredentialRotationAfterTheFix(string $age, bool $rotate): void
    {
        $expected = ["  ✓ Runner 'edge-4' (remote): HTTPS"];
        if ($rotate) {
            $expected[] = "  ⚠ Runner 'edge-4' (remote): " . self::ROTATE;
        }

        $this->assertSame($expected, $this->report("edge-4|remote|https|198.51.100.7|1|{$age}\n"));
    }

    public function testARunnerMovedIntoTheTrustedNetworkAlsoGetsTheRotationHint(): void
    {
        $this->assertSame(
            [
                "  Runner 'edge-5' (default): plain HTTP from a trusted network (172.18.0.5)",
                "  ⚠ Runner 'edge-5' (default): " . self::ROTATE,
            ],
            $this->report("edge-5|default|http_internal|172.18.0.5|1|60\n")
        );
    }

    public function testEveryRunnerIsReportedInOrderAndBlankLinesAreSkipped(): void
    {
        $lines = "runner-1|default|http_internal|172.18.0.5|1|\n"
            . "\n"
            . "edge-2|remote|http_external|203.0.113.10|1|5\n"
            . "edge-3|remote|http_external|203.0.113.11|0|100\n"
            . "edge-4|remote|https|198.51.100.7|1|\n";

        $this->assertSame(
            [
                "  Runner 'runner-1' (default): plain HTTP from a trusted network (172.18.0.5)",
                "  ✗ Runner 'edge-2' (remote): plain HTTP from 203.0.113.10, outside the trusted networks; credentials travel in clear",
                "  ⚠ Runner 'edge-3' (remote, offline): last connected over plain HTTP from 203.0.113.11",
                "  ✓ Runner 'edge-4' (remote): HTTPS",
            ],
            $this->report($lines)
        );
    }

    public function testNoRunnersPrintNothing(): void
    {
        $this->assertSame([], $this->report(''));
    }

    /**
     * The script captures the probe output with $(...), which strips the
     * final newline, and feeds it through a here-string. The last runner
     * must still be reported.
     */
    public function testTheScriptsOwnCallReportsTheLastRunnerToo(): void
    {
        $this->assertSame(
            1,
            preg_match('/^\s*(report_runner_transport <<< "\$transport_output")$/m', $this->diagnose(), $m),
            'bin/diagnose no longer feeds report_runner_transport from $transport_output'
        );
        $lines = $this->runBash(
            $this->harness('transport_output=$(cat)' . "\n" . $m[1]),
            "runner-1|default|http_internal|172.18.0.5|1|\nedge-4|remote|https|198.51.100.7|1|\n"
        );

        $this->assertSame(
            [
                "  Runner 'runner-1' (default): plain HTTP from a trusted network (172.18.0.5)",
                "  ✓ Runner 'edge-4' (remote): HTTPS",
            ],
            $lines
        );
    }

    // -- Trusted networks ------------------------------------------------------

    public function testTheTrustedNetworksInUseAreListed(): void
    {
        $this->assertSame(
            ['  Trusted networks in use: 127.0.0.0/8, 10.0.0.0/8 (RUNNER_TRUSTED_NETWORKS; unset means loopback and the private ranges)'],
            $this->runBash($this->harness('report_trusted_networks', 'report_trusted_networks'), "127.0.0.0/8, 10.0.0.0/8\n\n")
        );
    }

    /**
     * A value whose entries are all invalid (here: the wrong separator)
     * keeps the defaults. Regression: nothing told the operator.
     */
    public function testIgnoredEntriesAreNamed(): void
    {
        $this->assertSame(
            [
                '  Trusted networks in use: 127.0.0.0/8, ::1/128 (RUNNER_TRUSTED_NETWORKS; unset means loopback and the private ranges)',
                '  ⚠ RUNNER_TRUSTED_NETWORKS entries ignored as invalid: 172.19.0.0/16 172.20.0.0/16. Separate entries with commas.',
            ],
            $this->runBash($this->harness('report_trusted_networks', 'report_trusted_networks'), "127.0.0.0/8, ::1/128\n172.19.0.0/16 172.20.0.0/16\n")
        );
    }

    public function testNothingIsReportedWithoutProbeOutput(): void
    {
        $this->assertSame([], $this->runBash($this->harness('report_trusted_networks', 'report_trusted_networks'), ''));
    }

    // -- The in-container probe -------------------------------------------------

    public function testTheProbePrintsOneLinePerRunner(): void
    {
        $this->assertSame("edge-1|remote|https|198.51.100.7|1|\n", $this->probeLine('edge-1', 'remote', 'https', '198.51.100.7', true, null));
        $this->assertSame("old-runner||||0|90000\n", $this->probeLine('old-runner', '', '', '', false, 90000));
    }

    /**
     * Regression: the probe replaced only "|" in names. A runner name with a
     * line break (registration only trims the ends) printed extra lines that
     * report_runner_transport() took for other runners, such as a made-up
     * "✓ … HTTPS".
     */
    public function testANameWithALineBreakCannotFakeAnotherRunner(): void
    {
        $line = $this->probeLine("evil\nfake|grp|https|192.0.2.1|1|", "remote\r", 'http_external', '203.0.113.7', true, 5);

        $this->assertSame("evil fake grp https 192.0.2.1 1 |remote |http_external|203.0.113.7|1|5\n", $line);
        $this->assertSame(
            ["  ✗ Runner 'evil fake grp https 192.0.2.1 1 ' (remote ): plain HTTP from 203.0.113.7, outside the trusted networks; credentials travel in clear"],
            $this->report($line)
        );
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * What the probe's line function from bin/diagnose prints for one runner.
     */
    private function probeLine(string $name, string $group, string $transport, string $addr, bool $online, ?int $age): string
    {
        $this->assertSame(1, preg_match("/^RUNNER_TRANSPORT_LINE_PHP='(.*?)'$/ms", $this->diagnose(), $m), 'could not extract RUNNER_TRANSPORT_LINE_PHP from bin/diagnose');
        $arguments = array_map(static fn (string|bool|int|null $value): string => var_export($value, true), [$name, $group, $transport, $addr, $online, $age]);
        $process = proc_open(
            [PHP_BINARY, '-r', $m[1] . 'echo $runnerTransportLine(' . implode(', ', $arguments) . ');'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err);
        $this->assertSame('', $err);

        return $out;
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * @return list<string> output lines
     */
    private function report(string $stdin): array
    {
        return $this->runBash($this->harness('report_runner_transport'), $stdin);
    }

    /**
     * The extracted helpers and a report function from bin/diagnose under
     * the script's shell options, then $call. Colours are blanked so the
     * assertions read plainly.
     */
    private function harness(string $call, string $function = 'report_runner_transport'): string
    {
        $script = "set -euo pipefail\nRED='' GREEN='' YELLOW='' CYAN='' BOLD='' NC=''\n";
        foreach (['ok', 'warn', 'fail', 'info'] as $helper) {
            $script .= $this->extract('/^' . $helper . '\(\)\s*\{[^\n]*\}$/m', $helper . '()') . "\n";
        }

        return $script
            . $this->extract('/^' . $function . '\(\) \{.*?\n\}/ms', $function . '()') . "\n"
            . $call . "\n";
    }

    /**
     * @return list<string> output lines
     */
    private function runBash(string $script, string $stdin): array
    {
        $process = proc_open(
            ['bash', '-c', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), 'report_runner_transport must not abort a set -e script: ' . $err);
        $this->assertSame('', $err, 'no shell errors');

        return $out === '' ? [] : explode("\n", rtrim($out, "\n"));
    }

    private function extract(string $pattern, string $what): string
    {
        $this->assertSame(1, preg_match($pattern, $this->diagnose(), $m), "could not extract {$what} from bin/diagnose");

        return $m[0];
    }

    private function diagnose(): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/bin/diagnose');
        $this->assertNotFalse($content, 'could not read bin/diagnose');

        return $content;
    }
}
