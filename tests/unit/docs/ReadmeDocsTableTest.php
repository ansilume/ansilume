<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use PHPUnit\Framework\TestCase;

/**
 * Guard: the docs table of README.md (under "## Docs") lists every guide.
 * Every top-level docs/*.md has exactly one row there, and every row links,
 * in its first cell, a file that exists.
 *
 * Regression (found in wave 3 of 2.11.0; fails on the README of 2.10.1): the
 * table had no row for docs/credentials.md, docs/ldap.md and
 * docs/updating.md, so a reader of the README did not find the credentials
 * guide, the LDAP guide or the update notes.
 */
class ReadmeDocsTableTest extends TestCase
{
    use ReadsOperatorDocs;

    private const README = 'README.md';

    private const TABLE_HEADING = '## Docs';

    /** The delimiter row of a markdown table, the line under its header. */
    private const DELIMITER_ROW = '/^\|(?:\s*:?-+:?\s*\|)+$/';

    /** A markdown link; group 1 is its target without a #section. */
    private const LINK = '/\[[^\]]*\]\(([^)\s#]*)(?:#[^)\s]*)?\)/';

    /**
     * regression: docs/credentials.md, docs/ldap.md and docs/updating.md had
     * no row in the docs table.
     */
    public function testRegressionEveryTopLevelDocHasExactlyOneRowInTheDocsTable(): void
    {
        $docs = $this->topLevelDocs();
        $this->assertNotEmpty($docs, 'docs/ has guides.');
        $rowsPerLink = array_count_values(array_column($this->rows(), 'link'));
        $wrong = [];
        foreach ($docs as $doc) {
            $count = $rowsPerLink[$doc] ?? 0;
            if ($count !== 1) {
                $wrong[$doc] = $count;
            }
        }
        $this->assertSame([], $wrong, 'Each doc has exactly one row in the docs table of README.md; these have the number of rows shown.');
    }

    public function testEveryRowOfTheDocsTableLinksAFileThatExists(): void
    {
        foreach ($this->rows() as $row) {
            $this->assertNotSame('', $row['link'], "This row of the docs table of README.md links no file, or more than one: {$row['row']}");
            $this->assertTrue(is_file($this->root() . '/' . $row['link']), "The docs table of README.md links {$row['link']}, which does not exist.");
        }
    }

    /**
     * The top-level guides, as README.md links them: docs/<name>.md.
     *
     * @return list<string>
     */
    private function topLevelDocs(): array
    {
        return array_map(static fn (string $path): string => 'docs/' . basename($path), glob($this->root() . '/docs/*.md') ?: []);
    }

    /**
     * The rows of the docs table below its header and delimiter row, each
     * with the file that its first cell links (without a #section or a
     * leading ./); '' when the cell links no file or more than one.
     *
     * @return list<array{row: string, link: string}>
     */
    private function rows(): array
    {
        $lines = array_values(array_filter(
            preg_split('/\R/', $this->section(self::README, self::TABLE_HEADING)) ?: [],
            static fn (string $line): bool => str_starts_with($line, '|')
        ));
        $this->assertGreaterThan(2, count($lines), 'The docs table of README.md has a header, a delimiter row and rows.');
        $this->assertMatchesRegularExpression(self::DELIMITER_ROW, $lines[1], 'The second line of the docs table is its delimiter row.');
        $rows = [];
        foreach (array_slice($lines, 2) as $line) {
            $count = preg_match_all(self::LINK, explode('|', $line)[1], $links);
            $link = $count === 1 ? (string)preg_replace('#^\./#', '', $links[1][0]) : '';
            $rows[] = ['row' => $line, 'link' => $link];
        }

        return $rows;
    }
}
