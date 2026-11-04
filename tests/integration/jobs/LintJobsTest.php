<?php

declare(strict_types=1);

namespace app\tests\integration\jobs;

use app\components\LintState;
use app\components\LintVerdict;
use app\components\StoredLintOutput;
use app\helpers\FileHelper;
use app\jobs\LintProjectJob;
use app\jobs\LintTemplateJob;
use app\jobs\SyncProjectJob;
use app\models\JobTemplate;
use app\models\Project;
use app\services\LintService;
use app\tests\integration\DbTestCase;
use app\tests\integration\RecordingQueue;
use app\tests\integration\RestoresComponents;

/**
 * The jobs of the queue-worker that lint what a request queued
 * (LintTemplateJob, LintProjectJob): they load the record, run lint with
 * the time of the request, which ends it, and do nothing for a record that
 * is gone. A job always runs, also when the mark is clear already. Every
 * result is stored, and every request ends, also that of an output longer
 * than the column holds or one that the database refuses; so is every
 * result of the lint phase of a sync (SyncProjectJob).
 */
class LintJobsTest extends DbTestCase
{
    use RestoresComponents;

    /** The summary ansible-lint writes last, after its findings. */
    private const SUMMARY = "\n# Rule Violation Summary\n\n 60 \e[34mrisky-shell-pipe\e[0m \e[2mprofile:safety tags:command-shell\e[0m\n\n"
        . "\e[31m\e[1mFailed\e[0m\e[0m: 60 failure(s), 0 warning(s) in 1 files processed of 1 encountered.";

    /** What ErrorText says about the database's refusal of an output that is no valid UTF-8. */
    private const REFUSED = 'yii\db\Exception (SQLSTATE 22007, driver error 1366)';

    /** @var LintService&object{runs: list<array{0: string, 1: int, 2: int|null}>} records the runs */
    private LintService $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keepComponent('lintService');
        $this->keepComponent('queue');
        $this->recorder = new class () extends LintService {
            /** @var list<array{0: string, 1: int, 2: int|null}> what ran: kind, ID, requestedAt */
            public array $runs = [];

            public function runForTemplate(JobTemplate $template, ?int $requestedAt = null): void
            {
                $this->runs[] = ['template', (int)$template->id, $requestedAt];
            }

            public function runForProject(Project $project, ?int $requestedAt = null): void
            {
                $this->runs[] = ['project', (int)$project->id, $requestedAt];
            }
        };
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    public function testTheTemplateJobRunsLintWithTheTimeOfTheRequest(): void
    {
        \Yii::$app->set('lintService', $this->recorder);
        $template = $this->template();

        (new LintTemplateJob(['templateId' => (int)$template->id, 'requestedAt' => 1700000000]))->execute(null);

        $this->assertSame([['template', (int)$template->id, 1700000000]], $this->recorder->runs);
    }

    public function testTheProjectJobRunsLintWithTheTimeOfTheRequest(): void
    {
        \Yii::$app->set('lintService', $this->recorder);
        $project = $this->createProject((int)$this->createUser('lint-job-project')->id);

        (new LintProjectJob(['projectId' => (int)$project->id, 'requestedAt' => 1700000000]))->execute(null);

        $this->assertSame([['project', (int)$project->id, 1700000000]], $this->recorder->runs);
    }

    /**
     * A template deleted meanwhile (soft-deleted, hidden by
     * JobTemplate::find()) and a record that does not exist: nothing to
     * lint, and no error.
     */
    public function testARecordThatIsGoneIsNotLinted(): void
    {
        \Yii::$app->set('lintService', $this->recorder);
        $deleted = $this->template();
        $this->assertTrue($deleted->softDelete());

        (new LintTemplateJob(['templateId' => (int)$deleted->id, 'requestedAt' => 1700000000]))->execute(null);
        (new LintTemplateJob(['templateId' => 999999999, 'requestedAt' => 1700000000]))->execute(null);
        (new LintProjectJob(['projectId' => 999999999, 'requestedAt' => 1700000000]))->execute(null);

        $this->assertSame([], $this->recorder->runs);
    }

