<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\JobCancellationService;
use app\services\JobLaunchService;
use app\services\WorkflowAccessChecker;
use app\services\WorkflowRunEvents;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepDispatcher;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\base\Model;
use yii\base\ModelEvent;
use yii\db\BaseActiveRecord;

/**
 * An error while a step starts, after its run was committed, no longer
 * leaves the run active with nothing to move it on (which locks the
 * workflow's steps until someone cancels it):
 *
 * - an error while a job step launches its job (reading the template, the
 *   access check, the launch) fails the step with LAUNCH_FAILED, and the run;
 *   the log names the error's class, SQLSTATE and driver code, never its
 *   message;
 * - an error while the execution is written ends the run as failed before
 *   the error is thrown on;
 * - what follows a commit is best-effort: a failed notification (of the
 *   workflow, or of the job a step launched) is logged, and the run goes on.
 */
class WorkflowExecutionStartFailureTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;
    use CancellationFixtures;
    use StrandedRunFixtures;

    private User $operator;
    private JobTemplate $template;

    /** @var list<array{0: string, 1: string, 2: \Closure}> */
    private array $hooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordNotifications();
        $this->operator = $this->operator('wf-start-failure');
        $this->template = $this->openTemplate((int)$this->operator->id);
    }

    protected function tearDown(): void
    {
        foreach ($this->hooks as [$class, $event, $hook]) {
            Event::off($class, $event, $hook);
        }
        $this->hooks = [];
        $this->restoreSwappedServices();
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Regression: a database error while the start step launched its job (a
     * lost connection, say) escaped the launch; the run stayed running with
     * a step without a job for good, and every step change of the workflow
     * was refused with 409.
     */
    public function testRegressionADatabaseErrorWhileAJobStepLaunchesItsJobFailsTheStepAndTheRun(): void
    {
        $workflow = $this->jobWorkflow();
        $this->swapService('jobLaunchService', new class ($this->lostConnection('launch-marker-4711')) extends JobLaunchService {
            public function __construct(private \Throwable $failure)
            {
                parent::__construct();
            }

            public function launch(JobTemplate $template, int $launchedBy, #[\SensitiveParameter] array $overrides = []): Job
            {
                throw $this->failure;
            }
        });

        $logged = $this->errorsLoggedBy(WorkflowStepDispatcher::class, fn () => $this->engine()->launch($workflow, (int)$this->operator->id));

        /** @var WorkflowJob $run */
        $run = $logged['result'];
        $execution = $this->executionOfStep($run, $this->firstStep($workflow));
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowStepDispatcher::LAUNCH_FAILED, $execution->error_message);
        $this->assertNull($execution->job_id);
        $this->assertSame(
            [sprintf(WorkflowStepDispatcher::LAUNCH_FAILED_LOG, $run->id, $this->template->id, $execution->id, \yii\db\Exception::class, 'HY000', '2006')],
            $logged['errors']
        );
        $this->assertStringNotContainsString('launch-marker-4711', implode("\n", $logged['errors']));
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertSame([], $this->stepChangeGuard()->activeRunIds((int)$workflow->id), 'the steps can change');
    }

    /**
     * An error before the launch (the check whether the run's user may still
     * launch the job template) fails the step the same way.
     */
    public function testAnErrorBeforeTheLaunchFailsTheStep(): void
    {
        $workflow = $this->jobWorkflow();
        $this->swapService('workflowAccessChecker', new class ($this->lostConnection()) extends WorkflowAccessChecker {
            public function __construct(private \Throwable $failure)
            {
                parent::__construct();
            }

            public function canDispatch(int $userId, JobTemplate $template): bool
            {
                throw $this->failure;
            }
        });
        $jobsBefore = (int)Job::find()->count();

        $run = $this->engine()->launch($workflow, (int)$this->operator->id);

        $this->assertSame(WorkflowStepDispatcher::LAUNCH_FAILED, $this->executionOfStep($run, $this->firstStep($workflow))->error_message);
        $this->assertSame($jobsBefore, (int)Job::find()->count(), 'no job');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    /**
     * Regression (the advance path): the next step's launch failed after the
     * previous step's job completed; the run stayed running, and the error
     * reached the runner's completion report.
     */
    public function testRegressionADatabaseErrorWhileTheNextStepLaunchesItsJobFailsTheRun(): void
    {
        $workflow = $this->jobWorkflow(2);
        $run = $this->engine()->launch($workflow, (int)$this->operator->id);
        $first = $this->jobOfStep($run, $this->firstStep($workflow));
        $this->swapService('jobLaunchService', new class ($this->lostConnection()) extends JobLaunchService {
            public function __construct(private \Throwable $failure)
            {
                parent::__construct();
            }

            public function launch(JobTemplate $template, int $launchedBy, #[\SensitiveParameter] array $overrides = []): Job
            {
                throw $this->failure;
            }
        });

        $this->finishJob($first);

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $second = WorkflowJobStep::find()->where(['workflow_job_id' => $run->id])->orderBy(['id' => SORT_DESC])->one();
        $this->assertInstanceOf(WorkflowJobStep::class, $second);
        $this->assertSame(WorkflowStepDispatcher::LAUNCH_FAILED, $second->error_message);
    }

    /**
     * Regression: when the execution of the start step could not be written
     * (a database error), the run, already committed, stayed running with no
     * step at all. It ends as failed now, and the error is thrown on.
     */
    public function testRegressionAnExecutionThatCannotBeWrittenEndsTheRunAsFailed(): void
    {
        $workflow = $this->jobWorkflow();
        $error = $this->lostConnection();
        $this->hook(WorkflowJobStep::class, BaseActiveRecord::EVENT_BEFORE_INSERT, static function () use ($error): void {
            throw $error;
        });

        try {
            $this->engine()->launch($workflow, (int)$this->operator->id);
            $this->fail('expected the error');
        } catch (\yii\db\Exception $e) {
            $this->assertSame($error, $e);
        }

        $run = $this->onlyRunOf($workflow);
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertFalse(WorkflowJobStep::find()->where(['workflow_job_id' => $run->id])->exists());
        $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_WORKFLOW_FAILED, 'workflow_job', (int)$run->id));
        $this->assertSame([], $this->stepChangeGuard()->activeRunIds((int)$workflow->id));
    }

    /**
     * An execution that does not validate ends the run the same way.
     */
    public function testAnExecutionThatDoesNotValidateEndsTheRunAsFailed(): void
    {
        $workflow = $this->jobWorkflow();
        $this->hook(WorkflowJobStep::class, Model::EVENT_BEFORE_VALIDATE, static function (ModelEvent $event): void {
            $event->isValid = false;
        });

        try {
            $this->engine()->launch($workflow, (int)$this->operator->id);
            $this->fail('expected the error');
        } catch (\RuntimeException $e) {
            $this->assertSame('Failed to create workflow job step: []', $e->getMessage());
        }

        $this->assertSame(WorkflowJob::STATUS_FAILED, $this->onlyRunOf($workflow)->status);
    }

    /**
     * A run that was canceled while its step was being written stays
     * canceled: the end is conditional.
     */
    public function testARunCanceledMeanwhileStaysCanceled(): void
    {
        $workflow = $this->jobWorkflow();
        $error = $this->lostConnection();
        $operatorId = (int)$this->operator->id;
        $this->hook(WorkflowJobStep::class, BaseActiveRecord::EVENT_BEFORE_INSERT, function (Event $event) use ($error, $operatorId): void {
            /** @var WorkflowJobStep $execution */
            $execution = $event->sender;
            $run = WorkflowJob::findOne($execution->workflow_job_id);
            $this->assertNotNull($run);
            $this->runCancellation()->cancelRun($run, $operatorId, JobCancellationService::REASON_OPERATOR);
            throw $error;
        });

        try {
            $this->engine()->launch($workflow, (int)$this->operator->id);
            $this->fail('expected the error');
        } catch (\yii\db\Exception) {
            // thrown on
        }

        $run = $this->onlyRunOf($workflow);
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
        $this->assertSame([], $this->auditEntries(AuditLog::ACTION_WORKFLOW_FAILED, 'workflow_job', (int)$run->id));
    }

    /**
     * Regression: the job.launched notification of a step's job failed after
     * the job was committed; the step and the run failed with LAUNCH_FAILED
     * while the job, linked to nothing, stayed queued and ran. What follows
     * the launch's commit is best-effort now: the step waits for its job.
     */
    public function testRegressionAFailedNotificationOfAStepsJobNoLongerFailsTheStep(): void
    {
        $workflow = $this->jobWorkflow();
        $this->swapService(
            'notificationDispatcher',
            new FailingNotificationDispatcher([NotificationTemplate::EVENT_JOB_LAUNCHED], $this->lostConnection('job-notify-marker-4711'))
        );

        $logged = $this->errorsLoggedBy(JobLaunchService::class, fn () => $this->engine()->launch($workflow, (int)$this->operator->id));

        /** @var WorkflowJob $run */
        $run = $logged['result'];
        $execution = $this->executionOfStep($run, $this->firstStep($workflow));
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $execution->status);
        $job = $this->jobOfStep($run, $this->firstStep($workflow));
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $this->assertSame(
            [sprintf(JobLaunchService::EFFECTS_FAILED, $job->id, \yii\db\Exception::class, 'HY000', '2006')],
            $logged['errors']
        );
        $this->assertStringNotContainsString('job-notify-marker-4711', implode("\n", $logged['errors']));
    }

    /**
     * Regression: an error in the workflow.launched notification (sent after
     * the run was committed, before its start step) escaped the launch: the
     * run stayed running without any step, and the caller was told the
     * launch failed. The notification is best-effort now.
     */
    public function testRegressionAFailedLaunchNotificationLeftTheRunWithoutAStep(): void
    {
        $workflow = $this->jobWorkflow();
        $this->swapService(
            'notificationDispatcher',
            new FailingNotificationDispatcher([NotificationTemplate::EVENT_WORKFLOW_LAUNCHED], $this->lostConnection())
        );

        $logged = $this->errorsLoggedBy(WorkflowRunEvents::class, fn () => $this->engine()->launch($workflow, (int)$this->operator->id));

        /** @var WorkflowJob $run */
        $run = $logged['result'];
        $this->assertSame(
            [sprintf(WorkflowRunEvents::NOTIFY_FAILED, $run->id, NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, \yii\db\Exception::class, 'HY000', '2006')],
            $logged['errors']
        );
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $this->executionOfStep($run, $this->firstStep($workflow))->status);
        $this->assertSame(Job::STATUS_QUEUED, $this->jobOfStep($run, $this->firstStep($workflow))->status);
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * A new workflow of $steps job steps of $this->template.
     */
    private function jobWorkflow(int $steps = 1): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate((int)$this->operator->id);
        for ($order = 0; $order < $steps; $order++) {
            $this->createWorkflowStep((int)$workflow->id, $order, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        }

        return $workflow;
    }

    private function firstStep(WorkflowTemplate $workflow): WorkflowStep
    {
        $step = WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->orderBy(['step_order' => SORT_ASC])->one();
        $this->assertInstanceOf(WorkflowStep::class, $step);

        return $step;
    }

    private function onlyRunOf(WorkflowTemplate $workflow): WorkflowJob
    {
        $runs = WorkflowJob::findAll(['workflow_template_id' => $workflow->id]);
        $this->assertCount(1, $runs);

        return $runs[0];
    }

    private function stepChangeGuard(): WorkflowStepChangeGuard
    {
        /** @var WorkflowStepChangeGuard $guard */
        $guard = \Yii::$app->get('workflowStepChangeGuard');

        return $guard;
    }

    /**
     * @param class-string $class
     */
    private function hook(string $class, string $event, \Closure $hook): void
    {
        Event::on($class, $event, $hook);
        $this->hooks[] = [$class, $event, $hook];
    }
}
