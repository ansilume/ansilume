<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobTemplatesController;
use app\controllers\api\v1\ProjectsController;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Project;
use app\services\LintService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\RecordingQueue;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use yii\web\NotFoundHttpException;

/**
 * The lint state in the API: `lint` of every job template and project
 * ({status, exit_code, checked_at, queued_at}, LintState in web/openapi.yaml),
 * with the output for one record (LintStateDetail), and
 * POST /api/v1/projects/{id}/lint, which queues a lint run of the project as
 * Run Lint does: project.update and operator access, 202 with the lint
 * state, the audit entry project.linted.
 */
class LintStateApiTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use RestoresComponents;
    use TeamScopeFixtures;

    /** The output of the stored result of the tests. */
    private const OUTPUT = "\e[31mname[missing]\e[0m: All tasks should be named.";

    private RecordingQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new RecordingQueue();
        $this->swapComponent('queue', $this->queue);
        $this->swapComponent('lintService', new LintService());
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    public function testAJobTemplateHasItsLintStateInTheListAndWithItsOutputAlone(): void
    {
        $this->loginAs($this->userWithRole('lint-api-viewer', 'viewer'));
        $template = $this->jobTemplateOwnedBy($this->createUser('lint-api-owner'));
        JobTemplate::updateAll(['lint_output' => self::OUTPUT, 'lint_exit_code' => 2, 'lint_at' => 1700000000, 'lint_queued_at' => 1700000100], ['id' => $template->id]);
        $ctrl = new JobTemplatesController('api/v1/job-templates', $this->app());

        $listed = $this->listItem($ctrl->actionIndex(), (int)$template->id);
        $viewed = $this->data($ctrl->actionView((int)$template->id));

        $expected = ['status' => 'issues', 'exit_code' => 2, 'checked_at' => 1700000000, 'queued_at' => 1700000100];
        $this->assertSame($expected, $listed['lint'], 'a list leaves the output out');
        $this->assertSame($expected + ['output' => self::OUTPUT], $viewed['lint']);
        $this->assertMatchesProperties($this->properties('LintState'), $listed['lint'], 'Schema LintState');
        $this->assertMatchesProperties($this->properties('LintStateDetail'), $viewed['lint'], 'Schema LintStateDetail');
        $this->assertMatchesSchema('JobTemplate', $viewed, ['has_trigger_token', 'trigger_user_id']);
    }

    /**
     * A record without a stored result: status, exit code, time and output
     * null; not queued.
     */
    public function testAProjectWithoutAResultHasANullStateInTheListAndAlone(): void
    {
        $user = $this->userWithRole('lint-api-admin', 'admin');
        $this->loginAs($user);
        $project = $this->createProject((int)$user->id);
        $ctrl = new ProjectsController('api/v1/projects', $this->app());

        $listed = $this->listItem($ctrl->actionIndex(), (int)$project->id);
        $viewed = $this->data($ctrl->actionView((int)$project->id));

        $none = ['status' => null, 'exit_code' => null, 'checked_at' => null, 'queued_at' => null];
        $this->assertSame($none, $listed['lint']);
        $this->assertSame($none + ['output' => null], $viewed['lint']);
        $this->assertMatchesProperties($this->properties('LintState'), $listed['lint'], 'Schema LintState');
        $this->assertMatchesProperties($this->properties('LintStateDetail'), $viewed['lint'], 'Schema LintStateDetail');
        $this->assertMatchesSchema('Project', $viewed);
    }

    /**
     * A result whose ansible-lint did not run (-1) is not-run; a project
     * whose playbook loads vault-encrypted files is vault-skipped.
     */
    public function testTheStatusIsTheVerdictOfTheStoredResult(): void
    {
        $user = $this->userWithRole('lint-api-verdict', 'admin');
        $this->loginAs($user);
        $notRun = $this->createProject((int)$user->id);
        Project::updateAll(['lint_output' => sprintf(LintService::NOT_RUN, 'ansible-lint could not be started'), 'lint_exit_code' => -1, 'lint_at' => 1700000000], ['id' => $notRun->id]);
        $vault = $this->createProject((int)$user->id);
        Project::updateAll(['lint_output' => 'ERROR! Attempting to decrypt but no vault secrets found', 'lint_exit_code' => 2, 'lint_at' => 1700000000], ['id' => $vault->id]);
        $clean = $this->createProject((int)$user->id);
        Project::updateAll(['lint_output' => 'Passed', 'lint_exit_code' => 0, 'lint_at' => 1700000000], ['id' => $clean->id]);
        $ctrl = new ProjectsController('api/v1/projects', $this->app());

        $this->assertSame('not-run', $this->data($ctrl->actionView((int)$notRun->id))['lint']['status']);
        $this->assertSame('vault-skipped', $this->data($ctrl->actionView((int)$vault->id))['lint']['status']);
        $this->assertSame('clean', $this->data($ctrl->actionView((int)$clean->id))['lint']['status']);
        $this->assertSame(['not-run', 'clean', 'issues', 'vault-skipped'], $this->properties('LintState')['status']['enum'] ?? null);
    }

    public function testLintQueuesARunOfTheProjectAndAnswers202WithItsState(): void
    {
        $scope = $this->teamScope('operator');
        $this->loginAs($scope['member']);
        $projectId = (int)$scope['own']->project_id;
        Project::updateAll(['lint_output' => 'Passed', 'lint_exit_code' => 0, 'lint_at' => 1700000000], ['id' => $projectId]);

        $answer = (new ProjectsController('api/v1/projects', $this->app()))->actionLint($projectId);

        $this->assertSame(202, \Yii::$app->response->statusCode);
        $data = $this->data($answer);
        $stored = Project::findOne($projectId);
        $this->assertNotNull($stored);
        $this->assertNotNull($stored->lint_queued_at);
        $this->assertSame(
            ['status' => 'clean', 'exit_code' => 0, 'checked_at' => 1700000000, 'queued_at' => (int)$stored->lint_queued_at, 'output' => 'Passed'],
            $data,
            'the previous result, and when the new run was queued'
        );
        $this->assertMatchesProperties($this->properties('LintStateDetail'), $data, 'Schema LintStateDetail');
        $this->assertCount(1, $this->queue->jobs);
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_LINTED, 'object_id' => $projectId]);
        $this->assertNotNull($audit);
        $this->assertSame(['name' => $stored->name, 'source' => 'api'], json_decode((string)$audit->metadata, true));
        $this->assertSame((int)$scope['member']->id, (int)$audit->user_id);
    }

    /**
     * 403 without project.update, and without operator access to the
     * project (a team that only views it), as Run Lint on the page; nothing
     * is queued or audited. 404 for a project that does not exist.
     */
    public function testLintIsRefusedAsRunLintOnThePage(): void
    {
        $scope = $this->teamScope('operator');
        $ctrl = new ProjectsController('api/v1/projects', $this->app());

        $this->loginAs($this->userWithRole('lint-api-viewer-role', 'viewer'));
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $ctrl->actionLint((int)$scope['open']->project_id));
        $this->assertSame(403, \Yii::$app->response->statusCode);

        $this->loginAs($scope['member']);
        $viewed = (int)$scope['viewed']->project_id;
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $ctrl->actionLint($viewed));
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $ctrl->actionLint((int)$scope['foreign']->project_id));

        $this->assertSame([], $this->queue->jobs);
        $this->assertNull(Project::findOne($viewed)?->lint_queued_at);
        $this->assertNull(AuditLog::findOne(['action' => AuditLog::ACTION_PROJECT_LINTED, 'object_id' => $viewed]));

        $this->expectException(NotFoundHttpException::class);
        $ctrl->actionLint(999999999);
    }

    /**
     * The spec documents the endpoint as it answers, and the lint state of
     * both schemas, in both shapes.
     */
    public function testTheSpecDocumentsTheEndpointAndTheLintState(): void
    {
        $path = '/api/v1/projects/{id}/lint';
        $this->assertDocumentedData($path, 'post', '202', 'LintStateDetail');
        foreach (['403', '404', '503'] as $status) {
            $this->dig($this->spec(), ['paths', $path, 'post', 'responses', $status], "POST {$path} documents {$status}.");
        }
        $this->assertSame(
            LintService::NOT_QUEUED,
            $this->dig($this->spec(), ['paths', $path, 'post', 'responses', '503', 'content', 'application/json', 'example', 'error', 'message'], 'the 503 example')
        );
        $shapes = [['$ref' => '#/components/schemas/LintState'], ['$ref' => '#/components/schemas/LintStateDetail']];
        foreach (['JobTemplate', 'Project'] as $schema) {
            $this->assertSame($shapes, $this->properties($schema)['lint']['anyOf'] ?? null, "{$schema}.lint");
        }
        $this->assertSame(['status', 'exit_code', 'checked_at', 'queued_at'], $this->required('LintState'));
        $this->assertSame(['status', 'exit_code', 'checked_at', 'queued_at', 'output'], $this->required('LintStateDetail'));
    }
}
