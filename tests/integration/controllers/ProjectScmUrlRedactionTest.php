<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\ProjectsController;
use app\controllers\ProjectController;
use app\models\ApiToken;
use app\models\Project;
use app\models\ProjectSyncLog;
use app\models\TeamProject;
use app\models\User;
use app\services\ProjectService;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;

/**
 * A project's repository URL can carry credentials in its userinfo
 * (https://user:token@host/...), which git accepts. Who may view the project
 * sees the URL with the userinfo redacted (GitSyncDiagnostics::redactUrl):
 * on the project page, in its sync output and error, and in the API; in the
 * API, a caller who may update the project gets it in full, so that a
 * GET→PUT round trip keeps it (the edit form shows it to them as well).
 *
 * regression: the project page and GET /api/v1/projects(/{id}) showed the
 * URL with its credentials to everyone who may view the project, a viewer of
 * its team included; the page's sync output showed them in the clone
 * command too.
 */
class ProjectScmUrlRedactionTest extends WebControllerTestCase
{
    use RendersRealViews;
    use RestoresComponents;
    use TeamScopeFixtures;

    /** Test values, not secrets. */
    private const TOKEN = 'w5b-scm-token-4410';
    private const URL = 'https://deploy:' . self::TOKEN . '@git.example.com/org/playbooks.git';
    private const SHOWN = 'https://***@git.example.com/org/playbooks.git';

