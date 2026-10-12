<?php

declare(strict_types=1);

namespace app\tests\unit\views;

use PHPUnit\Framework\TestCase;

/**
 * Guard: views write JSON into inline scripts only hex-escaped.
 *
 * json_encode() and Json::encode() leave "<", ">" and "&" as they are. In an
 * inline <script>, "<!--<script" in a value makes the HTML parser read the
 * following "</script>" as text, so the rest of the page disappears into the
 * script; with JSON_UNESCAPED_SLASHES, "</script><script>…" in a value runs
 * script. Job log output, survey labels and names are such values.
 * Json::htmlEncode() (or the flag JSON_HEX_TAG) escapes them.
 *
 * Every line under views/ that calls json_encode( or Json::encode( fails
 * this test, unless it passes JSON_HEX_TAG or is listed in ALLOWED with the
 * reason the JSON never reaches a script as it is.
 */
class InlineScriptJsonTest extends TestCase
{
    /**
     * Calls of json_encode() or Json::encode() in any case (PHP names are
     * case-insensitive), also as \yii\helpers\Json or BaseJson.
     */
    private const ENCODER = '/(?:Json\s*::\s*encode|json_encode)\s*\(/i';

    /**
     * Allowed lines: file => [a fragment of the line => why it is safe].
     */
    private const ALLOWED = [
        'views/audit-log/view.php' => [
            '? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)' =>
                'Pretty-prints the audit context into a <pre>, through Html::encode(); not in a script.',
        ],
        'views/profile/recovery-codes.php' => [
            'onclick="copyToClipboard(<?= Html::encode(json_encode(implode("\n", $recoveryCodes))) ?>)' =>
                'A JS string in an onclick attribute: Html::encode() escapes it for the attribute, which the'
                . ' browser decodes before the script runs. Json::htmlEncode() would not do: its quotes stay literal.',
        ],
    ];

    public function testViewsWriteJsonOnlyHexEscaped(): void
    {
        $offenders = [];
        foreach ($this->viewLines() as [$file, $number, $line]) {
            if ($this->encodesJson($line) && !str_contains($line, 'JSON_HEX_TAG') && $this->allowedReason($file, $line) === null) {
                $offenders[] = "{$file}:{$number}: " . trim($line);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "JSON in views must be hex-escaped: use Json::htmlEncode() (or JSON_HEX_TAG).\n"
            . 'If the JSON never reaches a script as it is, add the line to ' . self::class . "::ALLOWED with the reason.\n"
        );
    }

    /**
     * An allowed line that changed or went away has to be checked again.
     */
    public function testEveryAllowedLineStillExists(): void
    {
        $found = [];
        foreach ($this->viewLines() as [$file, , $line]) {
            foreach (array_keys(self::ALLOWED[$file] ?? []) as $fragment) {
                if (str_contains($line, $fragment) && $this->encodesJson($line)) {
                    $found[$file . ': ' . $fragment] = true;
                }
            }
        }

        foreach (self::ALLOWED as $file => $fragments) {
            foreach ($fragments as $fragment => $reason) {
                $this->assertNotSame('', trim($reason), "{$file}: give the reason the line is allowed");
                $this->assertArrayHasKey($file . ': ' . $fragment, $found, "Allowed line not found in {$file}: {$fragment}");
            }
        }
    }

    /**
     * The guard is only as good as its pattern.
     */
    public function testThePatternFindsEveryWayToCallTheEncoders(): void
    {
        foreach (
            [
                'var a = <?= json_encode($x) ?>;',
                'var a = <?= JSON_ENCODE ($x) ?>;',
                'var a = <?= Json::encode($x) ?>;',
                'var a = <?= \yii\helpers\Json::encode($x) ?>;',
                'var a = <?= \yii\helpers\BaseJson::encode($x) ?>;',
                'var a = <?= Json :: encode($x) ?>;',
            ] as $line
        ) {
            $this->assertTrue($this->encodesJson($line), $line);
        }

        foreach (
            [
                'var a = <?= Json::htmlEncode($x) ?>;',
                'var a = <?= \yii\helpers\Json::htmlEncode($x) ?>;',
                '$a = json_decode($x, true);',
                '<?= Html::encode($x) ?>',
            ] as $line
        ) {
            $this->assertFalse($this->encodesJson($line), $line);
        }
    }

    private function encodesJson(string $line): bool
    {
        return preg_match(self::ENCODER, $line) === 1;
    }

    private function allowedReason(string $file, string $line): ?string
    {
        foreach (self::ALLOWED[$file] ?? [] as $fragment => $reason) {
            if (str_contains($line, $fragment)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Every line of every view: [path relative to the repository, line number, line].
     *
     * @return \Generator<int, array{0: string, 1: int, 2: string}>
     */
    private function viewLines(): \Generator
    {
        $root = dirname(__DIR__, 3);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/views', \FilesystemIterator::SKIP_DOTS));
        $paths = [];
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);
        $this->assertNotEmpty($paths, 'no views found');

        foreach ($paths as $path) {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            $this->assertIsArray($lines, "cannot read {$path}");
            foreach ($lines as $index => $line) {
                yield [substr($path, strlen($root) + 1), $index + 1, $line];
            }
        }
    }
}
