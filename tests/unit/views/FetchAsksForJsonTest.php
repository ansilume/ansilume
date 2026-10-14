<?php

declare(strict_types=1);

namespace app\tests\unit\views;

use PHPUnit\Framework\TestCase;

/**
 * Guard: every fetch() of the web pages asks for JSON, whatever its method.
 *
 * Unless told otherwise, fetch() asks for anything (Accept: * / *). Signed
 * out, a request that does not ask for JSON is sent to the login page
 * (yii\web\User::loginRequired()), whatever its method: fetch() follows the
 * redirect and the script reads the login page as JSON; with
 * X-Requested-With, Yii names the login page in X-Redirect instead of
 * Location, and the script gets the redirect with an empty body. A GET's
 * address is also kept as the one to return to after the next sign-in:
 * signed in again, the user lands on the JSON the script fetched instead of
 * the dashboard. Asked for JSON (Accept: application/json), a signed-out
 * request is refused with 403 and keeps no address. The job page's poll and
 * its artifact preview, the dashboard's charts and the sync output of a
 * project's page each had that bug (v2.10.1), and so did two POSTs (2.11.0):
 * Generate Ed25519 Key Pair on the credential form, which then said
 * "Request failed: SyntaxError: ...", and Parse Inventory on an inventory's
 * page, which said "Server response was not JSON".
 *
 * Every fetch( in the views and in the scripts of web/js/ (not the minified
 * and vendor files) fails this test unless the call itself sends
 * 'Accept': 'application/json'.
 */
final class FetchAsksForJsonTest extends TestCase
{
    /** A call of fetch(), also as window.fetch(); not prefetch( or $statement->fetch(. */
    private const CALL = '/(?<![\w$>])fetch\s*\(/';

    /** The header that asks for JSON, among the call's arguments (header names ignore case). */
    private const ASKS_FOR_JSON = '/([\'"]?)Accept\1\s*:\s*([\'"])application\/json\2/i';

