<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\ProjectsController;
use app\controllers\ProjectController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Project;
use app\models\User;
use app\services\ProjectDeletionService;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * A job template created in a project while the project is being deleted
 * (after the count of its active templates, before the purge) refuses the
 * delete, in the web UI and through the API, as a template counted in time
 * does: a danger flash on the project's page, or 422, both with the refusal
 * (ProjectDeletionService::REFUSED). The project and the template stay.
 *
 * Regression: the project's delete failed on the foreign key, and the
 * request ended in HTTP 500 (yii\db\IntegrityException).
 */
class ProjectDeletionRaceTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** @var array<string, mixed> component definitions to restore */
    private array $swapped = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The count before anything is done ran before the template below
        // was created: it finds none.
        $this->swap('projectDeletionService', new class () extends ProjectDeletionService {
            public function blockingTemplateCount(Project $project): int
            {
                return 0;
            }
        });
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
     * Regression: the web project delete answered 500 when a job template
     * was created while it ran.
     */
    public function testRegressionTheWebDeleteAnsweredATemplateCreatedMeanwhileWith500(): void
    {
        $user = $this->createUser('pdr-web');
        $user->is_superadmin = true;
        $user->save(false);
        $this->loginAs($user);
        ['project' => $project, 'template' => $template] = $this->projectWithTemplateCreatedMeanwhile($user);
        $controller = $this->webController();

        $controller->actionDelete((int)$project->id);

        $this->assertSame(['view', 'id' => (int)$project->id], $controller->redirectedTo);
        $this->assertSame(['danger' => $this->refusal($project)], \Yii::$app->session->getAllFlashes());
        $this->assertStays($project, $template);
    }

    /**
     * Regression: the API project delete answered 500 when a job template
     * was created while it ran; it answers 422 with the refusal now.
     */
    public function testRegressionTheApiDeleteAnsweredATemplateCreatedMeanwhileWith500(): void
    {
        $user = $this->createUserWithRole('pdr-api', 'admin');
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'project-delete-race');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $component */
        $component = \Yii::$app->user;
        $component->loginByAccessToken($raw);
        ['project' => $project, 'template' => $template] = $this->projectWithTemplateCreatedMeanwhile($user);

        $result = (new ProjectsController('api/v1/projects', $this->app()))->actionDelete((int)$project->id);

        $this->assertSame(['error' => ['message' => $this->refusal($project)]], $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertStays($project, $template);
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * A project with a deleted job template, which lets the delete start,
     * and an active one created after the count.
     *
     * @return array{project: Project, template: JobTemplate}
     */
    private function projectWithTemplateCreatedMeanwhile(User $owner): array
    {
        $ownerId = (int)$owner->id;
        $project = $this->createProject($ownerId);
        $groupId = (int)$this->createRunnerGroup($ownerId)->id;
        $inventoryId = (int)$this->createInventory($ownerId)->id;
        $this->assertTrue($this->createJobTemplate((int)$project->id, $inventoryId, $groupId, $ownerId)->softDelete());
        $template = $this->createJobTemplate((int)$project->id, $inventoryId, $groupId, $ownerId);

        return ['project' => $project, 'template' => $template];
    }

    private function refusal(Project $project): string
    {
        $text = "Cannot delete \"{$project->name}\": 1 job template(s) still reference this project. Remove or reassign them first.";
        $this->assertSame(sprintf(ProjectDeletionService::REFUSED, $project->name, 1), $text);

        return $text;
    }

    /**
     * The project and its template are still there, and no project.deleted
     * entry was written.
     */
    private function assertStays(Project $project, JobTemplate $template): void
    {
        $this->assertNotNull(Project::findOne($project->id), 'the project stays');
        $this->assertSame((int)$project->id, (int)JobTemplate::findOne($template->id)?->project_id, 'and so does its template');
        $this->assertFalse(
            AuditLog::find()->where(['action' => AuditLog::ACTION_PROJECT_DELETED, 'object_id' => $project->id])->exists(),
            'nothing deleted, nothing audited'
        );
    }

    /**
     * @return ProjectController&object{redirectedTo: mixed}
     */
    private function webController(): ProjectController
    {
        return new class ('project', $this->app()) extends ProjectController {
            public mixed $redirectedTo = null;

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $this->redirectedTo = $url;
                return new Response();
            }
        };
    }

    private function swap(string $id, object $component): void
    {
        $this->swapped[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        \Yii::$app->set($id, $component);
    }

    private function app(): \yii\base\Module
    {
        /** @var \yii\base\Module $app the test application */
        $app = \Yii::$app;

        return $app;
    }
}
