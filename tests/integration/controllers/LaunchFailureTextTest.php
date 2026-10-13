<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobController;
use app\controllers\JobTemplateController;
use app\controllers\TriggerController;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\JobLaunchService;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;
use yii\web\Response;

/**
 * A job launch that fails inside JobLaunchService, for real: extra vars of
 * more than 65535 bytes pass the job record's rule (it counts characters)
 * but not its TEXT column, so the database refuses the job, and the launch
 * fails with "Job launch failed: the job could not be saved." The texts
 * that word the failure themselves say "failed" once
 * (JobLaunchService::failureReason()): the web launch flash
 * (JobTemplateController::FLASH_LAUNCH_FAILED), the re-launch flash
 * (JobController::RELAUNCH_FAILED) and the job trigger's log line
 * (TriggerController::JOB_TRIGGER_FAILED); the API's log line has its own
 * test (JobsControllerLaunchLogTest).
 */
class LaunchFailureTextTest extends WebControllerTestCase
{
    use PresentsTriggerTokens;
    use TeamScopeFixtures;

    /** 20000 three-byte characters: 60000 bytes, which a TEXT column holds once, not twice. */
    private const HALF_A_COLUMN = 20000;

    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    private User $user;

    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swapped[] = ['notificationDispatcher', \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null];
        \Yii::$app->set('notificationDispatcher', new RecordingNotificationDispatcher());
        $this->user = $this->createUserWithRole('launch-text', 'operator');
        $this->loginAs($this->user);
        $userId = (int)$this->user->id;
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->swapped) as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    /**
     * Regression: the web launch flashed
     * "Launch failed: Job launch failed: the job could not be saved.".
     */
    public function testRegressionTheWebLaunchFlashSaysFailedOnce(): void
    {
        $this->setQueryParams(['id' => (string)$this->template->id]);
        $this->setPost(['overrides' => ['extra_vars' => (string)json_encode(['note' => $this->bytes(2)], JSON_UNESCAPED_UNICODE)]]);

        $result = $this->webLaunchController()->actionLaunch();

        $this->assertSame('rendered:launch', $result);
        $this->assertSame(['danger' => 'Launch failed: the job could not be saved.'], \Yii::$app->session->getAllFlashes());
        $this->assertSame(['danger' => sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, JobLaunchService::SAVE_FAILED)], \Yii::$app->session->getAllFlashes());
        $this->assertSame(0, $this->jobCount());
    }

    /**
     * Regression: the re-launch flashed
     * "Re-launch failed: Job launch failed: the job could not be saved.".
     * The template's extra vars and the job's each fit the column, both
     * together do not.
     */
    public function testRegressionTheRelaunchFlashSaysFailedOnce(): void
    {
        $this->template->extra_vars = (string)json_encode(['from_template' => $this->bytes(1)], JSON_UNESCAPED_UNICODE);
        $this->template->save(false);
        $original = $this->createJob((int)$this->template->id, (int)$this->user->id, Job::STATUS_SUCCEEDED);
        Job::updateAll(['extra_vars' => (string)json_encode(['from_job' => $this->bytes(1)], JSON_UNESCAPED_UNICODE)], ['id' => $original->id]);
        $before = $this->jobCount();

        $controller = new class ('job', $this->app()) extends JobController {
            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
        $controller->actionRelaunch((int)$original->id);

        $this->assertSame(['danger' => 'Re-launch failed: the job could not be saved.'], \Yii::$app->session->getAllFlashes());
        $this->assertSame(['danger' => sprintf(JobController::RELAUNCH_FAILED, JobLaunchService::SAVE_FAILED)], \Yii::$app->session->getAllFlashes());
        $this->assertSame($before, $this->jobCount());
    }

    /**
     * Regression: the job trigger logged
     * "Trigger for template #N failed: Job launch failed: the job could not be saved.".
     */
    public function testRegressionTheJobTriggerLogSaysFailedOnce(): void
    {
        $this->presentTriggerToken($this->template->generateTriggerToken((int)$this->user->id));
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setRawBody((string)json_encode(['extra_vars' => ['note' => $this->bytes(2)]]));
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $result = (new TriggerController('trigger', $this->app()))->actionFire();
        } finally {
            \Yii::setLogger($previous);
        }

        $this->assertSame(500, $result->statusCode);
        $this->assertSame(['error' => 'Launch failed.'], $result->data);
        $logged = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_ERROR && $message[2] === TriggerController::class) {
                $logged[] = $message[0];
            }
        }
        $this->assertSame(["Trigger for template #{$this->template->id} failed: the job could not be saved."], $logged);
        $this->assertSame([sprintf(TriggerController::JOB_TRIGGER_FAILED, (int)$this->template->id, JobLaunchService::SAVE_FAILED)], $logged);
        $this->assertSame(0, $this->jobCount());
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * Text of $halves times HALF_A_COLUMN three-byte characters.
     */
    private function bytes(int $halves): string
    {
        return str_repeat('€', $halves * self::HALF_A_COLUMN);
    }

    private function jobCount(): int
    {
        return (int)Job::find()->where(['job_template_id' => $this->template->id])->count();
    }

    private function webLaunchController(): JobTemplateController
    {
        return new class ('job-template', $this->app()) extends JobTemplateController {
            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return $app;
    }
}
