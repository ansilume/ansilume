<?php

declare(strict_types=1);

namespace app\commands;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use yii\rbac\ManagerInterface;

/**
 * Seeds the approval fixtures of the E2E specs, restored by name on every
 * seed so specs may rely on their state:
 *
 * - e2e-approval-rule, which also gates e2e-approval-workflow: e2e-admin and
 *   e2e-operator approve. The launcher never approves their own job, so a
 *   launch by e2e-admin needs e2e-operator to decide, and the other way round.
 * - e2e-gated-template, which that rule gates.
 * - e2e-solo-gated-template, gated by e2e-solo-approval-rule, whose only
 *   approver is e2e-admin: a launch by e2e-admin is refused.
 * - e2e-history-gated-template, gated by e2e-history-approval-rule, with one
 *   job that waits on a request that expired two hours ago (the next
 *   maintenance run times it out), one job that was canceled while it
 *   waited, so its request is canceled, and one job that ran once its request
 *   was approved, whose rule was deleted since. e2e-admin, who launched it,
 *   had voted for it before e2e-operator, when the launcher's votes still
 *   counted.
 * - e2e-dual-gated-template, gated by e2e-dual-approval-rule, which needs
 *   two approvals: e2e-admin's and e2e-operator's. No jobs: the user
 *   e2e-approval-launcher, an operator who is neither of the two, launches
 *   it in a spec, so the request waits for both of them, and one who voted
 *   sees it still pending.
 * - e2e-deleted-template, soft-deleted, with the disabled schedule
 *   e2e-deleted-template-schedule. Disabled: the maintenance run disables
 *   the enabled schedules of deleted templates anyway.
 *
 * The templates share the project, inventory and runner group of the job
 * template the seed passes (e2e-template). e2e-approval-launcher joins every
 * team that may operate that project, so the seed runs after the teams got
 * their projects (E2eController::seedTeam()).
 */
class E2eApprovalSeeder
{
    public const RULE = 'e2e-approval-rule';
    public const GATED_TEMPLATE = 'e2e-gated-template';
    public const SOLO_RULE = 'e2e-solo-approval-rule';
    public const SOLO_TEMPLATE = 'e2e-solo-gated-template';
    public const HISTORY_RULE = 'e2e-history-approval-rule';
    public const HISTORY_TEMPLATE = 'e2e-history-gated-template';
    public const DUAL_RULE = 'e2e-dual-approval-rule';
    public const DUAL_TEMPLATE = 'e2e-dual-gated-template';
    public const DELETED_TEMPLATE = 'e2e-deleted-template';
    public const DELETED_SCHEDULE = 'e2e-deleted-template-schedule';

    /** The approver of e2e-approval-rule besides e2e-admin. */
    public const SECOND_APPROVER = 'e2e-operator';

    /** How long a request on e2e-approval-rule waits before it is rejected. */
    public const RULE_TIMEOUT_MINUTES = 60;

    /** The approvals e2e-dual-approval-rule needs: e2e-admin's and e2e-operator's. */
    public const DUAL_APPROVALS = 2;

    /**
     * Launches e2e-dual-gated-template: an operator who approves on none of
     * the rules here. Signs in with the password tests/e2e/lib/session.ts
     * names (a test fixture, not a secret).
     */
    public const LAUNCHER = 'e2e-approval-launcher';

    /** The only RBAC role of e2e-approval-launcher: it may launch jobs. */
    public const LAUNCHER_ROLE = 'operator';

    /** sprintf(): the role. */
    public const ROLE_MISSING = 'RBAC role "%s" is missing; run the migrations first.';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Restore e2e-approval-rule: e2e-admin ($userId) and e2e-operator
     * approve, one approval is needed, and a request is rejected after
     * RULE_TIMEOUT_MINUTES. Also a rule an earlier seed created gets these
     * settings.
     *
     * @return int the rule's ID
     */
    public function seedRule(int $userId): int
    {
        $rule = $this->rule(self::RULE, $userId, $this->approvers($userId), 1, self::RULE_TIMEOUT_MINUTES);
        ($this->logger)(sprintf(
            "  Restored approval rule %s (ID %d): e2e-admin and %s approve.\n",
            self::RULE,
            $rule->id,
            self::SECOND_APPROVER
        ));

        return (int)$rule->id;
    }

    /**
     * Restore the job templates, requests and the schedule this class
     * describes. $jobTemplateId is the template whose project, inventory and
     * runner group they use, $ruleId the ID of e2e-approval-rule.
     */
    public function seed(int $userId, int $jobTemplateId, int $ruleId): void
    {
        $parents = $this->parentsOf($jobTemplateId);
        $gated = $this->gatedTemplate(self::GATED_TEMPLATE, $parents, $userId, $ruleId);
        $soloRule = $this->rule(self::SOLO_RULE, $userId, [$userId], 1, null);
        $this->gatedTemplate(self::SOLO_TEMPLATE, $parents, $userId, (int)$soloRule->id);
        $approvers = $this->approvers($userId);
        $historyRule = $this->rule(self::HISTORY_RULE, $userId, $approvers, 1, 1);
        $history = $this->gatedTemplate(self::HISTORY_TEMPLATE, $parents, $userId, (int)$historyRule->id);
        $this->restoreHistory($history, $historyRule, $approvers);
        $this->restoreDualApproval($parents, $userId, $approvers);
        $schedule = $this->deletedTemplateSchedule($parents, $userId);

        ($this->logger)(sprintf(
            "  Restored approval fixtures: %s (ID %d), %s, %s with an expired, a canceled and an approved request,"
            . " schedule %s (ID %d) of the deleted %s.\n",
            self::GATED_TEMPLATE,
            $gated->id,
            self::SOLO_TEMPLATE,
            self::HISTORY_TEMPLATE,
            self::DELETED_SCHEDULE,
            $schedule->id,
            self::DELETED_TEMPLATE
        ));
    }

