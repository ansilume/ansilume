<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\jobs\LintProjectJob;
use app\jobs\LintTemplateJob;
use app\models\JobTemplate;
use app\models\Project;
use app\services\LintService;
use app\tests\integration\DbTestCase;
use app\tests\integration\RecordingQueue;
use app\tests\integration\RestoresComponents;
use yii\log\Logger;

/**
 * The queued state of lint (LintService): a request marks the record
 * (lint_queued_at) and pushes a job; a run serves the requests up to the
 * time it is given and clears the mark in the UPDATE that stores its result
 * only when the mark is no newer, so that a newer request's mark stays.
 * Every path of a run ends the requests it serves; a push that fails clears
 * the mark it set, logs without the error's message, and keeps the save.
 */
class LintServiceQueueTest extends DbTestCase
{
    use RestoresComponents;

    /** In the message of the push error: what must not reach the log (a Redis command with its values). */
    private const PUSH_ERROR_MARKER = 'RPUSH ansilume-queue.waiting lint-queue-canary';

    private RecordingQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new RecordingQueue();
        $this->swapComponent('queue', $this->queue);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * The production default: lint runs go to the queue of the queue-worker
     * (the component queue), not the notification queue; config/services.php
     * sets no other.
     */
    public function testLintRunsGoToTheQueueOfTheQueueWorker(): void
    {
        $services = require dirname(__DIR__, 3) . '/config/services.php';

        $this->assertSame('queue', (new LintService())->queue);
        $this->assertSame(['class' => LintService::class], $services['lintService']);
    }

    public function testQueueForTemplateMarksTheTemplateAndPushesAJobThatCarriesTheMark(): void
    {
        $template = $this->template();
        $before = time();

        $this->assertTrue($this->service()->queueForTemplate($template));

        $stored = $this->storedTemplate($template);
        $this->assertNotNull($stored->lint_queued_at);
        $this->assertGreaterThanOrEqual($before, (int)$stored->lint_queued_at);
        $this->assertSame((int)$stored->lint_queued_at, (int)$template->lint_queued_at, 'the record holds the mark');
        $this->assertFalse($template->isAttributeChanged('lint_queued_at'), 'the mark is stored, no unsaved change');
        $this->assertCount(1, $this->queue->jobs);
        $job = $this->queue->jobs[0];
        $this->assertInstanceOf(LintTemplateJob::class, $job);
        $this->assertSame((int)$template->id, $job->templateId);
        $this->assertSame((int)$stored->lint_queued_at, $job->requestedAt);
    }

    public function testQueueForProjectMarksTheProjectAndPushesAJobThatCarriesTheMark(): void
    {
        $project = $this->createProject((int)$this->createUser('lint-queue-project')->id);

        $this->assertTrue($this->service()->queueForProject($project));

        $stored = Project::findOne($project->id);
        $this->assertNotNull($stored);
        $this->assertNotNull($stored->lint_queued_at);
        $this->assertCount(1, $this->queue->jobs);
        $job = $this->queue->jobs[0];
        $this->assertInstanceOf(LintProjectJob::class, $job);
        $this->assertSame((int)$project->id, $job->projectId);
        $this->assertSame((int)$stored->lint_queued_at, $job->requestedAt);
    }

    /**
     * Another request's newer mark is never overwritten by an older one:
     * a request sets the current time, which is the newest.
     */
    public function testARequestReplacesAnOlderMarkWithItsOwn(): void
    {
        $template = $this->template();
        JobTemplate::updateAll(['lint_queued_at' => 1700000000], ['id' => $template->id]);

        $this->service()->queueForTemplate($template);

        $this->assertGreaterThan(1700000000, (int)$this->storedTemplate($template)->lint_queued_at);
    }

    /**
     * A push that fails, as with a Redis that cannot be reached, keeps the
     * save: false, the mark it set cleared again (the API then reports
     * lint.queued_at null), and a warning without the error's message.
     */
    public function testAPushThatFailsClearsTheMarkItSetAndLogsWithoutTheMessage(): void
    {
        $template = $this->template();
        $this->queue->failWith = new \yii\db\Exception('Redis error: connection lost. Redis command was: ' . self::PUSH_ERROR_MARKER);

        [$queued, $logged] = $this->logged(fn (): bool => $this->service()->queueForTemplate($template));

        $this->assertFalse($queued);
        $this->assertNull($this->storedTemplate($template)->lint_queued_at);
        $this->assertNull($template->lint_queued_at, 'the record says so too');
        $this->assertSame([], $this->queue->jobs);
        $this->assertSame(
            [sprintf(LintService::NOT_QUEUED_LOGGED, 'job template', (int)$template->id, 'yii\db\Exception (SQLSTATE -, driver error -)')],
            $logged
        );
    }