    /**
     * The whole way, as the queue-worker takes it: the request queues, the
     * worker unserializes the job through the allowlist and runs it, and
     * the run stores its result and ends the request.
     */
    public function testAQueuedRunStoresItsResultAndEndsTheRequest(): void
    {
        $queue = new RecordingQueue();
        \Yii::$app->set('queue', $queue);
        \Yii::$app->set('lintService', new LintService());
        $template = $this->template();
        $project = $template->project;
        $project->local_path = '/tmp/ansilume_lint_job_missing_' . uniqid('', true);
        $project->save(false);
        /** @var LintService $lint */
        $lint = \Yii::$app->get('lintService');

        $this->assertTrue($lint->queueForTemplate($template));
        $this->assertTrue($lint->queueForProject($project));
        $this->assertNotNull(JobTemplate::findOne($template->id)?->lint_queued_at);
        $queue->run();

        $storedTemplate = JobTemplate::findOne($template->id);
        $this->assertNotNull($storedTemplate);
        $this->assertNull($storedTemplate->lint_queued_at, 'the run ended the request');
        $this->assertSame(sprintf(LintService::PATH_NOT_FOUND, $project->local_path), $storedTemplate->lint_output);
        $storedProject = Project::findOne($project->id);
        $this->assertNotNull($storedProject);
        $this->assertNull($storedProject->lint_queued_at);
        $this->assertSame(sprintf(LintService::PATH_NOT_FOUND, $project->local_path), $storedProject->lint_output);
    }

    /**
     * A job whose mark is clear already, as after a newer run served it,
     * still runs: the run decides, never the mark.
     */
    public function testAJobRunsAlsoWhenTheMarkIsClear(): void
    {
        \Yii::$app->set('lintService', new LintService());
        $template = $this->template();
        $this->assertNull($template->lint_queued_at);

        (new LintTemplateJob(['templateId' => (int)$template->id, 'requestedAt' => 1700000000]))->execute(null);

        $this->assertSame(LintService::NO_LOCAL_PATH, JobTemplate::findOne($template->id)?->lint_output);
    }

    /**
     * Regression (review of the lint queue): an output of more than the
     * 65,535 bytes that the TEXT column lint_output holds failed the UPDATE
     * that stores the result and clears the mark (SQLSTATE 22001, 1406 Data
     * too long: the database runs in strict mode). The queue job failed,
     * and the card showed "Lint queued" for good, after every new save or
     * Run Lint too; ansible-lint wrote 71,194 bytes for 60 legacy tasks.
     * The run now stores the start and the end of the output
     * (StoredLintOutput) and ends the request, of a template and of a
     * project, run as the queue-worker runs it.
     */
    public function testRegressionAnOutputOverTheColumnIsStoredCutAndEndsTheRequest(): void
    {
        $queue = new RecordingQueue();
        \Yii::$app->set('queue', $queue);
        $output = self::lintOutput(71194);
        \Yii::$app->set('lintService', self::linting($output, 2));
        $template = $this->template();
        $dir = $this->checkoutOf($template);
        try {
            /** @var LintService $lint */
            $lint = \Yii::$app->get('lintService');
            $this->assertTrue($lint->queueForTemplate($template));
            $this->assertTrue($lint->queueForProject($template->project));
            $queue->run();

            foreach ([JobTemplate::findOne($template->id), Project::findOne($template->project_id)] as $stored) {
                $this->assertNotNull($stored);
                $this->assertNull($stored->lint_queued_at, 'the run ended the request');
                $this->assertStoredOutput(StoredLintOutput::of($output), $stored->lint_output, 'the start and the end of the output');
                $this->assertLessThanOrEqual(StoredLintOutput::MAX_BYTES, strlen((string)$stored->lint_output));
                $this->assertStringEndsWith(self::SUMMARY, (string)$stored->lint_output, 'with the summary');
                $this->assertSame(2, (int)$stored->lint_exit_code);
                $this->assertSame(LintVerdict::ISSUES, LintState::of($stored)->status());
                $this->assertSame(['queued' => false, 'lint_at' => (int)$stored->lint_at], LintState::of($stored)->poll());
            }
        } finally {
            $this->removeCheckout($dir);
        }
    }

