<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\Project;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\services\ApprovalDecisionRefusedException;
use app\services\JobCancellationService;
use app\services\JobTemplateDeletionService;
use app\services\ProjectDeletionService;
use app\services\WorkflowStepRouter;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Regression: deleting a project whose job templates had been soft-deleted
 * failed with "Integrity constraint violation" (HTTP 500). The guard counted
 * only visible templates (JobTemplate::find() hides deleted_at != null) while
 * the RESTRICT foreign key still saw the rows.
 *
 * The purge of those templates sets the job_template_id of their jobs to
 * NULL (foreign key), and a job without a template never runs; so their
 * waiting jobs are canceled first, as the template delete cancels them.
 */
class ProjectDeletionServiceTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use WorkflowEngineFixtures;
    use StrandedRunFixtures;

    private RecordingNotificationDispatcher $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        $this->restoreSwappedServices();
        $this->restoreNotifications();
        parent::tearDown();
    }

    private function service(): ProjectDeletionService
    {
        return new ProjectDeletionService();
    }

    public function testActiveTemplatesBlockDeletion(): void
    {
        $user = $this->createUser('pds-active');
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);

        $svc = $this->service();
        $this->assertSame(1, $svc->blockingTemplateCount($project));
        $refusal = "Cannot delete \"{$project->name}\": 1 job template(s) still reference this project. Remove or reassign them first.";
        $this->assertSame($refusal, $svc->delete($project));

        $this->assertNotNull(Project::findOne($project->id), 'project must survive');
        $this->assertNotNull(JobTemplate::findOne($template->id), 'active template must survive');
        $this->assertSame($refusal, $svc->refusalMessage($project, 1));
        $this->assertSame(sprintf(ProjectDeletionService::REFUSED, $project->name, 1), $svc->refusalMessage($project, 1));
    }

    public function testSoftDeletedTemplatesDoNotBlockAndArePurgedWithTheProject(): void
    {
        $user = $this->createUser('pds-soft');
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $t1 = $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);
        $t2 = $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);
        $this->assertTrue($t1->softDelete());
        $this->assertTrue($t2->softDelete());

        $svc = $this->service();
        $this->assertSame(0, $svc->blockingTemplateCount($project), 'soft-deleted templates must not block');
        $this->assertNull($svc->delete($project));

        $this->assertNull(Project::findOne($project->id));
        $this->assertSame(
            0,
            (int)JobTemplate::findWithDeleted()->where(['id' => [$t1->id, $t2->id]])->count(),
            'soft-deleted rows must be purged so the RESTRICT foreign key is satisfied'
        );
    }

    public function testMixedActiveAndSoftDeletedTemplatesStillBlock(): void
    {
        $user = $this->createUser('pds-mixed');
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $active = $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);
        $gone = $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);
        $this->assertTrue($gone->softDelete());
        $job = $this->createJob((int)$gone->id, $user->id, Job::STATUS_QUEUED);

        $svc = $this->service();
        $this->assertSame(1, $svc->blockingTemplateCount($project));
        $this->assertSame(sprintf(ProjectDeletionService::REFUSED, $project->name, 1), $svc->delete($project));

        $this->assertNotNull(Project::findOne($project->id));
        $this->assertNotNull(JobTemplate::findOne($active->id));
        // Nothing is purged while the deletion is refused.
        $this->assertNotNull(JobTemplate::findWithDeleted()->where(['id' => $gone->id])->one());
        $this->assertSame(Job::STATUS_QUEUED, Job::findOne($job->id)?->status, 'and nothing is canceled');
    }

    public function testDeleteOfProjectWithoutTemplatesSucceeds(): void
    {
        $user = $this->createUser('pds-plain');
        $project = $this->createProject($user->id);

        $this->assertNull($this->service()->delete($project));
        $this->assertNull(Project::findOne($project->id));
    }

    public function testFailureInsideTransactionRollsBackThePurge(): void
    {
        $user = $this->createUser('pds-rollback');
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $gone = $this->createJobTemplate((int)$project->id, (int)$inv->id, (int)$group->id, $user->id);
        $this->assertTrue($gone->softDelete());

        // A project double whose delete() blows up after the purge ran.
        $exploding = new class () extends Project {
            public static function tableName(): string
            {
                return Project::tableName();
            }

            public function delete(): int|false
            {
                throw new \RuntimeException('boom');
            }
        };
        $exploding->setAttributes($project->getAttributes(), false);
        $exploding->setOldAttributes($project->getOldAttributes());
        $exploding->setIsNewRecord(false);

        try {
            $this->service()->delete($exploding);
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertNotNull(Project::findOne($project->id), 'project must still exist after rollback');
        $this->assertNotNull(
            JobTemplate::findWithDeleted()->where(['id' => $gone->id])->one(),
            'purged template rows must be restored by the rollback'
        );
    }

    // -- The waiting jobs of the purged templates ----------------------------------------------

    /**
     * Regression: a waiting job of a purged job template could be approved
     * and stayed queued. Its template had been deleted while the job waited
     * for approval, without the delete's cancel (a soft delete through the
     * model, a delete before this release, or a cancel that failed). The
     * project delete purged the template, the foreign key set the job's
     * job_template_id to NULL, and the job still waited: an approval queued
     * it, and no runner claims a job without a template. Now the project
     * delete cancels it first, as the template delete does, and nothing can
     * approve it any more.
     */
    public function testRegressionAWaitingJobOfAPurgedJobTemplateCouldBeApprovedAndStayedQueued(): void
    {
        ['admin' => $admin, 'approver' => $approver, 'launcher' => $launcher] = $this->people();
        $template = $this->cancelFixtureTemplate((int)$admin->id);
        ['job' => $job, 'request' => $request, 'rule' => $rule] = $this->jobWaitingForApproval($template, (int)$launcher->id, (int)$approver->id);
        $this->assertTrue($template->softDelete(), 'deleted without the cancel of its waiting jobs');

        $this->assertNull($this->service()->delete($this->projectOf($template)));

        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertNull($job->job_template_id, 'the template was purged');
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $this->assertSame(
            ['job_id' => (int)$job->id, 'rule_id' => (int)$rule->id, 'reason' => JobCancellationService::REASON_TEMPLATE_DELETED],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_APPROVAL_CANCELED, 'approval_request', (int)$request->id))
        );
        $this->assertSame(
            ['reason' => JobCancellationService::REASON_TEMPLATE_DELETED],
            $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$job->id))
        );

        // The approver's vote cannot queue it any more.
        try {
            $this->approvalService()->recordDecision($request, (int)$approver->id, ApprovalDecision::DECISION_APPROVED, null);
            $this->fail('the vote is refused');
        } catch (ApprovalDecisionRefusedException $e) {
            $this->assertSame(409, $e->httpStatus());
        }
        $job->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $job->status, 'never queued');
        $this->assertNull($job->queued_at);
    }

    /**
     * Every waiting job of the project's deleted templates is canceled,
     * before the purge: the job.canceled notification still names its
     * template. Running and finished jobs are left alone (they lose the
     * template, as before), and so is a waiting job of another project.
     */
    public function testTheWaitingJobsOfThePurgedTemplatesAreCanceledBeforeThePurge(): void
    {
        ['admin' => $admin, 'launcher' => $launcher] = $this->people();
        $first = $this->cancelFixtureTemplate((int)$admin->id);
        $project = $this->projectOf($first);
        $second = $this->templateIn($project, (int)$admin->id);
        $waiting = [];
        foreach (Job::waitingStatuses() as $status) {
            $waiting[] = $this->createJob((int)$first->id, (int)$launcher->id, $status);
        }
        $waiting[] = $this->createJob((int)$second->id, (int)$launcher->id, Job::STATUS_QUEUED);
        $running = $this->createJob((int)$second->id, (int)$launcher->id, Job::STATUS_RUNNING);
        $finished = $this->createJob((int)$first->id, (int)$launcher->id, Job::STATUS_SUCCEEDED);
        $elsewhere = $this->cancelFixtureTemplate((int)$admin->id);
        $otherJob = $this->createJob((int)$elsewhere->id, (int)$launcher->id, Job::STATUS_QUEUED);
        foreach ([$first, $second, $elsewhere] as $template) {
            $this->assertTrue($template->softDelete());
        }

        $this->assertNull($this->service()->delete($project));

        foreach ($waiting as $job) {
            $job->refresh();
            $this->assertSame(Job::STATUS_CANCELED, $job->status);
            $this->assertNull($job->job_template_id);
        }
        $this->assertSame(Job::STATUS_RUNNING, Job::findOne($running->id)?->status);
        $this->assertSame(Job::STATUS_SUCCEEDED, Job::findOne($finished->id)?->status);
        $otherJob->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $otherJob->status, 'another project\'s job is left alone');
        $this->assertSame((int)$elsewhere->id, (int)$otherJob->job_template_id);
        $payloads = $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_CANCELED);
        $this->assertCount(count($waiting), $payloads);
        $template = $payloads[0]['template'] ?? null;
        $this->assertIsArray($template);
        $this->assertSame((string)$first->name, $template['name'] ?? null, 'canceled while the template still existed');
    }

    /**
     * The job of a gated job step whose template is purged: canceled with
     * its request, its step ends as a gate that never opened, and without an
     * On Failure branch the run ends as failed.
     */
    public function testTheJobOfAWorkflowStepEndsItsStepAsAClosedGate(): void
    {
        ['admin' => $admin, 'approver' => $approver, 'launcher' => $launcher] = $this->people();
        $gated = $this->gatedTemplate((int)$admin->id, $this->usersRuleOf((int)$admin->id, (int)$approver->id));
        ['run' => $run, 'step' => $step, 'next' => $pause, 'job' => $job] = $this->runWaitingForItsJob($launcher, $gated);
        $request = $this->requestOfJob($job);
        $this->assertTrue($gated->softDelete());

        $this->assertNull($this->service()->delete($this->projectOf($gated)));

        $this->assertSame(Job::STATUS_CANCELED, Job::findOne($job->id)?->status);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $request->status);
        $execution = $this->executionOfStep($run, $step);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(sprintf(WorkflowStepRouter::JOB_APPROVAL_CANCELED, (int)$request->id), $execution->error_message);
        $this->assertNull($this->runOf($run, $pause), 'the step behind the gate never runs');
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    /**
     * A cancel that fails is logged without values, the other jobs are
     * canceled and the project is deleted. The job left without a template
     * never runs and is canceled by the next maintenance run
     * (StrandedJobSweep), for the purge.
     */
    public function testACancelThatFailsIsLoggedAndTheMaintenanceRunCancelsTheJobLater(): void
    {
        ['admin' => $admin, 'launcher' => $launcher] = $this->people();
        $template = $this->cancelFixtureTemplate((int)$admin->id);
        $broken = $this->createJob((int)$template->id, (int)$launcher->id, Job::STATUS_QUEUED);
        $fine = $this->createJob((int)$template->id, (int)$launcher->id, Job::STATUS_QUEUED);
        $this->assertTrue($template->softDelete());
        $this->swapService('jobCancellationService', $this->cancellationFailingFor((int)$broken->id, $this->deadlock()));

        $logged = $this->errorsLoggedBy(
            JobTemplateDeletionService::class,
            fn (): ?string => $this->service()->delete($this->projectOf($template))
        );

        $this->assertNull($logged['result'], 'deleted');
        $this->assertSame(
            [sprintf(JobTemplateDeletionService::CANCEL_FAILED, $broken->id, $template->id, \yii\db\Exception::class, '40001', '1213')],
            $logged['errors']
        );
        $this->assertSame(Job::STATUS_CANCELED, Job::findOne($fine->id)?->status);
        $broken->refresh();
        $this->assertSame(Job::STATUS_QUEUED, $broken->status);
        $this->assertNull($broken->job_template_id, 'purged all the same');
        $this->assertNull(Project::findOne($template->project_id));

        $this->restoreSwappedServices();
        Job::updateAll(['created_at' => time() - 86400], ['id' => $broken->id]);
        $this->assertSame(1, $this->strandedJobSweep()->sweep()['stranded_jobs_canceled']);
        $this->assertSame(Job::STATUS_CANCELED, Job::findOne($broken->id)?->status);
        $entry = $this->onlyAuditOf(AuditLog::ACTION_JOB_CANCELED, 'job', (int)$broken->id);
        $this->assertNull($entry->user_id);
        $this->assertSame(['reason' => JobCancellationService::REASON_TEMPLATE_DELETED], $this->contextOf($entry));
    }

    /**
     * Regression: the purge deleted every template row of the project, so a
     * template created after the count was purged with the deleted ones
     * (and the jobs it had launched lost it). Only soft-deleted rows are
     * purged now: such a template stays, the project's delete fails on the
     * foreign key, and the whole delete is rolled back.
     *
     * Regression: that foreign key error then ended the request in HTTP 500
     * (yii\db\IntegrityException). The delete is refused instead, with the
     * number of active templates counted after the rollback. The waiting
     * jobs of the deleted templates, canceled before the purge, stay
     * canceled.
     */
    public function testRegressionATemplateCreatedAfterTheCountWasPurgedWithTheDeletedOnes(): void
    {
        $user = $this->createUser('pds-race');
        $gone = $this->cancelFixtureTemplate((int)$user->id);
        $project = $this->projectOf($gone);
        $waiting = $this->createJob((int)$gone->id, (int)$user->id, Job::STATUS_QUEUED);
        $this->assertTrue($gone->softDelete());
        $created = $this->templateIn($project, (int)$user->id);
        $job = $this->createJob((int)$created->id, (int)$user->id, Job::STATUS_QUEUED);

        $refusal = $this->staleCountService()->delete($project);

        $this->assertSame(
            "Cannot delete \"{$project->name}\": 1 job template(s) still reference this project. Remove or reassign them first.",
            $refusal
        );
        $this->assertNotNull(JobTemplate::findOne($created->id), 'the live template is not purged');
        $this->assertNotNull(Project::findOne($project->id));
        $this->assertNotNull(JobTemplate::findWithDeleted()->where(['id' => $gone->id])->one(), 'rolled back');
        $job->refresh();
        $this->assertSame((int)$created->id, (int)$job->job_template_id);
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $waiting->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $waiting->status, 'canceled for its deleted template before the purge');
        $this->assertSame((int)$gone->id, (int)$waiting->job_template_id, 'which the rollback keeps');
    }

    /**
     * The insert of a template created during the delete can also make the
     * database end the delete's transaction with a deadlock: while an
     * active template references the project afterwards, that is the
     * refusal too.
     */
    public function testADeadlockWhileAnActiveTemplateReferencesTheProjectIsTheRefusal(): void
    {
        $user = $this->createUser('pds-deadlock');
        $project = $this->createProject((int)$user->id);
        $created = $this->templateIn($project, (int)$user->id);

        $refusal = $this->staleCountService()->delete($this->failingDelete($project, $this->deadlock()));

        $this->assertSame(sprintf(ProjectDeletionService::REFUSED, $project->name, 1), $refusal);
        $this->assertNotNull(Project::findOne($project->id));
        $this->assertNotNull(JobTemplate::findOne($created->id));
    }

    /**
     * A database error is no refusal while no active template references
     * the project: it is passed on, and the purge is rolled back.
     */
    public function testADatabaseErrorWithoutAnActiveTemplateIsPassedOn(): void
    {
        $user = $this->createUser('pds-db-error');
        $gone = $this->cancelFixtureTemplate((int)$user->id);
        $project = $this->projectOf($gone);
        $this->assertTrue($gone->softDelete());
        $error = $this->deadlock();

        try {
            $this->service()->delete($this->failingDelete($project, $error));
            $this->fail('the error is passed on');
        } catch (\yii\db\Exception $e) {
            $this->assertSame($error, $e);
        }

        $this->assertNotNull(Project::findOne($project->id));
        $this->assertNotNull(JobTemplate::findWithDeleted()->where(['id' => $gone->id])->one(), 'rolled back');
    }

    // -- Helpers -----------------------------------------------------------------------------

    /**
     * An admin, a launcher and an approver (operators may decide).
     *
     * @return array{admin: User, launcher: User, approver: User}
     */
    private function people(): array
    {
        return [
            'admin' => $this->createUserWithRole('pds-admin', 'admin'),
            'launcher' => $this->createUserWithRole('pds-launcher', 'operator'),
            'approver' => $this->createUserWithRole('pds-approver', 'operator'),
        ];
    }

    private function projectOf(JobTemplate $template): Project
    {
        $project = Project::findOne($template->project_id);
        $this->assertNotNull($project);

        return $project;
    }

    /**
     * A ProjectDeletionService whose count before anything is done ran
     * before the project's latest template was created: it finds none.
     */
    private function staleCountService(): ProjectDeletionService
    {
        return new class () extends ProjectDeletionService {
            public function blockingTemplateCount(Project $project): int
            {
                return 0;
            }
        };
    }

    /**
     * $project, whose delete throws $error (after the purge ran).
     */
    private function failingDelete(Project $project, \Throwable $error): Project
    {
        $failing = new class () extends Project {
            public ?\Throwable $error = null;

            public static function tableName(): string
            {
                return Project::tableName();
            }

            public function delete(): int|false
            {
                throw $this->error ?? new \LogicException('no error set');
            }
        };
        $failing->error = $error;
        $failing->setAttributes($project->getAttributes(), false);
        $failing->setOldAttributes($project->getOldAttributes());
        $failing->setIsNewRecord(false);

        return $failing;
    }

    /**
     * Another job template of $project.
     */
    private function templateIn(Project $project, int $userId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$project->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    /**
     * A JobCancellationService whose cancel() of job #$brokenJobId throws
     * $error; every other cancel is real.
     */
    private function cancellationFailingFor(int $brokenJobId, \Throwable $error): JobCancellationService
    {
        return new class ($brokenJobId, $error) extends JobCancellationService {
            public function __construct(private readonly int $brokenJobId, private readonly \Throwable $error)
            {
                parent::__construct();
            }

            public function cancel(Job $job, ?int $actorId, string $reason = self::REASON_OPERATOR): bool
            {
                if ((int)$job->id === $this->brokenJobId) {
                    throw $this->error;
                }

                return parent::cancel($job, $actorId, $reason);
            }
        };
    }
}
