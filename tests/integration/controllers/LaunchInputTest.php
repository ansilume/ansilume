<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\JobsController;
use app\controllers\JobTemplateController;
use app\controllers\TriggerController;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;
use yii\web\Response;

/**
 * Launch input the job record would refuse is refused before the launch,
 * with the record's own messages (JobLaunchInput): a verbosity outside 0-5,
 * a limit of more than 255 characters, a list where a value belongs.
 * POST /api/v1/jobs answers 422 with the messages, by field under "fields";
 * the web launch form shows them in its danger flash; a job trigger answers
 * 422 with them. Nothing is written, and nothing is logged as a failed
 * launch. A re-launch takes the limit and verbosity of a job record, which
 * took them already.
 *
 * Regression: such input failed inside the launch, as the job record
 * refused it: POST /api/v1/jobs and the trigger answered 500
 * "Launch failed.", the web form flashed
 * "Launch failed: Job launch failed: Failed to save job record: {...}", and a
 * list as the limit failed the request with "Array to string conversion".
 */
class LaunchInputTest extends WebControllerTestCase
{
    use PresentsTriggerTokens;
    use TeamScopeFixtures;

    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    private User $user;

    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swap('notificationDispatcher', new RecordingNotificationDispatcher());
        $this->user = $this->createUserWithRole('launch-input', 'operator');
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
     * Input, the message, the errors by field.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: array<string, string>}>
     */
    public static function invalidInputProvider(): array
    {
        $tooLong = str_repeat('w', 256);

        return [
            'a verbosity above 5' => [['verbosity' => 9], 'Verbosity must be no greater than 5.', ['verbosity' => 'Verbosity must be no greater than 5.']],
            'a verbosity below 0' => [['verbosity' => -1], 'Verbosity must be no less than 0.', ['verbosity' => 'Verbosity must be no less than 0.']],
            'a verbosity as text above 5' => [['verbosity' => '6'], 'Verbosity must be no greater than 5.', ['verbosity' => 'Verbosity must be no greater than 5.']],
            'a list as the verbosity' => [['verbosity' => [2]], 'Verbosity must be an integer.', ['verbosity' => 'Verbosity must be an integer.']],
            'a limit of 256 characters' => [['limit' => $tooLong], 'Limit should contain at most 255 characters.', ['limit' => 'Limit should contain at most 255 characters.']],
            'a list as the limit' => [['limit' => ['web']], 'Limit must be a string.', ['limit' => 'Limit must be a string.']],
            'both, the limit first' => [
                ['verbosity' => 7, 'limit' => $tooLong],
                'Limit should contain at most 255 characters. Verbosity must be no greater than 5.',
                ['limit' => 'Limit should contain at most 255 characters.', 'verbosity' => 'Verbosity must be no greater than 5.'],
            ],
        ];
    }

    /**
     * Regression: POST /api/v1/jobs with a verbosity outside 0-5 answered
     * 500 "Launch failed.": the job record refused it inside the launch.
     */
    public function testRegressionTheApiAnswered500ForAVerbosityOutsideZeroToFive(): void
    {
        [$result, $logged] = $this->apiLaunch(['verbosity' => 9]);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => 'Verbosity must be no greater than 5.', 'fields' => ['verbosity' => 'Verbosity must be no greater than 5.']]],
            $result
        );
        $this->assertSame([], $logged, 'no failed launch');
        $this->assertNoJob();
    }

    /**
     * @dataProvider invalidInputProvider
     * @param array<string, mixed> $input
     * @param array<string, string> $fields
     */
    public function testTheApiAnswers422WithTheErrorsByField(array $input, string $message, array $fields): void
    {
        [$result, $logged] = $this->apiLaunch($input);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $message, 'fields' => $fields]], $result);
        $this->assertSame([], $logged);
        $this->assertNoJob();
    }

    /**
     * The limits themselves are taken, and a number sent as text, as before.
     */
    public function testTheApiLaunchesWithInputAtTheLimits(): void
    {
        [$result] = $this->apiLaunch(['verbosity' => '5', 'limit' => str_repeat('w', 255)]);

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertIsArray($result);
        $job = $this->onlyJob();
        $this->assertSame(5, (int)$job->verbosity);
        $this->assertSame(str_repeat('w', 255), $job->limit);

        [$result] = $this->apiLaunch(['verbosity' => 0]);
        $this->assertSame(201, \Yii::$app->response->statusCode);
    }

    /**
     * Regression: the web launch form flashed
     * "Launch failed: Job launch failed: Failed to save job record: {...}"
     * for a verbosity outside 0-5 (a forged post; the form offers 0-5).
     */
    public function testRegressionTheWebLaunchFlashedTheJobRecordsSaveErrorForAVerbosityOutsideZeroToFive(): void
    {
        $result = $this->webLaunch(['verbosity' => '9']);

        $this->assertSame('rendered:launch', $result, 'the form again');
        $this->assertSame(['danger' => 'Launch failed: Verbosity must be no greater than 5.'], \Yii::$app->session->getAllFlashes());
        $this->assertSame(['danger' => sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, 'Verbosity must be no greater than 5.')], \Yii::$app->session->getAllFlashes());
        $this->assertNoJob();
    }

    public function testTheWebLaunchShowsATooLongLimitInItsFlash(): void
    {
        $result = $this->webLaunch(['limit' => str_repeat('w', 256), 'verbosity' => '2']);

        $this->assertSame('rendered:launch', $result);
        $this->assertSame(['danger' => 'Launch failed: Limit should contain at most 255 characters.'], \Yii::$app->session->getAllFlashes());
        $this->assertNoJob();
    }

    public function testTheWebLaunchLaunchesWithInputAtTheLimits(): void
    {
        $result = $this->webLaunch(['limit' => str_repeat('w', 255), 'verbosity' => '5', 'check_mode' => '0']);

        $this->assertInstanceOf(Response::class, $result);
        $job = $this->onlyJob();
        $this->assertSame(5, (int)$job->verbosity);
        $this->assertSame(str_repeat('w', 255), $job->limit);
    }

    /**
     * Regression: a job trigger with a verbosity outside 0-5 answered 500
     * "Launch failed.".
     */
    public function testRegressionTheJobTriggerAnswered500ForAVerbosityOutsideZeroToFive(): void
    {
        [$result, $warnings] = $this->trigger('{"verbosity": 9}');

        $this->assertSame(422, $result->statusCode);
        $this->assertSame(['error' => 'Verbosity must be no greater than 5.'], $result->data);
        $this->assertSame(["Trigger for template #{$this->template->id} rejected: Verbosity must be no greater than 5."], $warnings);
        $this->assertNoJob();
        $this->assertSame(0, (int)AuditLog::find()->where(['action' => AuditLog::ACTION_TRIGGER_FIRED, 'object_id' => $this->template->id])->count());
    }

    /**
     * Regression: a list as the limit of a job trigger failed the request
     * with "Array to string conversion".
     */
    public function testRegressionAListAsTheLimitOfAJobTriggerFailedTheRequest(): void
    {
        [$result] = $this->trigger('{"limit": ["web", "db"]}');

        $this->assertSame(422, $result->statusCode);
        $this->assertSame(['error' => 'Limit must be a string.'], $result->data);
        $this->assertNoJob();
    }

    public function testTheJobTriggerLaunchesWithANumberSentAsText(): void
    {
        [$result] = $this->trigger('{"verbosity": "4", "limit": "web"}');

        $this->assertSame(201, $result->statusCode);
        $job = $this->onlyJob();
        $this->assertSame(4, (int)$job->verbosity);
        $this->assertSame('web', $job->limit);
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * POST /api/v1/jobs for the template with $input; the answer and the
     * errors JobsController logged.
     *
     * @param array<string, mixed> $input
     * @return array{0: mixed, 1: list<string>}
     */
    private function apiLaunch(array $input): array
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['job_template_id' => (int)$this->template->id] + $input);

        return $this->logged(JobsController::class, Logger::LEVEL_ERROR, fn (): mixed => (new JobsController('api/v1/jobs', $this->app()))->actionCreate());
    }

    /**
     * The web launch form posted with $overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function webLaunch(array $overrides): Response|string
    {
        $this->setQueryParams(['id' => (string)$this->template->id]);
        $this->setPost(['overrides' => $overrides]);
        $controller = new class ('job-template', $this->app()) extends JobTemplateController {
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

        return $controller->actionLaunch();
    }

    /**
     * POST /trigger/fire for the template with the JSON $body; the answer
     * and the warnings TriggerController logged.
     *
     * @return array{0: Response, 1: list<string>}
     */
    private function trigger(string $body): array
    {
        $this->presentTriggerToken($this->template->generateTriggerToken((int)$this->user->id));
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setRawBody($body);

        [$result, $warnings] = $this->logged(
            TriggerController::class,
            Logger::LEVEL_WARNING,
            fn (): mixed => (new TriggerController('trigger', $this->app()))->actionFire()
        );
        $this->assertInstanceOf(Response::class, $result);

        return [$result, $warnings];
    }

    /**
     * What $action returned, and the messages of $level logged meanwhile in
     * $category.
     *
     * @return array{0: mixed, 1: list<string>}
     */
    private function logged(string $category, int $level, callable $action): array
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
        $messages = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === $level && $message[2] === $category && is_string($message[0])) {
                $messages[] = $message[0];
            }
        }

        return [$result, $messages];
    }

    private function assertNoJob(): void
    {
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $this->template->id])->count(), 'nothing was launched');
    }

    private function onlyJob(): Job
    {
        /** @var Job|null $job */
        $job = Job::find()->where(['job_template_id' => $this->template->id])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($job);

        return $job;
    }

    private function swap(string $id, object $component): void
    {
        $this->swapped[] = [$id, \Yii::$app->getComponents(true)[$id] ?? null];
        \Yii::$app->set($id, $component);
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return $app;
    }
}
