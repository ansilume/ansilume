<?php

declare(strict_types=1);

namespace app\services;

use app\components\SurveyDefinition;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;
use yii\db\Query;

/**
 * Who may decide on an approval, the one definition for votes, thresholds
 * and launches. An eligible approver:
 * - is on the rule's list, as ApprovalRule::getApproverUserIds() resolves
 *   it (a role rule counts the users assigned that role directly),
 * - has an active account,
 * - is a superadmin or holds approval.decide,
 * - may see what is to be approved: the job template's project (also for
 *   the approval rule of the template's inventory), or for a workflow
 *   approval step the workflow (team scoping, which applies only while some
 *   project is restricted to a team),
 * - and is not the launcher: the user who launched the job, or for a
 *   workflow the user who started the run. Nobody approves their own launch,
 *   superadmins included.
 *
 * The approvers are checked together, in a constant number of queries
 * however many a rule names, and web requests and console commands get the
 * same answer as long as both configure the same RBAC default roles.
 *
 * A launch is refused before anything is written when fewer eligible
 * approvers than the rule requires remain besides the launcher: such a
 * request could never be approved.
 */
class ApproverEligibility extends Component
{
    /** Launch of an approval-gated job template refused: rule name (quoted), required, eligible. */
    public const REFUSAL_JOB = 'Approval rule %s needs %d approval(s) from users other than the one who launches,'
        . ' but only %d of its approvers may decide on this job. An admin can add approvers to the rule.';

    /** Workflow approval step not run: rule name (quoted), required, the run's launcher, eligible. */
    public const REFUSAL_WORKFLOW_STEP = 'Not run: approval rule %s needs %d approval(s) from users other than'
        . ' user #%d, who launched this workflow, but only %d of its approvers may decide.';

    /** Workflow launch refused for a step: step name (quoted), rule name (quoted), required, eligible. */
    public const REFUSAL_WORKFLOW_LAUNCH = 'Step %s: approval rule %s needs %d approval(s) from users other than'
        . ' the one who launches, but only %d of its approvers may decide. An admin can add approvers to the rule.';

    /** Workflow launch refused for a step whose approval rule is gone: step name (quoted). */
    public const REFUSAL_WORKFLOW_RULE_MISSING = 'Step %s: its approval rule no longer exists. An admin can choose another rule.';

    /**
     * Workflow launch refused for a job step whose job template has an
     * approval rule of its own and its inventory another one
     * (JobTemplateGate::rulesDiffer()): a launch waits for one rule only, so
     * the step's job could never be launched. sprintf(): step name (quoted).
     */
    public const REFUSAL_WORKFLOW_RULES_DIFFER = 'Step %s: its job template and its inventory have different approval'
        . ' rules, and a launch waits for one rule only. An admin can remove the template\'s own rule or give it the'
        . ' inventory\'s rule.';

    /**
     * The users who may decide on the request, in the rule's order.
     *
     * @return list<int>
     */
    public function eligibleForRequest(ApprovalRequest $request): array
    {
        /** @var ApprovalRule|null $rule */
        $rule = $request->approvalRule;
        if ($rule === null) {
            return [];
        }

        return $this->eligible(
            $rule,
            $request->launcherId(),
            fn (): ApproverAudience => $this->requestAudience($request),
            $this->newFilter()
        );
    }

    /**
     * Whether $userId is one of {@see eligibleForRequest()}, checked for that
     * user alone. Independent of the request's state: whether it is still
     * pending, has expired or already has the user's vote is up to the caller.
     */
    public function mayDecide(ApprovalRequest $request, int $userId): bool
    {
        /** @var ApprovalRule|null $rule */
        $rule = $request->approvalRule;
        if (
            $rule === null
            || $this->isLauncher($request, $userId)
            || !in_array($userId, $rule->getApproverUserIds(), true)
        ) {
            return false;
        }

        return $this->newFilter()->mayDecide(
            [$userId],
            fn (): ApproverAudience => $this->requestAudience($request)
        ) !== [];
    }

    /**
     * Whether $userId launched the request's job, and so may not decide on it.
     */
    public function isLauncher(ApprovalRequest $request, int $userId): bool
    {
        return $userId === $request->launcherId();
    }

