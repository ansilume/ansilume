<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApproverEligibility;
use app\services\ProjectAccessChecker;
use app\services\WorkflowAccessChecker;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\rbac\DbManager;
use yii\rbac\Role;

/**
 * Who may decide on an approval: listed, active, a superadmin or holding
 * approval.decide, allowed to see it, and not the launcher. This file covers
 * installations without team-restricted projects, the launch refusals and
 * their messages; ApproverEligibilityTeamScopeTest covers team scoping.
 */
class ApproverEligibilityTest extends DbTestCase
{
    use TeamScopeFixtures;

    private const DECIDE = 'approval.decide';

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ProjectAccessChecker $projects */
        $projects = \Yii::$app->get('projectAccessChecker');
        $this->assertFalse($projects->hasRestrictedProjects(), 'precondition: no team-restricted project');
    }

    public function testTheListedUsersWhoMayDecideAreEligibleInTheRulesOrder(): void
    {
        $launcher = (int)$this->createUserWithRole('launcher', 'operator')->id;
        $operator = (int)$this->createUserWithRole('operator', 'operator')->id;
        $admin = (int)$this->createUserWithRole('admin', 'admin')->id;
        $superadmin = $this->superadmin();
        $viewer = (int)$this->createUserWithRole('viewer', 'viewer')->id;
        $plain = (int)$this->createUser('plain')->id;
        $inactive = $this->inactive($this->createUserWithRole('inactive', 'operator'));
        $deleted = $this->deletedUserId();
        $withoutDecide = $this->userWithRole($this->newRole('no-decide'));
        $listed = [$viewer, $operator, $plain, $admin, $operator, $superadmin, $inactive, $deleted, $withoutDecide, $launcher];
        $request = $this->request($this->usersRule($listed, 2), $launcher);

        $expected = [$operator, $admin, $superadmin];
        $this->assertSame($expected, $this->eligibility()->eligibleForRequest($request));
        $this->assertSame($expected, $this->checkedOneByOne($request));
        foreach (array_merge($listed, [(int)$this->createUserWithRole('unlisted', 'operator')->id]) as $userId) {
            $this->assertSame(
                in_array($userId, $expected, true),
                $this->eligibility()->mayDecide($request, $userId),
                "user #{$userId}"
            );
        }
    }

    public function testARoleRuleCountsTheActiveUsersAssignedThatRole(): void
    {
        $deciders = $this->newRole('deciders', true);
        $first = $this->userWithRole($deciders);
        $second = $this->userWithRole($deciders);
        $this->inactive(User::findOne($this->userWithRole($deciders)));
        $launcher = $this->userWithRole($deciders);
        $this->createUserWithRole('admin-not-in-role', 'admin');
        $rule = $this->createApprovalRule(
            (int)$this->createUser('owner')->id,
            ApprovalRule::APPROVER_TYPE_ROLE,
            (string)json_encode(['role' => $deciders->name])
        );

        $eligible = $this->eligibility()->eligibleForRequest($this->request($rule, $launcher));

        $this->assertEqualsCanonicalizing([$first, $second], $eligible, 'direct assignments only, launcher left out');
    }

    public function testARoleWithoutApprovalDecideLeavesOnlySuperadmins(): void
    {
        $watchers = $this->newRole('watchers');
        $this->userWithRole($watchers);
        $superadmin = $this->superadmin();
        $this->auth()->assign($watchers, (string)$superadmin);
        $rule = $this->createApprovalRule(
            (int)$this->createUser('owner')->id,
            ApprovalRule::APPROVER_TYPE_ROLE,
            (string)json_encode(['role' => $watchers->name])
        );

        $request = $this->request($rule, (int)$this->createUser('launcher')->id);

        $this->assertSame([$superadmin], $this->eligibility()->eligibleForRequest($request));
    }

    public function testATeamRuleCountsTheMembersWhoMayDecide(): void
    {
        $owner = (int)$this->createUser('owner')->id;
        $team = $this->createTeam($owner);
        $operator = (int)$this->createUserWithRole('member-operator', 'operator')->id;
        $viewer = (int)$this->createUserWithRole('member-viewer', 'viewer')->id;
        $superadmin = $this->superadmin();
        $launcher = (int)$this->createUserWithRole('member-launcher', 'admin')->id;
        foreach ([$operator, $viewer, $superadmin, $launcher] as $userId) {
            $this->addTeamMember((int)$team->id, $userId);
        }
        $rule = $this->createApprovalRule(
            $owner,
            ApprovalRule::APPROVER_TYPE_TEAM,
            (string)json_encode(['team_id' => (int)$team->id])
        );

        $eligible = $this->eligibility()->eligibleForRequest($this->request($rule, $launcher));

        $this->assertEqualsCanonicalizing([$operator, $superadmin], $eligible);
    }

    /**
     * A superadmin or admin launcher is no exception: nobody approves their
     * own launch.
     *
     * @dataProvider launcherKinds
     */
    public function testTheLauncherNeverDecidesOnTheirOwnJob(string $kind): void
    {
        $launcher = $kind === 'superadmin' ? $this->superadmin() : (int)$this->createUserWithRole('launcher', $kind)->id;
        $other = (int)$this->createUserWithRole('other', 'operator')->id;
        $request = $this->request($this->usersRule([$launcher, $other]), $launcher);

        $this->assertSame([$other], $this->eligibility()->eligibleForRequest($request));
        $this->assertTrue($this->eligibility()->isLauncher($request, $launcher));
        $this->assertFalse($this->eligibility()->mayDecide($request, $launcher));
        $this->assertFalse($this->eligibility()->isLauncher($request, $other));
        $this->assertTrue($this->eligibility()->mayDecide($request, $other));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function launcherKinds(): array
    {
        return ['operator' => ['operator'], 'admin' => ['admin'], 'superadmin' => ['superadmin']];
    }

    public function testARequestWithoutItsRuleHasNobodyToDecide(): void
    {
        $request = new ApprovalRequest();
        $request->approval_rule_id = 0;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $superadmin = $this->superadmin();

        $this->assertSame([], $this->eligibility()->eligibleForRequest($request));
        $this->assertFalse($this->eligibility()->mayDecide($request, $superadmin));
    }

    public function testARuleWithoutApproversHasNobodyToDecide(): void
    {
        $request = $this->request($this->usersRule([]), (int)$this->createUser('launcher')->id);

        $this->assertSame([], $this->eligibility()->eligibleForRequest($request));
    }

    // ── Launch refusals ──────────────────────────────────────────────────────

    public function testAJobLaunchPassesWhenEnoughApproversRemainBesidesTheLauncher(): void
    {
        $launcher = (int)$this->createUserWithRole('launcher', 'operator')->id;
        $rule = $this->usersRule([$launcher, $this->operator(), $this->operator()], 2);

        $this->assertNull($this->eligibility()->jobLaunchRefusal($rule, $this->template(), $launcher));
    }

    public function testAJobLaunchIsRefusedWhenTooFewApproversRemainBesidesTheLauncher(): void
    {
        $launcher = (int)$this->createUserWithRole('launcher', 'operator')->id;
        $viewer = (int)$this->createUserWithRole('viewer', 'viewer')->id;
        $rule = $this->usersRule([$launcher, $this->operator(), $viewer], 2, 'Prod "gate"');

        $this->assertSame(
            'Approval rule "Prod \"gate\"" needs 2 approval(s) from users other than the one who launches,'
            . ' but only 1 of its approvers may decide on this job. An admin can add approvers to the rule.',
            $this->eligibility()->jobLaunchRefusal($rule, $this->template(), $launcher)
        );
    }

    public function testARuleThatListsOnlyTheLauncherRefusesTheirLaunchEvenForASuperadmin(): void
    {
        $launcher = $this->superadmin();
        $rule = $this->usersRule([$launcher], 1, 'Self only');

        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_JOB, '"Self only"', 1, 0),
            $this->eligibility()->jobLaunchRefusal($rule, $this->template(), $launcher)
        );
    }

    public function testAWorkflowApprovalStepRunsWhenEnoughApproversRemainBesidesTheRunsLauncher(): void
    {
        $launcher = (int)$this->createUserWithRole('launcher', 'operator')->id;
        $run = $this->createWorkflowJob((int)$this->createWorkflowTemplate($launcher)->id, $launcher);

        $this->assertNull($this->eligibility()->workflowStepRefusal($this->usersRule([$launcher, $this->operator()]), $run));
    }

    public function testAWorkflowApprovalStepIsRefusedWhenOnlyTheRunsLauncherCouldApprove(): void
    {
        $launcher = (int)$this->createUserWithRole('launcher', 'admin')->id;
        $run = $this->createWorkflowJob((int)$this->createWorkflowTemplate($launcher)->id, $launcher);
        $rule = $this->usersRule([$launcher, (int)$this->createUser('plain')->id], 1, 'Gate');

        $this->assertSame(
            "Not run: approval rule \"Gate\" needs 1 approval(s) from users other than user #{$launcher},"
            . ' who launched this workflow, but only 0 of its approvers may decide.',
            $this->eligibility()->workflowStepRefusal($rule, $run)
        );
    }

    public function testAWorkflowLaunchPassesWhenEveryApprovalCanBeGranted(): void
    {
        $launcher = (int)$this->createUserWithRole('launcher', 'operator')->id;
        $workflow = $this->createWorkflowTemplate($launcher);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->template()->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_APPROVAL, null, (int)$this->usersRule([$launcher, $this->operator()])->id);
        $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowStep((int)$workflow->id, 3, WorkflowStep::TYPE_JOB, (int)$this->gatedTemplate($this->usersRule([$launcher, $this->operator()]))->id);

        $this->assertNull($this->eligibility()->workflowLaunchRefusal($workflow, $launcher));
    }

    public function testAWorkflowLaunchNamesTheFirstApprovalStepThatCouldNeverBeGranted(): void
    {
        $launcher = (int)$this->createUserWithRole('launcher', 'operator')->id;
        $workflow = $this->createWorkflowTemplate($launcher);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->template()->id);
        $later = $this->createWorkflowStep((int)$workflow->id, 5, WorkflowStep::TYPE_APPROVAL, null, (int)$this->usersRule([$launcher], 1, 'Later')->id);
        $later->name = 'Later gate';
        $later->save(false);
        $first = $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_APPROVAL, null, (int)$this->usersRule([$launcher, $this->viewer()], 1, 'Sign-off')->id);
        $first->name = 'Approve "prod"';
        $first->save(false);

        $this->assertSame(
            'Step "Approve \"prod\"": approval rule "Sign-off" needs 1 approval(s) from users other than the one'
            . ' who launches, but only 0 of its approvers may decide. An admin can add approvers to the rule.',
            $this->eligibility()->workflowLaunchRefusal($workflow, $launcher)
        );
    }

    public function testAJobStepWhoseTemplateCouldNeverBeApprovedRefusesTheWorkflowLaunch(): void
    {
        $launcher = $this->superadmin();
        $workflow = $this->createWorkflowTemplate($launcher);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->gatedTemplate($this->usersRule([$launcher, $this->operator()], 2, 'Two'))->id);

        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, '"step-0"', '"Two"', 2, 1),
            $this->eligibility()->workflowLaunchRefusal($workflow, $launcher)
        );
    }

    /**
     * An approval step left without a rule (older versions allowed it) can
     * never be approved: the launch is refused instead of failing at the step.
     */
    public function testAnApprovalStepWithoutItsRuleRefusesTheWorkflowLaunch(): void
    {
        $launcher = (int)$this->createUserWithRole('launcher', 'operator')->id;
        $workflow = $this->createWorkflowTemplate($launcher);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL);

        $this->assertSame(
            'Step "step-0": its approval rule no longer exists. An admin can choose another rule.',
            $this->eligibility()->workflowLaunchRefusal($workflow, $launcher)
        );
    }

    /**
     * A job step whose template was deleted fails when the run reaches it;
     * the template's former rule is not checked here.
     */
    public function testAJobStepWithADeletedTemplateIsLeftToItsOwnCheck(): void
    {
        $launcher = (int)$this->createUserWithRole('launcher', 'operator')->id;
        $template = $this->gatedTemplate($this->usersRule([$launcher]));
        $template->softDelete();
        $workflow = $this->createWorkflowTemplate($launcher);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, 987654321);

        $this->assertNull($this->eligibility()->workflowLaunchRefusal($workflow, $launcher));
    }

    // ── Web and console ──────────────────────────────────────────────────────

    /**
     * Holding approval.decide follows the authManager's default roles exactly
     * as checkAccess() does: web requests and console commands (scheduled
     * launches, timeouts) agree when both configure the same default roles,
     * which RbacDefaultRolesConfigTest asserts.
     */
    public function testHoldingApprovalDecideFollowsTheConfiguredDefaultRoles(): void
    {
        $auth = $this->auth();
        $viewerRole = $auth->getRole('viewer');
        $decide = $auth->getPermission(self::DECIDE);
        $this->assertNotNull($viewerRole);
        $this->assertNotNull($decide);
        $auth->addChild($viewerRole, $decide);
        $launcher = (int)$this->createUserWithRole('launcher', 'operator')->id;
        $withoutRole = (int)$this->createUser('no-role')->id;
        $request = $this->request($this->usersRule([$withoutRole]), $launcher);
        /** @var WorkflowAccessChecker $access */
        $access = \Yii::$app->get('workflowAccessChecker');

        $saved = $auth->defaultRoles;
        try {
            foreach ([[['viewer'], true], [[], false]] as [$defaultRoles, $holds]) {
                $auth->defaultRoles = $defaultRoles;
                $this->assertSame($holds, $access->isActiveWithPermission($withoutRole, self::DECIDE));
                $this->assertSame($holds ? [$withoutRole] : [], $this->eligibility()->eligibleForRequest($request));
                $this->assertSame($holds, $this->eligibility()->mayDecide($request, $withoutRole));
            }
        } finally {
            $auth->defaultRoles = $saved;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function eligibility(): ApproverEligibility
    {
        /** @var ApproverEligibility $eligibility */
        $eligibility = \Yii::$app->get('approverEligibility');

        return $eligibility;
    }

    private function auth(): DbManager
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(DbManager::class, $auth);

        return $auth;
    }

    /**
     * The rule's distinct approvers who pass the checks one by one: an
     * active user with approval.decide (superadmins hold every permission),
     * who may see the request, and is not the launcher.
     *
     * @return list<int>
     */
    private function checkedOneByOne(ApprovalRequest $request): array
    {
        /** @var WorkflowAccessChecker $access */
        $access = \Yii::$app->get('workflowAccessChecker');
        $rule = $request->approvalRule;
        $this->assertNotNull($rule);
        $launcher = $request->launcherId();

        return array_values(array_filter(
            array_values(array_unique($rule->getApproverUserIds())),
            static fn (int $userId): bool => $userId !== $launcher
                && $access->isActiveWithPermission($userId, self::DECIDE)
                && $access->canViewApprovalRequest($userId, $request)
        ));
    }

    private function template(): JobTemplate
    {
        $owner = (int)$this->createUser('owner')->id;

        return $this->createJobTemplate(
            (int)$this->createProject($owner)->id,
            (int)$this->createInventory($owner)->id,
            (int)$this->createRunnerGroup($owner)->id,
            $owner
        );
    }

    private function gatedTemplate(ApprovalRule $rule): JobTemplate
    {
        $template = $this->template();
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);

        return $template;
    }

    /**
     * @param list<int> $approverIds
     */
    private function usersRule(array $approverIds, int $required = 1, ?string $name = null): ApprovalRule
    {
        $rule = $this->createApprovalRule(
            (int)$this->createUser('rule-owner')->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => $approverIds]),
            $required
        );
        if ($name !== null) {
            $rule->name = $name;
            $rule->save(false);
        }

        return $rule;
    }

    /**
     * A pending request of a job that $launcherId launched, read back from
     * the database.
     */
    private function request(ApprovalRule $rule, int $launcherId): ApprovalRequest
    {
        $job = $this->createJob((int)$this->template()->id, $launcherId, Job::STATUS_PENDING_APPROVAL);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);
        $fresh = ApprovalRequest::findOne($request->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function operator(): int
    {
        return (int)$this->createUserWithRole('operator', 'operator')->id;
    }

    private function viewer(): int
    {
        return (int)$this->createUserWithRole('viewer', 'viewer')->id;
    }

    private function superadmin(): int
    {
        $user = $this->createUser('superadmin');
        $user->is_superadmin = true;
        $user->save(false);

        return (int)$user->id;
    }

    private function inactive(?User $user): int
    {
        $this->assertNotNull($user);
        $user->status = User::STATUS_INACTIVE;
        $user->save(false);

        return (int)$user->id;
    }

    /**
     * A user deleted without revoking their role, so the role assignment
     * outlives the account.
     */
    private function deletedUserId(): int
    {
        $user = $this->createUserWithRole('deleted', 'operator');
        $userId = (int)$user->id;
        $user->delete();
        $this->assertTrue($this->auth()->checkAccess($userId, self::DECIDE), 'precondition: the assignment remains');

        return $userId;
    }

    private function newRole(string $suffix, bool $decides = false): Role
    {
        $auth = $this->auth();
        $role = $auth->createRole('test-' . $suffix . '-' . uniqid());
        $auth->add($role);
        if ($decides) {
            $decide = $auth->getPermission(self::DECIDE);
            $this->assertNotNull($decide);
            $auth->addChild($role, $decide);
        }

        return $role;
    }

    private function userWithRole(Role $role): int
    {
        $userId = (int)$this->createUser('role-member')->id;
        $this->auth()->assign($role, (string)$userId);

        return $userId;
    }
}
