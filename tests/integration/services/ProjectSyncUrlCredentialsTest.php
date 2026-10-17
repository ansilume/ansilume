<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\NotificationTemplate;
use app\models\Project;
use app\models\ProjectSyncLog;
use app\services\ProjectService;
use app\services\ProjectSyncProcessRunner;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use yii\log\Logger;

/**
 * A repository URL can carry credentials in its userinfo
 * (https://user:token@host/...). A sync names it only without them
 * (GitSyncDiagnostics::redactUrl): in the log (the queue worker's log target
 * writes info), in the sync output that every project viewer polls, in the
 * error the project stores and its page shows, in the project.sync_failed
 * notification and in the exception SyncProjectJob logs.
 *
 * regression: ProjectService::runGit() logged the git command with the URL
 * and wrote it into the sync output ('$ git clone ... <url>'); the error of
 * a failed git command carried git's stderr as it was, which can name the
 * URL (with a raw "@" in the password, git names the rest of the password as
 * the user), into the log, the stored error, the notification and the
 * exception; a timeout named the command with the URL in all of them.
 */
class ProjectSyncUrlCredentialsTest extends DbTestCase
{
    use RestoresComponents;

    /** Test values, not secrets. */
    private const SECRET = 'w5c-sync-token-5521';
    private const URL = 'https://deploy:' . self::SECRET . '@git.example.com/org/playbooks.git';
    private const SHOWN = 'https://***@git.example.com/org/playbooks.git';

    /** What git writes when it cannot fetch the URL, naming it as an older git does. */
    private const GIT_STDERR = "Cloning into 'dest'...\nfatal: unable to access '" . self::URL . "/': The requested URL returned error: 403\n";
    private const GIT_STDERR_SHOWN = "Cloning into 'dest'...\nfatal: unable to access '" . self::SHOWN . "/': The requested URL returned error: 403";

    private RecordingNotificationDispatcher $dispatcher;

    private string $workspace;

    private Logger $logger;

