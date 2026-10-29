<?php

declare(strict_types=1);

namespace app\controllers\api\runner;

use app\models\Job;
use app\models\JobLog;
use app\models\Runner;
use app\models\RunnerGroup;
use app\services\JobClaimService;
use app\services\JobCompletionService;
use app\services\RunnerReportNumbers;
use yii\web\NotFoundHttpException;
use yii\web\ServerErrorHttpException;
use yii\web\Response;

/**
 * Runner job API — claim, stream logs, complete.
 *
 * POST /api/runner/v1/heartbeat            — update last_seen_at, get runner info
 * POST /api/runner/v1/jobs/claim           — atomically claim next queued job
 * POST /api/runner/v1/jobs/<id>/logs       — append log chunk
 * POST /api/runner/v1/jobs/<id>/complete   — finalize job
 * POST /api/runner/v1/jobs/<id>/tasks      — save task results
 */
class JobsController extends BaseRunnerApiController
{
    /**
     * POST /api/runner/v1/heartbeat
     * Runner announces itself. Returns group and server time.
     *
     * @return array<string, mixed>
     */
    public function actionHeartbeat(): array
    {
        $runner = $this->runner();
        $group = $this->requireGroup($runner);

        return $this->ok([
            'runner_id' => $runner->id,
            'runner_name' => $runner->name,
            'group_id' => $group->id,
            'group_name' => $group->name,
            'server_time' => time(),
        ]);
    }

    /**
     * POST /api/runner/v1/jobs/claim
     * Atomically claim the next queued job for this runner's group.
     * Returns 204 (no body) when there is nothing to run. Jobs that may not
     * run (not approved, an inventory gone or without hosts, credentials
     * that cannot be resolved) are failed on the server and skipped; once
     * such failures have taken JobClaimService::CLAIM_BUDGET_SECONDS, the
     * claim hands out no further job and answers 204, so that it answers
     * before the runner stops waiting.
     *
     * @return array<string, mixed>
     */
    public function actionClaim(): array|Response
    {
        $runner = $this->runner();
        $group = $this->requireGroup($runner);

        /** @var JobClaimService $svc */
        $svc = \Yii::$app->get('jobClaimService');
        $payload = $svc->claimNextPayload($group, $runner);

        if ($payload === null) {
            \Yii::$app->response->statusCode = 204;
            return [];
        }

        return $this->ok($payload);
    }

    /**
     * POST /api/runner/v1/jobs/<id>/logs
     * Body: { stream: "stdout"|"stderr", content: "...", sequence: N }
     *
     * A chunk without content writes nothing. The sequence (none: 0) must be
     * a whole number the job log takes; any other value is answered with 422
     * (RunnerReportNumbers::SEQUENCE_REFUSED), and the chunk is not written.
     *
     * @return array<string, mixed>
     */
    public function actionLogs(int $id): array
    {
        $job = $this->findOwnedJob($id);
        $body = (array)\Yii::$app->request->bodyParams;

        $stream = in_array($body['stream'] ?? '', ['stdout', 'stderr'], true)
            ? $body['stream']
            : JobLog::STREAM_STDOUT;
        $content = (string)($body['content'] ?? '');

        if ($content === '') {
            return $this->ok();
        }
        $sequence = RunnerReportNumbers::sequence($body['sequence'] ?? 0);
        if ($sequence === null) {
            return $this->err(RunnerReportNumbers::SEQUENCE_REFUSED, 422);
        }

        /** @var JobCompletionService $svc */
        $svc = \Yii::$app->get('jobCompletionService');
        $svc->appendLog($job, $stream, $content, $sequence);

        return $this->ok();
    }

    /**
     * POST /api/runner/v1/jobs/<id>/complete
     * Body: { exit_code: N, has_changes: bool, timed_out?: bool }
     *
     * When `timed_out` is true the job is marked STATUS_TIMED_OUT via
     * completeTimedOut(). The runner sets it after killing a process
     * that exceeded its deadline — without the flag the server would
     * only see exit_code=-1 and map it to STATUS_FAILED, indistinguishable
     * from a real failure and invisible to the "timed out" filter.
     *
     * Otherwise the exit code (none: 0) must be a whole number the job
     * record takes; any other value is answered with 422
     * (RunnerReportNumbers::EXIT_CODE_REFUSED), and the job keeps running.
     *
     * @return array<string, mixed>
     */
    public function actionComplete(int $id): array
    {
        $job = $this->findOwnedJob($id);
        $body = (array)\Yii::$app->request->bodyParams;
        $hasChanges = !empty($body['has_changes']);
        $timedOut = !empty($body['timed_out']);
        // completeTimedOut() writes -1, whatever exit code the report carries.
        $exitCode = $timedOut ? -1 : RunnerReportNumbers::exitCode($body['exit_code'] ?? 0);
        if ($exitCode === null) {
            return $this->err(RunnerReportNumbers::EXIT_CODE_REFUSED, 422);
        }

        /** @var JobCompletionService $svc */
        $svc = \Yii::$app->get('jobCompletionService');
        if ($timedOut) {
            $svc->completeTimedOut($job);
        } else {
            $svc->complete($job, $exitCode, $hasChanges);
        }

        return $this->ok(['status' => $job->status]);
    }

    /**
     * POST /api/runner/v1/jobs/<id>/tasks
     * Body: { tasks: [ {seq, name, action, host, status, changed, duration_ms}, ... ] }
     *
     * A list with a task that is no object, or whose seq or duration_ms is
     * no whole number the task list takes, is answered with 422
     * (RunnerReportNumbers::taskListRefusal()), and no task of it is saved.
     *
     * @return array<string, mixed>
     */
    public function actionTasks(int $id): array
    {
        $job = $this->findOwnedJob($id);
        $tasks = (array)\Yii::$app->request->bodyParams;
        $tasks = $tasks['tasks'] ?? [];

        if (!is_array($tasks)) {
            return $this->err('tasks must be an array');
        }

        /** @var JobCompletionService $svc */
        $svc = \Yii::$app->get('jobCompletionService');
        $refusal = $svc->saveTasks($job, $tasks);
        if ($refusal !== null) {
            return $this->err($refusal, 422);
        }

        return $this->ok(['saved' => count($tasks)]);
    }

    private function findOwnedJob(int $id): Job
    {
        /** @var Job|null $job */
        $job = Job::findOne($id);
        if ($job === null) {
            throw new NotFoundHttpException("Job #{$id} not found.");
        }
        if ((int)$job->runner_id !== $this->runner()->id) {
            throw new \yii\web\ForbiddenHttpException('This job belongs to a different runner.');
        }

        // Liveness signal for stale-job reclaim. Bumping here makes every
        // runner-side write (log chunk, complete, tasks) double as a per-job
        // heartbeat, so an idle but healthy runner is never confused with a
        // dead one. Use updateAll() — we only need the column write, not a
        // full ActiveRecord save with TimestampBehavior side-effects.
        $now = time();
        Job::updateAll(['last_progress_at' => $now], ['id' => $job->id]);
        $job->last_progress_at = $now;

        return $job;
    }

    /**
     * Resolve the runner's group, guarding against the edge case where the
     * group was deleted mid-request (CASCADE delete also removes the runner,
     * but this request has already authenticated with the in-memory record).
     */
    private function requireGroup(Runner $runner): RunnerGroup
    {
        $group = $runner->group;
        if ($group === null) {
            throw new ServerErrorHttpException('Runner has no group (was the group deleted?). Retry after re-registering.');
        }
        return $group;
    }
}
