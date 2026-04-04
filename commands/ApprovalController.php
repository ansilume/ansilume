<?php

declare(strict_types=1);

namespace app\commands;

use app\services\ApprovalService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Console commands for approval workflow maintenance.
 */
class ApprovalController extends Controller
{
    /**
     * Process expired approval requests.
     * Intended to be run via cron every minute.
     */
    public function actionCheckTimeouts(): int
    {
        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');
        $count = $service->processTimeouts();

        if ($count > 0) {
            $this->stdout("Processed {$count} timed-out approval request(s).\n");
        }

        return ExitCode::OK;
    }
}
