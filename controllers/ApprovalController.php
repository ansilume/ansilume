<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRequestSearch;
use app\models\User;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalService;
use app\services\runstate\ApprovalRequestContexts;
use app\services\runstate\Viewer;
use app\services\WorkflowAccessChecker;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Approval requests. Team scoping applies: a request is visible to users who
 * may view the job's project (or, for a workflow approval step, the
 * workflow), and only visible requests can be decided on.
 *
 * The list and the request page say what each request is for
 * (ApprovalRequestContexts, views/approval/_for.php): the workflow, step and
 * run of its job, named only to a user who may view that workflow, else the
 * job's template. The list filters by status (?status=, ApprovalRequestSearch),
 * ignoring a status it does not know.
 */
class ApprovalController extends BaseController
{
    /** The title of the list. */
    public const LIST_TITLE = 'Approval Requests';

    /** The header of the column, and of the request page's row, that says what a request is for. */
    public const FOR_HEADER = 'For';

    /** The columns of the list, in order. */
    public const LIST_COLUMNS = ['#', 'Job', self::FOR_HEADER, 'Rule', 'Status', 'Requested', 'Expires', 'Resolved'];

    /** The status filter's link to every request. */
    public const FILTER_ALL = 'All';

    /** The label of the status filter (aria-label). */
    public const FILTER_LABEL = 'Filter approval requests by status';

    /** The list without requests. */
    public const NO_REQUESTS = 'No approval requests.';

    /** The list filtered by a status without requests; sprintf(): the status label. */
    public const NO_REQUESTS_WITH_STATUS = 'No approval requests with the status %s.';

    /** Expires of a pending request without a timeout. */
    public const EXPIRES_NEVER = 'Never';

    /**
     * How the For cell names the run, as Texts::FOR_WORKFLOW_STEP writes it,
     * linked to the run for a user with workflow.view; sprintf(): the run ID.
     */
    public const RUN_LABEL = 'Workflow Job #%d';

    /** The dashboard's link to the pending requests (/approval/index?status=pending). */
    public const ALL_PENDING = 'All pending';

    /** The dashboard's link to a request: a pending one, or the one a running workflow awaits. */
    public const REVIEW = 'Review';

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['approval.view']],
            ['actions' => ['approve', 'reject'], 'allow' => true, 'roles' => ['approval.decide']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return [
            'approve' => ['POST'],
            'reject' => ['POST'],
        ];
    }

    /**
     * The requests the user may see, newest first, optionally of one status;
     * what each one is for is read for the whole page at once.
     */
    public function actionIndex(): string
    {
        $search = new ApprovalRequestSearch();
        $search->load((array)\Yii::$app->request->get(), '');
        $query = $search->apply(ApprovalRequest::find()->with(['approvalRule'])->orderBy(['id' => SORT_DESC]));
        $filter = $this->access()->buildApprovalRequestFilter($this->currentUserId());
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 20],
        ]);
        /** @var list<ApprovalRequest> $models */
        $models = array_values($dataProvider->getModels());
        $viewer = Viewer::current();

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'contexts' => $this->contexts()->forRequests($models, $viewer),
            'activeStatus' => $search->activeStatus(),
            'viewer' => $viewer,
        ]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findVisibleModel($id);
        $viewer = Viewer::current();

        return $this->render('view', [
            'model' => $model,
            'context' => $this->contexts()->forRequests([$model], $viewer)[(int)$model->id] ?? null,
            'viewer' => $viewer,
        ] + $this->decisionState($model));
    }

    public function actionApprove(int $id): Response
    {
        return $this->decide($id, ApprovalDecision::DECISION_APPROVED);
    }

    public function actionReject(int $id): Response
    {
        return $this->decide($id, ApprovalDecision::DECISION_REJECTED);
    }

    /**
     * Visibility first (403). The service then checks the vote under the
     * request's lock; a refusal is shown as ApprovalDecisionRefusedException
     * classifies it: the request's state (resolved, expired, its job no
     * longer waiting) as a warning, the voter (the launcher, no eligible
     * approver) as 403, and the vote itself (a second vote, a comment that
     * is too long, or one that is no text, which only a forged form sends)
     * as a danger flash, never as a server error.
     */
    private function decide(int $id, string $decision): Response
    {
        $model = $this->findVisibleModel($id);
        try {
            $comment = ApprovalService::commentOf($model, \Yii::$app->request->post('comment'));
            $this->approvals()->recordDecision(
                $model,
                (int)\Yii::$app->user->id,
                $decision,
                $comment !== '' ? $comment : null
            );
        } catch (ApprovalDecisionRefusedException $e) {
            return $this->refused($e, $id);
        }

        $recorded = $decision === ApprovalDecision::DECISION_APPROVED ? 'Approval recorded.' : 'Rejection recorded.';
        $this->session()->setFlash('success', $recorded);
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    private function refused(ApprovalDecisionRefusedException $e, int $id): Response
    {
        $status = $e->httpStatus();
        if ($status === 403) {
            throw new ForbiddenHttpException($e->getMessage());
        }
        if ($status === 404) {
            throw new NotFoundHttpException($e->getMessage());
        }
        if ($status === 409) {
            $this->session()->setFlash('warning', $e->getMessage());
        } else {
            $this->session()->setFlash('danger', 'Your decision was not recorded: ' . $e->getMessage());
        }
        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * Whether the view offers Approve and Reject, and if not, why. Only for
     * users who hold approval.decide (or are superadmins): the buttons show
     * when their vote would be recorded now; otherwise the note says why,
     * unless they are simply no approver of the request.
     *
     * @return array{canDecide: bool, decisionNote: string|null}
     */
    private function decisionState(ApprovalRequest $model): array
    {
        $user = \Yii::$app->user;
        $identity = $user->identity;
        $superadmin = $identity instanceof User && (bool)$identity->is_superadmin;
        if (!$superadmin && !$user->can('approval.decide')) {
            return ['canDecide' => false, 'decisionNote' => null];
        }

        $refusal = $this->approvals()->decisionRefusal($model, (int)$user->id);
        if ($refusal === null) {
            return ['canDecide' => true, 'decisionNote' => null];
        }
        $silent = $refusal->reason() === ApprovalDecisionRefusedException::REASON_NOT_ELIGIBLE;

        return ['canDecide' => false, 'decisionNote' => $silent ? null : $refusal->getMessage()];
    }

    /**
     * 404 when the request does not exist, 403 when the user may not see it.
     */
    private function findVisibleModel(int $id): ApprovalRequest
    {
        /** @var ApprovalRequest|null $model */
        $model = ApprovalRequest::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Approval request #{$id} not found.");
        }
        $userId = $this->currentUserId();
        if ($userId === null || !$this->access()->canViewApprovalRequest($userId, $model)) {
            throw new ForbiddenHttpException('You do not have access to this resource.');
        }
        return $model;
    }

    private function currentUserId(): ?int
    {
        return \Yii::$app->user->isGuest ? null : (int)\Yii::$app->user->id;
    }

    private function approvals(): ApprovalService
    {
        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');
        return $service;
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
}
