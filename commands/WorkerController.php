<?php

declare(strict_types=1);

namespace app\commands;

use app\components\WorkerHeartbeat;
use app\models\Job;
use yii\base\Event;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\queue\cli\Queue as CliQueue;

/**
 * Worker management commands.
 *
 * Usage:
 *   php yii worker/start       Start queue listener with heartbeat (use instead of queue/listen)
 *   php yii worker/status      Show active workers and queue depth
 *
 * Graceful shutdown:
 * On SIGTERM / SIGINT / SIGQUIT / SIGHUP, yii2-queue's built-in {@see \yii\queue\cli\SignalLoop}
 * sets an exit flag that is checked **between** queue iterations. The current
 * job (and the Ansible subprocess it spawned) finishes uninterrupted, then the
 * worker exits cleanly. Operators must give docker / systemd enough grace time
 * (see `stop_grace_period: 300s` in docker-compose.yml) for the longest-running
 * job to complete; jobs killed mid-flight are picked up by the JobReclaim sweep
 * on the next start.
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

        $this->stdout('[worker] started. PID=' . getmypid() . ' Host=' . gethostname() . PHP_EOL);

        $this->installHeartbeatRefresh($heartbeat);
        $this->attachWorkerEvents($heartbeat);

        register_shutdown_function(function () use ($heartbeat): void {
            $heartbeat->deregister();
        });

        // Delegate to Yii2 queue listener (loop=true, timeout=3 → blocking brpop with 3s timeout).
        // SignalLoop (registered by the queue itself) handles SIGTERM/INT/QUIT/HUP cooperatively:
        // current job finishes, then the loop returns here for clean teardown.
        /** @var \yii\queue\redis\Queue $queue */
        $queue = \Yii::$app->queue;
        $queue->run(true, 3);

        $heartbeat->deregister();
        $this->stdout('[worker] stopped cleanly.' . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Show active workers and queue depth.
     */
    public function actionStatus(): int
    {
        $workers = WorkerHeartbeat::all();
        $now = time();

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
                    $w['pid'] ?? '?',
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

    /**
     * Refresh the worker heartbeat every {@see WorkerHeartbeat::HEARTBEAT_INTERVAL}
     * seconds via a recurring SIGALRM. Does nothing if pcntl is unavailable
     * (the heartbeat then expires after STALE_AFTER unless deregistered cleanly).
     */
    private function installHeartbeatRefresh(WorkerHeartbeat $heartbeat): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_alarm')) {
            return;
        }
        pcntl_signal(SIGALRM, function () use ($heartbeat): void {
            $heartbeat->refresh();
            pcntl_alarm(WorkerHeartbeat::HEARTBEAT_INTERVAL);
        });
        pcntl_alarm(WorkerHeartbeat::HEARTBEAT_INTERVAL);
    }

    /**
     * Attach lifecycle hooks to the queue worker so graceful shutdowns are
     * visible in operator logs and the heartbeat is deregistered as soon as
     * the loop exits — even before the surrounding actionStart() can clean up.
     *
     * Public for testability: tests trigger {@see CliQueue::EVENT_WORKER_STOP}
     * directly to verify the cleanup hook runs without spawning a real signal.
     */
    public function attachWorkerEvents(WorkerHeartbeat $heartbeat): void
    {
        Event::on(CliQueue::class, CliQueue::EVENT_WORKER_START, function (): void {
            \Yii::info('Worker entered listening loop.', __CLASS__);
        });
        Event::on(CliQueue::class, CliQueue::EVENT_WORKER_STOP, function () use ($heartbeat): void {
            \Yii::info('Worker received stop signal — finishing current job, then exiting.', __CLASS__);
            $heartbeat->deregister();
        });
    }
}
