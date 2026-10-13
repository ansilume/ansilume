<?php

declare(strict_types=1);

namespace app\tests\unit\controllers;

use app\components\FlashAlerts;
use PHPUnit\Framework\TestCase;

/**
 * Every flash a controller sets is an alert or a data flash, and no data
 * flash can be printed by the layout.
 *
 * - An alert has one of the types the layout prints, success, info, warning
 *   and danger (FlashAlerts::TYPES): views/layouts/main.php prints each as
 *   <div class="alert alert-<type>">, and Bootstrap styles only those.
 * - A data flash (DATA_FLASHES) carries a new token to one page, which shows
 *   it in a card of its own. That page reads it with
 *   getFlash($key, null, true), the layout prints only alerts
 *   (FlashAlerts::of()), and the debug module's request panel censors it
 *   (config/debug.php).
 *
 * Regression: AnalyticsController (an export with invalid filters) and
 * RoleController (deleting a system role) set the type "error", as the
 * notification test did before them: the message showed as an unstyled box
 * (alert-error).
 *
 * Regression: the runner group page and the profile's API tokens page read
 * their data flash without delete=true, and the layout printed every flash
 * that was left: the raw runner token and the raw API token showed a second
 * time on the page. The debug module censored only the trigger token's
 * flash, so it saved the raw runner and API tokens to runtime/debug/.
 *
 * The scans read PHP tokens: for flashes set, the first argument of each
 * ->setFlash() and ->addFlash() call under controllers/, a string literal
 * or a ternary whose two results are string literals; for flashes read, each
 * ->getFlash() and ->getAllFlashes() call under READ_DIRECTORIES. Any other
 * form of the argument (a variable, a constant, a concatenation) is
 * reported as well, so every flash stays visible to this test.
 */
final class FlashTypesTest extends TestCase
{
    /**
     * Flashes that carry data to one page, which shows it in a card of its
     * own; key => that page. They are no alerts: the page has to read the
     * flash with getFlash($key, null, true), and config/debug.php censors it
     * as flashes.<key> and SESSION.<key>.
     */
    private const DATA_FLASHES = [
        'trigger_token_raw' => 'job and workflow template pages: the new trigger token',
        'runner_token' => 'runner group page: the token of a new runner',
        'new_token' => 'API tokens page of the profile: the new API token',
    ];

    /** Where flashes are read: the controllers, the pages, and what they use. */
    private const READ_DIRECTORIES = ['controllers', 'views', 'components', 'helpers'];

    /** The one class that reads every flash: it hands the layout the alerts only. */
    private const ALL_FLASHES_READER = 'components/FlashAlerts.php';

    public function testEveryFlashOfAControllerHasAnAlertType(): void
    {
        $allowed = array_merge(FlashAlerts::TYPES, array_keys(self::DATA_FLASHES));
        $found = [];
        foreach (self::phpFiles(['controllers']) as $relative => $path) {
            foreach (self::flashes((string)file_get_contents($path)) as $flash) {
                if ($flash['types'] === null || array_diff($flash['types'], $allowed) !== []) {
                    $found[] = sprintf('%s:%d ->%s(%s)', $relative, $flash['line'], $flash['call'], self::describe($flash['types']));
                }
            }
        }

        $this->assertSame(
            [],
            $found,
            'Give each flash the type success, info, warning or danger, as a string literal: '
            . 'the layout prints <div class="alert alert-<type>">, and Bootstrap styles only those.'
        );
    }

    /**
     * The layout prints exactly the types Bootstrap styles, and no data
     * flash is one of them.
     */
    public function testTheAlertTypesAreTheStyledOnes(): void
    {
        $this->assertSame(['success', 'info', 'warning', 'danger'], FlashAlerts::TYPES);
        $this->assertSame([], array_intersect(array_keys(self::DATA_FLASHES), FlashAlerts::TYPES));
    }

    /**
     * Every data flash is still set by a controller, so the list cannot keep
     * a name that would let a stray type through.
     */
    public function testEveryDataFlashIsStillSet(): void
    {
        $types = [];
        foreach (self::phpFiles(['controllers']) as $path) {
            foreach (self::flashes((string)file_get_contents($path)) as $flash) {
                $types = array_merge($types, $flash['types'] ?? []);
            }
        }

        foreach (array_keys(self::DATA_FLASHES) as $key) {
            $this->assertContains($key, $types, $key);
        }
    }

