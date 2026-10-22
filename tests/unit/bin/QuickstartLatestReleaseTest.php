<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;

/**
 * bin/quickstart installs and updates with the compose file of the latest
 * GitHub release, the one the :latest images belong to, and falls back to
 * main only when GitHub cannot say which release that is. Runs the real
 * latest_release_tag() with a stubbed curl. Regression: the repository had
 * no GitHub releases, so the API answered 404 and every install and every
 * --update took docker-compose.prebuilt.yml from main, unreleased changes
 * included.
 */
class QuickstartLatestReleaseTest extends TestCase
{
    /** How api.github.com answers for the latest release (shortened). */
    private const LATEST_RELEASE = <<<'JSON'
{
  "url": "https://api.github.com/repos/ansilume/ansilume/releases/261000000",
  "html_url": "https://github.com/ansilume/ansilume/releases/tag/v2.12.1",
  "id": 261000000,
  "tag_name": "v2.12.1",
  "target_commitish": "main",
  "name": "Ansilume 2.12.1",
  "draft": false,
  "prerelease": false,
  "body": "Images rebuilt with PHP 8.2.35. \"tag_name\": \"v0.0.1\""
}
JSON;

    private const CURL_STUB = <<<'BASH'
curl() {
    local a url=""
    for a in "$@"; do
        case "$a" in
            https://*) url="$a" ;;
        esac
    done
    printf '%s\n' "$url" >> "$STUB_URLS"
    if [ "${STUB_CURL_EXIT:-0}" != "0" ]; then
        return "$STUB_CURL_EXIT"
    fi
    printf '%s' "$STUB_BODY"
}

BASH;

    public function testItReadsTheTagOfTheLatestRelease(): void
    {
        [$exitCode, $stdout, $urls] = $this->latestReleaseTag(['STUB_BODY' => self::LATEST_RELEASE]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("v2.12.1\n", $stdout);
        $this->assertSame(["https://api.github.com/repos/ansilume/ansilume/releases/latest"], $urls);
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function noReleaseProvider(): array
    {
        return [
            'no release (curl -f exits 22 on the 404)' => [['STUB_CURL_EXIT' => '22', 'STUB_BODY' => '']],
            'offline (curl exits 6)' => [['STUB_CURL_EXIT' => '6', 'STUB_BODY' => '']],
            'an answer without a tag' => [['STUB_BODY' => '{"message": "API rate limit exceeded"}']],
        ];
    }

    /**
     * @param array<string, string> $env
     * @dataProvider noReleaseProvider
     */
    public function testItPrintsNothingWhenGithubNamesNoRelease(array $env): void
    {
        [$exitCode, $stdout] = $this->latestReleaseTag($env);

        $this->assertSame(0, $exitCode, 'set -e must not stop the quickstart here.');
        $this->assertSame('', $stdout);
    }

    /**
     * The version comes from latest_release_tag(), and both downloads of
     * the compose file, for an install and for --update, use its tag.
     */
    public function testTheComposeFileComesFromTheLatestRelease(): void
    {
        $quickstart = $this->quickstart();

        $this->assertStringContainsString("ANSILUME_VERSION=\$(latest_release_tag)\nANSILUME_VERSION=\"\${ANSILUME_VERSION:-latest}\"\n", $quickstart);
        $this->assertStringContainsString("COMPOSE_DOWNLOAD_REF=\"\${ANSILUME_VERSION}\"\n[ \"\$COMPOSE_DOWNLOAD_REF\" = \"latest\" ] && COMPOSE_DOWNLOAD_REF=\"main\"\n", $quickstart);
        $this->assertSame(2, substr_count($quickstart, '"https://raw.githubusercontent.com/ansilume/ansilume/${COMPOSE_DOWNLOAD_REF}/docker-compose.prebuilt.yml"'));
        $this->assertSame(0, substr_count($quickstart, 'raw.githubusercontent.com/ansilume/ansilume/main/docker-compose'));
    }

    /**
     * @param array<string, string> $env
     * @return array{0: int, 1: string, 2: list<string>} exit code, stdout, requested URLs
     */
    private function latestReleaseTag(array $env): array
    {
        $this->assertSame(
            1,
            preg_match('/^latest_release_tag\(\) \{.*?\n\}/ms', $this->quickstart(), $function),
            'Could not extract latest_release_tag() from bin/quickstart'
        );
        $urls = (string)tempnam(sys_get_temp_dir(), 'quickstart_urls_');
        $harness = "set -euo pipefail\n" . self::CURL_STUB . $function[0] . "\nlatest_release_tag\n";

        try {
            $process = proc_open(['bash', '-c', $harness], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + [
                'PATH' => (string)getenv('PATH'),
                'STUB_URLS' => $urls,
            ]);
            $this->assertIsResource($process);
            $stdout = (string)stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $requested = array_values(array_filter(explode("\n", (string)file_get_contents($urls))));
        } finally {
            unlink($urls);
        }

        return [$exitCode, $stdout, $requested];
    }

    private function quickstart(): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/bin/quickstart');
        $this->assertNotFalse($content, 'Could not read bin/quickstart');

        return $content;
    }
}
