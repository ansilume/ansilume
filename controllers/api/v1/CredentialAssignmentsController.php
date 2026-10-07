<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\models\Credential;
use app\services\VaultAssignmentException;
use app\services\VaultCredentialAssignmentService;
use yii\web\NotFoundHttpException;

/**
 * API v1: assigning a vault password to several job templates.
 *
 * GET  /api/v1/credentials/{id}/job-templates   templates the caller may change, with their vault passwords
 * POST /api/v1/credentials/{id}/job-templates   body {"job_template_ids": [..]}; replaces other vault passwords
 *
 * Both need job-template.update and credential.view.
 */
class CredentialAssignmentsController extends BaseApiController
{
    use ApiTeamScopingTrait;

    private const NOT_A_VAULT = 'Only vault passwords can be assigned to several job templates at once.';

    protected function apiAccessRules(): array
    {
        return [
            'index' => 'job-template.update',
            'create' => 'job-template.update',
        ];
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionIndex(int $id): array
    {
        if (!$this->userCan('credential.view')) {
            return $this->error('Forbidden.', 403);
        }
        $vault = $this->findCredential($id);
        if ($vault->credential_type !== Credential::TYPE_VAULT) {
            return $this->error(self::NOT_A_VAULT, 422);
        }

        return $this->success($this->service()->candidates($vault, $this->currentUserId()));
    }

    /**
     * @return array{data: mixed}|array{error: array<string, mixed>}
     */
    public function actionCreate(int $id): array
    {
        if (!$this->userCan('credential.view')) {
            return $this->error('Forbidden.', 403);
        }
        $vault = $this->findCredential($id);
        if ($vault->credential_type !== Credential::TYPE_VAULT) {
            return $this->error(self::NOT_A_VAULT, 422);
        }
        $body = (array)\Yii::$app->request->bodyParams;
        $ids = $body['job_template_ids'] ?? null;
        try {
            $result = $this->service()->assign($vault, is_array($ids) ? $ids : [], $this->currentUserId(), ['source' => 'api']);
        } catch (VaultAssignmentException $e) {
            return $this->errorWithDetails($e->getMessage(), $e->status, $e->templateIds === [] ? [] : ['job_template_ids' => $e->templateIds]);
        }

        return $this->success($result->toArray());
    }

    private function findCredential(int $id): Credential
    {
        /** @var Credential|null $credential */
        $credential = Credential::findOne($id);
        if ($credential === null) {
            throw new NotFoundHttpException("Credential #{$id} not found.");
        }

        return $credential;
    }

    private function service(): VaultCredentialAssignmentService
    {
        /** @var VaultCredentialAssignmentService $service */
        $service = \Yii::$app->get('vaultCredentialAssignmentService');

        return $service;
    }
}
