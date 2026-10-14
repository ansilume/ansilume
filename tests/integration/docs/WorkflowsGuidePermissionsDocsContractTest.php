<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\controllers\ApprovalController;
use app\controllers\ApprovalRuleController;
use app\controllers\WorkflowJobController;
use app\controllers\WorkflowTemplateController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\User;
use app\services\ApproverEligibility;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\ReadsOperatorDocs;

/**
 * The table "Who may do what" of docs/workflows.md is what the code does:
 * each action needs the permission the table names, in the access rules of
 * the controllers that do it, and the built-in roles viewer, operator and
 * admin hold the permissions the table says they hold, as the migrations
 * seeded them in the test database. Every signed-in user holds the default
 * role viewer (config/web.php), as the guide says.
 */
class WorkflowsGuidePermissionsDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;

    /** The built-in roles, in the columns of the table. */
    private const ROLES = ['viewer', 'operator', 'admin'];

    /**
     * The controller actions of each action of the table.
     *
     * @return array<string, list<array{0: class-string, 1: string}>>
     */
    private static function actions(): array
    {
        return [
            'See workflow templates, their steps and branches' => [[WorkflowTemplateController::class, 'view']],
            'See runs, their states and banners' => [[WorkflowJobController::class, 'view'], [WorkflowJobController::class, 'status']],
            'See approval requests' => [[ApprovalController::class, 'index'], [ApprovalController::class, 'view']],
            'Create a workflow template' => [[WorkflowTemplateController::class, 'create']],
            'Add, edit, move and remove steps' => [
                [WorkflowTemplateController::class, 'add-step'],
                [WorkflowTemplateController::class, 'edit-step'],
                [WorkflowTemplateController::class, 'move-step'],
                [WorkflowTemplateController::class, 'remove-step'],
            ],
            'Launch a workflow and resume a paused run' => [[WorkflowTemplateController::class, 'launch'], [WorkflowJobController::class, 'resume']],
            'Cancel a run' => [[WorkflowJobController::class, 'cancel']],
            'Approve or reject, as an approver of the rule' => [[ApprovalController::class, 'approve'], [ApprovalController::class, 'reject']],
            'Delete a workflow template' => [[WorkflowTemplateController::class, 'delete']],
            'Manage and assign approval rules' => [[ApprovalRuleController::class, 'update']],
        ];
    }

    public function testEveryActionNeedsThePermissionTheTableNames(): void
    {
        $rows = $this->tableRows();
        $this->assertSame(array_keys(self::actions()), array_keys($rows), 'The table lists these actions, in this order.');
        foreach (self::actions() as $action => $controllerActions) {
            foreach ($controllerActions as [$class, $id]) {
                $this->assertSame([$rows[$action]['permission']], $this->rolesOf($class, $id), "{$class} {$id}: {$action}");
            }
        }
    }

    public function testTheBuiltInRolesHoldWhatTheTableSays(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        foreach ($this->tableRows() as $action => $row) {
            foreach (self::ROLES as $index => $role) {
                $holds = array_key_exists($row['permission'], $auth->getPermissionsByRole($role));
                $this->assertSame($row['roles'][$index], $holds ? 'yes' : 'no', "{$role}: {$action} ({$row['permission']})");
            }
        }
        $config = require dirname(__DIR__, 3) . '/config/web.php';
        $this->assertIsArray($config);
        $this->assertSame(['viewer'], $config['components']['authManager']['defaultRoles'] ?? null);
        $this->assertMentions(
            'every signed-in user holds the role `viewer` as well',
            $this->flat('docs/workflows.md', '## Who may do what'),
            'workflows.md'
        );
    }

    /**
     * A role rule counts the users assigned the role directly, as the guide
     * and the rule form's hint say (WorkflowsGuideDocsContractTest): an admin,
     * who holds operator through the role hierarchy and holds
     * approval.decide, is no approver of a rule naming operator, and a user
     * who holds viewer only as the default role of every signed-in user is
     * no approver of a rule naming viewer.
     *
     * Wave 5 (R12-2): the form said "All users with this role can
     * approve.", which these approvers contradict; the guide said nothing
     * of it. The code always counted so: the docs part fails on the guide
     * from before.
     */
    public function testARoleRuleCountsTheUsersAssignedTheRoleDirectly(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(\yii\rbac\DbManager::class, $auth);
        $operator = $this->createUser('role-rule-operator');
        $admin = $this->createUser('role-rule-admin');
        $plain = $this->createUser('role-rule-plain');
        $viewer = $this->createUser('role-rule-viewer');
        foreach ([[$operator, 'operator'], [$admin, 'admin'], [$viewer, 'viewer']] as [$user, $role]) {
            $item = $auth->getRole($role);
            $this->assertNotNull($item);
            $auth->assign($item, (string)$user->id);
        }
        $defaultRoles = $auth->defaultRoles;
        $auth->defaultRoles = ['viewer'];
        try {
            $this->assertTrue($auth->checkAccess((string)$admin->id, 'operator'), 'An admin holds operator through the hierarchy.');
            $this->assertTrue($auth->checkAccess((string)$admin->id, 'approval.decide'));
            $this->assertTrue($auth->checkAccess((string)$plain->id, 'workflow.view'), 'Every signed-in user holds viewer by default.');
            $operatorRule = $this->createApprovalRule((int)$admin->id, ApprovalRule::APPROVER_TYPE_ROLE, '{"role": "operator"}');
            $viewerRule = $this->createApprovalRule((int)$admin->id, ApprovalRule::APPROVER_TYPE_ROLE, '{"role": "viewer"}');
            $this->assertContains((int)$operator->id, $operatorRule->getApproverUserIds());
            $this->assertNotContains((int)$admin->id, $operatorRule->getApproverUserIds(), 'Not through another role.');
            $this->assertContains((int)$viewer->id, $viewerRule->getApproverUserIds());
            $this->assertNotContains((int)$plain->id, $viewerRule->getApproverUserIds(), 'Not as the default role.');
            $this->assertNotContains((int)$admin->id, $viewerRule->getApproverUserIds());
        } finally {
            $auth->defaultRoles = $defaultRoles;
        }
        $this->assertMentions(
            'for a role rule, the users assigned that role directly: not those who hold it through another role, as admins hold operator, nor every signed-in user for the default role `viewer`.',
            $this->flat('docs/workflows.md', '## Deciding on an approval'),
            'workflows.md'
        );
    }

    /**
     * A team rule counts every member of its team, as the guide and the
     * rule form's hint under Team say (ApprovalRule::TEAM_HINT), and of
     * them those who may decide can approve: a member who holds viewer only
     * is on the rule's list but lacks approval.decide, the member who
     * launched the job never decides, and a user outside the team is on no
     * list, operator or not (ApproverEligibility).
     *
     * Wave 5c: the form said "All members of this team can approve.", which
     * the viewer and the launcher among the members contradict; the guide
     * did not say whom a team rule counts. The docs part fails on the guide
     * from before.
     */
    public function testATeamRuleCountsTheMembersOfItsTeamOfWhomThoseWhoMayDecideCanApprove(): void
    {
        $owner = $this->createUser('team-rule-owner');
        $operator = $this->userWithRole('team-rule-operator', 'operator');
        $viewer = $this->userWithRole('team-rule-viewer', 'viewer');
        $launcher = $this->userWithRole('team-rule-launcher', 'operator');
        $outsider = $this->userWithRole('team-rule-outsider', 'operator');
        $team = $this->createTeam((int)$owner->id);
        foreach ([$operator, $viewer, $launcher] as $member) {
            $this->addTeamMember((int)$team->id, (int)$member->id);
        }
        $rule = $this->createApprovalRule((int)$owner->id, ApprovalRule::APPROVER_TYPE_TEAM, (string)json_encode(['team_id' => (int)$team->id]));

        $this->assertEqualsCanonicalizing([(int)$operator->id, (int)$viewer->id, (int)$launcher->id], $rule->getApproverUserIds(), 'Every member of the team.');
        $this->assertNotContains((int)$outsider->id, $rule->getApproverUserIds());
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$owner->id)->id,
            (int)$this->createInventory((int)$owner->id)->id,
            (int)$this->createRunnerGroup((int)$owner->id)->id,
            (int)$owner->id
        );
        $request = new ApprovalRequest();
        $request->job_id = (int)$this->createJob((int)$template->id, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL)->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);
        /** @var ApproverEligibility $eligibility */
        $eligibility = \Yii::$app->get('approverEligibility');
        $this->assertSame([(int)$operator->id], $eligibility->eligibleForRequest($request), 'Of the members, those who may decide, never the launcher.');

        $deciding = $this->flat('docs/workflows.md', '## Deciding on an approval');
        $this->assertMentions('A team rule counts every member of the team.', $deciding, 'workflows.md');
        $this->assertMentions('and under Team: `' . ApprovalRule::TEAM_HINT . '`', $deciding, 'workflows.md');
    }

    /**
     * A user whose only role is $role.
     */
    private function userWithRole(string $suffix, string $role): User
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
     * The rows of the table: action => the permission and the yes/no of
     * each role, in the order of ROLES.
     *
     * @return array<string, array{permission: string, roles: list<string>}>
     */
    private function tableRows(): array
    {
        $rows = [];
        foreach ($this->units('docs/workflows.md', '## Who may do what') as $unit) {
            if (preg_match('/^\| ([^|`]+) \| `([a-z.-]+)` \| (yes|no) \| (yes|no) \| (yes|no) \|$/', $unit, $cells) === 1) {
                $rows[$cells[1]] = ['permission' => $cells[2], 'roles' => [$cells[3], $cells[4], $cells[5]]];
            }
        }
        $this->assertCount(10, $rows, 'The table of workflows.md has ten actions.');

        return $rows;
    }

    /**
     * The roles of the access rules of $class that allow the action $id.
     *
     * @param class-string $class
     * @return list<string>
     */
    private function rolesOf(string $class, string $id): array
    {
        $controller = new \ReflectionClass($class);
        $rules = $controller->getMethod('accessRules')->invoke($controller->newInstanceWithoutConstructor());
        $this->assertIsArray($rules);
        $roles = [];
        foreach ($rules as $rule) {
            if (in_array($id, (array)($rule['actions'] ?? []), true) && ($rule['allow'] ?? false) === true) {
                $roles = array_merge($roles, array_map('strval', (array)($rule['roles'] ?? [])));
            }
        }

        return $roles;
    }
}
