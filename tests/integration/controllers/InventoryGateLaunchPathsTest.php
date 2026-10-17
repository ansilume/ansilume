<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\JobsController;
use app\controllers\JobController;
use app\controllers\JobTemplateController;
use app\controllers\TriggerController;
use app\models\ApiToken;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\Schedule;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\JobLaunchService;
use app\services\ScheduleService;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\integration\services\ApprovalVoteFixtures;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\services\LaunchGateFixtures;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * Every launch path waits for the approval rule of a job template's
 * inventory, and answers a refused launch as it answers any: the web
 * launch, the quick launch of the dashboard, POST /api/v1/jobs, the
 * re-launch, a schedule, a job trigger and a workflow job step. Each path
 * launches through JobLaunchService, which reads the gate (JobTemplateGate);
 * the completeness check fails when a new caller of the launch service
 * appears, so that it gets a case here.
 *
 * The workflow's job step comes after a pause step, and the inventory gets
 * its rule (or the template its conflicting own rule) while the run waits
 * there: the launch of the step itself must see it, whatever the workflow's
 * pre-flight checked when the run started.
 */
class InventoryGateLaunchPathsTest extends WebControllerTestCase
{
    use PresentsTriggerTokens;
    use ScheduleAnswerFixtures;
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;
    use LaunchGateFixtures;

    /**
     * Every file of controllers/ and services/ that asks for the launch
     * service, with the launch paths below that go through it.
     */
    private const LAUNCH_CALLERS = [
        'controllers/JobTemplateController.php' => ['web launch', 'quick launch'],
        'controllers/TriggerController.php' => ['job trigger'],
        'controllers/api/v1/JobsController.php' => ['POST /api/v1/jobs'],
        'services/RelaunchService.php' => ['relaunch'],
        'services/ScheduleService.php' => ['schedule'],
        'services/WorkflowStepDispatcher.php' => ['workflow job step'],
    ];

    private RecordingNotificationDispatcher $notifications;

    private User $launcher;

    private ApprovalRule $rule;

    private JobTemplate $template;

    /** The finished job a re-launch repeats; null on other paths. */
    private ?Job $original = null;

    private ?Schedule $schedule = null;

    private ?WorkflowStep $jobStep = null;

    private ?WorkflowJob $run = null;

    /** @var array<int|string, mixed>|string|null where the job page's controller redirected */
    private array|string|null $redirect = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->launcher = $this->createUserWithRole('paths-launcher', 'operator');
        $this->rule = $this->listRule((int)$this->launcher->id, [$this->createUserWithRole('paths-approver', 'operator')]);
        $this->template = $this->voteTemplate((int)$this->launcher->id);
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pathProvider(): array
    {
        $paths = [];
        foreach (self::LAUNCH_CALLERS as $names) {
            foreach ($names as $name) {
                $paths[$name] = [$name];
            }
        }

        return $paths;
    }

    /**
     * regression: every launch path queued the job of a job template whose
     * inventory had an approval rule, without approval: the launch read only
     * the template's own rule. Now the job waits for the inventory's rule,
     * and its request says so.
     *
     * @dataProvider pathProvider
     */
    public function testRegressionEveryLaunchPathQueuedAnInventoryGatedJobWithoutApproval(string $path): void
    {
        $answer = $this->launchVia($path, fn () => $this->gateInventoryOf($this->template, $this->rule));

        $job = $this->onlyLaunchedJob();
        $request = $this->assertWaitsFor($job, $this->rule, ApprovalRequest::REQUIRED_BY_INVENTORY);
        $this->assertSame((int)$this->launcher->id, (int)$job->launched_by);
        $this->assertSame(
            ['required_by' => ApprovalRequest::REQUIRED_BY_INVENTORY, 'inventory_id' => (int)$this->template->inventory_id],
            array_intersect_key((array)$this->requestedContextOf($request), ['required_by' => 0, 'inventory_id' => 0])
        );
        $this->assertSame([], $this->jobLaunchedNotifications(), 'the job may never run: no job.launched');
        $this->assertWaitingAnswer($path, $answer, $job, $request);
    }

