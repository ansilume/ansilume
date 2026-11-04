<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\LintState;
use app\controllers\JobTemplateController;
use app\controllers\ProjectController;
use app\models\JobTemplate;
use app\models\Project;
use app\models\User;
use app\services\ProjectService;
use app\tests\integration\RestoresComponents;
use yii\base\Module;
use yii\helpers\Html;
use yii\helpers\Json;

/**
 * The lint card of the job template's and the project's page
 * (views/project/_lint.php): while a run is queued, the badge "Lint queued"
 * with when it was requested, the stored result marked as the previous run,
 * the hint the sync output gives when no queue worker beats, and a poll of
 * lint-status that asks for JSON, stops on 401, 403 and 404, and reloads the
 * page once the run has stored its result. Without a queued run none of it,
 * whatever the workers do. tests/e2e/tests/job-templates/lint-queue.spec.ts
 * and projects/lint-queue.spec.ts follow it in a browser.
 */
class LintCardRenderTest extends WebControllerTestCase
{
    use RendersRealViews;
    use RestoresComponents;

    /** The output of the stored result, as ansible-lint writes it. */
    private const OUTPUT = 'Passed: 0 failure(s), 0 warning(s) on 1 files.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->swapComponent('projectService', new class () extends ProjectService {
            public function localPath(Project $project): string
            {
                return '/tmp/nonexistent-lint-card-project';
            }
        });
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    public function testAQueuedRunOfATemplateShowsTheBadgeThePreviousRunAndThePoll(): void
    {
        $template = $this->template(['lint_output' => self::OUTPUT, 'lint_exit_code' => 0, 'lint_at' => 1700000000, 'lint_queued_at' => 1700000100]);

        $html = $this->templatePage($template, true);

        $this->assertStringContainsString('data-testid="lint-queued">' . LintState::QUEUED . '</span>', $html);
        $this->assertStringContainsString(Html::encode(sprintf(LintState::QUEUED_SINCE, date('Y-m-d H:i:s', 1700000100))), $html);
        $this->assertStringContainsString(Html::encode(sprintf(LintState::PREVIOUS_RUN, date('Y-m-d H:i', 1700000000))), $html);
        $this->assertStringContainsString('data-testid="lint-badge">clean</span>', $html, 'the stored result stays below');
        $this->assertStringContainsString('id="template-lint-output"', $html);
        $this->assertStringNotContainsString('data-testid="lint-no-worker"', $html, 'a queue worker beats');
        $this->assertPollOf($html, '/job-template/lint-status?id=' . $template->id);
    }

    public function testAQueuedRunWithoutAQueueWorkerSaysHowToStartOne(): void
    {
        $template = $this->template(['lint_queued_at' => 1700000100]);

        $html = $this->templatePage($template, false);

        $this->assertStringContainsString(
            'data-testid="lint-no-worker">&#9888; ' . Html::encode(ProjectController::NO_QUEUE_WORKER) . ' <code>' . Html::encode(ProjectController::START_QUEUE_WORKER) . '</code></div>',
            $html
        );
        $this->assertSame('No queue worker is running. Start one with:', ProjectController::NO_QUEUE_WORKER);
        $this->assertSame('docker compose up -d queue-worker', ProjectController::START_QUEUE_WORKER);
        $this->assertStringContainsString('data-testid="lint-empty">' . Html::encode(LintState::FIRST_RUN_QUEUED) . '</p>', $html);
        $this->assertStringNotContainsString('data-testid="lint-previous"', $html, 'there is no previous run');
    }

    /**
     * Without a queued run: the stored result as before, no badge, no poll,
     * and no hint, also when no queue worker beats (the page does not ask).
     */
    public function testATemplateWithoutAQueuedRunShowsItsResultOnly(): void
    {
        $template = $this->template(['lint_output' => self::OUTPUT, 'lint_exit_code' => 2, 'lint_at' => 1700000000]);

        $html = $this->templatePage($template, false);

        $this->assertStringNotContainsString('data-testid="lint-queued"', $html);
        $this->assertStringNotContainsString('data-testid="lint-previous"', $html);
        $this->assertStringNotContainsString('data-testid="lint-no-worker"', $html);
        $this->assertStringNotContainsString('lint-status', $html, 'no poll');
        $this->assertStringContainsString('data-testid="lint-badge">issues found</span>', $html);
        $this->assertStringContainsString(Json::htmlEncode(self::OUTPUT), $html);
    }

    public function testATemplateWithoutAnyResultSaysWhenOneComes(): void
    {
        $html = $this->templatePage($this->template([]), true);

        $this->assertStringContainsString('data-testid="lint-empty">Lint output will appear here after saving the template or syncing the project.</p>', $html);
        $this->assertStringContainsString('Ansible Lint <small class="text-muted fw-normal">(--profile production)</small>', $html);
    }

    public function testAQueuedRunOfAProjectShowsTheBadgeThePreviousRunAndThePoll(): void
    {
        $project = $this->project(['lint_output' => self::OUTPUT, 'lint_exit_code' => -1, 'lint_at' => 1700000000, 'lint_queued_at' => 1700000100]);

        $html = $this->projectPage($project, true);

        $this->assertStringContainsString('data-testid="lint-queued">' . LintState::QUEUED . '</span>', $html);
        $this->assertStringContainsString(Html::encode(sprintf(LintState::PREVIOUS_RUN, date('Y-m-d H:i', 1700000000))), $html);
        $this->assertStringContainsString('data-testid="lint-badge">not run</span>', $html);
        $this->assertStringContainsString('id="lint-output" style="max-height:400px;overflow-y:auto;"', $html);
        $this->assertPollOf($html, '/project/lint-status?id=' . $project->id);
    }

    public function testAQueuedRunOfAProjectWithoutAQueueWorkerSaysHowToStartOne(): void
    {
        $html = $this->projectPage($this->project(['lint_queued_at' => 1700000100]), false);

        $this->assertStringContainsString('data-testid="lint-no-worker"', $html);
        $this->assertStringContainsString(Html::encode(LintState::FIRST_RUN_QUEUED), $html);
    }

    public function testAProjectWithoutAQueuedRunShowsNoBadgeAndNoPoll(): void
    {
        $html = $this->projectPage($this->project([]), false);

        $this->assertStringNotContainsString('data-testid="lint-queued"', $html);
        $this->assertStringNotContainsString('data-testid="lint-no-worker"', $html);
        $this->assertStringNotContainsString('lint-status', $html);
        $this->assertStringContainsString('No lint output yet. Click &quot;Run Lint&quot; or sync the project to generate a report.', $html);
        $this->assertStringContainsString('(--profile production, full project)', $html);
    }

    /**
     * The page asks the heartbeats of the queue-workers (WorkerHeartbeat),
     * here one planted for the test: a queue-worker beats, so no hint.
     */
    public function testTheCardAsksTheHeartbeatsOfTheQueueWorkers(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('phpredis extension not loaded.');
        }
        try {
            $redis = \app\components\RedisSettings::fromEnvironment($_ENV)->connectPhpRedis(new \Redis());
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }
        $key = \app\components\WorkerHeartbeat::KEY_PREFIX . 'phpunit-lint-card-' . uniqid('', true);
        $template = $this->template(['lint_queued_at' => 1700000100]);
        $ctrl = new class ('job-template', $this->module()) extends JobTemplateController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
        $id = (int)$template->id;
        try {
            $redis->setex($key, 120, (string)json_encode(['worker_id' => 'phpunit-lint-card', 'pid' => 1, 'hostname' => 'phpunit', 'started_at' => time(), 'seen_at' => time()]));

            $html = $this->withRealViews($ctrl, '/job-template/view?id=' . $id, static fn (): string => $ctrl->actionView($id));

            $this->assertStringContainsString('data-testid="lint-queued"', $html);
            $this->assertStringNotContainsString('data-testid="lint-no-worker"', $html);
        } finally {
            $redis->del($key);
        }
    }

    /**
     * The sync output says the same when no queue worker beats: its script
     * writes NO_QUEUE_WORKER and START_QUEUE_WORKER as text.
     */
    public function testTheSyncOutputGivesTheSameHint(): void
    {
        $project = $this->project([]);
        $project->status = Project::STATUS_SYNCING;
        $this->assertTrue($project->save(false));

        $html = $this->projectPage($project, true);

        $this->assertStringContainsString('var noWorker = ' . Json::htmlEncode(ProjectController::NO_QUEUE_WORKER) . ';', $html);
        $this->assertStringContainsString('var startWorker = ' . Json::htmlEncode(ProjectController::START_QUEUE_WORKER) . ';', $html);
        $this->assertStringContainsString("workerEl.textContent = '\\u26A0 ' + noWorker + ' ';", $html);
        $this->assertStringContainsString('command.textContent = startWorker;', $html);
        $this->assertStringNotContainsString('docker compose up -d queue-worker</code>', $html, 'no markup built from text');
    }

    /**
     * The poll of the lint card: the URL of the page's lint-status, asked
     * for JSON every two seconds; a refusal stops it with the note, a
     * finished run reloads the page.
     */
    private function assertPollOf(string $html, string $url): void
    {
        $start = strpos($html, "<script>\n(function () {\n    // While the run is queued");
        $this->assertNotFalse($start, 'the poll script is there');
        $end = stripos($html, '</script', $start);
        $this->assertNotFalse($end);
        $script = substr($html, $start, $end - $start);

        $this->assertStringContainsString('var statusUrl = ' . Json::htmlEncode($url) . ';', $script);
        $this->assertStringContainsString("fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })", $script);
        $this->assertStringContainsString('var stopStatuses = [401, 403, 404];', $script);
        $this->assertStringContainsString('var stoppedNote = ' . Json::htmlEncode(LintState::LIVE_STOPPED) . ';', $script);
        $this->assertStringContainsString('note.textContent = stoppedNote;', $script);
        $this->assertStringContainsString('if (data !== null && data.queued === false && !stopped) {', $script);
        $this->assertStringContainsString('window.location.reload();', $script);
        $this->assertStringContainsString('timer = setInterval(poll, 2000);', $script);
    }

    /**
     * @param array<string, mixed> $lint
     */
    private function template(array $lint): JobTemplate
    {
        $user = $this->superadmin();
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$this->createRunnerGroup((int)$user->id)->id,
            (int)$user->id
        );
        if ($lint !== []) {
            JobTemplate::updateAll($lint, ['id' => $template->id]);
        }

        return $template;
    }

    /**
     * @param array<string, mixed> $lint
     */
    private function project(array $lint): Project
    {
        $project = $this->createProject((int)$this->superadmin()->id);
        if ($lint !== []) {
            Project::updateAll($lint, ['id' => $project->id]);
        }

        return $project;
    }

    private function superadmin(): User
    {
        $user = $this->createUser('lint-card');
        $user->is_superadmin = true;
        $this->assertTrue($user->save(false));
        $this->loginAs($user);

        return $user;
    }

    private function templatePage(JobTemplate $template, bool $workerAlive): string
    {
        $ctrl = new class ('job-template', $this->module(), $workerAlive) extends JobTemplateController {
            public function __construct(string $id, Module $module, private readonly bool $workerAlive)
            {
                parent::__construct($id, $module);
            }

            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }

            protected function queueWorkerAlive(): bool
            {
                return $this->workerAlive;
            }
        };
        $id = (int)$template->id;

        return $this->withRealViews($ctrl, '/job-template/view?id=' . $id, static fn (): string => $ctrl->actionView($id));
    }

    private function projectPage(Project $project, bool $workerAlive): string
    {
        $ctrl = new class ('project', $this->module(), $workerAlive) extends ProjectController {
            public function __construct(string $id, Module $module, private readonly bool $workerAlive)
            {
                parent::__construct($id, $module);
            }

            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }

            protected function queueWorkerAlive(): bool
            {
                return $this->workerAlive;
            }
        };
        $id = (int)$project->id;

        return $this->withRealViews($ctrl, '/project/view?id=' . $id, static fn (): string => $ctrl->actionView($id));
    }

    private function module(): Module
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);

        return $app;
    }
}