    public function testAPushOfAProjectThatFailsClearsTheMarkAndNamesTheProject(): void
    {
        $project = $this->createProject((int)$this->createUser('lint-queue-push')->id);
        $this->queue->failWith = new \RuntimeException('unreachable');

        [$queued, $logged] = $this->logged(fn (): bool => $this->service()->queueForProject($project));

        $this->assertFalse($queued);
        $stored = Project::findOne($project->id);
        $this->assertNotNull($stored);
        $this->assertNull($stored->lint_queued_at);
        $this->assertSame([sprintf(LintService::NOT_QUEUED_LOGGED, 'project', (int)$project->id, 'RuntimeException: unreachable')], $logged);
    }

    /**
     * The clear of a failed push follows the compare rule: a newer request's
     * mark, set while the push failed, stays.
     */
    public function testAPushThatFailsKeepsANewerRequestsMark(): void
    {
        $template = $this->template();
        $newer = time() + 60;
        $this->queue->beforePush = static function () use ($template, $newer): void {
            JobTemplate::updateAll(['lint_queued_at' => $newer], ['id' => $template->id]);
        };
        $this->queue->failWith = new \RuntimeException('unreachable');

        [$queued] = $this->logged(fn (): bool => $this->service()->queueForTemplate($template));

        $this->assertFalse($queued);
        $this->assertSame($newer, (int)$this->storedTemplate($template)->lint_queued_at);
    }

    /**
     * The compare rule of a run's UPDATE: a mark no newer than the time the
     * run serves up to is cleared, a newer one stays; the result is stored
     * either way.
     *
     * @dataProvider marks
     */
    public function testARunClearsOnlyTheMarksItServes(?int $mark, int $servedUpTo, ?int $left): void
    {
        $template = $this->template();
        JobTemplate::updateAll(['lint_queued_at' => $mark], ['id' => $template->id]);
        $template->refresh();

        $this->service()->runForTemplate($template, $servedUpTo);

        $stored = $this->storedTemplate($template);
        $this->assertSame($left, $stored->lint_queued_at === null ? null : (int)$stored->lint_queued_at);
        $this->assertSame($left, $template->lint_queued_at === null ? null : (int)$template->lint_queued_at, 'the record says so too');
        $this->assertSame(LintService::NO_LOCAL_PATH, $stored->lint_output, 'the result is stored');
        $this->assertNotNull($stored->lint_at);
    }

    /**
     * @return array<string, array{0: int|null, 1: int, 2: int|null}>
     */
    public static function marks(): array
    {
        return [
            'an older request' => [1700000000, 1700000005, null],
            'the request of the run' => [1700000005, 1700000005, null],
            'a newer request' => [1700000009, 1700000005, 1700000009],
            'no request' => [null, 1700000005, null],
        ];
    }

    public function testARunOfAProjectClearsOnlyTheMarksItServes(): void
    {
        $user = $this->createUser('lint-queue-compare');
        $older = $this->createProject((int)$user->id);
        $newer = $this->createProject((int)$user->id);
        foreach ([[$older, 1700000000], [$newer, 1700000009]] as [$project, $mark]) {
            $project->local_path = '/tmp/ansilume_lint_queue_missing_' . uniqid('', true);
            $project->lint_queued_at = $mark;
            $project->save(false);
            $this->service()->runForProject($project, 1700000005);
        }

        $this->assertNull(Project::findOne($older->id)?->lint_queued_at);
        $this->assertSame(1700000009, (int)Project::findOne($newer->id)?->lint_queued_at);
        $this->assertSame(sprintf(LintService::PATH_NOT_FOUND, $newer->local_path), Project::findOne($newer->id)?->lint_output);
    }

