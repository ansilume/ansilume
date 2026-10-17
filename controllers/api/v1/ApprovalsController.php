<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRequestSearch;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalService;
use app\services\runstate\ApprovalRequestContexts;
use app\services\runstate\ApprovalVotes;
use app\services\runstate\RequestContext;
use app\services\runstate\Viewer;
use app\services\WorkflowAccessChecker;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Approvals — list, view, approve, reject.
 *
 * Team scoping applies: the list holds only the requests the caller may see
 * (view access to the job's project or, for a workflow approval step, the
 * workflow); other requests answer 403. The list takes ?status=
 * (ApprovalRequestSearch); a status it does not know answers 422.
 *
 * Each request names the workflow run it is for (ApprovalRequestContexts):
 * workflow_job_id, workflow_job_step_id (the step execution),
 * workflow_template_id and workflow_step_name (the step's name when it ran).
 * All four are null when the request's job belongs to no workflow step, and
 * when the caller may not view that workflow: the request of a gated job
 * step can be visible through its job template's project while the
 * workflow stays hidden.
 *
 * Each request says what required it and names the inventory its job was
 * launched with (RequestContext::gateFields()): required_by (job_template,
 * inventory or approval_step; null only before m000088), and inventory_id
 * and inventory_name as the job's runner payload records them (its name at
 * launch, for jobs launched before 2.12.0 its current name; both null for
 * the placeholder of a workflow approval step, the name null when it is
 * unknown).
 *
 * Each request lists its votes in "decisions" (serializeAll()): user_id,
 * username, decision, comment and created_at of every vote the request page
 * lists, in the order cast; null for a caller without approval.view.
 *
 * A vote that is refused answers (ApprovalDecisionRefusedException):
 * 409 {"error": {"message", "status"}} for the request's state (resolved,
 * expired, its job no longer waiting; "status" is the request's status),
 * 403 for the voter (the launcher: LAUNCHER_REFUSED, or LAUNCHER_REFUSED_RUN
 * for the job of a workflow step, whose run they started; no eligible
 * approver), 422 for the vote (a second vote, an invalid comment), 404 for a
 * request that is gone.
 * A "comment" that is neither a string nor null answers 422 before the vote
 * is checked (ApprovalService::COMMENT_NOT_A_STRING).
 */
class ApprovalsController extends BaseApiController
{
    protected function apiAccessRules(): array
    {
        return [
            'index' => 'approval.view',
            'view' => 'approval.view',
            'approve' => 'approval.decide',
            'reject' => 'approval.decide',
        ];
    }

