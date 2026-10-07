<?php

declare(strict_types=1);

namespace app\controllers;

use app\controllers\traits\TeamScopingTrait;
use app\models\Credential;
use app\models\User;
use app\services\CredentialService;
use app\services\VaultAssignmentException;
use app\services\VaultCredentialAssignmentService;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Assigns a vault password to several job templates at once, from the
 * credential page. Changing templates needs job-template.update; seeing the
 * credential needs credential.view. Only templates the user may operate are
 * offered and accepted.
 */
class CredentialAssignmentController extends BaseController
{
    use TeamScopingTrait;

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['index', 'assign'], 'allow' => true, 'roles' => ['job-template.update']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return ['assign' => ['POST']];
    }

    public function actionIndex(int $id): string
    {
        $vault = $this->findVault($id);

        return $this->render('index', [
            'vault' => $vault,
            'candidates' => $this->service()->candidates($vault, $this->currentUserId()),
            'secretUsable' => $this->credentialService()->secretStatus($vault) === CredentialService::SECRET_STATUS_OK,
        ]);
    }

    public function actionAssign(int $id): Response
    {
        $vault = $this->findVault($id);
        $posted = \Yii::$app->request->post('job_template_ids', []);
        if (!is_array($posted) || $posted === []) {
            $this->session()->setFlash('danger', 'Select at least one job template.');
            return $this->redirect(['index', 'id' => $id]);
        }
        try {
            $result = $this->service()->assign($vault, $posted, $this->currentUserId(), ['source' => 'web']);
        } catch (VaultAssignmentException $e) {
            $this->session()->setFlash('danger', $e->getMessage());
            return $this->redirect(['index', 'id' => $id]);
        }
        $failed = $result->counts()[\app\components\VaultAssignmentResult::FAILED] > 0;
        $this->session()->setFlash($failed ? 'warning' : 'success', $result->summary());

        return $this->redirect(['/credential/view', 'id' => $id]);
    }

    private function findVault(int $id): Credential
    {
        if (!$this->canViewCredentials()) {
            throw new ForbiddenHttpException('You are not allowed to view credentials.');
        }
        /** @var Credential|null $credential */
        $credential = Credential::findOne($id);
        if ($credential === null || $credential->credential_type !== Credential::TYPE_VAULT) {
            throw new NotFoundHttpException("Vault password #{$id} not found.");
        }

        return $credential;
    }

    /**
     * Superadmins pass, as they pass the access rules (and the API's check).
     */
    private function canViewCredentials(): bool
    {
        $identity = \Yii::$app->user->identity;

        return ($identity instanceof User && (bool)$identity->is_superadmin) || (bool)\Yii::$app->user->can('credential.view');
    }

    private function service(): VaultCredentialAssignmentService
    {
        /** @var VaultCredentialAssignmentService $service */
        $service = \Yii::$app->get('vaultCredentialAssignmentService');

        return $service;
    }

    private function credentialService(): CredentialService
    {
        /** @var CredentialService $service */
        $service = \Yii::$app->get('credentialService');

        return $service;
    }
}