    /**
     * Regression: the runner group page and the profile's API tokens page
     * read their token's flash without delete=true, so the flash was still
     * there when the layout printed the flashes that were left: the raw
     * runner token and the raw API token were printed a second time.
     *
     * Each read of a data flash deletes it; each data flash is read
     * somewhere (the scan sees its pages); a read whose key is no string
     * literal is reported, since it could be a data flash.
     */
    public function testEveryDataFlashIsReadWithDelete(): void
    {
        $found = [];
        $read = [];
        foreach (self::phpFiles(self::READ_DIRECTORIES) as $relative => $path) {
            foreach (self::reads((string)file_get_contents($path)) as $call) {
                if ($call['key'] === null) {
                    $found[] = sprintf('%s:%d ->getFlash() with a key that is no string literal', $relative, $call['line']);
                } elseif (array_key_exists($call['key'], self::DATA_FLASHES)) {
                    $read[] = $call['key'];
                    if (!$call['delete']) {
                        $found[] = sprintf("%s:%d ->getFlash('%s') without delete=true", $relative, $call['line'], $call['key']);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $found,
            'Read a data flash with getFlash($key, null, true): the layout must never find it.'
        );
        foreach (array_keys(self::DATA_FLASHES) as $key) {
            $this->assertContains($key, $read, "the page that shows {$key} reads it");
        }
    }

    /**
     * The layout gets its flashes from FlashAlerts::of(), which leaves out
     * every type that is no alert; nothing else reads all flashes at once,
     * so no page can print a data flash among the alerts.
     */
    public function testOnlyFlashAlertsReadsEveryFlash(): void
    {
        $readers = [];
        foreach (self::phpFiles(self::READ_DIRECTORIES) as $relative => $path) {
            foreach (self::calls((string)file_get_contents($path), ['getallflashes']) as $call) {
                $readers[] = sprintf('%s:%d', $relative, $call['line']);
            }
        }

        $this->assertCount(1, $readers, implode(', ', $readers));
        $this->assertStringStartsWith(self::ALL_FLASHES_READER . ':', $readers[0]);
        $layout = (string)file_get_contents(dirname(__DIR__, 3) . '/views/layouts/main.php');
        $this->assertSame(1, substr_count($layout, '\app\components\FlashAlerts::of(\Yii::$app->session)'), 'the layout prints the alerts of FlashAlerts::of()');
    }

    /**
     * Regression: the debug module's request panel (YII_ENV dev) censored
     * only the trigger token's flash, so it saved the raw runner token and
     * the raw API token to runtime/debug/, among the flashes and in the
     * session.
     */
    public function testTheDebugModuleCensorsEveryDataFlash(): void
    {
        $censored = self::debugCensoredNames();

        foreach (array_keys(self::DATA_FLASHES) as $key) {
            $this->assertContains('flashes.' . $key, $censored);
            $this->assertContains('SESSION.' . $key, $censored);
        }
        foreach ($censored as $name) {
            if (str_starts_with($name, 'flashes.')) {
                $this->assertArrayHasKey(substr($name, strlen('flashes.')), self::DATA_FLASHES, "{$name}: no data flash, or one this list lacks");
            }
        }
        $this->assertContains('SESSION.totp_setup_secret', $censored, 'the TOTP secret of a setup in progress');
    }

    public function testTheScanReadsEveryController(): void
    {
        $files = array_keys(self::phpFiles(['controllers']));

        foreach (['AnalyticsController.php', 'RoleController.php', 'RunnerGroupController.php', 'api/v1/BaseApiController.php'] as $file) {
            $this->assertContains('controllers/' . $file, $files);
        }
        $controllers = glob(dirname(__DIR__, 3) . '/controllers/*Controller.php');
        $this->assertIsArray($controllers);
        $this->assertGreaterThan(25, count($controllers));
        foreach ($controllers as $controller) {
            $this->assertContains('controllers/' . basename($controller), $files);
        }
    }

    /**
     * The read scan sees the pages that show the data flashes, and the
     * layout.
     */
    public function testTheReadScanReadsThePages(): void
    {
        $files = array_keys(self::phpFiles(self::READ_DIRECTORIES));

        foreach (['views/layouts/main.php', 'views/runner-group/view.php', 'views/job-template/view.php', 'controllers/ProfileController.php', self::ALL_FLASHES_READER] as $file) {
            $this->assertContains($file, $files);
        }
    }

    /**
     * The scan finds each way of writing a flash, with the types its first
     * argument can have, and nothing else.
     */
    public function testTheScanFindsEveryFormOfAFlash(): void
    {
        $code = <<<'PHP'
            <?php
            class Sample
            {
                public function actionA(): void
                {
                    $this->session()->setFlash('success', 'a');
                    \Yii::$app->session->setFlash("danger", "b");
                    \Yii::$app->session?->setFlash('error', 'c');
                    $session -> AddFlash ('info', 'd');
                    $this->session()->setFlash(
                        'warning',
                        sprintf('%s, %s', 'e', 'f')
                    );
                    $this->session()->setFlash($failed ? 'warning' : 'success', $summary);
                    $this->session()->setFlash($type, 'g');
                    $this->session()->setFlash(self::TYPE, 'h');
                    $this->session()->setFlash('dan' . 'ger', 'i');
                    $this->session()->setFlash($a ?: 'info', 'j');
                    $this->session()->setFlash(strtolower('INFO'), 'k');
                    $this->session()->setFlash($a ? ($b ? 'info' : 'danger') : 'success', 'l');
                    // $this->session()->setFlash('in a comment', 'm');
                    $this->session()->getFlash('error');
                    $this->setFlash = 'error';
                    setFlash('error', 'n');
                }
            }
            PHP;

        $this->assertSame(
            [
                ['line' => 6, 'call' => 'setFlash', 'types' => ['success']],
                ['line' => 7, 'call' => 'setFlash', 'types' => ['danger']],
                ['line' => 8, 'call' => 'setFlash', 'types' => ['error']],
                ['line' => 9, 'call' => 'AddFlash', 'types' => ['info']],
                ['line' => 10, 'call' => 'setFlash', 'types' => ['warning']],
                ['line' => 14, 'call' => 'setFlash', 'types' => ['warning', 'success']],
                ['line' => 15, 'call' => 'setFlash', 'types' => null],
                ['line' => 16, 'call' => 'setFlash', 'types' => null],
                ['line' => 17, 'call' => 'setFlash', 'types' => null],
                ['line' => 18, 'call' => 'setFlash', 'types' => null],
                ['line' => 19, 'call' => 'setFlash', 'types' => null],
                ['line' => 20, 'call' => 'setFlash', 'types' => null],
            ],
            self::flashes($code)
        );
    }

    /**
     * The read scan finds each way of reading a flash, with its key when that
     * is a string literal and whether the read deletes the flash.
     */
    public function testTheScanFindsEveryFormOfAFlashRead(): void
    {
        $code = <<<'PHP'
            <?php
            $a = $session->getFlash('runner_token');
            $b = \Yii::$app->session?->getFlash('runner_token', null, true);
            $c = $session->getFlash("new_token", [], TRUE);
            $d = $session -> GetFlash ('new_token', null, false);
            $e = $session->getFlash('new_token', null, delete: true);
            $f = $session->getFlash('trigger_token_raw', fallback(1, 2), true);
            $g = $session->getFlash($key, null, true);
            $h = $session->getFlash(self::KEY, null, true);
            $i = $session->getFlash(
                'runner_token',
                null,
                true
            );
            $j = $session->getFlash('runner_token', null, $delete);
            // $session->getFlash('in a comment');
            $k = getFlash('runner_token');
            $l = $session->hasFlash('runner_token');
            PHP;

        $this->assertSame(
            [
                ['line' => 2, 'key' => 'runner_token', 'delete' => false],
                ['line' => 3, 'key' => 'runner_token', 'delete' => true],
                ['line' => 4, 'key' => 'new_token', 'delete' => true],
                ['line' => 5, 'key' => 'new_token', 'delete' => false],
                ['line' => 6, 'key' => 'new_token', 'delete' => true],
                ['line' => 7, 'key' => 'trigger_token_raw', 'delete' => true],
                ['line' => 8, 'key' => null, 'delete' => true],
                ['line' => 9, 'key' => null, 'delete' => true],
                ['line' => 10, 'key' => 'runner_token', 'delete' => true],
                ['line' => 15, 'key' => 'runner_token', 'delete' => false],
            ],
            self::reads($code)
        );
    }

    /**
     * @param list<string>|null $types
     */
    private static function describe(?array $types): string
    {
        if ($types === null) {
            return 'a type that is no string literal';
        }

        return implode(' or ', array_map(static fn (string $type): string => "'{$type}'", $types));
    }

    /**
     * Every PHP file under $directories (those that exist), by path relative
     * to the application root.
     *
     * @param list<string> $directories
     * @return array<string, string>
     */
    private static function phpFiles(array $directories): array
    {
        $root = dirname(__DIR__, 3) . '/';
        $files = [];
        foreach ($directories as $directory) {
            if (!is_dir($root . $directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[substr($file->getPathname(), strlen($root))] = $file->getPathname();
                }
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * The config of the debug module's request panel: the names it censors.
     *
     * @return list<string>
     */
    private static function debugCensoredNames(): array
    {
        $config = require dirname(__DIR__, 3) . '/config/debug.php';
        self::assertIsArray($config);
        $panels = $config['panels'] ?? null;
        self::assertIsArray($panels);
        $request = $panels['request'] ?? null;
        self::assertIsArray($request);
        $names = $request['censoredVariableNames'] ?? null;
        self::assertIsArray($names);

        return array_values(array_filter($names, 'is_string'));
    }

    /**
     * The tokens of $code that matter for a call: no whitespace, no comments.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function tokens(string $code): array
    {
        return array_values(array_filter(
            token_get_all($code),
            static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }

    /**
     * The calls ->name(...) / ?->name(...) in $code whose name is one of
     * $names (lower case), with their line and arguments.
     *
     * @param list<string> $names
     * @return list<array{line: int, call: string, arguments: list<list<array{0: int, 1: string, 2: int}|string>>}>
     */
    private static function calls(string $code, array $names): array
    {
        $tokens = self::tokens($code);
        $calls = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            $name = $tokens[$index + 1] ?? null;
            if (
                is_array($name)
                && $name[0] === T_STRING
                && in_array(strtolower($name[1]), $names, true)
                && ($tokens[$index + 2] ?? null) === '('
            ) {
                $calls[] = ['line' => $name[2], 'call' => $name[1], 'arguments' => self::arguments($tokens, $index + 3)];
            }
        }

        return $calls;
    }

    /**
     * The calls ->setFlash(...) / ?->setFlash(...) / ->addFlash(...) in
     * $code, with their line and the types their first argument can have:
     * the string literal, or both results of a ternary of string literals;
     * null for any other argument.
     *
     * @return list<array{line: int, call: string, types: list<string>|null}>
     */
    private static function flashes(string $code): array
    {
        $flashes = [];
        foreach (self::calls($code, ['setflash', 'addflash']) as $call) {
            $flashes[] = ['line' => $call['line'], 'call' => $call['call'], 'types' => self::types($call['arguments'][0] ?? [])];
        }

        return $flashes;
    }

    /**
     * The calls ->getFlash(...) / ?->getFlash(...) in $code, with their line,
     * their key when it is a string literal (else null), and whether they
     * delete the flash: a third argument, or a named argument delete, that is
     * the literal true.
     *
     * @return list<array{line: int, key: string|null, delete: bool}>
     */
    private static function reads(string $code): array
    {
        $reads = [];
        foreach (self::calls($code, ['getflash']) as $call) {
            $arguments = $call['arguments'];
            $delete = self::isTrueLiteral($arguments[2] ?? []);
            foreach ($arguments as $argument) {
                $delete = $delete || self::isNamedTrue($argument, 'delete');
            }
            $reads[] = ['line' => $call['line'], 'key' => self::literal($arguments[0] ?? []), 'delete' => $delete];
        }

        return $reads;
    }

    /**
     * The arguments of the call that starts at $start (after its opening
     * parenthesis), each as its tokens, up to the closing parenthesis on its
     * own level.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<list<array{0: int, 1: string, 2: int}|string>>
     */
    private static function arguments(array $tokens, int $start): array
    {
        $arguments = [];
        $argument = [];
        $depth = 0;
        for ($index = $start; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;
            if ($depth === 0 && ($text === ',' || $text === ')')) {
                $arguments[] = $argument;
                $argument = [];
                if ($text === ')') {
                    break;
                }
                continue;
            }
            if (in_array($text, ['(', '[', '{', '${'], true) || (is_array($token) && $token[0] === T_CURLY_OPEN)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            }
            $argument[] = $token;
        }

        return $arguments;
    }

    /**
     * The types an argument can have: one string literal, or a ternary on
     * its own level whose two results are one string literal each.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $argument
     * @return list<string>|null
     */
    private static function types(array $argument): ?array
    {
        $question = array_search('?', $argument, true);
        if ($question === false) {
            $literal = self::literal($argument);

            return $literal === null ? null : [$literal];
        }
        $colon = array_search(':', $argument, true);
        if ($colon === false || $colon < $question) {
            return null;
        }
        $then = self::literal(array_slice($argument, $question + 1, $colon - $question - 1));
        $else = self::literal(array_slice($argument, $colon + 1));

        return $then === null || $else === null ? null : [$then, $else];
    }

    /**
     * The value of a lone string literal, else null.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function literal(array $tokens): ?string
    {
        if (count($tokens) !== 1 || !is_array($tokens[0]) || $tokens[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return substr($tokens[0][1], 1, -1);
    }

    /**
     * Whether $tokens is the lone literal true (in any case).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isTrueLiteral(array $tokens): bool
    {
        return count($tokens) === 1 && is_array($tokens[0]) && $tokens[0][0] === T_STRING && strtolower($tokens[0][1]) === 'true';
    }

    /**
     * Whether $tokens is the named argument $name: true.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isNamedTrue(array $tokens, string $name): bool
    {
        return count($tokens) === 3
            && is_array($tokens[0]) && $tokens[0][0] === T_STRING && $tokens[0][1] === $name
            && $tokens[1] === ':'
            && self::isTrueLiteral([$tokens[2]]);
    }
}
