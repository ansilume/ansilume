<?php

declare(strict_types=1);

namespace app\commands;

use app\services\JobReclaimService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Job maintenance commands.
 *
 * Usage:
 *   php yii job/reclaim-stale   — fail or re-queue jobs whose runner has gone silent
 *
 * Intended to be run via cron, e.g. once a minute. The sweep is cheap when
 * there is nothing to do (one indexed query) so frequent runs are fine.
 */
class JobController extends Controller
{
    public function actionReclaimStale(): int
    {
        /** @var JobReclaimService $svc */
        $svc = \Yii::$app->get('jobReclaimService');
        $count = $svc->reclaimStaleJobs();

        if ($count > 0) {
            $this->stdout(
                "[jobs] Processed {$count} stuck job(s) "
                . "(mode: {$svc->mode}, progress timeout: {$svc->progressTimeoutSeconds}s)\n"
            );
        }

        $orphaned = $svc->reclaimOrphanedQueuedJobs();
        if ($orphaned > 0) {
            $this->stdout(
                "[jobs] Failed {$orphaned} orphaned queued job(s) "
                . "(queue timeout: {$svc->queueTimeoutSeconds}s)\n"
            );
        }

        return ExitCode::OK;
    }
}
