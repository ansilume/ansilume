<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\ApprovalRule;
use app\models\Team;
use app\models\User;
use app\services\ApprovalRuleUsageService;
use app\services\ApprovalRuleWriteService;
use yii\data\ActiveDataProvider;
use yii\helpers\ArrayHelper;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Approval rules in the web UI. Writes go through ApprovalRuleWriteService,
 * the same rules as the REST API: a rule in use is not deleted, and the
 * audit records what changed. Creating and changing rules needs
 * approval-rule.create / approval-rule.update, which only admins hold by
 * default; everyone may view them.
 */
class ApprovalRuleController extends BaseController
{
    public const FLASH_CREATED = 'Approval rule "%s" created.';
    public const FLASH_UPDATED = 'Approval rule "%s" updated.';
    public const FLASH_DELETED = 'Approval rule "%s" deleted.';

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
        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'canCreate' => $this->userCan('approval-rule.create'),
        ]);
    }

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);

        return $this->render('view', [
            'model' => $model,
            'usage' => $this->usageService()->forRule($model, $this->viewerId()),
            'canUpdate' => $this->userCan('approval-rule.update'),
            'canDelete' => $this->userCan('approval-rule.delete'),
        ]);
    }

    public function actionCreate(): Response|string
    {
        $model = new ApprovalRule();
        $model->required_approvals = 1;
        $model->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $model->approver_type = ApprovalRule::APPROVER_TYPE_ROLE;

        if (
            $model->load((array)\Yii::$app->request->post())
            && $this->writeService()->create($model, (int)\Yii::$app->user->id, ApprovalRuleWriteService::SOURCE_WEB)
        ) {
            $this->session()->setFlash('success', sprintf(self::FLASH_CREATED, $model->name));
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', $this->formParams($model));
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        if (
            $model->load((array)\Yii::$app->request->post())
            && $this->writeService()->update($model, ApprovalRuleWriteService::SOURCE_WEB)
        ) {
            $this->session()->setFlash('success', sprintf(self::FLASH_UPDATED, $model->name));
            return $this->redirect(['view', 'id' => $model->id]);
        }
        return $this->render('form', $this->formParams($model));
    }

    /**
     * A rule in use stays: the flash says what uses it, and the rule's page
     * lists it.
     */
    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $result = $this->writeService()->delete($model, $this->viewerId(), ApprovalRuleWriteService::SOURCE_WEB);
        if (!$result['deleted']) {
            $this->session()->setFlash('danger', ApprovalRuleWriteService::refusal($result['usage']));
            return $this->redirect(['view', 'id' => $id]);
        }
        $this->session()->setFlash('success', sprintf(self::FLASH_DELETED, $model->name));
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

    /**
     * Whether the signed-in user holds $permission; superadmins hold every
     * permission, as in the access rules.
     */
    private function userCan(string $permission): bool
    {
        $identity = \Yii::$app->user->identity;
        if ($identity instanceof User && (bool)$identity->is_superadmin) {
            return true;
        }

        return \Yii::$app->user->can($permission);
    }

    private function viewerId(): ?int
    {
        $id = \Yii::$app->user->id;

        return $id !== null ? (int)$id : null;
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

    private function usageService(): ApprovalRuleUsageService
    {
        /** @var ApprovalRuleUsageService $service */
        $service = \Yii::$app->get('approvalRuleUsageService');

        return $service;
    }

    private function writeService(): ApprovalRuleWriteService
    {
        /** @var ApprovalRuleWriteService $service */
        $service = \Yii::$app->get('approvalRuleWriteService');

        return $service;
    }
}
