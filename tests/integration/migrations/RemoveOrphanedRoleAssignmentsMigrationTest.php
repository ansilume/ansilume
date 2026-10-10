<?php

declare(strict_types=1);

namespace app\tests\integration\migrations;

use app\models\User;
use app\tests\integration\DbTestCase;
use yii\db\Query;
use yii\rbac\DbManager;

/**
 * m000079_000000_remove_orphaned_role_assignments: role assignments that
 * belong to no user are removed; those of existing users stay, matched to
 * their user the way the RBAC manager looks up a user's roles. The deletes
 * run inside the test's transaction and are rolled back with it.
 */
class RemoveOrphanedRoleAssignmentsMigrationTest extends DbTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 3) . '/migrations/m000079_000000_remove_orphaned_role_assignments.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Start without orphans, so every count below is this test's own.
        $this->up();
    }

    public function testUpRemovesTheAssignmentsOfDeletedUsersAndKeepsThoseOfExistingUsers(): void
    {
        $live = $this->createUser('orphan_live');
        $this->assign('operator', (string)$live->id);
        $inactive = $this->createUser('orphan_inactive');
        $inactive->status = User::STATUS_INACTIVE;
        $inactive->save(false);
        $this->assign('viewer', (string)$inactive->id);
        $deleted = $this->createUser('orphan_deleted');
        $this->assign('admin', (string)$deleted->id);
        $this->assign('viewer', (string)$deleted->id);
        // As the web UI deleted users before: the user goes, the roles stay.
        $this->assertSame(1, $deleted->delete());

        $output = $this->up();

        $this->assertSame("    > Removed 2 role assignment(s) that belong to no user.\n", $output);
        $this->assertSame([], $this->itemsOf((string)$deleted->id));
        $this->assertSame(['operator'], $this->itemsOf((string)$live->id));
        $this->assertSame(['viewer'], $this->itemsOf((string)$inactive->id), 'a deactivated user keeps their role');
    }

    /**
     * A row belongs to a user when the RBAC manager finds it for them: its
     * user_id equals the ID under the column's collation, which ignores
     * trailing spaces. Rows that no user's lookup finds go, even those that
     * read as the ID when taken as a number.
     */
    public function testRowsAreMatchedToUsersAsTheRbacManagerLooksThemUp(): void
    {
        $id = (string)$this->createUser('orphan_match')->id;
        $stored = [
            'canonical' => $id,
            'trailing space' => $id . ' ',
            'leading zero' => '0' . $id,
            'leading space' => ' ' . $id,
            'trailing tab' => $id . "\t",
            'decimal' => $id . '.0',
        ];
        // One role per row: the primary key (item_name, user_id) compares
        // under the same collation, so "42" and "42 " cannot share a role.
        $roles = [];
        foreach ($stored as $label => $userId) {
            $roles[$label] = $this->createRole();
            $this->assign($roles[$label], $userId);
        }
        $foundForTheUser = array_keys(array_intersect($roles, array_keys($this->rbac()->getAssignments($id))));

        $output = $this->up();

        $kept = array_keys(array_filter(
            $stored,
            fn (string $userId, string $label): bool => in_array($roles[$label], $this->itemsOf($userId), true),
            ARRAY_FILTER_USE_BOTH
        ));
        $this->assertSame(['canonical', 'trailing space'], $kept);
        $this->assertSame($foundForTheUser, $kept, 'the rows that stay are those the RBAC manager finds for the user');
        $this->assertSame("    > Removed 4 role assignment(s) that belong to no user.\n", $output);
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $live = $this->createUser('orphan_again');
        $this->assign('operator', (string)$live->id);
        $this->assign($this->createRole(), $live->id . ' ');
        $deleted = $this->createUser('orphan_again_deleted');
        $this->assign('viewer', (string)$deleted->id);
        $deleted->delete();
        $this->up();
        $once = $this->allAssignments();

        $output = $this->up();

        $this->assertSame("    > Removed 0 role assignment(s) that belong to no user.\n", $output);
        $this->assertSame($once, $this->allAssignments());
        $this->assertSame(['operator'], $this->itemsOf((string)$live->id));
        $this->assertCount(1, $this->itemsOf($live->id . ' '), 'the row with a trailing space is the user\'s too');
        $this->assertSame([], $this->itemsOf((string)$deleted->id));
    }

    /**
     * Down succeeds without touching data: the removed rows gave roles to
     * users that do not exist, so there is nothing to put back.
     */
    public function testDownSucceedsAndRestoresNothing(): void
    {
        $deleted = $this->createUser('orphan_down');
        $this->assign('viewer', (string)$deleted->id);
        $deleted->delete();
        $this->up();
        $after = $this->allAssignments();

        $this->assertTrue($this->migration()->safeDown());

        $this->assertSame([], $this->itemsOf((string)$deleted->id));
        $this->assertSame($after, $this->allAssignments());
    }

    // -- Helpers --------------------------------------------------------------

    private function migration(): \m000079_000000_remove_orphaned_role_assignments
    {
        return new \m000079_000000_remove_orphaned_role_assignments(['db' => \Yii::$app->db, 'compact' => true]);
    }

    /**
     * Runs safeUp() and returns what it printed.
     */
    private function up(): string
    {
        ob_start();
        try {
            $this->migration()->safeUp();
        } finally {
            $output = (string)ob_get_clean();
        }

        return $output;
    }

    /**
     * Stores an assignment row exactly as given, as older versions or a
     * manual insert could have left it.
     */
    private function assign(string $item, string $userId): void
    {
        \Yii::$app->db->createCommand()->insert('{{%auth_assignment}}', [
            'item_name' => $item,
            'user_id' => $userId,
            'created_at' => time(),
        ])->execute();
    }

    private function createRole(): string
    {
        $rbac = $this->rbac();
        $role = $rbac->createRole('orphan-check-' . uniqid('', true));
        $rbac->add($role);

        return $role->name;
    }

    private function rbac(): DbManager
    {
        $rbac = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $rbac);

        return $rbac;
    }

    /**
     * @return list<string> the items assigned to exactly this stored user_id,
     *                      byte for byte ("42 " is not "42")
     */
    private function itemsOf(string $userId): array
    {
        /** @var list<string> $items */
        $items = (new Query())
            ->select('item_name')
            ->from('{{%auth_assignment}}')
            ->where('HEX([[user_id]]) = :hex', [':hex' => strtoupper(bin2hex($userId))])
            ->orderBy(['item_name' => SORT_ASC])
            ->column();

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allAssignments(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query())
            ->from('{{%auth_assignment}}')
            ->orderBy(['item_name' => SORT_ASC, 'user_id' => SORT_ASC])
            ->all();

        return $rows;
    }
}
