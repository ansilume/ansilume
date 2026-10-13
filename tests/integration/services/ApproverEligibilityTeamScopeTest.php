<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\TeamMember;
use app\models\User;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApproverEligibility;
use app\services\WorkflowAccessChecker;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Team scoping of approvers: only those who may see what is to be approved
 * count. For a job template that is its project, for a workflow approval
 * step the workflow (the projects of all its job steps). Superadmins and
 * admins see everything. The bulk answer must equal the access checks made
 * user by user, for every kind of request.
 */
class ApproverEligibilityTeamScopeTest extends DbTestCase
{
    use TeamScopeFixtures;

    private const DECIDE = 'approval.decide';

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    private int $member;
    private int $outsider;
    private int $admin;
    private int $superadmin;
    /** An operator in no team: sees the projects no team is restricted to. */
    private int $teamless;
    /** In the member's team with the viewer role: sees, but may not decide. */
    private int $teamViewer;
    private int $inactiveMember;
    private int $launcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->teamScope();
        $this->member = (int)$this->s['member']->id;
        $this->outsider = (int)$this->s['outsider']->id;
        $this->admin = (int)$this->s['admin']->id;
        $superadmin = $this->createUser('superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->superadmin = (int)$superadmin->id;
        $this->teamless = (int)$this->createUserWithRole('teamless', 'operator')->id;
        $teamId = (int)TeamMember::find()->select('team_id')->where(['user_id' => $this->member])->scalar();
        $this->teamViewer = (int)$this->createUserWithRole('team-viewer', 'viewer')->id;
        $this->addTeamMember($teamId, $this->teamViewer);
        $inactive = $this->createUserWithRole('inactive-member', 'operator');
        $inactive->status = User::STATUS_INACTIVE;
        $inactive->save(false);
        $this->inactiveMember = (int)$inactive->id;
        $this->addTeamMember($teamId, $this->inactiveMember);
        $this->launcher = (int)$this->createUserWithRole('launcher', 'operator')->id;
    }

    public function testATemplatesRequestIsDecidedByTheApproversWhoMaySeeItsProject(): void
    {
        $expected = [
            'own' => [$this->member, $this->admin, $this->superadmin],
            'viewed' => [$this->member, $this->admin, $this->superadmin],
            'foreign' => [$this->outsider, $this->admin, $this->superadmin],
            'open' => [$this->member, $this->outsider, $this->admin, $this->superadmin, $this->teamless],
        ];
        foreach ($expected as $key => $eligible) {
            $request = $this->templateRequest($this->s[$key]);

            $this->assertSame($eligible, $this->eligibility()->eligibleForRequest($request), $key);
            $this->assertSame($eligible, $this->checkedOneByOne($request), $key);
        }
    }

