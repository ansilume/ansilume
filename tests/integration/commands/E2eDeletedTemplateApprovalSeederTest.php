<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eDeletedTemplateApprovalSeeder;
use app\commands\E2eTeardownHelper;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalService;
use app\services\MaintenanceService;
use app\tests\integration\DbTestCase;
use yii\caching\CacheInterface;

/**
 * The fixture of the E2E spec for an approval request whose job's template
 * was deleted while the job waited (E2eDeletedTemplateApprovalSeeder,
 * tests/e2e/tests/approvals/deleted-template.spec.ts). The spec finds it by
 * these names; the test database holds no "e2e-" rows outside a test, and
 * the rollback removes them.
 */
class E2eDeletedTemplateApprovalSeederTest extends DbTestCase
{
    private int $adminId;

    private int $operatorId;

    /** Stands for e2e-template, whose parents the fixture uses. */
    private JobTemplate $parent;

    /** Stands for e2e-approval-rule: e2e-admin and e2e-operator approve, 60 minutes to decide. */
    private ApprovalRule $rule;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->namedUser('e2e-admin', 'admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
        $this->operatorId = (int)$this->namedUser('e2e-operator', 'operator')->id;
        $this->parent = $this->createJobTemplate(
            (int)$this->createProject($this->adminId)->id,
            (int)$this->createInventory($this->adminId)->id,
            (int)$this->createRunnerGroup($this->adminId)->id,
            $this->adminId
        );
        $this->rule = $this->createApprovalRule(
            $this->adminId,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [$this->adminId, $this->operatorId]])
        );
        $this->rule->timeout_minutes = 60;
        $this->rule->save(false);
        $this->log = [];
        $this->cache()->delete(MaintenanceService::DELETED_TEMPLATE_SWEEP_KEY);
    }

    protected function tearDown(): void
    {
        $this->cache()->delete(MaintenanceService::DELETED_TEMPLATE_SWEEP_KEY);
        parent::tearDown();
    }

    public function testTheTemplateIsGatedDeletedAndHasNoJobs(): void
    {
        $before = time();
        $this->seed();

        $template = $this->template();
        $this->assertNotNull($template->deleted_at, 'soft-deleted');
        $this->assertGreaterThanOrEqual($before, (int)$template->deleted_at);
        $this->assertNull(JobTemplate::findOne($template->id), 'not among the live templates');
        $this->assertSame((int)$this->rule->id, (int)$template->approval_rule_id);
        $this->assertSame(
            [(int)$this->parent->project_id, (int)$this->parent->inventory_id, (int)$this->parent->runner_group_id],
            [(int)$template->project_id, (int)$template->inventory_id, (int)$template->runner_group_id]
        );
        $this->assertSame(E2eDeletedTemplateApprovalSeeder::DESCRIPTION, $template->description);
        $this->assertSame($this->adminId, (int)$template->created_by);
        $this->assertSame([], Job::findAll(['job_template_id' => $template->id]));
        $this->assertSame([
            sprintf(
                "  Restored e2e-deleted-gate-template (ID %d), gated by approval rule #%d: deleted, without jobs.\n",
                $template->id,
                $this->rule->id
            ),
        ], $this->log);
    }

    /**
     * After a spec run: a job of the template with its request, a task row,
     * and the template restored (live) by someone with another rule. Seeding
     * again restores it in place, deleted, without the job.
     */
    public function testSeedingAgainRestoresItInPlaceDeletedWithoutJobs(): void
    {
        $this->seed();
        $template = $this->template();
        $ids = $this->seeder()->waitingJob('e2e-operator');
        \Yii::$app->db->createCommand()->insert('{{%job_task}}', ['job_id' => $ids['job_id'], 'task_name' => 'e2e task'])->execute();
        $otherRule = $this->createApprovalRule($this->adminId);
        JobTemplate::updateAll(['deleted_at' => null, 'approval_rule_id' => $otherRule->id, 'extra_vars' => '{"a":1}'], ['id' => $template->id]);

        $this->seed();

        $restored = $this->template();
        $this->assertSame((int)$template->id, (int)$restored->id, 'restored in place');
        $this->assertNotNull($restored->deleted_at);
        $this->assertSame((int)$this->rule->id, (int)$restored->approval_rule_id);
        $this->assertNull($restored->extra_vars);
        $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => E2eDeletedTemplateApprovalSeeder::TEMPLATE])->count());
        $this->assertNull(Job::findOne($ids['job_id']));
        $this->assertNull(ApprovalRequest::findOne($ids['request_id']));
        $this->assertSame(0, (int)(new \yii\db\Query())->from('{{%job_task}}')->where(['job_id' => $ids['job_id']])->count());
    }

    /**
     * What the hook makes for the spec: e2e-operator's job of the deleted
     * template waits for approval on a pending request, which expires after
     * the rule's timeout. The sweep of deleted templates, which would cancel
     * the job, is held off meanwhile.
     */
    public function testTheWaitingJobOfTheDeletedTemplateHasAPendingRequest(): void
    {
        $this->seed();
        $before = time();

        $ids = $this->seeder()->waitingJob('e2e-operator');

        $job = Job::findOne($ids['job_id']);
        $this->assertNotNull($job);
        $this->assertSame((int)$this->template()->id, (int)$job->job_template_id);
        $this->assertSame($this->operatorId, (int)$job->launched_by);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status);
        $request = ApprovalRequest::findOne($ids['request_id']);
        $this->assertNotNull($request);
        $this->assertSame((int)$job->id, (int)$request->job_id);
        $this->assertSame((int)$this->rule->id, (int)$request->approval_rule_id);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertGreaterThanOrEqual($before, (int)$request->requested_at);
        $this->assertSame((int)$request->requested_at + 3600, (int)$request->expires_at);
        $this->assertNull($request->resolved_at);
        $this->assertNotFalse(
            $this->cache()->get(MaintenanceService::DELETED_TEMPLATE_SWEEP_KEY),
            'the sweep of deleted templates waits'
        );
    }

    /**
     * What the spec checks in the browser: the request page offers e2e-admin
     * no vote and says why, and e2e-admin's vote closes the request and
     * cancels the job instead of approving it.
     */
    public function testAVoteOnTheRequestFindsTheJobNoLongerWaitingAndCancelsIt(): void
    {
        $this->seed();
        $ids = $this->seeder()->waitingJob('e2e-operator');
        $request = ApprovalRequest::findOne($ids['request_id']);
        $this->assertNotNull($request);
        $expected = "Approval request #{$ids['request_id']} can no longer be decided: its job #{$ids['job_id']} no longer waits for approval.";

        $refusal = $this->approvals()->decisionRefusal($request, $this->adminId);
        $this->assertNotNull($refusal);
        $this->assertSame($expected, $refusal->getMessage());
        try {
            $this->approvals()->recordDecision($request, $this->adminId, ApprovalDecision::DECISION_APPROVED);
            $this->fail('The vote was recorded.');
        } catch (ApprovalDecisionRefusedException $e) {
            $this->assertSame($expected, $e->getMessage());
            $this->assertSame(409, $e->httpStatus());
        }

        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $job = Job::findOne($ids['job_id']);
        $this->assertNotNull($job);
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertSame(0, (int)ApprovalDecision::find()->where(['approval_request_id' => $request->id])->count());
    }

    public function testARuleWithoutATimeoutGivesARequestThatNeverExpires(): void
    {
        $this->rule->timeout_minutes = null;
        $this->rule->save(false);
        $this->seed();

        $request = ApprovalRequest::findOne($this->seeder()->waitingJob('e2e-operator')['request_id']);

        $this->assertNotNull($request);
        $this->assertNull($request->expires_at);
    }

    /**
     * @return array<string, array{0: \Closure(self): void}>
     */
    public static function notSeededProvider(): array
    {
        return [
            'no such template' => [static function (self $test): void {
            }],
            'the template is live' => [static function (self $test): void {
                $test->seed();
                JobTemplate::updateAll(['deleted_at' => null], ['name' => E2eDeletedTemplateApprovalSeeder::TEMPLATE]);
            }],
            'the template has no rule' => [static function (self $test): void {
                $test->seed();
                JobTemplate::updateAll(['approval_rule_id' => null], ['name' => E2eDeletedTemplateApprovalSeeder::TEMPLATE]);
            }],
        ];
    }

    /**
     * @dataProvider notSeededProvider
     * @param \Closure(self): void $arrange
     */
    public function testWithoutTheSeededTemplateNoJobIsMade(\Closure $arrange): void
    {
        $arrange($this);
        $jobs = (int)Job::find()->count();

        try {
            $this->seeder()->waitingJob('e2e-operator');
            $this->fail('A job was made.');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'Job template e2e-deleted-gate-template is not seeded as a deleted template with an approval rule: run php yii e2e/seed.',
                $e->getMessage()
            );
        }
        $this->assertSame($jobs, (int)Job::find()->count());
    }

    public function testAnUnknownLauncherIsReported(): void
    {
        $this->seed();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User e2e-nobody not found.');

        $this->seeder()->waitingJob('e2e-nobody');
    }

    public function testATemplateThatIsGoneIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Job template #999999999 not found.');

        $this->seeder()->seed($this->adminId, 999999999, (int)$this->rule->id);
    }

    /**
     * yii e2e/teardown removes the deleted template and the job the hook
     * made, with its request.
     */
    public function testTheTeardownRemovesTheTemplateItsJobAndItsRequest(): void
    {
        $this->rule->name = 'e2e-approval-rule';
        $this->rule->save(false);
        $this->seed();
        $ids = $this->seeder()->waitingJob('e2e-operator');

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        $this->assertNull(JobTemplate::findWithDeleted()->where(['name' => E2eDeletedTemplateApprovalSeeder::TEMPLATE])->one());
        $this->assertNull(Job::findOne($ids['job_id']));
        $this->assertNull(ApprovalRequest::findOne($ids['request_id']));
        $this->assertNull(ApprovalRule::findOne($this->rule->id));
    }

    public function seed(): void
    {
        $this->seeder()->seed($this->adminId, (int)$this->parent->id, (int)$this->rule->id);
    }

    private function seeder(): E2eDeletedTemplateApprovalSeeder
    {
        return new E2eDeletedTemplateApprovalSeeder(function (string $msg): void {
            $this->log[] = $msg;
        });
    }

    private function template(): JobTemplate
    {
        $template = JobTemplate::findWithDeleted()->where(['name' => E2eDeletedTemplateApprovalSeeder::TEMPLATE])->one();
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template;
    }

    private function cache(): CacheInterface
    {
        $cache = \Yii::$app->cache;
        $this->assertInstanceOf(CacheInterface::class, $cache);

        return $cache;
    }

    private function approvals(): ApprovalService
    {
        $service = \Yii::$app->get('approvalService');
        $this->assertInstanceOf(ApprovalService::class, $service);

        return $service;
    }

    /**
     * A user named $username who holds the RBAC role $role.
     */
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
