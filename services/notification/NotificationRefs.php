<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\ApprovalRequest;
use app\models\Job;
use app\models\NotificationDelivery;
use app\models\WorkflowJobStep;

/**
 * The refs of a notification's event: the objects it is about, by the keys
 * of NotificationDelivery::REF_ATTRIBUTES, in their order. A producer hands
 * them to the dispatcher in its payload under NotificationDispatcher::REFS;
 * they are no variables of the message. NotificationVisibility checks them
 * for each template when the event happens and again when it is delivered,
 * and fails closed for an event whose object it does not find.
 *
 * Each family of events names its object: a job event the job, an approval
 * event its request and the request's job, a project event the project
 * (project_id), schedule.failed_to_launch the schedule's job template
 * (job_template_id), a workflow event its run (workflow_job_id); runner.*
 * and webhook.invalid_token name none. A job or an approval of a workflow
 * step names the step's run too: the template's workflow filter applies to
 * it, and its deliveries keep the order of the run's thread.
 */
final class NotificationRefs
{
    /**
     * The refs of job $job: the job, and the run of the workflow step whose
     * job it is: $workflowJobId when the caller names it (the launch of a
     * step's job announces the job before the step records it), else the
     * run of the step that records the job, if any.
     *
     * @return array{workflow_job_id?: int, job_id: int}
     */
    public static function ofJob(Job $job, ?int $workflowJobId = null): array
    {
        $runId = $workflowJobId ?? self::runOfJob((int)$job->id);

        return ($runId === null ? [] : ['workflow_job_id' => $runId]) + ['job_id' => (int)$job->id];
    }

    /**
     * The refs of approval request $request: its job's refs (ofJob()), when
     * the job still exists, and the request.
     *
     * @return array{workflow_job_id?: int, job_id?: int, approval_request_id: int}
     */
    public static function ofApproval(ApprovalRequest $request, ?Job $job, ?int $workflowJobId = null): array
    {
        return ($job === null ? [] : self::ofJob($job, $workflowJobId)) + ['approval_request_id' => (int)$request->id];
    }

    /**
     * The refs a producer handed the dispatcher as it takes them: of the
     * keys of NotificationDelivery::REF_ATTRIBUTES, in their order, each
     * whose value is an ID, a whole number above 0 (an int, or its digits);
     * every other key and value is left out, so that an event with a ref
     * that is no ID fails closed instead of being judged without it.
     *
     * @return array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int}
     */
    public static function of(mixed $refs): array
    {
        $taken = [];
        foreach (NotificationDelivery::REF_ATTRIBUTES as $key) {
            $id = is_array($refs) ? self::idOf($refs[$key] ?? null) : null;
            if ($id !== null) {
                $taken[$key] = $id;
            }
        }

        /** @var array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $taken */
        return $taken;
    }

    /**
     * The run of the workflow step that records job $jobId; null for a job
     * of no run.
     */
    public static function runOfJob(int $jobId): ?int
    {
        $runId = WorkflowJobStep::find()->select('workflow_job_id')->where(['job_id' => $jobId])->scalar();

        return $runId === false || $runId === null ? null : (int)$runId;
    }

    /**
     * $value as an ID: an int above 0, or its digits (at most 18, which
     * every int holds); null otherwise.
     */
    private static function idOf(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int)$value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }
}
