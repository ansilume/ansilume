<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eTeardownHelper;
use app\commands\E2eWorkflowGateSeeder;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\ApprovalService;
use app\services\ApproverEligibility;
use app\services\TriggerTokenService;
use app\services\WorkflowExecutionService;
use app\services\WorkflowStepRouter;
use app\tests\integration\DbTestCase;

/**
 * The workflow fixtures of the E2E specs for approval gates, launch extra
 * vars, locked steps, deleting a workflow template and canceling a run
 * (E2eWorkflowGateSeeder). The specs find them by these names; the test
 * database holds no "e2e-" rows outside a test, and the rollback removes them.
 */
class E2eWorkflowGateSeederTest extends DbTestCase
{
    private const FIXTURES = [
        'e2e-steps-workflow',
        'e2e-approval-steps-workflow',
        'e2e-operator-steps-workflow',
        'e2e-locked-workflow',
        'e2e-delete-workflow',
        'e2e-empty-workflow',
        'e2e-trigger-vars-workflow',
        'e2e-gate-continue-workflow',
        'e2e-cancel-workflow',
        'e2e-solo-gate-workflow',
        'e2e-gated-job-workflow',
        'e2e-late-gate-workflow',
    ];

    private int $adminId;
    private int $operatorId;
    /** Stands for e2e-template, the job template E2eController passes. */
    private int $templateId;
    private int $secretTemplateId;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUser('admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->adminId = (int)$admin->id;
        $this->operatorId = (int)$this->namedUser('e2e-operator', 'operator')->id;
        $this->templateId = (int)$this->createJobTemplate(
            (int)$this->createProject($this->adminId)->id,
            (int)$this->createInventory($this->adminId)->id,
            (int)$this->createRunnerGroup($this->adminId)->id,
            $this->adminId
        )->id;
        $secret = $this->createPasswordSurveyTemplate($this->adminId);
        $secret->name = 'e2e-secret-template';
        $secret->save(false);
        $this->secretTemplateId = (int)$secret->id;
        $this->log = [];
    }

