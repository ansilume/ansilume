<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\models\AuditLog;
use app\models\Credential;
use app\services\CredentialService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Credentials
 *
 * GET    /api/v1/credentials
 * GET    /api/v1/credentials/{id}
 * POST   /api/v1/credentials
 * PUT    /api/v1/credentials/{id}
 * DELETE /api/v1/credentials/{id}
 *
 * Secret material is NEVER returned in responses — not even redacted placeholders.
 */
class CredentialsController extends BaseApiController
{
    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => Credential::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);
        /** @var int $page */
        $page = \Yii::$app->request->get('page', 1);

        return $this->paginated(
            array_map(fn ($c) => $this->serialize($c), $dp->getModels()),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    /**
     * @return array{data: mixed}
     */
    public function actionView(int $id): array
    {
        return $this->success($this->serialize($this->findModel($id)));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('credential.create')) {
            return $this->error('Forbidden.', 403);
        }

        $model = new Credential();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)$user->id;

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }

        /** @var CredentialService $cs */
        $cs = \Yii::$app->get('credentialService');
        /** @var array<string, string> $secrets */
        $secrets = $this->extractSecrets($model->credential_type, $body, $cs);

        if (!$cs->storeSecrets($model, $secrets)) {
            return $this->error($this->firstError($model), 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_CREDENTIAL_CREATED,
            'credential',
            $model->id,
            null,
            ['name' => $model->name, 'type' => $model->credential_type, 'source' => 'api']
        );

        return $this->success($this->serialize($model), 201);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('credential.update')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);

        if (!$model->validate()) {
            return $this->error($this->firstError($model), 422);
        }

        /** @var CredentialService $cs */
        $cs = \Yii::$app->get('credentialService');

        // Only rewrite secrets if the caller provided a non-empty `secrets` object.
        // Otherwise keep the existing encrypted blob untouched.
        $secretsInput = isset($body['secrets']) && is_array($body['secrets']) ? $body['secrets'] : [];
        if ($secretsInput !== []) {
            /** @var array<string, string> $secrets */
            $secrets = $this->extractSecrets($model->credential_type, $body, $cs);
            if (!$cs->storeSecrets($model, $secrets)) {
                return $this->error($this->firstError($model), 422);
            }
        } elseif (!$model->save()) {
            return $this->error($this->firstError($model), 422);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_CREDENTIAL_UPDATED,
            'credential',
            $model->id,
            null,
            ['name' => $model->name, 'source' => 'api']
        );

        return $this->success($this->serialize($model));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(int $id): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('credential.delete')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $name = $model->name;
        $model->delete();

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_CREDENTIAL_DELETED,
            'credential',
            $id,
            null,
            ['name' => $name, 'source' => 'api']
        );

        return $this->success(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(Credential $model, array $body): void
    {
        foreach (['name', 'description', 'credential_type', 'username'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($value === null && in_array($field, ['description', 'username'], true)) {
                $model->$field = null;
            } else {
                $model->$field = (string)$value;
            }
        }
    }

    /**
     * Extract secret fields from the request body, mirroring the web form layer.
     * For SSH keys, also analyses the private key to store public key + metadata.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function extractSecrets(string $type, array $body, CredentialService $cs): array
    {
        /** @var array<string, mixed> $input */
        $input = isset($body['secrets']) && is_array($body['secrets']) ? $body['secrets'] : [];

        if ($type !== Credential::TYPE_SSH_KEY) {
            return match ($type) {
                Credential::TYPE_USERNAME_PASSWORD => ['password' => (string)($input['password'] ?? '')],
                Credential::TYPE_VAULT => ['vault_password' => (string)($input['vault_password'] ?? '')],
                Credential::TYPE_TOKEN => ['token' => (string)($input['token'] ?? '')],
                default => [],
            };
        }

        $rawKey = (string)($input['private_key'] ?? '');
        $privateKey = str_replace("\r\n", "\n", str_replace("\r", "\n", $rawKey));
        /** @var array<string, mixed> $secrets */
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

    /**
     * @return array{id: int, name: string, description: string|null, credential_type: string, username: string|null, created_at: int, updated_at: int}
     */
    private function serialize(Credential $c): array
    {
        // secret_data is NEVER included — not even a redacted placeholder.
        return [
            'id' => $c->id,
            'name' => $c->name,
            'description' => $c->description,
            'credential_type' => $c->credential_type,
            'username' => $c->username,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ];
    }

    private function findModel(int $id): Credential
    {
        /** @var Credential|null $c */
        $c = Credential::findOne($id);
        if ($c === null) {
            throw new NotFoundHttpException("Credential #{$id} not found.");
        }
        return $c;
    }

    private function firstError(Credential $model): string
    {
        foreach ($model->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }
}
