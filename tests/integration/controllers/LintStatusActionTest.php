<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\JobTemplatesController;
use app\controllers\api\v1\ProjectsController;
use app\controllers\JobTemplateController;
use app\controllers\ProjectController;
use app\models\ApiToken;
use app\models\JobTemplate;
use app\models\Project;
use app\models\TeamProject;
use app\models\User;
use app\services\LintService;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The poll of the lint card, GET job-template/lint-status?id=N and
 * project/lint-status?id=N (LintCardTrait): {"queued": bool, "lint_at":
 * int|null} as JSON, to whoever may view the template or the project, with
 * the page's 404 and 403. And lint_queued_at is set in code only: a form or
 * a body that sends it changes nothing.
 */
class LintStatusActionTest extends WebControllerTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // No lint run is queued by the saves below: only mass assignment could set the mark.
        $this->swapComponent('lintService', new class () extends LintService {
            public function queueForTemplate(JobTemplate $template): bool
            {
                return true;
            }

            public function queueForProject(Project $project): bool
            {
                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    public function testTheTemplatesPollSaysWhetherARunIsQueuedAsJson(): void
    {
        $scope = $this->teamScope('viewer');
        $this->loginAs($scope['member']);
        $template = $scope['own'];
        JobTemplate::updateAll(['lint_queued_at' => 1700000005, 'lint_at' => 1700000000], ['id' => $template->id]);

        $queued = $this->templateController()->runAction('lint-status', ['id' => $template->id]);

        $this->assertSame(['queued' => true, 'lint_at' => 1700000000], $queued);
        $this->assertSame(Response::FORMAT_JSON, \Yii::$app->response->format);

        JobTemplate::updateAll(['lint_queued_at' => null, 'lint_at' => 1700000009], ['id' => $template->id]);
        $this->assertSame(['queued' => false, 'lint_at' => 1700000009], $this->templateController()->runAction('lint-status', ['id' => $template->id]));
    }

    public function testThePollOfATemplateWithoutAResultHasNoTime(): void
    {
        $scope = $this->teamScope('viewer');
        $this->loginAs($scope['member']);

        $this->assertSame(['queued' => false, 'lint_at' => null], $this->templateController()->actionLintStatus((int)$scope['viewed']->id));
    }

    /**
     * A user who may not view the template's project gets the page's 403;
     * the page's 404 for a template that does not exist or was deleted.
     */
    public function testTheTemplatesPollIsRefusedAsThePage(): void
    {
        $scope = $this->teamScope('viewer');
        $this->loginAs($scope['member']);

        $this->assertRefused(ForbiddenHttpException::class, fn () => $this->templateController()->actionLintStatus((int)$scope['foreign']->id));
        $this->assertRefused(ForbiddenHttpException::class, fn () => $this->templateController()->actionView((int)$scope['foreign']->id));
        $this->assertRefused(NotFoundHttpException::class, fn () => $this->templateController()->actionLintStatus(999999999));
        $deleted = $scope['own'];
        $this->assertTrue($deleted->softDelete());
        $this->assertRefused(NotFoundHttpException::class, fn () => $this->templateController()->actionLintStatus((int)$deleted->id));
    }

    /**
     * The access rules: job-template.view and project.view, as the pages;
     * a user without them is refused by the access filter.
     */
    public function testThePollsNeedTheViewPermissionsOfThePages(): void
    {
        $scope = $this->teamScope('viewer');
        $nobody = $this->createUser('lint-status-nobody');
        $this->loginAs($nobody);

        $this->assertRefused(ForbiddenHttpException::class, fn () => $this->templateController()->runAction('lint-status', ['id' => $scope['open']->id]));
        $this->assertRefused(ForbiddenHttpException::class, fn () => $this->projectController()->runAction('lint-status', ['id' => $scope['open']->project_id]));
    }

    public function testTheProjectsPollSaysWhetherARunIsQueuedAsJson(): void
    {
        $scope = $this->teamScope('viewer');
        $this->loginAs($scope['member']);
        $projectId = (int)$scope['own']->project_id;
        Project::updateAll(['lint_queued_at' => 1700000005, 'lint_at' => null], ['id' => $projectId]);

        $this->assertSame(['queued' => true, 'lint_at' => null], $this->projectController()->runAction('lint-status', ['id' => $projectId]));
        $this->assertSame(Response::FORMAT_JSON, \Yii::$app->response->format);

        Project::updateAll(['lint_queued_at' => null, 'lint_at' => 1700000009], ['id' => $projectId]);
        $this->assertSame(['queued' => false, 'lint_at' => 1700000009], $this->projectController()->runAction('lint-status', ['id' => $projectId]));
    }

    public function testTheProjectsPollIsRefusedAsThePage(): void
    {
        $scope = $this->teamScope('viewer');
        $this->loginAs($scope['member']);
        $foreign = (int)$scope['foreign']->project_id;

        $this->assertRefused(ForbiddenHttpException::class, fn () => $this->projectController()->actionLintStatus($foreign));
        $this->assertRefused(ForbiddenHttpException::class, fn () => $this->projectController()->actionView($foreign));
        $this->assertRefused(NotFoundHttpException::class, fn () => $this->projectController()->actionLintStatus(999999999));
    }

    /**
     * A team viewer may view the project: the poll answers.
     */
    public function testAViewerOfTheProjectsTeamMayPoll(): void
    {
        $scope = $this->teamScope('viewer');
        $this->loginAs($scope['member']);

        $answer = $this->projectController()->actionLintStatus((int)$scope['viewed']->project_id);

        $this->assertSame(['queued' => false, 'lint_at' => null], $answer);
        $this->assertNotNull(TeamProject::findOne(['project_id' => $scope['viewed']->project_id, 'role' => TeamProject::ROLE_VIEWER]));
    }

    /**
     * lint_queued_at is set in code only: the forms and the API bodies that
     * send it change nothing, and the models have no rule that makes it
     * safe.
     */
    public function testAPostedLintMarkChangesNothing(): void
    {
        $this->assertFalse((new JobTemplate())->isAttributeSafe('lint_queued_at'));
        $this->assertFalse((new Project())->isAttributeSafe('lint_queued_at'));
        $user = $this->createUser('lint-mark-post');
        $user->is_superadmin = true;
        $this->assertTrue($user->save(false));
        $this->loginAs($user);
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$this->createRunnerGroup((int)$user->id)->id,
            (int)$user->id
        );
        $project = $this->createProject((int)$user->id);

        $this->setPost(['JobTemplate' => ['name' => 'lint-mark-renamed-' . uniqid('', true), 'lint_queued_at' => 1700000000]]);
        $this->assertInstanceOf(Response::class, $this->templateController()->actionUpdate((int)$template->id), 'the template was saved');
        $this->setPost(['Project' => ['name' => 'lint-mark-project-' . uniqid('', true), 'lint_queued_at' => 1700000000]]);
        $this->assertInstanceOf(Response::class, $this->projectController()->actionUpdate((int)$project->id), 'the project was saved');
        $this->authenticateApi($user);
        $this->setBody(['name' => 'lint-mark-api-' . uniqid('', true), 'lint_queued_at' => 1700000000, 'lint' => ['queued_at' => 1700000000]]);
        (new JobTemplatesController('api/v1/job-templates', $this->module()))->actionUpdate((int)$template->id);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        (new ProjectsController('api/v1/projects', $this->module()))->actionUpdate((int)$project->id);
        $this->assertSame(200, \Yii::$app->response->statusCode);

        $this->assertNull(JobTemplate::findOne($template->id)?->lint_queued_at);
        $this->assertNull(Project::findOne($project->id)?->lint_queued_at);
        $loaded = new JobTemplate();
        $loaded->load(['JobTemplate' => ['lint_queued_at' => 1700000000]]);
        $this->assertNull($loaded->lint_queued_at);
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private function assertRefused(string $class, callable $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
            return;
        }
        $this->fail("refused with {$class}");
    }

    private function module(): Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return $app;
    }

    private function authenticateApi(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'lint-mark');
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
        return new class ('job-template', $this->module()) extends JobTemplateController {
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
        return new class ('project', $this->module()) extends ProjectController {
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