    /**
     * Why $launchedBy may not launch $template, which waits for $rule: too
     * few eligible approvers besides them, among those who may see the
     * template's project. Null when enough remain.
     */
    public function jobLaunchRefusal(ApprovalRule $rule, JobTemplate $template, int $launchedBy): ?string
    {
        $projectId = (int)$template->project_id;
        $eligible = count($this->eligible(
            $rule,
            $launchedBy,
            static fn (): ApproverAudience => ApproverAudience::ofProjects([$projectId]),
            $this->newFilter()
        ));
        $required = (int)$rule->required_approvals;
        if ($eligible >= $required) {
            return null;
        }

        return sprintf(self::REFUSAL_JOB, SurveyDefinition::quote((string)$rule->name), $required, $eligible);
    }

    /**
     * Why an approval step of $run that waits for $rule must not run: too few
     * eligible approvers besides the user who started the run, among those
     * who may see the run's workflow. Null when enough remain.
     */
    public function workflowStepRefusal(ApprovalRule $rule, WorkflowJob $run): ?string
    {
        $launchedBy = (int)$run->launched_by;
        $workflowTemplateId = (int)$run->workflow_template_id;
        $eligible = count($this->eligible(
            $rule,
            $launchedBy,
            fn (): ApproverAudience => $this->workflowAudience($workflowTemplateId),
            $this->newFilter()
        ));
        $required = (int)$rule->required_approvals;
        if ($eligible >= $required) {
            return null;
        }

        return sprintf(
            self::REFUSAL_WORKFLOW_STEP,
            SurveyDefinition::quote((string)$rule->name),
            $required,
            $launchedBy,
            $eligible
        );
    }

