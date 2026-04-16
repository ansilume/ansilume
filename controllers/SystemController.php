<?php

declare(strict_types=1);

namespace app\controllers;

use app\services\ArtifactService;

/**
 * Admin-facing system info pages.
 *
 * Currently exposes artifact storage statistics. The same data is also
 * available via `GET /api/v1/system/artifact-stats`.
 */
class SystemController extends BaseController
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        return [
            ['actions' => ['artifact-stats'], 'allow' => true, 'roles' => ['user.view']],
        ];
    }

    public function actionArtifactStats(): string
    {
        /** @var ArtifactService $svc */
        $svc = \Yii::$app->get('artifactService');

        return $this->render('artifact-stats', [
            'stats' => $svc->getStorageStats(),
            'topJobs' => $svc->getTopJobsByBytes(10),
            'retentionDays' => $svc->retentionDays,
            'maxFileSize' => $svc->maxFileSize,
            'maxArtifactsPerJob' => $svc->maxArtifactsPerJob,
            'maxBytesPerJob' => $svc->maxBytesPerJob,
            'maxTotalBytes' => $svc->maxTotalBytes,
        ]);
    }
}
