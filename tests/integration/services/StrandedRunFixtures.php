<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\JobTemplate;
use app\models\RunnerGroup;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\services\JobClaimService;
use app\services\StrandedJobSweep;
use yii\log\Logger;

/**
 * Fixtures of the tests of runs whose cancel failed part-way and of the
 * maintenance run's StrandedJobSweep; use in DbTestCase subclasses together
 * with TeamScopeFixtures and WorkflowEngineFixtures. Call
 * restoreSwappedServices() in tearDown().
 */
trait StrandedRunFixtures
{
    /** @var array<string, mixed> the components swapped, by ID, as they were */
    private array $swappedServices = [];

    /**
     * Replace the component $id with $replacement until restoreSwappedServices().
     */
    protected function swapService(string $id, object $replacement): void
    {
        if (!array_key_exists($id, $this->swappedServices)) {
            $this->swappedServices[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $replacement);
    }

    protected function restoreSwappedServices(): void
    {
        foreach ($this->swappedServices as $id => $original) {
            \Yii::$app->set($id, $original);
        }
        $this->swappedServices = [];
    }

    /**
     * Every job cancel within a workflow throws $error from now on.
     */
    protected function failJobCancels(\Throwable $error): FlakyJobCancellation
    {
        $flaky = new FlakyJobCancellation($error);
        $flaky->always = true;
        $this->swapService('jobCancellationService', $flaky);

        return $flaky;
    }

    /**
     * What $action returned, and the error lines of category $category
     * logged meanwhile.
     *
     * @return array{result: mixed, errors: list<string>}
     */
    protected function errorsLoggedBy(string $category, callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $result = $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $errors = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_ERROR && $message[2] === $category && is_string($message[0])) {
                $errors[] = $message[0];
            }
        }

        return ['result' => $result, 'errors' => $errors];
    }

    /**
     * A database error as PDO reports a lost connection; its message quotes
     * the statement with $marker, which no log line may show.
     */
    protected function lostConnection(string $marker = 'marker-value-4711'): \yii\db\Exception
    {
        return new \yii\db\Exception(
            "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away; The SQL being executed was: UPDATE `job` SET `extra_vars`='{$marker}'",
            ['HY000', 2006, 'MySQL server has gone away']
        );
    }

    /**
     * A deadlock, which MariaDB reports with SQLSTATE 40001 and error 1213.
     */
    protected function deadlock(): \yii\db\Exception
    {
        return new \yii\db\Exception(
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock',
            ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction']
        );
    }

    /**
     * A run of a new workflow whose first step, a job step of $template,
     * waits for its job; then a pause step.
     *
     * @return array{run: WorkflowJob, step: WorkflowStep, next: WorkflowStep, job: Job}
     */
    protected function runWaitingForItsJob(User $launcher, JobTemplate $template): array
    {
        $workflow = $this->createWorkflowTemplate((int)$launcher->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);
        $next = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $run = $this->engine()->launch($workflow, (int)$launcher->id);

        return ['run' => $run, 'step' => $step, 'next' => $next, 'job' => $this->jobOfStep($run, $step)];
    }

    /**
     * What a runner of $template's runner group claims now.
     */
    protected function claimFor(JobTemplate $template): ?Job
    {
        /** @var RunnerGroup $group */
        $group = RunnerGroup::findOne($template->runner_group_id);
        $runner = $this->createRunner((int)$group->id, (int)$template->created_by);
        /** @var JobClaimService $claims */
        $claims = \Yii::$app->get('jobClaimService');

        return $claims->claim($group, $runner);
    }

    protected function strandedJobSweep(): StrandedJobSweep
    {
        /** @var StrandedJobSweep $sweep */
        $sweep = \Yii::$app->get('strandedJobSweep');

        return $sweep;
    }
}
