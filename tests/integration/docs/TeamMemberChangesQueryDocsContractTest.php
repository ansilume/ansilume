<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\AuditLog;
use app\models\TeamProject;
use app\models\User;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\ReadsOperatorDocs;

/**
 * The second read-only query that docs/updating.md gives under **Security:
 * who may change other users' accounts** runs on the schema of this
 * release, in the test's transaction, and lists what its text says: the
 * changes (user.updated, user.status_changed) that users who are neither a
 * superadmin nor assigned the role admin made to the accounts of team
 * members whose teams give them a project that the changer's teams do not
 * give them, or only with a lower role, as teams and roles stand now, by
 * ID. The highest role of a user's teams counts, operator over viewer, as
 * UserChangePolicy and ProjectAccessChecker count it. Not listed: changes
 * by an admin or a superadmin, by a changer whose teams give the same
 * access or more, of a user in no team, one's own change, and the entries
 * of other actions and objects.
 */
class TeamMemberChangesQueryDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;
    use RunsTheUpdateSectionQueries;

    /** The comment the query starts with. */
    private const COMMENT = '-- Changes to the accounts of team members by users without the same access to the team\'s projects';

    /** The columns the query returns, in its order. */
    private const COLUMNS = ['id', 'changed_at', 'action', 'changed_by', 'changed_user'];

    public function testTheQueryListsTheChangesOfTeamMembersByUsersWithoutTheirAccessByIdAndNoOthers(): void
    {
        $owner = $this->createUser('docs-team-owner');
        $project = (int)$this->createProject((int)$owner->id)->id;
        $viewerMember = $this->member('docs-team-viewer', [$project => TeamProject::ROLE_VIEWER]);
        $operatorMember = $this->member('docs-team-operator', [$project => TeamProject::ROLE_OPERATOR]);
        $twoTeamsMember = $this->member('docs-team-both', [$project => TeamProject::ROLE_VIEWER], [$project => TeamProject::ROLE_OPERATOR]);
        $helpDesk = $this->createUser('docs-team-help-desk');
        $viewerManager = $this->member('docs-team-viewer-manager', [$project => TeamProject::ROLE_VIEWER]);
        $twoTeamsManager = $this->member('docs-team-both-manager', [$project => TeamProject::ROLE_VIEWER], [$project => TeamProject::ROLE_OPERATOR]);
        $admin = $this->createUser('docs-team-admin');
        $this->assign($admin, 'admin');
        $superadmin = $this->createUser('docs-team-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $noTeam = $this->createUser('docs-team-none');
        $gone = (int)(User::find()->max('id')) + 1000;

        $listed = [
            'a help desk in no team changed a viewer of the project' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$helpDesk->id, (int)$viewerMember->id),
            'a help desk in no team deactivated a viewer of the project' => $this->entry(AuditLog::ACTION_USER_STATUS_CHANGED, (int)$helpDesk->id, (int)$viewerMember->id),
            'a viewer of the project changed an operator of it' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$viewerManager->id, (int)$operatorMember->id),
            'a viewer of the project changed a user whose second team operates it' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$viewerManager->id, (int)$twoTeamsMember->id),
            'a user who no longer exists changed a team member' => $this->entry(AuditLog::ACTION_USER_UPDATED, $gone, (int)$viewerMember->id),
        ];
        $notListed = [
            'a viewer of the project changed another viewer of it' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$viewerManager->id, (int)$viewerMember->id),
            'a user whose second team operates the project changed an operator of it' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$twoTeamsManager->id, (int)$operatorMember->id),
            'an admin changed a team member' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$admin->id, (int)$operatorMember->id),
            'a superadmin changed a team member' => $this->entry(AuditLog::ACTION_USER_STATUS_CHANGED, (int)$superadmin->id, (int)$operatorMember->id),
            'a help desk changed a user in no team' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$helpDesk->id, (int)$noTeam->id),
            'a team member changed their own account' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$viewerMember->id, (int)$viewerMember->id),
            'a help desk created a team member' => $this->entry(AuditLog::ACTION_USER_CREATED, (int)$helpDesk->id, (int)$viewerMember->id),
            'an entry of another object' => $this->entry(AuditLog::ACTION_USER_UPDATED, (int)$helpDesk->id, (int)$viewerMember->id, 'project'),
            'a system entry without a user' => $this->entry(AuditLog::ACTION_USER_UPDATED, null, (int)$viewerMember->id),
        ];

        $rows = $this->rowsWhere(self::COMMENT, 'id', array_merge(array_values($listed), array_values($notListed)));
        $this->assertSame(array_values($listed), array_map(static fn (array $row): int => (int)$row['id'], $rows), 'The listed changes, by ID: ' . implode('; ', array_keys($listed)));
        $this->assertSame(self::COLUMNS, array_keys($rows[0]));
        $this->assertSame(
            [AuditLog::ACTION_USER_UPDATED, (int)$helpDesk->id, (int)$viewerMember->id],
            [$rows[0]['action'], (int)$rows[0]['changed_by'], (int)$rows[0]['changed_user']]
        );
    }

    /**
     * A user who is a member of a team of its own for each of $grants
     * (project ID => team role).
     *
     * @param array<int, string> ...$grants
     */
    private function member(string $suffix, array ...$grants): User
    {
        $user = $this->createUser($suffix);
        foreach ($grants as $teamGrants) {
            $team = $this->createTeam((int)$user->id);
            $this->addTeamMember((int)$team->id, (int)$user->id);
            foreach ($teamGrants as $projectId => $role) {
                $this->createTeamProject((int)$team->id, $projectId, $role);
            }
        }

        return $user;
    }

    private function assign(User $user, string $role): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $item = $auth->getRole($role);
        $this->assertNotNull($item, $role);
        $auth->assign($item, (string)$user->id);
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
}
