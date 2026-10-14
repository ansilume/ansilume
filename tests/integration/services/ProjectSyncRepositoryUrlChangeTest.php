<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\GitSyncDiagnostics;
use app\models\Project;
use app\models\ProjectSyncLog;
use app\services\ProjectService;
use app\tests\integration\DbTestCase;
use app\tests\unit\GitRemoteFixture;
use yii\log\Logger;

/**
 * A checkout keeps the URL it was cloned with as its origin, credentials
 * included, in its .git/config. The sync of an existing checkout sets origin
 * to the project's current URL before it fetches (ProjectService::gitPull()),
 * so that a changed URL (a rotated token, a moved repository) is used and the
 * credentials of the old one leave the checkout; the command is logged and
 * shown as the other git commands, without the credentials.
 *
 * Real git against repositories that it reaches through https URLs
 * (GitRemoteFixture). RunnerProjectSyncTest covers the same on the runner.
 */
class ProjectSyncRepositoryUrlChangeTest extends DbTestCase
{
    /** Test values, not secrets. */
    private const OLD_TOKEN = 'w5d-old-token-7316';
    private const NEW_TOKEN = 'w5d-new-token-9042';
    private const OLD_URL = 'https://deploy:' . self::OLD_TOKEN . '@git.example.invalid/org/playbooks.git';
    private const NEW_URL = 'https://deploy:' . self::NEW_TOKEN . '@git.example.invalid/moved/playbooks.git';

    private GitRemoteFixture $remotes;

    /** @var mixed the dispatcher definition the test replaced */
    private mixed $originalDispatcher = null;

    private Logger $logger;