    /**
     * A launch that meets two different rules (the template's own and its
     * inventory's) is refused on every path, with nothing written, and each
     * path answers it as it answers any refused launch.
     *
     * @dataProvider pathProvider
     */
    public function testEveryLaunchPathAnswersTwoDifferentRulesWithTheRefusal(string $path): void
    {
        $other = $this->listRule((int)$this->launcher->id, [$this->createUserWithRole('paths-other', 'operator')]);
        $requestsBefore = (int)ApprovalRequest::find()->count();

        $answer = $this->launchVia($path, function () use ($other): void {
            $this->gateInventoryOf($this->template, $this->rule);
            JobTemplate::updateAll(['approval_rule_id' => $other->id], ['id' => $this->template->id]);
        });

        $this->assertSame([], $this->launchedJobs(), 'no job is written');
        $this->assertSame($requestsBefore, (int)ApprovalRequest::find()->count(), 'no request is written');
        $text = sprintf(JobLaunchService::RULES_DIFFER, (int)$this->template->id, (int)$this->template->inventory_id);
        $this->assertRefusalAnswer($path, $answer, $text);
    }

    /**
     * The paths above are every launch path: each file of controllers/ and
     * services/ that asks for the launch service is listed with its paths.
     * A new one fails here until it gets its case in pathProvider().
     */
    public function testEveryCallerOfTheLaunchServiceIsALaunchPathHere(): void
    {
        $root = dirname(__DIR__, 3);
        $callers = [];
        foreach (['controllers', 'services'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                if ($file->getExtension() === 'php' && preg_match('~->get\(\s*[\'"]jobLaunchService[\'"]\s*\)~', (string)file_get_contents($file->getPathname())) === 1) {
                    $callers[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($callers);

        $this->assertSame(array_keys(self::LAUNCH_CALLERS), $callers, 'a new caller of the launch service needs a launch path here');
        $this->assertCount(7, self::pathProvider());
    }

    // -- The launch paths ---------------------------------------------------------

    /**
     * Launch the template through $path as the launcher; $gate() changes
     * what gates it, right before the launch (for the workflow job step,
     * while the run waits at the pause step before it). What the path
     * answered.
     */
    private function launchVia(string $path, callable $gate): mixed
    {
        return match ($path) {
            'web launch' => $this->webLaunch(['id' => (string)$this->template->id], [], $gate),
            'quick launch' => $this->webLaunch([], ['id' => (string)$this->template->id], $gate),
            'POST /api/v1/jobs' => $this->apiLaunch($gate),
            'relaunch' => $this->relaunch($gate),
            'schedule' => $this->scheduledLaunch($gate),
            'job trigger' => $this->triggerLaunch($gate),
            'workflow job step' => $this->workflowStepLaunch($gate),
        };
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $post
     */
    private function webLaunch(array $query, array $post, callable $gate): mixed
    {
        $this->loginAs($this->launcher);
        $gate();
        $this->setQueryParams($query);
        $this->setPost($post);

        return $this->jobTemplateController()->actionLaunch();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function apiLaunch(callable $gate): array
    {
        ['raw' => $raw] = ApiToken::generate((int)$this->launcher->id, 'paths-api');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        $user->loginByAccessToken($raw);
        $gate();
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['job_template_id' => $this->template->id]);

        $body = (new JobsController('api/v1/jobs', \Yii::$app))->actionCreate();

        return ['status' => \Yii::$app->response->statusCode, 'body' => $body];
    }

    private function relaunch(callable $gate): Response
    {
        $this->original = $this->createJob((int)$this->template->id, (int)$this->launcher->id, Job::STATUS_SUCCEEDED);
        $this->loginAs($this->launcher);
        $gate();

        return $this->jobController()->actionRelaunch((int)$this->original->id);
    }

    private function scheduledLaunch(callable $gate): int
    {
        $this->schedule = $this->createPlainSchedule((int)$this->template->id, (int)$this->launcher->id, null, time() - 60);
        $gate();
        /** @var ScheduleService $schedules */
        $schedules = \Yii::$app->get('scheduleService');

        return $schedules->runDue();
    }

    private function triggerLaunch(callable $gate): Response
    {
        $this->presentTriggerToken($this->template->generateTriggerToken((int)$this->launcher->id));
        $gate();

        return (new TriggerController('trigger', \Yii::$app))->actionFire();
    }

    private function workflowStepLaunch(callable $gate): WorkflowJobStep
    {
        $launcherId = (int)$this->launcher->id;
        $workflow = $this->createWorkflowTemplate($launcherId);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $this->jobStep = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $this->run = $this->workflowEngine()->launch($workflow, $launcherId);
        $this->assertSame([], $this->launchedJobs(), 'the run waits at the pause step');

        $gate();
        $this->workflowEngine()->resume($this->run, $launcherId);

        $execution = $this->executionOf($this->run, $this->jobStep);
        $this->assertNotNull($execution, 'the job step ran');

        return $execution;
    }

    // -- What the paths answer ------------------------------------------------------

    private function assertWaitingAnswer(string $path, mixed $answer, Job $job, ApprovalRequest $request): void
    {
        match ($path) {
            'web launch', 'quick launch' => $this->assertWebLaunchWaits($answer, $job),
            'POST /api/v1/jobs' => $this->assertApiLaunchWaits($answer, $job, $request),
            'relaunch' => $this->assertRelaunchWaits($answer),
            'schedule' => $this->assertSame([], $this->scheduleFailures(), 'the schedule launched'),
            'job trigger' => $this->assertTriggerWaits($answer, $job),
            'workflow job step' => $this->assertStepWaits($answer, $job),
        };
    }

    private function assertRefusalAnswer(string $path, mixed $answer, string $text): void
    {
        match ($path) {
            'web launch', 'quick launch' => $this->assertWebLaunchRefused($answer, $text),
            'POST /api/v1/jobs' => $this->assertSame(['status' => 422, 'body' => ['error' => ['message' => $text]]], $answer),
            'relaunch' => $this->assertRelaunchRefused($answer, $text),
            'schedule' => $this->assertSame([$text], $this->scheduleFailures()),
            'job trigger' => $this->assertTriggerRefused($answer, $text),
            'workflow job step' => $this->assertStepRefused($answer, $text),
        };
    }

    private function assertWebLaunchWaits(mixed $answer, Job $job): void
    {
        $this->assertInstanceOf(Response::class, $answer, 'redirected to the job');
        $this->assertSame(
            ['info' => sprintf(JobTemplateController::FLASH_WAITING_FOR_APPROVAL, (int)$job->id)],
            \Yii::$app->session->getAllFlashes()
        );
    }

    private function assertWebLaunchRefused(mixed $answer, string $text): void
    {
        $this->assertSame('rendered:launch', $answer, 'the form again');
        $this->assertSame(['danger' => sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, $text)], \Yii::$app->session->getAllFlashes());
    }

    private function assertApiLaunchWaits(mixed $answer, Job $job, ApprovalRequest $request): void
    {
        $this->assertIsArray($answer);
        $this->assertSame(201, $answer['status']);
        $this->assertIsArray($answer['body']['data'] ?? null);
        $data = $answer['body']['data'];
        $this->assertSame((int)$job->id, (int)$data['id']);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $data['status']);
        $this->assertSame((int)$request->id, $data['approval_request_id']);
    }

    private function assertRelaunchWaits(mixed $answer): void
    {
        $this->assertInstanceOf(Response::class, $answer);
        $this->assertArrayNotHasKey('danger', \Yii::$app->session->getAllFlashes());
    }

    private function assertRelaunchRefused(mixed $answer, string $text): void
    {
        $this->assertInstanceOf(Response::class, $answer);
        $this->assertNotNull($this->original);
        $this->assertSame(['view', 'id' => (int)$this->original->id], $this->redirect, 'back to the original job');
        $this->assertSame(['danger' => sprintf(JobController::RELAUNCH_FAILED, $text)], \Yii::$app->session->getAllFlashes());
    }

    private function assertTriggerWaits(mixed $answer, Job $job): void
    {
        $this->assertInstanceOf(Response::class, $answer);
        $this->assertSame(201, $answer->statusCode);
        $this->assertIsArray($answer->data);
        $this->assertSame((int)$job->id, (int)$answer->data['job_id']);
        $this->assertTrue($this->triggerFired(), 'trigger.fired');
    }

    private function assertTriggerRefused(mixed $answer, string $text): void
    {
        $this->assertInstanceOf(Response::class, $answer);
        $this->assertSame(422, $answer->statusCode);
        $this->assertSame(['error' => $text], $answer->data);
        $this->assertFalse($this->triggerFired(), 'a refused launch is no trigger.fired');
    }

    private function assertStepWaits(mixed $answer, Job $job): void
    {
        $this->assertInstanceOf(WorkflowJobStep::class, $answer);
        $answer->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $answer->status, 'the step waits with its job');
        $this->assertSame((int)$job->id, (int)$answer->job_id);
        $this->assertNotNull($this->run);
        $this->run->refresh();
        $this->assertSame(WorkflowJob::STATUS_RUNNING, $this->run->status);
    }

    private function assertStepRefused(mixed $answer, string $text): void
    {
        $this->assertInstanceOf(WorkflowJobStep::class, $answer);
        $answer->refresh();
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $answer->status);
        $this->assertSame(mb_substr($text, 0, 500), $answer->error_message);
        $this->assertNull($answer->job_id);
        $this->assertNotNull($this->run);
        $this->run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $this->run->status, 'the run fails');
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * The jobs of the template that the launch wrote (a re-launch's original
     * is not one of them).
     *
     * @return list<Job>
     */
    private function launchedJobs(): array
    {
        $query = Job::find()->where(['job_template_id' => $this->template->id])->orderBy(['id' => SORT_ASC]);
        if ($this->original !== null) {
            $query->andWhere(['<>', 'id', $this->original->id]);
        }
        /** @var list<Job> $jobs */
        $jobs = $query->all();

        return $jobs;
    }

    private function onlyLaunchedJob(): Job
    {
        $jobs = $this->launchedJobs();
        $this->assertCount(1, $jobs, 'the path launched one job');

        return $jobs[0];
    }

    /**
     * The job.launched notifications of the template's jobs.
     *
     * @return list<array<string, mixed>>
     */
    private function jobLaunchedNotifications(): array
    {
        return array_values(array_filter(
            $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_LAUNCHED),
            fn (array $payload): bool => is_array($payload['job'] ?? null) && ($payload['job']['template_id'] ?? null) === (string)$this->template->id
        ));
    }

