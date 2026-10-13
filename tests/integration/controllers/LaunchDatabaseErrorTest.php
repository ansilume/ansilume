<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\JobsController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\controllers\JobController;
use app\controllers\TriggerController;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowTemplate;
use app\services\JobLaunchService;
use app\services\WorkflowExecutionService;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;
use yii\web\Response;

/**
 * A launch that fails with a database error the launch services do not turn
 * into a refusal (a PDOException that escaped Yii's wrapping, such as a
 * failed BEGIN) reaches the re-launch flash, the API answer and the trigger
 * and API log lines only as ErrorText words it: class, SQLSTATE and driver
 * error code. Any other error keeps its message, as before; a job launch
 * failure of JobLaunchService loses only its "Job launch failed: " where
 * the flash or the log line says that itself
 * (JobLaunchService::failureReason()).
 *
 * Regression: these four places put the error's message into the flash,
 * the answer or the log, and the driver's message can quote a value.
 *
 * Regression: the re-launch flash and the job trigger's log line repeated
 * the prefix of JobLaunchService's failures: "Re-launch failed: Job launch
 * failed: ...", "Trigger for template #N failed: Job launch failed: ...".
 */
class LaunchDatabaseErrorTest extends WebControllerTestCase
{
    use PresentsTriggerTokens;
    use TeamScopeFixtures;

    private const MARKER = 'launch-db-marker-6633';

    /** What ErrorText says about the PDOException below. */
    private const SUMMARY = 'PDOException (SQLSTATE -, driver error -)';

    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swap('notificationDispatcher', new RecordingNotificationDispatcher());
        $this->user = $this->createUserWithRole('launch-db', 'operator');
        $this->loginAs($this->user);
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
     * The error, what the job launch wrappers say about it
     * (JobLaunchService::failureReason()), what the workflow wrappers say
     * about it (ErrorText).
     *
     * @return array<string, array{0: \Throwable, 1: string, 2: string}>
     */
    public static function errorProvider(): array
    {
        return [
            'a database error: class, SQLSTATE and driver error code only' => [self::pdoException(), self::SUMMARY, self::SUMMARY],
            'any other error keeps its message' => [
                new \RuntimeException('The runner payload builder is not configured.'),
                'The runner payload builder is not configured.',
                'The runner payload builder is not configured.',
            ],
            'a failure of JobLaunchService: the job wrappers say "failed" once' => [
                new \RuntimeException('Job launch failed: Failed to save job record: {"extra_vars":["Extra Vars is invalid."]}'),
                'Failed to save job record: {"extra_vars":["Extra Vars is invalid."]}',
                'Job launch failed: Failed to save job record: {"extra_vars":["Extra Vars is invalid."]}',
            ],
        ];
    }

    /**
     * @dataProvider errorProvider
     */
    public function testTheRelaunchFlashSaysWhatErrorTextSays(\Throwable $error, string $expected, string $workflowExpected): void
    {
        $template = $this->template();
        $original = $this->createJob((int)$template->id, (int)$this->user->id, Job::STATUS_SUCCEEDED);
        $this->failingJobLaunches($error);

        $result = $this->jobController()->actionRelaunch((int)$original->id);

        $this->assertInstanceOf(Response::class, $result);
        $flashes = \Yii::$app->session->getAllFlashes();
        $this->assertSame(['danger' => 'Re-launch failed: ' . $expected], $flashes);
        $this->assertSame(['danger' => sprintf(JobController::RELAUNCH_FAILED, $expected)], $flashes);
        $this->assertStringNotContainsString(self::MARKER, (string)json_encode($flashes));
    }

    /**
     * @dataProvider errorProvider
     */
    public function testTheApiLaunchLogsWhatErrorTextSays(\Throwable $error, string $expected, string $workflowExpected): void
    {
        $template = $this->template();
        $this->failingJobLaunches($error);
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['job_template_id' => (int)$template->id]);

        [$result, $logged] = $this->logged(
            JobsController::class,
            fn (): mixed => (new JobsController('api/v1/jobs', $this->app()))->actionCreate()
        );

