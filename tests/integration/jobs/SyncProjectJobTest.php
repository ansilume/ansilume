<?php

declare(strict_types=1);

namespace app\tests\integration\jobs;

use app\jobs\SyncProjectJob;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\JobTemplateVaultCheck;
use app\models\Project;
use app\models\ProjectVaultEntry;
use app\tests\integration\DbTestCase;
use app\tests\unit\components\vault\TemporaryTree;

/**
 * Integration tests for SyncProjectJob.
 */
class SyncProjectJobTest extends DbTestCase
{
    use TemporaryTree;

    /** @var array<string, mixed> application components replaced by a test, restored in tearDown() */
    private array $replaced = [];

    protected function tearDown(): void
    {
        foreach ($this->replaced as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->replaced = [];
        $this->removeTrees();
        parent::tearDown();
    }

    public function testExecuteSkipsNonExistentProject(): void
    {
        $job = new SyncProjectJob(['projectId' => 999999]);
        // Should not throw, just log and return
        $job->execute(null);
        $this->assertTrue(true);
    }

    public function testExecuteSkipsProjectIdZero(): void
    {
        $job = new SyncProjectJob(['projectId' => 0]);
        $job->execute(null);
        $this->assertTrue(true);
    }

    public function testExecuteHandlesManualProject(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user->id);
        // Manual projects have no SCM URL — sync should handle gracefully
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->scm_url  = '';
        $project->save(false);

        $job = new SyncProjectJob(['projectId' => $project->id]);
        // sync() on a manual project may throw RuntimeException — that's caught
        $job->execute(null);
        $this->assertTrue(true);
    }

    public function testExecuteDoesNotCrashOnGitProjectWithoutUrl(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url  = '';
        $project->save(false);

        $job = new SyncProjectJob(['projectId' => $project->id]);
        // RuntimeException from sync is caught internally
        $job->execute(null);
        $this->assertTrue(true);
    }

    public function testJobIdPropertyDefaultsToZero(): void
    {
        $job = new SyncProjectJob();
        $this->assertSame(0, $job->projectId);
    }

    public function testJobIdCanBeSetViaConstructor(): void
    {
        $job = new SyncProjectJob(['projectId' => 42]);
        $this->assertSame(42, $job->projectId);
    }

    public function testExecuteHappyPathRunsSyncAndLint(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        // Create a job template in the same project so runForTemplate is hit.
        $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $user->id);

        $originalProjectService = \Yii::$app->getComponents(true)['projectService'] ?? null;
        $originalLintService = \Yii::$app->getComponents(true)['lintService'] ?? null;

        $projectStub = new class extends \yii\base\Component {
            public int $syncCalls = 0;
            public function sync(\app\models\Project $p): void
            {
                $this->syncCalls++;
            }
        };
        $lintStub = new class extends \yii\base\Component {
            public int $projectCalls = 0;
            public int $templateCalls = 0;
            public function runForProject(\app\models\Project $p): void
            {
                $this->projectCalls++;
            }
            public function runForTemplate(\app\models\JobTemplate $t): void
            {
                $this->templateCalls++;
            }
        };
        \Yii::$app->set('projectService', $projectStub);
        \Yii::$app->set('lintService', $lintStub);

