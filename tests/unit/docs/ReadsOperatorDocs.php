<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

/**
 * Reads the operator docs (docs/, README.md, the .env examples, the deploy
 * role's defaults) for the doc contract tests: whole files, sections under a
 * heading, and their paragraphs, list items and table rows as single lines.
 *
 * root() says where the docs are. A test overrides it to read another copy,
 * for example the docs from before a fix, while the code under test stays
 * the repository's.
 */
trait ReadsOperatorDocs
{
    /** Where the docs live; overridable, so the tests can read another copy. */
    protected function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Fails with a short message instead of printing a whole document.
     */
    private function assertMentions(string $needle, string $text, string $where): void
    {
        $this->assertTrue(str_contains($text, $needle), "{$where} does not say: {$needle}");
    }

    /**
     * The sentences of $file, or of its section under $heading.
     *
     * @return list<string>
     */
    private function sentences(string $file, ?string $heading = null): array
    {
        $sentences = [];
        foreach ($this->units($file, $heading) as $unit) {
            array_push($sentences, ...(preg_split('/(?<=\.)\s+/', $unit) ?: []));
        }

        return $sentences;
    }

    /**
     * The paragraphs, list items and table rows of $file (or of its section
     * under $heading), each on one line: whitespace collapsed, comment
     * markers of the .env and YAML files removed.
     *
     * @return list<string>
     */
    private function units(string $file, ?string $heading = null): array
    {
        $text = $heading === null ? $this->read($file) : $this->section($file, $heading);
        $units = [];
        $current = '';
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = (string)preg_replace('/^#\s?(?!#)/', '', $line);
            if (trim($line) === '' || preg_match('/^\s*(?:[-*] |\d+\. |\|)/', $line) === 1) {
                $units[] = $current;
                $current = '';
            }
            $current .= ' ' . $line;
        }
        $units[] = $current;
        $units = array_map(static fn (string $unit): string => trim((string)preg_replace('/\s+/', ' ', $unit)), $units);

        return array_values(array_filter($units, static fn (string $unit): bool => $unit !== ''));
    }

    /**
     * $file (or its section under $heading) on one line, whitespace collapsed.
     */
    private function flat(string $file, ?string $heading = null): string
    {
        return implode(' ', $this->units($file, $heading));
    }

    /**
     * The section of $file that starts with the heading line beginning with
     * $heading, up to the next heading of the same or a higher level. Lines
     * in code blocks are no headings, "# a shell comment" included.
     */
    private function section(string $file, string $heading): string
    {
        $level = strlen((string)strstr($heading, ' ', true));
        $section = [];
        $inCode = false;
        foreach (preg_split('/\R/', $this->read($file)) ?: [] as $line) {
            if ($section === [] && !str_starts_with($line, $heading)) {
                continue;
            }
            if ($section !== [] && !$inCode && self::headingLevel($line) <= $level) {
                break;
            }
            $section[] = $line;
            // A fence line opens or closes a code block.
            $inCode = $inCode !== str_starts_with($line, '```');
        }
        $this->assertNotEmpty($section, "{$file} has no section {$heading}.");

        return implode("\n", $section);
    }

    /**
     * The level of the markdown heading $line, PHP_INT_MAX for other lines.
     */
    private static function headingLevel(string $line): int
    {
        return preg_match('/^(#+) /', $line, $match) === 1 ? strlen($match[1]) : PHP_INT_MAX;
    }

    /**
     * The part of $text from $from up to $to.
     */
    private function between(string $text, string $from, string $to): string
    {
        $start = strpos($text, $from);
        $this->assertNotFalse($start, "No {$from}");
        $end = strpos($text, $to, $start + strlen($from));

        return substr($text, $start, $end === false ? null : $end - $start);
    }

    private function read(string $file): string
    {
        $text = file_get_contents($this->root() . '/' . $file);
        $this->assertIsString($text, "{$file} cannot be read.");

        return $text;
    }
}
