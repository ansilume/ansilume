<?php

declare(strict_types=1);

namespace app\services;

use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;

/**
 * Orchestrates workflow runs: the launch, each step's start (through
 * WorkflowStepDispatcher), the way on after a step ended (WorkflowStepRouter),
 * resume, cancel (WorkflowCancellationService) and the end of the run. The
 * run's extra vars are WorkflowRunVars' part.
 *
 * A step leaves running with one conditional UPDATE: only the caller that
 * moved it moves the run on, so a stale or repeated job completion, a second
 * decision or a second resume never routes a step twice. A run ends the same
 * way, so a run that was canceled meanwhile keeps its outcome. Notifications
 * are best-effort (WorkflowRunEvents): a failed one is logged, and the run
 * goes on.
 */
class WorkflowExecutionService extends Component
{
    /**
     * Why a job step was not launched, a sprintf() format: the user the
     * workflow runs as (#id) may not launch the step's job template (name,
     * #id). E2eWorkflowScopingSeeder seeds a run refused with it.
     */
    public const JOB_STEP_REFUSAL = 'Not launched: user #%d, whom this workflow runs as, may not launch job template "%s" (#%d).';

    /** A launch of a soft-deleted workflow template: its ID. */
    public const WORKFLOW_TEMPLATE_DELETED = 'Workflow template #%d was deleted.';

    /** A launch of a workflow template without steps: its ID. */
    public const NO_STEPS = 'Workflow template #%d has no steps.';

    /** A resume or cancel of a run that has ended. */
    public const RUN_FINISHED = 'Workflow is already finished.';

    /** A resume of a run that waits at no pause step (or another resume came first). */
    public const NO_PAUSED_STEP = 'No paused step to resume.';

    /** The error of a step whose workflow step was deleted while it ran: the run cannot be routed on. */
    public const STEP_REMOVED = 'This step was removed from the workflow while it ran, so the run cannot go on.';

    private ?WorkflowStepDispatcher $dispatcher = null;

    /**
     * Launch a workflow from a template.
     *
     * Access is checked first, and a refusal audited. Then one transaction
     * holds the template's row lock (WorkflowTemplate::lockLive()), which its
     * deletion and every step change take too: the launch is refused when the
     * template was deleted, has no steps, its launch extra vars cannot be
     * taken (WorkflowRunVars), or one of its approvals could never be granted
     * (ApproverEligibility::workflowLaunchRefusal()); else the run is written
     * with its extra vars. A refusal writes nothing. The workflow.launched
     * audit entry, its notification and the start step follow after the
     * commit.
     *
     * @param array<string, mixed> $overrides extra_vars: the run's launch extra vars (a JSON
     *     object, decoded or as JSON text); other keys are ignored
     * @param string $source web, api or trigger
     * @throws WorkflowAccessDeniedException when $launchedBy may not run every job step
     * @throws WorkflowLaunchRejectedException when the launch is refused for a reason the caller can fix
     * @throws \RuntimeException when the run cannot be saved (nothing is written), or the execution of
     *     its start step cannot (the run then ends as failed; see executeStep())
     */
    public function launch(
        WorkflowTemplate $template,
        int $launchedBy,
        #[\SensitiveParameter] array $overrides = [],
        string $source = 'web'
    ): WorkflowJob {
        $this->access()->assertMayLaunch($launchedBy, $template, $source);

        /** @var array{run: WorkflowJob, start: WorkflowStep} $created */
        $created = WorkflowJob::getDb()->transaction(fn (): array => $this->createRun($template, $launchedBy, $overrides));
        $wfJob = $created['run'];

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WORKFLOW_LAUNCHED,
            'workflow_job',
            $wfJob->id,
            $launchedBy,
            ['template_id' => $template->id, 'template_name' => $template->name, 'source' => $source]
        );

        (new WorkflowRunEvents())->notify(NotificationTemplate::EVENT_WORKFLOW_LAUNCHED, $wfJob);

        $this->executeStep($wfJob, $created['start']);

