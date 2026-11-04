<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\JobTemplatesController;
use app\controllers\api\v1\ProjectsController;
use app\controllers\JobTemplateController;
use app\controllers\ProjectController;
use app\jobs\LintProjectJob;
use app\jobs\LintTemplateJob;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Project;
use app\models\User;
use app\services\LintService;
use app\tests\integration\RecordingQueue;
use app\tests\integration\RestoresComponents;
use yii\web\Response;

/**
 * Regression: saving a job template waited for ansible-lint. The web
 * actions ran LintService::runForTemplate() in the request, after the save:
 * about two seconds for a small playbook, and up to 180 seconds while
 * another lint run (the lint phase of a sync, Run Lint, another save) held
 * the lock of the checkout, longer than nginx waits for php-fpm (a 504 for a
 * save that had succeeded). Run Lint on the project page did the same. A
 * template saved through the API was not linted at all.
 *
 * Every lint run a request asks for is a job of the queue-worker now: the
 * request starts no ansible-lint (the double counts the runs), marks the
 * record as queued (lint_queued_at) and pushes one LintTemplateJob or
 * LintProjectJob to the queue, whose requestedAt is that mark.
 */
class LintQueuedOnSaveTest extends WebControllerTestCase
{
    use RestoresComponents;

    private RecordingQueue $queue;

    /** @var LintService&object{runs: int} counts the lint runs, queues for real */
    private LintService $lint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new RecordingQueue();
        $this->swapComponent('queue', $this->queue);
        $this->lint = new class () extends LintService {
            public int $runs = 0;

            public function runForTemplate(JobTemplate $template, ?int $requestedAt = null): void
            {
                $this->runs++;
            }

            public function runForProject(Project $project, ?int $requestedAt = null): void
            {
                $this->runs++;
            }
        };
        $this->swapComponent('lintService', $this->lint);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    public function testRegressionCreatingATemplateOnTheWebQueuesItsLintInsteadOfRunningIt(): void
    {
        $user = $this->createUser('lint-queue-web-create');
        $this->loginAs($user);
        $name = 'lint-queue-created-' . uniqid('', true);
        $this->setPost(['JobTemplate' => $this->templateFields((int)$user->id, $name)]);

        $result = $this->webTemplateController()->actionCreate();

        $this->assertInstanceOf(Response::class, $result, 'the template was saved');
        $this->assertSame(0, $this->lint->runs, 'ansible-lint does not run in the request');
        $this->assertQueuedTemplateLint($this->templateNamed($name));
    }

    public function testRegressionUpdatingATemplateOnTheWebQueuesItsLintInsteadOfRunningIt(): void
    {
        $user = $this->createUser('lint-queue-web-update');
        $this->loginAs($user);
        $template = $this->template((int)$user->id);
        $this->setPost(['JobTemplate' => ['name' => 'lint-queue-renamed-' . uniqid('', true)]]);

        $result = $this->webTemplateController()->actionUpdate((int)$template->id);

        $this->assertInstanceOf(Response::class, $result, 'the template was saved');
        $this->assertSame(0, $this->lint->runs, 'ansible-lint does not run in the request');
        $this->assertQueuedTemplateLint($template);
    }

    /**
     * A clone starts without a lint result: it queues a run of its own.
     */
    public function testRegressionCloningATemplateQueuesALintRunOfTheClone(): void
    {
        $user = $this->createUser('lint-queue-web-clone');
        $this->loginAs($user);
        $source = $this->template((int)$user->id);

        $this->webTemplateController()->actionClone((int)$source->id);

        $this->assertSame(0, $this->lint->runs, 'ansible-lint does not run in the request');
        $this->assertQueuedTemplateLint($this->templateNamed($source->name . ' (copy)'));
    }

