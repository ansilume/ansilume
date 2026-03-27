<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\Inventory;
use app\models\Project;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class InventoryController extends BaseController
{
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view', 'parse-hosts'], 'allow' => true, 'roles' => ['inventory.view']],
            ['actions' => ['create'],           'allow' => true, 'roles' => ['inventory.create']],
            ['actions' => ['update'],           'allow' => true, 'roles' => ['inventory.update']],
            ['actions' => ['delete'],           'allow' => true, 'roles' => ['inventory.delete']],
        ];
    }

    protected function verbRules(): array
    {
        return ['delete' => ['POST'], 'parse-hosts' => ['POST']];
    }

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => Inventory::find()->with(['creator', 'project'])->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        return $this->render('view', ['model' => $this->findModel($id)]);
    }

    public function actionCreate(?int $project_id = null): Response|string
    {
        $model = new Inventory();
        $model->inventory_type = Inventory::TYPE_STATIC;
        if ($project_id !== null) {
            $model->project_id = $project_id;
        }
        if ($model->load(\Yii::$app->request->post())) {
            $model->created_by = \Yii::$app->user->id;
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(AuditLog::ACTION_INVENTORY_CREATED, 'inventory', $model->id, null, ['name' => $model->name]);
                $this->session()->setFlash('success', "Inventory \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', [
            'model'    => $model,
            'projects' => Project::find()->orderBy('name')->all(),
        ]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        if ($model->load(\Yii::$app->request->post()) && $model->save()) {
            \Yii::$app->get('auditService')->log(AuditLog::ACTION_INVENTORY_UPDATED, 'inventory', $model->id, null, ['name' => $model->name]);
            $this->session()->setFlash('success', "Inventory \"{$model->name}\" updated.");
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', [
            'model'    => $model,
            'projects' => Project::find()->orderBy('name')->all(),
        ]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $name  = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_INVENTORY_DELETED, 'inventory', $id, null, ['name' => $name]);
        $this->session()->setFlash('success', "Inventory \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    public function actionParseHosts(int $id): Response
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        $model = $this->findModel($id);

        /** @var \app\services\InventoryService $service */
        $service = \Yii::$app->get('inventoryService');
        $result  = $service->resolveAndCache($model);

        return $this->asJson($result);
    }

    private function findModel(int $id): Inventory
    {
        $model = Inventory::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Inventory #{$id} not found.");
        }
        return $model;
    }
}
