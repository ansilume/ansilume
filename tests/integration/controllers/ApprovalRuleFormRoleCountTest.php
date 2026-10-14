<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ApprovalRuleController;
use app\models\ApprovalRule;
use app\models\User;
use app\tests\integration\CountsQueries;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;

/**
 * The approval rule form warns when a rule needs more approvals than it has
 * approvers (#approver-count-warning, which its script fills from
 * roleCounts). For a role rule it counts the rule's candidates as the server
 * reads them, as far as the form can know them: the users assigned the role
 * directly (ApprovalRule::resolveRoleIds()) whose account is active, the
 * only accounts ApproverFilter lets decide. An inactive user, a user who
 * holds the role only through another role, and an assignment of a user that
 * no longer exists do not count; a role without such users counts 0. All
 * roles cost one query.
 *
 * Whether those users hold approval.decide and may see the job (it depends
 * on what the rule gates) the count leaves out, and the launcher never
 * approves (ApprovalRule::LAUNCHER_NOTE).
 */
class ApprovalRuleFormRoleCountTest extends WebControllerTestCase
{
    use CountsQueries;
    use RendersRealViews;
    use TeamScopeFixtures;

    /**
     * regression: the form counted every user assigned the role
     * (AuthManager::getUserIdsByRole()), inactive users and assignments of
     * users that no longer exist included. With the E2E fixtures the admin
     * role counted e2e-dormant-chief, who is inactive and approves nothing,
     * so a rule that needs one approval more than the role's active holders
     * got no warning (W5e-ADMINROLE-E2E, open issue 2). Fails on the form
     * from before, which counted 4 and 1 where 2 and 0 are right.
     */
    public function testRegressionARoleCountsTheActiveUsersAssignedItDirectly(): void
    {
        $admin = $this->signedInAdmin();
        $roles = ['counted' => $this->freshRole('counted'), 'dormant' => $this->freshRole('dormant'), 'empty' => $this->freshRole('empty')];
        $roles['wrapper'] = $this->freshRole('wrapper', $roles['counted']);
        $this->createUserWithRole('rule-count-active-a', $roles['counted']);
        $this->createUserWithRole('rule-count-active-b', $roles['counted']);
        $this->inactiveUserWithRole('rule-count-inactive', $roles['counted']);
        $this->createUserWithRole('rule-count-through-wrapper', $roles['wrapper']);
        $this->assignRole((int)User::find()->max('id') + 1000, $roles['counted']);
        $this->inactiveUserWithRole('rule-count-dormant', $roles['dormant']);
        $rule = $this->createApprovalRule((int)$admin->id, ApprovalRule::APPROVER_TYPE_ROLE, (string)json_encode(['role' => $roles['counted']]));

        foreach ($this->formPages((int)$rule->id) as $url => $html) {
            $counts = $this->roleCountsOf($html);
            $this->assertSame(
                ['counted' => 2, 'dormant' => 0, 'empty' => 0, 'wrapper' => 1],
                array_map(static fn (string $role): ?int => $counts[$role] ?? null, $roles),
                $url
            );
            $counted = array_map('strval', array_keys($counts));
            sort($counted, SORT_STRING);
            $this->assertSame($this->rolesOfTheInstance(), $counted, "{$url}: a count for every role the form offers");
        }
    }

    /**
     * regression: the form asked the RBAC manager for the users of each
     * role, a query per role. All roles cost one query now, so the form
     * costs as much with 3 roles more as with 13. Fails on the form from
     * before, which cost 10 queries more.
     */
    public function testRegressionTheRoleCountsCostOneQueryHoweverManyRoles(): void
    {
        $this->signedInAdmin();
        $this->rolesWithAHolder(3);
        $this->formPage('/approval-rule/create', null);
        $few = $this->queriesOf(fn (): string => $this->formPage('/approval-rule/create', null));

        $this->rolesWithAHolder(10);
        $this->formPage('/approval-rule/create', null);

        $this->assertSame($few, $this->queriesOf(fn (): string => $this->formPage('/approval-rule/create', null)));
    }

    /**
     * The directory of the form's view. A check of these tests against a
     * changed copy of the view overrides it.
     */
    protected function viewPath(): string
    {
        return '@app/views/approval-rule';
    }

    /**
     * The create form and the edit form of the rule $ruleId, as an admin
     * sees them, by URL.
     *
     * @return array<string, string>
     */
    private function formPages(int $ruleId): array
    {
        return [
            '/approval-rule/create' => $this->formPage('/approval-rule/create', null),
            '/approval-rule/update?id=' . $ruleId => $this->formPage('/approval-rule/update?id=' . $ruleId, $ruleId),
        ];
    }

    /**
     * The form page at $url as the signed-in user sees it: create without
     * $id, update of the rule $id.
     */
    private function formPage(string $url, ?int $id): string
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Module::class, $app);
        $ctrl = new ApprovalRuleController('approval-rule', $app);
        $ctrl->layout = false;
        $ctrl->setViewPath($this->viewPath());
        $html = $this->withRealViews($ctrl, $url, static fn (): mixed => $id === null ? $ctrl->actionCreate() : $ctrl->actionUpdate($id));
        $this->assertIsString($html);

        return $html;
    }

    /**
     * The approver counts by role that the page gives its script.
     *
     * @return array<string, int>
     */
    private function roleCountsOf(string $html): array
    {
        $this->assertSame(1, preg_match('/^[ \t]*var roleCounts = (.*);$/m', $html, $match), 'the page has the line var roleCounts = ...;');
        $counts = json_decode($match[1], true);
        $this->assertIsArray($counts, $match[1]);

        /** @var array<string, int> $counts */
        return $counts;
    }

    /**
     * The names of the roles of the instance, which the form offers, sorted.
     *
     * @return list<string>
     */
    private function rolesOfTheInstance(): array
    {
        $names = array_map('strval', array_keys($this->auth()->getRoles()));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * $count new roles, each assigned to a new active user.
     */
    private function rolesWithAHolder(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->createUserWithRole('rule-count-cost', $this->freshRole('cost'));
        }
    }

    /**
     * A new role named test-rule-count-$prefix-<random>, with the role
     * $child as its only child when given.
     */
    private function freshRole(string $prefix, ?string $child = null): string
    {
        $auth = $this->auth();
        $role = $auth->createRole('test-rule-count-' . $prefix . '-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        if ($child !== null) {
            $childRole = $auth->getRole($child);
            $this->assertNotNull($childRole, $child);
            $auth->addChild($role, $childRole);
        }

        return $role->name;
    }

    private function inactiveUserWithRole(string $suffix, string $role): User
    {
        $user = $this->createUserWithRole($suffix, $role);
        $user->status = User::STATUS_INACTIVE;
        $this->assertTrue($user->save(false));

        return $user;
    }

    private function signedInAdmin(): User
    {
        $user = $this->createUserWithRole('rule-count-admin', 'admin');
        $this->loginAs($user);

        return $user;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }
}
