<?php

declare(strict_types=1);

namespace app\commands;

use app\services\ArtifactService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Artifact maintenance commands.
 *
 * Usage:
 *   php yii artifact/cleanup   — delete expired artifacts and orphan files
 *   php yii artifact/stats     — show artifact storage statistics
 */
class ArtifactController extends Controller
{
    public function actionCleanup(): int
    {
        /** @var ArtifactService $svc */
        $svc = \Yii::$app->get('artifactService');

        $expired = 0;
        if ($svc->retentionDays > 0) {
            $expired = $svc->deleteExpiredArtifacts();
            $this->stdout("[artifacts] Deleted {$expired} expired artifact(s) (retention: {$svc->retentionDays} days)\n");
        } else {
            $this->stdout("[artifacts] Retention disabled (ARTIFACT_RETENTION_DAYS=0), skipping expiry\n");
        }

        $orphans = $svc->cleanupOrphans();
        $this->stdout("[artifacts] Removed {$orphans} orphan file(s)\n");

        return ExitCode::OK;
    }

    public function actionStats(): int
    {
        /** @var ArtifactService $svc */
        $svc = \Yii::$app->get('artifactService');
        $stats = $svc->getStorageStats();

        $this->stdout("[artifacts] Total size:  " . $this->formatBytes($stats['total_bytes']) . "\n");
        $this->stdout("[artifacts] Artifacts:   {$stats['artifact_count']}\n");
        $this->stdout("[artifacts] Jobs:        {$stats['job_count']}\n");
        $this->stdout("[artifacts] Retention:   " . ($svc->retentionDays > 0 ? "{$svc->retentionDays} days" : 'forever') . "\n");

        return ExitCode::OK;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int)floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}
