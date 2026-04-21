<?php

declare(strict_types=1);

namespace app\tests\integration\jobs;

use app\jobs\JobTimeoutException;
use app\jobs\RunAnsibleJob;
use app\models\Job;
use app\models\JobLog;
use app\models\JobTask;
use app\services\JobCompletionService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for RunAnsibleJob — methods that require a real database.
 *
 * Task persistence tests target JobCompletionService::saveTasks() since
 * RunAnsibleJob delegates to it.
 */
class RunAnsibleJobIntegrationTest extends DbTestCase
{
    /** @var array<string, mixed> */
    private array $swappedComponents = [];

    protected function tearDown(): void
    {
        foreach ($this->swappedComponents as $id => $original) {
            \Yii::$app->set($id, $original);
        }
        $this->swappedComponents = [];
        parent::tearDown();
    }

    /**
     * Snapshot a component's current definition and swap in a stub.
     * tearDown() restores the original so later tests in the same process
     * (notably JobClaimServiceIntegrationTest) see the bootstrap wiring.
     *
     * @param mixed $stub
     */
    private function swapComponent(string $id, $stub): void
    {
        $components = \Yii::$app->getComponents(true);
        if (!array_key_exists($id, $this->swappedComponents)) {
            $this->swappedComponents[$id] = $components[$id] ?? null;
        }
        \Yii::$app->set($id, $stub);
    }

    // -------------------------------------------------------------------------
    // saveTasks (via JobCompletionService, called by RunAnsibleJob)
    // -------------------------------------------------------------------------

