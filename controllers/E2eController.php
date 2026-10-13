<?php

declare(strict_types=1);

namespace app\controllers;

use app\commands\E2eDeletedTemplateApprovalSeeder;
use app\components\EnvFlag;
use app\components\ErrorText;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Project;
use app\models\ProjectSyncLog;
use app\models\Schedule;
use app\services\ScheduleService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * E2E-only HTTP hooks. The Playwright container has no Docker socket and
 * cannot shell back into the app container, so it drives scheduled-execution
 * and similar server-side triggers through these endpoints instead.
 *
 * Every action is hard-gated behind YII_DEBUG — the controller returns 404
 * in production, so there is no operational surface. An additional prefix
 * check on every resource name (must start with "e2e-") ensures that even
 * on a misconfigured staging deploy the hook cannot be pointed at real data.
 */
class E2eController extends BaseController
{
    private const PREFIX = 'e2e-';

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function accessRules(): array
    {
        // Everything else is rejected in beforeAction(); rules only need to
        // allow guest access so Playwright can hit the endpoint without a
        // session cookie (kept unauthenticated on purpose — the YII_DEBUG
        // + prefix combination is the guard, not user identity).
        return [
            [
                'actions' => [
                    'fire-schedule',
                    'create-cancelable-job',
                    'seed-syncing-project',
                    'create-deleted-template-request',
                ],
                'allow' => true,
                'roles' => ['?', '@'],
            ],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    protected function verbRules(): array
    {
        return [
            'fire-schedule' => ['POST'],
            'create-cancelable-job' => ['POST'],
            'seed-syncing-project' => ['POST'],
            'create-deleted-template-request' => ['POST'],
        ];
    }

    public function beforeAction($action): bool
    {
        // PHPStan's bootstrap pins YII_DEBUG to false, which would make a
        // plain `if (!YII_DEBUG)` look always-true and the rest of this
        // method unreachable in static analysis. The runtime value flips
        // based on web/index.php, so we read it through a helper PHPStan
        // treats as an opaque bool return.
        if (!$this->isDebugEnabled()) {
            throw new NotFoundHttpException();
        }
        // Playwright POSTs directly without first loading a CSRF-bearing page,
        // and this controller is debug-only anyway — the YII_DEBUG guard plus
        // the prefix check on the target resource are what protect it.
        $this->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }

    /**
     * Parsed like web/index.php does (EnvFlag): YII_DEBUG=false is off.
     */
    private function isDebugEnabled(): bool
    {
        return EnvFlag::isOn($_ENV['YII_DEBUG'] ?? (defined('YII_DEBUG') ? YII_DEBUG : false));
    }

    /**
     * POST /e2e/fire-schedule?name=e2e-schedule
     *
     * Enables + backdates a named e2e-* schedule and runs ScheduleService
     * once, returning a JSON summary the spec asserts against.
     */
    public function actionFireSchedule(string $name): Response
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        if (!str_starts_with($name, self::PREFIX)) {
            \Yii::$app->response->statusCode = 400;
            return $this->asJson(['error' => 'non-e2e schedule name']);
        }

        $schedule = Schedule::find()->where(['name' => $name])->one();
        if ($schedule === null) {
            throw new NotFoundHttpException("Schedule '{$name}' not found.");
        }

        $schedule->enabled = true;
        $schedule->next_run_at = time() - 60;
        $schedule->save(false, ['enabled', 'next_run_at']);

        /** @var ScheduleService $service */
        $service = \Yii::$app->get('scheduleService');
        $launched = $service->runDue();

        $latestJobId = (int)Job::find()
            ->where(['job_template_id' => $schedule->job_template_id])
            ->max('id');

        return $this->asJson([
            'launched' => $launched,
            'latest_job_id' => $latestJobId,
        ]);
    }

    /**
     * POST /e2e/create-cancelable-job?template=e2e-template
     *
     * Inserts a Job row in STATUS_QUEUED against the named e2e-* template
     * without going through the full launch pipeline (no queue push). A
     * runner of the template's group still claims it on its next poll: a
     * spec that needs the job to stay queued puts the template in a group
     * no runner serves. The cancel-walk spec needs a guaranteed-cancelable
     * target on every run — re-using the seeded running job would make
     * the test single-shot because the first cancel leaves the fixture in
     * STATUS_CANCELED forever.
     *
     * The lookup also finds a soft-deleted template: where() replaces the
     * deleted_at condition of JobTemplate::find(). projects/crud.spec.ts
     * relies on it to give the soft-deleted e2e-softdel-template a waiting
     * job, which the project delete must cancel.
     */
    public function actionCreateCancelableJob(string $template = 'e2e-template'): Response
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        if (!str_starts_with($template, self::PREFIX)) {
            \Yii::$app->response->statusCode = 400;
            return $this->asJson(['error' => 'non-e2e template name']);
        }

        $tpl = JobTemplate::find()->where(['name' => $template])->one();
        if ($tpl === null) {
            throw new NotFoundHttpException("Template '{$template}' not found.");
        }

        $job = new Job();
        $job->job_template_id = $tpl->id;
        $job->launched_by = (int)$tpl->created_by;
        $job->status = Job::STATUS_QUEUED;
        $job->timeout_minutes = 120;
        $job->has_changes = 0;
        $job->queued_at = time();
        $job->created_at = time();
        $job->updated_at = time();
        $job->save(false);

        return $this->asJson(['job_id' => (int)$job->id]);
    }

