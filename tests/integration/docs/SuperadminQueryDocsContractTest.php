<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\User;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\ReadsOperatorDocs;
use app\tests\unit\docs\WorkflowStepsUpdateDocsContractTest;

/**
 * The read-only query that docs/updating.md gives under **Security: who
 * may give roles and the superadmin flag** runs on the schema of this
 * release, in the test's transaction, and lists what its text says: every
 * user who holds the superadmin flag or the role admin assigned to them,
 * disabled ones too, with their status, by ID; not a user who holds admin's
 * permissions only through a custom role, which the item tells to check on
 * the Roles page.
 */
class SuperadminQueryDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;

    /** The comment the query starts with. */
    private const COMMENT = '-- Superadmins and admins, to check after the update';

    /** The columns the query returns, in its order. */
    private const COLUMNS = ['id', 'username', 'is_superadmin', 'status'];

    public function testTheQueryIsReadOnlyAndAnswersTheDocumentedColumns(): void
    {
        $sql = $this->query();
        $this->assertSame(1, preg_match('/^SELECT\b/', trim((string)preg_replace('/^\s*--.*$/m', '', $sql))), 'The query is a SELECT.');

        $this->userWith('admin', 'docs-columns-admin');
        $rows = $this->rows();
        $this->assertNotEmpty($rows);
        $this->assertSame(self::COLUMNS, array_keys($rows[0]));
    }

    public function testTheQueryListsTheSuperadminsAndTheAdminsByIdAndNoOneElse(): void
    {
        $superadmin = $this->createUser('docs-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $admin = $this->userWith('admin', 'docs-admin');
        $disabled = $this->userWith('admin', 'docs-admin-disabled');
        $disabled->status = User::STATUS_INACTIVE;
        $disabled->save(false);
        $operator = $this->userWith('operator', 'docs-operator');
        $custom = $this->userWith($this->roleWithAdmin(), 'docs-custom');
        $plain = $this->createUser('docs-plain');

        $listed = [];
        foreach ($this->rows() as $row) {
            $listed[(int)$row['id']] = $row;
        }

        foreach ([$superadmin, $admin, $disabled] as $user) {
            $this->assertArrayHasKey((int)$user->id, $listed, (string)$user->username);
        }
        foreach ([$operator, $custom, $plain] as $user) {
            $this->assertArrayNotHasKey((int)$user->id, $listed, (string)$user->username);
        }
        $this->assertSame(1, (int)$listed[(int)$superadmin->id]['is_superadmin']);
        $this->assertSame(User::STATUS_INACTIVE, (int)$listed[(int)$disabled->id]['status'], 'A disabled admin is listed with its status.');
        $ids = array_keys($listed);
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'By ID.');
        $this->assertMentions(
            'This read-only query lists the users who hold the superadmin flag or the role admin assigned to them; a custom role can grant the same permissions, so check the roles on the Roles page as well:',
            $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION),
            'updating.md'
        );
    }

    /**
     * The query's ```sql block in the section of the update from 2.10.1.
     */
    private function query(): string
    {
        preg_match_all('/^```sql\R(.*?)^```/ms', $this->section('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION), $blocks);
        $queries = array_values(array_filter($blocks[1], static fn (string $sql): bool => str_starts_with($sql, self::COMMENT)));
        $this->assertCount(1, $queries, 'The section has the query of the superadmins and admins.');

        return $queries[0];
    }

    /**
     * The rows the query returns on the test database, in its order.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = \Yii::$app->db->createCommand(rtrim(trim($this->query()), ';'))->queryAll();

        return $rows;
    }

    private function userWith(string $role, string $suffix): User
    {
        $user = $this->createUser($suffix);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $item = $auth->getRole($role);
        $this->assertNotNull($item, $role);
        $auth->assign($item, (string)$user->id);

        return $user;
    }

    /**
     * A custom role whose child is admin: its users hold every permission
     * of admin without being assigned admin.
     */
    private function roleWithAdmin(): string
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('docs-admins-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        $admin = $auth->getRole('admin');
        $this->assertNotNull($admin);
        $auth->addChild($role, $admin);

        return $role->name;
    }
}
