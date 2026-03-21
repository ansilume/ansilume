<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\Credential;
use app\services\AuditService;
use app\services\CredentialService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class CredentialController extends BaseController
{
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'],   'allow' => true, 'roles' => ['credential.view']],
            ['actions' => ['create'],           'allow' => true, 'roles' => ['credential.create']],
            ['actions' => ['update'],           'allow' => true, 'roles' => ['credential.update']],
            ['actions' => ['delete'],           'allow' => true, 'roles' => ['credential.delete']],
        ];
    }

    protected function verbRules(): array
    {
        return ['delete' => ['POST']];
    }

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => Credential::find()->with('creator')->orderBy(['id' => SORT_DESC]),
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
        $model = new Credential();
        if ($model->load(\Yii::$app->request->post())) {
            $model->created_by = \Yii::$app->user->id;
            if ($model->validate()) {
                $secrets = $this->extractSecrets($model->credential_type);
                /** @var CredentialService $cs */
                $cs = \Yii::$app->get('credentialService');
                if ($cs->storeSecrets($model, $secrets)) {
                    \Yii::$app->get('auditService')->log(
                        AuditService::ACTION_CREDENTIAL_CREATED,
                        'credential', $model->id, null,
                        ['name' => $model->name, 'type' => $model->credential_type]
                    );
                    \Yii::$app->session->setFlash('success', "Credential "{$model->name}" created.");
                    return $this->redirect(['view', 'id' => $model->id]);
                }
            }
        }
        return $this->render('form', ['model' => $model, 'secrets' => []]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        if ($model->load(\Yii::$app->request->post())) {
            if ($model->validate()) {
                $secrets = $this->extractSecrets($model->credential_type);
                /** @var CredentialService $cs */
                $cs = \Yii::$app->get('credentialService');
                if (!empty(array_filter($secrets))) {
                    $cs->storeSecrets($model, $secrets);
                } else {
                    $model->save();
                }
                \Yii::$app->get('auditService')->log(
                    AuditService::ACTION_CREDENTIAL_UPDATED,
                    'credential', $model->id, null, ['name' => $model->name]
                );
                \Yii::$app->session->setFlash('success', "Credential "{$model->name}" updated.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', ['model' => $model, 'secrets' => []]);
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $name  = $model->name;
        \Yii::$app->get('auditService')->log(
            AuditService::ACTION_CREDENTIAL_DELETED, 'credential', $id, null, ['name' => $name]
        );
        $model->delete();
        \Yii::$app->session->setFlash('success', "Credential "{$name}" deleted.");
        return $this->redirect(['index']);
    }

    private function extractSecrets(string $type): array
    {
        $post = \Yii::$app->request->post('secrets', []);
        return match ($type) {
            Credential::TYPE_SSH_KEY           => ['private_key'    => $post['private_key'] ?? ''],
            Credential::TYPE_USERNAME_PASSWORD => ['password'       => $post['password'] ?? ''],
            Credential::TYPE_VAULT             => ['vault_password' => $post['vault_password'] ?? ''],
            Credential::TYPE_TOKEN             => ['token'          => $post['token'] ?? ''],
            default                            => [],
        };
    }

    private function findModel(int $id): Credential
    {
        $model = Credential::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Credential #{$id} not found.");
        }
        return $model;
    }
}