    public function testSeedsEveryFixtureWithTheStepsAndRunsTheSpecsExpect(): void
    {
        $this->seed();

        $job = $this->templateId;
        $this->assertSame([
            ['e2e-steps-build', 10, 'job', $job, null, null, 'e2e-steps-cleanup', null],
            ['e2e-steps-cleanup', 20, 'job', $job, null, null, null, null],
        ], $this->stepsOf('e2e-steps-workflow'), 'the build step routes to the cleanup step on failure');
        $this->assertSame([
            ['e2e-approval-steps-job', 10, 'job', $job, null, null, null, null],
        ], $this->stepsOf('e2e-approval-steps-workflow'));
        $this->assertSame([
            ['e2e-operator-steps-job', 10, 'job', $job, null, null, null, null],
        ], $this->stepsOf('e2e-operator-steps-workflow'));
        $this->assertSame([
            ['e2e-locked-pause', 10, 'pause', null, null, null, null, null],
            ['e2e-locked-job', 20, 'job', $job, null, null, null, null],
        ], $this->stepsOf('e2e-locked-workflow'));
        $this->assertSame([
            ['e2e-delete-pause', 10, 'pause', null, null, null, null, null],
            ['e2e-delete-job', 20, 'job', $job, null, null, null, null],
        ], $this->stepsOf('e2e-delete-workflow'));
        $this->assertSame([], $this->stepsOf('e2e-empty-workflow'));
        $this->assertSame([
            ['e2e-trigger-vars-first', 10, 'job', $this->secretTemplateId, null, null, null, null],
            ['e2e-trigger-vars-second', 20, 'job', $this->secretTemplateId, null, null, null, null],
        ], $this->stepsOf('e2e-trigger-vars-workflow'));
        $rule = $this->gateRule();
        $soloRule = $this->rule('e2e-solo-gate-rule');
        foreach (['e2e-gate-continue' => $rule, 'e2e-cancel' => $rule, 'e2e-solo-gate' => $soloRule] as $prefix => $gate) {
            $this->assertSame([
                // No On Failure branch on the approval step.
                ["{$prefix}-approve", 10, 'approval', null, (int)$gate->id, null, null, null],
                ["{$prefix}-job", 20, 'job', $job, null, null, null, null],
            ], $this->stepsOf("{$prefix}-workflow"));
        }
        $gated = $this->assertGatedTemplate((int)$rule->id);
        $this->assertSame([
            ['e2e-gated-job-step', 10, 'job', (int)$gated->id, null, null, null, null],
            ['e2e-gated-job-after', 20, 'pause', null, null, null, null, null],
        ], $this->stepsOf('e2e-gated-job-workflow'));
        $lateGate = $this->assertLateGateTemplate();
        $this->assertSame([
            ['e2e-late-gate-job', 10, 'job', (int)$lateGate->id, null, null, null, null],
        ], $this->stepsOf('e2e-late-gate-workflow'));

        foreach (self::FIXTURES as $name) {
            $workflow = $this->workflow($name);
            $this->assertSame($this->adminId, (int)$workflow->created_by, $name);
            $this->assertNull($workflow->trigger_token, $name);
            $expectedRuns = in_array($name, ['e2e-locked-workflow', 'e2e-delete-workflow'], true) ? 1 : 0;
            $this->assertCount($expectedRuns, $this->runsOf($name), $name);
        }
        $locked = $this->assertPausedRun('e2e-locked-workflow', 'e2e-locked-pause');
        $deleted = $this->assertPausedRun('e2e-delete-workflow', 'e2e-delete-pause');

        foreach ([[$rule, [$this->adminId, $this->operatorId]], [$soloRule, [$this->adminId]]] as [$approvalRule, $approvers]) {
            $this->assertSame(ApprovalRule::APPROVER_TYPE_USERS, $approvalRule->approver_type);
            $this->assertSame($approvers, $approvalRule->getApproverUserIds(), (string)$approvalRule->name);
            $this->assertSame(1, (int)$approvalRule->required_approvals);
            $this->assertNull($approvalRule->timeout_minutes, 'a request never expires before a spec decides it');
            $this->assertSame(ApprovalRule::TIMEOUT_ACTION_REJECT, $approvalRule->timeout_action);
            $this->assertSame($this->adminId, (int)$approvalRule->created_by);
            $this->assertNull($approvalRule->job_template_id);
        }

        $this->assertSame([
            '  Restored workflow gate fixtures: e2e-steps-workflow, e2e-approval-steps-workflow, e2e-operator-steps-workflow, '
            . "e2e-locked-workflow (run #{$locked->id} paused), e2e-delete-workflow (run #{$deleted->id} paused), "
            . 'e2e-empty-workflow, e2e-trigger-vars-workflow, e2e-gate-rule, e2e-gate-continue-workflow, e2e-cancel-workflow, '
            . 'e2e-gated-job-template, e2e-gated-job-workflow, e2e-solo-gate-rule, e2e-solo-gate-workflow, '
            . 'e2e-late-gate-template, e2e-late-gate-workflow.' . "\n",
        ], $this->log);
    }

    /**
     * What workflow-jobs/approval-flow.spec.ts does with the fixture: e2e-admin
     * launches, the run waits at the approval step, only e2e-operator may
     * decide, and a rejection ends the run as failed, because the approval
     * step has no On Failure branch: the job step never starts.
     */
    public function testTheAdminLaunchesTheGatedWorkflowAndARejectionByTheOperatorEndsTheRun(): void
    {
        $this->seed();
        $run = $this->engine()->launch($this->workflow('e2e-gate-continue-workflow'), $this->adminId);
        $request = $this->requestOf($this->onlyExecutionOf($run));
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertSame((int)$this->gateRule()->id, (int)$request->approval_rule_id);
        $this->assertSame([$this->operatorId], $this->eligibility()->eligibleForRequest($request), 'the launcher never decides');

        $this->approvals()->recordDecision($request, $this->operatorId, ApprovalDecision::DECISION_REJECTED);

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $execution = $this->onlyExecutionOf($run);
        $this->assertSame('e2e-gate-continue-approve', $execution->stepName(), 'the job step never started');
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(sprintf(WorkflowStepRouter::APPROVAL_REJECTED, $request->id), $execution->error_message);
    }

