<?php

declare(strict_types=1);

namespace app\services\runstate;

use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ProjectAccessChecker;
use app\services\WorkflowCancellationService;
use yii\base\Component;
use yii\db\Expression;
use yii\db\Query;

/**
 * What workflow runs and their step executions are doing, as the run page,
 * the poll, the lists and the API show it (RunState, StepState). Nothing is
 * stored: a run that waits stays stored as running, and its state is
 * derived from its executions and their jobs.
 *
 * A running execution, by the type its step had when it ran
 * (WorkflowJobStep::stepType(), so a step removed since still maps):
 * - a pause step is paused: it waits to be resumed;
 * - any other step whose job is pending_approval awaits approval: the
 *   placeholder of an approval step, or the job of a gated job step;
 * - any other step but an approval step whose job is pending or queued is
 *   queued: its job waits for a runner;
 * - anything else runs: its job runs, has finished while the engine moves
 *   on, or has not been created yet.
 * A finished execution keeps its stored status, except a failed one that a
 * run cancel ended (WorkflowCancellationService::stepMessages()): it was
 * canceled. A running run takes the state of its newest running execution,
 * or runs between two steps; a finished run keeps its stored status.
 *
 * Waiting since: an approval wait since its request was opened (else since
 * the execution started), a runner wait since its job was queued (else
 * created), a pause since the execution started.
 */
final class WorkflowRunStates extends Component
{
    /**
     * $run with every execution, oldest first, in a constant number of
     * queries; $run->stepExecutions is populated with the executions read,
     * so a page that renders them reads them no second time. The decision
     * notes of the requests are included, and the pending request of the
     * current execution carries the eligibility of its approvers; with
     * $personal also the viewer's relation to it.
     */
    public function forRun(WorkflowJob $run, Viewer $viewer, bool $personal = false): RunState
    {
        /** @var list<WorkflowJobStep> $executions */
        $executions = WorkflowJobStep::find()
            ->where(['workflow_job_id' => $run->id])
            ->with('workflowStep')
            ->orderBy(['id' => SORT_ASC])
            ->all();
        $run->populateRelation('stepExecutions', $executions);
        $current = $run->isActive() ? self::newestRunning($executions) : null;
        $jobIds = self::jobIds($executions);
        $jobs = $this->jobRows($jobIds, $viewer);
        $waits = $this->waits()->forJobs($jobIds, $viewer, true);
        $currentJobId = (int)$current?->job_id;
        if (isset($waits[$currentJobId])) {
            $waits[$currentJobId] = $this->waits()->withEligibility($waits[$currentJobId], $viewer, $personal);
        }
        $steps = [];
        $currentState = null;
        foreach ($executions as $execution) {
            $step = self::stepState($execution, $jobs, $waits);
            $steps[] = $step;
            if ($execution === $current) {
                $currentState = $step;
            }
        }

        return self::runState($run, $currentState, $steps);
    }

    /**
     * The state of each run of $runs, keyed by run ID, with its current
     * execution only (no steps), in a constant number of queries however
     * many runs there are; finished runs need none.
     *
     * @param list<WorkflowJob> $runs
     * @return array<int, RunState>
     */
    public function forRuns(array $runs, Viewer $viewer): array
    {
        $activeIds = [];
        foreach ($runs as $run) {
            if ($run->isActive()) {
                $activeIds[] = (int)$run->id;
            }
        }
        $current = $activeIds === [] ? [] : self::currentExecutions($activeIds);
        $jobIds = self::jobIds(array_values($current));
        $jobs = $this->jobRows($jobIds, $viewer);
        $waits = $this->waits()->forJobs($jobIds, $viewer);
        $states = [];
        foreach ($runs as $run) {
            $execution = $current[(int)$run->id] ?? null;
            $step = $execution === null ? null : self::stepState($execution, $jobs, $waits);
            $states[(int)$run->id] = self::runState($run, $step, []);
        }

        return $states;
    }

