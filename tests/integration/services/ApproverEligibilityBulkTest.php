<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\TeamMember;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApproverEligibility;
use app\services\ProjectAccessChecker;
use app\services\WorkflowAccessChecker;
use app\tests\integration\CountsQueries;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Approvers are checked together: deciding who may approve costs the same
 * number of queries for five approvers as for twenty-five, with and without
 * team scoping, and the answer for many approvers of every kind equals the
 * checks made one by one. A vote evaluates the threshold with these checks
 * while it holds the request's row lock.
 */
class ApproverEligibilityBulkTest extends DbTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    private const DECIDE = 'approval.decide';

    /**
     * The kinds of approvers a scenario cycles through, and whether they may
     * decide under team scoping (without it, the outsider may decide too).
     */
    private const KINDS = [
        'member' => true,
        'outsider' => false,
        'admin' => true,
        'superadmin' => true,
        'inactive' => false,
        'viewer' => false,
        'plain' => false,
    ];

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate}|null */
    private ?array $scope = null;

    private int $launcher = 0;

    public function testWithoutTeamScopingTheCostDoesNotGrowWithTheApprovers(): void
    {
        /** @var ProjectAccessChecker $projects */
        $projects = \Yii::$app->get('projectAccessChecker');
        $this->assertFalse($projects->hasRestrictedProjects(), 'precondition: no team-restricted project');
        $this->launcher = (int)$this->createUserWithRole('launcher', 'operator')->id;

        $this->assertCostsDoNotGrow();
    }

    public function testUnderTeamScopingTheCostDoesNotGrowWithTheApprovers(): void
    {
        $this->scope = $this->teamScope();
        $this->launcher = (int)$this->createUserWithRole('launcher', 'operator')->id;

        $this->assertCostsDoNotGrow();
    }

    public function testManyApproversOfEveryKindAreSortedAsTheChecksOneByOneSortThem(): void
    {
        $this->scope = $this->teamScope();
        $this->launcher = (int)$this->createUserWithRole('launcher', 'operator')->id;
        $scenario = $this->scenario(40);

        foreach ([$scenario['templateRequest'], $scenario['placeholderRequest']] as $requestId) {
            $request = $this->request($requestId);
            $eligible = $this->eligibility()->eligibleForRequest($request);

            $this->assertSame($scenario['expected'], $eligible);
            $this->assertSame($this->checkedOneByOne($request), $eligible);
        }
        $this->assertSame(
            sprintf(ApproverEligibility::REFUSAL_JOB, '"' . $scenario['rule']->name . '"', 40, count($scenario['expected'])),
            $this->eligibility()->jobLaunchRefusal($scenario['rule'], $scenario['template'], $this->launcher)
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function assertCostsDoNotGrow(): void
    {
        $few = $this->scenario(5);
        $many = $this->scenario(25);
        $this->costs($few);

        $this->assertSame($this->costs($few), $this->costs($many));
    }

    /**
     * Statements each check sends, on objects freshly read from the database.
     *
     * @param array{approvers: list<int>, expected: list<int>, rule: ApprovalRule, template: JobTemplate, workflow: WorkflowTemplate, run: WorkflowJob, templateRequest: int, placeholderRequest: int} $scenario
     * @return array<string, int>
     */
    private function costs(array $scenario): array
    {
        $eligibility = $this->eligibility();
        $costs = [];
        foreach (['templateRequest', 'placeholderRequest'] as $key) {
            $request = $this->request($scenario[$key]);
            $costs[$key] = $this->queriesOf(fn () => $eligibility->eligibleForRequest($request));
            $request = $this->request($scenario[$key]);
            $costs[$key . ' mayDecide'] = $this->queriesOf(
                fn () => $eligibility->mayDecide($request, $scenario['approvers'][0])
            );
        }
        $costs['job launch'] = $this->queriesOf(
            fn () => $eligibility->jobLaunchRefusal($scenario['rule'], $scenario['template'], $this->launcher)
        );
        $costs['workflow step'] = $this->queriesOf(
            fn () => $eligibility->workflowStepRefusal($scenario['rule'], $scenario['run'])
        );
        $costs['workflow launch'] = $this->queriesOf(
            fn () => $eligibility->workflowLaunchRefusal($scenario['workflow'], $this->launcher)
        );

        return $costs;
    }

    /**
     * $count approvers cycling through KINDS (the launcher listed too), a
     * rule that needs all of them, a gated job template, a workflow with a
     * gated job step and an approval step, and a pending request for each.
     * 'expected' holds the approvers who may decide under team scoping.
     *
     * @return array{approvers: list<int>, expected: list<int>, rule: ApprovalRule, template: JobTemplate, workflow: WorkflowTemplate, run: WorkflowJob, templateRequest: int, placeholderRequest: int}
     */
    private function scenario(int $count): array
    {
        $kinds = array_keys(self::KINDS);
        $approvers = [];
        $expected = [];
        for ($i = 0; $i < $count; $i++) {
            $kind = $kinds[$i % count($kinds)];
            $userId = $this->approverOfKind($kind);
            $approvers[] = $userId;
            if (self::KINDS[$kind]) {
                $expected[] = $userId;
            }
        }
        $rule = $this->createApprovalRule(
            $this->launcher,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => array_merge($approvers, [$this->launcher])]),
            $count
        );
        $template = $this->template();
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);
        $workflow = $this->createWorkflowWithJobSteps($this->launcher, (int)$template->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$workflow->id, $this->launcher);

        return [
            'approvers' => $approvers,
            'expected' => $expected,
            'rule' => $rule,
            'template' => $template,
            'workflow' => $workflow,
            'run' => $run,
            'templateRequest' => $this->pendingRequest($this->createJob((int)$template->id, $this->launcher, Job::STATUS_PENDING_APPROVAL), $rule),
            'placeholderRequest' => $this->pendingRequest($this->placeholderJob($run, $step), $rule),
        ];
    }

    /**
     * The template whose project the members' team operates; without team
     * scoping a template of its own project.
     */
    private function template(): JobTemplate
    {
        if ($this->scope !== null) {
            return $this->createJobTemplate(
                (int)$this->scope['own']->project_id,
                (int)$this->scope['own']->inventory_id,
                (int)$this->scope['own']->runner_group_id,
                $this->launcher
            );
        }

        return $this->createJobTemplate(
            (int)$this->createProject($this->launcher)->id,
            (int)$this->createInventory($this->launcher)->id,
            (int)$this->createRunnerGroup($this->launcher)->id,
            $this->launcher
        );
    }

    /**
     * A user of $kind. Under team scoping members, inactive users and viewers
     * join the team that operates the scenario's project, outsiders the other
     * team; without it the outsider is an operator like any other.
     */
    private function approverOfKind(string $kind): int
    {
        $role = match ($kind) {
            'member', 'outsider', 'inactive' => 'operator',
            'admin', 'viewer' => $kind,
            default => null,
        };
        $user = $role === null ? $this->createUser($kind) : $this->createUserWithRole($kind, $role);
        $user->is_superadmin = $kind === 'superadmin';
        $user->status = $kind === 'inactive' ? User::STATUS_INACTIVE : User::STATUS_ACTIVE;
        $user->save(false);
        if ($this->scope !== null && in_array($kind, ['member', 'outsider', 'inactive', 'viewer'], true)) {
            $team = $this->teamOf($this->scope[$kind === 'outsider' ? 'outsider' : 'member']);
            $this->addTeamMember($team, (int)$user->id);
        }

        return (int)$user->id;
    }

    private function teamOf(User $user): int
    {
        return (int)TeamMember::find()->select('team_id')->where(['user_id' => $user->id])->scalar();
    }

    private function placeholderJob(WorkflowJob $run, WorkflowStep $step): Job
    {
        $job = $this->createJob((int)$this->template()->id, $this->launcher, Job::STATUS_PENDING_APPROVAL);
        $job->job_template_id = null;
        $job->save(false);
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = (int)$run->id;
        $execution->workflow_step_id = (int)$step->id;
        $execution->job_id = (int)$job->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->created_at = time();
        $execution->updated_at = time();
        $execution->save(false);

        return $job;
    }

    private function pendingRequest(Job $job, ApprovalRule $rule): int
    {
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        return (int)$request->id;
    }

    private function request(int $id): ApprovalRequest
    {
        $request = ApprovalRequest::findOne($id);
        $this->assertNotNull($request);

        return $request;
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
}
