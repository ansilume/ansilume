<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveyDefinition;
use app\controllers\ApprovalRuleController;
use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApproverEligibility;
use app\services\JobTemplateGate;
use app\tests\integration\TeamScopeFixtures;

/**
 * The IDs of an approval rule's approver config (the team of a team rule,
 * the users of a users rule), a JSON object that the rule form takes as
 * text and the API as an object, so a value can be a JSON number beyond the
 * int range. Such a value names nobody: the rule's approvers leave it out,
 * a launch the rule gates is refused as one that could never be approved,
 * and the rule's page and its form show the rule. Every form of an ID
 * ("5", "05", 5.0) names that team or user, as before.
 *
 * Regression: on PHP 8.5 the (int) and intval() casts of these values
 * warned ("The float 1.0E+30 is not representable as an int, cast
 * occurred") in ApprovalRule::resolveUserIds() and resolveTeamIds(), in the
 * rule's page (views/approval-rule/view.php) and in its form
 * (views/approval-rule/form.php); the error handler turned the warning into
 * an exception, so the page, the form shown again and every launch the rule
 * gates answered 500. Up to PHP 8.4 the casts made such a number an
 * arbitrary user or team, and 1e999 user or team 0.
 */
class ApprovalRuleConfigNumbersTest extends WebControllerTestCase
{
    use RendersRealViews;
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('rule-config-admin', 'admin');
        $this->loginAs($this->admin);
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testANumberBeyondTheIntRangeNamesNoApprover(string $number): void
    {
        $approver = $this->createUserWithRole('rule-config-approver', 'operator');
        $team = $this->createTeam((int)$this->admin->id);
        $this->addTeamMember((int)$team->id, (int)$approver->id);

        $users = $this->rule(ApprovalRule::APPROVER_TYPE_USERS, '{"user_ids": [' . $number . ', ' . (int)$approver->id . ']}');
        $teamRule = $this->rule(ApprovalRule::APPROVER_TYPE_TEAM, '{"team_id": ' . $number . '}');

        $this->assertSame([(int)$approver->id], $users->getApproverUserIds());
        $this->assertSame([], $teamRule->getApproverUserIds());
    }

    /**
     * Every form of an ID names that user or team, as intval() and the
     * (int) cast read it.
     */
    public function testEveryFormOfAnIdNamesThatApprover(): void
    {
        $approver = $this->createUserWithRole('rule-config-forms', 'operator');
        $id = (int)$approver->id;
        $team = $this->createTeam((int)$this->admin->id);
        $this->addTeamMember((int)$team->id, $id);

        $users = $this->rule(ApprovalRule::APPROVER_TYPE_USERS, sprintf('{"user_ids": [%1$d, "%1$d", "0%1$d", %1$d.0]}', $id));

        $this->assertSame([$id, $id, $id, $id], $users->getApproverUserIds());
        foreach ([(string)$team->id, '"' . $team->id . '"', $team->id . '.0'] as $teamId) {
            $this->assertSame([$id], $this->rule(ApprovalRule::APPROVER_TYPE_TEAM, '{"team_id": ' . $teamId . '}')->getApproverUserIds(), $teamId);
        }
    }

