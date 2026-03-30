<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\User;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

class AuditLogController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        // Audit log is admin-only: only users with user.view (admin+) or superadmin
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['user.view']],
        ];
    }

    public function actionIndex(): string
    {
        $request = \Yii::$app->request;

        $query = AuditLog::find()
            ->with('user')
            ->orderBy(['id' => SORT_DESC]);

        $filterAction = $request->get('action');
        $filterUser = $request->get('user_id');
        $filterObject = $request->get('object_type');

        if ($filterAction) {
            $query->andWhere(['like', 'action', $filterAction]);
        }
        if ($filterUser && ctype_digit((string)$filterUser)) {
            $query->andWhere(['user_id' => (int)$filterUser]);
        }
        if ($filterObject) {
            $query->andWhere(['object_type' => $filterObject]);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 50],
        ]);

        $users = User::find()->orderBy('username')->all();

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'filterAction' => $filterAction,
            'filterUser' => $filterUser,
            'filterObject' => $filterObject,
            'users' => $users,
        ]);
    }

    public function actionView(int $id): string
    {
        $entry = AuditLog::findOne($id);
        if ($entry === null) {
            throw new NotFoundHttpException("Audit log entry #{$id} not found.");
        }
        return $this->render('view', ['entry' => $entry]);
    }
}