    public function testAJobLaunchCountsOnlyTheApproversWhoMaySeeTheTemplatesProject(): void
    {
        $rule = $this->rule(4);

        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_JOB, '"' . $rule->name . '"', 4, 3),
            $this->eligibility()->jobLaunchRefusal($rule, $this->s['own'], $this->launcher)
        );
        $this->assertNull($this->eligibility()->jobLaunchRefusal($rule, $this->s['open'], $this->launcher));
    }

    public function testAWorkflowApprovalStepIsDecidedByTheApproversWhoMaySeeTheWorkflow(): void
    {
        $cases = [
            'own and viewed' => [[$this->s['own'], $this->s['viewed']], [$this->member, $this->admin, $this->superadmin]],
            'own and foreign' => [[$this->s['own'], $this->s['foreign']], [$this->admin, $this->superadmin]],
            'open only' => [[$this->s['open']], [$this->member, $this->outsider, $this->admin, $this->superadmin, $this->teamless]],
            'approval only' => [[], [$this->member, $this->outsider, $this->admin, $this->superadmin, $this->teamless]],
        ];
        foreach ($cases as $case => [$templates, $eligible]) {
            $workflow = $this->workflowOf(...$templates);
            $request = $this->placeholderRequest($workflow);
            $run = $this->createWorkflowJob((int)$workflow->id, $this->launcher);
            $count = count($eligible);

            $this->assertSame($eligible, $this->eligibility()->eligibleForRequest($request), $case);
            $this->assertSame($eligible, $this->checkedOneByOne($request), $case);
            $this->assertNull($this->eligibility()->workflowStepRefusal($this->rule($count), $run), $case);
            $tooMany = $this->rule($count + 1);
            $this->assertSame(
                sprintf(ApproverEligibility::REFUSAL_WORKFLOW_STEP, '"' . $tooMany->name . '"', $count + 1, $this->launcher, $count),
                $this->eligibility()->workflowStepRefusal($tooMany, $run),
                $case
            );
        }
    }

    public function testAWorkflowWhoseJobStepLostItsTemplateIsLeftToUnrestrictedApprovers(): void
    {
        $workflow = $this->workflowOf($this->s['open']);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, 987654321);
        $request = $this->placeholderRequest($workflow);

        $this->assertSame([$this->admin, $this->superadmin], $this->eligibility()->eligibleForRequest($request));
        $this->assertSame([$this->admin, $this->superadmin], $this->checkedOneByOne($request));
    }

    public function testASoftDeletedTemplateKeepsItsProject(): void
    {
        $request = $this->templateRequest($this->s['own']);
        $workflowRequest = $this->placeholderRequest($this->workflowOf($this->s['own']));
        $this->s['own']->softDelete();

        foreach ([$request, $workflowRequest] as $deletedTemplateRequest) {
            $fresh = ApprovalRequest::findOne($deletedTemplateRequest->id);
            $this->assertNotNull($fresh);
            $this->assertSame([$this->member, $this->admin, $this->superadmin], $this->eligibility()->eligibleForRequest($fresh));
            $this->assertSame([$this->member, $this->admin, $this->superadmin], $this->checkedOneByOne($fresh));
        }
    }

    public function testAJobWithoutTemplateOutsideWorkflowsIsLeftToUnrestrictedApprovers(): void
    {
        $request = $this->orphanRequest();

        $this->assertSame([$this->admin, $this->superadmin], $this->eligibility()->eligibleForRequest($request));
        $this->assertSame([$this->admin, $this->superadmin], $this->checkedOneByOne($request));
    }

    /**
     * A request whose job, or whose job's template, cannot be found (the
     * foreign keys keep both from happening in the database, so only objects
     * built in memory get here) is seen by unrestricted users only, as
     * canViewApprovalRequest() decides.
     */
    public function testARequestWithoutAKnownJobOrTemplateIsLeftToUnrestrictedApprovers(): void
    {
        $withoutJob = new ApprovalRequest();
        $withoutJob->approval_rule_id = (int)$this->rule()->id;
        $withoutJob->status = ApprovalRequest::STATUS_PENDING;
        $danglingJob = new Job();
        $danglingJob->job_template_id = 987654321;
        $danglingJob->launched_by = $this->launcher;
        $withoutTemplate = new ApprovalRequest();
        $withoutTemplate->approval_rule_id = (int)$this->rule()->id;
        $withoutTemplate->status = ApprovalRequest::STATUS_PENDING;
        $withoutTemplate->populateRelation('job', $danglingJob);

        foreach (['without job' => $withoutJob, 'without template' => $withoutTemplate] as $case => $request) {
            $this->assertSame([$this->admin, $this->superadmin], $this->eligibility()->eligibleForRequest($request), $case);
            $this->assertSame([$this->admin, $this->superadmin], $this->checkedOneByOne($request), $case);
        }
    }

    /**
     * Each gate of a workflow counts with its own audience: a gated job
     * step's approvers need its template's project, an approval step's
     * approvers the whole workflow.
     */
    public function testAWorkflowLaunchCountsEachGateWithItsOwnAudience(): void
    {
        $this->s['foreign']->approval_rule_id = (int)$this->usersRule([$this->outsider, $this->launcher], 1)->id;
        $this->s['foreign']->save(false);
        $workflow = $this->workflowOf($this->s['own'], $this->s['foreign']);

        $this->assertNull(
            $this->eligibility()->workflowLaunchRefusal($workflow, $this->launcher),
            'the outsider may see the foreign project'
        );

        $approvalRule = $this->usersRule([$this->outsider, $this->member, $this->launcher], 1);
        $step = $this->createWorkflowStep((int)$workflow->id, 7, WorkflowStep::TYPE_APPROVAL, null, (int)$approvalRule->id);

        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_WORKFLOW_LAUNCH, '"' . $step->name . '"', '"' . $approvalRule->name . '"', 1, 0),
            $this->eligibility()->workflowLaunchRefusal($workflow, $this->launcher),
            'neither the outsider nor the member may see the whole workflow'
        );
    }

    public function testEligibilityMatchesTheAccessChecksOneByOneForEveryKindOfRequest(): void
    {
        $requests = [
            'own' => $this->templateRequest($this->s['own']),
            'viewed' => $this->templateRequest($this->s['viewed']),
            'foreign' => $this->templateRequest($this->s['foreign']),
            'open' => $this->templateRequest($this->s['open']),
            'workflow own+viewed' => $this->placeholderRequest($this->workflowOf($this->s['own'], $this->s['viewed'])),
            'workflow own+foreign' => $this->placeholderRequest($this->workflowOf($this->s['own'], $this->s['foreign'])),
            'workflow foreign' => $this->placeholderRequest($this->workflowOf($this->s['foreign'])),
            'workflow without job steps' => $this->placeholderRequest($this->workflowOf()),
            'orphan' => $this->orphanRequest(),
        ];
        $unlisted = (int)$this->createUserWithRole('unlisted', 'admin')->id;

        foreach ($requests as $case => $request) {
            $eligible = $this->eligibility()->eligibleForRequest($request);
            $this->assertSame($this->checkedOneByOne($request), $eligible, $case);
            foreach (array_merge($this->everybody(), [$unlisted]) as $userId) {
                $this->assertSame(
                    in_array($userId, $eligible, true),
                    $this->eligibility()->mayDecide($request, $userId),
                    "{$case}, user #{$userId}"
                );
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Every user the rules list, the launcher included.
     *
     * @return list<int>
     */
    private function everybody(): array
    {
        return [
            $this->member,
            $this->outsider,
            $this->admin,
            $this->superadmin,
            $this->teamless,
            $this->teamViewer,
            $this->inactiveMember,
            $this->launcher,
        ];
    }

    private function eligibility(): ApproverEligibility
    {
        /** @var ApproverEligibility $eligibility */
        $eligibility = \Yii::$app->get('approverEligibility');

        return $eligibility;
    }

    /**
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

    /**
     * A rule that lists everybody.
     */
    private function rule(int $required = 1): ApprovalRule
    {
        return $this->usersRule($this->everybody(), $required);
    }

    /**
     * @param list<int> $approverIds
     */
    private function usersRule(array $approverIds, int $required): ApprovalRule
    {
        return $this->createApprovalRule(
            $this->admin,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => $approverIds]),
            $required
        );
    }

    private function workflowOf(JobTemplate ...$templates): WorkflowTemplate
    {
        return $this->createWorkflowWithJobSteps(
            $this->admin,
            ...array_map(static fn (JobTemplate $template): int => (int)$template->id, $templates)
        );
    }

    private function templateRequest(JobTemplate $template): ApprovalRequest
    {
        return $this->requestFor($this->createJob((int)$template->id, $this->launcher, Job::STATUS_PENDING_APPROVAL));
    }

    /**
     * The request of an approval step of a running $workflow: its placeholder
     * job has no job template.
     */
    private function placeholderRequest(WorkflowTemplate $workflow): ApprovalRequest
    {
        $run = $this->createWorkflowJob((int)$workflow->id, $this->launcher);
        $step = $this->createWorkflowStep((int)$workflow->id, 99, WorkflowStep::TYPE_APPROVAL, null, (int)$this->rule()->id);
        $job = $this->templateLessJob();
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = (int)$job->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->created_at = time();
        $execution->updated_at = time();
        $execution->save(false);

        return $this->requestFor($job);
    }

    /**
     * The request of a job without a template that belongs to no workflow,
     * as left by a template purged from the database.
     */
    private function orphanRequest(): ApprovalRequest
    {
        return $this->requestFor($this->templateLessJob());
    }

    private function templateLessJob(): Job
    {
        $job = $this->createJob((int)$this->s['open']->id, $this->launcher, Job::STATUS_PENDING_APPROVAL);
        $job->job_template_id = null;
        $job->save(false);

        return $job;
    }

    private function requestFor(Job $job): ApprovalRequest
    {
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$this->rule()->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);
        $fresh = ApprovalRequest::findOne($request->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }
}
