<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eApprovalSeeder;
use app\commands\E2eTeardownHelper;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalService;
use app\services\ApproverEligibility;
use app\services\JobLaunchService;
use app\services\ProjectAccessChecker;
use app\tests\integration\DbTestCase;
use yii\rbac\DbManager;
use yii\rbac\ManagerInterface;

/**
 * The approval fixtures of the E2E specs (E2eApprovalSeeder). The user who
 * launches a job never approves it, so e2e-approval-rule needs a second
 * approver besides e2e-admin, or every launch by e2e-admin of a template or
 * workflow it gates is refused before anything is written.
 *
 * The fixtures carry the names the specs look for; the test database holds
 * no "e2e-" rows outside a test, and the rollback removes them.
 */
class E2eApprovalSeederTest extends DbTestCase
{
    /** The password tests/e2e/lib/session.ts signs e2e-approval-launcher in with; a test fixture, not a secret. */
    private const LAUNCHER_PASSWORD = 'E2eApprovalLauncherPass1!';

    private int $adminId;

    private int $operatorId;

    /** The job template whose project, inventory and runner group the fixtures use. */
    private JobTemplate $parent;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->namedUser('e2e-admin', 'admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
        $this->operatorId = (int)$this->namedUser(E2eApprovalSeeder::SECOND_APPROVER, 'operator')->id;
        $this->parent = $this->createJobTemplate(
            (int)$this->createProject($this->adminId)->id,
            (int)$this->createInventory($this->adminId)->id,
            (int)$this->createRunnerGroup($this->adminId)->id,
            $this->adminId
        );
        $this->log = [];
    }

    public function testTheRuleHasASecondApproverSoALaunchByE2eAdminIsAccepted(): void
    {
        $ruleId = $this->seeder()->seedRule($this->adminId);

        $rule = $this->rule(E2eApprovalSeeder::RULE);
        $this->assertSame($ruleId, (int)$rule->id);
        $this->assertSame(ApprovalRule::APPROVER_TYPE_USERS, $rule->approver_type);
        $this->assertSame(['user_ids' => [$this->adminId, $this->operatorId]], json_decode((string)$rule->approver_config, true));
        $this->assertSame(1, (int)$rule->required_approvals);
        $this->assertSame(E2eApprovalSeeder::RULE_TIMEOUT_MINUTES, (int)$rule->timeout_minutes);
        $this->assertSame(ApprovalRule::TIMEOUT_ACTION_REJECT, $rule->timeout_action);
        $this->assertSame($this->adminId, (int)$rule->created_by);
        $this->assertSame(
            ["  Restored approval rule e2e-approval-rule (ID {$ruleId}): e2e-admin and e2e-operator approve.\n"],
            $this->log
        );

        $this->seeder()->seed($this->adminId, (int)$this->parent->id, $ruleId);
        $gated = $this->template(E2eApprovalSeeder::GATED_TEMPLATE);
        $this->assertNull($this->eligibility()->jobLaunchRefusal($rule, $gated, $this->adminId), 'e2e-operator decides');
        $this->assertNull($this->eligibility()->jobLaunchRefusal($rule, $gated, $this->operatorId), 'e2e-admin decides');
    }

    /**
     * Regression: a rule an earlier seed created kept e2e-admin as its only
     * approver, because the seed returned early when it found the rule; every
     * launch by e2e-admin was then refused.
     */
    public function testRegressionTheRuleOfAnEarlierSeedGetsTheSecondApprover(): void
    {
        $stale = $this->createApprovalRule($this->adminId);
        $stale->name = E2eApprovalSeeder::RULE;
        $stale->approver_type = ApprovalRule::APPROVER_TYPE_USERS;
        $stale->approver_config = (string)json_encode(['user_ids' => [$this->adminId]]);
        $stale->required_approvals = 2;
        $stale->timeout_minutes = null;
        $stale->timeout_action = ApprovalRule::TIMEOUT_ACTION_APPROVE;
        $stale->save(false);

        $this->assertSame((int)$stale->id, $this->seeder()->seedRule($this->adminId));

        $rule = $this->rule(E2eApprovalSeeder::RULE);
        $this->assertSame(['user_ids' => [$this->adminId, $this->operatorId]], json_decode((string)$rule->approver_config, true));
        $this->assertSame(1, (int)$rule->required_approvals);
        $this->assertSame(E2eApprovalSeeder::RULE_TIMEOUT_MINUTES, (int)$rule->timeout_minutes);
        $this->assertSame(ApprovalRule::TIMEOUT_ACTION_REJECT, $rule->timeout_action);
        $this->assertSame(1, (int)ApprovalRule::find()->where(['name' => E2eApprovalSeeder::RULE])->count());
    }