    /**
     * Regression (review of the lint queue): a result that the database
     * refuses all the same, as an output that is no valid UTF-8 (1366
     * Incorrect string value), left the mark too. The run stores why
     * instead (LintService::NOT_STORED: the error as ErrorText gives it,
     * never the SQL with the output) and ends the request; the exit code is
     * ansible-lint's.
     */
    public function testRegressionAResultTheDatabaseRefusesEndsTheRequestWithWhy(): void
    {
        $queue = new RecordingQueue();
        \Yii::$app->set('queue', $queue);
        \Yii::$app->set('lintService', self::linting("name[missing]: All tasks should be named.\nsite.yml:5 Task/Handler: \xff\xfe", 2));
        $template = $this->template();
        $dir = $this->checkoutOf($template);
        try {
            /** @var LintService $lint */
            $lint = \Yii::$app->get('lintService');
            $this->assertTrue($lint->queueForTemplate($template));
            $this->assertTrue($lint->queueForProject($template->project));
            $queue->run();

            foreach ([JobTemplate::findOne($template->id), Project::findOne($template->project_id)] as $stored) {
                $this->assertNotNull($stored);
                $this->assertNull($stored->lint_queued_at, 'the run ended the request');
                $this->assertSame(sprintf(LintService::NOT_STORED, self::REFUSED), $stored->lint_output);
                $this->assertSame(2, (int)$stored->lint_exit_code);
                $this->assertNotNull($stored->lint_at);
            }
        } finally {
            $this->removeCheckout($dir);
        }
    }

    /**
     * Regression (review of the lint queue): in the lint phase of a sync,
     * the project's output over the column threw out of runForProject(),
     * and the job templates of the project were not linted at all. Every
     * run of the phase stores its result now.
     */
    public function testRegressionTheLintPhaseOfASyncLintsEveryTemplateAfterAnOutputOverTheColumn(): void
    {
        $this->keepComponent('projectService');
        $this->keepComponent('vaultScanService');
        \Yii::$app->set('projectService', new class () extends \yii\base\Component {
            public function sync(Project $project): void
            {
            }
        });
        \Yii::$app->set('vaultScanService', new class () extends \yii\base\Component {
            public function scanProject(Project $project): bool
            {
                return true;
            }
        });
        $output = self::lintOutput(80000);
        \Yii::$app->set('lintService', self::linting($output, 2));
        $first = $this->template();
        $dir = $this->checkoutOf($first);
        $second = $this->createJobTemplate((int)$first->project_id, (int)$first->inventory_id, (int)$first->runner_group_id, (int)$first->created_by);
        try {
            (new SyncProjectJob(['projectId' => (int)$first->project_id]))->execute(null);

            foreach ([Project::findOne($first->project_id), JobTemplate::findOne($first->id), JobTemplate::findOne($second->id)] as $stored) {
                $this->assertNotNull($stored);
                $this->assertStoredOutput(StoredLintOutput::of($output), $stored->lint_output, $stored::class . ' #' . $stored->id);
                $this->assertSame(2, (int)$stored->lint_exit_code);
            }
        } finally {
            $this->removeCheckout($dir);
        }
    }

    /**
     * $actual is $expected, compared without a diff of some 64 KB on failure.
     */
    private function assertStoredOutput(string $expected, mixed $actual, string $what): void
    {
        $this->assertIsString($actual, $what);
        $this->assertSame(strlen($expected), strlen($actual), $what . ': its length');
        $this->assertTrue($expected === $actual, $what);
    }

    private function template(): JobTemplate
    {
        $user = (int)$this->createUser('lint-job')->id;

        return $this->createJobTemplate(
            (int)$this->createProject($user)->id,
            (int)$this->createInventory($user)->id,
            (int)$this->createRunnerGroup($user)->id,
            $user
        );
    }

    /**
     * A directory with the playbook of $template, which becomes the local
     * path of its (manual) project.
     */
    private function checkoutOf(JobTemplate $template): string
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_job_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/' . $template->playbook, "---\n- hosts: all\n");
        $project = $template->project;
        $project->local_path = $dir;
        $project->save(false);

        return $dir;
    }

    private function removeCheckout(string $dir): void
    {
        FileHelper::safeUnlink($dir . '/site.yml');
        FileHelper::safeRmdir($dir);
    }

    /**
     * A LintService whose ansible-lint is installed and writes $output,
     * exiting with $exitCode.
     */
    private static function linting(string $output, int $exitCode): LintService
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

    /**
     * An output as ansible-lint writes it for many findings, of at least
     * $bytes bytes: findings of three lines each, then the summary.
     */
    private static function lintOutput(int $bytes): string
    {
        $findings = '';
        for ($i = 1; strlen($findings) < $bytes; $i++) {
            $findings .= "\e[31m\e[34mrisky-shell-pipe\e[0m\e[2m:\e[0m \e[31mShells that use pipes should set the pipefail option.\e[0m\n"
                . "\e[35msite.yml\e[0m:" . ($i * 2 + 3) . " \e[2mTask/Handler: shell echo {$i} | grep {$i}\e[0m\n\e[0m\n";
        }

        return $findings . self::SUMMARY;
    }
}
