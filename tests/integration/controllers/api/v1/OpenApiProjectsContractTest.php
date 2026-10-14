<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ProjectsController;
use app\models\ApiToken;
use app\models\Project;
use app\models\User;
use app\services\ProjectService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\web\Request;

/**
 * What web/openapi.yaml says about the branch of a project is what
 * POST /api/v1/projects and PUT /api/v1/projects/{id} answer: their 422 is
 * ProjectRejected, whose example is the refusal of a branch that starts with
 * "-" or contains whitespace (Project::BRANCH_FORMAT), also for a PUT of a
 * project saved with such a branch that sends no other branch; nothing is
 * saved and no sync is queued. ProjectInput.scm_branch quotes the refusal
 * (GitSyncDocsContractTest pins its text).
 *
 * Regression (wave 5e of 2.11.0): the spec said a branch that starts with
 * "-" is accepted, and named no refusal for it, while the API refuses it
 * now. Fails on the spec from before.
 *
 * Regression (wave 5f of 2.11.0): the spec said the branch of every project
 * is checked; the API checks that of git projects only, also for a PUT that
 * makes a manual project a git project. Fails on the spec from before.
 */
class OpenApiProjectsContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use OpenApiWorkflowAssertions;
    use TeamScopeFixtures;

    private const PROJECTS = '/api/v1/projects';

    private const PROJECT = '/api/v1/projects/{id}';

    /** A repository URL of the examples; no credentials. */
    private const URL = 'https://git.example.com/org/playbooks.git';

    /**
     * regression: POST and PUT documented the generic 422 only, and
     * ProjectInput.scm_branch said a branch that starts with "-" is
     * accepted. The 422 of both is ProjectRejected, its example is the
     * answer of the API, and nothing is saved.
     */
    public function testRegressionTheBranchRefusalIsDocumentedAsTheApiAnswersIt(): void
    {
        foreach ([[self::PROJECTS, 'post'], [self::PROJECT, 'put']] as [$path, $method]) {
            $this->assertSame('#/components/responses/ProjectRejected', $this->specAt(['paths', $path, $method, 'responses', '422', '$ref']), "{$method} {$path}");
            $this->assertSame([Project::BRANCH_FORMAT], $this->exampleMessages($path, $method, '422'), "{$method} {$path}");
        }
        $this->assertSame(
            'Invalid input, such as a missing name, an unknown `scm_type`, or, for git projects, a `scm_branch` that starts with `-` or contains whitespace, also the branch a project was saved with before 2.11.0 when a `PUT` sends no other one, and that of a manual project that a `PUT` makes a git project. The answer names the first error. Nothing was saved and no sync was queued.',
            $this->specText(['components', 'responses', 'ProjectRejected', 'description'])
        );
        $branch = $this->specText(['components', 'schemas', 'ProjectInput', 'properties', 'scm_branch', 'description']);
        $this->assertStringContainsString('For git projects (`scm_type` `git` once the request is applied), a branch that starts with `-` or contains whitespace is refused: `POST` and `PUT` answer 422 `' . Project::BRANCH_FORMAT . '` and save nothing.', $branch);
        $this->assertStringNotContainsString('is accepted', $branch);

        // A saved git project would queue a sync: here it is only counted.
        $syncs = new class () extends ProjectService {
            public int $queued = 0;

            public function queueSync(Project $project): void
            {
                $this->queued++;
            }
        };
        $components = \Yii::$app->getComponents(true);
        $original = $components['projectService'] ?? null;
        \Yii::$app->set('projectService', $syncs);
        try {
            $this->assertTheApiAnswers(['error' => ['message' => Project::BRANCH_FORMAT]]);
        } finally {
            \Yii::$app->set('projectService', $original);
        }
        $this->assertSame(0, $syncs->queued, 'No sync is queued.');
    }

    /**
     * regression (wave 5f): the spec said the branch of every project is
     * checked. A manual project's is not: POST and a PUT that keeps the
     * project manual save such a branch and queue no sync, while a PUT that
     * makes it a git project answers ProjectRejected's example and saves
     * nothing, as ProjectInput.scm_branch and ProjectRejected say.
     */
    public function testRegressionOnlyTheBranchOfAGitProjectIsCheckedAsTheSpecSays(): void
    {
        $branch = $this->specText(['components', 'schemas', 'ProjectInput', 'properties', 'scm_branch', 'description']);
        $this->assertStringContainsString(
            'The branch of a manual project is not checked, as no sync reads it; a `PUT` that makes a manual project with such a branch a git project answers that 422.',
            $branch
        );
        $this->assertStringContainsString('and that of a manual project that a `PUT` makes a git project.', $this->specText(['components', 'responses', 'ProjectRejected', 'description']));

        $syncs = new class () extends ProjectService {
            public int $queued = 0;

            public function queueSync(Project $project): void
            {
                $this->queued++;
            }
        };
        $components = \Yii::$app->getComponents(true);
        $original = $components['projectService'] ?? null;
        \Yii::$app->set('projectService', $syncs);
        try {
            $operator = $this->createUserWithRole('spec-manual-operator', 'operator');
            $this->authenticate($operator);
            $name = 'spec-manual-' . uniqid('', true);
            $this->setBody(['name' => $name, 'scm_type' => Project::SCM_TYPE_MANUAL, 'scm_branch' => '-x']);
            $created = $this->callApi('create');
            $this->assertSame(201, \Yii::$app->response->statusCode, 'POST of a manual project with such a branch');
            $data = $created['data'] ?? null;
            $this->assertIsArray($data);
            $this->assertSame('-x', $data['scm_branch'] ?? null);
            $project = Project::find()->where(['name' => $name])->one();
            $this->assertInstanceOf(Project::class, $project);

            $this->setBody(['description' => 'kept manual']);
            $this->callApi('update', ['id' => $project->id]);
            $this->assertSame(200, \Yii::$app->response->statusCode, 'PUT that keeps it manual');

            $this->setBody(['scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::URL]);
            $this->assertSame(['error' => ['message' => Project::BRANCH_FORMAT]], $this->callApi('update', ['id' => $project->id]), 'PUT that makes it a git project');
            $this->assertSame(422, \Yii::$app->response->statusCode);
            $this->assertSame([Project::BRANCH_FORMAT], $this->exampleMessages(self::PROJECT, 'put', '422'), 'The example of ProjectRejected.');
        } finally {
            \Yii::$app->set('projectService', $original);
        }
        $stored = Project::findOne($project->id);
        $this->assertNotNull($stored);
        $this->assertSame(Project::SCM_TYPE_MANUAL, $stored->scm_type, 'Nothing is saved.');
        $this->assertSame('kept manual', $stored->description);
        $this->assertSame(0, $syncs->queued, 'No sync is queued.');
    }

    /**
     * POST with a branch that starts with "-", and PUT of a project saved
     * with such a branch that sends no other one, answer $example with 422,
     * and nothing is saved.
     *
     * @param array{error: array{message: string}} $example
     */
    private function assertTheApiAnswers(array $example): void
    {
        $operator = $this->createUserWithRole('spec-branch-operator', 'operator');
        $this->authenticate($operator);
        $name = 'spec-branch-' . uniqid('', true);
        $this->setBody(['name' => $name, 'scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::URL, 'scm_branch' => '-x']);
        $this->assertSame($example, $this->callApi('create'), 'POST with a branch that starts with "-"');
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertFalse(Project::find()->where(['name' => $name])->exists(), 'Nothing is saved.');

        $project = $this->createProject((int)$operator->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = self::URL;
        $project->scm_branch = '-x';
        $project->save(false);
        $this->setBody(['name' => $name]);
        $this->assertSame($example, $this->callApi('update', ['id' => $project->id]), 'PUT of a project saved with such a branch, without another one');
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $stored = Project::findOne($project->id);
        $this->assertNotNull($stored);
        $this->assertSame('-x', $stored->scm_branch, 'Nothing is saved.');
        $this->assertNotSame($name, $stored->name);
    }

    /**
     * Signs $user in with an API token of their own, for the filters of the
     * API controller.
     */
    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'spec');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams($body);
    }

    /**
     * The action of the API controller, through its filters, as the caller
     * of the bearer token.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    private function callApi(string $action, array $params = []): array
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        \Yii::$app->response->statusCode = 200;
        $result = (new ProjectsController('api/v1/projects', $app))->runAction($action, $params);
        $this->assertIsArray($result);

        return $result;
    }
}
