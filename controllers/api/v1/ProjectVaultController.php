<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\models\AuditLog;
use app\models\Project;
use app\services\VaultOverviewService;
use app\services\VaultScanService;
use yii\web\NotFoundHttpException;

/**
 * API v1: the vault overview of a project.
 *
 * GET  /api/v1/projects/{id}/vault        last scan, findings, how each job template fits it
 * POST /api/v1/projects/{id}/vault/scan   rescans the checkout now and answers with the overview
 *
 * GET needs project.view and view access to the project; POST needs
 * project.update and operator access. Runner details and the names of vault
 * passwords in the overview follow runner-group.view and credential.view or
 * job-template.view (VaultOverviewService).
 */
class ProjectVaultController extends BaseApiController
{
    use ApiTeamScopingTrait;

    protected function apiAccessRules(): array
    {
        return [
            'view' => 'project.view',
            'scan' => 'project.update',
        ];
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionView(int $id): array
    {
        $project = $this->findProject($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canView($userId, (int)$project->id)) {
            return $this->error('Forbidden.', 403);
        }

        return $this->success($this->overview()->forProject($project, fn (string $permission): bool => $this->userCan($permission)));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionScan(int $id): array
    {
        $project = $this->findProject($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperate($userId, (int)$project->id)) {
            return $this->error('Forbidden.', 403);
        }
        /** @var VaultScanService $scans */
        $scans = \Yii::$app->get('vaultScanService');
        $scanned = $scans->scanProject($project);
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_PROJECT_VAULT_SCANNED,
            'project',
            (int)$project->id,
            null,
            ['name' => $project->name, 'scanned' => $scanned, 'source' => 'api']
        );

        return $this->success($this->overview()->forProject($project, fn (string $permission): bool => $this->userCan($permission)));
    }

    private function findProject(int $id): Project
    {
        /** @var Project|null $project */
        $project = Project::findOne($id);
        if ($project === null) {
            throw new NotFoundHttpException("Project #{$id} not found.");
        }

        return $project;
    }

    private function overview(): VaultOverviewService
    {
        /** @var VaultOverviewService $service */
        $service = \Yii::$app->get('vaultOverviewService');

        return $service;
    }
}
