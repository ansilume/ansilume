<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

/**
 * Runs the read-only ```sql blocks of docs/updating.md's section for the
 * update to approvals and workflows on the test database, for the doc
 * contract tests that check what each query lists. The class reads the
 * docs with ReadsOperatorDocs.
 */
trait UpdateSectionQueries
{
    /**
     * The section under $heading of $file (ReadsOperatorDocs).
     */
    abstract private function section(string $file, string $heading): string;

    /**
     * The ```sql blocks of the section, in order.
     *
     * @return list<string>
     */
    private function queries(): array
    {
        preg_match_all(
            '/^```sql\R(.*?)^```/ms',
            $this->section('docs/updating.md', '## After updating from 2.9.0 or older: approvals and workflows'),
            $blocks
        );

        return $blocks[1];
    }

    private static function withoutComments(string $sql): string
    {
        return (string)preg_replace('/^\s*--.*$/m', '', $sql);
    }

    /**
     * The rows query $number of the section (1 for the first) returns on the
     * test database, in its order.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOf(int $number): array
    {
        $sql = $this->queries()[$number - 1] ?? null;
        $this->assertIsString($sql, "updating.md has a query {$number}.");
        /** @var list<array<string, mixed>> $rows */
        $rows = \Yii::$app->db->createCommand(rtrim(trim($sql), ';'))->queryAll();

        return $rows;
    }

    /**
     * The rows of query $number whose $column is one of $ids, by that
     * column, in the query's order.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function rowsByColumn(int $number, string $column, array $ids): array
    {
        $rows = [];
        foreach ($this->rowsOf($number) as $row) {
            $id = (int)$row[$column];
            if (in_array($id, $ids, true)) {
                $rows[$id] = $row;
            }
        }

        return $rows;
    }
}