    private Logger $previousLogger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new RecordingNotificationDispatcher();
        $this->swapComponent('notificationDispatcher', $this->dispatcher);
        $this->workspace = sys_get_temp_dir() . '/project-sync-url-credentials-' . uniqid('', true);
        $this->previousLogger = \Yii::getLogger();
        $this->logger = new Logger();
        $this->logger->flushInterval = PHP_INT_MAX;
        \Yii::setLogger($this->logger);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        \Yii::setLogger($this->previousLogger);
        if (is_dir($this->workspace)) {
            \app\helpers\FileHelper::removeDirectory($this->workspace);
        }
        parent::tearDown();
    }

    /**
     * regression: the command and git's stderr reached the info and warning
     * log, the sync output, the stored error, the notification and the
     * exception with the URL's credentials.
     */
    public function testRegressionAFailedCloneNamedTheCredentialsEverywhere(): void
    {
        $project = $this->gitProject();
        $runner = new class (self::GIT_STDERR) extends ProjectSyncProcessRunner {
            public function __construct(private readonly string $stderr)
            {
            }

            public function run(Project $project, array $cmd, array $env, int $timeoutSeconds): array
            {
                return ['', $this->stderr, 128];
            }
        };

        $thrown = $this->syncWith($project, $runner);

        $this->assertNoSecretAnywhere($project, $thrown);
        $clone = 'git clone --progress --branch main --depth 1 -- ' . self::SHOWN . ' ' . $this->dest($project);
        $error = sprintf(ProjectService::GIT_FAILED, 128, self::GIT_STDERR_SHOWN);
        $this->assertContains(sprintf(ProjectService::GIT_COMMAND_LOGGED, $clone), $this->logged(Logger::LEVEL_INFO));
        $this->assertContains(sprintf(ProjectService::GIT_STDERR_LOGGED, str_replace(self::URL, self::SHOWN, self::GIT_STDERR)), $this->logged(Logger::LEVEL_WARNING));
        $this->assertSame([sprintf(ProjectService::GIT_COMMAND_ROW, $clone)], $this->rows($project));
        $this->assertSame($error, $thrown->getMessage());
        $this->assertSame($error, $this->reload($project)->last_sync_error);
        $this->assertSame([$error], $this->notifiedErrors());
    }

    /**
     * regression: git's stdout, logged at info, kept the URL's credentials too.
     */
    public function testRegressionGitsStdoutWasLoggedWithTheCredentials(): void
    {
        $project = $this->gitProject();
        $runner = new class (self::URL) extends ProjectSyncProcessRunner {
            public function __construct(private readonly string $url)
            {
            }

            public function run(Project $project, array $cmd, array $env, int $timeoutSeconds): array
            {
                return ["From {$this->url}\n", '', 0];
            }
        };
        $service = $this->service($runner);

        $service->sync($project);

        $this->assertStringNotContainsString(self::SECRET, $this->allLogged());
        $this->assertContains(sprintf(ProjectService::GIT_STDOUT_LOGGED, 'From ' . self::SHOWN . "\n"), $this->logged(Logger::LEVEL_INFO));
        $this->assertSame(Project::STATUS_SYNCED, $this->reload($project)->status);
    }

    /**
     * With nothing from git, the error says so.
     */
    public function testAFailedCommandWithoutOutputSaysSo(): void
    {
        $project = $this->gitProject();
        $runner = new class () extends ProjectSyncProcessRunner {
            public function run(Project $project, array $cmd, array $env, int $timeoutSeconds): array
            {
                return ['', '', 1];
            }
        };

        $thrown = $this->syncWith($project, $runner);

        $this->assertSame(sprintf(ProjectService::GIT_FAILED, 1, ProjectService::NO_OUTPUT), $thrown->getMessage());
    }

    /**
     * regression: a timeout's row, the stored error, the notification and the
     * exception named the command with the URL's credentials. A real
     * subprocess that outlives the timeout.
     */
    public function testRegressionATimeoutNamedTheCredentialsOfTheCommand(): void
    {
        $project = $this->gitProject();
        $runner = new class () extends ProjectSyncProcessRunner {
            public function run(Project $project, array $cmd, array $env, int $timeoutSeconds): array
            {
                return parent::run($project, ['/bin/sh', '-c', 'sleep 30', ...$cmd], [], $timeoutSeconds);
            }
        };

        $thrown = $this->syncWith($project, $runner, 1);

        $this->assertNoSecretAnywhere($project, $thrown);
        $error = sprintf(
            ProjectSyncProcessRunner::TIMED_OUT,
            1,
            '/bin/sh -c sleep 30 git clone --progress --branch main --depth 1 -- ' . self::SHOWN . ' ' . $this->dest($project)
        );
        $this->assertSame($error, $thrown->getMessage());
        $this->assertSame($error, $this->reload($project)->last_sync_error);
        $this->assertSame([$error], $this->notifiedErrors());
        $this->assertSame($error . "\n", $this->rows($project)[1] ?? null);
    }

    /**
     * Any other error whose message names a URL's credentials is stored,
     * sent and thrown without them, and not passed on as the previous
     * exception, whose message SyncProjectJob's log would not see redacted.
     *
     * @return array<string, array{0: \Throwable}>
     */
    public static function otherErrorProvider(): array
    {
        return [
            'a RuntimeException' => [new \RuntimeException('cannot read ' . self::URL)],
            'an Error' => [new \Error('cannot read ' . self::URL)],
        ];
    }

    /**
     * @dataProvider otherErrorProvider
     */
    public function testAnyOtherErrorNamingCredentialsIsStoredAndThrownWithoutThem(\Throwable $error): void
    {
        $project = $this->gitProject();
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
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $thrown) {
            $this->assertNotSame($error, $thrown);
            $this->assertNull($thrown->getPrevious());
            $this->assertSame('cannot read ' . self::SHOWN, $thrown->getMessage());
            $this->assertNoSecretAnywhere($project, $thrown);
        }
        $this->assertSame('cannot read ' . self::SHOWN, $this->reload($project)->last_sync_error);
    }

    // -- Helpers ----------------------------------------------------------------

    private function gitProject(): Project
    {
        $project = $this->createProject((int)$this->createUser('sync-url')->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = self::URL;
        $project->scm_branch = 'main';
        $project->save(false);

        return $project;
    }

    private function service(ProjectSyncProcessRunner $runner, int $timeout = 300): ProjectService
    {
        $service = new class ($this->workspace, $runner) extends ProjectService {
            public function __construct(private readonly string $workspace, private readonly ProjectSyncProcessRunner $runner)
            {
                parent::__construct();
            }

            public function localPath(Project $project): string
            {
                return $this->workspace . '/' . $project->id;
            }

            protected function buildGitEnv(Project $project, ?string &$keyFile): array
            {
                return ['HOME' => $this->workspace];
            }

            protected function processRunner(): ProjectSyncProcessRunner
            {
                return $this->runner;
            }
        };
        $service->gitTimeoutSeconds = $timeout;

        return $service;
    }

    /**
     * Syncs $project with $runner in place of git; the exception sync() threw.
     */
    private function syncWith(Project $project, ProjectSyncProcessRunner $runner, int $timeout = 300): \RuntimeException
    {
        try {
            $this->service($runner, $timeout)->sync($project);
        } catch (\RuntimeException $e) {
            return $e;
        }
        $this->fail('expected a RuntimeException');
    }

    private function dest(Project $project): string
    {
        return $this->workspace . '/' . $project->id;
    }

    /**
     * The secret is in no log line, no row of the sync output, not in the
     * stored error, the notification or the exception (with its chain).
     */
    private function assertNoSecretAnywhere(Project $project, \Throwable $thrown): void
    {
        $this->assertStringNotContainsString(self::SECRET, $this->allLogged(), 'the log');
        $this->assertStringNotContainsString(self::SECRET, implode('', $this->rows($project)), 'the sync output');
        $this->assertStringNotContainsString(self::SECRET, (string)$this->reload($project)->last_sync_error, 'the stored error');
        $this->assertStringNotContainsString(self::SECRET, (string)json_encode($this->notifiedErrors()), 'the notification');
        $this->assertStringNotContainsString(self::SECRET, (string)$thrown, 'the exception');
    }

    /**
     * @return list<string> the messages logged at $level that the log targets write
     */
    private function logged(int $level): array
    {
        $messages = [];
        foreach ($this->written() as $message) {
            if ($message[1] === $level && is_string($message[0])) {
                $messages[] = $message[0];
            }
        }

        return $messages;
    }

    /** Every message the log targets write, one per line. */
    private function allLogged(): string
    {
        $text = '';
        foreach ($this->written() as $message) {
            $text .= (is_string($message[0]) ? $message[0] : (string)json_encode($message[0])) . "\n";
        }

        return $text;
    }

    /**
     * The messages logged, but those of yii\db\Command::*: the statements
     * with their values, the fixtures' too, which no log target writes
     * (config/console.php leaves them out, config/web.php writes no info).
     *
     * @return list<array<int, mixed>>
     */
    private function written(): array
    {
        $written = [];
        foreach ($this->logger->messages as $message) {
            if (!str_starts_with((string)$message[2], 'yii\\db\\Command::')) {
                $written[] = $message;
            }
        }

        return $written;
    }

    /**
     * @return list<string> the rows of $project's sync output, in order
     */
    private function rows(Project $project): array
    {
        $rows = [];
        foreach (ProjectSyncLog::find()->where(['project_id' => $project->id])->orderBy(['sequence' => SORT_ASC])->all() as $row) {
            /** @var ProjectSyncLog $row */
            $rows[] = (string)$row->content;
        }

        return $rows;
    }

    private function reload(Project $project): Project
    {
        $stored = Project::findOne($project->id);
        $this->assertNotNull($stored);

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