        try {
            $job = new SyncProjectJob(['projectId' => $project->id]);
            $job->execute(null);

            $this->assertSame(1, $projectStub->syncCalls);
            $this->assertSame(1, $lintStub->projectCalls);
            $this->assertSame(1, $lintStub->templateCalls);
        } finally {
            \Yii::$app->set('projectService', $originalProjectService);
            \Yii::$app->set('lintService', $originalLintService);
        }
    }

    /**
     * Regression: a Throwable from the lint phase used to bubble out and
     * leave the rest of the job dangling. The fix wraps the lint block in
     * its own try/catch so a successful sync stays committed and only a
     * warning gets logged.
     */
    public function testExecuteSwallowsLintThrowableAfterSuccessfulSync(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);

        $originalProjectService = \Yii::$app->getComponents(true)['projectService'] ?? null;
        $originalLintService = \Yii::$app->getComponents(true)['lintService'] ?? null;

        \Yii::$app->set('projectService', new class extends \yii\base\Component {
            public function sync(\app\models\Project $p): void
            {
                $p->status = \app\models\Project::STATUS_SYNCED;
                $p->save(false);
            }
        });
        \Yii::$app->set('lintService', new class extends \yii\base\Component {
            public function runForProject(\app\models\Project $p): void
            {
                throw new \Error('ansible-lint exploded');
            }
            public function runForTemplate(\app\models\JobTemplate $t): void
            {
            }
        });

        try {
            $job = new SyncProjectJob(['projectId' => $project->id]);
            $job->execute(null); // must not throw
            $project->refresh();
            $this->assertSame(\app\models\Project::STATUS_SYNCED, $project->status);
        } finally {
            \Yii::$app->set('projectService', $originalProjectService);
            \Yii::$app->set('lintService', $originalLintService);
        }
    }

    // -- vault scan phase --------------------------------------------------------

    private function replaceComponent(string $id, object $component): void
    {
        if (!array_key_exists($id, $this->replaced)) {
            $this->replaced[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $component);
    }

    /**
     * Replaces the project, vault scan and lint services with stubs that
     * append each call to $calls, in the order the job makes them.
     *
     * @param \ArrayObject<int, string> $calls
     */
    private function recordCalls(\ArrayObject $calls, ?\Throwable $syncFailure = null, ?\Throwable $scanFailure = null): void
    {
        $this->replaceComponent('projectService', new class ($calls, $syncFailure) extends \yii\base\Component {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private \ArrayObject $calls, private ?\Throwable $failure)
            {
                parent::__construct();
            }

            public function sync(Project $project): void
            {
                $this->calls[] = 'sync';
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                $project->status = Project::STATUS_SYNCED;
                $project->save(false);
            }
        });
        $this->replaceComponent('vaultScanService', new class ($calls, $scanFailure) extends \yii\base\Component {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private \ArrayObject $calls, private ?\Throwable $failure)
            {
                parent::__construct();
            }

            public function scanProject(Project $project): bool
            {
                $this->calls[] = 'vault-scan';
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return true;
            }
        });
        $this->recordLint($calls);
    }

    /**
     * @param \ArrayObject<int, string> $calls
     */
    private function recordLint(\ArrayObject $calls): void
    {
        $this->replaceComponent('lintService', new class ($calls) extends \yii\base\Component {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private \ArrayObject $calls)
            {
                parent::__construct();
            }

            public function runForProject(Project $project): void
            {
                $this->calls[] = 'lint-project';
            }

            public function runForTemplate(JobTemplate $template): void
            {
                $this->calls[] = 'lint-template:' . $template->id;
            }
        });
    }

    private function templateOf(Project $project): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$project->id,
            (int)$this->createInventory((int)$project->created_by)->id,
            (int)$this->createRunnerGroup((int)$project->created_by)->id,
            (int)$project->created_by
        );
    }

    public function testTheVaultScanRunsAfterASuccessfulSyncAndBeforeLint(): void
    {
        $project = $this->createProject((int)$this->createUser()->id);
        $template = $this->templateOf($project);
        $calls = new \ArrayObject();
        $this->recordCalls($calls);

        (new SyncProjectJob(['projectId' => $project->id]))->execute(null);

        $this->assertSame(['sync', 'vault-scan', 'lint-project', 'lint-template:' . $template->id], $calls->getArrayCopy());
    }

    /**
     * The vault scan is opportunistic like lint: whatever it throws, the
     * committed sync stands, the failure is logged, and lint still runs.
     */
    public function testAFailedVaultScanLeavesTheProjectSyncedAndLintStillRuns(): void
    {
        $project = $this->createProject((int)$this->createUser()->id);
        $template = $this->templateOf($project);
        $calls = new \ArrayObject();
        $this->recordCalls($calls, null, new \Error('checkout unreadable'));
        $previousLogger = \Yii::getLogger();
        $logger = new \yii\log\Logger();
        \Yii::setLogger($logger);

        try {
            (new SyncProjectJob(['projectId' => $project->id]))->execute(null);
        } finally {
            \Yii::setLogger($previousLogger);
        }

        $project->refresh();
        $this->assertSame(Project::STATUS_SYNCED, $project->status);
        $this->assertSame(['sync', 'vault-scan', 'lint-project', 'lint-template:' . $template->id], $calls->getArrayCopy());
        $warnings = array_column(array_filter(
            $logger->messages,
            static fn (array $message): bool => $message[1] === \yii\log\Logger::LEVEL_WARNING && $message[2] === SyncProjectJob::class
        ), 0);
        $this->assertSame(
            ["SyncProjectJob: vault scan for project #{$project->id} failed (sync still committed): checkout unreadable"],
            array_values($warnings)
        );
    }

    public function testAFailedSyncRunsNeitherTheVaultScanNorLint(): void
    {
        $project = $this->createProject((int)$this->createUser()->id);
        $this->templateOf($project);
        $calls = new \ArrayObject();
        $this->recordCalls($calls, new \RuntimeException('git clone failed'));

        (new SyncProjectJob(['projectId' => $project->id]))->execute(null);

        $this->assertSame(['sync'], $calls->getArrayCopy());
    }

    /**
     * Regression: a git project without a repository URL stayed on
     * "syncing" until the stale-sync sweeper noticed it. The sync now fails
     * right away, and a failed sync is neither scanned nor linted.
     */
    public function testAGitProjectWithoutAUrlFailsTheSyncAndIsNotScanned(): void
    {
        $project = $this->createProject((int)$this->createUser()->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = '';
        $project->status = Project::STATUS_SYNCING;
        $project->sync_started_at = time();
        $project->save(false);
        $calls = new \ArrayObject();
        $this->replaceComponent('vaultScanService', new class ($calls) extends \yii\base\Component {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private \ArrayObject $calls)
            {
                parent::__construct();
            }

            public function scanProject(Project $project): bool
            {
                $this->calls[] = 'vault-scan';

                return true;
            }
        });
        $this->recordLint($calls);

        (new SyncProjectJob(['projectId' => $project->id]))->execute(null);

        $project->refresh();
        $this->assertSame(Project::STATUS_ERROR, $project->status);
        $this->assertSame('This git project has no repository URL.', $project->last_sync_error);
        $this->assertNull($project->sync_started_at);
        $this->assertSame([], $calls->getArrayCopy());
    }

    /**
     * Regression: the lint loop selected the project's templates with
     * where(), which replaced the scope that hides soft-deleted templates,
     * so deleted templates were linted after every sync.
     */
    public function testSoftDeletedTemplatesAreNotLintedAfterASync(): void
    {
        $project = $this->createProject((int)$this->createUser()->id);
        $active = $this->templateOf($project);
        $deleted = $this->templateOf($project);
        $this->assertTrue($deleted->softDelete());
        $calls = new \ArrayObject();
        $this->recordCalls($calls);

        (new SyncProjectJob(['projectId' => $project->id]))->execute(null);

        $this->assertSame(['sync', 'vault-scan', 'lint-project', 'lint-template:' . $active->id], $calls->getArrayCopy());
    }

    /**
     * End to end with the real project and vault services: syncing a manual
     * project scans its checkout and checks its templates.
     */
    public function testASyncedManualProjectHasItsCheckoutScannedAndItsTemplatesChecked(): void
    {
        $userId = (int)$this->createUser()->id;
        $root = $this->newTree();
        foreach (['inventories/dev/hosts.yml', 'inventories/dev/group_vars/all/vault.yml', 'site.yml', 'vars/secrets.yml'] as $file) {
            $this->put($root, $file, self::fixtureContent('repo/' . $file));
        }
        $project = $this->createProject($userId);
        $project->local_path = $root;
        $project->save(false);
        $inventory = $this->createInventory($userId);
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->project_id = $project->id;
        $inventory->source_path = 'inventories/dev/hosts.yml';
        $inventory->content = null;
        $inventory->save(false);
        $vault = $this->createCredential($userId, Credential::TYPE_VAULT);
        $vault->secret_data = \Yii::$app->get('credentialService')->encryptSecrets(['vault_password' => 'ansilume-test-dummy-dev']);
        $vault->save(false);
        $template = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$this->createRunnerGroup($userId)->id, $userId);
        $template->credential_id = $vault->id;
        $template->save(false);
        $calls = new \ArrayObject();
        $this->recordLint($calls);

        (new SyncProjectJob(['projectId' => $project->id]))->execute(null);

        $project->refresh();
        $this->assertSame(Project::STATUS_SYNCED, $project->status);
        $this->assertNotNull($project->vault_scanned_at);
        $this->assertNull($project->vault_scan_error);
        $this->assertSame(
            ['inventories/dev/group_vars/all/vault.yml', 'vars/secrets.yml'],
            ProjectVaultEntry::find()->select('path')->where(['project_id' => $project->id])->orderBy(['path' => SORT_ASC])->column()
        );
        $check = JobTemplateVaultCheck::findOne($template->id);
        $this->assertNotNull($check);
        $this->assertSame(JobTemplateVaultCheck::STATUS_OK, $check->status);
        $this->assertSame(2, $check->relevant_count);
        $this->assertSame(['lint-project', 'lint-template:' . $template->id], $calls->getArrayCopy());
    }
}