    /**
     * Whichever of the rule's two approvers launches, the other one may
     * decide, so neither launch is refused for too few approvers.
     */
    public function testEitherApproverMayLaunchTheGatedWorkflows(): void
    {
        $this->seed();

        foreach (['e2e-gate-continue-workflow', 'e2e-cancel-workflow'] as $name) {
            foreach ([$this->adminId, $this->operatorId] as $launcher) {
                $this->assertNull($this->eligibility()->workflowLaunchRefusal($this->workflow($name), $launcher), "{$name} launched by #{$launcher}");
            }
        }
    }

    /**
     * What workflow-jobs/gated-job-step.spec.ts does with the fixture: the job
     * of e2e-gated-job-step waits for approval, only e2e-operator may decide,
     * and once approved the job is queued and the step waits for it; no
     * runner serves its group, so it stays queued.
     */
    public function testTheJobOfTheGatedJobStepWaitsForTheOperatorAndThenStaysQueued(): void
    {
        $this->seed();
        $run = $this->engine()->launch($this->workflow('e2e-gated-job-workflow'), $this->adminId);
        $execution = $this->onlyExecutionOf($run);
        $this->assertSame('e2e-gated-job-step', $execution->stepName());
        $request = $this->requestOf($execution);
        $job = Job::findOne($request->job_id);
        $this->assertNotNull($job);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status);
        $this->assertSame((int)$this->workflow('e2e-gated-job-workflow')->steps[0]->job_template_id, (int)$job->job_template_id);
        $this->assertSame([$this->operatorId], $this->eligibility()->eligibleForRequest($request));

        $this->approvals()->recordDecision($request, $this->operatorId, ApprovalDecision::DECISION_APPROVED);

