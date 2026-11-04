<?php

declare(strict_types=1);

namespace app\controllers\traits;

use app\components\LintState;
use app\components\WorkerHeartbeat;
use app\models\JobTemplate;
use app\models\Project;
use app\services\LintService;
use yii\web\Response;

/**
 * The lint card of the job template and project pages (views/project/_lint.php):
 * queueing a lint run, which a save and Run Lint ask for, the poll of a
 * queued run (lint-status), and whether a queue-worker can run it.
 *
 * Lint never runs in a request: LintService::queueForTemplate() and
 * queueForProject() mark the record as queued and push a job that the
 * queue-worker runs (tests/unit/LintNeverRunsInARequestTest.php).
 *
 * For the controllers of BaseController, which give session().
 */
trait LintCardTrait
{
    /**
     * GET <controller>/lint-status?id=N: whether a lint run of the record is
     * still queued, and when its stored result was linted. The lint card
     * polls it every two seconds while a run is queued and reloads the page
     * once none is; whoever may view the page may ask, with the page's 404
     * and 403 (findLintRecord()).
     *
     * @return array{queued: bool, lint_at: int|null}
     */
    public function actionLintStatus(int $id): array
    {
        $record = $this->findLintRecord($id);
        \Yii::$app->response->format = Response::FORMAT_JSON;

        return LintState::of($record)->poll();
    }

    /**
     * The record of the lint card, as its page finds it: 404 when it does
     * not exist, 403 when the signed-in user may not view it.
     */
    abstract protected function findLintRecord(int $id): JobTemplate|Project;

    /**
     * The parameters of the lint card for the page of $record: whether it
     * says that no queue-worker runs, asked only while a run is queued.
     *
     * @return array{lintWorkerMissing: bool}
     */
    protected function lintCard(JobTemplate|Project $record): array
    {
        return ['lintWorkerMissing' => $record->lint_queued_at !== null && !$this->queueWorkerAlive()];
    }

    /**
     * Whether a queue-worker beats: a worker of the queue of project syncs
     * and lint runs (WorkerHeartbeat::all()), never the notification-worker.
     */
    protected function queueWorkerAlive(): bool
    {
        return WorkerHeartbeat::all() !== [];
    }

    /**
     * Queues a lint run of $record (LintService). When the queue could not
     * be reached, the warning flash LintService::NOT_QUEUED; a save before
     * stays.
     */
    protected function queueLint(JobTemplate|Project $record): bool
    {
        /** @var LintService $lint */
        $lint = \Yii::$app->get('lintService');
        $queued = $record instanceof JobTemplate ? $lint->queueForTemplate($record) : $lint->queueForProject($record);
        if (!$queued) {
            $this->session()->setFlash('warning', LintService::NOT_QUEUED);
        }

        return $queued;
    }
}