    /**
     * e2e-admin ($userId) and, when it exists, e2e-operator.
     *
     * @return non-empty-list<int>
     */
    private function approvers(int $userId): array
    {
        $operator = User::findOne(['username' => self::SECOND_APPROVER]);

        return $operator === null ? [$userId] : [$userId, (int)$operator->id];
    }

    /**
     * A rule of the listed users that needs $requiredApprovals approvals and
     * rejects on its timeout (null: never times out), restored by name.
     *
     * @param list<int> $approverIds
     */
    private function rule(string $name, int $userId, array $approverIds, int $requiredApprovals, ?int $timeoutMinutes): ApprovalRule
    {
        $rule = ApprovalRule::findOne(['name' => $name]) ?? new ApprovalRule();
        $rule->name = $name;
        $rule->description = 'E2E approval rule';
        $rule->job_template_id = null;
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_USERS;
        $rule->approver_config = (string)json_encode(['user_ids' => $approverIds]);
        $rule->required_approvals = $requiredApprovals;
        $rule->timeout_minutes = $timeoutMinutes;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;
        $rule->created_by = $userId;
        $rule->save(false);

        return $rule;
    }

    /**
     * The project, inventory and runner group of job template #$jobTemplateId.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function parentsOf(int $jobTemplateId): array
    {
        $template = JobTemplate::findOne($jobTemplateId);
        if ($template === null) {
            throw new \RuntimeException("Job template #{$jobTemplateId} not found.");
        }

        return [0 => (int)$template->project_id, 1 => (int)$template->inventory_id, 2 => (int)$template->runner_group_id];
    }

    /**
     * A template the rule gates, restored by name: a spec may have deleted
     * it, so the newest deleted copy comes back instead of a second one.
     *
     * @param array{0: int, 1: int, 2: int} $parents
     */
    private function gatedTemplate(string $name, array $parents, int $userId, int $ruleId): JobTemplate
    {
        if (JobTemplate::findOne(['name' => $name]) === null) {
            /** @var JobTemplate|null $deleted */
            $deleted = JobTemplate::findWithDeleted()->where(['name' => $name])->orderBy(['id' => SORT_DESC])->one();
            $deleted?->updateAttributes(['deleted_at' => null]);
        }
        $template = E2eFixtureHelper::template($name, $parents, $userId, null, []);
        $template->description = 'E2E fixture: every launch waits for approval';
        $template->approval_rule_id = $ruleId;
        $template->extra_vars = null;
        $template->survey_fields = null;
        $template->limit = null;
        $template->trigger_token = null;
        $template->trigger_token_created_by = null;
        $template->save(false);

        return $template;
    }

    /**
     * Replace the jobs of $template with one that waits on a request that
     * expired two hours ago, one that was canceled while it waited, and one
     * that ran once its request was approved, whose rule was deleted since.
     * The first of $approvers (e2e-admin) launched them all and, on the
     * approved request, voted before e2e-operator did. The requests and
     * decisions of the old jobs go with them (ON DELETE CASCADE).
     *
     * @param non-empty-list<int> $approvers
     */
    private function restoreHistory(JobTemplate $template, ApprovalRule $rule, array $approvers): void
    {
        $userId = $approvers[0];
        Job::deleteAll(['job_template_id' => $template->id]);
        $now = time();

        $waiting = $this->job($template, $userId, Job::STATUS_PENDING_APPROVAL, null);
        $this->request($waiting, $rule, ApprovalRequest::STATUS_PENDING, $now - 7260, $now - 7200, null);

        $canceled = $this->job($template, $userId, Job::STATUS_CANCELED, $now - 3570);
        $this->request($canceled, $rule, ApprovalRequest::STATUS_CANCELED, $now - 3600, $now - 3540, $now - 3570);

        $ran = $this->job($template, $userId, Job::STATUS_SUCCEEDED, $now - 5300);
        $approved = $this->request($ran, null, ApprovalRequest::STATUS_APPROVED, $now - 5460, $now - 5400, $now - 5430);
        // The launcher's vote first, cast before self-approval was refused: it counts for nothing.
        foreach ($approvers as $index => $approverId) {
            $this->decision($approved, $approverId, $now - 5450 + 20 * $index);
        }
    }

