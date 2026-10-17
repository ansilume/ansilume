<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\Inventory;
use app\models\Project;
use app\models\User;
use app\controllers\traits\TeamScopingTrait;
use app\services\GatedInventoryLock;
use app\services\InventoryRuleAssignment;
use app\services\InventoryUsageService;
use app\services\InventoryWriteRefusedException;
use app\services\InventoryWriteService;
use yii\data\ActiveDataProvider;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Inventories in the web UI. Creating, changing and deleting one goes through
 * InventoryWriteService, which refuses with an InventoryWriteRefusedException
 * before anything is written: a 403 becomes a ForbiddenHttpException with its
 * message (the team scope checked again under the inventory's row lock in the
 * words of this page's own check, MODIFY_FORBIDDEN), a 404 a
 * NotFoundHttpException, and a delete of an inventory in use (409) a danger
 * flash on the inventory's page.
 *
 * A gated inventory (one with an approval rule) is for admins to change
 * (GatedInventoryLock): users who may not assign approval rules see its
 * notice, its host fields disabled, so that the form posts only its name and
 * description, and no Delete. Only they get the rule select of the form
 * (InventoryRuleAssignment); everyone else sees the rule as text. The
 * inventory's page lists the job templates that use it, as far as the viewer
 * may see them (InventoryUsageService).
 */
class InventoryController extends BaseController
{
    use TeamScopingTrait;

    /** sprintf(): the inventory's name. */
    public const FLASH_CREATED = 'Inventory "%s" created.';

    /** sprintf(): the inventory's name. */
    public const FLASH_UPDATED = 'Inventory "%s" updated.';

    /** sprintf(): the inventory's name. */
    public const FLASH_DELETED = 'Inventory "%s" deleted.';

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view', 'parse-hosts'], 'allow' => true, 'roles' => ['inventory.view']],
            ['actions' => ['create'], 'allow' => true, 'roles' => ['inventory.create']],
            ['actions' => ['update'], 'allow' => true, 'roles' => ['inventory.update']],
            ['actions' => ['delete'], 'allow' => true, 'roles' => ['inventory.delete']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return ['delete' => ['POST'], 'parse-hosts' => ['POST']];
    }

    public function actionIndex(): string
    {
        $query = Inventory::find()->with(['creator', 'project', 'approvalRule'])->orderBy(['id' => SORT_DESC]);

        $filter = $this->checker()->buildChildResourceFilter($this->currentUserId(), 'inventory.project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        $this->requireChildView($model->project_id);
        $userId = (int)$this->currentUserId();
        $operate = $this->checker()->canOperateChildResource($userId, $model->project_id);
        $locked = (new GatedInventoryLock())->appliesTo($userId, $model);
        $canChange = $operate && $this->userCan('inventory.update');

        return $this->render('view', [
            'model' => $model,
            // Edit: inventory.update and operator access to the inventory's project.
            'canChange' => $canChange,
            // Delete: inventory.delete and operator access, unless the lock keeps the user from it.
            'canDelete' => $operate && !$locked && $this->userCan('inventory.delete'),
            // Users who may change the inventory, but not its hosts, are told so.
            'changeLocked' => $canChange && $locked,
            // Everyone sees the inventory's approval rule; a link to it only for users who may open rules.
            'canViewRules' => $this->userCan('approval-rule.view'),
            'usage' => $this->usageService()->forInventory($model, $userId),
        ]);
    }

    public function actionCreate(?int $project_id = null): Response|string
    {
        $model = new Inventory();
        $model->inventory_type = Inventory::TYPE_STATIC;
        if ($project_id !== null) {
            $model->project_id = $project_id;
        }
        if (!$model->load((array)\Yii::$app->request->post())) {
            return $this->renderForm($model);
        }
        $this->requireChildOperate($model->project_id);
        $userId = (int)$this->currentUserId();
        $saved = $this->written(
            fn (): bool => $this->writer()->create($model, $this->submitted(), $userId, InventoryWriteService::SOURCE_WEB)
        );
        if ($saved) {
            $this->session()->setFlash('success', sprintf(self::FLASH_CREATED, $model->name));
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->renderForm($model);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
        if (!$model->load((array)\Yii::$app->request->post())) {
            return $this->renderForm($model);
        }
        // The project it moves to as well, not only the one it comes from.
        $this->requireChildOperate($model->project_id);
        $userId = (int)$this->currentUserId();
        $saved = $this->written(
            fn (): bool => $this->writer()->update($model, $this->submitted(), $userId, InventoryWriteService::SOURCE_WEB)
        );
        if ($saved) {
            $this->session()->setFlash('success', sprintf(self::FLASH_UPDATED, $model->name));
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->renderForm($model);
    }

    /**
     * Deletes the inventory unless job templates use it: then the inventory's
     * page shows why, as a danger flash (InventoryWriteService::DELETE_IN_USE).
     */
    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
        try {
            $this->writer()->delete($model, (int)$this->currentUserId(), InventoryWriteService::SOURCE_WEB);
        } catch (InventoryWriteRefusedException $e) {
            if ($e->status !== InventoryWriteRefusedException::CONFLICT) {
                throw self::httpException($e);
            }
            $this->session()->setFlash('danger', $e->getMessage());
            return $this->redirect(['view', 'id' => $id]);
        }
        $this->session()->setFlash('success', sprintf(self::FLASH_DELETED, $model->name));
        return $this->redirect(['index']);
    }

    public function actionParseHosts(int $id): Response
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        $model = $this->findModel($id);
        $this->requireChildView($model->project_id);

        /** @var \app\services\InventoryService $service */
        $service = \Yii::$app->get('inventoryService');
        $result = $service->resolveAndCache($model);

        return $this->asJson($result);
    }

    /**
     * The form, shown again with what was submitted. A list that a crafted
     * form posted for a field (Inventory[name][]=x) is shown as empty: the
     * rules refuse it, and a field cannot render it as text.
     *
     * Whether the lock applies and the inventory is gated follow its stored
     * state: after a failed update, that is the row the write service locked.
     */
    private function renderForm(Inventory $model): string
    {
        foreach ($model->getAttributes() as $attribute => $value) {
            if (is_array($value)) {
                $model->setAttribute($attribute, null);
            }
        }
        $userId = (int)$this->currentUserId();
        $rules = new InventoryRuleAssignment();
        $canAssignRule = $rules->mayAssign($userId);

        return $this->render('form', [
            'model' => $model,
            'projects' => $this->filteredProjects(),
            // approval-rule.update: the rule select, else the rule as text.
            'canAssignRule' => $canAssignRule,
            'approvalRuleOptions' => $canAssignRule ? $rules->options() : [],
            // A gated inventory, a user who may not assign rules: only its name and description are enabled.
            'changeLocked' => (new GatedInventoryLock())->appliesTo($userId, $model),
            // Where the hosts of a file or dynamic inventory come from, for those who give or meet a rule.
            'repositoryHostsNote' => $canAssignRule || GatedInventoryLock::isGated($model)
                ? GatedInventoryLock::REPOSITORY_HOSTS_NOTE
                : null,
        ]);
    }

    /**
     * The submitted Inventory fields, for the write service, which reads the
     * approval rule from them.
     *
     * @return array<array-key, mixed>
     */
    private function submitted(): array
    {
        return (array)\Yii::$app->request->post('Inventory', []);
    }

    /**
     * Runs $write (InventoryWriteService::create() or update()): whether it
     * saved.
     *
     * @param callable(): bool $write
     * @throws HttpException the write service's refusal (httpException())
     */
    private function written(callable $write): bool
    {
        try {
            return $write();
        } catch (InventoryWriteRefusedException $e) {
            throw self::httpException($e);
        }
    }

    /**
     * The answer to a refusal of the write service other than a 409: 404 or
     * 403 with its message; the team scope it checked again under the row
     * lock in the words of this page's own check (MODIFY_FORBIDDEN).
     */
    private static function httpException(InventoryWriteRefusedException $e): HttpException
    {
        if ($e->status === InventoryWriteRefusedException::NOT_FOUND) {
            return new NotFoundHttpException($e->getMessage());
        }
        $teamScope = $e->getMessage() === InventoryWriteService::TEAM_SCOPE_REFUSED;

        return new ForbiddenHttpException($teamScope ? self::MODIFY_FORBIDDEN : $e->getMessage());
    }

    private function writer(): InventoryWriteService
    {
        /** @var InventoryWriteService $writer */
        $writer = \Yii::$app->get('inventoryWriteService');

        return $writer;
    }

    private function usageService(): InventoryUsageService
    {
        /** @var InventoryUsageService $service */
        $service = \Yii::$app->get('inventoryUsageService');

        return $service;
    }

    /**
     * Whether the signed-in user holds $permission; superadmins hold every
     * permission, as in the access rules.
     */
    private function userCan(string $permission): bool
    {
        $identity = \Yii::$app->user->identity;

        return ($identity instanceof User && (bool)$identity->is_superadmin) || \Yii::$app->user->can($permission);
    }

    /**
     * @return Project[]
     */
    private function filteredProjects(): array
    {
        $query = Project::find()->orderBy('name');
        $filter = $this->checker()->buildProjectFilter($this->currentUserId());
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        return $query->all();
    }

    private function findModel(int $id): Inventory
    {
        /** @var Inventory|null $model */
        $model = Inventory::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Inventory #{$id} not found.");
        }
        return $model;
    }
}
