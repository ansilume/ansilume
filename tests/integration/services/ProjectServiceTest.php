<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\NotificationTemplate;
use app\models\Project;
use app\services\NotificationDispatcher;
use app\services\ProjectService;
use app\tests\integration\DbTestCase;

/**
 * ProjectService::sync() for a git project that cannot be synced because it
 * has no repository URL, with a real project row and a recording
 * notification dispatcher.
 */
class ProjectServiceTest extends DbTestCase
{
    private ?object $originalDispatcher = null;

    /** @var NotificationDispatcher&object{dispatched: list<array{event: string, payload: array<string, mixed>}>} */
    private NotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDispatcher = \Yii::$app->get('notificationDispatcher');
        $this->dispatcher = new class () extends NotificationDispatcher {
            /** @var list<array{event: string, payload: array<string, mixed>}> */
            public array $dispatched = [];

            public function dispatch(string $event, array $payload = []): void
            {
                $this->dispatched[] = ['event' => $event, 'payload' => $payload];
            }
        };
        \Yii::$app->set('notificationDispatcher', $this->dispatcher);
    }

    protected function tearDown(): void
    {
        \Yii::$app->set('notificationDispatcher', $this->originalDispatcher);
        parent::tearDown();
    }

    /**
     * Regression: sync() threw before it touched the project, so a queued
     * sync left the project on "syncing" until the stale-sync sweeper gave
     * up on it, with no error for the operator and no notification.
     *
     * @return array<string, array{0: string|null}>
     */
    public static function missingUrlProvider(): array
    {
        return ['no URL' => [null], 'an empty URL' => ['']];
    }

    /**
     * @dataProvider missingUrlProvider
     */
    public function testSyncOfAGitProjectWithoutRepositoryUrlFailsTheProject(?string $url): void
    {
        $project = $this->queuedGitProject($url, null);

        try {
            $this->service()->sync($project);
            $this->fail('A git project without a repository URL cannot be synced.');
        } catch (\RuntimeException $e) {
            $this->assertSame("Project #{$project->id} has no SCM URL.", $e->getMessage());
        }

        $stored = Project::findOne($project->id);
        $this->assertNotNull($stored);
        $this->assertSame(Project::STATUS_ERROR, $stored->status);
        $this->assertSame('This git project has no repository URL.', $stored->last_sync_error);
        $this->assertNull($stored->sync_started_at);
        $this->assertSame('failed', $stored->last_sync_event);
        $this->assertSame([[
            'event' => NotificationTemplate::EVENT_PROJECT_SYNC_FAILED,
            'payload' => ['project' => [
                'id' => (string)$project->id,
                'name' => (string)$project->name,
                'status' => Project::STATUS_ERROR,
                'error' => 'This git project has no repository URL.',
            ]],
        ]], $this->dispatcher->dispatched);
    }

    /**
     * The failure notification follows the transition rule of every other
     * sync: a project that synced before is reported once when it starts
     * failing, not again on each failed sync after that.
     */
    public function testRepeatedSyncsWithoutRepositoryUrlNotifyOnce(): void
    {
        $project = $this->queuedGitProject('', 'synced');

        foreach ([1, 2] as $attempt) {
            try {
                $this->service()->sync($project);
                $this->fail("Sync attempt {$attempt} must fail.");
            } catch (\RuntimeException $e) {
                $this->assertSame("Project #{$project->id} has no SCM URL.", $e->getMessage());
            }
            $project->refresh();
            $this->assertSame(Project::STATUS_ERROR, $project->status, "after attempt {$attempt}");
        }

        $this->assertSame(
            [NotificationTemplate::EVENT_PROJECT_SYNC_FAILED],
            array_column($this->dispatcher->dispatched, 'event')
        );
    }

    /**
     * A git project as queueSync() leaves it: status "syncing" with a start
     * time, as the worker finds it.
     */
    private function queuedGitProject(?string $url, ?string $lastSyncEvent): Project
    {
        $project = $this->createProject((int)$this->createUser()->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = $url;
        $project->status = Project::STATUS_SYNCING;
        $project->sync_started_at = time() - 5;
        $project->last_sync_event = $lastSyncEvent;
        $project->save(false);

        return $project;
    }

    private function service(): ProjectService
    {
        /** @var ProjectService $service */
        $service = \Yii::$app->get('projectService');

        return $service;
    }
}
