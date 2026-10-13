<?php

declare(strict_types=1);

namespace app\commands;

use app\components\PendingMigrations;
use app\services\ApprovalService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Console commands for approval workflow maintenance.
 */
class ApprovalController extends Controller
{
    /** sprintf(): the number of requests whose timeout was applied. */
    public const PROCESSED = 'Processed %d timed-out approval request(s).';

    /** sprintf(): the number of pending migrations. */
    public const MIGRATIONS_PENDING = 'Approval timeouts not applied: %d database migration(s) pending; '
        . 'they are applied once the update has finished.';

    /**
     * Apply the timeout action of every expired approval request, and print
     * how many there were, if any.
     *
     * The maintenance run (`php yii maintenance/run`, which the
     * schedule-runner starts every minute) already applies approval
     * timeouts, in batches. This command is for own cron setups that run
     * without the schedule-runner; it does the same work, all expired
     * requests at once, and is safe next to the maintenance run.
     *
     * While database migrations are pending it applies nothing, as the
     * maintenance run does, and exits 0.
     */
    public function actionCheckTimeouts(): int
    {
        $pending = $this->pendingMigrations()->count();
        if ($pending > 0) {
            $this->stdout(sprintf(self::MIGRATIONS_PENDING, $pending) . PHP_EOL);
            return ExitCode::OK;
        }

        /** @var ApprovalService $service */
        $service = \Yii::$app->get('approvalService');
        $count = $service->processTimeouts();

        if ($count > 0) {
            $this->stdout(sprintf(self::PROCESSED, $count) . PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * The migration state checked before timeouts are applied.
     */
    protected function pendingMigrations(): PendingMigrations
    {
        return new PendingMigrations();
    }
}