    /**
     * A condition for a query of runs (WorkflowJob::find(), whose table is
     * not aliased) that keeps the running runs that wait for $waitingFor
     * (Display::WAITING_FOR_*) at their current execution, exactly as
     * forRun() and forRuns() derive it: EXISTS on the newest running
     * execution, by the type its step had when it ran (the live step's type
     * for an execution without one) compared as stored, so a legacy type
     * such as "PAUSE" waits for nothing. An unknown value matches no run.
     *
     * @return array<int|string, mixed>
     */
    public function waitingCondition(string $waitingFor): array
    {
        $kind = $this->kindCondition($waitingFor);
        if ($kind === null) {
            return ProjectAccessChecker::DENY_ALL;
        }
        $newer = (new Query())
            ->select(new Expression('1'))
            ->from(['wjs_newer' => WorkflowJobStep::tableName()])
            ->where('[[wjs_newer.workflow_job_id]] = [[wjs.workflow_job_id]]')
            ->andWhere(['wjs_newer.status' => WorkflowJobStep::STATUS_RUNNING])
            ->andWhere('[[wjs_newer.id]] > [[wjs.id]]');
        $current = (new Query())
            ->select(new Expression('1'))
            ->from(['wjs' => WorkflowJobStep::tableName()])
            ->leftJoin(['ws' => WorkflowStep::tableName()], '[[ws.id]] = [[wjs.workflow_step_id]]')
            ->leftJoin(['wjs_job' => Job::tableName()], '[[wjs_job.id]] = [[wjs.job_id]]')
            ->where('[[wjs.workflow_job_id]] = [[workflow_job.id]]')
            ->andWhere(['wjs.status' => WorkflowJobStep::STATUS_RUNNING])
            ->andWhere(['not exists', $newer])
            ->andWhere($kind);

        return ['and', ['workflow_job.status' => WorkflowJob::STATUS_RUNNING], ['exists', $current]];
    }

    /**
     * The condition on the execution (wjs), its step (ws) and its job
     * (wjs_job) for one kind of wait; null for an unknown one.
     */
    private function kindCondition(string $waitingFor): ?Expression
    {
        $type = $this->stepTypeSql();

        return match ($waitingFor) {
            Display::WAITING_FOR_RESUME => new Expression("{$type} = :rs_pause", [':rs_pause' => WorkflowStep::TYPE_PAUSE]),
            Display::WAITING_FOR_APPROVAL => new Expression(
                "[[wjs_job.status]] = :rs_awaiting AND {$type} <> :rs_pause",
                [':rs_awaiting' => Job::STATUS_PENDING_APPROVAL, ':rs_pause' => WorkflowStep::TYPE_PAUSE]
            ),
            Display::WAITING_FOR_RUNNER => new Expression(
                "[[wjs_job.status]] IN (:rs_pending, :rs_queued) AND {$type} NOT IN (:rs_pause, :rs_approval)",
                [
                    ':rs_pending' => Job::STATUS_PENDING,
                    ':rs_queued' => Job::STATUS_QUEUED,
                    ':rs_pause' => WorkflowStep::TYPE_PAUSE,
                    ':rs_approval' => WorkflowStep::TYPE_APPROVAL,
                ]
            ),
            default => null,
        };
    }

    /**
     * The type of an execution's step as WorkflowJobStep::stepType() reads
     * it (the snapshot, else the live step's type; '' when neither is
     * known), as a binary string: compared byte for byte, not in the
     * column's case-insensitive collation. Before migration m000084 there is
     * no snapshot column, and only the live type counts.
     */
    private function stepTypeSql(): string
    {
        $schema = WorkflowJobStep::getTableSchema();
        $snapshot = $schema->getColumn('step_type') === null ? '' : "NULLIF([[wjs.step_type]], ''), ";

        return "CAST(COALESCE({$snapshot}[[ws.step_type]], '') AS BINARY)";
    }

    /**
     * The newest running execution of each of $runIds, keyed by run ID;
     * runs without one are left out.
     *
     * @param list<int> $runIds
     * @return array<int, WorkflowJobStep>
     */
    private static function currentExecutions(array $runIds): array
    {
        /** @var list<WorkflowJobStep> $running */
        $running = WorkflowJobStep::find()
            ->where(['workflow_job_id' => $runIds, 'status' => WorkflowJobStep::STATUS_RUNNING])
            ->with('workflowStep')
            ->orderBy(['id' => SORT_ASC])
            ->all();
        $current = [];
        foreach ($running as $execution) {
            $current[(int)$execution->workflow_job_id] = $execution;
        }

        return $current;
    }

    /**
     * @param list<WorkflowJobStep> $executions oldest first
     */
    private static function newestRunning(array $executions): ?WorkflowJobStep
    {
        $newest = null;
        foreach ($executions as $execution) {
            if ($execution->status === WorkflowJobStep::STATUS_RUNNING) {
                $newest = $execution;
            }
        }

        return $newest;
    }

    /**
     * The jobs of $jobIds, keyed by ID: their status, queue and creation
     * times, and their template's name (a deleted template's too) when the
     * viewer may see its project.
     *
     * @param list<int> $jobIds
     * @return array<int, array<string, mixed>>
     */
    private function jobRows(array $jobIds, Viewer $viewer): array
    {
        if ($jobIds === []) {
            return [];
        }
        $templateName = (new Query())
            ->select('rs_template.name')
            ->from(['rs_template' => JobTemplate::tableName()])
            ->where('[[rs_template.id]] = [[rs_job.job_template_id]]');
        // Nobody (user ID 0) sees no name: a null user is denied every project.
        $visible = $this->projects()->buildJobFilter($viewer->userId > 0 ? $viewer->userId : null, 'rs_template.id');
        if ($visible !== null) {
            $templateName->andWhere($visible);
        }
        $rows = (new Query())
            ->select([
                'id' => 'rs_job.id',
                'status' => 'rs_job.status',
                'queued_at' => 'rs_job.queued_at',
                'created_at' => 'rs_job.created_at',
                'template_name' => $templateName,
            ])
            ->from(['rs_job' => Job::tableName()])
            ->where(['rs_job.id' => $jobIds])
            ->all();
        $jobs = [];
        foreach ($rows as $row) {
            $jobs[(int)$row['id']] = $row;
        }

        return $jobs;
    }

