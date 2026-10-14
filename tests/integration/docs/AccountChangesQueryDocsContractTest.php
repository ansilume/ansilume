<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\AuditLog;
use app\models\User;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\ReadsOperatorDocs;
use app\tests\unit\docs\WorkflowStepsUpdateDocsContractTest;

/**
 * The read-only query that docs/updating.md gives under **Security: who
 * may change other users' accounts** runs on the schema of this release, in
 * the test's transaction, and lists what its text says: the changes
 * (user.updated, user.status_changed) that users who are no superadmin made
 * to the accounts of superadmins, and those that users who are neither a
 * superadmin nor assigned the role admin made to the accounts of admins, as
 * the roles and flags stand now, by ID. Not listed: a change by a superadmin,
 * an admin's change of another admin, which 2.11.0 still allows
 * (UserChangePolicy), a change of another user, one's own change, a deleted
 * account and the entries of other actions and objects.
 */
class AccountChangesQueryDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;

    /** The comment the query starts with. */
    private const COMMENT = '-- Changes to the accounts of superadmins and admins by users who may no longer make them';

    /** The columns the query returns, in its order. */
    private const COLUMNS = ['id', 'changed_at', 'action', 'changed_by', 'changed_user'];

    public function testTheQueryIsReadOnlyAndAnswersTheDocumentedColumns(): void
    {
        $sql = $this->query();
        $this->assertSame(1, preg_match('/^SELECT\b/', trim((string)preg_replace('/^\s*--.*$/m', '', $sql))), 'The query is a SELECT.');

        $superadmin = $this->superadmin('docs-changes-columns-superadmin');
        $helpDesk = $this->createUser('docs-changes-columns-help-desk');
        $entry = $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$helpDesk->id, (int)$superadmin->id);
        $rows = $this->rowsOf([$entry]);
        $this->assertCount(1, $rows);
        $this->assertSame(self::COLUMNS, array_keys($rows[0]));
    }

    public function testTheQueryListsTheChangesThatUsersWhoMayNoLongerMakeThemMadeByIdAndNoOthers(): void
    {
        $superadmin = $this->superadmin('docs-changes-superadmin');
        $otherSuperadmin = $this->superadmin('docs-changes-other-superadmin');
        $admin = $this->userWith('admin', 'docs-changes-admin');
        $otherAdmin = $this->userWith('admin', 'docs-changes-other-admin');
        $viewer = $this->userWith('viewer', 'docs-changes-viewer');
        $helpDesk = $this->userWith($this->helpDeskRole(), 'docs-changes-help-desk');
        $deleted = (int)(User::find()->max('id')) + 1000;

        $listed = [
            'a help desk set the password of a superadmin' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$helpDesk->id, (int)$superadmin->id),
            'a help desk deactivated an admin' => $this->entry(AuditLog::ACTION_USER_STATUS_CHANGED, (int)$helpDesk->id, (int)$admin->id),
            'a help desk changed the email of an admin' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$helpDesk->id, (int)$admin->id),
            'an admin who is no superadmin changed a superadmin' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$otherAdmin->id, (int)$superadmin->id),
            'a user who no longer exists changed an admin' => $this->entry(AuditLog::ACTION_USER_UPDATED, $deleted, (int)$admin->id),
        ];
        $notListed = [
            'an admin changed another admin' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$otherAdmin->id, (int)$admin->id),
            'a superadmin changed an admin' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$otherSuperadmin->id, (int)$admin->id),
            'a superadmin changed a superadmin' => $this->entry(AuditLog::ACTION_USER_STATUS_CHANGED, (int)$otherSuperadmin->id, (int)$superadmin->id),
            'a help desk changed a viewer' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$helpDesk->id, (int)$viewer->id),
            'an admin changed their own account' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$admin->id, (int)$admin->id),
            'a help desk deleted a superadmin, which the item tells to check apart' => $this->entry(AuditLog::ACTION_USER_DELETED, (int)$helpDesk->id, (int)$superadmin->id),
            'a system entry without a user' => $this->entry(AuditLog::ACTION_USER_UPDATED, null, (int)$superadmin->id),
            'an entry of another object' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$helpDesk->id, (int)$superadmin->id, 'project'),
        ];

        $rows = $this->rowsOf(array_merge(array_values($listed), array_values($notListed)));
        $this->assertSame(array_values($listed), array_map(static fn (array $row): int => (int)$row['id'], $rows), 'The listed changes, by ID: ' . implode('; ', array_keys($listed)));
        $first = $rows[0];
        $this->assertSame(
            [AuditLog::ACTION_USER_UPDATED, (int)$helpDesk->id, (int)$superadmin->id],
            [$first['action'], (int)$first['changed_by'], (int)$first['changed_user']]
        );
        $this->assertSame(
            \Yii::$app->db->createCommand('SELECT FROM_UNIXTIME(:at)', [':at' => 1760000000])->queryScalar(),
            $first['changed_at'],
            'changed_at is the time of the entry.'
        );
    }

    /**
     * The query's ```sql block in the section of the update from 2.10.1.
     */
    private function query(): string
    {
        preg_match_all('/^```sql\R(.*?)^```/ms', $this->section('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION), $blocks);
        $queries = array_values(array_filter($blocks[1], static fn (string $sql): bool => str_starts_with($sql, self::COMMENT)));
        $this->assertCount(1, $queries, 'The section has the query of the changes to the accounts of superadmins and admins.');

        return $queries[0];
    }

    /**
     * The rows the query returns on the test database for the audit entries
     * $ids, in its order.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function rowsOf(array $ids): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = \Yii::$app->db->createCommand(rtrim(trim($this->query()), ';'))->queryAll();

        return array_values(array_filter($rows, static fn (array $row): bool => in_array((int)$row['id'], $ids, true)));
    }

    /**
     * An audit entry as the Users pages and the users API write one: $userId
     * changed the account $objectId; its ID.
     */
    private function entry(string $action, ?int $userId, int $objectId, string $objectType = 'user'): int
    {
        $db = \Yii::$app->db;
        $db->createCommand()->insert('{{%audit_log}}', [
            'user_id' => $userId,
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'metadata' => null,
            'created_at' => 1760000000,
        ])->execute();

        return (int)$db->getLastInsertID();
    }

    private function superadmin(string $suffix): User
    {
        $user = $this->createUser($suffix);
        $user->is_superadmin = true;
        $user->save(false);

        return $user;
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
     * A custom role of a help desk: user.view and user.update.
     */
    private function helpDeskRole(): string
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('docs-help-desk-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        foreach (['user.view', 'user.update'] as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }

        return $role->name;
    }
}