    /**
     * POST /e2e/create-deleted-template-request?launcher=e2e-operator
     *
     * A job of the soft-deleted e2e-deleted-gate-template that the e2e-*
     * user `launcher` launched and that waits for approval, with its pending
     * request (E2eDeletedTemplateApprovalSeeder::waitingJob()): a vote must
     * find that it no longer waits. Made on demand because the maintenance
     * run cancels such jobs; the seeder holds that sweep off for a while.
     * Answers {job_id, request_id}; 404 when the seed has not run.
     */
    public function actionCreateDeletedTemplateRequest(string $launcher = 'e2e-operator'): Response
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        if (!str_starts_with($launcher, self::PREFIX)) {
            \Yii::$app->response->statusCode = 400;
            return $this->asJson(['error' => 'non-e2e launcher name']);
        }

        try {
            $ids = (new E2eDeletedTemplateApprovalSeeder(static function (string $msg): void {
            }))->waitingJob($launcher);
        } catch (\RuntimeException $e) {
            // A PDOException of the seeder's transaction is a RuntimeException
            // too: named by its class, SQLSTATE and driver error code only.
            throw new NotFoundHttpException(ErrorText::of($e));
        }

        return $this->asJson($ids);
    }

    /**
     * POST /e2e/seed-syncing-project?name=e2e-project[&reset=1]
     *
     * Forces an e2e-* project into STATUS_SYNCING with sync_started_at
     * stamped and a couple of project_sync_log rows so the live-poll panel
     * spec has predictable content. Pass `reset=1` to flip it back to
     * STATUS_NEW + clear the log buffer (used as the spec's teardown so
     * subsequent project specs see a clean fixture).
     */
    public function actionSeedSyncingProject(string $name = 'e2e-project', int $reset = 0): Response
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        if (!str_starts_with($name, self::PREFIX)) {
            \Yii::$app->response->statusCode = 400;
            return $this->asJson(['error' => 'non-e2e project name']);
        }

        $project = Project::find()->where(['name' => $name])->one();
        if ($project === null) {
            throw new NotFoundHttpException("Project '{$name}' not found.");
        }

        if ($reset === 1) {
            $project->status = Project::STATUS_NEW;
            $project->sync_started_at = null;
            $project->save(false);
            ProjectSyncLog::deleteAll(['project_id' => $project->id]);
            return $this->asJson(['project_id' => (int)$project->id, 'reset' => true]);
        }

        $project->status = Project::STATUS_SYNCING;
        $project->sync_started_at = time();
        $project->save(false);

        ProjectSyncLog::deleteAll(['project_id' => $project->id]);
        $sequence = 1;
        foreach (['Cloning into /tmp/repo', 'Receiving objects: 100%', 'Resolving deltas: 100%'] as $line) {
            $row = new ProjectSyncLog();
            $row->project_id = (int)$project->id;
            $row->stream = ProjectSyncLog::STREAM_STDOUT;
            $row->content = $line . "\n";
            $row->sequence = $sequence++;
            $row->created_at = time();
            $row->save(false);
        }

        return $this->asJson(['project_id' => (int)$project->id]);
    }
}