    public function testWithoutTheSecondApproverOnlyE2eAdminApproves(): void
    {
        $operator = User::findOne($this->operatorId);
        $this->assertNotNull($operator);
        $operator->username = 'not-the-e2e-operator-' . bin2hex(random_bytes(4));
        $operator->save(false);

        $this->seed();

        $this->assertSame(['user_ids' => [$this->adminId]], json_decode((string)$this->rule(E2eApprovalSeeder::RULE)->approver_config, true));
        $this->assertSame(['user_ids' => [$this->adminId]], json_decode((string)$this->rule(E2eApprovalSeeder::HISTORY_RULE)->approver_config, true));
        $this->assertSame([[$this->adminId, true]], $this->votesOf($this->historyRequests()[2]), 'only the launcher voted');
        $dualRule = $this->rule(E2eApprovalSeeder::DUAL_RULE);
        $this->assertSame(['user_ids' => [$this->adminId]], json_decode((string)$dualRule->approver_config, true));
        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_JOB, '"' . E2eApprovalSeeder::DUAL_RULE . '"', 2, 1),
            $this->eligibility()->jobLaunchRefusal($dualRule, $this->template(E2eApprovalSeeder::DUAL_TEMPLATE), $this->launcherId()),
            'one approver cannot give two approvals'
        );
    }

    public function testTheTemplatesAreGatedOnTheParentsOfTheGivenTemplate(): void
    {
        $this->seed();

        $parents = [(int)$this->parent->project_id, (int)$this->parent->inventory_id, (int)$this->parent->runner_group_id];
        $rules = [
            E2eApprovalSeeder::GATED_TEMPLATE => E2eApprovalSeeder::RULE,
            E2eApprovalSeeder::SOLO_TEMPLATE => E2eApprovalSeeder::SOLO_RULE,
            E2eApprovalSeeder::HISTORY_TEMPLATE => E2eApprovalSeeder::HISTORY_RULE,
            E2eApprovalSeeder::DUAL_TEMPLATE => E2eApprovalSeeder::DUAL_RULE,
        ];
        foreach ($rules as $templateName => $ruleName) {
            $template = $this->template($templateName);
            $this->assertSame($parents, [(int)$template->project_id, (int)$template->inventory_id, (int)$template->runner_group_id], $templateName);
            $this->assertSame((int)$this->rule($ruleName)->id, (int)$template->approval_rule_id, $templateName);
            $this->assertSame('site.yml', $template->playbook);
            $this->assertNull($template->trigger_token);
        }
        $this->assertSame(['user_ids' => [$this->adminId]], json_decode((string)$this->rule(E2eApprovalSeeder::SOLO_RULE)->approver_config, true));
        $this->assertNull($this->rule(E2eApprovalSeeder::SOLO_RULE)->timeout_minutes);
        $this->assertSame(
            ['user_ids' => [$this->adminId, $this->operatorId]],
            json_decode((string)$this->rule(E2eApprovalSeeder::HISTORY_RULE)->approver_config, true)
        );
        $this->assertSame(1, (int)$this->rule(E2eApprovalSeeder::HISTORY_RULE)->timeout_minutes);
        $this->assertStringContainsString('  Restored approval fixtures: e2e-gated-template (ID ', implode('', $this->log));
    }

    /**
     * e2e-dual-approval-rule needs both of its approvers, so only a user who
     * is neither may launch its template: e2e-approval-launcher, an operator
     * who signs in with the password of the specs.
     */
    public function testTheDualRuleNeedsBothApproversAndItsLauncherIsNeither(): void
    {
        $this->seed();

        $rule = $this->rule(E2eApprovalSeeder::DUAL_RULE);
        $this->assertSame(ApprovalRule::APPROVER_TYPE_USERS, $rule->approver_type);
        $this->assertSame(['user_ids' => [$this->adminId, $this->operatorId]], json_decode((string)$rule->approver_config, true));
        $this->assertSame(E2eApprovalSeeder::DUAL_APPROVALS, (int)$rule->required_approvals);
        $this->assertNull($rule->timeout_minutes, 'a request waits until both decided');
        $this->assertNull($rule->job_template_id);

        $launcherId = $this->launcherId();
        $launcher = User::findOne($launcherId);
        $this->assertNotNull($launcher);
        $this->assertTrue($launcher->isActive());
        $this->assertFalse((bool)$launcher->is_superadmin);
        $this->assertTrue($launcher->validatePassword(self::LAUNCHER_PASSWORD), 'the password of tests/e2e/lib/session.ts');
        $this->assertSame([E2eApprovalSeeder::LAUNCHER_ROLE], array_keys($this->auth()->getRolesByUser($launcherId)));
        $this->assertTrue($this->auth()->checkAccess($launcherId, 'job.launch'));

        $template = $this->template(E2eApprovalSeeder::DUAL_TEMPLATE);
        $this->assertNull($this->eligibility()->jobLaunchRefusal($rule, $template, $launcherId), 'e2e-admin and e2e-operator decide');
        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_JOB, '"' . E2eApprovalSeeder::DUAL_RULE . '"', 2, 1),
            $this->eligibility()->jobLaunchRefusal($rule, $template, $this->adminId),
            'an approver who launches leaves one approver for two approvals'
        );
        $this->assertContains(
            sprintf(
                "  Restored e2e-dual-gated-template (ID %d): e2e-dual-approval-rule needs 2 approvals, of e2e-admin and e2e-operator;"
                . " e2e-approval-launcher (ID %d) launches it.\n",
                $template->id,
                $launcherId
            ),
            $this->log
        );
    }

    /**
     * What tests/e2e/tests/approvals/two-approvals.spec.ts does in the
     * browser: e2e-approval-launcher launches, e2e-operator approves, and
     * the request still waits for e2e-admin; e2e-operator may not vote again.
     */
    public function testAnApproverWhoVotedSeesTheRequestWaitForTheOtherApproval(): void
    {
        $this->seed();
        $job = $this->launchDual();
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status);
        $request = ApprovalRequest::findOne(['job_id' => $job->id]);
        $this->assertNotNull($request);

        $this->approvals()->recordDecision($request, $this->operatorId, ApprovalDecision::DECISION_APPROVED, null);

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertSame(1, $request->approvalCount());
        $refusal = $this->approvals()->decisionRefusal($request, $this->operatorId);
        $this->assertNotNull($refusal);
        $this->assertSame(ApprovalDecisionRefusedException::REASON_ALREADY_VOTED, $refusal->reason());
        $this->assertSame(ApprovalDecisionRefusedException::ALREADY_VOTED, $refusal->getMessage());
        $this->assertNull($this->approvals()->decisionRefusal($request, $this->adminId), 'e2e-admin may still decide');
        try {
            $this->approvals()->recordDecision($request, $this->operatorId, ApprovalDecision::DECISION_REJECTED, null);
            $this->fail('A second vote of e2e-operator was recorded.');
        } catch (ApprovalDecisionRefusedException $e) {
            $this->assertSame(ApprovalDecisionRefusedException::ALREADY_VOTED, $e->getMessage());
            $this->assertSame(422, $e->httpStatus());
        }

        $this->approvals()->recordDecision($request, $this->adminId, ApprovalDecision::DECISION_REJECTED, null);

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $request->status);
        $this->assertSame(Job::STATUS_REJECTED, Job::findOne($job->id)?->status);
    }

    /**
     * The project of the templates may be restricted to teams: the launcher
     * then belongs to exactly the teams that may operate it, so the launch is
     * allowed, and to no other team (an earlier seed's membership goes). An
     * earlier seed's user keeps its ID and gets the operator role alone.
     */
    public function testTheLauncherJoinsExactlyTheTeamsThatMayOperateTheProject(): void
    {
        $projectId = (int)$this->parent->project_id;
        $operating = $this->createTeam($this->adminId);
        $this->createTeamProject((int)$operating->id, $projectId, TeamProject::ROLE_OPERATOR);
        $this->addTeamMember((int)$operating->id, $this->operatorId);
        $viewing = $this->createTeam($this->adminId);
        $this->createTeamProject((int)$viewing->id, $projectId, TeamProject::ROLE_VIEWER);
        $stale = $this->createTeam($this->adminId);
        $earlier = $this->namedUser(E2eApprovalSeeder::LAUNCHER, 'admin');
        $this->addTeamMember((int)$stale->id, (int)$earlier->id);

        $this->seed();

        $launcherId = $this->launcherId();
        $this->assertSame((int)$earlier->id, $launcherId, 'the user of the earlier seed, restored');
        $this->assertSame([E2eApprovalSeeder::LAUNCHER_ROLE], array_keys($this->auth()->getRolesByUser($launcherId)), 'no other role');
        $teams = TeamMember::find()->select('team_id')->where(['user_id' => $launcherId])->column();
        $this->assertSame([(int)$operating->id], array_map('intval', $teams));
        $this->assertTrue($this->projectAccess()->canOperate($launcherId, $projectId));
        $this->assertNull(
            $this->eligibility()->jobLaunchRefusal(
                $this->rule(E2eApprovalSeeder::DUAL_RULE),
                $this->template(E2eApprovalSeeder::DUAL_TEMPLATE),
                $launcherId
            ),
            'e2e-operator, in the team, and e2e-admin decide'
        );
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $this->launchDual()->status);
    }

    public function testWithoutTheLaunchersRoleTheSeedStopsAndSaysWhy(): void
    {
        $auth = \Yii::$app->authManager;
        \Yii::$app->set('authManager', new class () extends DbManager {
            public function getRole($name)
            {
                return $name === E2eApprovalSeeder::LAUNCHER_ROLE ? null : parent::getRole($name);
            }
        });
        try {
            $this->seed();
            $this->fail('The seed restored a launcher without the role.');
        } catch (\RuntimeException $e) {
            $this->assertSame(sprintf(E2eApprovalSeeder::ROLE_MISSING, 'operator'), $e->getMessage());
        } finally {
            \Yii::$app->set('authManager', $auth);
        }
        $this->assertNull(User::findOne(['username' => E2eApprovalSeeder::LAUNCHER]));
    }

    /**
     * The only approver of the solo rule launches: no other approver may
     * decide, so the launch is refused (job-templates/approval-rule.spec.ts).
     */
    public function testALaunchOfTheSoloTemplateByE2eAdminIsRefused(): void
    {
        $this->seed();

        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_JOB, '"' . E2eApprovalSeeder::SOLO_RULE . '"', 1, 0),
            $this->eligibility()->jobLaunchRefusal(
                $this->rule(E2eApprovalSeeder::SOLO_RULE),
                $this->template(E2eApprovalSeeder::SOLO_TEMPLATE),
                $this->adminId
            )
        );
    }

    public function testAGatedTemplateASpecDeletedIsRestoredInsteadOfAdded(): void
    {
        $this->seed();
        $gated = $this->template(E2eApprovalSeeder::GATED_TEMPLATE);
        $gated->softDelete();

        $this->seed();

        $this->assertSame((int)$gated->id, (int)$this->template(E2eApprovalSeeder::GATED_TEMPLATE)->id);
        $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => E2eApprovalSeeder::GATED_TEMPLATE])->count());
    }

    public function testTheHistoryHasAnExpiredACanceledAndAnApprovedRequest(): void
    {
        $before = time();
        $this->seed();

        $requests = $this->historyRequests();
        $this->assertSame(
            [Job::STATUS_PENDING_APPROVAL, Job::STATUS_CANCELED, Job::STATUS_SUCCEEDED],
            array_map(static fn (ApprovalRequest $request): string => (string)$request->job->status, $requests)
        );
        [$expired, $canceled, $approved] = $requests;
        $historyRule = (int)$this->rule(E2eApprovalSeeder::HISTORY_RULE)->id;

        $this->assertSame(ApprovalRequest::STATUS_PENDING, $expired->status);
        $this->assertSame($historyRule, (int)$expired->approval_rule_id);
        $this->assertLessThan($before, (int)$expired->expires_at, 'expired');
        $this->assertNull($expired->resolved_at);

        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $canceled->status);
        $this->assertSame($historyRule, (int)$canceled->approval_rule_id);
        $this->assertNotNull($canceled->resolved_at);
        $this->assertSame((int)$canceled->resolved_at, (int)$canceled->job->finished_at);

        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $approved->status);
        $this->assertNull($approved->approval_rule_id, 'its rule was deleted');
        $this->assertNotNull($approved->resolved_at);
        $this->assertSame([[$this->adminId, true], [$this->operatorId, false]], $this->votesOf($approved));
        $this->assertSame(1, $approved->approvalCount(), 'the launcher\'s vote counts for nothing');

        foreach ($requests as $request) {
            $this->assertSame($this->adminId, (int)$request->job->launched_by);
            $this->assertLessThan($before, (int)$request->requested_at);
        }
    }

    /**
     * The expired request is what the maintenance run times out: an
     * approval spec may find it pending or timed out.
     */
    public function testTheTimeoutRunRejectsTheJobOfTheExpiredRequest(): void
    {
        $this->seed();
        $expired = $this->historyRequests()[0];

        /** @var ApprovalService $approvals */
        $approvals = \Yii::$app->get('approvalService');
        $this->assertGreaterThanOrEqual(1, $approvals->processTimeouts());

        $expired->refresh();
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $expired->status);
        $this->assertSame(Job::STATUS_REJECTED, $expired->job->status);
    }

    public function testSeedingAgainReplacesTheHistoryAndTheDeletedTemplate(): void
    {
        $this->seed();
        $oldJobs = array_map(static fn (ApprovalRequest $request): int => (int)$request->job_id, $this->historyRequests());
        $oldDeleted = $this->deletedTemplate();
        // A job a spec launched and voted on, of the template that needs two approvals.
        $dualJob = $this->launchDual();
        $dualRequest = ApprovalRequest::findOne(['job_id' => $dualJob->id]);
        $this->assertNotNull($dualRequest);
        $this->approvals()->recordDecision($dualRequest, $this->operatorId, ApprovalDecision::DECISION_APPROVED, null);
        $launcherId = $this->launcherId();

        $this->seed();

        $this->assertCount(3, $this->historyRequests());
        $this->assertSame(0, (int)Job::find()->where(['id' => $oldJobs])->count(), 'the old jobs went, with their requests');
        $this->assertSame(0, (int)ApprovalRequest::find()->where(['job_id' => $oldJobs])->count());
        $this->assertNull(JobTemplate::findWithDeleted()->where(['id' => $oldDeleted->id])->one());
        $this->assertNull(Job::findOne($dualJob->id), 'a new run of the spec launches anew');
        $this->assertNull(ApprovalRequest::findOne($dualRequest->id));
        $this->assertSame(0, (int)ApprovalDecision::find()->where(['approval_request_id' => $dualRequest->id])->count());
        $this->assertSame($launcherId, $this->launcherId());
        $this->assertSame(1, (int)User::find()->where(['username' => E2eApprovalSeeder::LAUNCHER])->count());
        foreach ([E2eApprovalSeeder::RULE, E2eApprovalSeeder::SOLO_RULE, E2eApprovalSeeder::HISTORY_RULE, E2eApprovalSeeder::DUAL_RULE] as $name) {
            $this->assertSame(1, (int)ApprovalRule::find()->where(['name' => $name])->count(), $name);
        }
        $templates = [
            E2eApprovalSeeder::GATED_TEMPLATE,
            E2eApprovalSeeder::SOLO_TEMPLATE,
            E2eApprovalSeeder::HISTORY_TEMPLATE,
            E2eApprovalSeeder::DUAL_TEMPLATE,
            E2eApprovalSeeder::DELETED_TEMPLATE,
        ];
        foreach ($templates as $name) {
            $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => $name])->count(), $name);
        }
        $this->assertSame(1, (int)Schedule::find()->where(['name' => E2eApprovalSeeder::DELETED_SCHEDULE])->count());
    }

    public function testTheScheduleOfTheDeletedTemplateIsDisabled(): void
    {
        $this->seed();

        $template = $this->deletedTemplate();
        $this->assertTrue($template->isDeleted());
        $this->assertNull($template->approval_rule_id);
        $schedule = Schedule::findOne(['name' => E2eApprovalSeeder::DELETED_SCHEDULE]);
        $this->assertNotNull($schedule);
        $this->assertSame((int)$template->id, (int)$schedule->job_template_id);
        $this->assertFalse((bool)$schedule->enabled);
        $this->assertNull($schedule->next_run_at);
        $this->assertSame($this->adminId, (int)$schedule->created_by);
    }

    public function testATemplateThatIsGoneIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Job template #0 not found.');

        $this->seeder()->seed($this->adminId, 0, 0);
    }

    /**
     * yii e2e/teardown removes every row the seed wrote: the rules once the
     * templates that use them are gone, the jobs with their requests.
     */
    public function testTheTeardownRemovesEverythingTheSeedWrote(): void
    {
        $operating = $this->createTeam($this->adminId);
        $this->createTeamProject((int)$operating->id, (int)$this->parent->project_id, TeamProject::ROLE_OPERATOR);
        $this->addTeamMember((int)$operating->id, $this->operatorId);
        $this->seed();
        $jobs = array_map(static fn (ApprovalRequest $request): int => (int)$request->job_id, $this->historyRequests());
        // What the two-approvals spec leaves: a job of the launcher, with e2e-operator's vote.
        $dualJob = $this->launchDual();
        $dualRequest = ApprovalRequest::findOne(['job_id' => $dualJob->id]);
        $this->assertNotNull($dualRequest);
        $this->approvals()->recordDecision($dualRequest, $this->operatorId, ApprovalDecision::DECISION_APPROVED, null);
        $jobs[] = (int)$dualJob->id;
        $launcherId = $this->launcherId();

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        $this->assertSame(0, (int)ApprovalRule::find()->where(['like', 'name', 'e2e-%', false])->count());
        $this->assertSame(0, (int)JobTemplate::findWithDeleted()->where(['like', 'name', 'e2e-%', false])->count());
        $this->assertSame(0, (int)Schedule::find()->where(['like', 'name', 'e2e-%', false])->count());
        $this->assertSame(0, (int)Job::find()->where(['id' => $jobs])->count());
        $this->assertSame(0, (int)ApprovalRequest::find()->where(['job_id' => $jobs])->count());
        $this->assertNull(User::findOne($this->adminId));
        $this->assertNull(User::findOne($launcherId));
        $this->assertSame(0, (int)TeamMember::find()->where(['user_id' => $launcherId])->count());
        $this->assertSame([], $this->auth()->getAssignments($launcherId));
    }

    private function seed(): void
    {
        $ruleId = $this->seeder()->seedRule($this->adminId);
        $this->seeder()->seed($this->adminId, (int)$this->parent->id, $ruleId);
    }

    private function seeder(): E2eApprovalSeeder
    {
        return new E2eApprovalSeeder(function (string $msg): void {
            $this->log[] = $msg;
        });
    }

    /**
     * The requests of the jobs of e2e-history-gated-template, oldest job first.
     *
     * @return list<ApprovalRequest>
     */
    private function historyRequests(): array
    {
        $jobs = Job::find()->select('id')->where(['job_template_id' => $this->template(E2eApprovalSeeder::HISTORY_TEMPLATE)->id]);
        /** @var list<ApprovalRequest> $requests */
        $requests = ApprovalRequest::find()->where(['job_id' => $jobs])->orderBy(['job_id' => SORT_ASC])->all();
        $this->assertCount(3, $requests);

        return $requests;
    }

    /**
     * The voters of $request in the order they voted, each with whether it
     * is the launcher's vote.
     *
     * @return list<array{0: int, 1: bool}>
     */
    private function votesOf(ApprovalRequest $request): array
    {
        $votes = [];
        foreach ($request->getDecisions()->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])->all() as $decision) {
            $this->assertInstanceOf(ApprovalDecision::class, $decision);
            $this->assertSame(ApprovalDecision::DECISION_APPROVED, $decision->decision);
            $votes[] = [0 => (int)$decision->user_id, 1 => $request->isLauncherDecision($decision)];
        }

        return $votes;
    }

    private function deletedTemplate(): JobTemplate
    {
        /** @var JobTemplate|null $template */
        $template = JobTemplate::findWithDeleted()->where(['name' => E2eApprovalSeeder::DELETED_TEMPLATE])->one();
        $this->assertNotNull($template);

        return $template;
    }

    private function template(string $name): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => $name]);
        $this->assertNotNull($template, "{$name} is a live template");

        return $template;
    }

    private function rule(string $name): ApprovalRule
    {
        $rule = ApprovalRule::findOne(['name' => $name]);
        $this->assertNotNull($rule, "{$name} is stored");

        return $rule;
    }

    private function eligibility(): ApproverEligibility
    {
        $eligibility = \Yii::$app->get('approverEligibility');
        $this->assertInstanceOf(ApproverEligibility::class, $eligibility);

        return $eligibility;
    }

    private function approvals(): ApprovalService
    {
        $approvals = \Yii::$app->get('approvalService');
        $this->assertInstanceOf(ApprovalService::class, $approvals);

        return $approvals;
    }

    private function projectAccess(): ProjectAccessChecker
    {
        $checker = \Yii::$app->get('projectAccessChecker');
        $this->assertInstanceOf(ProjectAccessChecker::class, $checker);

        return $checker;
    }

    private function auth(): ManagerInterface
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);

        return $auth;
    }

    /** The ID of e2e-approval-launcher, whom the seed restored. */
    private function launcherId(): int
    {
        $launcher = User::findOne(['username' => E2eApprovalSeeder::LAUNCHER]);
        $this->assertNotNull($launcher, 'the seed restored ' . E2eApprovalSeeder::LAUNCHER);

        return (int)$launcher->id;
    }

    /** A job of e2e-dual-gated-template, launched by e2e-approval-launcher as its launch form does. */
    private function launchDual(): Job
    {
        $launch = \Yii::$app->get('jobLaunchService');
        $this->assertInstanceOf(JobLaunchService::class, $launch);

        return $launch->launch($this->template(E2eApprovalSeeder::DUAL_TEMPLATE), $this->launcherId());
    }

    private function namedUser(string $username, string $role): User
    {
        $user = $this->createUser($role);
        $user->username = $username;
        $user->save(false);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $rbacRole = $auth->getRole($role);
        $this->assertNotNull($rbacRole, "RBAC role {$role}");
        $auth->assign($rbacRole, (int)$user->id);

        return $user;
    }
}