    protected function setUp(): void
    {
        parent::setUp();
        // An update of a git project queues a sync: here it pushes nothing.
        $this->swapComponent('projectService', new class extends ProjectService {
            public function queueSync(Project $project): void
            {
            }

            public function localPath(Project $project): string
            {
                return '/tmp/nonexistent-scm-url-redaction-project';
            }
        });
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    // -- The project page ---------------------------------------------------------

    /**
     * regression: the page showed the URL, the clone command of its sync
     * output and its sync error with the credentials.
     */
    public function testRegressionThePageShowedTheCredentialsOfTheURL(): void
    {
        $viewer = $this->createUserWithRole('scm-url-viewer', 'viewer');
        $project = $this->gitProject((int)$viewer->id);
        $this->loginAs($viewer);

        $html = $this->openPage($project);

        $this->assertStringNotContainsString(self::TOKEN, $html);
        $this->assertStringNotContainsString('deploy:', $html);
        $this->assertStringContainsString('<code>' . self::SHOWN . '</code>', $html);
        $this->assertStringContainsString('$ git clone --progress --branch main --depth 1 -- ' . self::SHOWN . ' /var/www/runtime/projects/', $html);
        $this->assertStringContainsString('git command timed out after 300 seconds: git clone -- ' . self::SHOWN, $html);
    }

    /**
     * The page shows the URL redacted to whoever opens it: the edit form is
     * where those who may update the project see it in full.
     */
    public function testThePageShowsTheURLRedactedToAnAdminToo(): void
    {
        $admin = $this->createUserWithRole('scm-url-admin', 'admin');
        $project = $this->gitProject((int)$admin->id);
        $this->loginAs($admin);

        $this->assertStringNotContainsString(self::TOKEN, $this->openPage($project));
    }

    /**
     * regression: the sync output that the page polls (/project/sync-status,
     * project.view) answered the rows and the error as stored, so every
     * project viewer read the credentials in the clone command and in the
     * timeout while a sync ran. Rows and errors stored before the sync
     * wrote them redacted are redacted when answered.
     */
    public function testRegressionThePolledSyncOutputAnsweredTheCredentials(): void
    {
        $viewer = $this->createUserWithRole('scm-url-poller', 'viewer');
        $project = $this->gitProject((int)$viewer->id);
        $this->loginAs($viewer);

        $answer = $this->pollSyncStatus($project);

        $this->assertStringNotContainsString(self::TOKEN, (string)json_encode($answer));
        $this->assertStringNotContainsString('deploy:', (string)json_encode($answer));
        $logs = $answer['logs'] ?? null;
        $this->assertIsArray($logs);
        $this->assertIsArray($logs[0] ?? null);
        $this->assertSame('$ git clone --progress --branch main --depth 1 -- ' . self::SHOWN . ' /var/www/runtime/projects/' . $project->id . "\n", $logs[0]['content'] ?? null);
        $this->assertSame('git command timed out after 300 seconds: git clone -- ' . self::SHOWN . ' /var/www/runtime/projects/' . $project->id, $answer['last_sync_error'] ?? null);
    }

    /**
     * A project without an error answers null for it, and rows without a
     * URL as they are.
     */
    public function testThePolledSyncOutputKeepsWhatHoldsNoCredentials(): void
    {
        $viewer = $this->createUserWithRole('scm-url-poller-plain', 'viewer');
        $project = $this->gitProject((int)$viewer->id, 'https://git.example.com/org/playbooks.git');
        $project->last_sync_error = null;
        $project->save(false);
        $this->loginAs($viewer);

        $answer = $this->pollSyncStatus($project);

        $this->assertArrayHasKey('last_sync_error', $answer);
        $this->assertNull($answer['last_sync_error']);
        $logs = $answer['logs'] ?? null;
        $this->assertIsArray($logs);
        $this->assertIsArray($logs[0] ?? null);
        $this->assertSame('$ git clone --progress --branch main --depth 1 -- https://git.example.com/org/playbooks.git /var/www/runtime/projects/' . $project->id . "\n", $logs[0]['content'] ?? null);
    }

    public function testAURLWithoutCredentialsIsShownAsItIs(): void
    {
        $viewer = $this->createUserWithRole('scm-url-plain', 'viewer');
        $project = $this->gitProject((int)$viewer->id, 'https://git.example.com/org/playbooks.git');
        $this->loginAs($viewer);

        $this->assertStringContainsString('<code>https://git.example.com/org/playbooks.git</code>', $this->openPage($project));
    }

    // -- The API ------------------------------------------------------------------

    /**
     * regression: the detail and the list answered the URL with its
     * credentials to every caller who may view the project.
     */
    public function testRegressionAViewerReadTheCredentialsInTheApi(): void
    {
        $viewer = $this->createUserWithRole('scm-url-api-viewer', 'viewer');
        $project = $this->gitProject((int)$viewer->id);
        $this->authenticate($viewer);

        $detail = $this->callApi('view', ['id' => $project->id]);
        $list = $this->callApi('index');

        $this->assertStringNotContainsString(self::TOKEN, (string)json_encode($detail) . (string)json_encode($list));
        $this->assertSame(self::SHOWN, $this->dataOf($detail)['scm_url']);
        $this->assertSame(self::SHOWN, $this->listedUrl($list, (int)$project->id));
    }

    /**
     * An operator whose team only views the project may not update it: the
     * URL is redacted for them, although they hold project.update.
     */
    public function testATeamViewerWhoHoldsProjectUpdateGetsTheURLRedacted(): void
    {
        $admin = $this->createUserWithRole('scm-url-team-admin', 'admin');
        $member = $this->createUserWithRole('scm-url-team-viewer', 'operator');
        $project = $this->gitProject((int)$admin->id);
        $team = $this->createTeam((int)$admin->id);
        $this->addTeamMember((int)$team->id, (int)$member->id);
        $this->createTeamProject((int)$team->id, (int)$project->id, TeamProject::ROLE_VIEWER);
        $this->authenticate($member);

        $this->assertSame(self::SHOWN, $this->dataOf($this->callApi('view', ['id' => $project->id]))['scm_url']);
    }

    /**
     * A caller who may update the project gets the URL in full, in the
     * detail and in the list: a GET→PUT round trip keeps it.
     */
    public function testACallerWhoMayUpdateTheProjectGetsTheFullURL(): void
    {
        $operator = $this->createUserWithRole('scm-url-operator', 'operator');
        $project = $this->gitProject((int)$operator->id);
        $this->authenticate($operator);

        $detail = $this->dataOf($this->callApi('view', ['id' => $project->id]));
        $this->assertSame(self::URL, $detail['scm_url']);
        $this->assertSame(self::URL, $this->listedUrl($this->callApi('index'), (int)$project->id));

        $this->setBody(['name' => $detail['name'] . '-renamed', 'scm_url' => $detail['scm_url']]);
        $answer = $this->callApi('update', ['id' => $project->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame(self::URL, $this->dataOf($answer)['scm_url']);
        $stored = Project::findOne($project->id);
        $this->assertInstanceOf(Project::class, $stored);
        $this->assertSame(self::URL, $stored->scm_url);
    }

    public function testAProjectWithoutAURLAnswersNull(): void
    {
        $viewer = $this->createUserWithRole('scm-url-none', 'viewer');
        $project = $this->createProject((int)$viewer->id);
        $this->authenticate($viewer);

        $this->assertNull($this->dataOf($this->callApi('view', ['id' => $project->id]))['scm_url']);
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * A git project with $url that failed its last sync: its sync output
     * holds the clone command, its error the timeout message, as
     * ProjectService and ProjectSyncProcessRunner write them.
     */
    private function gitProject(int $createdBy, string $url = self::URL): Project
    {
        $project = $this->createProject($createdBy);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = $url;
        $project->status = Project::STATUS_ERROR;
        $project->last_sync_error = 'git command timed out after 300 seconds: git clone -- ' . $url . ' /var/www/runtime/projects/' . $project->id;
        $project->save(false);
        $log = new ProjectSyncLog();
        $log->project_id = (int)$project->id;
        $log->stream = ProjectSyncLog::STREAM_SYSTEM;
        $log->content = '$ git clone --progress --branch main --depth 1 -- ' . $url . ' /var/www/runtime/projects/' . $project->id . "\n";
        $log->sequence = 1;
        $log->created_at = time();
        $log->save(false);

        return $project;
    }

    private function openPage(Project $project): string
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $ctrl = new class ('project', $app) extends ProjectController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
        $result = $this->withRealViews(
            $ctrl,
            '/project/view?id=' . $project->id,
            static fn (): mixed => $ctrl->runAction('view', ['id' => $project->id])
        );
        $this->assertIsString($result);

        return $result;
    }

    /**
     * What the page's poll gets: /project/sync-status, through the access
     * filter, as the signed-in user.
     *
     * @return array<string, mixed>
     */
    private function pollSyncStatus(Project $project): array
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $answer = (new ProjectController('project', $app))->runAction('sync-status', ['id' => $project->id]);
        $this->assertIsArray($answer);
        $named = [];
        foreach ($answer as $key => $value) {
            $named[(string)$key] = $value;
        }

        return $named;
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'scm-url-token');
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

    /**
     * @param array<mixed> $answer
     * @return array<string, mixed>
     */
    private function dataOf(array $answer): array
    {
        $data = $answer['data'] ?? null;
        $this->assertIsArray($data);
        $named = [];
        foreach ($data as $key => $value) {
            $named[(string)$key] = $value;
        }

        return $named;
    }

    /**
     * @param array<mixed> $answer
     */
    private function listedUrl(array $answer, int $projectId): mixed
    {
        $items = $answer['data'] ?? null;
        $this->assertIsArray($items);
        foreach ($items as $item) {
            $this->assertIsArray($item);
            if (($item['id'] ?? null) === $projectId) {
                return $item['scm_url'] ?? null;
            }
        }
        $this->fail('the project is on the first page of the list');
    }
}