    /**
     * Why $launchedBy may not launch $template: the first step, in step
     * order, whose approval could never be granted. Every approval step
     * counts (an approval step whose rule is gone, too) and every job step
     * whose job template waits for an approval rule, its own or its
     * inventory's (JobTemplateGate), whether or not a run would reach it: a
     * job step whose template and inventory have different rules, or whose
     * rule is gone, could never be launched either. Null when every approval
     * can be granted.
     *
     * The steps, their rules, job templates, inventories and the inventories'
     * rules are read at once, so reading them costs the same for any number
     * of steps; each gated step then costs its approver check.
     */
    public function workflowLaunchRefusal(WorkflowTemplate $template, int $launchedBy): ?string
    {
        $workflowTemplateId = (int)$template->id;
        $filter = $this->newFilter();
        /** @var WorkflowStep[] $steps */
        $steps = WorkflowStep::find()
            ->where(['workflow_template_id' => $workflowTemplateId])
            ->with(['approvalRule', 'jobTemplate.approvalRule', 'jobTemplate.inventory.approvalRule'])
            ->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
        foreach ($steps as $step) {
            $refusal = $this->stepLaunchRefusal($step, $workflowTemplateId, $launchedBy, $filter);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }

    private function stepLaunchRefusal(
        WorkflowStep $step,
        int $workflowTemplateId,
        int $launchedBy,
        ApproverFilter $filter
    ): ?string {
        $gate = $this->gateOf($step, $workflowTemplateId);
        if ($gate === null) {
            return null;
        }
        $stepName = SurveyDefinition::quote((string)$step->name);
        if ($gate['rules_differ']) {
            return sprintf(self::REFUSAL_WORKFLOW_RULES_DIFFER, $stepName);
        }
        $rule = $gate['rule'];
        if ($rule === null) {
            return sprintf(self::REFUSAL_WORKFLOW_RULE_MISSING, $stepName);
        }
        $eligible = count($this->eligible($rule, $launchedBy, $gate['audience'], $filter));
        $required = (int)$rule->required_approvals;
        if ($eligible >= $required) {
            return null;
        }

        return sprintf(
            self::REFUSAL_WORKFLOW_LAUNCH,
            $stepName,
            SurveyDefinition::quote((string)$rule->name),
            $required,
            $eligible
        );
    }

    /**
     * The approval a step waits for: its rule (null when the rule no longer
     * exists), who may see its request, and whether two rules meet. Null for
     * a step without one: a pause step, or a job step whose job template is
     * not gated (neither it nor its inventory has a rule) or no longer exists
     * (launching such a step fails on its own).
     *
     * A job step waits for the rule that gates its job template
     * (JobTemplateGate): the template's own, else its inventory's; its
     * request is seen by whoever may see the template's project, also for
     * the rule of its inventory. rules_differ: the template has a rule of its
     * own and its inventory another one, so its job's launch is refused.
     *
     * @return array{rule: ApprovalRule|null, audience: \Closure(): ApproverAudience, rules_differ: bool}|null
     */
    private function gateOf(WorkflowStep $step, int $workflowTemplateId): ?array
    {
        if ($step->step_type === WorkflowStep::TYPE_APPROVAL) {
            /** @var ApprovalRule|null $rule */
            $rule = $step->approvalRule;

            return [
                'rule' => $rule,
                'audience' => fn (): ApproverAudience => $this->workflowAudience($workflowTemplateId),
                'rules_differ' => false,
            ];
        }
        /** @var JobTemplate|null $template */
        $template = $step->step_type === WorkflowStep::TYPE_JOB ? $step->jobTemplate : null;
        if ($template === null) {
            return null;
        }
        $gate = JobTemplateGate::of($template);
        if (!$gate->isGated()) {
            return null;
        }
        $projectId = (int)$template->project_id;

        return [
            'rule' => $gate->rule,
            'audience' => static fn (): ApproverAudience => ApproverAudience::ofProjects([$projectId]),
            'rules_differ' => $gate->rulesDiffer(),
        ];
    }

    /**
     * The rule's approvers, without the launcher, who may decide.
     *
     * @param \Closure(): ApproverAudience $audience
     * @return list<int>
     */
    private function eligible(ApprovalRule $rule, int $launcherId, \Closure $audience, ApproverFilter $filter): array
    {
        $candidates = array_values(array_diff(array_unique($rule->getApproverUserIds()), [$launcherId]));

        return $filter->mayDecide($candidates, $audience);
    }

    /**
     * Who may see a request, as WorkflowAccessChecker::canViewApprovalRequest()
     * decides: the job template's project (also of a template deleted since),
     * or for a placeholder job of a workflow approval step its workflow. A job
     * without either is seen only by users whom team scoping does not restrict.
     */
    private function requestAudience(ApprovalRequest $request): ApproverAudience
    {
        /** @var Job|null $job */
        $job = $request->job;
        if ($job === null) {
            return ApproverAudience::unrestrictedOnly();
        }
        if ($job->job_template_id !== null) {
            /** @var JobTemplate|null $template */
            $template = $job->jobTemplate;

            return $template === null
                ? ApproverAudience::unrestrictedOnly()
                : ApproverAudience::ofProjects([(int)$template->project_id]);
        }
        $workflowTemplateIds = array_map('intval', (new Query())
            ->select('wj.workflow_template_id')
            ->distinct()
            ->from(['wjs' => WorkflowJobStep::tableName()])
            ->innerJoin(['wj' => WorkflowJob::tableName()], '[[wj.id]] = [[wjs.workflow_job_id]]')
            ->where(['wjs.job_id' => $job->id])
            ->column());

        return ApproverAudience::anyOf(...array_map(
            fn (int $workflowTemplateId): ApproverAudience => $this->workflowAudience($workflowTemplateId),
            $workflowTemplateIds
        ));
    }

    /**
     * Who may see a workflow, as WorkflowAccessChecker::canViewWorkflowTemplate()
     * decides: users who may view the project of every job step's template
     * (soft-deleted templates count with their project). A job step whose
     * template no longer exists leaves only users whom team scoping does not
     * restrict; a workflow without job steps is seen by everyone.
     */
    private function workflowAudience(int $workflowTemplateId): ApproverAudience
    {
        $templateIds = array_map('intval', WorkflowStep::find()
            ->select('job_template_id')
            ->where(['workflow_template_id' => $workflowTemplateId, 'step_type' => WorkflowStep::TYPE_JOB])
            ->andWhere(['not', ['job_template_id' => null]])
            ->distinct()
            ->column());
        if ($templateIds === []) {
            return ApproverAudience::ofProjects([]);
        }
        $projectIds = array_map('intval', (new Query())
            ->select('project_id')
            ->from(JobTemplate::tableName())
            ->where(['id' => $templateIds])
            ->column());

        return count($projectIds) < count($templateIds)
            ? ApproverAudience::unrestrictedOnly()
            : ApproverAudience::ofProjects($projectIds);
    }

    private function newFilter(): ApproverFilter
    {
        /** @var \yii\rbac\ManagerInterface $auth */
        $auth = \Yii::$app->authManager;

        return new ApproverFilter(new PermissionHolders($auth));
    }
}
