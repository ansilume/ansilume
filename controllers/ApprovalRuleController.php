<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Team;
use app\models\User;
use yii\data\ActiveDataProvider;
use yii\helpers\ArrayHelper;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ApprovalRuleController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['approval-rule.view']],
            ['actions' => ['create'], 'allow' => true, 'roles' => ['approval-rule.create']],
            ['actions' => ['update'], 'allow' => true, 'roles' => ['approval-rule.update']],
            ['actions' => ['delete'], 'allow' => true, 'roles' => ['approval-rule.delete']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return ['delete' => ['POST']];
    }

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => ApprovalRule::find()->with('creator')->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 20],
        ]);
        return $this->render('index', ['dataProvider' => $dataProvider]);
    }

    public function actionView(int $id): string
    {
        return $this->render('view', ['model' => $this->findModel($id)]);
    }

    public function actionCreate(): Response|string
    {
        $model = new ApprovalRule();
        $model->required_approvals = 1;
        $model->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $model->approver_type = ApprovalRule::APPROVER_TYPE_ROLE;

        if ($model->load((array)\Yii::$app->request->post())) {
            $model->created_by = (int)\Yii::$app->user->id;
            if ($model->save()) {
                \Yii::$app->get('auditService')->log(
                    AuditLog::ACTION_APPROVAL_RULE_CREATED,
                    'approval_rule',
                    $model->id,
                    null,
                    ['name' => $model->name]
                );
                $this->session()->setFlash('success', "Approval rule \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', $this->formParams($model));
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        if ($model->load((array)\Yii::$app->request->post()) && $model->save()) {
            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_APPROVAL_RULE_UPDATED,
                'approval_rule',
                $model->id,
                null,
                ['name' => $model->name]
            );
            $this->session()->setFlash('success', "Approval rule \"{$model->name}\" updated.");
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', $this->formParams($model));
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $name = $model->name;
        $model->delete();
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_APPROVAL_RULE_DELETED,
            'approval_rule',
            $id,
            null,
            ['name' => $name]
        );
        $this->session()->setFlash('success', "Approval rule \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    /**
     * @return array{model: ApprovalRule, roles: array<string, string>, teams: array<int, string>, users: array<int, string>}
     */
    private function formParams(ApprovalRule $model): array
    {
        $auth = \Yii::$app->authManager;
        $roleNames = [];
        if ($auth !== null) {
            foreach ($auth->getRoles() as $role) {
                $roleNames[$role->name] = $role->name;
            }
            ksort($roleNames);
        }

        /** @var array<int, string> $teams */
        $teams = ArrayHelper::map(
            Team::find()->orderBy('name')->all(),
            'id',
            'name'
        );

        /** @var array<int, string> $users */
        $users = ArrayHelper::map(
            User::find()->where(['status' => User::STATUS_ACTIVE])->orderBy('username')->all(),
            'id',
            'username'
        );

        return [
            'model' => $model,
            'roles' => $roleNames,
            'teams' => $teams,
            'users' => $users,
        ];
    }

    private function findModel(int $id): ApprovalRule
    {
        /** @var ApprovalRule|null $model */
        $model = ApprovalRule::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Approval rule #{$id} not found.");
        }
        return $model;
    }
}