    /**
     * @param array<int, array<string, mixed>> $jobs jobRows()
     * @param array<int, ApprovalWait> $waits by job ID
     */
    private static function stepState(WorkflowJobStep $execution, array $jobs, array $waits): StepState
    {
        $jobId = self::intOrNull($execution->job_id);
        $job = $jobs[(int)$jobId] ?? null;
        $wait = $waits[(int)$jobId] ?? null;
        $jobStatus = $job === null ? null : (string)$job['status'];
        $state = self::executionState($execution, $jobStatus);

        return new StepState(
            executionId: (int)$execution->id,
            workflowStepId: self::intOrNull($execution->workflow_step_id),
            stepName: $execution->stepName(),
            stepType: $execution->stepType(),
            status: (string)$execution->status,
            state: $state,
            waitingFor: Display::waitingFor($state),
            jobId: $jobId,
            jobStatus: $jobStatus,
            jobTemplateName: self::stringOrNull($job['template_name'] ?? null),
            startedAt: self::intOrNull($execution->started_at),
            finishedAt: self::intOrNull($execution->finished_at),
            waitingSince: self::waitingSince($state, $execution, $job, $wait),
            errorMessage: self::stringOrNull($execution->error_message),
            approval: $wait,
            decisionNote: $wait?->decisionNote,
        );
    }

    private static function executionState(WorkflowJobStep $execution, ?string $jobStatus): string
    {
        $status = (string)$execution->status;
        if ($status === WorkflowJobStep::STATUS_RUNNING) {
            return self::runningState($execution->stepType(), $jobStatus);
        }
        $canceled = $status === WorkflowJobStep::STATUS_FAILED
            && in_array($execution->error_message, WorkflowCancellationService::stepMessages(), true);

        return $canceled ? Display::STATE_CANCELED : $status;
    }

    private static function runningState(?string $stepType, ?string $jobStatus): string
    {
        if ($stepType === WorkflowStep::TYPE_PAUSE) {
            return Display::STATE_PAUSED;
        }
        if ($jobStatus === Job::STATUS_PENDING_APPROVAL) {
            return Display::STATE_AWAITING_APPROVAL;
        }
        $waitsForRunner = $stepType !== WorkflowStep::TYPE_APPROVAL
            && in_array($jobStatus, [Job::STATUS_PENDING, Job::STATUS_QUEUED], true);

        return $waitsForRunner ? Display::STATE_QUEUED : Display::STATE_RUNNING;
    }

    /**
     * @param array<string, mixed>|null $job
     */
    private static function waitingSince(string $state, WorkflowJobStep $execution, ?array $job, ?ApprovalWait $wait): ?int
    {
        $startedAt = self::intOrNull($execution->started_at);

        return match ($state) {
            Display::STATE_AWAITING_APPROVAL => $wait?->requestedAt ?? $startedAt,
            Display::STATE_PAUSED => $startedAt,
            Display::STATE_QUEUED => self::intOrNull($job['queued_at'] ?? null) ?? self::intOrNull($job['created_at'] ?? null),
            default => null,
        };
    }

    /**
     * @param list<StepState> $steps
     */
    private static function runState(WorkflowJob $run, ?StepState $current, array $steps): RunState
    {
        $status = (string)$run->status;
        $active = $run->isActive();
        $state = $active ? ($current?->state ?? Display::STATE_RUNNING) : $status;

        return new RunState(
            runId: (int)$run->id,
            status: $status,
            state: $state,
            waitingFor: Display::waitingFor($state),
            current: $active ? $current : null,
            steps: $steps,
        );
    }

    /**
     * @param list<WorkflowJobStep> $executions
     * @return list<int>
     */
    private static function jobIds(array $executions): array
    {
        $ids = [];
        foreach ($executions as $execution) {
            if ($execution->job_id !== null) {
                $ids[] = (int)$execution->job_id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int)$value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }

    private function waits(): ApprovalWaits
    {
        /** @var ApprovalWaits $waits */
        $waits = \Yii::$app->get('approvalWaits');

        return $waits;
    }

    private function projects(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $projects */
        $projects = \Yii::$app->get('projectAccessChecker');

        return $projects;
    }
}
