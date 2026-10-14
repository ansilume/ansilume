<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ApprovalsController;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRequestSearch;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalDecisionRefusedException;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\services\WorkflowEngineFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * What approval requests are for, through the API (contract C13): every
 * ApprovalRequest names the workflow run it belongs to (workflow_job_id,
 * workflow_job_step_id, workflow_template_id, workflow_step_name), null
 * outside a workflow and for a caller who may not view the workflow.
 * GET /api/v1/approvals filters by ?status= and answers 422 for a status it
 * does not know. The launcher of a workflow run is told so when their vote
 * is refused.
 */
class ApprovalsContextApiTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use WorkflowEngineFixtures;
    use CountsQueries;
    use RunContextApiFixtures;

    private const WORKFLOW_FIELDS = ['workflow_job_id', 'workflow_job_step_id', 'workflow_template_id', 'workflow_step_name'];

    private User $launcher;

    private User $approver;

    private int $templateId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->launcher = $this->namedUser('acx-launcher', 'operator');
        $this->approver = $this->namedUser('acx-approver', 'operator');
        $this->templateId = (int)$this->openTemplate((int)$this->launcher->id)->id;
    }

    // -- The workflow fields --------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function workflowStepProvider(): array
    {
        return [
            'the placeholder of an approval step' => [WorkflowStep::TYPE_APPROVAL],
            'the job of a gated job step' => [WorkflowStep::TYPE_JOB],
        ];
    }

    /**
     * regression: an approval request did not say which workflow run it is
     * for. GET /approvals/{id} named only its job, a placeholder without a
     * job template for an approval step, so an approver could not tell
     * which run waited. The request, the list and the vote's answer now name
     * the run, the step execution, the workflow and the step.
     *
     * @dataProvider workflowStepProvider
     */
    public function testRegressionAnApprovalRequestDidNotSayWhichWorkflowRunItIsFor(string $stepType): void
    {
        $launched = $this->launched($stepType);
        $expected = [
            'workflow_job_id' => (int)$launched['run']->id,
            'workflow_job_step_id' => (int)$launched['execution']->id,
            'workflow_template_id' => (int)$launched['workflow']->id,
            'workflow_step_name' => $stepType === WorkflowStep::TYPE_APPROVAL ? 'sign off' : 'deploy',
        ];
        $requestId = (int)$launched['request']->id;
        $this->authenticate($this->approver);

        $view = $this->data($this->controller()->actionView($requestId));
        $item = $this->itemsById($this->controller()->actionIndex())[$requestId];
        $vote = $this->data($this->controller()->actionApprove($requestId));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        foreach (['view' => $view, 'list' => $item, 'vote' => $vote] as $answer => $data) {
            $this->assertSame($expected, array_intersect_key($data, $expected), $answer);
            $this->assertSame((int)$launched['job']->id, $data['job_id'], $answer);
        }
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $vote['status']);
    }

    /**
     * The step's name is the one it had when the run reached it: renaming
     * the step since changes nothing.
     */
    public function testTheStepIsNamedAsItWasWhenTheRunReachedIt(): void
    {
        $launched = $this->launched(WorkflowStep::TYPE_APPROVAL);
        WorkflowStep::updateAll(['name' => 'renamed later'], ['id' => $launched['gate']->id]);
        $this->authenticate($this->approver);

        $data = $this->data($this->controller()->actionView((int)$launched['request']->id));

        $this->assertSame('sign off', $data['workflow_step_name']);
    }

    /**
     * Team scoping: a member may see the request of a gated job step
     * through its job template's project while the workflow stays hidden
     * from them (another of its steps belongs to another team). They learn
     * nothing of the workflow: all four fields are null, in the request and
     * in the list. An admin gets them.
     */
    public function testACallerWhoMayNotViewTheWorkflowLearnsNothingOfIt(): void
    {
        $s = $this->teamScope();
        $adminId = (int)$s['admin']->id;
        $rule = $this->ruleOf($adminId, [$s['member']]);
        JobTemplate::updateAll(['approval_rule_id' => $rule->id], ['id' => $s['own']->id]);
        $workflow = $this->createWorkflowTemplate($adminId);
        $deploy = $this->namedStep($workflow, 10, 'deploy', WorkflowStep::TYPE_JOB, (int)$s['own']->id);
        $this->namedStep($workflow, 20, 'foreign rollout', WorkflowStep::TYPE_JOB, (int)$s['foreign']->id);
        $run = $this->engine()->launch($workflow, $adminId);
        $request = $this->requestOfJob($this->jobOfStep($run, $deploy));
        $requestId = (int)$request->id;

        $this->authenticate($s['member']);
        $asMember = [
            'view' => $this->data($this->controller()->actionView($requestId)),
            'list' => $this->itemsById($this->controller()->actionIndex())[$requestId],
        ];
        $this->authenticate($s['admin']);
        $asAdmin = $this->data($this->controller()->actionView($requestId));

        foreach ($asMember as $answer => $data) {
            $this->assertSame(array_fill_keys(self::WORKFLOW_FIELDS, null), array_intersect_key($data, array_flip(self::WORKFLOW_FIELDS)), $answer);
        }
        $this->assertSame((int)$run->id, $asAdmin['workflow_job_id']);
        $this->assertSame((int)$workflow->id, $asAdmin['workflow_template_id']);
        $this->assertSame('deploy', $asAdmin['workflow_step_name']);
    }

    /**
     * The vote counts of the list are those of the request itself: every
     * vote but the launcher's (an earlier version stored one).
     */
    public function testTheListCountsTheVotesAsTheRequestDoes(): void
    {
        $voters = [$this->namedUser('acx-voter-a', 'operator'), $this->namedUser('acx-voter-b', 'operator')];
        $rule = $this->ruleOf((int)$this->launcher->id, [$this->approver, ...$voters], 3);
        $job = $this->jobOf($this->templateId, (int)$this->launcher->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($job, $rule);
        $this->voteOn($request, $this->launcher, ApprovalDecision::DECISION_APPROVED);
        $this->voteOn($request, $voters[0], ApprovalDecision::DECISION_APPROVED);
        $this->voteOn($request, $voters[1], ApprovalDecision::DECISION_REJECTED);
        $this->authenticate($this->approver);

        $item = $this->itemsById($this->controller()->actionIndex())[(int)$request->id];
        $view = $this->data($this->controller()->actionView((int)$request->id));

        foreach (['list' => $item, 'view' => $view] as $answer => $data) {
            $this->assertSame(1, $data['approval_count'], "{$answer}: the launcher's approval does not count");
            $this->assertSame(1, $data['rejection_count'], $answer);
            $this->assertSame($request->approvalCount(), $data['approval_count'], $answer);
            $this->assertSame($request->rejectionCount(), $data['rejection_count'], $answer);
            $this->assertSame((int)$this->launcher->id, $data['launched_by'], $answer);
        }
    }

    // -- The launcher of a run --------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function launcherVoteProvider(): array
    {
        return [
            'approve the placeholder of an approval step' => [WorkflowStep::TYPE_APPROVAL, 'actionApprove'],
            'reject the placeholder of an approval step' => [WorkflowStep::TYPE_APPROVAL, 'actionReject'],
            'approve the job of a gated job step' => [WorkflowStep::TYPE_JOB, 'actionApprove'],
            'reject the job of a gated job step' => [WorkflowStep::TYPE_JOB, 'actionReject'],
        ];
    }

    /**
     * regression: the launcher of a workflow run was told "You launched
     * this job" when the API refused their vote, about a job they never
     * launched: the run created it. They are told that they launched the
     * run (403 LAUNCHER_REFUSED_RUN), although they are on the rule's
     * approver list, and the request stays pending for another approver.
     *
     * @dataProvider launcherVoteProvider
     */
    public function testRegressionTheLauncherOfARunGets403LauncherRefusedRun(string $stepType, string $action): void
    {
        $launched = $this->launched($stepType, true);
        $request = $launched['request'];
        $this->authenticate($this->launcher);

        $result = $this->controller()->$action((int)$request->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'You launched this workflow run; another approver must decide.']], $result);
        $this->assertSame(['error' => ['message' => ApprovalDecisionRefusedException::LAUNCHER_REFUSED_RUN]], $result);
        $this->assertSame(0, (int)ApprovalDecision::find()->where(['approval_request_id' => $request->id])->count(), 'nothing recorded');
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status, 'another approver decides');
    }

    // -- The status filter ----------------------------------------------------------

    /**
     * ?status= keeps the requests of one status, newest first, and the
     * total counts them; an empty status lists every request.
     */
    public function testTheStatusFilterKeepsTheRequestsOfOneStatus(): void
    {
        $rule = $this->ruleOf((int)$this->launcher->id, [$this->approver]);
        $byStatus = [];
        foreach (ApprovalRequest::statuses() as $status) {
            $job = $this->jobOf($this->templateId, (int)$this->launcher->id, Job::STATUS_PENDING_APPROVAL);
            $byStatus[$status] = (int)$this->requestOf($job, $rule, $status)->id;
        }
        $this->authenticate($this->approver);

        foreach ($byStatus as $status => $requestId) {
            $this->setQueryParams(['status' => $status]);
            $result = $this->controller()->actionIndex();
            $this->assertSame(200, \Yii::$app->response->statusCode, $status);
            $this->assertSame([$requestId], array_column($this->data($result), 'id'), $status);
            $this->assertSame(1, $result['meta']['total'], $status);
        }
        $this->setQueryParams(['status' => '']);
        $this->assertSame(array_reverse(array_values($byStatus)), array_column($this->data($this->controller()->actionIndex()), 'id'));
    }

    /**
     * The filter never widens team scoping: a member's pending requests
     * leave out another team's.
     */
    public function testTheStatusFilterKeepsTeamScoping(): void
    {
        $s = $this->teamScope();
        $rule = $this->ruleOf((int)$s['admin']->id, [$s['admin']]);
        $own = $this->requestOf($this->jobOf((int)$s['own']->id, (int)$s['admin']->id, Job::STATUS_PENDING_APPROVAL), $rule);
        $this->requestOf($this->jobOf((int)$s['foreign']->id, (int)$s['admin']->id, Job::STATUS_PENDING_APPROVAL), $rule);
        $this->authenticate($s['member']);
        $this->setQueryParams(['status' => ApprovalRequest::STATUS_PENDING]);

        $result = $this->controller()->actionIndex();

        $this->assertSame([(int)$own->id], array_column($this->data($result), 'id'));
        $this->assertSame(1, $result['meta']['total']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidStatusProvider(): array
    {
        return [
            'a status in upper case' => ['PENDING'],
            'a word for one' => ['open'],
            'a run status' => ['running'],
            'a list of statuses' => [['pending']],
        ];
    }

    /**
     * A status the filter does not know answers 422 with the valid ones, so
     * a typo never passes for "every request".
     *
     * @dataProvider invalidStatusProvider
     */
    public function testAnInvalidStatusAnswers422(mixed $status): void
    {
        $this->authenticate($this->approver);
        $this->setQueryParams(['status' => $status]);

        $result = $this->controller()->actionIndex();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'status must be one of: pending, approved, rejected, timed_out, canceled.']], $result);
        $this->assertSame(['error' => ['message' => ApprovalRequestSearch::statusError()]], $result);
    }

    /**
     * Through the controller's pipeline, the permission comes before the
     * filter: a caller without approval.view gets the plain 403, not the
     * filter's 422.
     */
    public function testACallerWithoutApprovalViewGets403BeforeTheFilter(): void
    {
        $this->authenticate($this->createUser('acx-no-role'));
        $this->setQueryParams(['status' => 'open']);

        $this->assertNull($this->controller()->runAction('index'));

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], \Yii::$app->response->data);
    }

    // -- Cost -------------------------------------------------------------------------

    /**
     * The list reads the votes and the workflow context of its requests at
     * once: the same number of queries for 2 requests as for 12, for an
     * admin and for a member restricted by team scoping.
     *
     * regression: the list counted the votes of each request with two
     * queries of its own (approvalCount(), rejectionCount()), 20 more for
     * 10 more requests.
     */
    public function testTheListUsesAConstantNumberOfQueries(): void
    {
        $s = $this->teamScope();
        $requests = $this->requestsOf(2, $s);
        $two = $this->measuredLists([$s['admin'], $s['member']], $requests);
        $requests = array_merge($requests, $this->requestsOf(10, $s));
        $twelve = $this->measuredLists([$s['admin'], $s['member']], $requests);

        $this->assertCount(12, $requests);
        $this->assertSame($two, $twelve, '2 requests and 12 requests, per caller');
    }

    // -- Helpers ----------------------------------------------------------------------

    private function controller(): ApprovalsController
    {
        return new ApprovalsController('api/v1/approvals', \Yii::$app);
    }

    /**
     * A run launched by the launcher through the engine, waiting at a step
     * of $stepType: the approval step "sign off" (key gate), or the gated
     * job step "deploy". The rule lists the approver and, with
     * $launcherApproves, the launcher too.
     *
     * @return array{run: WorkflowJob, workflow: WorkflowTemplate, deploy: WorkflowStep, execution: WorkflowJobStep, job: Job, request: ApprovalRequest, gate?: WorkflowStep, hold?: WorkflowStep}
     */
    private function launched(string $stepType, bool $launcherApproves = false): array
    {
        $approvers = $launcherApproves ? [$this->launcher, $this->approver] : [$this->approver];
        if ($stepType === WorkflowStep::TYPE_APPROVAL) {
            return $this->launchedAtApprovalStep($this->launcher, $approvers, $this->templateId);
        }
        $rule = $this->ruleOf((int)$this->launcher->id, $approvers);

        return $this->launchedAtGatedJobStep($this->launcher, $this->gatedTemplate((int)$this->launcher->id, $rule));
    }

    /**
     * $count new requests the member may see, cycling through the
     * placeholder of an approval step, the job of a gated job step and the
     * job of a job template outside workflows, each with a vote; so 2
     * requests already read everything 12 requests read.
     *
     * @param array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} $s
     * @return list<int> the request IDs, oldest first
     */
    private function requestsOf(int $count, array $s): array
    {
        $adminId = (int)$s['admin']->id;
        $templateId = (int)$s['own']->id;
        $rule = $this->ruleOf($adminId, [$s['member'], $s['outsider']]);
        $workflow = $this->createWorkflowTemplate($adminId);
        $approval = $this->namedStep($workflow, 10, 'sign off', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $job = $this->namedStep($workflow, 20, 'deploy', WorkflowStep::TYPE_JOB, $templateId);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $run = $this->createWorkflowJob((int)$workflow->id, $adminId);
            $kind = $i % 3;
            $waiting = $this->jobOf($kind === 0 ? null : $templateId, $adminId, Job::STATUS_PENDING_APPROVAL);
            $request = $this->requestOf($waiting, $rule);
            if ($kind < 2) {
                $this->executionOf($run, $kind === 0 ? $approval : $job, WorkflowJobStep::STATUS_RUNNING, $waiting);
            }
            $this->voteOn($request, $s['outsider'], ApprovalDecision::DECISION_REJECTED);
            $ids[] = (int)$request->id;
        }

        return $ids;
    }

    /**
     * The queries of one list per caller, each listing exactly $requestIds.
     *
     * @param list<User> $callers
     * @param list<int> $requestIds oldest first
     * @return list<int>
     */
    private function measuredLists(array $callers, array $requestIds): array
    {
        $queries = [];
        foreach ($callers as $caller) {
            $this->authenticate($caller);
            // Once before measuring: schemas and RBAC assignments are read on first use.
            $this->controller()->actionIndex();
            $result = [];
            $queries[] = $this->queriesOf(function () use (&$result): void {
                $result = $this->controller()->actionIndex();
            });
            $this->assertSame(array_reverse($requestIds), array_column($this->data($result), 'id'), "user #{$caller->id} lists exactly these requests");
        }

        return $queries;
    }
}
