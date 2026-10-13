<?php

declare(strict_types=1);

namespace app\commands;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\MaintenanceService;
use yii\caching\CacheInterface;
use yii\db\Query;

/**
 * The fixture of the E2E spec for an approval request whose job's job
 * template was deleted while the job waited for approval
 * (tests/e2e/tests/approvals/deleted-template.spec.ts): such a job no
 * longer waits, so its request can no longer be decided.
 *
 * - e2e-deleted-gate-template: soft-deleted, gated by e2e-approval-rule
 *   (e2e-admin and e2e-operator approve), on the project, inventory and
 *   runner group of e2e-template. Every seed restores it, deleted, without
 *   jobs.
 *
 * Deleting a template cancels its waiting jobs right after its commit, and
 * the maintenance run's sweep cancels those it left; a vote or a timeout
 * that comes first finds the job no longer waiting. A job that waits on a
 * deleted template therefore exists only for a moment, and a seeded one
 * would not last until the spec runs: waitingJob() makes it right before
 * the spec needs it (the YII_DEBUG hook /e2e/create-deleted-template-request
 * calls it), and keeps the sweep of deleted templates off meanwhile.
 */
final class E2eDeletedTemplateApprovalSeeder
{
    public const TEMPLATE = 'e2e-deleted-gate-template';

    public const DESCRIPTION = 'E2E fixture: deleted while a job of it waited for approval';

    /**
     * Seconds waitingJob() keeps the maintenance run's sweep of deleted
     * templates off, which would cancel the job: long enough for the spec,
     * short enough not to matter to the sweep's 10-minute cycle.
     */
    public const SWEEP_HOLD_SECONDS = 120;

    /** sprintf(): the template's name. */
    public const NOT_SEEDED = 'Job template %s is not seeded as a deleted template with an approval rule: run php yii e2e/seed.';

    /** sprintf(): the username. */
    public const NO_USER = 'User %s not found.';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Restore the template, which $userId (e2e-admin) created, gated by the
     * rule #$ruleId (e2e-approval-rule), on the parents of job template
     * #$jobTemplateId (e2e-template): soft-deleted, without jobs. Their
     * approval requests and logs go with them (foreign keys), tasks do not.
     *
     * @throws \RuntimeException when job template #$jobTemplateId does not exist
     */
    public function seed(int $userId, int $jobTemplateId, int $ruleId): void
    {
        $source = JobTemplate::findOne($jobTemplateId);
        if ($source === null) {
            throw new \RuntimeException("Job template #{$jobTemplateId} not found.");
        }
        $parents = [0 => (int)$source->project_id, 1 => (int)$source->inventory_id, 2 => (int)$source->runner_group_id];
        /** @var JobTemplate|null $previous */
        $previous = JobTemplate::findWithDeleted()->where(['name' => self::TEMPLATE])->orderBy(['id' => SORT_DESC])->one();
        $previous?->updateAttributes(['deleted_at' => null]);
        $template = E2eFixtureHelper::template(self::TEMPLATE, $parents, $userId, null, []);
        $template->description = self::DESCRIPTION;
        $template->approval_rule_id = $ruleId;
        $template->extra_vars = null;
        $template->survey_fields = null;
        $template->limit = null;
        $template->trigger_token = null;
        $template->trigger_token_created_by = null;
        $template->save(false);
        $this->deleteJobs((int)$template->id);
        // Directly, as a delete whose cancel of the waiting jobs has not run yet.
        $template->updateAttributes(['deleted_at' => time()]);

        ($this->logger)(sprintf(
            "  Restored %s (ID %d), gated by approval rule #%d: deleted, without jobs.\n",
            self::TEMPLATE,
            $template->id,
            $ruleId
        ));
    }

    /**
     * A job of the deleted template that $launcher launched and that waits
     * for approval (pending_approval), and its pending request on the
     * template's rule, which expires after the rule's timeout. Holds off the
     * maintenance run's sweep of deleted templates for SWEEP_HOLD_SECONDS
     * first: it would cancel the job.
     *
     * @return array{job_id: int, request_id: int}
     * @throws \RuntimeException when the template is not seeded or the user does not exist
     */
    public function waitingJob(string $launcher): array
    {
        [$template, $rule] = $this->deletedTemplate();
        $user = User::findOne(['username' => $launcher]);
        if ($user === null) {
            throw new \RuntimeException(sprintf(self::NO_USER, $launcher));
        }
        /** @var CacheInterface $cache */
        $cache = \Yii::$app->cache;
        $cache->set(MaintenanceService::DELETED_TEMPLATE_SWEEP_KEY, time(), self::SWEEP_HOLD_SECONDS);

        /** @var array{job_id: int, request_id: int} $ids */
        $ids = Job::getDb()->transaction(fn (): array => $this->insertWaitingJob($template, $rule, (int)$user->id));

        return $ids;
    }

    /**
     * The newest soft-deleted template of that name and its rule.
     *
     * @return array{0: JobTemplate, 1: ApprovalRule}
     * @throws \RuntimeException when there is no such template, or it has no rule
     */
    private function deletedTemplate(): array
    {
        /** @var JobTemplate|null $template */
        $template = JobTemplate::findWithDeleted()
            ->where(['name' => self::TEMPLATE])
            ->andWhere(['not', ['deleted_at' => null]])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $rule = $template?->approvalRule;
        if ($template === null || $rule === null) {
            throw new \RuntimeException(sprintf(self::NOT_SEEDED, self::TEMPLATE));
        }

        return [0 => $template, 1 => $rule];
    }

    /**
     * The job and its request, written together: a job that waits for
     * approval without a request is canceled by the maintenance run.
     *
     * @return array{job_id: int, request_id: int}
     */
    private function insertWaitingJob(JobTemplate $template, ApprovalRule $rule, int $launchedBy): array
    {
        $now = time();
        $job = new Job();
        $job->job_template_id = (int)$template->id;
        $job->launched_by = $launchedBy;
        $job->status = Job::STATUS_PENDING_APPROVAL;
        $job->timeout_minutes = 30;
        $job->has_changes = 0;
        $job->save(false);

        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = $now;
        $request->expires_at = $rule->timeout_minutes === null ? null : $now + 60 * (int)$rule->timeout_minutes;
        $request->save(false);

        return ['job_id' => (int)$job->id, 'request_id' => (int)$request->id];
    }

    /**
     * Deletes the jobs of the template: logs, artifacts and approval requests
     * go with them (foreign keys), tasks do not.
     */
    private function deleteJobs(int $templateId): void
    {
        $db = \Yii::$app->db;
        $jobIds = (new Query())->select('id')->from('{{%job}}')->where(['job_template_id' => $templateId])->column($db);
        if ($jobIds !== []) {
            $db->createCommand()->delete('{{%job_task}}', ['job_id' => $jobIds])->execute();
            Job::deleteAll(['id' => $jobIds]);
        }
    }
}