    /**
     * ansible-lint not installed: nothing new is stored, the result before
     * stays, and the mark is cleared all the same.
     */
    public function testWithoutAnsibleLintTheMarkIsClearedAndTheStoredResultStays(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_queue_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/site.yml', "---\n- hosts: all\n");
        try {
            $template = $this->template();
            $project = $template->project;
            $project->local_path = $dir;
            $project->save(false);
            JobTemplate::updateAll(
                ['lint_output' => 'earlier result', 'lint_exit_code' => 2, 'lint_at' => 1700000000, 'lint_queued_at' => 1700000005],
                ['id' => $template->id]
            );
            $project->lint_output = 'earlier project result';
            $project->lint_at = 1700000000;
            $project->lint_queued_at = 1700000005;
            $project->save(false);
            $template->refresh();
            $service = new class () extends LintService {
                protected function isAvailable(): bool
                {
                    return false;
                }
            };

            $service->runForTemplate($template, 1700000005);
            $service->runForProject($project, 1700000005);

            $stored = $this->storedTemplate($template);
            $this->assertNull($stored->lint_queued_at);
            $this->assertSame('earlier result', $stored->lint_output);
            $this->assertSame(2, (int)$stored->lint_exit_code);
            $this->assertSame(1700000000, (int)$stored->lint_at);
            $storedProject = Project::findOne($project->id);
            $this->assertNotNull($storedProject);
            $this->assertNull($storedProject->lint_queued_at);
            $this->assertSame('earlier project result', $storedProject->lint_output);
        } finally {
            \app\helpers\FileHelper::safeUnlink($dir . '/site.yml');
            \app\helpers\FileHelper::safeRmdir($dir);
        }
    }

    /**
     * A project without a path stores nothing, as before, and ends the
     * request.
     */
    public function testAProjectWithoutAPathStoresNothingAndEndsTheRequest(): void
    {
        $project = $this->createProject((int)$this->createUser('lint-queue-nopath')->id);
        $project->lint_queued_at = 1700000000;
        $project->save(false);

        $this->service()->runForProject($project, 1700000000);

        $stored = Project::findOne($project->id);
        $this->assertNotNull($stored);
        $this->assertNull($stored->lint_queued_at);
        $this->assertNull($stored->lint_output);
    }

    /**
     * The early paths of a template's run each store why and end the
     * request: no project, a path or a playbook that is missing.
     */
    public function testTheEarlyPathsOfATemplatesRunEndTheRequest(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_queue_early_' . uniqid('', true);
        mkdir($dir);
        try {
            $missingPath = $this->template();
            $missingPath->project->local_path = $dir . '/gone';
            $missingPath->project->save(false);
            $missingPlaybook = $this->template();
            $missingPlaybook->project->local_path = $dir;
            $missingPlaybook->project->save(false);
            $noProject = $this->template();
            $noProject->populateRelation('project', null);
            foreach ([$missingPath, $missingPlaybook, $noProject] as $template) {
                JobTemplate::updateAll(['lint_queued_at' => 1700000000], ['id' => $template->id]);
                $template->lint_queued_at = 1700000000;
                $this->service()->runForTemplate($template, 1700000000);
            }

            $this->assertSame(sprintf(LintService::PATH_NOT_FOUND, $dir . '/gone'), $this->storedTemplate($missingPath)->lint_output);
            $this->assertSame(sprintf(LintService::PLAYBOOK_NOT_FOUND, 'site.yml'), $this->storedTemplate($missingPlaybook)->lint_output);
            $this->assertSame(LintService::NO_PROJECT, $this->storedTemplate($noProject)->lint_output);
            foreach ([$missingPath, $missingPlaybook, $noProject] as $template) {
                $this->assertNull($this->storedTemplate($template)->lint_queued_at);
            }
        } finally {
            \app\helpers\FileHelper::safeRmdir($dir);
        }
    }

    /**
     * The texts of the early paths, which the docs quote: the stored output
     * says why lint did not run, as the class constants word it.
     */
    public function testTheEarlyPathsStoreTheTextsOfTheirConstants(): void
    {
        $this->assertSame('Project path not found: /srv/playbooks', sprintf(LintService::PATH_NOT_FOUND, '/srv/playbooks'));
        $this->assertSame('Project workspace not found — sync the project first.', LintService::WORKSPACE_NOT_FOUND);
        $this->assertSame('No project assigned to this template.', LintService::NO_PROJECT);
        $this->assertSame('No local path configured for this project.', LintService::NO_LOCAL_PATH);
        $this->assertSame("Playbook not found: site.yml\nSync the project to fetch the latest files.", sprintf(LintService::PLAYBOOK_NOT_FOUND, 'site.yml'));
    }

