<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\controllers\api\runner\JobsController;
use app\models\Job;
use app\models\JobLog;
use app\models\Runner;
use app\tests\integration\DbTestCase;

/**
 * Verifies the implicit per-job liveness signal: every runner-side write
 * (logs, complete, tasks) bumps job.last_progress_at so a healthy-but-quiet
 * runner is never confused with a dead one by the reclaim sweep.
 */
class RunnerJobsHeartbeatTest extends DbTestCase
{
    public function testAppendingLogsBumpsLastProgressAt(): void
    {
        [$runner, $job] = $this->makeRunningJob();
        $job->last_progress_at = time() - 5_000;
        $job->save(false);

        $controller = $this->makeController($runner);
        \Yii::$app->request->setBodyParams([
            'stream' => JobLog::STREAM_STDOUT,
            'content' => 'task: gathering facts',
            'sequence' => 1,
        ]);
        $controller->actionLogs($job->id);

        $job->refresh();
        $this->assertGreaterThan(time() - 60, (int)$job->last_progress_at);
    }

    public function testCompleteBumpsLastProgressAt(): void
    {
        [$runner, $job] = $this->makeRunningJob();
        $job->last_progress_at = time() - 5_000;
        $job->save(false);

        $controller = $this->makeController($runner);
        \Yii::$app->request->setBodyParams(['exit_code' => 0, 'has_changes' => false]);
        $controller->actionComplete($job->id);

        $job->refresh();
        $this->assertGreaterThan(time() - 60, (int)$job->last_progress_at);
    }

    public function testTasksBumpsLastProgressAt(): void
    {
        [$runner, $job] = $this->makeRunningJob();
        $job->last_progress_at = time() - 5_000;
        $job->save(false);

        $controller = $this->makeController($runner);
        \Yii::$app->request->setBodyParams(['tasks' => []]);
        $controller->actionTasks($job->id);

        $job->refresh();
        $this->assertGreaterThan(time() - 60, (int)$job->last_progress_at);
    }

    public function testClaimedJobHasFreshLastProgressAt(): void
    {
        // Regression: a freshly claimed job must not be eligible for reclaim
        // immediately. JobClaimService::claim() sets last_progress_at = time().
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        $this->createJob($template->id, $user->id);

        /** @var \app\services\JobClaimService $claim */
        $claim = \Yii::$app->get('jobClaimService');
        $claimed = $claim->claim($group, $runner);

        $this->assertNotNull($claimed);
        $this->assertGreaterThan(time() - 60, (int)$claimed->last_progress_at);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{0: Runner, 1: Job}
     */
    private function makeRunningJob(): array
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $job = $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);
        $job->runner_id = $runner->id;
        $job->started_at = time() - 100;
        $job->last_progress_at = time() - 100;
        $job->save(false);

        return [$runner, $job];
    }

    /**
     * Anonymous subclass of the runner JobsController that pre-populates the
     * authenticated runner so we can call action methods without going through
     * Bearer-token authentication. Also installs a JSON web request component
     * since the action methods rely on $request->bodyParams.
     */
    private function makeController(Runner $runner): JobsController
    {
        if (!\Yii::$app->has('request') || !(\Yii::$app->request instanceof \yii\web\Request)) {
            \Yii::$app->set('request', new \yii\web\Request([
                'enableCsrfValidation' => false,
                'cookieValidationKey' => 'test-key',
                'scriptUrl' => '/index.php',
                'baseUrl' => '',
            ]));
        }
        if (!\Yii::$app->has('response') || !(\Yii::$app->response instanceof \yii\web\Response)) {
            \Yii::$app->set('response', new \yii\web\Response());
        }

        return new class ('jobs', \Yii::$app, $runner) extends JobsController {
            public function __construct(string $id, $module, Runner $runner)
            {
                parent::__construct($id, $module);
                $this->currentRunner = $runner;
            }
        };
    }
}
