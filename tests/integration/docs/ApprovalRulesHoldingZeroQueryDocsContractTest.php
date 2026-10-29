<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\ApprovalRule;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\Php85UpdateDocsContractTest;
use app\tests\unit\docs\ReadsOperatorDocs;

/**
 * The read-only query that docs/updating.md gives, in the section of the
 * update from 2.13.1, in its bullet on `POST` and `PUT
 * /api/v1/approval-rules`, runs on the schema of this release, in the
 * test's transaction, and lists what the bullet says: the approval rules
 * that hold 0, which up to 2.13.1 an empty list or object stored as
 * required_approvals or timeout_minutes, by ID and name. Not listed: a rule
 * with valid values, and one without a timeout (timeout_minutes NULL). The
 * query is read from the bullet itself, so that what the docs say is what
 * this test runs.
 */
class ApprovalRulesHoldingZeroQueryDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;

    /** The start of the bullet that gives the query. */
    private const BULLET = '- `POST` and `PUT /api/v1/approval-rules`:';

    public function testTheBulletGivesOneReadOnlyQuery(): void
    {
        $this->assertSame(1, preg_match('/^SELECT\b/', $this->query()), 'The query is a SELECT.');
    }

    public function testTheQueryListsTheRulesThatHoldZeroByIdAndNameAndNoOthers(): void
    {
        $userId = (int)$this->createUser('docs-zero-rules')->id;
        $listed = [
            'required_approvals 0' => $this->rule($userId, 'docs-zero required_approvals 0', 0, 60),
            'timeout_minutes 0' => $this->rule($userId, 'docs-zero timeout_minutes 0', 1, 0),
        ];
        $notListed = [
            'valid values' => $this->rule($userId, 'docs-zero valid values', 2, 60),
            'no timeout (timeout_minutes NULL)' => $this->rule($userId, 'docs-zero no timeout', 1, null),
        ];

        $rows = $this->rowsOf(array_merge(array_values($listed), array_values($notListed)));

        $this->assertSame(
            array_map(static fn (ApprovalRule $rule): array => ['id' => (int)$rule->id, 'name' => $rule->name], array_values($listed)),
            $rows,
            'Listed: ' . implode(', ', array_keys($listed)) . '; not listed: ' . implode(', ', array_keys($notListed))
        );
    }

    /**
     * The query of the bullet: its one code span that starts with SELECT.
     */
    private function query(): string
    {
        $bullets = array_values(array_filter(
            $this->units('docs/updating.md', Php85UpdateDocsContractTest::UPDATE_SECTION),
            static fn (string $unit): bool => str_starts_with($unit, self::BULLET)
        ));
        $this->assertCount(1, $bullets, 'The section has one bullet on the approval rules API.');
        preg_match_all('/`(SELECT\b[^`]*)`/', $bullets[0], $queries);
        $this->assertCount(1, $queries[1], 'The bullet gives one query.');

        return $queries[1][0];
    }

    /**
     * The rows the query returns on the test database for $rules, by ID; it
     * answers exactly the columns id and name.
     *
     * @param list<ApprovalRule> $rules
     * @return list<array{id: int, name: string}>
     */
    private function rowsOf(array $rules): array
    {
        $ids = array_map(static fn (ApprovalRule $rule): int => (int)$rule->id, $rules);
        $rows = [];
        foreach (\Yii::$app->db->createCommand(rtrim(trim($this->query()), ';'))->queryAll() as $row) {
            $this->assertSame(['id', 'name'], array_keys($row), 'The columns of the query.');
            $this->assertIsNumeric($row['id']);
            $this->assertIsString($row['name']);
            if (in_array((int)$row['id'], $ids, true)) {
                $rows[] = ['id' => (int)$row['id'], 'name' => $row['name']];
            }
        }
        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $rows;
    }

    /**
     * An approval rule as 2.13.1 could store one, without validation.
     */
    private function rule(int $userId, string $name, int $requiredApprovals, ?int $timeoutMinutes): ApprovalRule
    {
        $rule = $this->createApprovalRule($userId, ApprovalRule::APPROVER_TYPE_ROLE, '{"role": "operator"}', $requiredApprovals);
        $rule->updateAttributes(['name' => $name, 'timeout_minutes' => $timeoutMinutes]);

        return $rule;
    }
}
