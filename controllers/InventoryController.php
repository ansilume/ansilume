<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\Inventory;
use app\models\Project;
use app\controllers\traits\TeamScopingTrait;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class InventoryController extends BaseController
{
    use TeamScopingTrait;

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
        $query = Inventory::find()->with(['creator', 'project'])->orderBy(['id' => SORT_DESC]);

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
        return $this->render('view', ['model' => $model]);
    }

    public function actionCreate(?int $project_id = null): Response|string
    {
        $model = new Inventory();
        $model->inventory_type = Inventory::TYPE_STATIC;
        if ($project_id !== null) {
            $model->project_id = $project_id;
        }
        if ($model->load((array)\Yii::$app->request->post())) {
            $this->requireChildOperate($model->project_id);
            $model->created_by = (int)(\Yii::$app->user->id ?? 0);
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(AuditLog::ACTION_INVENTORY_CREATED, 'inventory', $model->id, null, ['name' => $model->name]);
                $this->session()->setFlash('success', "Inventory \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', [
            'model' => $model,
            'projects' => $this->filteredProjects(),
        ]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
        if ($model->load((array)\Yii::$app->request->post()) && $model->save()) {
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_INVENTORY_UPDATED, 'inventory', $model->id, null, ['name' => $model->name]);
            $this->session()->setFlash('success', "Inventory \"{$model->name}\" updated.");
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', [
            'model' => $model,
            'projects' => $this->filteredProjects(),
        ]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $this->requireChildOperate($model->project_id);
        $name = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_INVENTORY_DELETED, 'inventory', $id, null, ['name' => $name]);
        $this->session()->setFlash('success', "Inventory \"{$name}\" deleted.");
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