    public function testSaveTasksCreatesJobTaskRecords(): void
    {
        $job = $this->makeRunningJob();
        $service = new JobCompletionService();
        $tasks = [
            ['seq' => 1, 'name' => 'Gather facts', 'action' => 'setup', 'host' => 'web1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 500],
            ['seq' => 2, 'name' => 'Install pkg', 'action' => 'apt', 'host' => 'web1', 'status' => 'ok', 'changed' => true, 'duration_ms' => 3200],
        ];

        $service->saveTasks($job, $tasks);

        $this->assertSame(2, (int)JobTask::find()->where(['job_id' => $job->id])->count());
        $job->refresh();
        $this->assertSame(1, (int)$job->has_changes);
    }

    public function testSaveTasksNoChangesWhenNoneReported(): void
    {
        $job = $this->makeRunningJob();
        $service = new JobCompletionService();
        $tasks = [
            ['seq' => 1, 'name' => 'Gather facts', 'action' => 'setup', 'host' => 'web1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 500],
        ];

        $service->saveTasks($job, $tasks);

        $job->refresh();
        $this->assertSame(0, (int)$job->has_changes);
    }

    public function testSaveTasksHandlesEmptyArray(): void
    {
        $job = $this->makeRunningJob();
        $service = new JobCompletionService();

        $service->saveTasks($job, []);

        $this->assertSame(0, (int)JobTask::find()->where(['job_id' => $job->id])->count());
    }

    public function testSaveTasksSetsCorrectFields(): void
    {
        $job = $this->makeRunningJob();
        $service = new JobCompletionService();
        $tasks = [
            ['seq' => 5, 'name' => 'Deploy app', 'action' => 'copy', 'host' => 'srv1', 'status' => 'ok', 'changed' => true, 'duration_ms' => 1500],
        ];

        $service->saveTasks($job, $tasks);

        $task = JobTask::find()->where(['job_id' => $job->id])->one();
        $this->assertNotNull($task);
        $this->assertSame(5, $task->sequence);
        $this->assertSame('Deploy app', $task->task_name);
        $this->assertSame('copy', $task->task_action);
        $this->assertSame('srv1', $task->host);
        $this->assertSame('ok', $task->status);
        $this->assertSame(1, (int)$task->changed);
        $this->assertSame(1500, (int)$task->duration_ms);
    }

    public function testSaveTasksHandlesMultipleHosts(): void
    {
        $job = $this->makeRunningJob();
        $service = new JobCompletionService();
        $tasks = [
            ['seq' => 1, 'name' => 'ping', 'action' => 'ping', 'host' => 'web1', 'status' => 'ok', 'changed' => false, 'duration_ms' => 10],
            ['seq' => 2, 'name' => 'ping', 'action' => 'ping', 'host' => 'web2', 'status' => 'ok', 'changed' => false, 'duration_ms' => 12],
            ['seq' => 3, 'name' => 'ping', 'action' => 'ping', 'host' => 'db1', 'status' => 'unreachable', 'changed' => false, 'duration_ms' => 5000],
        ];

        $service->saveTasks($job, $tasks);

        $records = JobTask::find()->where(['job_id' => $job->id])->orderBy('sequence')->all();
        $this->assertCount(3, $records);
        $this->assertSame('web1', $records[0]->host);
        $this->assertSame('web2', $records[1]->host);
        $this->assertSame('db1', $records[2]->host);
        $this->assertSame('unreachable', $records[2]->status);
    }

    public function testSaveTasksDefaultsForMissingFields(): void
    {
        $job = $this->makeRunningJob();
        $service = new JobCompletionService();
        $tasks = [['seq' => 0]];

        $service->saveTasks($job, $tasks);

        $task = JobTask::find()->where(['job_id' => $job->id])->one();
        $this->assertNotNull($task);
        $this->assertSame('', $task->task_name);
        $this->assertSame('', $task->task_action);
        $this->assertSame('', $task->host);
        $this->assertSame('ok', $task->status);
        $this->assertSame(0, (int)$task->changed);
        $this->assertSame(0, (int)$task->duration_ms);
    }

    // -------------------------------------------------------------------------
    // parseCallbackFile (via testable subclass)
    // -------------------------------------------------------------------------

    public function testParseCallbackFileSkipsMalformedJson(): void
    {
        $file = sys_get_temp_dir() . '/ansilume_test_cb_' . uniqid('', true) . '.ndjson';
        file_put_contents($file, "not valid json\n" . json_encode(['seq' => 1]) . "\n{broken\n");

        $runner = new TestableRunAnsibleJobDb();
        $tasks = $runner->parseCallbackFile($file);
        unlink($file);

        $this->assertCount(1, $tasks);
        $this->assertSame(1, $tasks[0]['seq']);
    }

    // -------------------------------------------------------------------------
    // execute — guard clauses
    // -------------------------------------------------------------------------

    public function testExecuteSkipsNonExistentJob(): void
    {
        $runner = new RunAnsibleJob(['jobId' => 999999]);
        // Should not throw, just log and return
        $runner->execute(null);
        $this->assertTrue(true);
    }

    public function testExecuteSkipsJobInWrongStatus(): void
    {
        $job = $this->makeJobWithStatus(Job::STATUS_SUCCEEDED);
        $runner = new RunAnsibleJob(['jobId' => $job->id]);

        $runner->execute(null);

        $job->refresh();
        // Status should remain unchanged
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
    }

    public function testExecuteSkipsRunningJob(): void
    {
        $job = $this->makeJobWithStatus(Job::STATUS_RUNNING);
        $runner = new RunAnsibleJob(['jobId' => $job->id]);

        $runner->execute(null);

        $job->refresh();
        $this->assertSame(Job::STATUS_RUNNING, $job->status);
    }

    // -------------------------------------------------------------------------
    // execute — full run with stubbed playbook + side-effect services
    // -------------------------------------------------------------------------

    public function testExecuteHappyPathTransitionsToRunningAndFinished(): void
    {
        $this->swapWebhookServiceWithStub();
        $job = $this->makeJobWithStatus(Job::STATUS_QUEUED);

        $runner = new class (['jobId' => $job->id]) extends RunAnsibleJob {
            protected function runPlaybook(Job $job): int
            {
                // Seed a JobTask inside the playbook stub so the no-op
                // safeguard in JobCompletionService::complete() doesn't flip
                // this to FAILED. A real playbook run produces task rows
                // via the callback plugin before complete() is called.
                $task = new JobTask();
                $task->job_id = $job->id;
                $task->sequence = 0;
                $task->task_name = 'seeded';
                $task->task_action = 'debug';
                $task->host = 'localhost';
                $task->status = 'ok';
                $task->changed = 0;
                $task->duration_ms = 0;
                $task->save(false);
                return 0;
            }
        };
        $runner->execute(null);

        $job->refresh();
        // JobCompletionService sets succeeded when exitCode=0 and at least one host actually ran.
        $this->assertSame(Job::STATUS_SUCCEEDED, $job->status);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->worker_id);
    }

    public function testExecuteCatchesTimeoutException(): void
    {
        $this->swapWebhookServiceWithStub();
        $job = $this->makeJobWithStatus(Job::STATUS_QUEUED);

        $runner = new class (['jobId' => $job->id]) extends RunAnsibleJob {
            protected function runPlaybook(Job $job): int
            {
                throw new JobTimeoutException(5);
            }
        };
        $runner->execute(null);

        $job->refresh();
        // Should have transitioned via completeTimedOut.
        $this->assertNotSame(Job::STATUS_RUNNING, $job->status);
        $this->assertNotSame(Job::STATUS_QUEUED, $job->status);

        // appendLog wrote a stderr line mentioning the timeout.
        $log = JobLog::find()->where(['job_id' => $job->id])->andWhere(['stream' => JobLog::STREAM_STDERR])->one();
        $this->assertNotNull($log);
        $this->assertStringContainsString('timed out', $log->content);
    }

    public function testExecuteCatchesGenericThrowable(): void
    {
        $this->swapWebhookServiceWithStub();
        $job = $this->makeJobWithStatus(Job::STATUS_QUEUED);

        $runner = new class (['jobId' => $job->id]) extends RunAnsibleJob {
            protected function runPlaybook(Job $job): int
            {
                throw new \RuntimeException('boom');
            }
        };
        $runner->execute(null);

        $job->refresh();
        $this->assertSame(Job::STATUS_FAILED, $job->status);

        $log = JobLog::find()->where(['job_id' => $job->id])->andWhere(['stream' => JobLog::STREAM_STDERR])->one();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Runner error: boom', $log->content);
    }

    // -------------------------------------------------------------------------
    // saveTaskResults (private) — via reflection
    // -------------------------------------------------------------------------

    public function testSaveTaskResultsNoOpWhenFileMissing(): void
    {
        $job = $this->makeRunningJob();
        $runner = new RunAnsibleJob();

        $ref = new \ReflectionMethod($runner, 'saveTaskResults');
        $ref->setAccessible(true);
        $ref->invoke($runner, $job, '/tmp/definitely-not-there-' . uniqid('', true) . '.ndjson');

        $this->assertSame(0, (int)JobTask::find()->where(['job_id' => $job->id])->count());
    }

    public function testSaveTaskResultsParsesFileAndPersistsTasks(): void
    {
        $job = $this->makeRunningJob();
        $runner = new RunAnsibleJob();

        $file = sys_get_temp_dir() . '/ansilume_test_cb_' . uniqid('', true) . '.ndjson';
        file_put_contents(
            $file,
            json_encode(['seq' => 1, 'name' => 'ping', 'host' => 'h1', 'status' => 'ok', 'changed' => false]) . "\n"
        );

        $ref = new \ReflectionMethod($runner, 'saveTaskResults');
        $ref->setAccessible(true);
        $ref->invoke($runner, $job, $file);

        $this->assertSame(1, (int)JobTask::find()->where(['job_id' => $job->id])->count());
        $this->assertFileDoesNotExist($file); // cleaned up in finally
    }

    // -------------------------------------------------------------------------
    // writeInventoryTempFile (private)
    // -------------------------------------------------------------------------

    public function testWriteInventoryTempFileCreatesFileWithContent(): void
    {
        $runner = new RunAnsibleJob();
        $ref = new \ReflectionMethod($runner, 'writeInventoryTempFile');
        $ref->setAccessible(true);

        /** @var string $path */
        $path = $ref->invoke($runner, "localhost\n");
        try {
            $this->assertFileExists($path);
            $this->assertSame("localhost\n", (string)file_get_contents($path));
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    // -------------------------------------------------------------------------
    // appendLog (private)
    // -------------------------------------------------------------------------

    public function testAppendLogPersistsEntry(): void
    {
        $job = $this->makeRunningJob();
        $runner = new RunAnsibleJob();
        $ref = new \ReflectionMethod($runner, 'appendLog');
        $ref->setAccessible(true);
        $ref->invoke($runner, $job, JobLog::STREAM_STDOUT, 'hello world', 1);

        $log = JobLog::find()->where(['job_id' => $job->id])->one();
        $this->assertNotNull($log);
        $this->assertSame('hello world', $log->content);
        $this->assertSame(JobLog::STREAM_STDOUT, $log->stream);
    }

    // -------------------------------------------------------------------------
    // runPlaybook — via factory-method stubs
    // -------------------------------------------------------------------------

    public function testRunPlaybookInvokesProcessAndArtifactCollectorForStaticInventory(): void
    {
        $job = $this->makeRunningJob();
        $this->swapJobClaimService([
            'command' => ['ansible-playbook', '__INVENTORY_TMP__', 'site.yml'],
            'project_path' => '/tmp/project',
            'timeout_minutes' => 30,
            'inventory_type' => 'static',
            'inventory_content' => "localhost ansible_connection=local\n",
            'credential' => null,
        ]);

        $runner = new class extends RunAnsibleJob {
            /** @var array<int, mixed> */
            public array $receivedCmd = [];
            public bool $collectCalled = false;

            protected function createAnsibleJobProcess(): \app\components\AnsibleJobProcess
            {
                return new class extends \app\components\AnsibleJobProcess {
                    public function run(\app\models\Job $job, array $cmd, array $payload, array $env, int $timeoutMinutes): int
                    {
                        $GLOBALS['__rap_cmd'] = $cmd;
                        return 0;
                    }
                };
            }

            protected function createArtifactCollector(): \app\components\ArtifactCollector
            {
                $self = $this;
                return new class ($self) extends \app\components\ArtifactCollector {
                    /** @var object */
                    private $parent;
                    public function __construct(object $parent)
                    {
                        $this->parent = $parent;
                    }
                    public function collect(\app\models\Job $job, array $env): void
                    {
                        $this->parent->collectCalled = true;
                    }
                };
            }

            protected function createCredentialInjector(): \app\components\CredentialInjector
            {
                return new class extends \app\components\CredentialInjector {
                    public function inject(?array $credentialData): \app\components\CredentialInjectionResult
                    {
                        return \app\components\CredentialInjectionResult::empty();
                    }
                };
            }
        };

        $ref = new \ReflectionMethod($runner, 'runPlaybook');
        $ref->setAccessible(true);
        $exit = $ref->invoke($runner, $job);

        $this->assertSame(0, $exit);
        $this->assertTrue($runner->collectCalled);
        // Placeholder was replaced with an actual temp file path.
        $cmd = $GLOBALS['__rap_cmd'] ?? [];
        $this->assertNotContains('__INVENTORY_TMP__', $cmd);
        // Path should look like a writable temp file and should be cleaned up.
        $invPath = $cmd[1] ?? '';
        $this->assertStringContainsString('ansilume_inv_', $invPath);
        $this->assertFileDoesNotExist($invPath);
        unset($GLOBALS['__rap_cmd']);
    }

    public function testRunPlaybookWrapsCommandInDockerWhenRunnerModeDocker(): void
    {
        $originalMode = $_ENV['RUNNER_MODE'] ?? null;
        $_ENV['RUNNER_MODE'] = 'docker';
        try {
            $job = $this->makeRunningJob();
            $this->swapJobClaimService([
                'command' => ['ansible-playbook', 'site.yml'],
                'project_path' => '/tmp/project',
                'timeout_minutes' => 30,
                'inventory_type' => 'dynamic',
                'inventory_content' => '',
                'credential' => null,
            ]);

            $runner = new class extends RunAnsibleJob {
                protected function createAnsibleJobProcess(): \app\components\AnsibleJobProcess
                {
                    return new class extends \app\components\AnsibleJobProcess {
                        public function run(\app\models\Job $job, array $cmd, array $payload, array $env, int $timeoutMinutes): int
                        {
                            $GLOBALS['__rap_cmd'] = $cmd;
                            return 0;
                        }
                    };
                }
                protected function createArtifactCollector(): \app\components\ArtifactCollector
                {
                    return new class extends \app\components\ArtifactCollector {
                        public function collect(\app\models\Job $job, array $env): void
                        {
                        }
                    };
                }
                protected function createCredentialInjector(): \app\components\CredentialInjector
                {
                    return new class extends \app\components\CredentialInjector {
                        public function inject(?array $credentialData): \app\components\CredentialInjectionResult
                        {
                            return \app\components\CredentialInjectionResult::empty();
                        }
                    };
                }
            };

            $ref = new \ReflectionMethod($runner, 'runPlaybook');
            $ref->setAccessible(true);
            $ref->invoke($runner, $job);

            $cmd = $GLOBALS['__rap_cmd'] ?? [];
            $this->assertSame('docker', $cmd[0]);
            $this->assertSame('run', $cmd[1]);
            unset($GLOBALS['__rap_cmd']);
        } finally {
            if ($originalMode === null) {
                unset($_ENV['RUNNER_MODE']);
            } else {
                $_ENV['RUNNER_MODE'] = $originalMode;
            }
        }
    }

    /**
     * Replace jobClaimService so runPlaybook gets a deterministic payload.
     *
     * @param array<string, mixed> $payload
     */
    private function swapJobClaimService(array $payload): void
    {
        $this->swapComponent('jobClaimService', new class ($payload) extends \yii\base\Component {
            /** @var array<string, mixed> */
            private array $payload;
            /** @param array<string, mixed> $payload */
            public function __construct(array $payload)
            {
                parent::__construct();
                $this->payload = $payload;
            }
            /** @return array<string, mixed> */
            public function buildExecutionPayload(\app\models\Job $job): array
            {
                return $this->payload;
            }
        });
    }

    /**
     * Replace webhookService with a silent stub so dispatch() inside
     * transitionToRunning() doesn't try to hit real HTTP endpoints.
     */
    private function swapWebhookServiceWithStub(): void
    {
        $this->swapComponent('webhookService', new class extends \yii\base\Component {
            public function dispatch(string $event, $payload): void
            {
            }
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRunningJob(): Job
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        return $this->createJob($template->id, $user->id, Job::STATUS_RUNNING);
    }

    private function makeJobWithStatus(string $status): Job
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
        return $this->createJob($template->id, $user->id, $status);
    }
}

/**
 * Testable subclass exposing protected methods for integration tests.
 */
class TestableRunAnsibleJobDb extends RunAnsibleJob // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
{
    public function parseCallbackFile(string $callbackFile): array
    {
        return parent::parseCallbackFile($callbackFile);
    }
}
