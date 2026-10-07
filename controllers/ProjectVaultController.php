<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\AuditLog;
use app\models\Project;
use app\services\ProjectAccessChecker;
use app\services\VaultScanService;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Rescans a project checkout for vault files from the project page, e.g.
 * for a manual project, which never syncs, or after changing files by hand.
 * Needs project.update and operator access to the project.
 */
class ProjectVaultController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['scan'], 'allow' => true, 'roles' => ['project.update']],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return ['scan' => ['POST']];
    }

    public function actionScan(int $id): Response
    {
        /** @var Project|null $project */
        $project = Project::findOne($id);
        if ($project === null) {
            throw new NotFoundHttpException("Project #{$id} not found.");
        }
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        if (!$checker->canOperate((int)\Yii::$app->user->id, (int)$project->id)) {
            throw new ForbiddenHttpException('You do not have permission to modify this project.');
        }

        /** @var VaultScanService $scans */
        $scans = \Yii::$app->get('vaultScanService');
        $scanned = $scans->scanProject($project);
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_PROJECT_VAULT_SCANNED,
            'project',
            (int)$project->id,
            null,
            ['name' => $project->name, 'scanned' => $scanned, 'source' => 'web']
        );
        if ($scanned) {
            $this->session()->setFlash('success', 'Vault files rescanned.');
        } else {
            $this->session()->setFlash('warning', 'Vault files not scanned: ' . (string)$project->vault_scan_error);
        }

        return $this->redirect(['/project/view', 'id' => $id, '#' => 'vault']);
    }
}
