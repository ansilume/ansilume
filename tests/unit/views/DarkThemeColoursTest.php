<?php

declare(strict_types=1);

namespace app\tests\unit\views;

use app\services\runstate\Display;
use PHPUnit\Framework\TestCase;

/**
 * Guard: no badge or chart colour that the dark theme hides.
 *
 * The layout always renders data-bs-theme="dark" (views/layouts/main.php).
 * Its page background is the colour of text-bg-dark, so a "dark" badge keeps
 * only its text, and muted text on a light badge is light grey on near-white.
 * Each of these happened: the canceled approval request badge, the
 * unreachable task and host recap badges, the dashboard's unreachable bars
 * (#343a40) and the webhook list's "No" badge.
 *
 * - Every statusCssClass() of a model, for every STATUS_* constant of its
 *   class, and Display::css() for every state, is a colour the theme shows:
 *   neither "dark" nor "light" (its counterpart on a light theme).
 * - No view, no PHP class that builds HTML and no script under web/js uses
 *   text-bg-dark, a badge with bg-dark, a light badge with muted or light
 *   text, or one of the theme's own greys as a chart colour.
 */
final class DarkThemeColoursTest extends TestCase
{
    /** Status badge colours (text-bg-<css>) a theme hides. */
    private const HIDDEN_CSS = ['dark', 'light'];

    /** The models that had a status badge when this guard was written. */
    private const KNOWN_STATUS_CLASSES = [
        'app\models\ApprovalRequest',
        'app\models\Job',
        'app\models\JobTask',
        'app\models\WorkflowJob',
        'app\models\WorkflowJobStep',
    ];

    /** Folders whose files may write badge classes or chart colours. */
    private const SCANNED = ['views', 'services', 'components', 'helpers', 'controllers', 'web/js'];

    /** Classes and colours the dark theme hides, per line. */
    private const HIDDEN_PATTERNS = [
        'text-bg-dark' => '/\btext-bg-dark\b/',
        'a badge with bg-dark' => '/\bbadge\b[^"\'>]*\bbg-dark\b|\bbg-dark\b[^"\'>]*\bbadge\b/',
        'a light badge with muted or light text' =>
            '/\btext-bg-light\b[^"\'>]*\btext-(?:muted|body-secondary|secondary|light|white)\b'
            . '|\btext-(?:muted|body-secondary|secondary|light|white)\b[^"\'>]*\btext-bg-light\b/',
        'a chart colour in the theme\'s own greys' => '/[\'"]#(?:343a40|212529|2b3035|1a1d20)[\'"]/i',
    ];

    public function testTheLayoutIsDarkThemed(): void
    {
        $layout = (string)file_get_contents(self::root() . '/views/layouts/main.php');

        $this->assertStringContainsString(
            'data-bs-theme="dark"',
            $layout,
            'This guard assumes the dark theme. If the theme becomes selectable, check every badge on both.'
        );
    }

    /**
     * regression: the unreachable badges and the canceled approval request
     * badge used "dark", which the dark theme draws on its own colour.
     */
    public function testNoStatusBadgeUsesAColourTheThemeHides(): void
    {
        $classes = self::statusClasses();
        $this->assertSame([], array_diff(self::KNOWN_STATUS_CLASSES, $classes), 'statusCssClass() not found');

        $hidden = [];
        foreach ($classes as $class) {
            foreach (self::statusesOf($class) as $status) {
                $css = $class::statusCssClass($status);
                if (in_array($css, self::HIDDEN_CSS, true)) {
                    $hidden[] = "{$class}::statusCssClass('{$status}') is '{$css}'";
                }
            }
        }
        foreach (self::constantsOf(Display::class, 'STATE_') as $state) {
            if (in_array(Display::css($state), self::HIDDEN_CSS, true)) {
                $hidden[] = "Display::css('{$state}') is '" . Display::css($state) . "'";
            }
        }

        $this->assertSame([], $hidden, "Badge colours the dark theme hides:\n" . implode("\n", $hidden));
    }

    /**
     * regression: the job list's unreachable recap badge (text-bg-dark), the
     * dashboard's unreachable bars (#343a40) and the webhook list's "No"
     * badge (text-bg-light with text-muted) were nearly invisible.
     */
    public function testNoFileWritesAColourTheThemeHides(): void
    {
        $findings = self::findings(self::root());

        $this->assertSame([], $findings, "Colours the dark theme hides:\n" . implode("\n", $findings));
    }

