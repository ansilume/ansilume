<?php

declare(strict_types=1);

namespace app\commands;

use app\components\WorkerHeartbeat;
use app\models\Job;
use yii\base\Event;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\queue\cli\Queue as CliQueue;
use yii\queue\Queue as BaseQueue;

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
     * If the queue's BRPOP loop hasn't iterated in this many seconds the
     * connection is presumed stale and the worker exits so docker's
     * restart-policy can spawn a fresh process. Tuned higher than yii's
     * 3s BRPOP timeout (the loop normally iterates ≥ once every 3s) but
     * low enough that operators see recovery within a minute.
     */
    public const STALE_LOOP_THRESHOLD_SECONDS = 60;

    /**
     * In-process timestamp of the last EVENT_WORKER_LOOP firing. Updated
     * from inside the worker loop, read by the SIGALRM-driven self-check.
     * Static so the alarm callback (a closure) can reach it from outside
     * the action method's scope.
     */
    private static int $lastLoopAt = 0;

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

        self::$lastLoopAt = time();
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
     *
     * Also runs the self-heal probe: if the queue's BRPOP loop hasn't
     * iterated in {@see STALE_LOOP_THRESHOLD_SECONDS} the worker process
     * has gone wedged on a stale Redis connection — heartbeat itself uses
     * a separate Redis connection so it can't see this. Exit(1) so the
     * docker restart-policy spawns a fresh process with a new connection.
     */
    private function installHeartbeatRefresh(WorkerHeartbeat $heartbeat): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_alarm')) {
            return;
        }
        pcntl_signal(SIGALRM, function () use ($heartbeat): void {
            $heartbeat->refresh();
            $this->detectStaleLoopAndExit();
            pcntl_alarm(WorkerHeartbeat::HEARTBEAT_INTERVAL);
        });
        pcntl_alarm(WorkerHeartbeat::HEARTBEAT_INTERVAL);
    }

    /**
     * Self-heal probe. Two signals point at a wedged worker:
     *   1. EVENT_WORKER_LOOP hasn't fired in STALE_LOOP_THRESHOLD_SECONDS
     *      (BRPOP not returning, even with a 3s timeout).
     *   2. The queue's redis component fails PING.
     * On either, log and exit(1). docker restart-policy: unless-stopped
     * spawns a fresh worker with a new connection in <5s.
     *
     * Public so tests can drive it without rigging up a real queue loop.
     */
    public function detectStaleLoopAndExit(): void
    {
        $silentSeconds = time() - self::$lastLoopAt;
        if (self::$lastLoopAt > 0 && $silentSeconds > self::STALE_LOOP_THRESHOLD_SECONDS) {
            \Yii::error(
                "Worker BRPOP loop has been silent for {$silentSeconds}s "
                . '(threshold ' . self::STALE_LOOP_THRESHOLD_SECONDS . 's) — '
                . 'exiting so docker restart-policy can recover with a fresh connection.',
                __CLASS__,
            );
            $this->stderr("[worker] stale BRPOP loop detected — exiting for restart.\n");
            exit(1);
        }

        try {
            /** @var \yii\redis\Connection|null $redis */
            $redis = \Yii::$app->has('redis') ? \Yii::$app->get('redis') : null;
            if ($redis !== null && method_exists($redis, 'executeCommand')) {
                $reply = $redis->executeCommand('PING');
                if ($reply !== 'PONG' && $reply !== true) {
                    throw new \RuntimeException('Unexpected PING reply: ' . var_export($reply, true));
                }
            }
        } catch (\Throwable $e) {
            \Yii::error('Worker queue-redis PING failed (' . $e->getMessage() . ') — exiting for restart.', __CLASS__);
            $this->stderr("[worker] queue redis ping failed — exiting for restart.\n");
            exit(1);
        }
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
        // EVENT_WORKER_LOOP fires on every BRPOP iteration (~ every 3s
        // when idle). The static stamp is what detectStaleLoopAndExit()
        // reads on each SIGALRM tick to decide whether the loop is stuck.
        Event::on(CliQueue::class, CliQueue::EVENT_WORKER_LOOP, function (): void {
            self::$lastLoopAt = time();
        });
        // Real job finished — heartbeat carries `last_job_processed_at` so
        // the panel can flag "queue has work but worker hasn't picked any
        // up in N min" without relying on the loop stamp.
        Event::on(BaseQueue::class, BaseQueue::EVENT_AFTER_EXEC, function () use ($heartbeat): void {
            $heartbeat->markJobProcessed();
        });
        Event::on(CliQueue::class, CliQueue::EVENT_WORKER_STOP, function () use ($heartbeat): void {
            \Yii::info('Worker received stop signal — finishing current job, then exiting.', __CLASS__);
            $heartbeat->deregister();
        });
    }
}
