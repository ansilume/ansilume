<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ProjectController;
use app\models\Project;
use app\models\ProjectSyncLog;
use app\services\ProjectService;
use app\tests\integration\RestoresComponents;
use yii\base\Module;
use yii\helpers\Json;

/**
 * The sync output of a project's page polls /project/sync-status: once when
 * the page loads, then every two seconds while the project syncs
 * (views/project/view.php). tests/e2e/tests/projects/sync-poll.spec.ts
 * follows the script in a browser.
 */
class ProjectSyncPollScriptTest extends WebControllerTestCase
{
    use RendersRealViews;
    use RestoresComponents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swapComponent('projectService', new class () extends ProjectService {
            public function localPath(Project $project): string
            {
                return '/tmp/nonexistent-sync-poll-script-project';
            }
        });
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * regression: the poll asked for anything (fetch's Accept: * / *), so a
     * signed-out poll was sent to the login page, which kept the poll as the
     * address to return to after the next sign-in; and it read every answer
     * as JSON, so a refused poll (401, 403: signed out or no access any more;
     * 404: the project is gone) was sent again every two seconds for as long
     * as the page stayed open. It asks for JSON now, and a refusal stops it
     * with the note ProjectController::SYNC_LIVE_STOPPED, written as text.
     */
    public function testRegressionTheSyncPollAskedForAnythingAndNeverStopped(): void
    {
        $script = $this->pollScript($this->renderProjectPage(Project::STATUS_SYNCING));

        $this->assertStringContainsString(
            "fetch(statusUrl + '&since=' + lastSeq, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })",
            $script
        );
        $this->assertStringContainsString('var stopStatuses = [401, 403, 404];', $script);
        $this->assertStringContainsString('if (stopStatuses.indexOf(r.status) !== -1) {', $script);
        $this->assertStringContainsString('var stoppedNote = ' . Json::htmlEncode(ProjectController::SYNC_LIVE_STOPPED) . ';', $script);
        $this->assertStringContainsString('note.textContent = stoppedNote;', $script);
        $this->assertStringContainsString('if (timer) { clearInterval(timer); timer = null; }', $script);
        $this->assertStringContainsString('timer = setInterval(poll, pollIntervalMs);', $script);
    }

    /**
     * The note is the server's text: plain words, nothing the page would
     * read as markup.
     */
    public function testTheNoteIsPlainText(): void
    {
        $this->assertSame('Live updates stopped. Reload the page to keep following the sync.', ProjectController::SYNC_LIVE_STOPPED);
        $this->assertSame(strip_tags(ProjectController::SYNC_LIVE_STOPPED), ProjectController::SYNC_LIVE_STOPPED);
    }

    /**
     * A project that does not sync polls once, for the worker indicator,
     * with the same script; one without sync output renders no poll.
     */
    public function testAProjectThatDoesNotSyncPollsOnceAndOneWithoutOutputNotAtAll(): void
    {
        $failed = $this->pollScript($this->renderProjectPage(Project::STATUS_ERROR));
        $this->assertStringContainsString('var initialStatus = ' . Json::htmlEncode(Project::STATUS_ERROR) . ';', $failed);
        $this->assertStringContainsString("headers: { 'Accept': 'application/json' }", $failed);

        $this->assertStringNotContainsString('sync-status', $this->renderProjectPage(Project::STATUS_NEW, false));
    }

    private function renderProjectPage(string $status, bool $withOutput = true): string
    {
        $user = $this->createUser('sync-poll-script');
        $user->is_superadmin = true;
        $user->save(false);
        $this->loginAs($user);
        $project = $this->createProject((int)$user->id);
        $project->status = $status;
        $project->last_sync_error = $status === Project::STATUS_ERROR ? 'git command failed (exit 128): fatal: no' : null;
        $project->save(false);
        if ($withOutput) {
            $row = new ProjectSyncLog();
            $row->project_id = (int)$project->id;
            $row->stream = ProjectSyncLog::STREAM_SYSTEM;
            $row->content = "Sync queued.\n";
            $row->sequence = 1;
            $row->created_at = time();
            $row->save(false);
        }
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $ctrl = new class ('project', $app) extends ProjectController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };

        return $this->withRealViews($ctrl, '/project/view?id=' . $project->id, static fn (): string => $ctrl->actionView((int)$project->id));
    }

    /**
     * The script that polls the sync output: from its <script> tag to the
     * first "</script", which is where the browser ends it.
     */
    private function pollScript(string $html): string
    {
        $tag = strpos($html, "<script>\n(function () {\n    var statusUrl");
        $this->assertNotFalse($tag, 'the poll script is missing');
        $start = $tag + strlen('<script>');
        $end = stripos($html, '</script', $start);
        $this->assertNotFalse($end, 'the poll script does not end');

        return substr($html, $start, $end - $start);
    }
}