    /**
     * @dataProvider hiddenLineData
     */
    public function testThePatternsFindEveryHiddenColour(string $line): void
    {
        $this->assertNotSame([], self::hiddenIn($line), $line);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hiddenLineData(): array
    {
        return [
            'text-bg-dark' => ['<span class="badge text-bg-dark">2 unreach</span>'],
            'badge bg-dark' => ['<span class="badge bg-dark">x</span>'],
            'bg-dark badge' => ["<span class='bg-dark rounded badge'>x</span>"],
            'light with muted' => ['<span class="badge text-bg-light text-muted">No</span>'],
            'muted with light' => ['<span class="text-body-secondary badge text-bg-light">No</span>'],
            'chart grey' => ["{ label: 'unreachable', backgroundColor: '#343a40' }"],
            'chart grey, upper case' => ['color: "#212529"'],
        ];
    }

    /**
     * @dataProvider shownLineData
     */
    public function testThePatternsLeaveVisibleColoursAlone(string $line): void
    {
        $this->assertSame([], self::hiddenIn($line), $line);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function shownLineData(): array
    {
        return [
            'a dark code block' => ['<pre class="bg-dark text-light p-2">'],
            'a light badge with dark text' => ['<span class="badge bg-light text-dark border">'],
            'a light badge' => ['<span class="badge text-bg-light border ms-1">'],
            'the danger badge' => ['<span class="badge text-bg-danger">2 unreach</span>'],
            'a chart colour' => ["backgroundColor: '#ea868f'"],
            'the layout' => ['<html lang="en" data-bs-theme="dark">'],
        ];
    }

    /**
     * Every line under $root's scanned folders that writes a colour the dark
     * theme hides, as "path:line: what".
     *
     * @return list<string>
     */
    public static function findings(string $root): array
    {
        $findings = [];
        foreach (self::scannedFiles($root) as $path) {
            $lines = (array)file($path, FILE_IGNORE_NEW_LINES);
            foreach ($lines as $number => $line) {
                foreach (self::hiddenIn((string)$line) as $what) {
                    $findings[] = substr($path, strlen($root) + 1) . ':' . ($number + 1) . ': ' . $what;
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private static function hiddenIn(string $line): array
    {
        $hidden = [];
        foreach (self::HIDDEN_PATTERNS as $what => $pattern) {
            if (preg_match($pattern, $line) === 1) {
                $hidden[] = $what;
            }
        }

        return $hidden;
    }

    /**
     * @return list<string>
     */
    private static function scannedFiles(string $root): array
    {
        $files = [];
        foreach (self::SCANNED as $folder) {
            if (!is_dir($root . '/' . $folder)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $folder, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = $file instanceof \SplFileInfo ? $file->getPathname() : '';
                if (self::isScanned($path)) {
                    $files[] = $path;
                }
            }
        }
        sort($files);

        return $files;
    }

    private static function isScanned(string $path): bool
    {
        if (str_contains($path, '/vendor/') || str_ends_with($path, '.min.js')) {
            return false;
        }

        return str_ends_with($path, '.php') || str_ends_with($path, '.js');
    }

    /**
     * The model classes that declare statusCssClass().
     *
     * @return list<string>
     */
    private static function statusClasses(): array
    {
        $classes = [];
        foreach ((array)glob(self::root() . '/models/*.php') as $path) {
            $source = (string)file_get_contents((string)$path);
            if (preg_match('/public static function statusCssClass\s*\(/', $source) === 1) {
                $classes[] = 'app\models\\' . basename((string)$path, '.php');
            }
        }
        sort($classes);

        return $classes;
    }

    /**
     * @param string $class
     * @return list<string>
     */
    private static function statusesOf(string $class): array
    {
        return self::constantsOf($class, 'STATUS_');
    }

    /**
     * The string values of $class's constants whose names start with $prefix.
     *
     * @return list<string>
     */
    private static function constantsOf(string $class, string $prefix): array
    {
        $values = [];
        foreach ((new \ReflectionClass($class))->getConstants() as $name => $value) {
            if (str_starts_with($name, $prefix) && is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
