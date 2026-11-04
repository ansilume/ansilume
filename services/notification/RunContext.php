<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\AppLinks;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\NotificationDispatcher;
use app\services\runstate\Banner;
use app\services\runstate\WorkflowRunStates;

/**
 * The context of a workflow run that its notifications carry, built when
 * the event happens, every value a string (payload()):
 *
 * - workflow: id, status (as stored: a run that waits is running),
 *   template_id and template_name (of a deleted template too), url (the
 *   run's page, AppLinks), steps_done (the steps of the workflow that ended
 *   in the run, succeeded, failed or skipped, each counted once however
 *   often a loop ran it), steps_total (the steps the workflow has now), and
 *   reason: what the run page says of the step, the title of its banner
 *   while it waits (runstate\Banner::title()), else the step's error (why
 *   it failed, or why the run could not go on after it), else ''.
 * - step: id and execution_id (the step's execution), job_id,
 *   workflow_step_id and position (its place 1..n in the workflow now; both
 *   '' once the step was removed), name and type (as when it ran), and
 *   waiting_for: approval, resume or runner, as the run page and the API
 *   derive it (WorkflowRunStates::waitingForOf()), '' when it waits for
 *   nothing.
 *
 * A step event names its step (of()); an event of the whole run its
 * newest step, none before its first (ofRun()); a job or an approval event
 * of a run the step whose job it is (ofJob()). The dispatcher leaves every
 * one of these variables out for a template whose identity may not see the
 * run's workflow (NotificationDispatcher::RUN_CONTEXT_PREFIXES), and the
 * notification worker leaves them out of a message that it sends once the
 * identity of its template may no longer see it (without(),
 * NotificationDeliveryService).
 */
final class RunContext
{
    /** The statuses of an execution that ended. */
    private const ENDED = [WorkflowJobStep::STATUS_SUCCEEDED, WorkflowJobStep::STATUS_FAILED, WorkflowJobStep::STATUS_SKIPPED];

    /**
     * @param WorkflowJob $run the run
     * @param WorkflowJobStep|null $execution the step's execution; null for none
     * @param int|null $jobId the step's job; null without one
     * @param string|null $waitingFor what the step waits for (Display::WAITING_FOR_*); null for nothing
     * @param int|null $approvalRequestId the approval request of the step's job; null without one
     * @param array{workflow: array<string, string>, step?: array<string, string>} $payload
     */
    private function __construct(
        public readonly WorkflowJob $run,
        public readonly ?WorkflowJobStep $execution,
        public readonly ?int $jobId,
        public readonly ?string $waitingFor,
        public readonly ?int $approvalRequestId,
        private readonly array $payload
    ) {
    }

    /**
     * The context of $run and $execution, one of its executions (null: the
     * run alone). $jobId is the execution's job when the execution has not
     * recorded it yet (a launch announces its job first); by default the
     * job it records.
     */
    public static function of(WorkflowJob $run, ?WorkflowJobStep $execution, ?int $jobId = null): self
    {
        $jobId ??= self::recordedJobOf($execution);
        $waitingFor = $execution === null ? null : WorkflowRunStates::waitingForOf($execution, self::jobStatusOf($jobId));
        $stepIds = self::stepIdsOf((int)$run->workflow_template_id);
        /** @var WorkflowTemplate|null $template */
        $template = $run->workflowTemplate;
        $payload = ['workflow' => [
            'id' => (string)$run->id,
            'status' => (string)$run->status,
            'template_id' => (string)($template?->id ?? ''),
            'template_name' => (string)($template?->name ?? ''),
            'url' => AppLinks::workflowRun((int)$run->id),
            'steps_done' => (string)self::stepsDone((int)$run->id, $stepIds),
            'steps_total' => (string)count($stepIds),
            'reason' => self::reason($execution, $jobId, $waitingFor),
        ]];
        if ($execution !== null) {
            $payload['step'] = self::step($execution, $jobId, $stepIds, $waitingFor);
        }

        return new self($run, $execution, $jobId, $waitingFor, self::requestOf($jobId), $payload);
    }

    /**
     * The context of an event of the whole run $run: its newest execution,
     * none before its first.
     */
    public static function ofRun(WorkflowJob $run): self
    {
        /** @var WorkflowJobStep|null $newest */
        $newest = WorkflowJobStep::find()->where(['workflow_job_id' => $run->id])->orderBy(['id' => SORT_DESC])->one();

        return self::of($run, $newest);
    }