    /**
     * The errors of the schedule's failed-to-launch notifications.
     *
     * @return list<mixed>
     */
    private function scheduleFailures(): array
    {
        $this->assertNotNull($this->schedule);
        $errors = [];
        foreach ($this->notifications->payloadsOf(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH) as $payload) {
            $about = is_array($payload['schedule'] ?? null) ? $payload['schedule'] : [];
            if (($about['id'] ?? null) === (string)$this->schedule->id) {
                $errors[] = $about['error'] ?? null;
            }
        }

        return $errors;
    }

    private function triggerFired(): bool
    {
        return AuditLog::find()->where(['action' => AuditLog::ACTION_TRIGGER_FIRED, 'object_id' => $this->template->id])->exists();
    }

    private function jobTemplateController(): JobTemplateController
    {
        return new class ('job-template', \Yii::$app) extends JobTemplateController {
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

    private function jobController(): JobController
    {
        $test = $this;

        return new class ('job', \Yii::$app, $test) extends JobController {
            /**
             * @param array<string, mixed> $config
             */
            public function __construct(string $id, \yii\base\Module $module, private readonly InventoryGateLaunchPathsTest $test, array $config = [])
            {
                parent::__construct($id, $module, $config);
            }

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $this->test->recordRedirect($url);

                return new Response();
            }
        };
    }

    /**
     * @param array<int|string, mixed>|string $url
     */
    public function recordRedirect(array|string $url): void
    {
        $this->redirect = $url;
    }
}
