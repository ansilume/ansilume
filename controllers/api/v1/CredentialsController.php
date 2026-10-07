<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\models\Credential;
use app\services\CredentialService;
use app\services\CredentialUsageService;
use app\services\CredentialWriteService;
use yii\data\ActiveDataProvider;
use yii\web\NotFoundHttpException;

/**
 * API v1: Credentials
 *
 * GET    /api/v1/credentials              credential.view
 * GET    /api/v1/credentials/{id}         credential.view, with used_by and secret_status
 * POST   /api/v1/credentials              credential.create
 * PUT    /api/v1/credentials/{id}         credential.update
 * DELETE /api/v1/credentials/{id}         credential.delete; 409 while in use unless ?force=1
 *
 * Secret material is NEVER returned in responses — not even redacted placeholders.
 */
class CredentialsController extends BaseApiController
{
    use ApiTeamScopingTrait;

    protected function apiAccessRules(): array
    {
        return [
            'index' => 'credential.view',
            'view' => 'credential.view',
            'create' => 'credential.create',
            'update' => 'credential.update',
            'delete' => 'credential.delete',
        ];
    }

    /**
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}
     */
    public function actionIndex(): array
    {
        $dp = new ActiveDataProvider([
            'query' => Credential::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 25],
        ]);

        $page = $this->requestedPage();

        return $this->paginated(
            array_map(fn (Credential $c) => $this->serialize($c), $dp->getModels()),
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
        $model = $this->findModel($id);

        return $this->success($this->serialize($model) + [
            'secret_status' => $this->credentialService()->secretStatus($model),
            'used_by' => $this->usageService()->forCredential($model, $this->currentUserId())->toArray(),
        ]);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        $model = new Credential();
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);
        $model->created_by = (int)\Yii::$app->user->id;

        if (!$this->writeService()->create($model, $this->secretsFrom($body), ['source' => 'api'])) {
            return $this->error($this->firstError($model), 422);
        }

        return $this->success($this->serialize($model), 201);
    }

    /**
     * Secrets are merged: omitted or blank secrets keep the stored ones. A
     * type change needs the new type's secret.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(int $id): array
    {
        $model = $this->findModel($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $this->applyBody($model, $body);

        if (!$this->writeService()->update($model, $this->secretsFrom($body), ['source' => 'api'])) {
            return $this->error($this->firstError($model), 422);
        }

        return $this->success($this->serialize($model));
    }

    /**
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionDelete(int $id): array
    {
        $model = $this->findModel($id);
        $force = filter_var(\Yii::$app->request->get('force', false), FILTER_VALIDATE_BOOLEAN);

        $result = $this->writeService()->delete($model, $force, $this->currentUserId(), ['source' => 'api']);
        if (!$result['deleted']) {
            return $this->errorWithDetails(
                $result['usage']->summary() . ' Pass force=1 to delete it anyway; it will be detached from all of them.',
                409,
                ['used_by' => $result['usage']->toArray()]
            );
        }

        return $this->success(['deleted' => true]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(Credential $model, array $body): void
    {
        foreach (['name', 'credential_type'] as $field) {
            if (array_key_exists($field, $body)) {
                $model->$field = is_scalar($body[$field]) ? (string)$body[$field] : '';
            }
        }
        foreach (['description', 'username', 'env_var_name'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = $body[$field];
                $model->$field = is_scalar($value) && $value !== '' ? (string)$value : null;
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    private function secretsFrom(array $body): array
    {
        return isset($body['secrets']) && is_array($body['secrets']) ? $body['secrets'] : [];
    }

    /**
     * @return array{id: int, name: string, description: string|null, credential_type: string, username: string|null, env_var_name: string|null, created_at: int, updated_at: int}
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
            'env_var_name' => $c->env_var_name,
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

    private function credentialService(): CredentialService
    {
        /** @var CredentialService $service */
        $service = \Yii::$app->get('credentialService');

        return $service;
    }

    private function usageService(): CredentialUsageService
    {
        /** @var CredentialUsageService $service */
        $service = \Yii::$app->get('credentialUsageService');

        return $service;
    }

    private function writeService(): CredentialWriteService
    {
        /** @var CredentialWriteService $service */
        $service = \Yii::$app->get('credentialWriteService');

        return $service;
    }
}
