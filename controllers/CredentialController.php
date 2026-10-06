<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\CredentialUsage;
use app\models\Credential;
use app\services\CredentialService;
use app\services\CredentialUsageService;
use app\services\CredentialWriteService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Credentials in the web UI. Writes go through CredentialWriteService, the
 * same rules as the REST API: the type's secret is required, a type change
 * needs the new type's secret, and a credential in use is only deleted when
 * the operator confirms the forced delete.
 */
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
        $secretStatus = $this->credentialService()->secretStatus($model);

        return $this->render('view', [
            'model' => $model,
            'secretStatus' => $secretStatus,
            // Decrypting a broken or empty secret would fail; the status says why.
            'sshInfo' => $secretStatus === CredentialService::SECRET_STATUS_OK ? $this->sshInfo($model) : null,
            'usage' => $this->usage($model),
        ]);
    }

    public function actionCreate(): Response|string
    {
        $model = new Credential();
        if ($model->load((array)\Yii::$app->request->post())) {
            $model->created_by = (int)(\Yii::$app->user->id ?? 0);
            if ($this->writeService()->create($model, $this->postedSecrets())) {
                $this->session()->setFlash('success', "Credential \"{$model->name}\" created.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', ['model' => $model]);
    }

    public function actionUpdate(int $id): Response|string
    {
        $model = $this->findModel($id);
        if ($model->load((array)\Yii::$app->request->post())) {
            if ($this->writeService()->update($model, $this->postedSecrets())) {
                $this->session()->setFlash('success', "Credential \"{$model->name}\" updated.");
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }
        return $this->render('form', ['model' => $model]);
    }

    /**
     * AJAX: generate a fresh Ed25519 key pair and return it as JSON.
     * Used by the credential form's "Generate Key Pair" button.
     */
    public function actionGenerateSshKey(): Response
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        try {
            $pair = $this->credentialService()->generateSshKeyPair();
            return $this->asJson(['ok' => true, 'private_key' => $pair['private_key'], 'public_key' => $pair['public_key']]);
        } catch (\RuntimeException $e) {
            \Yii::error('SSH key generation failed: ' . $e->getMessage(), __CLASS__);
            \Yii::$app->response->statusCode = 500;
            return $this->asJson(['ok' => false, 'error' => 'Key generation failed.']);
        }
    }

    /**
     * A credential in use is only deleted with force=1, which the delete
     * button on the credential page sends after the operator confirmed it.
     */
    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);
        $force = (string)\Yii::$app->request->post('force', '') === '1';
        $result = $this->writeService()->delete($model, $force, $this->viewerId());
        if (!$result['deleted']) {
            $this->session()->setFlash('danger', $result['usage']->summary() . ' Review the usage below before deleting it.');
            return $this->redirect(['view', 'id' => $id]);
        }

        $this->session()->setFlash('success', $this->deletedMessage($result['usage']));
        return $this->redirect(['index']);
    }

    private function deletedMessage(CredentialUsage $usage): string
    {
        $message = "Credential \"{$usage->credentialName}\" deleted.";
        if ($usage->isInUse()) {
            $message .= ' It was detached from every job template and project that used it.';
        }

        return $message;
    }

    /**
     * Public key and strength of an SSH key credential, derived on the fly
     * for credentials saved before the metadata was stored.
     *
     * @return array{public_key: string, algorithm: string, bits: int, key_secure: bool|null}|null
     */
    private function sshInfo(Credential $model): ?array
    {
        if ($model->credential_type !== Credential::TYPE_SSH_KEY) {
            return null;
        }
        $cs = $this->credentialService();
        $secrets = $cs->getSecrets($model);
        if (empty($secrets['public_key'])) {
            $secrets = $cs->analyzePrivateKey((string)$secrets['private_key']) + $secrets;
        }

        return [
            'public_key' => (string)($secrets['public_key'] ?? ''),
            'algorithm' => (string)($secrets['algorithm'] ?? ''),
            'bits' => (int)($secrets['bits'] ?? 0),
            'key_secure' => isset($secrets['key_secure']) ? (bool)$secrets['key_secure'] : null,
        ];
    }

    private function usage(Credential $model): CredentialUsage
    {
        /** @var CredentialUsageService $service */
        $service = \Yii::$app->get('credentialUsageService');

        return $service->forCredential($model, $this->viewerId());
    }

    /**
     * @return array<array-key, mixed>
     */
    private function postedSecrets(): array
    {
        $secrets = \Yii::$app->request->post('secrets', []);

        return is_array($secrets) ? $secrets : [];
    }

    private function viewerId(): ?int
    {
        $id = \Yii::$app->user->id;

        return $id !== null ? (int)$id : null;
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

    private function credentialService(): CredentialService
    {
        /** @var CredentialService $service */
        $service = \Yii::$app->get('credentialService');

        return $service;
    }

    private function writeService(): CredentialWriteService
    {
        /** @var CredentialWriteService $service */
        $service = \Yii::$app->get('credentialWriteService');

        return $service;
    }
}
