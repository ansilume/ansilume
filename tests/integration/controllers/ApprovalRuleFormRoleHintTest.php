<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ApprovalRuleController;
use app\models\ApprovalRule;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;

/**
 * The hints under Role and Team on the approval rule form say who a rule
 * counts and who of them can approve (ApprovalRule::ROLE_HINT and
 * TEAM_HINT). A role rule counts the users assigned the role directly, not
 * those who hold it through another role or as the default role of every
 * signed-in user (ApprovalRule::resolveRoleIds() reads
 * AuthManager::getUserIdsByRole()); a team rule counts every member of the
 * team (ApprovalRule::resolveTeamIds()). Of them, those who are active,
 * hold approval.decide (or are superadmins) and may see the job can
 * approve, never the launcher (ApproverEligibility;
 * ApproverEligibilityTest::testATeamRuleCountsTheMembersWhoMayDecide).
 * docs/workflows.md quotes both hints (WorkflowsGuideDocsContractTest), and
 * WorkflowsGuidePermissionsDocsContractTest shows rules counting so.
 *
 * Regression (wave 5, R12-2): the role hint said "All users with this role
 * can approve.", which admins (operator through the hierarchy) and the users
 * who hold viewer by default contradict. Regression (wave 5c): the team hint
 * said "All members of this team can approve.", which members without
 * approval.decide, members who may not see the job and the launcher
 * contradict. Each fails on the form from before.
 */
class ApprovalRuleFormRoleHintTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;

    /** What the role hint said up to 2.10.1. */
    private const OLD_ROLE_HINT = 'All users with this role can approve.';

    /** What the team hint said up to 2.10.1. */
    private const OLD_TEAM_HINT = 'All members of this team can approve.';

    /**
     * regression: the create and the edit form showed OLD_ROLE_HINT under Role.
     */
    public function testRegressionTheRoleHintSaysWhoARoleRuleCounts(): void
    {
        $admin = $this->signedInAdmin();
        $rule = $this->createApprovalRule((int)$admin->id, ApprovalRule::APPROVER_TYPE_ROLE, '{"role": "operator"}');

        foreach ($this->formPages((int)$rule->id) as $url => $html) {
            $this->assertStringContainsString('<div class="form-text" id="approver-role-hint">' . Html::encode(ApprovalRule::ROLE_HINT) . '</div>', $html, $url);
            $this->assertStringNotContainsString(self::OLD_ROLE_HINT, $html, $url);
        }
    }

    /**
     * regression: the create and the edit form showed OLD_TEAM_HINT under
     * Team, though only the members who may decide can approve.
     */
    public function testRegressionTheTeamHintSaysWhoATeamRuleCounts(): void
    {
        $admin = $this->signedInAdmin();
        $team = $this->createTeam((int)$admin->id);
        $rule = $this->createApprovalRule((int)$admin->id, ApprovalRule::APPROVER_TYPE_TEAM, (string)json_encode(['team_id' => (int)$team->id]));

        foreach ($this->formPages((int)$rule->id) as $url => $html) {
            $this->assertStringContainsString('<div class="form-text" id="approver-team-hint">' . Html::encode(ApprovalRule::TEAM_HINT) . '</div>', $html, $url);
            $this->assertStringNotContainsString(self::OLD_TEAM_HINT, $html, $url);
        }
        $who = ' Those of them who are active, hold approval.decide (or are superadmins) and may see the job can approve, never the user who launched it.';
        $this->assertStringEndsWith($who, ApprovalRule::ROLE_HINT);
        $this->assertSame('Approvers are the members of this team.' . $who, ApprovalRule::TEAM_HINT, 'Both hints say alike who of the approvers can approve.');
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
     * The form page at $url as an admin sees it: create without $id, update
     * of the rule $id.
     */
    private function formPage(string $url, ?int $id): string
    {
        $ctrl = new ApprovalRuleController('approval-rule', $this->app());
        $ctrl->layout = false;
        $html = $this->withRealViews($ctrl, $url, static fn (): mixed => $id === null ? $ctrl->actionCreate() : $ctrl->actionUpdate($id));
        $this->assertIsString($html);

        return $html;
    }

    private function signedInAdmin(): User
    {
        $user = $this->createUserWithRole('rule-hint-admin', 'admin');
        $this->loginAs($user);

        return $user;
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(\yii\base\Module::class, $app);

        return $app;
    }
}
