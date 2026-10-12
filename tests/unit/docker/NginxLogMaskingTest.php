<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;

/**
 * Regression: nginx wrote the access log in the predefined "combined"
 * format, so trigger tokens in URLs (?token=<token>, /trigger/<token>) and
 * password-reset tokens landed in the container logs, and from there in
 * bin/diagnose output and log shippers.
 *
 * Both nginx configs (the image's docker/nginx/default.conf and the deploy
 * role's template) mask them with chained maps and the log format
 * ansilume_masked. This test takes the maps from each file and evaluates
 * them as nginx does: exact strings first, then the regular expressions in
 * order, first match wins (PHP's preg is PCRE, like nginx), then the
 * default. The log line keeps the field order of "combined".
 */
class NginxLogMaskingTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const OTHER = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

    private const COMBINED = '$remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent"';

    /**
     * @return array<string, array{0: string}>
     */
    public static function configProvider(): array
    {
        return [
            'image config' => ['docker/nginx/default.conf'],
            'deploy role template' => ['deploy/roles/ansilume/templates/nginx.conf'],
        ];
    }

    /**
     * Request URIs and what the access log shows of them.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function requestUriProvider(): array
    {
        $t = self::TOKEN;

        return [
            'query token' => ["/trigger/fire?token={$t}", '/trigger/fire?token=[REDACTED]'],
            'query token after another parameter' => ["/trigger/fire-workflow?env=prod&token={$t}", '/trigger/fire-workflow?env=prod&token=[REDACTED]'],
            'everything after the token goes' => ["/trigger/fire?token={$t}&env=prod", '/trigger/fire?token=[REDACTED]'],
            'repeated token parameter' => ["/trigger/fire?token={$t}&token={$t}", '/trigger/fire?token=[REDACTED]'],
            'parameter name in capitals' => ["/trigger/fire?TOKEN={$t}", '/trigger/fire?token=[REDACTED]'],
            'path token' => ["/trigger/{$t}", '/trigger/[REDACTED]'],
            'path token with a trailing slash' => ["/trigger/{$t}/", '/trigger/[REDACTED]'],
            'path token in capitals' => ['/trigger/' . strtoupper($t), '/trigger/[REDACTED]'],
            'path in capitals' => ["/TRIGGER/{$t}", '/TRIGGER/[REDACTED]'],
            'path and query token in one line' => ["/trigger/{$t}?token=" . self::OTHER, '/trigger/[REDACTED]?token=[REDACTED]'],
            'path token behind the script name' => ["/index.php/trigger/{$t}", '/index.php/trigger/[REDACTED]'],
            'token behind an action' => ["/trigger/fire/{$t}", '/trigger/[REDACTED]'],
            'absolute-form request target' => ["http://ansilume.example/trigger/{$t}", 'http://ansilume.example/trigger/[REDACTED]'],
            'password-reset link' => ["/reset-password?token={$t}", '/reset-password?token=[REDACTED]'],
            'trigger endpoint' => ['/trigger/fire', '/trigger/fire'],
            'workflow trigger endpoint' => ['/trigger/fire-workflow', '/trigger/fire-workflow'],
            'endpoint with a trailing slash' => ['/trigger/fire/', '/trigger/fire/'],
            'endpoint with a trailing slash and a query' => ['/trigger/fire-workflow/?env=prod', '/trigger/fire-workflow/?env=prod'],
            'nothing after trigger/' => ['/trigger/', '/trigger/'],
            'another page' => ['/job-template/view?id=5', '/job-template/view?id=5'],
            'a parameter that only starts with token' => ['/job/index?tokens=keep', '/job/index?tokens=keep'],
            'a parameter that only ends with token' => ['/job/index?my_token=keep', '/job/index?my_token=keep'],
        ];
    }

    /**
     * Referrers and what the access log shows of them.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refererProvider(): array
    {
        $t = self::TOKEN;

        return [
            'none' => ['', '-'],
            'another page' => ['https://ansilume.example/job-template/view?id=5', 'https://ansilume.example/job-template/view?id=5'],
            'password-reset link' => ["https://ansilume.example/reset-password?token={$t}", 'https://ansilume.example/reset-password?token=[REDACTED]'],
            'path token' => ["https://ansilume.example/trigger/{$t}", 'https://ansilume.example/trigger/[REDACTED]'],
            'path and query token' => ["https://ci.example/trigger/{$t}?token=" . self::OTHER, 'https://ci.example/trigger/[REDACTED]?token=[REDACTED]'],
            'trigger endpoint' => ['https://ansilume.example/trigger/fire', 'https://ansilume.example/trigger/fire'],
        ];
    }

    /**
     * @dataProvider configProvider
     */
    public function testRequestUrisAreMasked(string $file): void
    {
        $maps = $this->maps($file);
        foreach (self::requestUriProvider() as $label => [$uri, $expected]) {
            $logged = $this->evaluate($maps, '$ansilume_log_uri', ['$request_uri' => $uri]);
            $this->assertSame($expected, $logged, "{$file}: {$label}");
            $this->assertStringNotContainsStringIgnoringCase(self::TOKEN, $logged, "{$file}: {$label}");
            $this->assertStringNotContainsString(self::OTHER, $logged, "{$file}: {$label}");
        }
    }

    /**
     * @dataProvider configProvider
     */
    public function testReferrersAreMasked(string $file): void
    {
        $maps = $this->maps($file);
        foreach (self::refererProvider() as $label => [$referer, $expected]) {
            $logged = $this->evaluate($maps, '$ansilume_log_referer', ['$http_referer' => $referer]);
            $this->assertSame($expected, $logged, "{$file}: {$label}");
            $this->assertStringNotContainsString(self::TOKEN, $logged, "{$file}: {$label}");
        }
    }

    /**
     * A log line has the fields of "combined", in its order: a line without
     * a token is the combined line, and one with tokens differs only by the
     * masks, in the request and the referrer of the same line.
     *
     * @dataProvider configProvider
     */
    public function testTheLogLineKeepsTheCombinedFormat(string $file): void
    {
        $config = $this->config($file);
        $this->assertSame(1, preg_match("/^log_format ansilume_masked '([^']*)';$/m", $config, $m), 'one log_format line');
        $format = $m[1];
        $maps = $this->maps($file);
        $plain = $this->variables('/job-template/view?id=5', 'https://ansilume.example/job-template/index');
        $this->assertSame($this->render(self::COMBINED, $maps, $plain), $this->render($format, $maps, $plain));

        $secret = $this->variables('/trigger/' . self::TOKEN, 'https://ansilume.example/reset-password?token=' . self::OTHER);
        $this->assertSame(
            '192.0.2.10 - - [11/Oct/2026:12:00:00 +0000] "POST /trigger/[REDACTED] HTTP/1.1" 201 14 '
                . '"https://ansilume.example/reset-password?token=[REDACTED]" "curl/8.5.0"',
            $this->render($format, $maps, $secret)
        );
    }

    /**
     * @dataProvider configProvider
     */
    public function testTheAccessLogUsesTheMaskedFormatInTheHttpContext(string $file): void
    {
        $config = $this->config($file);

        $this->assertSame(1, preg_match_all('/^[ \t]*access_log\s.*$/m', $config, $lines));
        $this->assertSame('access_log /var/log/nginx/access.log ansilume_masked;', trim($lines[0][0]));
        $server = strpos($config, "\nserver {");
        $this->assertNotFalse($server);
        foreach (['map ', 'log_format '] as $directive) {
            $this->assertSame(0, preg_match('/^[ \t]+' . $directive . '/m', $config), "{$directive}is not allowed inside server {}");
            $position = strpos($config, "\n" . $directive);
            $this->assertNotFalse($position);
            $this->assertLessThan($server, $position, "{$directive}comes before server {}, in the http context");
        }
    }

    /**
     * The masking block is the same in both files, and the deploy template
     * is Jinja2: the block must not contain its delimiters.
     */
    public function testBothFilesHaveTheSameMaskingBlock(): void
    {
        $blocks = [];
        foreach (self::configProvider() as [$file]) {
            $config = $this->config($file);
            $start = strpos($config, "\nmap ");
            $end = strpos($config, "\nlog_format ");
            $this->assertNotFalse($start, $file);
            $this->assertNotFalse($end, $file);
            $blocks[$file] = substr($config, $start, (int)strpos($config, "\n", $end + 1) - $start);
        }

        $this->assertCount(1, array_unique($blocks), 'identical masking blocks');
        $block = (string)reset($blocks);
        foreach (['{{', '{%', '{#'] as $delimiter) {
            $this->assertStringNotContainsString($delimiter, $block, 'no Jinja2 delimiter');
        }
    }

    /**
     * Named captures are variables of the whole request in nginx, so two
     * maps must not share a name.
     *
     * @dataProvider configProvider
     */
    public function testCaptureNamesAreUnique(string $file): void
    {
        preg_match_all('/\(\?<(\w+)>/', $this->config($file), $names);

        $this->assertNotSame([], $names[1]);
        $this->assertSame($names[1], array_values(array_unique($names[1])));
    }

    // ── nginx emulation ──────────────────────────────────────────────────────

    private function config(string $file): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        $this->assertIsString($content);

        return $content;
    }

    /**
     * The map blocks of $file by result variable.
     *
     * @return array<string, array{source: string, entries: list<array{0: string, 1: string}>, default: string}>
     */
    private function maps(string $file): array
    {
        preg_match_all('/^map\s+(\$\w+)\s+(\$\w+)\s*\{\n(.*?)^\}/ms', $this->config($file), $blocks, PREG_SET_ORDER);
        $this->assertCount(4, $blocks, "{$file}: two chained maps each for the request URI and the referrer");

        $maps = [];
        foreach ($blocks as $block) {
            $entries = [];
            $default = null;
            foreach ($this->statements($block[3]) as [$key, $value]) {
                if ($key === 'default') {
                    $default = $value;
                } else {
                    $entries[] = [$key, $value];
                }
            }
            $this->assertNotNull($default, "{$file}: {$block[2]} has a default");
            $maps[$block[2]] = ['source' => $block[1], 'entries' => $entries, 'default' => $default];
        }

        return $maps;
    }

    /**
     * The "key value;" statements of a map body, with nginx's quoting
     * removed.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function statements(string $body): array
    {
        preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|([^\s\'";]+)|(;)/', $body, $tokens, PREG_SET_ORDER);
        $statements = [];
        $words = [];
        foreach ($tokens as $token) {
            if (($token[4] ?? '') === ';') {
                $this->assertCount(2, $words, 'a map entry has a key and a value');
                $statements[] = [$words[0], $words[1]];
                $words = [];
                continue;
            }
            $words[] = ($token[3] ?? '') !== '' ? $token[3] : $this->unquote($token[1] !== '' ? $token[1] : ($token[2] ?? ''));
        }
        $this->assertSame([], $words, 'every statement ends with ;');

        return $statements;
    }

    /**
     * nginx's unescaping inside quotes: \" \' \\ and \t \r \n.
     */
    private function unquote(string $quoted): string
    {
        return (string)preg_replace_callback(
            '/\\\\(["\'\\\\trn])/',
            static fn (array $m): string => ['t' => "\t", 'r' => "\r", 'n' => "\n"][$m[1]] ?? $m[1],
            $quoted
        );
    }

    /**
     * The value of $variable: a given one, or the result of its map.
     *
     * @param array<string, array{source: string, entries: list<array{0: string, 1: string}>, default: string}> $maps
     * @param array<string, string> $vars
     */
    private function evaluate(array $maps, string $variable, array $vars): string
    {
        if (array_key_exists($variable, $vars)) {
            return $vars[$variable];
        }
        $this->assertArrayHasKey($variable, $maps, "{$variable} is set or mapped");
        $map = $maps[$variable];
        $input = $this->evaluate($maps, $map['source'], $vars);

        foreach ($map['entries'] as [$key, $value]) {
            if (!str_starts_with($key, '~') && $key === $input) {
                return $this->interpolate($value, [], $maps, $vars);
            }
        }
        foreach ($map['entries'] as [$key, $value]) {
            if (!str_starts_with($key, '~')) {
                continue;
            }
            $caseless = str_starts_with($key, '~*');
            $regex = substr($key, $caseless ? 2 : 1);
            $this->assertStringNotContainsString('#', $regex);
            $matched = preg_match('#' . $regex . '#' . ($caseless ? 'i' : ''), $input, $captures, PREG_UNMATCHED_AS_NULL);
            $this->assertNotFalse($matched, "valid PCRE: {$regex}");
            if ($matched === 1) {
                return $this->interpolate($value, $captures, $maps, $vars);
            }
        }

        return $this->interpolate($map['default'], [], $maps, $vars);
    }

    /**
     * Replace $name and ${name} in a map value or log format: named captures
     * of the matching regex first (empty when they did not take part), then
     * variables.
     *
     * @param array<int|string, string|null> $captures
     * @param array<string, array{source: string, entries: list<array{0: string, 1: string}>, default: string}> $maps
     * @param array<string, string> $vars
     */
    private function interpolate(string $value, array $captures, array $maps, array $vars): string
    {
        return (string)preg_replace_callback(
            '/\$\{(\w+)\}|\$(\w+)/',
            function (array $m) use ($captures, $maps, $vars): string {
                $name = $m[1] !== '' ? $m[1] : $m[2];
                if (array_key_exists($name, $captures)) {
                    return (string)$captures[$name];
                }

                return $this->evaluate($maps, '$' . $name, $vars);
            },
            $value
        );
    }

    /**
     * @param array<string, array{source: string, entries: list<array{0: string, 1: string}>, default: string}> $maps
     * @param array<string, string> $vars
     */
    private function render(string $format, array $maps, array $vars): string
    {
        return $this->interpolate($format, [], $maps, $vars);
    }

    /**
     * The variables of one request, as nginx sets them. An empty $remote_user
     * or referrer is logged as "-".
     *
     * @return array<string, string>
     */
    private function variables(string $uri, string $referer): array
    {
        return [
            '$remote_addr' => '192.0.2.10',
            '$remote_user' => '-',
            '$time_local' => '11/Oct/2026:12:00:00 +0000',
            '$request_method' => 'POST',
            '$request_uri' => $uri,
            '$server_protocol' => 'HTTP/1.1',
            '$request' => 'POST ' . $uri . ' HTTP/1.1',
            '$status' => '201',
            '$body_bytes_sent' => '14',
            '$http_referer' => $referer,
            '$http_user_agent' => 'curl/8.5.0',
        ];
    }
}
