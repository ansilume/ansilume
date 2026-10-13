<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eScheduleRunAsSeeder;
use app\commands\E2eTeardownHelper;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\ApproverEligibility;
use app\services\ScheduleService;
use app\tests\integration\DbTestCase;

/**
 * The fixtures of the E2E spec for whom a schedule runs as
 * (E2eScheduleRunAsSeeder, tests/e2e/tests/schedules/runs-as.spec.ts). The
 * specs find them by these names; the test database holds no "e2e-" rows
 * outside a test, and the rollback removes them.
 */
class E2eScheduleRunAsSeederTest extends DbTestCase
{
    private int $adminId;

    private int $operatorId;

    /** Stands for e2e-template, whose project, inventory and runner group the fixture uses. */
    private JobTemplate $parent;

    /** Stands for e2e-approval-rule: e2e-admin and e2e-operator approve, one approval is needed. */
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
        $this->log = [];
    }

    public function testTheScheduleOfTheGatedTemplateIsE2eAdminsAndDisabled(): void
    {
        $this->seed();

        $template = $this->template();
        $this->assertSame((int)$this->rule->id, (int)$template->approval_rule_id);
        $this->assertSame((int)$this->parent->project_id, (int)$template->project_id);
        $this->assertSame((int)$this->parent->inventory_id, (int)$template->inventory_id);
        $this->assertSame((int)$this->parent->runner_group_id, (int)$template->runner_group_id);
        $this->assertSame('site.yml', $template->playbook);
        $this->assertNull($template->extra_vars);
        $this->assertNull($template->survey_fields);
        $this->assertNull($template->trigger_token);
        $this->assertNull($template->deleted_at);
        $this->assertSame($this->adminId, (int)$template->created_by);

        $schedule = $this->schedule();
        $this->assertSame((int)$template->id, (int)$schedule->job_template_id);
        $this->assertSame($this->adminId, (int)$schedule->created_by, 'it runs as e2e-admin');
        $this->assertFalse((bool)$schedule->enabled, 'it never comes due by itself');
        $this->assertSame(E2eScheduleRunAsSeeder::CRON, $schedule->cron_expression);
        $this->assertSame('UTC', $schedule->timezone);
        $this->assertNull($schedule->extra_vars);
        $this->assertNull($schedule->getSealedSecretExtraVars());
        $this->assertNull($schedule->last_run_at);
        $this->assertNull($schedule->next_run_at);
        $this->assertSame([], Job::findAll(['job_template_id' => $template->id]));

        $this->assertSame([
            sprintf(
                "  Restored e2e-run-as-schedule (ID %d) of e2e-run-as-template (ID %d), gated by approval rule #%d: disabled, runs as user #%d.\n",
                $schedule->id,
                $template->id,
                $this->rule->id,
                $this->adminId
            ),
        ], $this->log);
    }

    /**
     * What the spec does once e2e-operator saved the schedule: it runs as
     * e2e-operator, its job waits for approval, and only e2e-admin may decide
     * on it. A launch by e2e-admin would not be refused either.
     */
    public function testOnceE2eOperatorOwnsTheScheduleItsJobWaitsForE2eAdmin(): void
    {
        $this->seed();
        $template = $this->template();
        foreach ([$this->adminId, $this->operatorId] as $launcher) {
            $this->assertNull($this->eligibility()->jobLaunchRefusal($this->rule, $template, $launcher), "a launch by user #{$launcher}");
        }
        // As /e2e/fire-schedule does it, once e2e-operator saved the schedule.
        Schedule::updateAll(['created_by' => $this->operatorId, 'enabled' => 1, 'next_run_at' => time() - 60], ['id' => $this->schedule()->id]);

        /** @var ScheduleService $schedules */
        $schedules = \Yii::$app->get('scheduleService');
        $this->assertSame(1, $schedules->runDue());

        $jobs = Job::findAll(['job_template_id' => $template->id]);
        $this->assertCount(1, $jobs);
        $this->assertSame($this->operatorId, (int)$jobs[0]->launched_by);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $jobs[0]->status);
        $request = ApprovalRequest::findOne(['job_id' => $jobs[0]->id]);
        $this->assertNotNull($request);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertSame([$this->adminId], $this->eligibility()->eligibleForRequest($request), 'the launcher never decides');
        $this->assertTrue($this->eligibility()->isLauncher($request, $this->operatorId));
    }

    /**
     * After the spec ran: e2e-operator owns the schedule, which is enabled,
     * with another cron expression and extra vars, and its job waits for
     * approval; the template lost its rule and was deleted. Seeding again
     * restores both in place, without the job and its request.
     */
    public function testSeedingAgainRestoresTheFixtureInPlace(): void
    {
        $this->seed();
        $template = $this->template();
        $schedule = $this->schedule();
        $job = $this->createJob((int)$template->id, $this->operatorId, Job::STATUS_PENDING_APPROVAL);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$this->rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);
        \Yii::$app->db->createCommand()->insert('{{%job_task}}', ['job_id' => $job->id, 'task_name' => 'e2e task'])->execute();
        Schedule::updateAll([
            'created_by' => $this->operatorId,
            'enabled' => 1,
            'cron_expression' => '30 0 1 1 *',
            'timezone' => 'Europe/Berlin',
            'extra_vars' => '{"env":"prod"}',
            'secret_extra_vars' => 'sealed',
            'last_run_at' => time(),
            'next_run_at' => time() + 60,
        ], ['id' => $schedule->id]);
        JobTemplate::updateAll(['approval_rule_id' => null, 'deleted_at' => time(), 'extra_vars' => '{"a":1}'], ['id' => $template->id]);

        $this->seed();

        $restored = $this->template();
        $this->assertSame((int)$template->id, (int)$restored->id, 'restored in place');
        $this->assertNull($restored->deleted_at);
        $this->assertSame((int)$this->rule->id, (int)$restored->approval_rule_id);
        $this->assertNull($restored->extra_vars);
        $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => E2eScheduleRunAsSeeder::TEMPLATE])->count());
        $again = $this->schedule();
        $this->assertSame((int)$schedule->id, (int)$again->id, 'restored in place');
        $this->assertSame($this->adminId, (int)$again->created_by, 'e2e-admin\'s again');
        $this->assertFalse((bool)$again->enabled);
        $this->assertSame(E2eScheduleRunAsSeeder::CRON, $again->cron_expression);
        $this->assertSame('UTC', $again->timezone);
        $this->assertNull($again->extra_vars);
        $this->assertNull($again->getSealedSecretExtraVars());
        $this->assertNull($again->last_run_at);
        $this->assertNull($again->next_run_at);
        $this->assertSame(1, (int)Schedule::find()->where(['name' => E2eScheduleRunAsSeeder::SCHEDULE])->count());
        $this->assertNull(Job::findOne($job->id), 'a new run of the spec fires the schedule anew');
        $this->assertNull(ApprovalRequest::findOne($request->id));
        $this->assertSame(0, (int)(new \yii\db\Query())->from('{{%job_task}}')->where(['job_id' => $job->id])->count());
        $this->assertCount(2, $this->log);
    }

    public function testATemplateThatIsGoneIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Job template #999999999 not found.');

        $this->seeder()->seed($this->adminId, 999999999, (int)$this->rule->id);
    }

    /**
     * yii e2e/teardown removes what the seed and the spec wrote: the template
     * and its schedule, and the job the schedule launched with its request.
     */
    public function testTheTeardownRemovesEverythingTheSeedAndTheSpecWrote(): void
    {
        $this->rule->name = 'e2e-approval-rule';
        $this->rule->save(false);
        $this->seed();
        $job = $this->createJob((int)$this->template()->id, $this->operatorId, Job::STATUS_PENDING_APPROVAL);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$this->rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        $this->assertNull(JobTemplate::findWithDeleted()->where(['name' => E2eScheduleRunAsSeeder::TEMPLATE])->one());
        $this->assertNull(Schedule::findOne(['name' => E2eScheduleRunAsSeeder::SCHEDULE]));
        $this->assertNull(Job::findOne($job->id));
        $this->assertNull(ApprovalRequest::findOne($request->id));
        $this->assertNull(ApprovalRule::findOne($this->rule->id));
    }

    private function seed(): void
    {
        $this->seeder()->seed($this->adminId, (int)$this->parent->id, (int)$this->rule->id);
    }

    private function seeder(): E2eScheduleRunAsSeeder
    {
        return new E2eScheduleRunAsSeeder(function (string $msg): void {
            $this->log[] = $msg;
        });
    }

    private function template(): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => E2eScheduleRunAsSeeder::TEMPLATE]);
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template;
    }

    private function schedule(): Schedule
    {
        $schedule = Schedule::findOne(['name' => E2eScheduleRunAsSeeder::SCHEDULE]);
        $this->assertInstanceOf(Schedule::class, $schedule);

        return $schedule;
    }

    private function eligibility(): ApproverEligibility
    {
        $eligibility = \Yii::$app->get('approverEligibility');
        $this->assertInstanceOf(ApproverEligibility::class, $eligibility);

        return $eligibility;
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