    /**
     * The requests the caller may see, newest first, filtered by ?status=.
     * An invalid status answers 422 with ApprovalRequestSearch::statusError(),
     * before anything is read. The cost does not grow with the number of
     * requests on the page (serializeAll()).
     *
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}|array{error: array{message: string}}
     */
    public function actionIndex(): array
    {
        $search = new ApprovalRequestSearch();
        $search->load((array)\Yii::$app->request->get(), '');
        if (!$search->validate()) {
            return $this->error((string)$search->getFirstError('status'), 422);
        }
        $query = ApprovalRequest::find()->with('job')->orderBy(['id' => SORT_DESC]);
        $filter = $this->access()->buildApprovalRequestFilter($this->currentUserId());
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        $dp = new ActiveDataProvider([
            'query' => $search->apply($query),
            'pagination' => ['pageSize' => 25],
        ]);
        $page = $this->requestedPage();
        /** @var list<ApprovalRequest> $models */
        $models = array_values($dp->getModels());

        return $this->paginated(
            $this->serializeAll($models),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionView(int $id): array
    {
        $model = $this->findModel($id);
        if (!$this->mayView($model)) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success($this->serializeAll([$model])[0]);
    }

    /**
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionApprove(int $id): array
    {
        return $this->decide($id, ApprovalDecision::DECISION_APPROVED);
    }

    /**
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionReject(int $id): array
    {
        return $this->decide($id, ApprovalDecision::DECISION_REJECTED);
    }

    /**
     * Visibility first (403), then the comment's type (422); then the
     * service checks the vote under the request's lock, and a refusal
     * answers with its status code.
     *
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    private function decide(int $id, string $decision): array
    {
        $model = $this->findModel($id);
        if (!$this->mayView($model)) {
            return $this->error('Forbidden.', 403);
        }

        $body = (array)\Yii::$app->request->bodyParams;

        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');
        try {
            $comment = ApprovalService::commentOf($model, $body['comment'] ?? null);
            $service->recordDecision($model, (int)\Yii::$app->user->id, $decision, $comment);
        } catch (ApprovalDecisionRefusedException $e) {
            if ($e->httpStatus() === 409) {
                return $this->errorWithDetails($e->getMessage(), 409, ['status' => $e->requestStatus()]);
            }
            return $this->error($e->getMessage(), $e->httpStatus());
        }

        $model->refresh();
        return $this->success($this->serializeAll([$model])[0]);
    }

    private function mayView(ApprovalRequest $model): bool
    {
        $userId = $this->currentUserId();
        return $userId !== null && $this->access()->canViewApprovalRequest($userId, $model);
    }

    private function currentUserId(): ?int
    {
        return \Yii::$app->user->isGuest ? null : (int)\Yii::$app->user->id;
    }

    private function access(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        return $checker;
    }

    private function contexts(): ApprovalRequestContexts
    {
        /** @var ApprovalRequestContexts $contexts */
        $contexts = \Yii::$app->get('approvalRequestContexts');
        return $contexts;
    }

    /**
     * $models as the API answers them, in a constant number of queries: the
     * votes that count (ApprovalRequest::countedDecisionsOf(), without the
     * launcher's, as approvalCount() and rejectionCount() count them), every
     * vote (ApprovalVotes) and what each request is for are read for all of
     * them at once. Pass requests with their job loaded (with('job')) to
     * read no job per request for launched_by.
     *
     * The votes ("decisions": who, how, the comment and when, of every vote
     * the request page lists, the launcher's too, in the order cast) are
     * answered to callers with approval.view, which the list and the detail
     * require; the answers of approve and reject, which need approval.decide
     * only, carry null instead for a caller without it.
     *
     * @param list<ApprovalRequest> $models
     * @return list<array<string, mixed>>
     */
    private function serializeAll(array $models): array
    {
        $ids = array_map(static fn (ApprovalRequest $m): int => (int)$m->id, $models);
        $counts = ApprovalRequest::countedDecisionsOf($ids);
        $contexts = $this->contexts()->forRequests($models, Viewer::current());
        $votes = $this->userCan('approval.view') ? ApprovalVotes::of($ids) : null;

        return array_map(
            static fn (ApprovalRequest $m): array => self::serialize(
                $m,
                $counts[(int)$m->id],
                $votes === null ? null : ($votes[(int)$m->id] ?? []),
                $contexts[(int)$m->id]
            ),
            $models
        );
    }

    /**
     * @param array{approved: int, rejected: int} $counts the votes that count
     * @param list<array{user_id: int, username: string, decision: string, comment: string|null, created_at: int}>|null $decisions
     *     every vote, in the order cast (ApprovalVotes); null for a caller without approval.view
     * @return array<string, mixed>
     */
    private static function serialize(ApprovalRequest $m, array $counts, ?array $decisions, RequestContext $context): array
    {
        return [
            'id' => $m->id,
            'job_id' => $m->job_id,
            // Who launched the job (for a workflow approval step: started the run); never an approver.
            'launched_by' => $m->launcherId(),
            'approval_rule_id' => $m->approval_rule_id,
        ] + $context->gateFields() + [
            'status' => $m->status,
            'requested_at' => $m->requested_at,
            'resolved_at' => $m->resolved_at,
            'expires_at' => $m->expires_at,
            'approval_count' => $counts['approved'],
            'rejection_count' => $counts['rejected'],
            'decisions' => $decisions,
        ] + $context->apiFields();
    }

    private function findModel(int $id): ApprovalRequest
    {
        /** @var ApprovalRequest|null $model */
        $model = ApprovalRequest::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Approval request #{$id} not found.");
        }
        return $model;
    }
}
