<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\AppLinks;
use app\models\Job;
use app\services\NotificationDispatcher;

/**
 * Builds the notification payload for a job event.
 *
 * Returns a nested array that TemplateRenderer::buildVariables() flattens into
 * dot-keyed template variables like {{ job.id }}, {{ project.name }}, etc.;
 * for the job of a workflow step also the run's context (RunContext:
 * workflow.*, step.*), which the dispatcher leaves out for an identity that
 * may not see the run's workflow; and the event's refs under
 * NotificationDispatcher::REFS: the job, and the run of the workflow step
 * whose job it is (NotificationRefs::ofJob()).
 */
class JobPayloadBuilder
{
    /**
     * @param int|null $workflowJobId the run of the workflow step whose job
     *     $job is, when the caller knows it before the step records the job
     *     (the launch of a step's job); else the step that records it says
     * @return array<string, mixed>
     */
    public static function build(Job $job, ?int $workflowJobId = null): array
    {
        $template = $job->jobTemplate;
        $project = $template?->project;
        $launcher = $job->launcher;

        $duration = '';
        if ($job->started_at && $job->finished_at) {
            $duration = (string)($job->finished_at - $job->started_at) . 's';
        }
        $refs = NotificationRefs::ofJob($job, $workflowJobId);
        $run = RunContext::ofJob((int)$job->id, $refs['workflow_job_id'] ?? null);

        return [
            'job' => [
                'id' => (string)$job->id,
                'status' => (string)$job->status,
                'exit_code' => (string)($job->exit_code ?? ''),
                'duration' => $duration,
                // From appBaseUrl (APP_URL) alone, never the request's host:
                // a job's report arrives on the runner→nginx path (Host:
                // nginx), whose host must not reach a notification.
                'url' => AppLinks::job((int)$job->id),
                'template_id' => (string)($template?->id ?? ''),
            ],
            'template' => [
                'id' => (string)($template?->id ?? ''),
                'name' => (string)($template?->name ?? ''),
            ],
            'project' => [
                'id' => (string)($project?->id ?? ''),
                'name' => (string)($project?->name ?? ''),
            ],
            'launched_by' => (string)($launcher?->username ?? ''),
        ] + ($run?->payload() ?? []) + [NotificationDispatcher::REFS => $refs];
    }
}