    /**
     * A launch the rule gates: refused before anything is written, as a
     * launch whose approval could never be granted (no eligible approver).
     *
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testALaunchWhoseOnlyApproverIsBeyondTheIntRangeIsRefused(string $number): void
    {
        $rule = $this->rule(ApprovalRule::APPROVER_TYPE_USERS, '{"user_ids": [' . $number . ']}');
        $ownerId = (int)$this->admin->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );
        JobTemplate::updateAll(['approval_rule_id' => $rule->id], ['id' => $template->id]);
        $gated = JobTemplate::findOne($template->id);
        $this->assertNotNull($gated);
        /** @var ApproverEligibility $eligibility */
        $eligibility = \Yii::$app->get('approverEligibility');

        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_JOB, SurveyDefinition::quote((string)$rule->name), 1, 0),
            JobTemplateGate::of($gated)->refusal($ownerId, $eligibility)
        );
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testTheRulesPageShowsSuchAConfig(string $number): void
    {
        $teamRule = $this->rule(ApprovalRule::APPROVER_TYPE_TEAM, '{"team_id": ' . $number . '}');
        $users = $this->rule(ApprovalRule::APPROVER_TYPE_USERS, '{"user_ids": [' . $number . ']}');

        $this->assertNamesNoTeam($this->page($teamRule));
        $this->assertStringContainsString('Users: <em class="text-muted">none configured</em>', $this->page($users));
    }

    /**
     * Team IDs that are none: missing, null, or a value that is no ID.
     *
     * @return array<string, array{0: string}>
     */
    public static function noTeamIdProvider(): array
    {
        return [
            'no team_id' => ['{}'],
            'null' => ['{"team_id": null}'],
            'a fraction' => ['{"team_id": 5.5}'],
            'text' => ['{"team_id": "abc"}'],
            'true' => ['{"team_id": true}'],
            'a list' => ['{"team_id": [5]}'],
        ];
    }

    /**
     * Regression: the page of a team rule whose team ID is none
     * (ApproverConfigIds::teamOf() gives null) printed "Team: #", the ID
     * sign of a team that no longer exists without an ID after it. It says
     * that none is configured now, as the page of a users rule without
     * users does.
     *
     * @dataProvider noTeamIdProvider
     */
    public function testRegressionTheRulesPageOfATeamRuleWithoutATeamIdSaysNoneIsConfigured(string $config): void
    {
        $this->assertNamesNoTeam($this->page($this->rule(ApprovalRule::APPROVER_TYPE_TEAM, $config)));
    }

    /**
     * The rule's page names the team and the users of a valid config, as
     * before, and the ID of a team that is gone.
     */
    public function testTheRulesPageNamesTheApprovers(): void
    {
        $approver = $this->createUserWithRole('rule-config-named', 'operator');
        $team = $this->createTeam((int)$this->admin->id);

        $this->assertStringContainsString(
            'Team: <strong>' . $team->name . '</strong>',
            $this->page($this->rule(ApprovalRule::APPROVER_TYPE_TEAM, '{"team_id": "' . $team->id . '"}'))
        );
        $this->assertStringContainsString(
            'Team: <strong>#999999999</strong>',
            $this->page($this->rule(ApprovalRule::APPROVER_TYPE_TEAM, '{"team_id": 999999999}'))
        );
        $this->assertStringContainsString(
            'Users: <strong>' . $approver->username . '</strong>',
            $this->page($this->rule(ApprovalRule::APPROVER_TYPE_USERS, '{"user_ids": [' . $approver->id . ']}'))
        );
    }

    /**
     * The rule form posted as JSON with such a config and an error in
     * another field: the form is shown again, with that error, and nothing
     * is created; no team and no user is selected for the number.
     *
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testTheFormIsShownAgainForSuchAConfig(string $number): void
    {
        foreach (['team' => '{"team_id": ' . $number . '}', 'users' => '{"user_ids": [' . $number . ']}'] as $type => $config) {
            $this->sendJsonText((string)json_encode(['ApprovalRule' => [
                'name' => '',
                'approver_type' => $type,
                'approver_config' => $config,
                'required_approvals' => 1,
            ]]));

            $ctrl = $this->controller();
            $html = $this->withRealViews($ctrl, '/approval-rule/create', static fn (): mixed => $ctrl->actionCreate());

            $this->assertIsString($html);
            $this->assertStringContainsString('Name cannot be blank.', $html, $type);
            $this->assertDoesNotMatchRegularExpression('/<option value="\d+" selected/', $html, 'nothing is selected: ' . $type);
        }
        $this->assertFalse(ApprovalRule::find()->where(['name' => ''])->exists());
    }

    /**
     * The form preselects the team and the users of a valid config, as
     * before.
     */
    public function testTheFormPreselectsTheApprovers(): void
    {
        $approver = $this->createUserWithRole('rule-config-selected', 'operator');
        $team = $this->createTeam((int)$this->admin->id);
        $ctrl = $this->controller();

        $teamForm = $this->withRealViews($ctrl, '/approval-rule/update', fn (): mixed => $ctrl->actionUpdate(
            (int)$this->rule(ApprovalRule::APPROVER_TYPE_TEAM, '{"team_id": "' . $team->id . '"}')->id
        ));
        $usersForm = $this->withRealViews($ctrl, '/approval-rule/update', fn (): mixed => $ctrl->actionUpdate(
            (int)$this->rule(ApprovalRule::APPROVER_TYPE_USERS, '{"user_ids": ["' . $approver->id . '"]}')->id
        ));

        $this->assertIsString($teamForm);
        $this->assertIsString($usersForm);
        $this->assertMatchesRegularExpression('/<option value="' . $team->id . '" selected/', $teamForm);
        $this->assertMatchesRegularExpression('/<option value="' . $approver->id . '"[^>]* selected/', $usersForm);
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * A stored rule with the approver config $config, as the rule form or
     * the API stores it.
     */
    private function rule(string $type, string $config): ApprovalRule
    {
        $rule = $this->createApprovalRule((int)$this->admin->id, $type, $config);
        $stored = ApprovalRule::findOne($rule->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    /**
     * The rule's page $html says that no team is configured, and names no
     * team, not even as "#".
     */
    private function assertNamesNoTeam(string $html): void
    {
        $this->assertStringContainsString('Team: <em class="text-muted">none configured</em>', $html);
        $this->assertStringNotContainsString('Team: <strong>', $html);
    }

    /**
     * The rule's page, rendered with the real views.
     */
    private function page(ApprovalRule $rule): string
    {
        $ctrl = $this->controller();
        $html = $this->withRealViews($ctrl, '/approval-rule/view', static fn (): mixed => $ctrl->actionView((int)$rule->id));
        $this->assertIsString($html);

        return $html;
    }

    private function controller(): ApprovalRuleController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new class ('approval-rule', $app) extends ApprovalRuleController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
    }
}
