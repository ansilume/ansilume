<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real disk space and memory checks of bin/quickstart, the last of
 * its prerequisite checks, in bash under the quickstart's set -euo pipefail
 * and with its own ok, warn, fail and info, with stand-ins for df and free
 * on PATH. A df or free that fails skips its check without a line, and the
 * quickstart goes on; too little disk space still ends it with exit 1.
 *
 * Regression: the checks kept the number in plain assignments,
 * `DISK_AVAIL_KB=$(df -k . 2>/dev/null | awk …)` and
 * `MEM_AVAIL_MB=$(free -m 2>/dev/null | awk …)`, which take the exit status
 * of df and free under pipefail. When df could not stat the current
 * directory (one the user cannot search, as with `sudo -u` from a private
 * home directory, or a stale network mount) or free could not read
 * /proc/meminfo (exit 102), set -e ended the quickstart right after the
 * green Docker checks, without a word, as their errors go to /dev/null: the
 * checks never skipped themselves, and the install or update never ran.
 * Without a number the checks print no line, not "Disk space: 0MB
 * available" or "Memory: MB available".
 */
class QuickstartDiskAndMemoryCheckTest extends TestCase
{
    /** The two checks, from the comment of the first to the fi of the second. */
    private const CHECKS = '/^# Check available disk space .*?^fi\n\n# Check available memory .*?^fi\n/ms';

    /** What the harness prints once the checks have let the quickstart go on. */
    private const GOES_ON = 'The quickstart goes on.';

    /**
     * df for this test: `df -k .` as GNU df prints it, with STUB_DF KB
     * available, or with STUB_DF=fails its error in a directory the user
     * cannot search.
     */
    private const STUB_DF = <<<'BASH'
        #!/usr/bin/env bash
        if [ "$STUB_DF" = "fails" ]; then
            echo "df: .: Permission denied" >&2
            exit 1
        fi
        echo "Filesystem     1K-blocks     Used Available Use% Mounted on"
        echo "/dev/sda1      102687672 41234568 $STUB_DF  41% /"
        BASH;

    /**
     * free for this test: `free -m` as procps prints it, with STUB_FREE MB
     * available, or with STUB_FREE=fails its error where /proc is not
     * mounted.
     */
    private const STUB_FREE = <<<'BASH'
        #!/usr/bin/env bash
        if [ "$STUB_FREE" = "fails" ]; then
            echo "Error: /proc must be mounted" >&2
            exit 102
        fi
        echo "               total        used        free      shared  buff/cache   available"
        echo "Mem:            7951        2180        1270         248        4500 $STUB_FREE"
        echo "Swap:           2047           0        2047"
        BASH;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quickstart-resources-' . uniqid('', true);
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function measurementProvider(): array
    {
        $disk = "  ✓ Disk space: 10240MB available\n";
        $memory = "  ✓ Memory: 5207MB available\n";
        $lowMemory = "  ⚠ Low memory: 300MB available. Ansilume needs at least 512MB.\n"
            . "  Containers may fail to start or be killed by the OOM killer.\n";
        $lowDisk = "  ✗ Not enough disk space: 1024MB available, at least 2048MB recommended.\n"
            . "  Free up disk space and try again.\n";
        $goesOn = self::GOES_ON . "\n";

        return [
            'regression: df cannot stat the current directory' => ['fails', '5207', $memory . $goesOn, 0],
            'regression: free cannot read /proc/meminfo' => ['10485760', 'fails', $disk . $goesOn, 0],
            'regression: neither can measure' => ['fails', 'fails', $goesOn, 0],
            'enough disk space and memory' => ['10485760', '5207', $disk . $memory . $goesOn, 0],
            'low memory, which only warns' => ['10485760', '300', $disk . $lowMemory . $goesOn, 0],
            'too little disk space, which ends the quickstart' => ['1048576', '5207', $lowDisk, 1],
        ];
    }

    #[DataProvider('measurementProvider')]
    public function testTheChecksReportWhatDfAndFreeMeasureAndSkipWhatTheyCannot(
        string $df,
        string $free,
        string $output,
        int $status
    ): void {
        [$exitCode, $printed] = $this->runChecks($df, $free);

        $this->assertSame($output, $printed);
        $this->assertSame($status, $exitCode, $printed);
    }

    /**
     * Runs the checks of bin/quickstart as the quickstart runs them: under
     * its options and with its output helpers, df and free being the
     * stand-ins, and then the line that shows the quickstart goes on.
     *
     * @return array{0: int, 1: string} exit status, stdout and stderr without colours
     */
    private function runChecks(string $df, string $free): array
    {
        foreach (['df' => self::STUB_DF, 'free' => self::STUB_FREE] as $command => $stub) {
            file_put_contents($this->directory . '/' . $command, $stub . "\n");
            chmod($this->directory . '/' . $command, 0o755);
        }
        $quickstart = (string)file_get_contents(dirname(__DIR__, 3) . '/bin/quickstart');
        $this->assertStringContainsString(
            "\nset -euo pipefail\n",
            $quickstart,
            'The harness runs the checks with the options of the quickstart.'
        );
        $this->assertSame(
            1,
            preg_match('/^RED=.*?^die\(\)[^\n]*\n/ms', $quickstart, $helpers),
            'Could not extract the output helpers from bin/quickstart'
        );
        $this->assertSame(
            1,
            preg_match(self::CHECKS, $quickstart, $checks),
            'Could not extract the disk space and memory checks from bin/quickstart'
        );

        $process = proc_open(
            ['bash', '-c', "set -euo pipefail\n" . $helpers[0] . $checks[0] . 'echo "' . self::GOES_ON . "\"\n"],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->directory,
            [
                'PATH' => $this->directory . ':' . (string)getenv('PATH'),
                'STUB_DF' => $df,
                'STUB_FREE' => $free,
            ]
        );
        $this->assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), (string)preg_replace('/\e\[[0-9;]*m/', '', $output)];
    }
}
