<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ProjectAccessChecker;
use app\services\WorkflowAccessChecker;
use app\services\WorkflowAccessDeniedException;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Regression: workflows ignored team scoping. Any operator could build and
 * launch a workflow from another team's job templates, and see every team's
 * workflows, runs and approval requests. A workflow now belongs to the
 * projects of its job steps.
 */
class WorkflowAccessCheckerTest extends DbTestCase
{
    use TeamScopeFixtures;

    private function checker(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        return $checker;
    }

    /**
     * IDs of the given workflows that pass the filter for $userId.
     *
     * @param list<WorkflowTemplate> $workflows
     * @return list<int>
     */
    private function visibleIds(?int $userId, array $workflows, bool $operate = false): array
    {
        $query = WorkflowTemplate::find()
            ->select('workflow_template.id')
            ->andWhere(['workflow_template.id' => array_map(static fn (WorkflowTemplate $w): int => $w->id, $workflows)])
            ->orderBy(['workflow_template.id' => SORT_ASC]);
        $filter = $this->checker()->buildWorkflowTemplateFilter($userId, 'workflow_template.id', $operate);
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        return array_map('intval', $query->column());
    }

    // -- Workflow template visibility ------------------------------------------

    public function testAMemberSeesWorkflowsOfVisibleProjectsOnly(): void
    {
        $s = $this->teamScope();
        $own = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $viewed = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $open = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $foreign = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);
        $mixed = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id);
        $all = [$own, $viewed, $open, $foreign, $mixed];

        $this->assertSame([$own->id, $viewed->id, $open->id], $this->visibleIds($s['member']->id, $all));
        $this->assertSame([$own->id, $open->id], $this->visibleIds($s['member']->id, $all, true), 'operate needs the operator role');
        $this->assertTrue($this->checker()->canViewWorkflowTemplate($s['member']->id, $viewed->id));
        $this->assertFalse($this->checker()->canOperateWorkflowTemplate($s['member']->id, $viewed->id));
        $this->assertFalse($this->checker()->canViewWorkflowTemplate($s['member']->id, $mixed->id), 'one foreign step hides the workflow');
        $this->assertTrue($this->checker()->canOperateWorkflowTemplate($s['member']->id, $own->id));
    }

    public function testAdminsSeeEverythingAndGuestsNothing(): void
    {
        $s = $this->teamScope();
        $foreign = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);

        $this->assertNull($this->checker()->buildWorkflowTemplateFilter($s['admin']->id, 'workflow_template.id', true));
        $this->assertSame([$foreign->id], $this->visibleIds($s['admin']->id, [$foreign], true));
        $this->assertSame(ProjectAccessChecker::DENY_ALL, $this->checker()->buildWorkflowTemplateFilter(null, 'workflow_template.id'));
        $this->assertSame([], $this->visibleIds(null, [$foreign]));
    }

    public function testWithoutRestrictedProjectsNothingIsFiltered(): void
    {
        $user = $this->createUserWithRole('no_teams', 'operator');
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($this->createProject($user->id)->id, $this->createInventory($user->id)->id, $group->id, $user->id);
        $workflow = $this->createWorkflowWithJobSteps($user->id, $template->id);

        $this->assertNull($this->checker()->buildWorkflowTemplateFilter($user->id, 'workflow_template.id', true));
        $this->assertTrue($this->checker()->canOperateWorkflowTemplate($user->id, $workflow->id));
    }

    public function testApprovalAndPauseStepsDoNotRestrict(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_APPROVAL, null, $this->createApprovalRule($s['admin']->id)->id);

        $this->assertSame([$workflow->id], $this->visibleIds($s['member']->id, [$workflow], true));
        $this->assertSame([], $this->checker()->deniedJobTemplateIds($s['member']->id, $workflow->id));
    }

    public function testAStepWithAPurgedTemplateFailsClosed(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_JOB, 987654321);

        $this->assertSame([], $this->visibleIds($s['member']->id, [$workflow]));
        $this->assertSame([987654321], $this->checker()->deniedJobTemplateIds($s['member']->id, $workflow->id, false));
        $this->assertSame([$workflow->id], $this->visibleIds($s['admin']->id, [$workflow]), 'admins still see it to repair it');
    }

    public function testASoftDeletedTemplateKeepsItsProject(): void
    {
        $s = $this->teamScope();
        $ownWorkflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $foreignWorkflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);
        $s['own']->softDelete();
        $s['foreign']->softDelete();

        $this->assertSame([$ownWorkflow->id], $this->visibleIds($s['member']->id, [$ownWorkflow, $foreignWorkflow]));
    }

    public function testDeniedJobTemplateIdsListsOnlyTheOffendingTemplates(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['viewed']->id, $s['foreign']->id, $s['open']->id);

        $this->assertEqualsCanonicalizing([$s['viewed']->id, $s['foreign']->id], $this->checker()->deniedJobTemplateIds($s['member']->id, $workflow->id));
        $this->assertSame([$s['foreign']->id], $this->checker()->deniedJobTemplateIds($s['member']->id, $workflow->id, false));
        $this->assertSame([], $this->checker()->deniedJobTemplateIds($s['admin']->id, $workflow->id));
    }

    // -- Step templates and dropdown -------------------------------------------

    public function testClassifyStepTemplateIdsSeparatesMissingFromForbidden(): void
    {
        $s = $this->teamScope();
        $deleted = $this->createJobTemplate((int)$s['own']->project_id, (int)$s['own']->inventory_id, (int)$s['own']->runner_group_id, $s['admin']->id);
        $deleted->softDelete();

        $result = $this->checker()->classifyStepTemplateIds($s['member']->id, [
            $s['own']->id, $s['open']->id, $s['viewed']->id, $s['foreign']->id, $deleted->id, 987654321, $s['own']->id,
        ]);

        $this->assertEqualsCanonicalizing([$s['foreign']->id, $deleted->id, 987654321], $result['missing'], 'invisible counts as not existing');
        $this->assertSame([$s['viewed']->id], $result['forbidden']);
        $this->assertSame(['missing' => [], 'forbidden' => []], $this->checker()->classifyStepTemplateIds($s['member']->id, []));
        $this->assertSame(
            ['missing' => [987654321], 'forbidden' => []],
            $this->checker()->classifyStepTemplateIds($s['admin']->id, [$s['foreign']->id, 987654321])
        );
    }

    public function testJobTemplateOptionsListOnlyOperableTemplates(): void
    {
        $s = $this->teamScope();

        $options = $this->checker()->jobTemplateOptions($s['member']->id);

        $this->assertArrayHasKey($s['own']->id, $options);
        $this->assertArrayHasKey($s['open']->id, $options);
        $this->assertArrayNotHasKey($s['viewed']->id, $options);
        $this->assertArrayNotHasKey($s['foreign']->id, $options);
        $this->assertSame($s['own']->name, $options[$s['own']->id]);
        $this->assertArrayHasKey($s['foreign']->id, $this->checker()->jobTemplateOptions($s['admin']->id));
    }

    // -- Identities without a session ------------------------------------------

    public function testCanDispatchNeedsAnActiveUserWithWorkflowLaunchAndOperateAccess(): void
    {
        $s = $this->teamScope();

        $this->assertTrue($this->checker()->canDispatch($s['member']->id, $s['own']));
        $this->assertTrue($this->checker()->canDispatch($s['member']->id, $s['open']));
        $this->assertFalse($this->checker()->canDispatch($s['member']->id, $s['viewed']));
        $this->assertFalse($this->checker()->canDispatch($s['member']->id, $s['foreign']));
        $this->assertTrue($this->checker()->canDispatch($s['admin']->id, $s['foreign']));

        $viewer = $this->createUserWithRole('dispatch_viewer', 'viewer');
        $this->assertFalse($this->checker()->canDispatch($viewer->id, $s['open']), 'no workflow.launch');

        $s['member']->status = User::STATUS_INACTIVE;
        $s['member']->save(false);
        $this->assertFalse($this->checker()->canDispatch($s['member']->id, $s['own']), 'disabled account');
    }

    public function testSuperadminsHoldEveryPermissionWithoutARole(): void
    {
        $root = $this->createUser('root');
        $root->is_superadmin = true;
        $root->save(false);

        $this->assertTrue($this->checker()->isActiveWithPermission($root->id, 'workflow.launch'));
        $this->assertFalse($this->checker()->isActiveWithPermission($this->createUser('nobody')->id, 'workflow.launch'));
        $this->assertFalse($this->checker()->isActiveWithPermission(987654321, 'workflow.launch'), 'unknown user');
    }

    // -- Launch and operate assertions -----------------------------------------

    /**
     * Regression: the refusal named the job templates of a workflow the user
     * may not even see, so launching probed other teams' workflows for their
     * job template IDs. Only the audit entry names them now.
     */
    public function testAssertMayLaunchRefusesAndAuditsAForeignStepWithoutNamingIt(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id);

        try {
            $this->checker()->assertMayLaunch($s['member']->id, $workflow, 'trigger');
            $this->fail('expected a refusal');
        } catch (WorkflowAccessDeniedException $e) {
            $this->assertSame([], $e->jobTemplateIds);
            $this->assertSame('You may not launch this workflow.', $e->getMessage());
        }

        /** @var AuditLog|null $audit */
        $audit = AuditLog::find()->where(['action' => AuditLog::ACTION_WORKFLOW_LAUNCH_DENIED, 'object_id' => $workflow->id])->one();
        $this->assertNotNull($audit);
        $this->assertSame($s['member']->id, (int)$audit->user_id);
        $this->assertSame('workflow_template', $audit->object_type);
        $this->assertSame(
            ['source' => 'trigger', 'job_template_ids' => [$s['foreign']->id]],
            json_decode((string)$audit->metadata, true),
            'the audit log keeps the details for operators'
        );
    }

    public function testAssertMayLaunchNamesTheTemplatesToAUserWhoMaySeeTheWorkflow(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['viewed']->id);

        try {
            $this->checker()->assertMayLaunch($s['member']->id, $workflow, 'api');
            $this->fail('expected a refusal');
        } catch (WorkflowAccessDeniedException $e) {
            $this->assertSame([$s['viewed']->id], $e->jobTemplateIds);
            $this->assertSame("You may not launch job template(s) #{$s['viewed']->id} of this workflow.", $e->getMessage());
        }
    }

    public function testAssertMayLaunchRefusesAUserWhoMayNotLaunchWorkflows(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $s['member']->status = User::STATUS_INACTIVE;
        $s['member']->save(false);

        $this->expectException(WorkflowAccessDeniedException::class);
        $this->expectExceptionMessage('You may not launch workflows.');
        $this->checker()->assertMayLaunch($s['member']->id, $workflow, 'web');
    }

    public function testAssertMayLaunchPassesForTheOwningTeam(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);

        $this->checker()->assertMayLaunch($s['member']->id, $workflow, 'web');

        $this->assertFalse(AuditLog::find()->where(['action' => AuditLog::ACTION_WORKFLOW_LAUNCH_DENIED, 'object_id' => $workflow->id])->exists());
    }

    public function testAssertMayOperate(): void
    {
        $s = $this->teamScope();
        $viewed = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $own = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);

        $this->checker()->assertMayOperate($s['member']->id, $own->id);
        try {
            $this->checker()->assertMayOperate($s['member']->id, $viewed->id);
            $this->fail('expected a refusal');
        } catch (WorkflowAccessDeniedException $e) {
            $this->assertSame([$s['viewed']->id], $e->jobTemplateIds, 'a user who sees the workflow learns why');
            $this->assertSame("You may not operate job template(s) #{$s['viewed']->id} of this workflow.", $e->getMessage());
        }
    }

    /**
     * Regression: refusing to operate a workflow the user may not see named
     * its job templates, those of another team.
     */
    public function testAssertMayOperateNamesNoTemplatesToAUserWhoMayNotSeeTheWorkflow(): void
    {
        $s = $this->teamScope();
        $own = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);

        try {
            $this->checker()->assertMayOperate($s['outsider']->id, $own->id);
            $this->fail('expected a refusal');
        } catch (WorkflowAccessDeniedException $e) {
            $this->assertSame([], $e->jobTemplateIds);
            $this->assertSame('You may not operate this workflow.', $e->getMessage());
        }
    }

    // -- Jobs -------------------------------------------------------------------

    public function testJobsWithATemplateFollowItsProject(): void
    {
        $s = $this->teamScope();
        $viewedJob = $this->createJob($s['viewed']->id, $s['admin']->id);
        $foreignJob = $this->createJob($s['foreign']->id, $s['admin']->id);

        $this->assertTrue($this->checker()->canAccessJob($s['member']->id, $viewedJob, false));
        $this->assertFalse($this->checker()->canAccessJob($s['member']->id, $viewedJob, true));
        $this->assertFalse($this->checker()->canAccessJob($s['member']->id, $foreignJob, false));
        $this->assertTrue($this->checker()->canAccessJob($s['admin']->id, $foreignJob, true));
    }

    /**
     * Regression: a workflow approval step's placeholder job has no template
     * and counted as global, so any operator could cancel another team's
     * placeholder and push its workflow on.
     */
    public function testPlaceholderJobsFollowTheirWorkflow(): void
    {
        $s = $this->teamScope();
        $foreignPlaceholder = $this->placeholderJobOf($this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id), $s['admin']->id);
        $ownPlaceholder = $this->placeholderJobOf($this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id), $s['admin']->id);

        $this->assertFalse($this->checker()->canAccessJob($s['member']->id, $foreignPlaceholder, true));
        $this->assertFalse($this->checker()->canAccessJob($s['member']->id, $foreignPlaceholder, false));
        $this->assertTrue($this->checker()->canAccessJob($s['member']->id, $ownPlaceholder, true));
        $this->assertTrue($this->checker()->canAccessJob($s['outsider']->id, $foreignPlaceholder, true));
    }

    public function testTemplateLessJobsOutsideWorkflowsAreForUnrestrictedUsersOnly(): void
    {
        $s = $this->teamScope();
        $orphan = $this->createJob($s['open']->id, $s['admin']->id);
        $orphan->job_template_id = null;
        $orphan->save(false);

        $this->assertFalse($this->checker()->canAccessJob($s['member']->id, $orphan, false));
        $this->assertTrue($this->checker()->canAccessJob($s['admin']->id, $orphan, true));
    }

    // -- Approval requests -----------------------------------------------------

    public function testApprovalRequestsFollowTheirJobOrWorkflow(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule($s['admin']->id);
        $ownRequest = $this->approvalRequestFor($this->createJob($s['own']->id, $s['admin']->id, Job::STATUS_PENDING_APPROVAL), $rule->id);
        $foreignRequest = $this->approvalRequestFor($this->createJob($s['foreign']->id, $s['admin']->id, Job::STATUS_PENDING_APPROVAL), $rule->id);
        $ownStepRequest = $this->approvalRequestFor(
            $this->placeholderJobOf($this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id), $s['admin']->id),
            $rule->id
        );
        $foreignStepRequest = $this->approvalRequestFor(
            $this->placeholderJobOf($this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id), $s['admin']->id),
            $rule->id
        );

        $filter = $this->checker()->buildApprovalRequestFilter($s['member']->id);
        $this->assertNotNull($filter);
        $visible = array_map('intval', ApprovalRequest::find()
            ->select('approval_request.id')
            ->where(['approval_request.id' => [$ownRequest->id, $foreignRequest->id, $ownStepRequest->id, $foreignStepRequest->id]])
            ->andWhere($filter)
            ->orderBy(['approval_request.id' => SORT_ASC])
            ->column());

        $this->assertSame([$ownRequest->id, $ownStepRequest->id], $visible);
        $this->assertTrue($this->checker()->canViewApprovalRequest($s['member']->id, $ownStepRequest));
        $this->assertFalse($this->checker()->canViewApprovalRequest($s['member']->id, $foreignRequest));
        $this->assertTrue($this->checker()->canViewApprovalRequest($s['admin']->id, $foreignStepRequest));
        $this->assertNull($this->checker()->buildApprovalRequestFilter($s['admin']->id));
        $this->assertSame(ProjectAccessChecker::DENY_ALL, $this->checker()->buildApprovalRequestFilter(null));
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * A running workflow job whose first step is an approval placeholder job
     * (job_template_id NULL), the way WorkflowExecutionService creates it.
     */
    private function placeholderJobOf(WorkflowTemplate $workflow, int $launchedBy): Job
    {
        $wfJob = $this->createWorkflowJob($workflow->id, $launchedBy);
        $step = $this->createWorkflowStep($workflow->id, 99, WorkflowStep::TYPE_APPROVAL);

        $job = new Job();
        $job->job_template_id = null;
        $job->launched_by = $launchedBy;
        $job->status = Job::STATUS_PENDING_APPROVAL;
        $job->timeout_minutes = 0;
        $job->has_changes = 0;
        $job->created_at = time();
        $job->updated_at = time();
        $job->save(false);

        $wjs = new WorkflowJobStep();
        $wjs->workflow_job_id = $wfJob->id;
        $wjs->workflow_step_id = $step->id;
        $wjs->job_id = $job->id;
        $wjs->status = WorkflowJobStep::STATUS_RUNNING;
        $wjs->created_at = time();
        $wjs->updated_at = time();
        $wjs->save(false);

        return $job;
    }

    private function approvalRequestFor(Job $job, int $ruleId): ApprovalRequest
    {
        $request = new ApprovalRequest();
        $request->job_id = $job->id;
        $request->approval_rule_id = $ruleId;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);
        return $request;
    }
}
