<?php

declare(strict_types=1);

namespace app\commands;

use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;

/**
 * Seeds a workflow whose execution is paused at a pause-type step.
 * The {@see \app\services\WorkflowExecutionService::resume()} method is
 * designed to advance exactly this shape: a running WorkflowJob with a
 * running WorkflowJobStep that references a WorkflowStep of type `pause`.
 */
class E2eWorkflowPausedSeeder
{
    private const TEMPLATE_NAME = 'e2e-paused-workflow';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId, int $jobTemplateId): void
    {
        $this->deleteExisting();

        $template = new WorkflowTemplate();
        $template->name = self::TEMPLATE_NAME;
        $template->description = 'Paused-for-e2e fixture';
        $template->created_by = $userId;
        $template->save(false);

        $pauseStep = new WorkflowStep();
        $pauseStep->workflow_template_id = $template->id;
        $pauseStep->name = 'Wait for manual resume';
        $pauseStep->step_order = 1;
        $pauseStep->step_type = WorkflowStep::TYPE_PAUSE;
        $pauseStep->save(false);

        $jobStep = new WorkflowStep();
        $jobStep->workflow_template_id = $template->id;
        $jobStep->name = 'Post-resume job';
        $jobStep->step_order = 2;
        $jobStep->step_type = WorkflowStep::TYPE_JOB;
        $jobStep->job_template_id = $jobTemplateId;
        $jobStep->save(false);

        $wfJob = new WorkflowJob();
        $wfJob->workflow_template_id = $template->id;
        $wfJob->launched_by = $userId;
        $wfJob->status = WorkflowJob::STATUS_RUNNING;
        $wfJob->current_step_id = $pauseStep->id;
        $wfJob->started_at = time() - 60;
        $wfJob->save(false);

        $wjs = new WorkflowJobStep();
        $wjs->workflow_job_id = $wfJob->id;
        $wjs->workflow_step_id = $pauseStep->id;
        $wjs->status = WorkflowJobStep::STATUS_RUNNING;
        $wjs->started_at = time() - 55;
        $wjs->save(false);

        ($this->logger)("  Created paused workflow fixture: template #{$template->id}, job #{$wfJob->id} paused at step '{$pauseStep->name}'.\n");
    }

    private function deleteExisting(): void
    {
        $template = WorkflowTemplate::find()->where(['name' => self::TEMPLATE_NAME])->one();
        if ($template === null) {
            return;
        }
        $wfJobIds = WorkflowJob::find()->select('id')->where(['workflow_template_id' => $template->id])->column();
        if ($wfJobIds !== []) {
            WorkflowJobStep::deleteAll(['workflow_job_id' => $wfJobIds]);
            WorkflowJob::deleteAll(['id' => $wfJobIds]);
        }
        WorkflowStep::deleteAll(['workflow_template_id' => $template->id]);
        $template->delete();
    }
}
