<?php

declare(strict_types=1);

namespace app\commands;

use app\components\WorkerHeartbeat;
use app\models\Job;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Worker management commands.
 *
 * Usage:
 *   php yii worker/start       Start queue listener with heartbeat (use instead of queue/listen)
 *   php yii worker/status      Show active workers and queue depth
 */
class WorkerController extends Controller
{
    /**
     * Start the queue listener with heartbeat registration.
     *
     * Replaces `php yii queue/listen` in docker-compose worker command.
     * The heartbeat is refreshed by a PCNTL alarm every 30 seconds.
     */
    public function actionStart(): int
    {
        $heartbeat = new WorkerHeartbeat();
        $heartbeat->register();

        $this->stdout('Worker started. PID=' . getmypid() . ' Host=' . gethostname() . PHP_EOL);

        // Refresh heartbeat every HEARTBEAT_INTERVAL seconds via SIGALRM if available
        if (function_exists('pcntl_signal') && function_exists('pcntl_alarm')) {
            pcntl_signal(SIGALRM, function () use ($heartbeat): void {
                $heartbeat->refresh();
                pcntl_alarm(WorkerHeartbeat::HEARTBEAT_INTERVAL);
            });
            pcntl_alarm(WorkerHeartbeat::HEARTBEAT_INTERVAL);

            // Clean up on graceful shutdown signals
            foreach ([SIGTERM, SIGINT] as $sig) {
                pcntl_signal($sig, function () use ($heartbeat): void {
                    $heartbeat->deregister();
                    exit(0);
                });
            }
        }

        register_shutdown_function(function () use ($heartbeat): void {
            $heartbeat->deregister();
        });

        // Delegate to Yii2 queue listener (loop = true keeps running until signal)
        \Yii::$app->queue->run(true);

        $heartbeat->deregister();
        return ExitCode::OK;
    }

    /**
     * Show active workers and queue depth.
     */
    public function actionStatus(): int
    {
        $workers = WorkerHeartbeat::all();
        $now     = time();

        if (empty($workers)) {
            $this->stdout("No active workers found.\n");
        } else {
            $this->stdout(sprintf("%-30s %-8s %-20s %s\n", 'Worker ID', 'PID', 'Started', 'Last seen'));
            $this->stdout(str_repeat('-', 80) . "\n");
            foreach ($workers as $w) {
                $age = $now - ($w['seen_at'] ?? 0);
                $this->stdout(sprintf(
                    "%-30s %-8s %-20s %ds ago\n",
                    $w['worker_id'] ?? '?',
                    $w['pid']       ?? '?',
                    $w['started_at'] ? date('Y-m-d H:i:s', $w['started_at']) : '?',
                    $age
                ));
            }
        }

        // Queue depth: count pending/queued jobs
        $queuedJobs = Job::find()
            ->where(['status' => [Job::STATUS_PENDING, Job::STATUS_QUEUED]])
            ->count();
        $runningJobs = Job::find()
            ->where(['status' => Job::STATUS_RUNNING])
            ->count();

        $this->stdout("\nQueue:\n");
        $this->stdout("  Pending/queued jobs: {$queuedJobs}\n");
        $this->stdout("  Running jobs:        {$runningJobs}\n");

        return ExitCode::OK;
    }
}
