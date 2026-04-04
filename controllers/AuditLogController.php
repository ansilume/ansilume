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
        $webRequest = \Yii::$app->request;
        assert($webRequest instanceof \yii\web\Request);

        $query = AuditLog::find()
            ->with('user')
            ->orderBy(['id' => SORT_DESC]);

        $filterAction = $webRequest->get('action');
        $filterUser = $webRequest->get('user_id');
        $filterObject = $webRequest->get('object_type');

        if (is_string($filterAction) && $filterAction !== '') {
            $query->andWhere(['like', 'action', $filterAction]);
        }
        if (is_string($filterUser) && ctype_digit($filterUser)) {
            $query->andWhere(['user_id' => (int)$filterUser]);
        }
        if (is_string($filterObject) && $filterObject !== '') {
            $query->andWhere(['object_type' => $filterObject]);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 50],
        ]);

        $users = User::find()->orderBy('username')->all();

        // Resolve object names for rows whose object_type is "user" so the
        // table can show the username instead of just "user #7".
        /** @var AuditLog[] $rows */
        $rows = $dataProvider->getModels();
        $userObjectIds = [];
        foreach ($rows as $row) {
            if ($row->object_type === 'user' && $row->object_id !== null) {
                $userObjectIds[] = (int)$row->object_id;
            }
        }
        $objectUsernames = [];
        if ($userObjectIds !== []) {
            /** @var array<int, string> $objectUsernames */
            $objectUsernames = User::find()
                ->select(['username', 'id'])
                ->where(['id' => array_unique($userObjectIds)])
                ->indexBy('id')
                ->column();
        }

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'filterAction' => $filterAction,
            'filterUser' => $filterUser,
            'filterObject' => $filterObject,
            'users' => $users,
            'objectUsernames' => $objectUsernames,
        ]);
    }

    public function actionView(int $id): string
    {
        /** @var AuditLog|null $entry */
        $entry = AuditLog::findOne($id);
        if ($entry === null) {
            throw new NotFoundHttpException("Audit log entry #{$id} not found.");
        }
        return $this->render('view', ['entry' => $entry]);
    }
}