    /**
     * A result that the database refuses (an output that is no valid
     * UTF-8) is replaced by why, in an UPDATE of its own that follows the
     * compare rule: a newer request's mark stays, an older one is cleared.
     * The warning names the record and the error, never the statement or
     * the output.
     */
    public function testARefusedResultIsStoredAsWhyAndKeepsANewerRequestsMark(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_queue_refused_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/site.yml', "---\n- hosts: all\n");
        try {
            $older = $this->template();
            $newer = $this->template();
            foreach ([[$older, 1700000000], [$newer, 1700000009]] as [$template, $mark]) {
                $template->project->local_path = $dir;
                $template->project->save(false);
                JobTemplate::updateAll(['lint_queued_at' => $mark], ['id' => $template->id]);
                $template->refresh();
            }
            $service = $this->linting('lint-refused-canary ' . "\xc3\x28", 2);

            [, $logged] = $this->logged(function () use ($service, $older, $newer): void {
                $service->runForTemplate($older, 1700000005);
                $service->runForTemplate($newer, 1700000005);
            });

            $why = sprintf(LintService::NOT_STORED, 'yii\db\Exception (SQLSTATE 22007, driver error 1366)');
            $this->assertNull($this->storedTemplate($older)->lint_queued_at);
            $this->assertNull($older->lint_queued_at, 'the record says so too');
            $this->assertSame(1700000009, (int)$this->storedTemplate($newer)->lint_queued_at, 'the newer mark stays');
            foreach ([$older, $newer] as $template) {
                $this->assertSame($why, $this->storedTemplate($template)->lint_output);
                $this->assertSame($why, $template->lint_output);
                $this->assertSame(2, (int)$this->storedTemplate($template)->lint_exit_code);
                $this->assertFalse($template->isAttributeChanged('lint_output'), 'what is stored, no unsaved change');
            }
            $this->assertSame([
                sprintf(LintService::NOT_STORED_LOGGED, 'job template', (int)$older->id, 'yii\db\Exception (SQLSTATE 22007, driver error 1366)'),
                sprintf(LintService::NOT_STORED_LOGGED, 'job template', (int)$newer->id, 'yii\db\Exception (SQLSTATE 22007, driver error 1366)'),
            ], $logged);
            foreach ($logged as $line) {
                $this->assertStringNotContainsString('lint-refused-canary', $line);
                $this->assertStringNotContainsString('UPDATE', $line);
            }
        } finally {
            \app\helpers\FileHelper::safeUnlink($dir . '/site.yml');
            \app\helpers\FileHelper::safeRmdir($dir);
        }
    }

    private function service(): LintService
    {
        return new LintService();
    }

    /**
     * A LintService whose ansible-lint is installed and writes $output,
     * exiting with $exitCode.
     */
    private function linting(string $output, int $exitCode): LintService
    {
        return new class ($output, $exitCode) extends LintService {
            public function __construct(private readonly string $output, private readonly int $exitCode)
            {
                parent::__construct();
            }

            protected function isAvailable(): bool
            {
                return true;
            }

            protected function execute(?string $playbook, string $cwd): array
            {
                return [$this->output, $this->exitCode];
            }
        };
    }

    private function template(): JobTemplate
    {
        $user = (int)$this->createUser('lint-queue')->id;

        return $this->createJobTemplate(
            (int)$this->createProject($user)->id,
            (int)$this->createInventory($user)->id,
            (int)$this->createRunnerGroup($user)->id,
            $user
        );
    }

    private function storedTemplate(JobTemplate $template): JobTemplate
    {
        $stored = JobTemplate::findOne($template->id);
        $this->assertInstanceOf(JobTemplate::class, $stored);

        return $stored;
    }

    /**
     * Runs $call and returns its result with the warnings LintService
     * logged; none may hold the push error's message.
     *
     * @template T
     * @param callable(): T $call
     * @return array{0: T, 1: list<string>}
     */
    private function logged(callable $call): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $result = $call();
        } finally {
            \Yii::setLogger($previous);
        }
        $logged = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_WARNING && str_starts_with((string)$message[2], LintService::class) && is_string($message[0])) {
                $this->assertStringNotContainsString(self::PUSH_ERROR_MARKER, $message[0]);
                $logged[] = $message[0];
            }
        }

        return [$result, $logged];
    }
}
