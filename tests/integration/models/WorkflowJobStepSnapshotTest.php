<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Job;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowExecutionService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\ModelEvent;
use yii\db\ActiveRecord;

/**
 * The snapshot of a step execution (step_name, step_type of m000084): what
 * the step was when it ran, read through stepName() and stepType(), and the
 * checks built on the type, isApprovalPlaceholder() and isPauseStep().
 */
class WorkflowJobStepSnapshotTest extends DbTestCase
{
    use TeamScopeFixtures;

    private int $userId;
    private int $workflowId;
    private int $runId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('wjs_snapshot')->id;
        $this->workflowId = (int)$this->createWorkflowTemplate($this->userId)->id;
        $run = new WorkflowJob();
        $run->workflow_template_id = $this->workflowId;
        $run->launched_by = $this->userId;
        $run->status = WorkflowJob::STATUS_RUNNING;
        $this->assertTrue($run->save(false));
        $this->runId = (int)$run->id;
    }

    /**
     * A step the workflow engine starts keeps its name and type, also once
     * the step is removed while the run waits at it.
     */
    public function testAStepTheEngineStartsKeepsItsStep(): void
    {
        $launcher = $this->createUserWithRole('wjs_engine', 'operator');
        $workflow = WorkflowTemplate::findOne($this->workflowId);
        $this->assertNotNull($workflow);
        $pause = $this->step('wait for the window', WorkflowStep::TYPE_PAUSE);
        /** @var WorkflowExecutionService $engine */
        $engine = \Yii::$app->get('workflowExecutionService');

        $run = $engine->launch($workflow, (int)$launcher->id, [], 'api');

        $execution = WorkflowJobStep::findOne(['workflow_job_id' => $run->id]);
        $this->assertNotNull($execution);
        $this->assertSame(['wait for the window', WorkflowStep::TYPE_PAUSE], $this->stored($execution));
        $this->assertSame(1, $pause->delete());
        $kept = $this->reload($execution);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $kept->status);
        $this->assertSame('wait for the window', $kept->stepName());
        $this->assertTrue($kept->isPauseStep());
    }

    public function testANewExecutionKeepsItsStepAsItWasWhenSaved(): void
    {
        $step = $this->step('build', WorkflowStep::TYPE_PAUSE);

        $execution = $this->saved($this->newExecution($step));

        $this->assertSame(['build', WorkflowStep::TYPE_PAUSE], $this->stored($execution));
        $step->name = 'renamed later';
        $step->save(false, ['name']);
        $reloaded = $this->reload($execution);
        $this->assertSame('build', $reloaded->stepName(), 'the name when the step ran');
        $this->assertSame(WorkflowStep::TYPE_PAUSE, $reloaded->stepType());
        $this->assertTrue($reloaded->isPauseStep());
    }

    /**
     * snapshotStep() sets both columns; saving then needs no query for the
     * live step.
     */
    public function testSnapshotStepCopiesTheStepWithoutLoadingItAgain(): void
    {
        $step = $this->step('approve', WorkflowStep::TYPE_APPROVAL);
        $execution = $this->newExecution($step);

        $execution->snapshotStep($step);
        $this->saved($execution);

        $this->assertFalse($execution->isRelationPopulated('workflowStep'), 'beforeSave() did not load the step');
        $this->assertSame(['approve', WorkflowStep::TYPE_APPROVAL], $this->stored($execution));
    }

    public function testSnapshotStepOverwritesAndBeforeSaveFillsOnlyEmptyColumns(): void
    {
        $step = $this->step('deploy', WorkflowStep::TYPE_JOB);
        $other = $this->step('other', WorkflowStep::TYPE_PAUSE);
        $execution = $this->newExecution($step);
        $execution->snapshotStep($other);
        $execution->snapshotStep($step);
        $this->assertSame(['deploy', WorkflowStep::TYPE_JOB], [$execution->step_name, $execution->step_type]);

        $partial = $this->newExecution($step);
        $partial->step_name = 'kept as set';
        $this->saved($partial);

        $this->assertSame(['kept as set', WorkflowStep::TYPE_JOB], $this->stored($partial));
    }

    /**
     * An update fills a snapshot that is empty, from the live step.
     */
    public function testAnUpdateFillsAnEmptySnapshot(): void
    {
        $step = $this->step('verify', WorkflowStep::TYPE_JOB);
        $execution = $this->saved($this->newExecution($step));
        WorkflowJobStep::updateAll(['step_name' => '', 'step_type' => null], ['id' => $execution->id]);
        $loaded = $this->reload($execution);

        $loaded->status = WorkflowJobStep::STATUS_SUCCEEDED;
        $this->saved($loaded);

        $this->assertSame(['verify', WorkflowStep::TYPE_JOB], $this->stored($loaded));
    }

    /**
     * Without a snapshot (an empty one, also ''), the live step answers.
     */
    public function testWithoutASnapshotTheLiveStepAnswers(): void
    {
        $step = $this->step('live', WorkflowStep::TYPE_APPROVAL);
        $execution = $this->saved($this->newExecution($step));
        WorkflowJobStep::updateAll(['step_name' => '', 'step_type' => null], ['id' => $execution->id]);

        $loaded = $this->reload($execution);

        $this->assertSame('live', $loaded->stepName());
        $this->assertSame(WorkflowStep::TYPE_APPROVAL, $loaded->stepType());
        $this->assertTrue($loaded->isApprovalPlaceholder());
    }

    public function testWithoutSnapshotAndStepNothingIsKnown(): void
    {
        $step = $this->step('gone', WorkflowStep::TYPE_PAUSE);
        $execution = $this->saved($this->newExecution($step));
        WorkflowJobStep::updateAll(['step_name' => null, 'step_type' => null], ['id' => $execution->id]);
        $this->assertSame(1, $step->delete());

        $loaded = $this->reload($execution);

        $this->assertNull($loaded->workflow_step_id);
        $this->assertSame(WorkflowJobStep::UNKNOWN_STEP_NAME, $loaded->stepName());
        $this->assertSame('—', WorkflowJobStep::UNKNOWN_STEP_NAME);
        $this->assertNull($loaded->stepType());
        $this->assertFalse($loaded->isApprovalPlaceholder());
        $this->assertFalse($loaded->isPauseStep());
        $loaded->status = WorkflowJobStep::STATUS_FAILED;
        $this->saved($loaded);
        $this->assertSame([null, null], $this->stored($loaded), 'nothing to fill from');
    }

    /**
     * The type is compared exactly, as dispatch compares it: a legacy type
     * such as APPROVAL is no approval step.
     *
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function typeProvider(): array
    {
        return [
            'approval' => [WorkflowStep::TYPE_APPROVAL, true, false],
            'pause' => [WorkflowStep::TYPE_PAUSE, false, true],
            'job' => [WorkflowStep::TYPE_JOB, false, false],
            'APPROVAL' => ['APPROVAL', false, false],
            'approval with a trailing space' => ['approval ', false, false],
            'Pause' => ['Pause', false, false],
            'the text 1' => ['1', false, false],
        ];
    }

    /**
     * @dataProvider typeProvider
     */
    public function testTheStepTypeDecidesWhetherItIsAnApprovalOrPauseStep(string $type, bool $approval, bool $pause): void
    {
        $execution = $this->saved($this->newExecution($this->step('typed', $type)));

        $reloaded = $this->reload($execution);

        $this->assertSame($type, $reloaded->stepType(), 'copied as stored');
        $this->assertSame($approval, $reloaded->isApprovalPlaceholder());
        $this->assertSame($pause, $reloaded->isPauseStep());
    }

    /**
     * The type decides, never the job: the job of a job step is a real job,
     * also when its template needs an approval, or when its template was
     * deleted and the job lost it.
     */
    public function testTheJobOfAJobStepIsNoPlaceholder(): void
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        $template->approval_rule_id = (int)$this->createApprovalRule($this->userId)->id;
        $template->save(false, ['approval_rule_id']);
        $step = $this->step('gated job', WorkflowStep::TYPE_JOB, (int)$template->id);
        $gated = $this->createJob((int)$template->id, $this->userId, Job::STATUS_PENDING_APPROVAL);
        $orphan = $this->createJob((int)$template->id, $this->userId, Job::STATUS_PENDING_APPROVAL);
        $orphan->job_template_id = null;
        $orphan->save(false, ['job_template_id']);

        foreach ([$gated, $orphan] as $job) {
            $execution = $this->newExecution($step);
            $execution->job_id = (int)$job->id;
            $this->assertFalse($this->reload($this->saved($execution))->isApprovalPlaceholder(), "job #{$job->id}");
        }
    }

    /**
     * A new execution needs its step; a stored one keeps its history when
     * the step is deleted and still validates.
     */
    public function testTheStepIsRequiredForNewExecutionsOnly(): void
    {
        $new = new WorkflowJobStep();
        $new->workflow_job_id = $this->runId;
        $new->status = WorkflowJobStep::STATUS_RUNNING;
        $this->assertFalse($new->validate());
        $this->assertSame(['Workflow Step Id cannot be blank.'], $new->getErrors('workflow_step_id'));

        $step = $this->step('deleted', WorkflowStep::TYPE_PAUSE);
        $execution = $this->saved($this->newExecution($step));
        $step->delete();
        $stored = $this->reload($execution);
        $stored->status = WorkflowJobStep::STATUS_SUCCEEDED;

        $this->assertNull($stored->workflow_step_id);
        $this->assertTrue($stored->validate(), implode(' ', $stored->getFirstErrors()));
        $this->assertTrue($stored->save());
    }

    public function testACanceledInsertTakesNoSnapshot(): void
    {
        $execution = $this->newExecution($this->step('canceled', WorkflowStep::TYPE_PAUSE));
        $execution->on(ActiveRecord::EVENT_BEFORE_INSERT, static function (ModelEvent $event): void {
            $event->isValid = false;
        });

        $this->assertFalse($execution->save(false));

        $this->assertTrue($execution->getIsNewRecord());
        $this->assertNull($execution->step_name);
        $this->assertNull($execution->step_type);
        $this->assertFalse($execution->isRelationPopulated('workflowStep'));
    }

    /**
     * Regression guard for updates: code can run before m000084 (bind-mounted
     * code after `git pull`, a worker that starts while the app container
     * still migrates). Without the columns, executions save and show the
     * live step, and nothing tries to write the snapshot.
     */
    public function testBeforeTheMigrationTheLiveStepAnswers(): void
    {
        $step = $this->step('old schema', WorkflowStep::TYPE_APPROVAL);
        $execution = new class () extends WorkflowJobStep {
            /**
             * @return list<string>
             */
            public function attributes(): array
            {
                return array_values(array_diff(array_map('strval', parent::attributes()), ['step_name', 'step_type']));
            }
        };
        $execution->workflow_job_id = $this->runId;
        $execution->workflow_step_id = (int)$step->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;
        $execution->snapshotStep($step);

        $this->assertTrue($execution->save());

        $this->assertFalse($execution->hasAttribute('step_name'));
        $this->assertSame('old schema', $execution->stepName());
        $this->assertSame(WorkflowStep::TYPE_APPROVAL, $execution->stepType());
        $this->assertTrue($execution->isApprovalPlaceholder());
        $this->assertSame([null, null], $this->stored($execution), 'nothing written');
    }

    private function step(string $name, string $type, ?int $jobTemplateId = null): WorkflowStep
    {
        $step = $this->createWorkflowStep($this->workflowId, 0, $type, $jobTemplateId);
        $step->name = $name;
        $step->save(false, ['name']);

        return $step;
    }

    private function newExecution(WorkflowStep $step): WorkflowJobStep
    {
        $execution = new WorkflowJobStep();
        $execution->workflow_job_id = $this->runId;
        $execution->workflow_step_id = (int)$step->id;
        $execution->status = WorkflowJobStep::STATUS_RUNNING;

        return $execution;
    }

    private function saved(WorkflowJobStep $execution): WorkflowJobStep
    {
        $this->assertTrue($execution->save(false));

        return $execution;
    }

    private function reload(WorkflowJobStep $execution): WorkflowJobStep
    {
        $reloaded = WorkflowJobStep::findOne($execution->id);
        $this->assertNotNull($reloaded);

        return $reloaded;
    }

    /**
     * step_name and step_type as stored.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function stored(WorkflowJobStep $execution): array
    {
        $row = \Yii::$app->db->createCommand(
            'SELECT step_name, step_type FROM {{%workflow_job_step}} WHERE id = :id',
            [':id' => $execution->id]
        )->queryOne();
        $this->assertIsArray($row);

        return [$row['step_name'], $row['step_type']];
    }
}
