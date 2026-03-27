<?php

declare(strict_types=1);

namespace app\controllers\api\runner;

use app\models\Job;
use app\models\JobLog;
use app\services\AuditService;
use app\services\JobClaimService;
use app\services\JobCompletionService;
use yii\web\NotFoundHttpException;
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
     */
    public function actionHeartbeat(): array
    {
        $runner = $this->currentRunner;
        $group = $runner->group;

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
     * Returns 204 (no body) when there is nothing to run.
     */
    public function actionClaim(): array|Response
    {
        $runner = $this->currentRunner;
        $group = $runner->group;

        /** @var JobClaimService $svc */
        $svc = \Yii::$app->get('jobClaimService');
        $job = $svc->claim($group, $runner);

        if ($job === null) {
            \Yii::$app->response->statusCode = 204;
            return [];
        }

        $payload = $svc->buildExecutionPayload($job);

        return $this->ok($payload);
    }

    /**
     * POST /api/runner/v1/jobs/<id>/logs
     * Body: { stream: "stdout"|"stderr", content: "...", sequence: N }
     */
    public function actionLogs(int $id): array
    {
        $job = $this->findOwnedJob($id);
        $body = \Yii::$app->request->bodyParams;

        $stream = in_array($body['stream'] ?? '', ['stdout', 'stderr'], true)
            ? $body['stream']
            : JobLog::STREAM_STDOUT;
        $content = (string)($body['content'] ?? '');
        $sequence = (int)($body['sequence'] ?? 0);

        if ($content === '') {
            return $this->ok();
        }

        /** @var JobCompletionService $svc */
        $svc = \Yii::$app->get('jobCompletionService');
        $svc->appendLog($job, $stream, $content, $sequence);

        return $this->ok();
    }

    /**
     * POST /api/runner/v1/jobs/<id>/complete
     * Body: { exit_code: N, has_changes: bool }
     */
    public function actionComplete(int $id): array
    {
        $job = $this->findOwnedJob($id);
        $body = \Yii::$app->request->bodyParams;
        $exitCode = (int)($body['exit_code'] ?? 0);
        $hasChanges = !empty($body['has_changes']);

        /** @var JobCompletionService $svc */
        $svc = \Yii::$app->get('jobCompletionService');
        $svc->complete($job, $exitCode, $hasChanges);

        return $this->ok(['status' => $job->status]);
    }

    /**
     * POST /api/runner/v1/jobs/<id>/tasks
     * Body: { tasks: [ {seq, name, action, host, status, changed, duration_ms}, ... ] }
     */
    public function actionTasks(int $id): array
    {
        $job = $this->findOwnedJob($id);
        $tasks = \Yii::$app->request->bodyParams['tasks'] ?? [];

        if (!is_array($tasks)) {
            return $this->err('tasks must be an array');
        }

        /** @var JobCompletionService $svc */
        $svc = \Yii::$app->get('jobCompletionService');
        $svc->saveTasks($job, $tasks);

        return $this->ok(['saved' => count($tasks)]);
    }

    private function findOwnedJob(int $id): Job
    {
        $job = Job::findOne($id);
        if ($job === null) {
            throw new NotFoundHttpException("Job #{$id} not found.");
        }
        if ((int)$job->runner_id !== $this->currentRunner->id) {
            throw new \yii\web\ForbiddenHttpException('This job belongs to a different runner.');
        }
        return $job;
    }
}
