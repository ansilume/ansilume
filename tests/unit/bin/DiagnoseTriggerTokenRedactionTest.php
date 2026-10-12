<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;

/**
 * bin/diagnose prints the last log lines of every container, and its output
 * is meant to be pasted into bug reports.
 *
 * Regression: redact() masked ?token=<token> but not the path form
 * /trigger/<token>, which nginx access and error log lines and app.log
 * route errors of older versions contain.
 */
class DiagnoseTriggerTokenRedactionTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private function redact(string $input): string
    {
        $diagnose = file_get_contents(dirname(__DIR__, 3) . '/bin/diagnose');
        $this->assertIsString($diagnose);
        $this->assertSame(1, preg_match('/^redact\(\) \{.*?\n\}/ms', $diagnose, $m), 'redact() is in bin/diagnose');

        $process = proc_open(['bash', '-c', $m[0] . "\nredact"], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $this->assertSame(0, proc_close($process));

        return $out;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function logLineProvider(): array
    {
        $t = self::TOKEN;
        $upper = strtoupper($t);

        return [
            'nginx access log, path form' => [
                "192.0.2.10 - - [11/Oct/2026:12:00:00 +0000] \"POST /trigger/{$t} HTTP/1.1\" 201 14 \"-\" \"curl/8.5.0\"\n",
                "192.0.2.10 - - [11/Oct/2026:12:00:00 +0000] \"POST /trigger/***REDACTED*** HTTP/1.1\" 201 14 \"-\" \"curl/8.5.0\"\n",
            ],
            'trailing slash' => ["GET /trigger/{$t}/ 405\n", "GET /trigger/***REDACTED***/ 405\n"],
            'capitals' => ["POST /trigger/{$upper}\n", "POST /trigger/***REDACTED***\n"],
            'route error of an older version' => [
                "[error][yii\\web\\HttpException:404] Unable to resolve the request \"trigger/{$t}\".\n",
                "[error][yii\\web\\HttpException:404] Unable to resolve the request \"trigger/***REDACTED***\".\n",
            ],
            'path and query form in one line' => [
                "request: \"POST /trigger/{$t}?token={$t} HTTP/1.1\", referrer: \"https://ci.example/trigger/{$t}\"\n",
                "request: \"POST /trigger/***REDACTED***?token=***REDACTED*** HTTP/1.1\", referrer: \"https://ci.example/trigger/***REDACTED***\"\n",
            ],
            'endpoint without a token' => ["POST /trigger/fire 201\n", "POST /trigger/fire 201\n"],
        ];
    }

    /**
     * @dataProvider logLineProvider
     */
    public function testRedactMasksPathTokens(string $line, string $expected): void
    {
        $this->assertSame($expected, $this->redact($line));
    }

    public function testRedactKeepsTriggerLinesWithoutATokenAsTheyAre(): void
    {
        $lines = "trigger fired for job #42\nPOST /trigger/fire-workflow 201\nGET /trigger/abc 404\n";

        $this->assertSame($lines, $this->redact($lines));
    }
}
