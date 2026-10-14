<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\tests\unit\docs\WorkflowStepsUpdateDocsContractTest;

/**
 * Runs a read-only ```sql block of docs/updating.md's section for the
 * update from 2.10.1 on the test database, found by the comment it starts
 * with, for the doc contract tests that check what the query lists. The
 * class reads the docs with ReadsOperatorDocs.
 */
trait RunsTheUpdateSectionQueries
{
    /**
     * The section under $heading of $file (ReadsOperatorDocs).
     */
    abstract private function section(string $file, string $heading): string;

    /**
     * The ```sql block of the section that starts with the comment line
     * $comment; the section must have exactly one.
     */
    private function queryStartingWith(string $comment): string
    {
        preg_match_all('/^```sql\R(.*?)^```/ms', $this->section('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION), $blocks);
        $queries = array_values(array_filter($blocks[1], static fn (string $sql): bool => str_starts_with($sql, $comment . "\n")));
        $this->assertCount(1, $queries, "The section of updating.md has one query that starts with {$comment}.");
        $this->assertSame(
            1,
            preg_match('/^SELECT\b/', trim((string)preg_replace('/^\s*--.*$/m', '', $queries[0]))),
            'The query is a SELECT.'
        );

        return $queries[0];
    }

    /**
     * The rows the query that starts with $comment returns on the test
     * database whose $column is one of $ids, in the query's order.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function rowsWhere(string $comment, string $column, array $ids): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = \Yii::$app->db->createCommand(rtrim(trim($this->queryStartingWith($comment)), ';'))->queryAll();

        return array_values(array_filter($rows, static fn (array $row): bool => in_array((int)$row[$column], $ids, true)));
    }
}
