<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\ErrorText;
use app\controllers\api\v1\JobsController;
use app\models\ApiToken;
use app\models\Job;
use app\models\JobTemplate;
use app\services\JobLaunchService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;

/**
 * A launch through POST /api/v1/jobs that fails inside the launch answers a
 * bare 500 and logs one line that says "Job launch failed: " once
 * (JobsController::LAUNCH_FAILED_LOG with JobLaunchService::failureReason(),
 * which leaves out the prefix of JobLaunchService's own failures). A
 * database error is named only by its class, SQLSTATE and driver error code
 * (ErrorText).
 *
 * Regression: the failures of JobLaunchService were logged as
 * "Job launch failed: Job launch failed: ...".
 */
class JobsControllerLaunchLogTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** @var array<string, mixed> component definitions to restore */
    private array $swapped = [];

    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swap('notificationDispatcher', new RecordingNotificationDispatcher());
        $user = $this->createUserWithRole('launch-log', 'operator');
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'launch-log');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $component */
        $component = \Yii::$app->user;
        $component->loginByAccessToken($raw);
        $userId = (int)$user->id;
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->swapped as $id => $original) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    /**
     * Regression: a job record the launch could not save (extra vars longer
     * than the record takes fail its validation) was logged with the prefix
     * twice. A verbosity out of range did the same; it is refused with 422
     * before the launch now (LaunchInputTest).
     */
    public function testRegressionALaunchFailureOfTheServiceIsLoggedOnce(): void
    {
        $logged = $this->launchLogs(['extra_vars' => ['note' => str_repeat('x', 70000)]]);

        $this->assertCount(1, $logged);
        $this->assertMatchesRegularExpression('/^Job launch failed: Failed to save job record: \{"extra_vars":\[".+"\]\}$/', $logged[0]);
        $this->assertSame(1, substr_count($logged[0], 'Job launch failed: '));
    }

    /**
     * Regression: a database error while saving the job (extra vars longer
     * than their column) was logged as
     * "Job launch failed: Job launch failed: the job could not be saved.".
     */
    public function testRegressionADatabaseErrorOfTheServiceIsLoggedOnce(): void
    {
        $before = (int)Job::find()->count();

        $logged = $this->launchLogs(['extra_vars' => ['note' => str_repeat('€', 30000)]]);

        $this->assertSame(['Job launch failed: the job could not be saved.'], $logged);
        $this->assertSame($before, (int)Job::find()->count(), 'nothing was written');
    }

    public function testAnotherErrorGetsThePrefix(): void
    {
        $this->failingLaunches(new \RuntimeException('The runner payload builder is not configured.'));

        $this->assertSame(['Job launch failed: The runner payload builder is not configured.'], $this->launchLogs([]));
    }

    /**
     * A PDOException that escaped Yii's wrapping (such as a failed BEGIN)
     * is named by class, SQLSTATE and driver error code only.
     */
    public function testADatabaseErrorOutsideTheServicesWrappingGetsThePrefix(): void
    {
        $error = new \PDOException("SQLSTATE[HY000]: General error: 2006 MySQL server has gone away (launch-log-marker-5150)");
        $this->failingLaunches($error);

        $logged = $this->launchLogs([]);

        $this->assertSame([sprintf(JobsController::LAUNCH_FAILED_LOG, ErrorText::of($error))], $logged);
        $this->assertStringNotContainsString('launch-log-marker-5150', $logged[0]);
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * Launch $this->template through the API with $body, which must fail
     * with the bare 500; the errors JobsController logged meanwhile.
     *
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private function launchLogs(array $body): array
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['job_template_id' => (int)$this->template->id] + $body);
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            /** @var \yii\base\Module $app */
            $app = \Yii::$app;
            $result = (new JobsController('api/v1/jobs', $app))->actionCreate();
        } finally {
            \Yii::setLogger($previous);
        }

        $this->assertSame(['error' => ['message' => 'Launch failed.']], $result);
        $this->assertSame(500, \Yii::$app->response->statusCode);
        $logged = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_ERROR && $message[2] === JobsController::class && is_string($message[0])) {
                $logged[] = $message[0];
            }
        }

        return $logged;
    }

    private function failingLaunches(\Throwable $error): void
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

    private function swap(string $id, object $component): void
    {
        if (!array_key_exists($id, $this->swapped)) {
            $this->swapped[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $component);
    }
}
