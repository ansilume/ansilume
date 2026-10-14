<?php

declare(strict_types=1);

namespace app\tests\unit\views;

use PHPUnit\Framework\TestCase;

/**
 * Guard: the run page's live script (web/js/workflow-run-live.js) writes
 * the poll's server text only as text, and never loads the page again.
 *
 * The poll answers names, notes and errors that users typed. The inline
 * script it replaced built a step's badge with innerHTML, so a label with
 * markup ran as HTML. A page that reloads when a run finishes reloads
 * forever on a stubbed or cached answer that says it has finished. The
 * script is loaded through VersionedAssetUrl, so a browser runs the version
 * the page was built for, and the run page keeps no inline script.
 *
 * Its filters for server values are checked here as the browser applies
 * them: a CSS class only from lower-case letters and dashes, an address
 * only when it starts with a single "/".
 */
final class WorkflowRunLiveScriptTest extends TestCase
{
    private const SCRIPT = 'web/js/workflow-run-live.js';

    /** Ways to turn text into markup, or to run it. */
    private const HTML_SINKS = [
        'innerHTML' => '/\binnerHTML\b/i',
        'outerHTML' => '/\bouterHTML\b/i',
        'insertAdjacentHTML' => '/\binsertAdjacentHTML\b/i',
        'document.write' => '/\bdocument\s*\.\s*write(?:ln)?\b/i',
        'createContextualFragment' => '/\bcreateContextualFragment\b/i',
        'DOMParser' => '/\bDOMParser\b/',
        'eval' => '/\beval\s*\(/',
        'Function constructor' => '/\bnew\s+Function\b/',
    ];

    /** Ways to load the page again or leave it. */
    private const NAVIGATION = [
        'location.reload' => '/\blocation\s*\.\s*reload\b/i',
        'location.assign' => '/\blocation\s*\.\s*assign\b/i',
        'location.replace' => '/\blocation\s*\.\s*replace\b/i',
        'an assignment to location' => '/\blocation(?:\s*\.\s*href)?\s*=(?!=)/i',
        'history.go' => '/\bhistory\s*\.\s*go\b/i',
    ];

    public function testTheScriptWritesNoServerTextAsMarkup(): void
    {
        $this->assertSame([], self::found(self::script(), self::HTML_SINKS));
    }

    public function testTheScriptNeverLoadsThePageAgain(): void
    {
        $this->assertSame([], self::found(self::script(), self::NAVIGATION));
    }

    /**
     * The guard is only as good as its patterns.
     */
    public function testThePatternsFindEverySink(): void
    {
        foreach (
            [
                'el.innerHTML = x;', "el['innerHTML'] = x;", 'el.outerHTML = x;', "el.insertAdjacentHTML('beforeend', x);",
                'document.write(x);', 'document .writeln(x);', 'range.createContextualFragment(x);', 'new DOMParser()',
                // Split, because the eval scan of bin/tests-lint.sh reads the tests too.
                'ev' . 'al(x);', 'new Function(x)',
            ] as $line
        ) {
            $this->assertNotSame([], self::found($line, self::HTML_SINKS), $line);
        }
        foreach (
            [
                'window.location.reload();', 'location.assign(u);', 'location.replace(u);', 'window.location = u;',
                'location.href = u;', 'history.go(0);',
            ] as $line
        ) {
            $this->assertNotSame([], self::found($line, self::NAVIGATION), $line);
        }
        foreach (["el.textContent = x;", "el.setAttribute('href', h);", "if (location.href === u) {}"] as $line) {
            $this->assertSame([], self::found($line, self::HTML_SINKS + self::NAVIGATION), $line);
        }
    }

    /**
     * The run page loads the script through a versioned address and keeps no
     * inline script: the poll client lives in the file only.
     */
    public function testTheRunPageLoadsTheScriptVersionedAndHasNoInlineScript(): void
    {
        $view = self::read('views/workflow-job/view.php');

        $this->assertStringContainsString("<?= Html::jsFile(VersionedAssetUrl::of('/js/workflow-run-live.js')) ?>", $view);
        foreach (glob(self::root() . '/views/workflow-job/*.php') ?: [] as $path) {
            $this->assertDoesNotMatchRegularExpression('/<script\b(?![^>]*\bsrc=)/i', (string)file_get_contents($path), $path);
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function hrefData(): array
    {
        return [
            'a page of the application' => ['/approval/view?id=12', true],
            'the root' => ['/', true],
            'another host, scheme-relative' => ['//evil.example/x', false],
            'another host, through a backslash' => ['/\\evil.example/x', false],
            'a script' => ['javascript:alert(1)', false],
            'another site' => ['https://evil.example/', false],
            'a relative address' => ['approval/view?id=12', false],
            'empty' => ['', false],
            'a leading space' => [' /approval/view?id=12', false],
        ];
    }

    /**
     * @dataProvider hrefData
     */
    public function testAnAddressFromThePollIsUsedOnlyWhenItStartsWithASingleSlash(string $href, bool $used): void
    {
        $this->assertSame($used, preg_match(self::pattern('SAFE_HREF'), $href) === 1);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function classData(): array
    {
        return [
            'a colour' => ['info', true],
            'a colour with a dash' => ['body-secondary', true],
            'two classes' => ['info d-none', false],
            'an attribute' => ['info" onclick="x', false],
            'upper case' => ['Info', false],
            'empty' => ['', false],
        ];
    }

    /**
     * @dataProvider classData
     */
    public function testACssClassFromThePollIsUsedOnlyWhenItIsLowerCaseLettersAndDashes(string $class, bool $used): void
    {
        $this->assertSame($used, preg_match(self::pattern('SAFE_CLASS'), $class) === 1);
    }

    /**
     * The JavaScript regular expression literal assigned to $name, as a PCRE
     * pattern (the two dialects agree for these).
     */
    private static function pattern(string $name): string
    {
        if (preg_match('/^\s*var ' . $name . ' = (\/.+\/);$/m', self::script(), $m) !== 1) {
            self::fail("{$name} is missing from " . self::SCRIPT);
        }

        return $m[1];
    }

    /**
     * @param array<string, string> $patterns
     * @return list<string>
     */
    private static function found(string $source, array $patterns): array
    {
        $found = [];
        foreach ($patterns as $what => $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $found[] = $what;
            }
        }

        return $found;
    }

    private static function script(): string
    {
        return self::read(self::SCRIPT);
    }

    private static function read(string $path): string
    {
        $source = file_get_contents(self::root() . '/' . $path);
        self::assertIsString($source, $path);

        return $source;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