    /**
     * The context of job $jobId of run $runId (NotificationRefs names the
     * run): the execution that records the job, else (a launch announces
     * its job before the step records it) the run's newest running
     * execution without a job, the step that launches it. Null for a job of
     * no run ($runId null) and for a run that no longer exists.
     */
    public static function ofJob(int $jobId, ?int $runId): ?self
    {
        $run = $runId === null ? null : WorkflowJob::findOne($runId);
        if (!$run instanceof WorkflowJob) {
            return null;
        }
        /** @var WorkflowJobStep|null $execution */
        $execution = WorkflowJobStep::find()->where(['workflow_job_id' => $runId, 'job_id' => $jobId])->orderBy(['id' => SORT_DESC])->one()
            ?? WorkflowJobStep::find()
                ->where(['workflow_job_id' => $runId, 'job_id' => null, 'status' => WorkflowJobStep::STATUS_RUNNING])
                ->orderBy(['id' => SORT_DESC])
                ->one();

        return self::of($run, $execution, $execution === null ? null : $jobId);
    }

    /**
     * The payload part of the context: 'workflow', and 'step' when there
     * is a step.
     *
     * @return array{workflow: array<string, string>, step?: array<string, string>}
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * $variables, the variables of a notification, without the run's
     * context: without every variable whose name starts with a prefix of
     * NotificationDispatcher::RUN_CONTEXT_PREFIXES (workflow.*, step.*).
     *
     * @param array<string, string> $variables
     * @return array<string, string>
     */
    public static function without(array $variables): array
    {
        return array_filter(
            $variables,
            static function (string $name): bool {
                foreach (NotificationDispatcher::RUN_CONTEXT_PREFIXES as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        return false;
                    }
                }

                return true;
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @param list<int> $stepIds the steps of the workflow in run order
     * @return array<string, string>
     */
    private static function step(WorkflowJobStep $execution, ?int $jobId, array $stepIds, ?string $waitingFor): array
    {
        $stepId = $execution->workflow_step_id === null ? null : (int)$execution->workflow_step_id;
        $index = $stepId === null ? false : array_search($stepId, $stepIds, true);

        return [
            'id' => (string)$execution->id,
            'job_id' => (string)($jobId ?? ''),
            'execution_id' => (string)$execution->id,
            'workflow_step_id' => (string)($stepId ?? ''),
            'position' => $index === false ? '' : (string)($index + 1),
            'name' => $execution->stepName(),
            'type' => (string)$execution->stepType(),
            'waiting_for' => (string)$waitingFor,
        ];
    }

    /**
     * What the run page says of the step: its banner's title while it
     * waits, else its error; '' for neither, and without a step.
     */
    private static function reason(?WorkflowJobStep $execution, ?int $jobId, ?string $waitingFor): string
    {
        if ($execution === null) {
            return '';
        }

        return Banner::title($waitingFor, $execution->stepType(), $execution->stepName(), $jobId) ?? (string)$execution->error_message;
    }

    /**
     * The IDs of the steps of workflow template $workflowTemplateId, in run
     * order (WorkflowTemplate::RUN_ORDER): an ID's index is its position,
     * less 1. The status post of a run names its steps by them too
     * (MattermostChannel).
     *
     * @return list<int>
     */
    public static function stepIdsOf(int $workflowTemplateId): array
    {
        return array_map('intval', WorkflowStep::find()
            ->select('id')
            ->where(['workflow_template_id' => $workflowTemplateId])
            ->orderBy(WorkflowTemplate::RUN_ORDER)
            ->column());
    }

    /**
     * How many of the steps $stepIds ended in run $runId, each once.
     *
     * @param list<int> $stepIds
     */
    private static function stepsDone(int $runId, array $stepIds): int
    {
        if ($stepIds === []) {
            return 0;
        }

        return count(WorkflowJobStep::find()
            ->select('workflow_step_id')
            ->distinct()
            ->where(['workflow_job_id' => $runId, 'workflow_step_id' => $stepIds, 'status' => self::ENDED])
            ->column());
    }

    /** The job $execution records; null without an execution or a job. */
    private static function recordedJobOf(?WorkflowJobStep $execution): ?int
    {
        return $execution === null || $execution->job_id === null ? null : (int)$execution->job_id;
    }

    private static function jobStatusOf(?int $jobId): ?string
    {
        $status = $jobId === null ? false : Job::find()->select('status')->where(['id' => $jobId])->scalar();

        return is_string($status) ? $status : null;
    }

    private static function requestOf(?int $jobId): ?int
    {
        $id = $jobId === null ? false : ApprovalRequest::find()->select('id')->where(['job_id' => $jobId])->orderBy(['id' => SORT_DESC])->scalar();

        return $id === false || $id === null ? null : (int)$id;
    }
}