    /**
     * regression: the POSTs of Generate Ed25519 Key Pair and Parse Inventory
     * did not ask for JSON, and this guard exempted every method but GET.
     */
    public function testEveryFetchAsksForJson(): void
    {
        $offenders = [];
        foreach (self::scannedFiles() as $file) {
            foreach (self::calls((string)file_get_contents(self::root() . '/' . $file)) as [$line, $arguments]) {
                if (!self::asksForJson($arguments)) {
                    $offenders[] = sprintf('%s:%d: fetch(%s)', $file, $line, strtok($arguments, "\n"));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Every fetch() must ask for JSON, whatever its method: headers: { 'Accept': 'application/json' }.\n"
        );
    }

    /**
     * The scan reaches the fetches it guards, so it cannot pass for having
     * found none: both of the job page (its poll and its artifact preview),
     * the poll of a workflow run's page, and the POSTs of the credential
     * form's key generator and of an inventory's Parse Inventory.
     */
    public function testTheScanFindsTheFetchesOfThePages(): void
    {
        $found = [];
        foreach (self::scannedFiles() as $file) {
            $found[$file] = count(self::calls((string)file_get_contents(self::root() . '/' . $file)));
        }

        $this->assertSame(2, $found['views/job/view.php'] ?? 0, 'the poll and the artifact preview of the job page');
        $this->assertSame(1, $found['web/js/workflow-run-live.js'] ?? 0, 'the poll of a workflow run\'s page');
        $this->assertSame(1, $found['views/credential/form.php'] ?? 0, 'Generate Ed25519 Key Pair, a POST');
        $this->assertSame(1, $found['views/inventory/view.php'] ?? 0, 'Parse Inventory, a POST');
        $this->assertArrayNotHasKey('web/js/chart.min.js', $found, 'minified files are not scanned');
        $this->assertArrayNotHasKey('web/js/vendor/codemirror/codemirror.js', $found, 'vendor files are not scanned');
    }

    /**
     * The guard is only as good as its patterns: what counts as a call of
     * fetch(), with its arguments.
     */
    public function testThePatternFindsEveryCallOfFetch(): void
    {
        foreach (
            [
                'fetch(url)' => 'url',
                'window.fetch(url, o)' => 'url, o',
                'return fetch (url);' => 'url',
                "fetch(u + '?a=(' + b + ')', { method: 'POST' })" => "u + '?a=(' + b + ')', { method: 'POST' }",
                "fetch(<?= Json::htmlEncode(Url::to(['/x'])) ?>, {\n    method: 'POST',\n})" => "<?= Json::htmlEncode(Url::to(['/x'])) ?>, {\n    method: 'POST',\n}",
                "x = 1;\n  fetch(a(b), c).then(d);" => 'a(b), c',
            ] as $code => $arguments
        ) {
            $line = substr_count((string)strstr($code, 'fetch', true), "\n") + 1;
            $this->assertSame([[$line, $arguments]], self::calls($code), $code);
        }
        foreach (['$statement->fetch()', 'prefetch(url)', 'refetch(url)', 'fetchAll()', 'a.fetchJson(url)', '// fetch(url)', ' * fetch(url) in a docblock', '/* fetch(url) */'] as $code) {
            $this->assertSame([], self::calls($code), $code);
        }
        $this->assertSame([[1, "'a"]], self::calls("fetch('a"), 'a call that does not end: the rest of the text');
    }

    /**
     * What a call must send to pass: a JSON Accept header, whatever its
     * method.
     */
    public function testThePatternTellsAFetchThatAsksForJsonFromOneThatDoesNot(): void
    {
        foreach (
            [
                "url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }",
                'url, {headers: {"Accept": "application/json"}}',
                "url, { headers: { accept: 'application/json' } }",
                "url, {\n    headers: {\n        Accept: 'application/json'\n    }\n}",
                "url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': t }, body: '{}' }",
                "url, {\n    method: 'POST',\n    headers: {\n        'Accept': 'application/json',\n        'X-Requested-With': 'XMLHttpRequest'\n    }\n}",
            ] as $arguments
        ) {
            $this->assertTrue(self::asksForJson($arguments), $arguments);
        }
        foreach (
            [
                'url',
                "url, { credentials: 'same-origin' }",
                "url, { method: 'GET' }",
                "url, { method: 'get', headers: { 'Accept': 'text/html' } }",
                "url, { headers: { 'X-Accept': 'application/json' } }",
                "url, { headers: { 'Accept': 'application/json, text/html' } }",
                'url, options',
                // regression: a POST was exempt, but signed out it is sent to the login page too.
                "url, { method: 'POST', body: data }",
                'url, { method: "delete" }',
                "url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t }, body: '{}' }",
                "url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } }",
            ] as $arguments
        ) {
            $this->assertFalse(self::asksForJson($arguments), $arguments);
        }
    }

    private static function asksForJson(string $arguments): bool
    {
        return preg_match(self::ASKS_FOR_JSON, $arguments) === 1;
    }

    /**
     * The calls of fetch() in $code, outside comment lines: [line, arguments].
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function calls(string $code): array
    {
        preg_match_all(self::CALL, $code, $matches, PREG_OFFSET_CAPTURE);
        $calls = [];
        foreach ($matches[0] as [$call, $offset]) {
            $lineStart = (int)strrpos(substr($code, 0, $offset), "\n");
            if (preg_match('#^\s*(//|/\*|\*)#', substr($code, $lineStart, $offset - $lineStart)) === 1) {
                continue;
            }
            $calls[] = [substr_count($code, "\n", 0, $offset) + 1, self::arguments($code, $offset + strlen($call) - 1)];
        }

        return $calls;
    }

    /**
     * The text between the "(" at $open and the ")" that closes it, past
     * quoted strings; the rest of $code if nothing closes it.
     */
    private static function arguments(string $code, int $open): string
    {
        $depth = 0;
        $quote = '';
        for ($at = $open, $length = strlen($code); $at < $length; $at++) {
            $char = $code[$at];
            if ($quote !== '') {
                $at += $char === '\\' ? 1 : 0;
                $quote = $char === $quote ? '' : $quote;
                continue;
            }
            $quote = str_contains('\'"`', $char) ? $char : '';
            $depth += ['(' => 1, ')' => -1][$char] ?? 0;
            if ($depth === 0) {
                return substr($code, $open + 1, $at - $open - 1);
            }
        }

        return substr($code, $open + 1);
    }

    /**
     * The views and the scripts of web/js/ that are not minified or vendor
     * files, relative to the repository.
     *
     * @return list<string>
     */
    private static function scannedFiles(): array
    {
        $files = [];
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/views', \FilesystemIterator::SKIP_DOTS));
        foreach ($views as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen(self::root()) + 1);
            }
        }
        foreach (glob(self::root() . '/web/js/*.js') ?: [] as $script) {
            if (!str_ends_with($script, '.min.js')) {
                $files[] = substr($script, strlen(self::root()) + 1);
            }
        }
        sort($files);

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
