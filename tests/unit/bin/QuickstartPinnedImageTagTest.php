<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * ANSILUME_IMAGE_TAG pins the images of docker-compose.prebuilt.yml to a
 * version (Rollback in docs/updating.md). bin/quickstart --update reads the
 * pin as Compose does, warns that it keeps the images where they are, and
 * names the version that runs instead of saying the latest one does.
 *
 * Regression found in review: with a pin, --update pulled nothing new and
 * still reported "Ansilume is running the latest version"; with a pin that
 * names no image (v2.12.3), the pull failed silently and the update
 * reported success all the same.
 */
class QuickstartPinnedImageTagTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/quickstart-pin-' . uniqid('', true);
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    /**
     * @return array<string, array{0: ?string, 1: array<string, string>, 2: string}>
     */
    public static function pinProvider(): array
    {
        return [
            'no pin' => ["NGINX_PORT=8080\n", [], ''],
            'a pin in .env' => ["ANSILUME_IMAGE_TAG=2.12.1\n", [], '2.12.1'],
            'the last line wins, quotes and CR go' => ["ANSILUME_IMAGE_TAG=2.11.0\nANSILUME_IMAGE_TAG=\"2.12.1\"\r\n", [], '2.12.1'],
            'latest is no pin' => ["ANSILUME_IMAGE_TAG=latest\n", [], ''],
            'a commented line is no pin' => ["# ANSILUME_IMAGE_TAG=2.12.1\n", [], ''],
            'the environment wins over .env' => ["ANSILUME_IMAGE_TAG=2.12.1\n", ['ANSILUME_IMAGE_TAG' => '2.10.1'], '2.10.1'],
            'no .env' => [null, [], ''],
        ];
    }

    /**
     * @param array<string, string> $env
     * @dataProvider pinProvider
     */
    public function testItReadsThePinAsComposeDoes(?string $dotenv, array $env, string $pin): void
    {
        if ($dotenv !== null) {
            file_put_contents($this->directory . '/.env', $dotenv);
        }

        [$exitCode, $stdout] = $this->pinnedImageTag($env);

        $this->assertSame(0, $exitCode, 'set -e must not stop the quickstart here.');
        $this->assertSame($pin, $stdout);
    }

    /**
     * The update warns about a pin, reports a failed pull, exits 1 then,
     * and names the version that runs.
     */
    public function testTheUpdateSaysWhatRuns(): void
    {
        $quickstart = $this->quickstart();
        $start = strpos($quickstart, '# ── Update path');
        $end = strpos($quickstart, '# ── 4. Database');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $quickstart = substr($quickstart, $start, $end - $start);

        $this->assertStringContainsString("    PINNED_TAG=\$(pinned_image_tag)\n    if [ -n \"\$PINNED_TAG\" ]; then\n        warn \"ANSILUME_IMAGE_TAG=\${PINNED_TAG} keeps the images on \${PINNED_TAG}: remove it from .env to update to the latest release.\"\n", $quickstart);
        $this->assertStringContainsString("pull < /dev/null >/dev/null 2>&1 || PULL_OK=0\n", $quickstart);
        $this->assertStringContainsString("pull < /dev/null 2>&1 | while IFS= read -r line || [ -n \"\$line\" ]; do\n            info \"  \$line\"\n        done || PULL_OK=0\n", $quickstart, 'The -vv branch notices a failed pull too.');
        $this->assertStringContainsString("    if [ \"\$PULL_OK\" = \"0\" ]; then\n        warn \"Update incomplete: the images could not be pulled (see above). Ansilume \${RUNNING_VERSION:+\${RUNNING_VERSION} }is running at \${APP_URL}\"\n    else\n        warn \"Update finished but verification checks failed (see above).\"\n    fi\n    printf \"\\n\"\n    exit 1\n", $quickstart, 'A failed pull ends the update with exit 1.');
        $this->assertStringContainsString("    if [ \"\$UPDATE_OK\" = \"1\" ] && [ \"\$PULL_OK\" = \"1\" ]; then\n        ok \"Update complete. Ansilume \${RUNNING_VERSION:+\${RUNNING_VERSION} }is running at \${APP_URL}\"\n", $quickstart);
        $this->assertStringNotContainsString('running the latest version', $quickstart);
        $this->assertStringNotContainsString('pull < /dev/null >/dev/null 2>&1 || true', $quickstart);
    }

    /**
     * @param array<string, string> $env
     * @return array{0: int, 1: string} exit code, stdout
     */
    private function pinnedImageTag(array $env): array
    {
        $this->assertSame(
            1,
            preg_match('/^pinned_image_tag\(\) \{.*?\n\}/ms', $this->quickstart(), $function),
            'Could not extract pinned_image_tag() from bin/quickstart'
        );
        $process = proc_open(['bash', '-c', "set -euo pipefail\n" . $function[0] . "\npinned_image_tag\n"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory, $env + [
            'PATH' => (string)getenv('PATH'),
        ]);
        $this->assertIsResource($process);
        $stdout = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout];
    }

    private function quickstart(): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/bin/quickstart');
        $this->assertNotFalse($content, 'Could not read bin/quickstart');

        return $content;
    }
}
