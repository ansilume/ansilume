<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ApprovalsController;
use app\helpers\TimeHelper;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\runstate\Texts;
use app\services\runstate\Viewer;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\services\WorkflowEngineFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * The votes on an approval request in the API: every ApprovalRequest
 * answers in "decisions" the votes the request page lists (every vote, the
 * launcher's too), in the order cast: user_id, username, decision, the
 * comment as given (null without one) and created_at. Callers without
 * approval.view (only the answers of approve and reject reach them) get
 * null. The list reads the votes of all its requests at once.
 */
class ApprovalsDecisionsApiTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;
    use WorkflowEngineFixtures;
    use CountsQueries;
    use RunContextApiFixtures;

    private const COMMENT = 'Change window confirmed';

    private User $admin;

    private User $launcher;

    private int $templateId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->namedUser('apd-admin', 'admin');
        $this->launcher = $this->namedUser('apd-launcher', 'operator');
        $this->templateId = (int)$this->openTemplate((int)$this->admin->id)->id;
    }

    /**
     * regression: who approved or rejected a request, and the comment, were
     * shown on the request page and in the run page's decision notes, but
     * no API answer carried them: GET /api/v1/approvals/{id} and the list
     * answered the counts only.
     */
    public function testRegressionWhoDecidedAndTheCommentWereAnsweredByNoEndpoint(): void
    {
        $approver = $this->namedUser('apd-approver', 'operator');
        $request = $this->requestFor([$approver], ApprovalRequest::STATUS_APPROVED);
        $vote = $this->voteOn($request, $approver, ApprovalDecision::DECISION_APPROVED, self::COMMENT);
        $this->authenticate($this->admin);

        $detail = $this->data($this->controller()->actionView((int)$request->id));
        $listed = $this->itemsById($this->controller()->actionIndex())[(int)$request->id] ?? null;

        $expected = [[
            'user_id' => (int)$approver->id,
            'username' => (string)$approver->username,
            'decision' => ApprovalDecision::DECISION_APPROVED,
            'comment' => self::COMMENT,
            'created_at' => (int)$vote->created_at,
        ]];
        $this->assertSame($expected, $detail['decisions']);
        $this->assertNotNull($listed, 'the list holds the request');
        $this->assertSame($expected, $listed['decisions']);
    }

    /**
     * The votes come in the order they were cast, not by user: the later
     * voter has the lower user ID here. They are the votes the request page
     * lists, the launcher's too: cast before self-approval was refused, the
     * page marks it "launcher, not counted", and the counts leave it out. A
     * vote without a comment answers null.
     */
    public function testTheVotesComeInTheOrderCastTheLaunchersToo(): void
    {
        $first = $this->namedUser('apd-first', 'operator');
        $second = $this->namedUser('apd-second', 'operator');
        $request = $this->requestFor([$this->launcher, $first, $second], ApprovalRequest::STATUS_PENDING, 3);
        $this->voteOn($request, $this->launcher, ApprovalDecision::DECISION_APPROVED, 'my own');
        $this->voteOn($request, $second, ApprovalDecision::DECISION_REJECTED);
        $this->voteOn($request, $first, ApprovalDecision::DECISION_APPROVED, self::COMMENT);
        $this->authenticate($this->admin);

        $data = $this->data($this->controller()->actionView((int)$request->id));

        /** @var list<array<string, mixed>> $decisions */
        $decisions = $data['decisions'];
        $this->assertSame(
            [
                [(int)$this->launcher->id, ApprovalDecision::DECISION_APPROVED, 'my own'],
                [(int)$second->id, ApprovalDecision::DECISION_REJECTED, null],
                [(int)$first->id, ApprovalDecision::DECISION_APPROVED, self::COMMENT],
            ],
            array_map(static fn (array $vote): array => [$vote['user_id'], $vote['decision'], $vote['comment']], $decisions)
        );
        $this->assertSame($data['launched_by'], $decisions[0]['user_id'], 'the launcher\'s vote is told by launched_by');
        $this->assertSame([1, 1], [$data['approval_count'], $data['rejection_count']], 'the launcher\'s vote counts for nothing');
        // The votes the request page lists ($model->decisions), by who cast them.
        $page = $request->decisions;
        usort($page, static fn (ApprovalDecision $a, ApprovalDecision $b): int => (int)$a->id <=> (int)$b->id);
        $this->assertSame(
            array_map(static fn (ApprovalDecision $vote): array => [(int)$vote->user_id, (string)$vote->user->username, $vote->decision], $page),
            array_map(static fn (array $vote): array => [$vote['user_id'], $vote['username'], $vote['decision']], $decisions)
        );
    }

    public function testARequestWithoutVotesAnswersAnEmptyList(): void
    {
        $request = $this->requestFor([$this->namedUser('apd-approver', 'operator')], ApprovalRequest::STATUS_PENDING);
        $this->authenticate($this->admin);

        $this->assertSame([], $this->data($this->controller()->actionView((int)$request->id))['decisions']);
    }

    /**
     * The answer of a vote lists it with the others, to an approver with
     * approval.view.
     */
    public function testTheAnswerOfAVoteListsIt(): void
    {
        $approver = $this->namedUser('apd-approver', 'operator');
        $request = $this->requestFor([$approver], ApprovalRequest::STATUS_PENDING);
        $this->authenticate($approver);
        $this->setBody(['comment' => self::COMMENT]);

        $data = $this->data($this->controller()->actionApprove((int)$request->id));

        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $data['status']);
        /** @var list<array<string, mixed>> $decisions */
        $decisions = $data['decisions'];
        $this->assertCount(1, $decisions);
        $this->assertSame(
            [(int)$approver->id, (string)$approver->username, ApprovalDecision::DECISION_APPROVED, self::COMMENT],
            [$decisions[0]['user_id'], $decisions[0]['username'], $decisions[0]['decision'], $decisions[0]['comment']]
        );
        $this->assertIsInt($decisions[0]['created_at']);
    }

    /**
     * An approver who holds approval.decide but not approval.view sees no
     * approval page, so the answer of their vote names no voters: decisions
     * is null, as the run page names no voters to them.
     */
    public function testACallerWithoutApprovalViewGetsNoVotes(): void
    {
        $decider = $this->namedUser('apd-decider');
        $this->grantOnly($decider, ['approval.decide']);
        $other = $this->namedUser('apd-other', 'operator');
        $request = $this->requestFor([$other, $decider], ApprovalRequest::STATUS_PENDING, 2);
        $this->voteOn($request, $other, ApprovalDecision::DECISION_APPROVED, self::COMMENT);
        $this->authenticate($decider);

        $data = $this->data($this->controller()->actionApprove((int)$request->id));

        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $data['status']);
        $this->assertSame(2, $data['approval_count']);
        $this->assertArrayHasKey('decisions', $data);
        $this->assertNull($data['decisions']);
    }

    /**
     * The API names the voters and the comment that the run page's decision
     * note of the step names, for a workflow approval step.
     */
    public function testTheApiNamesTheVotesOfTheRunPagesDecisionNote(): void
    {
        $approver = $this->namedUser('apd-approver', 'operator');
        $rule = $this->ruleOf((int)$this->admin->id, [$approver]);
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $gate = $this->namedStep($workflow, 10, 'sign off', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->launcher->id);
        $placeholder = $this->jobOf(null, (int)$this->launcher->id, Job::STATUS_SUCCEEDED);
        $resolvedAt = time() - 30;
        $request = $this->requestOf($placeholder, $rule, ApprovalRequest::STATUS_APPROVED, time() - 60, null, $resolvedAt);
        $this->voteOn($request, $approver, ApprovalDecision::DECISION_APPROVED, self::COMMENT);
        $execution = $this->executionOf($run, $gate, WorkflowJobStep::STATUS_SUCCEEDED, $placeholder);
        $this->authenticate($this->admin);

        $state = $this->runStates()->forRun($run, Viewer::of((int)$this->admin->id));
        $decisions = $this->data($this->controller()->actionView((int)$request->id))['decisions'];

        $this->assertSame((int)$execution->id, $state->steps[0]->executionId);
        $this->assertSame(
            sprintf(Texts::DECIDED_APPROVED_BY, $approver->username, TimeHelper::stamp($resolvedAt))
                . ' ' . sprintf(Texts::DECIDED_COMMENT, self::COMMENT),
            $state->steps[0]->decisionNote
        );
        $this->assertSame(
            [[(string)$approver->username, ApprovalDecision::DECISION_APPROVED, self::COMMENT]],
            array_map(static fn (array $vote): array => [$vote['username'], $vote['decision'], $vote['comment']], $decisions)
        );
    }

    /**
     * The list reads the votes of all its requests at once: the same
     * number of queries for 2 requests as for 12, each with two votes, and
     * each request lists its own.
     */
    public function testTheListReadsTheVotesOfAllItsRequestsAtOnce(): void
    {
        $one = $this->namedUser('apd-one', 'operator');
        $two = $this->namedUser('apd-two', 'operator');
        $requests = $this->votedRequests(2, $one, $two);
        $few = $this->measuredList($requests, $one, $two);
        $requests = array_merge($requests, $this->votedRequests(10, $one, $two));
        $many = $this->measuredList($requests, $one, $two);

        $this->assertCount(12, $requests);
        $this->assertSame($few, $many, '2 requests and 12 requests');
    }

    // -- Helpers ----------------------------------------------------------------------

    private function controller(): ApprovalsController
    {
        return new ApprovalsController('api/v1/approvals', \Yii::$app);
    }

    /**
     * A request of a job of the open template, launched by the launcher,
     * whose rule lists $approvers.
     *
     * @param list<User> $approvers
     */
    private function requestFor(array $approvers, string $status, int $required = 1): ApprovalRequest
    {
        $job = $this->jobOf(
            $this->templateId,
            (int)$this->launcher->id,
            $status === ApprovalRequest::STATUS_PENDING ? Job::STATUS_PENDING_APPROVAL : Job::STATUS_QUEUED
        );
        $resolvedAt = $status === ApprovalRequest::STATUS_PENDING ? null : time();

        return $this->requestOf($job, $this->ruleOf((int)$this->admin->id, $approvers, $required), $status, time() - 60, null, $resolvedAt);
    }

    /**
     * $count pending requests, each with a rejection by $one and an approval
     * by $two.
     *
     * @return list<int> the request IDs, oldest first
     */
    private function votedRequests(int $count, User $one, User $two): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $request = $this->requestFor([$one, $two], ApprovalRequest::STATUS_PENDING, 2);
            $this->voteOn($request, $one, ApprovalDecision::DECISION_REJECTED);
            $this->voteOn($request, $two, ApprovalDecision::DECISION_APPROVED, self::COMMENT);
            $ids[] = (int)$request->id;
        }

        return $ids;
    }

    /**
     * The queries of the admin's list of exactly $requestIds, newest first,
     * each with its two votes.
     *
     * @param list<int> $requestIds oldest first
     */
    private function measuredList(array $requestIds, User $one, User $two): int
    {
        $this->authenticate($this->admin);
        // Once before measuring: schemas and RBAC assignments are read on first use.
        $this->controller()->actionIndex();
        $result = [];
        $queries = $this->queriesOf(function () use (&$result): void {
            $result = $this->controller()->actionIndex();
        });
        /** @var list<array<string, mixed>> $items */
        $items = $this->data($result);
        $this->assertSame(array_reverse($requestIds), array_column($items, 'id'));
        foreach ($items as $item) {
            /** @var list<array<string, mixed>> $decisions */
            $decisions = $item['decisions'];
            $this->assertSame([(int)$one->id, (int)$two->id], array_column($decisions, 'user_id'), "the votes of request #{$item['id']}");
        }

        return $queries;
    }

    /**
     * A role of its own with exactly $permissions, assigned to $user.
     *
     * @param list<string> $permissions
     */
    private function grantOnly(User $user, array $permissions): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('apd-role-' . bin2hex(random_bytes(4)));
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $auth->assign($role, (string)$user->id);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }
}