        $this->assertSame(['error' => ['message' => 'Launch failed.']], $result);
        $this->assertSame(500, \Yii::$app->response->statusCode);
        // The line says "Job launch failed: " once, also for JobLaunchService's own failures.
        $this->assertSame(['Job launch failed: ' . $expected], $logged);
        $this->assertSame([sprintf(JobsController::LAUNCH_FAILED_LOG, $expected)], $logged);
    }

    /**
     * @dataProvider errorProvider
     */
    public function testTheJobTriggerLogsWhatErrorTextSays(\Throwable $error, string $expected, string $workflowExpected): void
    {
        $template = $this->template();
        $this->failingJobLaunches($error);
        $this->presentTriggerToken($template->generateTriggerToken((int)$this->user->id));

        [$result, $logged] = $this->logged(
            TriggerController::class,
            fn (): mixed => (new TriggerController('trigger', $this->app()))->actionFire()
        );

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(500, $result->statusCode);
        $this->assertSame(['error' => 'Launch failed.'], $result->data);
        $this->assertSame(["Trigger for template #{$template->id} failed: {$expected}"], $logged);
        $this->assertSame([sprintf(TriggerController::JOB_TRIGGER_FAILED, (int)$template->id, $expected)], $logged);
    }

    /**
     * @dataProvider errorProvider
     */
    public function testTheWorkflowTriggerLogsWhatErrorTextSays(\Throwable $error, string $jobExpected, string $expected): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $this->failingWorkflowLaunches($error);
        $this->presentTriggerToken($workflow->generateTriggerToken((int)$this->user->id));

        [$result, $logged] = $this->logged(
            TriggerController::class,
            fn (): mixed => (new TriggerController('trigger', $this->app()))->actionFireWorkflow()
        );

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(500, $result->statusCode);
        $this->assertSame(['error' => 'Launch failed.'], $result->data);
        $this->assertSame(["Workflow trigger for template #{$workflow->id} failed: {$expected}"], $logged);
        $this->assertSame([sprintf(TriggerController::WORKFLOW_TRIGGER_FAILED, (int)$workflow->id, $expected)], $logged);
    }

    /**
     * @dataProvider errorProvider
     */
    public function testTheApiWorkflowLaunchAnswersWhatErrorTextSays(\Throwable $error, string $jobExpected, string $expected): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->user->id);
        $this->failingWorkflowLaunches($error);

        $result = (new WorkflowTemplatesController('api/v1/workflow-templates', $this->app()))->actionLaunch((int)$workflow->id);

        $this->assertSame(['error' => ['message' => $expected]], $result);
        // A database error is no refusal: 500 (WorkflowLaunchDatabaseErrorTest); any other error answers 422.
        $this->assertSame($error instanceof \PDOException ? 500 : 422, \Yii::$app->response->statusCode);
        $this->assertSame(0, (int)WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->count());
    }

    // -- Helpers ----------------------------------------------------------------

    private static function pdoException(): \PDOException
    {
        return new \PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '" . self::MARKER . "' for key 'PRIMARY'"
        );
    }

    private function swap(string $id, object $component): void
    {
        $this->swapped[] = [$id, \Yii::$app->getComponents(true)[$id] ?? null];
        \Yii::$app->set($id, $component);
    }

    private function failingJobLaunches(\Throwable $error): void
    {
        $this->swap('jobLaunchService', new class ($error) extends JobLaunchService {
            public function __construct(private readonly \Throwable $error)
            {
                parent::__construct();
            }

            public function launch(JobTemplate $template, int $launchedBy, #[\SensitiveParameter] array $overrides = []): Job
            {
                throw $this->error;
            }
        });
    }

    private function failingWorkflowLaunches(\Throwable $error): void
    {
        $this->swap('workflowExecutionService', new class ($error) extends WorkflowExecutionService {
            public function __construct(private readonly \Throwable $error)
            {
                parent::__construct();
            }

            public function launch(
                WorkflowTemplate $template,
                int $launchedBy,
                #[\SensitiveParameter] array $overrides = [],
                string $source = 'web'
            ): WorkflowJob {
                throw $this->error;
            }
        });
    }

    private function template(): JobTemplate
    {
        $userId = (int)$this->user->id;

        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return $app;
    }

    private function jobController(): JobController
    {
        return new class ('job', $this->app()) extends JobController {
            /**
             * @param string|array<int|string, mixed> $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $r = new Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }

    /**
     * What $action returned, and the errors logged meanwhile in $category.
     *
     * @return array{0: mixed, 1: list<string>}
     */
    private function logged(string $category, callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $result = $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $errors = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_ERROR && $message[2] === $category && is_string($message[0])) {
                $this->assertStringNotContainsString(self::MARKER, $message[0]);
                $errors[] = $message[0];
            }
        }

        return [$result, $errors];
    }
}