    private Logger $previousLogger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDispatcher = \Yii::$app->getComponents(true)['notificationDispatcher'] ?? null;
        \Yii::$app->set('notificationDispatcher', new RecordingNotificationDispatcher());
        $this->previousLogger = \Yii::getLogger();
        $this->logger = new Logger();
        $this->logger->flushInterval = PHP_INT_MAX;
        \Yii::setLogger($this->logger);
        $this->remotes = new GitRemoteFixture(sys_get_temp_dir() . '/project-sync-url-change-' . uniqid('', true));
        $this->remotes->add(self::OLD_URL, "first\n");
        $this->remotes->add(self::NEW_URL, "second\n", self::OLD_URL);
    }

    protected function tearDown(): void
    {
        \Yii::setLogger($this->previousLogger);
        \Yii::$app->set('notificationDispatcher', $this->originalDispatcher);
        $this->remotes->remove();
        parent::tearDown();
    }

    /**
     * regression: the sync of an existing checkout fetched from its origin,
     * the URL of the first clone, so a changed repository URL was not used
     * until the checkout was deleted, and the old credentials stayed in its
     * .git/config.
     */
    public function testRegressionASyncFetchedFromTheUrlTheCheckoutWasClonedWith(): void
    {
        $project = $this->gitProject(self::OLD_URL, 'main');
        $dest = $this->dest($project);
        $this->service()->sync($project);
        $this->assertSame("first\n", file_get_contents($dest . '/README.md'));
        $this->assertSame(['config'], GitRemoteFixture::filesHolding($dest . '/.git', self::OLD_TOKEN), 'a clone keeps its URL with the credentials');

        $project->scm_url = self::NEW_URL;
        $project->save(false);
        $this->service()->sync($project);

        $this->assertSame("second\n", file_get_contents($dest . '/README.md'), 'the sync fetched from the new URL');
        $this->assertSame([], GitRemoteFixture::filesHolding($dest, self::OLD_TOKEN), 'the old credentials left the checkout');
        $this->assertStringContainsString("\turl = " . self::NEW_URL . "\n", $this->config($project));
        $this->assertSame(Project::STATUS_SYNCED, $this->reload($project)->status);
        $setUrl = "git -C {$dest} remote set-url -- origin " . GitSyncDiagnostics::redactUrl(self::NEW_URL);
        $this->assertContains(sprintf(ProjectService::GIT_COMMAND_ROW, $setUrl), $this->rows($project));
        $this->assertContains(sprintf(ProjectService::GIT_COMMAND_LOGGED, $setUrl), $this->logged());
        $this->assertNoTokenShown($project);
    }

    /**
     * regression: the branch went to git fetch after "origin", where git
     * read a branch that starts with "-" as an option: --upload-pack=<command>
     * ran <command> (on the server here, where the fetch reads a local
     * repository; over ssh on the git server). After "--" git refuses it as
     * a refspec.
     */
    public function testRegressionABranchWasReadAsAnOptionOfGitFetch(): void
    {
        $project = $this->gitProject(self::OLD_URL, 'main');
        $this->service()->sync($project);
        $marker = $this->remotes->dir . '/upload-pack-ran';
        $branch = '--upload-pack=touch ' . $marker . '; git-upload-pack';
        $project->scm_branch = $branch;
        $project->save(false);

        $thrown = null;
        try {
            $this->service()->sync($project);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertFileDoesNotExist($marker, 'git ran no command that the branch named');
        $this->assertSame(sprintf(ProjectService::GIT_FAILED, 128, "fatal: invalid refspec '{$branch}'"), $thrown?->getMessage());
        $this->assertSame(Project::STATUS_ERROR, $this->reload($project)->status);
    }

    /**
     * A checkout without an origin fails the sync where origin is set, and
     * nothing is fetched; the command shows the URL without its credentials.
     */
    public function testACheckoutWithoutOriginFailsTheSyncWhereOriginIsSet(): void
    {
        $project = $this->gitProject(self::NEW_URL, 'main');
        $dest = $this->dest($project);
        $this->remotes->git(['init', '-q', '-b', 'main', $dest]);

        $thrown = $this->failedSync($project);

        $this->assertStringContainsString("No such remote 'origin'", $thrown->getMessage());
        $this->assertFileDoesNotExist($dest . '/.git/FETCH_HEAD', 'nothing was fetched');
        $this->assertSame(Project::STATUS_ERROR, $this->reload($project)->status);
        $setUrl = "git -C {$dest} remote set-url -- origin " . GitSyncDiagnostics::redactUrl(self::NEW_URL);
        $this->assertSame([sprintf(ProjectService::GIT_COMMAND_ROW, $setUrl)], $this->systemRows($project));
        $this->assertNoTokenShown($project);
    }

    // -- Helpers ----------------------------------------------------------------

    private function gitProject(string $url, string $branch): Project
    {
        $project = $this->createProject((int)$this->createUser('sync-url-change')->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = $url;
        $project->scm_branch = $branch;
        $project->save(false);

        return $project;
    }

    /**
     * The service as the queue-worker runs it, with its checkouts in the
     * fixture's directory and git's environment extended by the fixture's
     * URL rewrites.
     */
    private function service(): ProjectService
    {
        return new class ($this->remotes) extends ProjectService {
            public function __construct(private readonly GitRemoteFixture $remotes)
            {
                parent::__construct();
            }

            public function localPath(Project $project): string
            {
                return $this->remotes->dir . '/project-' . $project->id;
            }

            protected function buildGitEnv(Project $project, ?string &$keyFile): array
            {
                $env = parent::buildGitEnv($project, $keyFile);
                $env['HOME'] = $this->remotes->dir . '/home';
                $count = (int)$env['GIT_CONFIG_COUNT'];
                foreach ($this->remotes->rewrites() as $key => $value) {
                    $env['GIT_CONFIG_KEY_' . $count] = $key;
                    $env['GIT_CONFIG_VALUE_' . $count] = $value;
                    $count++;
                }
                $env['GIT_CONFIG_COUNT'] = (string)$count;

                return $env;
            }
        };
    }

    /**
     * Syncs $project, which must fail; the exception sync() threw.
     */
    private function failedSync(Project $project): \RuntimeException
    {
        try {
            $this->service()->sync($project);
        } catch (\RuntimeException $e) {
            return $e;
        }
        $this->fail('expected a RuntimeException');
    }

    private function dest(Project $project): string
    {
        return $this->remotes->dir . '/project-' . $project->id;
    }

    private function config(Project $project): string
    {
        return (string)file_get_contents($this->dest($project) . '/.git/config');
    }

    /**
     * Neither token is in a row of the sync output, a log line or the
     * stored error.
     */
    private function assertNoTokenShown(Project $project): void
    {
        $shown = implode('', $this->rows($project)) . implode("\n", $this->logged()) . $this->reload($project)->last_sync_error;
        $this->assertStringNotContainsString(self::OLD_TOKEN, $shown);
        $this->assertStringNotContainsString(self::NEW_TOKEN, $shown);
    }

    /**
     * @return list<string> the rows of $project's sync output, in order
     */
    private function rows(Project $project): array
    {
        return $this->rowsOf(ProjectSyncLog::find()->where(['project_id' => $project->id]));
    }

    /**
     * @return list<string> the rows of $project's sync output that the sync wrote itself, in order
     */
    private function systemRows(Project $project): array
    {
        $query = ProjectSyncLog::find()->where(['project_id' => $project->id, 'stream' => ProjectSyncLog::STREAM_SYSTEM]);

        return $this->rowsOf($query);
    }

    /**
     * @return list<string>
     */
    private function rowsOf(\yii\db\ActiveQuery $query): array
    {
        $rows = [];
        foreach ($query->orderBy(['sequence' => SORT_ASC])->all() as $row) {
            /** @var ProjectSyncLog $row */
            $rows[] = (string)$row->content;
        }

        return $rows;
    }

    /**
     * @return list<string> the messages ProjectService logged
     */
    private function logged(): array
    {
        $messages = [];
        foreach ($this->logger->messages as $message) {
            if ($message[2] === ProjectService::class && is_string($message[0])) {
                $messages[] = $message[0];
            }
        }

        return $messages;
    }

    private function reload(Project $project): Project
    {
        $stored = Project::findOne($project->id);
        $this->assertNotNull($stored);

        return $stored;
    }
}