        $job->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $job->status, 'approved, the job is queued');
        $execution->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $execution->status, 'the step waits for its job');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
        $this->assertCount(1, WorkflowJobStep::findAll(['workflow_job_id' => $run->id]), 'the pause step did not start');
    }

    /**
     * e2e-admin is the only approver of e2e-solo-gate-rule and never decides
     * what they launch: their launch is refused before a run starts, naming
     * the step and the rule (workflow-templates/launch.spec.ts). Another
     * user's launch is not refused: e2e-admin may decide then.
     */
    public function testALaunchOfTheSoloGatedWorkflowByItsOnlyApproverIsRefused(): void
    {
        $this->seed();
        $workflow = $this->workflow('e2e-solo-gate-workflow');

        $this->assertSame(
            'Step "e2e-solo-gate-approve": approval rule "e2e-solo-gate-rule" needs 1 approval(s) from users other than '
            . 'the one who launches, but only 0 of its approvers may decide. An admin can add approvers to the rule.',
            $this->eligibility()->workflowLaunchRefusal($workflow, $this->adminId)
        );
        $this->assertNull($this->eligibility()->workflowLaunchRefusal($workflow, $this->operatorId));
    }

    /**
     * After the specs ran: a deleted template, a canceled run, a new run with
     * its job and approval request, a step added, a trigger token, a changed
     * rule. Seeding again restores each fixture in place.
     */
    public function testSeedingAgainRestoresEveryFixtureInPlace(): void
    {
        $this->seed();
        $ids = array_map(fn (string $name): int => (int)$this->workflow($name)->id, self::FIXTURES);
        $ruleId = (int)$this->gateRule()->id;
        $oldRuns = array_map(static fn (WorkflowJob $run): int => (int)$run->id, array_merge(
            $this->runsOf('e2e-locked-workflow'),
            $this->runsOf('e2e-delete-workflow')
        ));

        $this->engine()->cancel($this->runsOf('e2e-delete-workflow')[0], $this->adminId);
        $this->assertTrue($this->workflow('e2e-delete-workflow')->softDelete());
        $gated = $this->engine()->launch($this->workflow('e2e-gate-continue-workflow'), $this->adminId);
        $request = $this->requestOf($this->onlyExecutionOf($gated));
        $placeholderId = (int)$request->job_id;
        $added = $this->createWorkflowStep((int)$this->workflow('e2e-approval-steps-workflow')->id, 5, WorkflowStep::TYPE_APPROVAL, null, $ruleId);
        $addedPause = $this->createWorkflowStep((int)$this->workflow('e2e-operator-steps-workflow')->id, 20, WorkflowStep::TYPE_PAUSE);
        $steps = $this->workflow('e2e-steps-workflow');
        $steps->trigger_token = hash('sha256', 'e2e-token');
        $steps->trigger_token_created_by = $this->adminId;
        $steps->save(false);
        WorkflowStep::updateAll(['on_failure_step_id' => null], ['workflow_template_id' => $steps->id]);
        ApprovalRule::updateAll(['approver_config' => (string)json_encode(['user_ids' => [$this->adminId]]), 'required_approvals' => 2, 'timeout_minutes' => 5], ['id' => $ruleId]);
        $gatedTemplate = $this->gatedTemplate();
        $gatedTemplateId = (int)$gatedTemplate->id;
        $gateGroupId = (int)$gatedTemplate->runner_group_id;
        JobTemplate::updateAll(['approval_rule_id' => null, 'deleted_at' => time(), 'trigger_token' => hash('sha256', 'e2e-token')], ['id' => $gatedTemplateId]);
        $soloRuleId = (int)$this->rule('e2e-solo-gate-rule')->id;
        ApprovalRule::updateAll(['approver_config' => (string)json_encode(['user_ids' => [$this->adminId, $this->operatorId]])], ['id' => $soloRuleId]);
        // The spec gave the template a rule; it failed before it took the rule away again.
        $lateGateId = (int)$this->lateGateTemplate()->id;
        JobTemplate::updateAll(['approval_rule_id' => $ruleId, 'deleted_at' => time()], ['id' => $lateGateId]);

        $this->seed();

        foreach (self::FIXTURES as $index => $name) {
            $this->assertSame(1, (int)WorkflowTemplate::findWithDeleted()->where(['name' => $name])->count(), $name);
            $workflow = $this->workflow($name);
            $this->assertSame($ids[$index], (int)$workflow->id, "{$name} is restored in place");
            $this->assertNull($workflow->deleted_at, $name);
            $this->assertNull($workflow->trigger_token, $name);
            $this->assertNull($workflow->trigger_token_created_by, $name);
        }
        $this->assertSame('e2e-steps-cleanup', $this->stepsOf('e2e-steps-workflow')[0][6], 'the route is back');
        $this->assertSame(['e2e-approval-steps-job'], array_column($this->stepsOf('e2e-approval-steps-workflow'), 0));
        $this->assertNull(WorkflowStep::findOne($added->id));
        $this->assertSame(['e2e-operator-steps-job'], array_column($this->stepsOf('e2e-operator-steps-workflow'), 0));
        $this->assertNull(WorkflowStep::findOne($addedPause->id));
        $this->assertSame([], $this->runsOf('e2e-gate-continue-workflow'));
        $this->assertNull(Job::findOne($placeholderId), 'the placeholder job of the removed run went with it');
        $this->assertNull(ApprovalRequest::findOne($request->id), 'and its approval request');
        foreach ([['e2e-locked-workflow', 'e2e-locked-pause'], ['e2e-delete-workflow', 'e2e-delete-pause']] as [$name, $pause]) {
            $run = $this->assertPausedRun($name, $pause);
            $this->assertNotContains((int)$run->id, $oldRuns, "{$name} has a new paused run");
        }
        $rule = $this->gateRule();
        $this->assertSame($ruleId, (int)$rule->id);
        $this->assertSame([$this->adminId, $this->operatorId], $rule->getApproverUserIds());
        $this->assertSame(1, (int)$rule->required_approvals);
        $this->assertNull($rule->timeout_minutes);
        $this->assertSame(1, (int)ApprovalRule::find()->where(['name' => 'e2e-gate-rule'])->count());
        $this->assertSame($gatedTemplateId, (int)$this->assertGatedTemplate($ruleId)->id, 'restored in place');
        $this->assertSame($gateGroupId, (int)$this->gatedTemplate()->runner_group_id);
        $soloRule = $this->rule('e2e-solo-gate-rule');
        $this->assertSame($soloRuleId, (int)$soloRule->id);
        $this->assertSame([$this->adminId], $soloRule->getApproverUserIds(), 'e2e-admin is its only approver again');
        $this->assertSame($lateGateId, (int)$this->assertLateGateTemplate()->id, 'restored in place, without a rule');
        $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => 'e2e-late-gate-template'])->count());
        $this->assertCount(2, $this->log);
    }

    /**
     * The global teardown (php yii e2e/teardown) removes every fixture, with
     * the runs, jobs and approval requests the specs leave behind: approval
     * rules restrict the delete of the steps and templates that use them.
     */
    public function testTheE2eTeardownRemovesEveryFixtureAndWhatTheSpecsLeft(): void
    {
        // The teardown takes the rows of the users named like E2E users.
        User::updateAll(['username' => 'e2e-admin'], ['id' => $this->adminId]);
        JobTemplate::updateAll(['name' => 'e2e-template'], ['id' => $this->templateId]);
        $this->seed();
        $runIds = array_map(static fn (WorkflowJob $run): int => (int)$run->id, $this->runsOf('e2e-locked-workflow'));
        $waiting = $this->engine()->launch($this->workflow('e2e-cancel-workflow'), $this->adminId);
        $request = $this->requestOf($this->onlyExecutionOf($waiting));
        $trigger = $this->workflow('e2e-trigger-vars-workflow');
        (new TriggerTokenService())->generate($trigger, $this->adminId);
        $triggered = $this->engine()->launch($trigger, $this->adminId, ['extra_vars' => ['e2e_marker' => 'teardown']], 'trigger');
        $triggeredJobId = (int)$this->onlyExecutionOf($triggered)->job_id;
        $gatedRun = $this->engine()->launch($this->workflow('e2e-gated-job-workflow'), $this->adminId);
        $gatedJobId = (int)$this->onlyExecutionOf($gatedRun)->job_id;
        $runIds = array_merge($runIds, [(int)$waiting->id, (int)$triggered->id, (int)$gatedRun->id]);

        $problems = (new E2eTeardownHelper('e2e-', static function (string $msg): void {
        }))->teardownDatabase();

        $this->assertSame([], $problems);
        foreach (self::FIXTURES as $name) {
            $this->assertNull(WorkflowTemplate::findWithDeleted()->where(['name' => $name])->one(), $name);
        }
        $this->assertNull(ApprovalRule::findOne(['name' => 'e2e-gate-rule']));
        $this->assertNull(ApprovalRule::findOne(['name' => 'e2e-solo-gate-rule']));
        $this->assertNull(JobTemplate::findWithDeleted()->where(['name' => 'e2e-gated-job-template'])->one());
        $this->assertNull(JobTemplate::findWithDeleted()->where(['name' => 'e2e-late-gate-template'])->one());
        $this->assertNull(RunnerGroup::findOne(['name' => 'e2e-gate-runner-group']));
        $this->assertNull(Job::findOne($gatedJobId), 'the job of the gated job step');
        $this->assertSame(0, (int)WorkflowJob::find()->where(['id' => $runIds])->count());
        $this->assertSame(0, (int)WorkflowJobStep::find()->where(['workflow_job_id' => $runIds])->count());
        $this->assertNull(Job::findOne($request->job_id), 'the placeholder job');
        $this->assertNull(ApprovalRequest::findOne($request->id));
        $this->assertNull(Job::findOne($triggeredJobId), 'the job a trigger launched');
    }

    /**
     * What workflow-templates/rbac.spec.ts does with e2e-late-gate-workflow:
     * its steps are e2e-operator's to change until e2e-admin gives the job
     * template of its step an approval rule; from then on only admins may
     * change them, until the rule is gone again.
     */
    public function testARuleOnTheLateGateTemplateLocksItsWorkflowForE2eOperator(): void
    {
        $this->seed();
        $workflow = $this->workflow('e2e-late-gate-workflow');
        $template = $this->lateGateTemplate();
        $policy = \Yii::$app->get('approvalRuleAssignmentPolicy');
        $this->assertInstanceOf(ApprovalRuleAssignmentPolicy::class, $policy);
        $this->assertNull($policy->workflowStepsChangeRefusal($this->operatorId, $workflow, false), 'no rule yet');

        JobTemplate::updateAll(['approval_rule_id' => (int)$this->gateRule()->id], ['id' => $template->id]);

        $this->assertSame(
            ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED,
            $policy->workflowStepsChangeRefusal($this->operatorId, $workflow, false)
        );
        $this->assertNull($policy->workflowStepsChangeRefusal($this->adminId, $workflow, false), 'e2e-admin may');

        JobTemplate::updateAll(['approval_rule_id' => null], ['id' => $template->id]);

        $this->assertNull($policy->workflowStepsChangeRefusal($this->operatorId, $workflow, false), 'the rule is gone');
    }

    public function testWithoutTheSecretTemplateTheTriggerFixtureIsSkipped(): void
    {
        JobTemplate::updateAll(['name' => 'not-the-e2e-secret-template'], ['id' => $this->secretTemplateId]);

        $this->seed();

        $this->assertNull(WorkflowTemplate::findOne(['name' => 'e2e-trigger-vars-workflow']));
        $this->assertSame("  Workflow gate fixture e2e-trigger-vars-workflow skipped: job template e2e-secret-template missing.\n", $this->log[0]);
        $this->assertCount(2, $this->log);
        $this->assertStringNotContainsString('e2e-trigger-vars-workflow', $this->log[1]);
        $this->assertStringEndsWith(
            ', e2e-empty-workflow, e2e-gate-rule, e2e-gate-continue-workflow, e2e-cancel-workflow, '
            . 'e2e-gated-job-template, e2e-gated-job-workflow, e2e-solo-gate-rule, e2e-solo-gate-workflow, '
            . "e2e-late-gate-template, e2e-late-gate-workflow.\n",
            $this->log[1]
        );
        $this->assertNotNull(WorkflowTemplate::findOne(['name' => 'e2e-cancel-workflow']));
    }

    /**
     * Without e2e-operator, e2e-admin would be the only approver, and every
     * launch e2e-admin made would be refused: no gate fixtures then.
     */
    public function testWithoutTheSecondApproverTheGateFixturesAreSkipped(): void
    {
        User::updateAll(['username' => 'not-e2e-operator-' . uniqid()], ['id' => $this->operatorId]);

        $this->seed();

        $this->assertNull(ApprovalRule::findOne(['name' => 'e2e-gate-rule']));
        $this->assertNull(WorkflowTemplate::findOne(['name' => 'e2e-gate-continue-workflow']));
        $this->assertNull(WorkflowTemplate::findOne(['name' => 'e2e-cancel-workflow']));
        $this->assertNull(JobTemplate::findOne(['name' => 'e2e-gated-job-template']));
        $this->assertNull(WorkflowTemplate::findOne(['name' => 'e2e-gated-job-workflow']));
        $this->assertSame(
            '  Workflow gate fixtures e2e-gate-rule, e2e-gate-continue-workflow, e2e-cancel-workflow, e2e-gated-job-template, '
            . "e2e-gated-job-workflow skipped: user e2e-operator missing.\n",
            $this->log[0]
        );
        $this->assertCount(2, $this->log);
        $this->assertStringEndsWith(
            ', e2e-empty-workflow, e2e-trigger-vars-workflow, e2e-solo-gate-rule, e2e-solo-gate-workflow, '
            . "e2e-late-gate-template, e2e-late-gate-workflow.\n",
            $this->log[1]
        );
        $this->assertNotNull(WorkflowTemplate::findOne(['name' => 'e2e-locked-workflow']));
        $this->assertNotNull(WorkflowTemplate::findOne(['name' => 'e2e-solo-gate-workflow']), 'its rule needs no second approver');
        $this->assertNotNull(WorkflowTemplate::findOne(['name' => 'e2e-late-gate-workflow']), 'no rule, no approver');
        $this->assertNull($this->lateGateTemplate()->approval_rule_id);
    }

    public function testAJobTemplateThatDoesNotExistIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Job template #999999999 does not exist.');

        (new E2eWorkflowGateSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId, 999999999);
    }

    private function seed(): void
    {
        (new E2eWorkflowGateSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId, $this->templateId);
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

    private function workflow(string $name): WorkflowTemplate
    {
        $workflow = WorkflowTemplate::findOne(['name' => $name]);
        $this->assertNotNull($workflow, $name);

        return $workflow;
    }

    /**
     * The steps of a fixture by step order: name, order, type, job template,
     * approval rule, and the names of the steps its On Success, On Failure
     * and Always branches lead to.
     *
     * @return list<array{0: string, 1: int, 2: string, 3: int|null, 4: int|null, 5: string|null, 6: string|null, 7: string|null}>
     */
    private function stepsOf(string $workflowName): array
    {
        $steps = WorkflowStep::find()
            ->where(['workflow_template_id' => $this->workflow($workflowName)->id])
            ->orderBy(['step_order' => SORT_ASC])
            ->all();
        $names = [];
        foreach ($steps as $step) {
            $names[(int)$step->id] = (string)$step->name;
        }
        $target = static fn (?int $id): ?string => $id === null ? null : ($names[$id] ?? "#{$id}");

        return array_map(static fn (WorkflowStep $s): array => [
            (string)$s->name,
            (int)$s->step_order,
            (string)$s->step_type,
            $s->job_template_id === null ? null : (int)$s->job_template_id,
            $s->approval_rule_id === null ? null : (int)$s->approval_rule_id,
            $target($s->on_success_step_id === null ? null : (int)$s->on_success_step_id),
            $target($s->on_failure_step_id === null ? null : (int)$s->on_failure_step_id),
            $target($s->on_always_step_id === null ? null : (int)$s->on_always_step_id),
        ], $steps);
    }

    /**
     * @return list<WorkflowJob>
     */
    private function runsOf(string $workflowName): array
    {
        return WorkflowJob::find()->where(['workflow_template_id' => $this->workflow($workflowName)->id])->orderBy(['id' => SORT_ASC])->all();
    }

    /**
     * The only run of the fixture: e2e-admin started it, and it is paused
     * at $pauseStep, its only execution, which the run page highlights.
     */
    private function assertPausedRun(string $workflowName, string $pauseStep): WorkflowJob
    {
        $runs = $this->runsOf($workflowName);
        $this->assertCount(1, $runs, $workflowName);
        $run = $runs[0];
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $run->status);
        $this->assertSame($this->adminId, (int)$run->launched_by);
        $this->assertNotNull($run->started_at);
        $this->assertNull($run->finished_at);
        $execution = $this->onlyExecutionOf($run);
        $this->assertSame($pauseStep, $execution->workflowStep?->name);
        $this->assertSame((int)$execution->workflow_step_id, (int)$run->current_step_id);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $execution->status);
        $this->assertTrue($execution->isPauseStep());
        $this->assertSame($pauseStep, $execution->step_name, 'the run keeps the name of its step');
        $this->assertSame(WorkflowStep::TYPE_PAUSE, $execution->step_type);
        $this->assertNull($execution->job_id);
        $this->assertNull($execution->finished_at);

        return $run;
    }

    private function onlyExecutionOf(WorkflowJob $run): WorkflowJobStep
    {
        $executions = WorkflowJobStep::findAll(['workflow_job_id' => $run->id]);
        $this->assertCount(1, $executions);

        return $executions[0];
    }

    private function requestOf(WorkflowJobStep $execution): ApprovalRequest
    {
        $this->assertNotNull($execution->job_id, 'the approval step has its placeholder job');
        $request = ApprovalRequest::findOne(['job_id' => $execution->job_id]);
        $this->assertNotNull($request);

        return $request;
    }

    private function gatedTemplate(): JobTemplate
    {
        $template = JobTemplate::findWithDeleted()->where(['name' => 'e2e-gated-job-template'])->one();
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template;
    }

    /**
     * e2e-gated-job-template: gated by the rule $ruleId, on the project,
     * inventory and playbook of the job template the seed was given, in
     * e2e-gate-runner-group, which no runner serves.
     */
    private function assertGatedTemplate(int $ruleId): JobTemplate
    {
        $template = $this->gatedTemplate();
        $source = JobTemplate::findOne($this->templateId);
        $this->assertNotNull($source);
        $this->assertSame($ruleId, (int)$template->approval_rule_id);
        $this->assertSame((int)$source->project_id, (int)$template->project_id);
        $this->assertSame((int)$source->inventory_id, (int)$template->inventory_id);
        $this->assertSame((string)$source->playbook, $template->playbook);
        $this->assertNull($template->deleted_at);
        $this->assertNull($template->trigger_token);
        $this->assertSame($this->adminId, (int)$template->created_by);
        $group = RunnerGroup::findOne($template->runner_group_id);
        $this->assertNotNull($group);
        $this->assertSame('e2e-gate-runner-group', $group->name);
        $this->assertSame([], Runner::findAll(['runner_group_id' => $group->id]), 'no runner serves its group');

        return $template;
    }

    private function lateGateTemplate(): JobTemplate
    {
        $template = JobTemplate::findWithDeleted()->where(['name' => 'e2e-late-gate-template'])->one();
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template;
    }

    /**
     * e2e-late-gate-template: no approval rule, on the project, inventory and
     * playbook of the job template the seed was given, in
     * e2e-gate-runner-group, which no runner serves.
     */
    private function assertLateGateTemplate(): JobTemplate
    {
        $template = $this->lateGateTemplate();
        $source = JobTemplate::findOne($this->templateId);
        $this->assertNotNull($source);
        $this->assertNull($template->approval_rule_id);
        $this->assertSame((int)$source->project_id, (int)$template->project_id);
        $this->assertSame((int)$source->inventory_id, (int)$template->inventory_id);
        $this->assertSame((string)$source->playbook, $template->playbook);
        $this->assertNull($template->deleted_at);
        $this->assertSame($this->adminId, (int)$template->created_by);
        $this->assertSame((int)$this->gatedTemplate()->runner_group_id, (int)$template->runner_group_id, 'e2e-gate-runner-group');

        return $template;
    }

    private function gateRule(): ApprovalRule
    {
        return $this->rule('e2e-gate-rule');
    }

    private function rule(string $name): ApprovalRule
    {
        $rule = ApprovalRule::findOne(['name' => $name]);
        $this->assertNotNull($rule, $name);

        return $rule;
    }

    private function engine(): WorkflowExecutionService
    {
        $engine = \Yii::$app->get('workflowExecutionService');
        $this->assertInstanceOf(WorkflowExecutionService::class, $engine);

        return $engine;
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
}
