<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\JobTemplatesController;
use app\controllers\api\v1\ProjectsController;
use app\controllers\JobTemplateController;
use app\controllers\ProjectController;
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
 * A lint run that cannot be queued, as with a Redis that cannot be reached,
 * never fails the save before it: the template is saved, the page adds the
 * warning LintService::NOT_QUEUED to its flash, the API answers the saved
 * template with lint.queued_at null. Run Lint warns instead of saying it was
 * queued, POST /api/v1/projects/{id}/lint answers 503, and both audit the
 * request.
 */
class LintNotQueuedTest extends WebControllerTestCase
{
    use RestoresComponents;

    protected function setUp(): void
    {
        parent::setUp();
        $queue = new RecordingQueue();
        $queue->failWith = new \yii\db\Exception('Redis error: connection refused');
        $this->swapComponent('queue', $queue);
        $this->swapComponent('lintService', new LintService());
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    public function testASaveOnTheWebStaysAndWarnsThatLintWasNotQueued(): void
    {
        $user = $this->superadmin();
        $template = $this->template((int)$user->id);
        $name = 'lint-not-queued-' . uniqid('', true);
        $this->setPost(['JobTemplate' => ['name' => $name]]);

        $result = $this->templateController()->actionUpdate((int)$template->id);

        $this->assertInstanceOf(Response::class, $result);
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);
        $this->assertSame($name, $stored->name, 'the save stays');
        $this->assertNull($stored->lint_queued_at);
        $this->assertSame("Template \"{$name}\" updated.", \Yii::$app->session->getFlash('success'));
        $this->assertSame(LintService::NOT_QUEUED, \Yii::$app->session->getFlash('warning'));
    }

    public function testACreateAndACloneOnTheWebWarnToo(): void
    {
        $user = $this->superadmin();
        $source = $this->template((int)$user->id);

        $this->templateController()->actionClone((int)$source->id);
        $this->assertSame(LintService::NOT_QUEUED, \Yii::$app->session->getFlash('warning'));
        $this->assertNotNull(JobTemplate::findOne(['name' => $source->name . ' (copy)']), 'the clone stays');

        $name = 'lint-not-queued-created-' . uniqid('', true);
        $this->setPost(['JobTemplate' => [
            'name' => $name,
            'project_id' => (int)$source->project_id,
            'inventory_id' => (int)$source->inventory_id,
            'runner_group_id' => (int)$source->runner_group_id,
            'playbook' => 'site.yml',
            'verbosity' => 0,
            'forks' => 5,
            'timeout_minutes' => 60,
        ]]);
        $this->templateController()->actionCreate();
        $this->assertNotNull(JobTemplate::findOne(['name' => $name]), 'the template was created');
        $this->assertSame(LintService::NOT_QUEUED, \Yii::$app->session->getFlash('warning'));
    }

    public function testRunLintWarnsInsteadOfSayingItWasQueuedAndAuditsTheRequest(): void
    {
        $user = $this->superadmin();
        $project = $this->createProject((int)$user->id);

        $this->projectController()->actionLint((int)$project->id);

        $this->assertNull(\Yii::$app->session->getFlash('success'));
        $this->assertSame(LintService::NOT_QUEUED, \Yii::$app->session->getFlash('warning'));
        $this->assertNull(Project::findOne($project->id)?->lint_queued_at);
        $this->assertNotNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_LINTED, 'object_id' => $project->id]));
    }

    public function testAnApiSaveAnswersTheTemplateWithoutAQueuedRun(): void
    {
        $user = $this->superadmin();
        $template = $this->template((int)$user->id);
        $this->authenticateApi($user);
        $this->setBody(['name' => 'lint-not-queued-api-' . uniqid('', true)]);

        $answer = (new JobTemplatesController('api/v1/job-templates', \Yii::$app))->actionUpdate((int)$template->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertArrayHasKey('data', $answer);
        $this->assertNull($answer['data']['lint']['queued_at']);
        $this->assertNull(JobTemplate::findOne($template->id)?->lint_queued_at);
    }

    public function testTheApisLintAnswers503AndAuditsTheRequest(): void
    {
        $user = $this->superadmin();
        $project = $this->createProject((int)$user->id);
        $this->authenticateApi($user);

        $answer = (new ProjectsController('api/v1/projects', \Yii::$app))->actionLint((int)$project->id);

        $this->assertSame(503, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => LintService::NOT_QUEUED]], $answer);
        $this->assertNull(Project::findOne($project->id)?->lint_queued_at);
        $this->assertNotNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_LINTED, 'object_id' => $project->id]));
    }

    private function superadmin(): User
    {
        $user = $this->createUser('lint-not-queued');
        $user->is_superadmin = true;
        $this->assertTrue($user->save(false));
        $this->loginAs($user);

        return $user;
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

    private function authenticateApi(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'lint-not-queued');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
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

    private function templateController(): JobTemplateController
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

    private function projectController(): ProjectController
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
