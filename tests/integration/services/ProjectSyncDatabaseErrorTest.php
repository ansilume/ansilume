<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\NotificationTemplate;
use app\models\Project;
use app\services\ProjectService;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;

/**
 * A sync that fails stores its error on the project (shown on the project
 * page and in the API, sent in the project.sync_failed notification) and
 * throws it on as a RuntimeException. A database error, such as one while
 * loading the project's SCM credential, is stored and passed on only as
 * ErrorText words it: class, SQLSTATE and driver error code.
 *
 * Regression: the stored error, the notification and the exception carried
 * the database error's message, which holds the SQL statement with its
 * values.
 */
class ProjectSyncDatabaseErrorTest extends DbTestCase
{
    use RestoresComponents;

    private const MARKER = 'project-sync-db-marker-3301';

    private RecordingNotificationDispatcher $dispatcher;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new RecordingNotificationDispatcher();
        $this->swapComponent('notificationDispatcher', $this->dispatcher);
        $this->workspace = sys_get_temp_dir() . '/project-sync-db-error-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        if (is_dir($this->workspace)) {
            \app\helpers\FileHelper::safeRmdir($this->workspace);
        }
        parent::tearDown();
    }

    public function testRegressionADatabaseErrorOfASyncWasStoredSentAndThrownWithItsStatement(): void
    {
        $project = $this->gitProject();
        $summary = 'yii\db\Exception (SQLSTATE HY000, driver error 2006)';

        $thrown = $this->syncFailing($project, new \yii\db\Exception(
            "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\n"
                . "The SQL being executed was: SELECT * FROM `credential` WHERE `name`='" . self::MARKER . "'",
            ['HY000', 2006, 'MySQL server has gone away']
        ));

        $this->assertSame(\RuntimeException::class, $thrown::class);
        $this->assertSame($summary, $thrown->getMessage());
        $this->assertStringNotContainsString(self::MARKER, (string)$thrown);
        $this->assertNull($thrown->getPrevious(), 'the database error is not passed on as the previous exception');
        $stored = $this->reload($project);
        $this->assertSame(Project::STATUS_ERROR, $stored->status);
        $this->assertSame($summary, $stored->last_sync_error);
        $this->assertSame([$summary], $this->notifiedErrors());
    }

    /**
     * A PDOException that escaped Yii is a RuntimeException already: it is
     * thrown on as it is (SyncProjectJob, the only caller, logs it as
     * ErrorText words it), and stored only as its class.
     */
    public function testAPdoExceptionIsStoredOnlyAsItsClass(): void
    {
        $project = $this->gitProject();
        $error = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '" . self::MARKER . "'");

        $thrown = $this->syncFailing($project, $error);

        $this->assertSame($error, $thrown);
        $this->assertSame('PDOException (SQLSTATE -, driver error -)', $this->reload($project)->last_sync_error);
        $this->assertSame(['PDOException (SQLSTATE -, driver error -)'], $this->notifiedErrors());
    }

    /**
     * Any other error keeps its message, stored and thrown on, with the
     * error as the previous exception.
     */
    public function testAnotherErrorKeepsItsMessage(): void
    {
        $project = $this->gitProject();
        $error = new \Error('Call to undefined method Credential::secretOf()');

        $thrown = $this->syncFailing($project, $error);

        $this->assertSame('Call to undefined method Credential::secretOf()', $thrown->getMessage());
        $this->assertSame($error, $thrown->getPrevious());
        $this->assertSame('Call to undefined method Credential::secretOf()', $this->reload($project)->last_sync_error);
    }

    // -- Helpers ----------------------------------------------------------------

    private function gitProject(): Project
    {
        $project = $this->createProject((int)$this->createUser('sync-db')->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = 'https://git.example.com/ops/playbooks.git';
        $project->save(false);

        return $project;
    }

    /**
     * Sync $project while preparing git's environment throws $error (as
     * loading its SCM credential would); the exception sync() threw.
     */
    private function syncFailing(Project $project, \Throwable $error): \RuntimeException
    {
        $service = new class ($this->workspace, $error) extends ProjectService {
            public function __construct(private readonly string $workspace, private readonly \Throwable $error)
            {
                parent::__construct();
            }

            public function localPath(Project $project): string
            {
                return $this->workspace . '/' . $project->id;
            }

            protected function buildGitEnv(Project $project, ?string &$keyFile): array
            {
                throw $this->error;
            }
        };

        try {
            $service->sync($project);
        } catch (\RuntimeException $e) {
            return $e;
        }
        $this->fail('expected a RuntimeException');
    }

    private function reload(Project $project): Project
    {
        $stored = Project::findOne($project->id);
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString(self::MARKER, (string)$stored->last_sync_error);

        return $stored;
    }

    /**
     * @return list<mixed> the errors of the project.sync_failed notifications
     */
    private function notifiedErrors(): array
    {
        $errors = [];
        foreach ($this->dispatcher->payloadsOf(NotificationTemplate::EVENT_PROJECT_SYNC_FAILED) as $payload) {
            $project = $payload['project'] ?? null;
            $this->assertIsArray($project);
            $errors[] = $project['error'] ?? null;
        }

        return $errors;
    }
}