    private function job(JobTemplate $template, int $userId, string $status, ?int $finishedAt): Job
    {
        $job = new Job();
        $job->job_template_id = (int)$template->id;
        $job->launched_by = $userId;
        $job->status = $status;
        $job->timeout_minutes = 30;
        $job->has_changes = 0;
        $job->finished_at = $finishedAt;
        $job->save(false);

        return $job;
    }

    /**
     * @param ApprovalRule|null $rule null: the rule was deleted (ON DELETE SET NULL)
     */
    private function request(Job $job, ?ApprovalRule $rule, string $status, int $requestedAt, int $expiresAt, ?int $resolvedAt): ApprovalRequest
    {
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = $rule === null ? null : (int)$rule->id;
        $request->status = $status;
        $request->requested_at = $requestedAt;
        $request->expires_at = $expiresAt;
        $request->resolved_at = $resolvedAt;
        $request->save(false);

        return $request;
    }

    private function decision(ApprovalRequest $request, int $userId, int $createdAt): void
    {
        $decision = new ApprovalDecision();
        $decision->approval_request_id = (int)$request->id;
        $decision->user_id = $userId;
        $decision->decision = ApprovalDecision::DECISION_APPROVED;
        $decision->comment = 'E2E fixture';
        $decision->created_at = $createdAt;
        $decision->save(false);
    }

    /**
     * Restore e2e-dual-approval-rule, which needs DUAL_APPROVALS approvals
     * of $approvers (e2e-admin and e2e-operator; without e2e-operator, every
     * launch of its template is refused) and never times out,
     * e2e-dual-gated-template, which it gates, and its launcher. The jobs of
     * earlier runs go, with their requests and votes: a spec launches a new
     * one every time.
     *
     * @param array{0: int, 1: int, 2: int} $parents
     * @param non-empty-list<int> $approvers
     */
    private function restoreDualApproval(array $parents, int $userId, array $approvers): void
    {
        $rule = $this->rule(self::DUAL_RULE, $userId, $approvers, self::DUAL_APPROVALS, null);
        $template = $this->gatedTemplate(self::DUAL_TEMPLATE, $parents, $userId, (int)$rule->id);
        Job::deleteAll(['job_template_id' => $template->id]);
        $launcherId = $this->launcher($parents[0]);

        ($this->logger)(sprintf(
            "  Restored %s (ID %d): %s needs %d approvals, of e2e-admin and %s; %s (ID %d) launches it.\n",
            self::DUAL_TEMPLATE,
            $template->id,
            self::DUAL_RULE,
            self::DUAL_APPROVALS,
            self::SECOND_APPROVER,
            self::LAUNCHER,
            $launcherId
        ));
    }

    /**
     * Restore e2e-approval-launcher: active, with the password of the specs,
     * LAUNCHER_ROLE as the only role, and a member of exactly the teams that
     * may operate project #$projectId, so the user may launch its templates.
     * Its ID.
     *
     * @throws \RuntimeException when the role does not exist
     */
    private function launcher(int $projectId): int
    {
        /** @var ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $role = $auth->getRole(self::LAUNCHER_ROLE);
        if ($role === null) {
            throw new \RuntimeException(sprintf(self::ROLE_MISSING, self::LAUNCHER_ROLE));
        }

        $user = User::findOne(['username' => self::LAUNCHER]) ?? new User();
        $user->username = self::LAUNCHER;
        $user->email = self::LAUNCHER . '@example.com';
        $user->status = User::STATUS_ACTIVE;
        $user->is_superadmin = false;
        $user->setPassword('E2eApprovalLauncherPass1!');
        if ($user->isNewRecord) {
            $user->generateAuthKey();
        }
        $user->save(false);
        $launcherId = (int)$user->id;
        $auth->revokeAll($launcherId);
        $auth->assign($role, $launcherId);

        TeamMember::deleteAll(['user_id' => $launcherId]);
        $teamIds = TeamProject::find()
            ->select('team_id')
            ->where(['project_id' => $projectId, 'role' => TeamProject::ROLE_OPERATOR])
            ->column();
        foreach ($teamIds as $teamId) {
            $member = new TeamMember();
            $member->team_id = (int)$teamId;
            $member->user_id = $launcherId;
            $member->created_at = time();
            $member->save(false);
        }

        return $launcherId;
    }

    /**
     * The soft-deleted template and its disabled schedule, created anew: the
     * schedule goes with the old template (ON DELETE CASCADE).
     *
     * @param array{0: int, 1: int, 2: int} $parents
     */
    private function deletedTemplateSchedule(array $parents, int $userId): Schedule
    {
        JobTemplate::deleteAll(['name' => self::DELETED_TEMPLATE]);
        Schedule::deleteAll(['name' => self::DELETED_SCHEDULE]);
        $template = E2eFixtureHelper::template(self::DELETED_TEMPLATE, $parents, $userId, null, []);
        $template->description = 'E2E fixture: a deleted template that still has a schedule';
        $template->save(false);
        $template->softDelete();

        $schedule = new Schedule();
        $schedule->name = self::DELETED_SCHEDULE;
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '0 0 1 1 *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = false;
        $schedule->next_run_at = null;
        $schedule->created_by = $userId;
        $schedule->save(false);

        return $schedule;
    }
}
