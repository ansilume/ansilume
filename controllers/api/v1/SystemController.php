<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\services\ArtifactService;

/**
 * API v1: System
 *
 * GET /api/v1/system/artifact-stats — aggregate artifact storage stats
 *
 * Admin-only: requires the same `user.view` permission used for other
 * operator-level system inspection (audit log, user list).
 */
class SystemController extends BaseApiController
{
    public $enableCsrfValidation = false;

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionArtifactStats(): array
    {
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        if (!$user->can('user.view')) {
            return $this->error('Forbidden.', 403);
        }

        /** @var ArtifactService $svc */
        $svc = \Yii::$app->get('artifactService');

        return $this->success([
            'stats' => $svc->getStorageStats(),
            'top_jobs' => $svc->getTopJobsByBytes(10),
            'config' => [
                'retention_days' => $svc->retentionDays,
                'max_file_size' => $svc->maxFileSize,
                'max_artifacts_per_job' => $svc->maxArtifactsPerJob,
                'max_bytes_per_job' => $svc->maxBytesPerJob,
                'max_total_bytes' => $svc->maxTotalBytes,
            ],
        ]);
    }
}