        return $wfJob;
    }

    /**
     * Execute a single step within a workflow: a new execution, running, that
     * keeps the step's name and type, then the step's work starts. A step that
     * cannot start fails, and with it the run, without following its branches.
     * A job step whose job ended before the step was linked to it (a vote, a
     * cancel or its runner came first) ends with that job right away, as
     * nobody else moved it on (WorkflowStepDispatcher::settleStart()).
     *
     * When the execution cannot be written (or the run's current step, or
     * the step.started audit entry), the run ends as failed before the error
     * is thrown on: nothing would ever move it on, and an active run locks
     * its workflow's steps. A run that was canceled meanwhile stays canceled.
     *
     * @param array<int|string, mixed> $mappedVars the step's mapping of the previous step's output
     * @throws \RuntimeException when the execution cannot be saved
     */
    public function executeStep(
        WorkflowJob $wfJob,
        WorkflowStep $step,
        #[\SensitiveParameter] array $mappedVars = []
    ): WorkflowJobStep {
        $wjs = new WorkflowJobStep();
        $wjs->workflow_job_id = $wfJob->id;
        $wjs->workflow_step_id = $step->id;
        $wjs->snapshotStep($step);
        $wjs->status = WorkflowJobStep::STATUS_RUNNING;
        $wjs->started_at = time();
        $wjs->created_at = time();
        $wjs->updated_at = time();

        try {
            if (!$wjs->save()) {
                throw new \RuntimeException(
                    'Failed to create workflow job step: ' . json_encode($wjs->errors)
                );
            }

            $wfJob->current_step_id = $step->id;
            $wfJob->save(false);

            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_WORKFLOW_STEP_STARTED,
                'workflow_job_step',
                $wjs->id,
                null,
                ['step_name' => $step->name, 'step_type' => $step->step_type]
            );
        } catch (\Throwable $e) {
            $this->completeWorkflow($wfJob, WorkflowJob::STATUS_FAILED);
            throw $e;
        }

        $this->dispatcher ??= new WorkflowStepDispatcher();
        $refusal = $this->dispatcher->dispatch($wfJob, $wjs, $step, $mappedVars);
        if ($refusal !== null) {
            $this->failStep($wfJob, $wjs, $refusal['reason'], $refusal['denied']);
            return $wjs;
        }
        $ended = $this->dispatcher->settleStart($wfJob, $wjs);
        if ($ended !== null) {
            $this->onChildJobCompleted($ended);
            $wjs->refresh();
        }

        return $wjs;
    }

    /**
     * Called when a child job ended (its runner reported, it timed out or
     * failed before it ran, or it was canceled), and by executeStep() for a
     * job that ended before its step was linked to it. Its step, if it still
     * runs, ends with the job's outcome (the workflow.step.completed audit
     * entry, and the workflow.step_failed notification when it failed), and
     * the run moves on. A job that never ran because its approval was
     * rejected or canceled while it waited is a closed gate: the step fails
     * with why (WorkflowStepRouter::closedGate()).
     */
    public function onChildJobCompleted(Job $job): void
    {
        /** @var WorkflowJobStep|null $wjs */
        $wjs = WorkflowJobStep::findOne(['job_id' => $job->id, 'status' => WorkflowJobStep::STATUS_RUNNING]);
        if ($wjs === null) {
            return;
        }
        $gate = $this->router()->closedGate($wjs, $job);
        $this->endStep($wjs, $job, $gate === null && $job->status === Job::STATUS_SUCCEEDED, $gate);
    }

    /**
     * Called after an approval request of a workflow step was decided, with
     * the request's final status committed.
     *
     * - Approved, an approval step: the step succeeds; the run follows its
     *   Always branch, else its On Success branch, else the next step.
     * - Approved, a job step whose job waited for approval: nothing here; the
     *   job is queued, and its completion ends the step.
     * - Rejected or timed out (rejecting): the step fails with the request's
     *   outcome as its error, and the closed gate is routed
     *   (WorkflowStepRouter): only an explicit On Failure branch goes on.
     *
     * The step ends as one that ends with its job does: the
     * workflow.step.completed audit entry, and the workflow.step_failed
     * notification when it failed (WorkflowRunEvents::stepEnded()).
     */
    public function onApprovalResolved(Job $job, bool $approved): void
    {
        /** @var WorkflowJobStep|null $wjs */
        $wjs = WorkflowJobStep::findOne(['job_id' => $job->id, 'status' => WorkflowJobStep::STATUS_RUNNING]);
        if ($wjs === null || ($approved && !$wjs->isApprovalPlaceholder())) {
            return;
        }
        $gate = $approved ? null : $this->router()->gateMessage($wjs, ApprovalRequest::findOne(['job_id' => $job->id]));
        $this->endStep($wjs, $job, $approved, $gate);
    }

    /**
     * Resume a paused workflow step. The pause step is marked as succeeded
     * and the workflow advances to the next step.
     *
     * Access is checked first, so a user who may not operate the workflow
     * learns nothing about the run's state.
     *
     * @throws WorkflowAccessDeniedException when $userId may not operate every job step
     * @throws \RuntimeException when the run is finished or has no paused step
     */
    public function resume(WorkflowJob $wfJob, int $userId): void
    {
        $this->access()->assertMayOperate($userId, (int)$wfJob->workflow_template_id);
        if ($wfJob->isFinished()) {
            throw new \RuntimeException(self::RUN_FINISHED);
        }

        /** @var WorkflowJobStep[] $running */
        $running = WorkflowJobStep::find()
            ->where(['workflow_job_id' => $wfJob->id, 'status' => WorkflowJobStep::STATUS_RUNNING])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        $paused = array_values(array_filter($running, static fn (WorkflowJobStep $wjs): bool => $wjs->isPauseStep()));
        // Only one of two resumes moves the step on.
        if ($paused === [] || !$this->closeStep($paused[0], true, null)) {
            throw new \RuntimeException(self::NO_PAUSED_STEP);
        }

        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_WORKFLOW_STEP_RESUMED,
            'workflow_job_step',
            $paused[0]->id,
            $userId,
            ['step_name' => $paused[0]->stepName()]
        );

        $this->advance($paused[0], true, false);
    }

    /**
     * Cancel a running workflow (WorkflowCancellationService::cancelRun()):
     * its running steps fail, their jobs are canceled with their pending
     * approval requests. Access is checked first, as for {@see resume()}.
     *
     * @throws WorkflowAccessDeniedException when $userId may not operate every job step
     * @throws \RuntimeException when the run is already finished
     */
    public function cancel(WorkflowJob $wfJob, int $userId): void
    {
        $this->access()->assertMayOperate($userId, (int)$wfJob->workflow_template_id);

        /** @var WorkflowCancellationService $cancellation */
        $cancellation = \Yii::$app->get('workflowCancellationService');
        if (!$cancellation->cancelRun($wfJob, $userId, JobCancellationService::REASON_OPERATOR)) {
            throw new \RuntimeException(self::RUN_FINISHED);
        }
    }

    /**
     * End a running workflow with a final status: one conditional UPDATE, so
     * a run that ended meanwhile (it was canceled) keeps its outcome, and the
     * audit entry and the notification are written once. $wfJob is refreshed.
     *
     * @return bool whether this call ended the run
     */
    public function completeWorkflow(WorkflowJob $wfJob, string $status): bool
    {
        $now = time();
        $changed = WorkflowJob::updateAll(
            ['status' => $status, 'finished_at' => $now, 'updated_at' => $now],
            ['id' => $wfJob->id, 'status' => WorkflowJob::STATUS_RUNNING]
        ) === 1;
        $wfJob->refresh();
        if (!$changed) {
            return false;
        }

        $auditAction = $status === WorkflowJob::STATUS_SUCCEEDED
            ? AuditLog::ACTION_WORKFLOW_COMPLETED
            : AuditLog::ACTION_WORKFLOW_FAILED;

        \Yii::$app->get('auditService')->log(
            $auditAction,
            'workflow_job',
            $wfJob->id,
            null,
            ['status' => $status]
        );

        $event = match ($status) {
            WorkflowJob::STATUS_SUCCEEDED => NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED,
            WorkflowJob::STATUS_CANCELED => NotificationTemplate::EVENT_WORKFLOW_CANCELED,
            default => NotificationTemplate::EVENT_WORKFLOW_FAILED,
        };
        (new WorkflowRunEvents())->notify($event, $wfJob);

        return true;
    }

    /**
     * The launch transaction (see launch()); runs with the template's row locked.
     *
     * @param array<string, mixed> $overrides
     * @return array{run: WorkflowJob, start: WorkflowStep}
     * @throws WorkflowLaunchRejectedException
     * @throws \RuntimeException
     */
    private function createRun(WorkflowTemplate $template, int $launchedBy, #[\SensitiveParameter] array $overrides): array
    {
        $templateId = (int)$template->id;
        if (!WorkflowTemplate::lockLive($templateId)) {
            throw new WorkflowLaunchRejectedException(sprintf(self::WORKFLOW_TEMPLATE_DELETED, $templateId));
        }
        // Read under the lock: a step change takes it too.
        $startStep = $template->getStartStep();
        if ($startStep === null) {
            throw new WorkflowLaunchRejectedException(sprintf(self::NO_STEPS, $templateId));
        }

        $wfJob = new WorkflowJob();
        $wfJob->workflow_template_id = $templateId;
        $wfJob->launched_by = $launchedBy;
        $wfJob->status = WorkflowJob::STATUS_RUNNING;
        $wfJob->started_at = time();
        $wfJob->created_at = time();
        $wfJob->updated_at = time();

        /** @var WorkflowRunVars $runVars */
        $runVars = \Yii::$app->get('workflowRunVars');
        $runVars->storeLaunchVars($template, $wfJob, $overrides);
        /** @var ApproverEligibility $eligibility */
        $eligibility = \Yii::$app->get('approverEligibility');
        $refusal = $eligibility->workflowLaunchRefusal($template, $launchedBy);
        if ($refusal !== null) {
            throw new WorkflowLaunchRejectedException($refusal);
        }

        if (!$wfJob->save()) {
            throw new \RuntimeException(
                'Failed to create workflow job: ' . json_encode($wfJob->errors)
            );
        }

        return ['run' => $wfJob, 'start' => $startStep];
    }

    /**
     * End $wjs, which ended with $job (its runner's report, a cancel, an
     * approval decision): when this call moved it out of running, the
     * workflow.step.completed audit entry and, for a step that failed, the
     * workflow.step_failed notification, then the run moves on. $gate: why
     * the step's approval gate did not open, or null.
     */
    private function endStep(WorkflowJobStep $wjs, Job $job, bool $succeeded, ?string $gate): void
    {
        if (!$this->closeStep($wjs, $succeeded, $gate)) {
            return;
        }
        // The run exists: its executions go with it (foreign key).
        /** @var WorkflowJob $wfJob */
        $wfJob = $wjs->workflowJob;
        (new WorkflowRunEvents())->stepEnded($wfJob, $wjs, $job);

        $this->advance($wjs, $succeeded, $gate !== null);
    }

    /**
     * Move $wjs out of running: succeeded, or failed with $error when it
     * failed for a reason the job does not show. Whether this call moved it.
     * $wjs is refreshed.
     */
    private function closeStep(WorkflowJobStep $wjs, bool $succeeded, ?string $error): bool
    {
        $now = time();
        $columns = [
            'status' => $succeeded ? WorkflowJobStep::STATUS_SUCCEEDED : WorkflowJobStep::STATUS_FAILED,
            'finished_at' => $now,
            'updated_at' => $now,
        ];
        if ($error !== null) {
            $columns['error_message'] = mb_substr($error, 0, 500);
        }
        $changed = WorkflowJobStep::updateAll(
            $columns,
            ['id' => $wjs->id, 'status' => WorkflowJobStep::STATUS_RUNNING]
        ) === 1;
        $wjs->refresh();

        return $changed;
    }

    /**
     * Move the run of $wjs on after the step ended: start the next step, or
     * end the run. A run that ended meanwhile (it was canceled) stays as it is.
     */
    private function advance(WorkflowJobStep $wjs, bool $succeeded, bool $gateClosed): void
    {
        /** @var WorkflowJob|null $wfJob */
        $wfJob = WorkflowJob::findOne(['id' => $wjs->workflow_job_id]);
        if ($wfJob === null || $wfJob->isFinished()) {
            return;
        }

        /** @var WorkflowStep|null $step */
        $step = $wjs->workflowStep;
        $route = $step === null
            ? ['next' => null, 'error' => self::STEP_REMOVED]
            : $this->router()->route($step, $succeeded, $gateClosed);
        if ($route['error'] !== null) {
            $this->noteStepError($wjs, $route['error']);
        }
        $next = $route['next'];
        if ($next === null) {
            $this->completeWorkflow(
                $wfJob,
                $succeeded && $route['error'] === null ? WorkflowJob::STATUS_SUCCEEDED : WorkflowJob::STATUS_FAILED
            );
            return;
        }

        /** @var WorkflowRunVars $runVars */
        $runVars = \Yii::$app->get('workflowRunVars');
        $this->executeStep($wfJob, $next, $runVars->stepMapping($wjs, $next));
    }

    /**
     * Add why the run could not go on after $wjs to the step's error.
     */
    private function noteStepError(WorkflowJobStep $wjs, string $error): void
    {
        WorkflowJobStep::updateAll(
            ['error_message' => mb_substr(trim($wjs->error_message . ' ' . $error), 0, 500), 'updated_at' => time()],
            ['id' => $wjs->id]
        );
        $wjs->refresh();
    }

    /**
     * End a step that could not start, and with it the workflow, without
     * following its branches. A denial ($denied context) is audited.
     *
     * @param array<string, int>|null $denied
     */
    private function failStep(WorkflowJob $wfJob, WorkflowJobStep $wjs, string $reason, ?array $denied): void
    {
        $this->closeStep($wjs, false, $reason);
        \Yii::warning("Workflow job #{$wfJob->id} step #{$wjs->id}: {$reason}", __CLASS__);

        if ($denied !== null) {
            \Yii::$app->get('auditService')->log(
                AuditLog::ACTION_WORKFLOW_STEP_DENIED,
                'workflow_job_step',
                $wjs->id,
                (int)$wfJob->launched_by,
                array_merge(['workflow_job_id' => (int)$wfJob->id, 'workflow_step_id' => (int)$wjs->workflow_step_id], $denied)
            );
        }
        $this->completeWorkflow($wfJob, WorkflowJob::STATUS_FAILED);
    }

    private function access(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');
        return $checker;
    }

    private function router(): WorkflowStepRouter
    {
        /** @var WorkflowStepRouter $router */
        $router = \Yii::$app->get('workflowStepRouter');
        return $router;
    }
}
