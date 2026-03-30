<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\Credential;
use app\services\CredentialService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class CredentialController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'view'], 'allow' => true, 'roles' => ['credential.view']],
            ['actions' => ['create', 'generate-ssh-key'], 'allow' => true, 'roles' => ['credential.create']],
            ['actions' => ['update'], 'allow' => true, 'roles' => ['credential.update']],
            ['actions' => ['delete'], 'allow' => true, 'roles' => ['credential.delete']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return ['delete' => ['POST'], 'generate-ssh-key' => ['POST']];
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
        $model = $this->findModel($id);
        $sshInfo = null;
        if ($model->credential_type === Credential::TYPE_SSH_KEY && !empty($model->secret_data)) {
            /** @var CredentialService $cs */
            $cs = \Yii::$app->get('credentialService');
            $secrets = $cs->getSecrets($model);

            $publicKey = $secrets['public_key'] ?? '';
            $algorithm = $secrets['algorithm'] ?? '';
            $bits = (int)($secrets['bits'] ?? 0);
            $keySecure = $secrets['key_secure'] ?? null;

            // Derive public key on-the-fly if not yet stored (legacy credentials)
            if ($publicKey === '' && !empty($secrets['private_key'])) {
                $analysis = $cs->analyzePrivateKey($secrets['private_key']);
                $publicKey = $analysis['public_key'];
                $algorithm = $analysis['algorithm'];
                $bits = $analysis['bits'];
                $keySecure = $analysis['key_secure'];
            }

            $sshInfo = [
                'public_key' => $publicKey,
                'algorithm' => $algorithm,
                'bits' => $bits,
                'key_secure' => $keySecure,
            ];
        }
        return $this->render('view', ['model' => $model, 'sshInfo' => $sshInfo]);
    }

    public function actionCreate(): Response|string
    {
        $model = new Credential();
        if ($model->load((array)\Yii::$app->request->post())) {
            $model->created_by = (int)(\Yii::$app->user->id ?? 0);
            if ($model->validate()) {
                /** @var CredentialService $cs */
                $cs = \Yii::$app->get('credentialService');
                /** @var array<string, string> $secrets */
                $secrets = $this->extractSecrets($model->credential_type, $cs);
                if ($cs->storeSecrets($model, $secrets)) {
                    \Yii::$app->get('auditService')->log(
                        AuditLog::ACTION_CREDENTIAL_CREATED,
                        'credential',
                        $model->id,
                        null,
                        ['name' => $model->name, 'type' => $model->credential_type]
                    );
                    $this->session()->setFlash('success', "Credential \"{$model->name}\" created.");
                    return $this->redirect(['view', 'id' => $model->id]);
                }
            }
        }
        return $this->render('form', ['model' => $model, 'secrets' => []]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        if ($model->load((array)\Yii::$app->request->post())) {
            if ($model->validate()) {
                /** @var CredentialService $cs */
                $cs = \Yii::$app->get('credentialService');
                /** @var array<string, string> $secrets */
                $secrets = $this->extractSecrets($model->credential_type, $cs);
                if (!empty(array_filter($secrets))) {
                    $cs->storeSecrets($model, $secrets);
                } else {
                    $model->save();
                }
                \Yii::$app->get('auditService')->log(
                    AuditLog::ACTION_CREDENTIAL_UPDATED,
                    'credential',
                    $model->id,
                    null,
                    ['name' => $model->name]
                );
                $this->session()->setFlash('success', "Credential \"{$model->name}\" updated.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', ['model' => $model, 'secrets' => []]);
    }

    /**
     * AJAX: generate a fresh Ed25519 key pair and return it as JSON.
     * Used by the credential form's "Generate Key Pair" button.
     */
    public function actionGenerateSshKey(): Response
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            /** @var CredentialService $cs */
            $cs = \Yii::$app->get('credentialService');
            $pair = $cs->generateSshKeyPair();
            return $this->asJson(['ok' => true, 'private_key' => $pair['private_key'], 'public_key' => $pair['public_key']]);
        } catch (\RuntimeException $e) {
            \Yii::$app->response->statusCode = 500;
            return $this->asJson(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $name = $model->name;
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_CREDENTIAL_DELETED,
            'credential',
            $id,
            null,
            ['name' => $name]
        );
        $model->delete();
        $this->session()->setFlash('success', "Credential \"{$name}\" deleted.");
        return $this->redirect(['index']);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSecrets(string $type, CredentialService $cs): array
    {
        $post = (array)\Yii::$app->request->post('secrets', []);
        if ($type !== Credential::TYPE_SSH_KEY) {
            return match ($type) {
                Credential::TYPE_USERNAME_PASSWORD => ['password' => $post['password'] ?? ''],
                Credential::TYPE_VAULT => ['vault_password' => $post['vault_password'] ?? ''],
                Credential::TYPE_TOKEN => ['token' => $post['token'] ?? ''],
                default => [],
            };
        }

        // Normalise line endings — browsers submit \r\n from textareas
        /** @var string $rawKey */
        $rawKey = $post['private_key'] ?? '';
        $privateKey = str_replace("\r\n", "\n", str_replace("\r", "\n", $rawKey));
        $secrets = ['private_key' => $privateKey];

        if ($privateKey !== '') {
            $analysis = $cs->analyzePrivateKey($privateKey);
            $secrets['public_key'] = $analysis['public_key'];
            $secrets['algorithm'] = $analysis['algorithm'];
            $secrets['bits'] = $analysis['bits'];
            $secrets['key_secure'] = $analysis['key_secure'];
        }

        return $secrets;
    }

    private function findModel(int $id): Credential
    {
        /** @var Credential|null $model */
        $model = Credential::findOne($id);
        if ($model === null) {
            throw new NotFoundHttpException("Credential #{$id} not found.");
        }
        return $model;
    }
}