    public function testRegressionRunLintOnTheProjectPageQueuesALintRunInsteadOfRunningIt(): void
    {
        $user = $this->createSuperadmin('lint-queue-web-project');
        $this->loginAs($user);
        $project = $this->createProject((int)$user->id);

        $result = $this->webProjectController()->actionLint((int)$project->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(0, $this->lint->runs, 'ansible-lint does not run in the request');
        $this->assertQueuedProjectLint($project);
        $this->assertSame(
            [sprintf(ProjectController::FLASH_LINT_QUEUED, $project->name)],
            (array)\Yii::$app->session->getFlash('success')
        );
        $this->assertNotNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_LINTED, 'object_id' => $project->id]), 'the request is audited');
    }

    public function testRegressionCreatingATemplateThroughTheApiQueuesItsLint(): void
    {
        $user = $this->authenticateApiAdmin();
        $name = 'lint-queue-api-created-' . uniqid('', true);
        $this->setBody($this->templateFields((int)$user->id, $name));

        $answer = (new JobTemplatesController('api/v1/job-templates', \Yii::$app))->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame(0, $this->lint->runs, 'ansible-lint does not run in the request');
        $template = $this->templateNamed($name);
        $this->assertQueuedTemplateLint($template);
        $this->assertSame((int)$template->lint_queued_at, $this->dataOf($answer)['lint']['queued_at']);
    }

    public function testRegressionUpdatingATemplateThroughTheApiQueuesItsLint(): void
    {
        $user = $this->authenticateApiAdmin();
        $template = $this->template((int)$user->id);
        $this->setBody(['name' => 'lint-queue-api-renamed-' . uniqid('', true)]);

        $answer = (new JobTemplatesController('api/v1/job-templates', \Yii::$app))->actionUpdate((int)$template->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame(0, $this->lint->runs, 'ansible-lint does not run in the request');
        $this->assertQueuedTemplateLint($template);
        $this->assertSame((int)$this->reload($template)->lint_queued_at, $this->dataOf($answer)['lint']['queued_at']);
    }

    public function testRegressionLintThroughTheApiQueuesALintRunOfTheProject(): void
    {
        $user = $this->authenticateApiAdmin();
        $project = $this->createProject((int)$user->id);

        $answer = (new ProjectsController('api/v1/projects', \Yii::$app))->actionLint((int)$project->id);

        $this->assertSame(202, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame(0, $this->lint->runs, 'ansible-lint does not run in the request');
        $this->assertQueuedProjectLint($project);
        $stored = Project::findOne($project->id);
        $this->assertNotNull($stored);
        $this->assertSame((int)$stored->lint_queued_at, $this->dataOf($answer)['queued_at']);
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_LINTED, 'object_id' => $project->id]);
        $this->assertNotNull($audit, 'the request is audited as on the page');
        $this->assertSame('api', json_decode((string)$audit->metadata, true)['source'] ?? null);
    }

    /**
     * One lint run of $template is queued: one LintTemplateJob, whose
     * requestedAt is the template's stored mark.
     */
    private function assertQueuedTemplateLint(JobTemplate $template): void
    {
        $this->assertCount(1, $this->queue->jobs, 'one lint run is queued');
        $job = $this->queue->jobs[0];
        $this->assertInstanceOf(LintTemplateJob::class, $job);
        $stored = $this->reload($template);
        $this->assertNotNull($stored->lint_queued_at, 'the template is marked as queued');
        $this->assertSame((int)$stored->id, $job->templateId);
        $this->assertSame((int)$stored->lint_queued_at, $job->requestedAt);
    }

    /**
     * One lint run of $project is queued: one LintProjectJob, whose
     * requestedAt is the project's stored mark.
     */
    private function assertQueuedProjectLint(Project $project): void
    {
        $this->assertCount(1, $this->queue->jobs, 'one lint run is queued');
        $job = $this->queue->jobs[0];
        $this->assertInstanceOf(LintProjectJob::class, $job);
        $stored = Project::findOne($project->id);
        $this->assertNotNull($stored);
        $this->assertNotNull($stored->lint_queued_at, 'the project is marked as queued');
        $this->assertSame((int)$stored->id, $job->projectId);
        $this->assertSame((int)$stored->lint_queued_at, $job->requestedAt);
    }

    /**
     * The fields of a valid job template of a new project of $userId.
     *
     * @return array<string, mixed>
     */
    private function templateFields(int $userId, string $name): array
    {
        return [
            'name' => $name,
            'project_id' => (int)$this->createProject($userId)->id,
            'inventory_id' => (int)$this->createInventory($userId)->id,
            'runner_group_id' => (int)$this->createRunnerGroup($userId)->id,
            'playbook' => 'site.yml',
            'verbosity' => 0,
            'forks' => 5,
            'timeout_minutes' => 60,
            'become' => 0,
            'become_method' => 'sudo',
            'become_user' => 'root',
        ];
    }

    private function template(int $userId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    private function templateNamed(string $name): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(JobTemplate::class, $template, "template {$name} was saved");

        return $template;
    }

    private function reload(JobTemplate $template): JobTemplate
    {
        $stored = JobTemplate::findOne($template->id);
        $this->assertInstanceOf(JobTemplate::class, $stored);

        return $stored;
    }

    /**
     * @param array<mixed> $answer
     * @return array<string, mixed>
     */
    private function dataOf(array $answer): array
    {
        $this->assertArrayHasKey('data', $answer, (string)json_encode($answer));
        $this->assertIsArray($answer['data']);

        return $answer['data'];
    }

    private function createSuperadmin(string $suffix): User
    {
        $user = $this->createUser($suffix);
        $user->is_superadmin = true;
        $this->assertTrue($user->save(false));

        return $user;
    }

    private function authenticateApiAdmin(): User
    {
        $user = $this->createUser('lint-queue-api');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $admin = $auth->getRole('admin');
        $this->assertNotNull($admin);
        $auth->assign($admin, (string)$user->id);
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'lint-queue');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    private function webTemplateController(): JobTemplateController
    {
        return new class ('job-template', \Yii::$app) extends JobTemplateController {
            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }

    private function webProjectController(): ProjectController
    {
        return new class ('project', \Yii::$app) extends ProjectController {
            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }
}
