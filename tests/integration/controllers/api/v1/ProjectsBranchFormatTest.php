<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ProjectsController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Project;
use app\models\User;
use app\services\ProjectService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;

/**
 * POST and PUT /api/v1/projects refuse a branch that starts with "-" or
 * holds whitespace for a git project: 422 with Project::BRANCH_FORMAT as the
 * message, nothing saved or audited, no sync queued. A manual project's
 * branch never reaches git and is not checked.
 *
 * regression: the API saved such a branch (201, 200) and queued a sync with
 * it; up to 2.10.1 git read a branch that starts with "-" as one of its
 * options.
 */
class ProjectsBranchFormatTest extends WebControllerTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;

    private const URL = 'https://git.example.com/org/playbooks.git';

    /** The answer of a refused branch. */
    private const REFUSED = ['error' => ['message' => 'The branch cannot start with "-" or contain whitespace.']];

    /** @var ProjectService&object{queued: int} */
    private ProjectService $syncs;

    protected function setUp(): void
    {
        parent::setUp();
        // A saved git project queues a sync: here it is only counted.
        $this->syncs = new class () extends ProjectService {
            public int $queued = 0;

            public function queueSync(Project $project): void
            {
                $this->queued++;
            }
        };
        $this->swapComponent('projectService', $this->syncs);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedBranchProvider(): array
    {
        return [
            'a dash first' => ['-x'],
            'an option of git' => ['--upload-pack=touch /tmp/pwned'],
            'a space first' => [' main'],
            'a space inside' => ['a b'],
            'a tab' => ["\tmain"],
            'a newline last' => ["main\n"],
        ];
    }

    /**
     * regression: the old model answered 201 and queued the sync.
     *
     * @dataProvider refusedBranchProvider
     */
    public function testRegressionPostSavedABranchThatStartsWithADashOrHoldsWhitespace(string $branch): void
    {
        $this->authenticate($this->createUserWithRole('branch-format-api-creator', 'operator'));
        $name = 'branch-format-api-' . uniqid('', true);
        $this->setBody(['name' => $name, 'scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::URL, 'scm_branch' => $branch]);

        $answer = $this->callApi('create');

        $this->assertSame(422, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame(self::REFUSED, $answer);
        $this->assertSame(['error' => ['message' => Project::BRANCH_FORMAT]], $answer);
        $this->assertNull(Project::findOne(['name' => $name]), 'nothing is saved');
        $this->assertSame(0, $this->syncs->queued, 'no sync is queued');
        $this->assertSame(0, (int)AuditLog::find()->where(['action' => AuditLog::ACTION_PROJECT_CREATED])->andWhere(['like', 'metadata', $name])->count());
    }

    /**
     * regression: the old model answered 200 and queued a sync with the
     * branch.
     *
     * @dataProvider refusedBranchProvider
     */
    public function testRegressionPutSavedABranchThatStartsWithADashOrHoldsWhitespace(string $branch): void
    {
        $operator = $this->createUserWithRole('branch-format-api-editor', 'operator');
        $project = $this->gitProject((int)$operator->id, 'main');
        $this->authenticate($operator);
        $this->setBody(['scm_branch' => $branch]);

        $answer = $this->callApi('update', ['id' => $project->id]);

        $this->assertSame(422, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame(self::REFUSED, $answer);
        $this->assertSame('main', $this->reload($project)->scm_branch, 'the stored branch is kept');
        $this->assertSame(0, $this->syncs->queued, 'no sync is queued');
        $this->assertNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_UPDATED, 'object_id' => $project->id]));
    }

    /**
     * A project saved with such a branch before 2.11.0 keeps it until it is
     * saved again: every PUT that leaves the branch as it is answers 422
     * with BRANCH_FORMAT, a PUT that gives it another branch saves.
     */
    public function testPutOfAProjectWhoseStoredBranchStartsWithADashSavesOnlyWithAnotherBranch(): void
    {
        $operator = $this->createUserWithRole('branch-format-api-legacy', 'operator');
        $project = $this->gitProject((int)$operator->id, '-x');
        $this->authenticate($operator);
        $renamed = $project->name . '-renamed';

        $this->setBody(['name' => $renamed]);
        $refused = $this->callApi('update', ['id' => $project->id]);

        $this->assertSame(422, \Yii::$app->response->statusCode, (string)json_encode($refused));
        $this->assertSame(self::REFUSED, $refused);
        $this->assertSame($project->name, $this->reload($project)->name, 'the new name is not saved either');
        $this->assertSame(0, $this->syncs->queued);

        $this->setBody(['name' => $renamed, 'scm_branch' => 'main']);
        $saved = $this->callApi('update', ['id' => $project->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($saved));
        $data = $saved['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame('main', $data['scm_branch'] ?? null);
        $this->assertSame($renamed, $data['name'] ?? null);
        $this->assertSame('main', $this->reload($project)->scm_branch);
        $this->assertSame(1, $this->syncs->queued, 'saving the git project queues its sync');
    }

    /**
     * A GET still answers such a stored branch as it is, so a caller sees
     * what to change.
     */
    public function testGetAnswersAStoredBranchThatStartsWithADash(): void
    {
        $operator = $this->createUserWithRole('branch-format-api-reader', 'operator');
        $project = $this->gitProject((int)$operator->id, '-x');
        $this->authenticate($operator);

        $answer = $this->callApi('view', ['id' => $project->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $data = $answer['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame('-x', $data['scm_branch'] ?? null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedBranchProvider(): array
    {
        return [
            'main' => ['main'],
            'a branch in a folder' => ['feature/x'],
            'a tag' => ['v1.0.0'],
            'a commit' => ['0123456789abcdef0123456789abcdef01234567'],
            'underscore and dash' => ['user/name_with-dash'],
        ];
    }

    /**
     * @dataProvider acceptedBranchProvider
     */
    public function testPostAndPutSaveABranchThatStartsWithNoDashAndHoldsNoWhitespace(string $branch): void
    {
        $operator = $this->createUserWithRole('branch-format-api-valid', 'operator');
        $this->authenticate($operator);
        $name = 'branch-format-api-valid-' . uniqid('', true);
        $this->setBody(['name' => $name, 'scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::URL, 'scm_branch' => $branch]);

        $created = $this->callApi('create');

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($created));
        $project = Project::findOne(['name' => $name]);
        $this->assertInstanceOf(Project::class, $project);
        $this->assertSame($branch, $project->scm_branch);

        $this->setBody(['scm_branch' => 'release-1.2']);
        $updated = $this->callApi('update', ['id' => $project->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($updated));
        $this->assertSame('release-1.2', $this->reload($project)->scm_branch);
        $this->assertSame(2, $this->syncs->queued);
    }

    /**
     * regression (wave 5f): the branch rule applied to manual projects too,
     * whose branch never reaches git: POST answered 422 for one.
     *
     * @dataProvider refusedBranchProvider
     */
    public function testRegressionPostOfAManualProjectWithSuchABranchSavesIt(string $branch): void
    {
        $this->authenticate($this->createUserWithRole('bf-api-manual-creator', 'operator'));
        $name = 'branch-format-api-manual-' . uniqid('', true);
        $this->setBody(['name' => $name, 'scm_type' => Project::SCM_TYPE_MANUAL, 'scm_branch' => $branch]);

        $answer = $this->callApi('create');

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $data = $answer['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame(Project::SCM_TYPE_MANUAL, $data['scm_type'] ?? null);
        $this->assertSame($branch, $data['scm_branch'] ?? null);
        $this->assertSame($branch, Project::findOne(['name' => $name])?->scm_branch);
        $this->assertSame(0, $this->syncs->queued, 'a manual project queues no sync');
    }

    /**
     * regression (wave 5f): PUT answered 422 for a manual project whose
     * stored branch is such a branch, whatever it changed, and for such a
     * branch sent for a manual project.
     *
     * @dataProvider refusedBranchProvider
     */
    public function testRegressionPutOfAManualProjectWithSuchABranchSavesIt(string $branch): void
    {
        $operator = $this->createUserWithRole('bf-api-manual-editor', 'operator');
        $stored = $this->manualProject((int)$operator->id, $branch);
        $sent = $this->manualProject((int)$operator->id, 'main');
        $this->authenticate($operator);
        $renamed = $stored->name . '-renamed';

        $this->setBody(['name' => $renamed]);
        $answer = $this->callApi('update', ['id' => $stored->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame($renamed, $this->reload($stored)->name);
        $this->assertSame($branch, $this->reload($stored)->scm_branch, 'the stored branch is kept');

        $this->setBody(['scm_branch' => $branch]);
        $answer = $this->callApi('update', ['id' => $sent->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame($branch, $this->reload($sent)->scm_branch);
        $this->assertSame(0, $this->syncs->queued, 'a manual project queues no sync');
    }

    /**
     * Every save that makes a project a git project checks its branch: a PUT
     * that switches such a manual project to git answers 422 BRANCH_FORMAT;
     * nothing is saved or audited and no sync is queued, until the branch is
     * changed too.
     */
    public function testPutThatSwitchesSuchAManualProjectToGitIsRefused(): void
    {
        $operator = $this->createUserWithRole('bf-api-manual-switcher', 'operator');
        $project = $this->manualProject((int)$operator->id, '-x');
        $this->authenticate($operator);

        $this->setBody(['scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::URL]);
        $refused = $this->callApi('update', ['id' => $project->id]);

        $this->assertSame(422, \Yii::$app->response->statusCode, (string)json_encode($refused));
        $this->assertSame(self::REFUSED, $refused);
        $this->assertSame(Project::SCM_TYPE_MANUAL, $this->reload($project)->scm_type, 'the project stays manual');
        $this->assertSame(0, $this->syncs->queued, 'no sync is queued');
        $this->assertNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_UPDATED, 'object_id' => $project->id]));

        $this->setBody(['scm_type' => Project::SCM_TYPE_GIT, 'scm_url' => self::URL, 'scm_branch' => 'main']);
        $saved = $this->callApi('update', ['id' => $project->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($saved));
        $this->assertSame(Project::SCM_TYPE_GIT, $this->reload($project)->scm_type);
        $this->assertSame(1, $this->syncs->queued, 'saving the git project queues its sync');
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'branch-format-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
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
        $result = (new ProjectsController('api/v1/projects', $app))->runAction($action, $params);
        $this->assertIsArray($result);

        return $result;
    }

    private function gitProject(int $createdBy, string $branch): Project
    {
        $project = $this->createProject($createdBy);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = self::URL;
        $project->scm_branch = $branch;
        $project->save(false);

        return $project;
    }

    /** A manual project stored with $branch, as the API saved one before 2.11.0. */
    private function manualProject(int $createdBy, string $branch): Project
    {
        $project = $this->createProject($createdBy);
        $project->scm_branch = $branch;
        $project->save(false);

        return $project;
    }

    private function reload(Project $project): Project
    {
        $stored = Project::findOne($project->id);
        $this->assertInstanceOf(Project::class, $stored);

        return $stored;
    }
}
