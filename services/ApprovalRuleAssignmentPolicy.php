<?php

declare(strict_types=1);

namespace app\services;

use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;
use yii\validators\NumberValidator;

/**
 * Who may assign approval rules. A rule gates launches, so choosing which
 * rule gates a job template, and changing the steps of a workflow that has
 * a gated step, is for admins: users who hold approval-rule.update (the
 * admin role, superadmins, and custom roles granted it). Whoever holds it
 * can change any rule's approvers anyway.
 *
 * - Job templates: any change of approval_rule_id needs the permission,
 *   also setting one on a new template and removing one. Sending the stored
 *   value again, or not sending the field, is no change. A clone keeps its
 *   source's rule for everyone: copying is not assigning, and dropping the
 *   rule would create an ungated twin.
 * - Workflows: a gated step is an approval step, or a job step whose job
 *   template has an approval rule (a gated job step). Adding one needs the
 *   permission, and so does every change of the steps (add, remove, move,
 *   replace) of a workflow that has one. Name and description, launching
 *   and trigger tokens are not affected. A run launches its jobs as the
 *   user who started it (or whose trigger token did), and the launcher is
 *   the one user who may not approve them: whoever else chose a gated step
 *   could otherwise approve it alone. Whether a workflow has a gated job
 *   step is read when asked, so a job template that gets a rule locks the
 *   workflows that use it from then on.
 *
 * Callers refuse with the message, before anything is written: the web
 * with a 403 (ForbiddenHttpException), the API with a 403 error.
 */
class ApprovalRuleAssignmentPolicy extends Component
{
    /** The permission that makes a user an admin of approval rules. */
    public const PERMISSION = 'approval-rule.update';

    public const ASSIGN_REFUSED = 'Only admins may assign or remove an approval rule: that needs approval-rule.update.';
    public const APPROVAL_STEP_ADD_REFUSED = 'Only admins may add approval steps: that needs approval-rule.update.';
    public const APPROVAL_WORKFLOW_STEPS_REFUSED = 'This workflow has an approval step: only admins may change its steps (approval-rule.update).';
    public const GATED_JOB_STEP_ADD_REFUSED = 'Only admins may add a job step whose job template has an approval rule: '
        . 'that needs approval-rule.update.';
    public const GATED_JOB_WORKFLOW_STEPS_REFUSED = 'This workflow has a job step whose job template has an approval rule: '
        . 'only admins may change its steps (approval-rule.update).';

    /**
     * Whether $userId may assign and remove approval rules: an active
     * account that holds approval-rule.update, or a superadmin.
     */
    public function mayAssign(int $userId): bool
    {
        return $this->access()->isActiveWithPermission($userId, self::PERMISSION);
    }

    /**
     * The rule a submitted approval_rule_id stands for: null for no rule (''
     * from a form, null from JSON), the ID for an integer or a string of
     * digits. Anything else is no rule ID and comes back as it is, so that
     * the template's validation refuses it.
     */
    public static function normalizeRuleId(mixed $submitted): mixed
    {
        if ($submitted === null || $submitted === '') {
            return null;
        }
        if (is_string($submitted) && ctype_digit($submitted)) {
            return (int)$submitted;
        }

        return $submitted;
    }

    /**
     * The rule a job template has now: the stored one, null for a template
     * not saved yet. The baseline of an update; a clone passes its source's
     * rule instead.
     */
    public static function storedRuleId(JobTemplate $template): ?int
    {
        $stored = $template->getOldAttribute('approval_rule_id');

        return $stored === null ? null : (int)$stored;
    }

    /**
     * Why $userId may not set a job template's rule to $submitted, or null
     * when they may: the same rule as $baseline is no change, and a change
     * needs approval-rule.update. A value that is no rule ID counts as a
     * change.
     */
    public function templateRuleRefusal(int $userId, mixed $submitted, ?int $baseline): ?string
    {
        if (self::normalizeRuleId($submitted) === $baseline || $this->mayAssign($userId)) {
            return null;
        }

        return self::ASSIGN_REFUSED;
    }

    /**
     * Why $userId may not change the steps of $template, or null when they
     * may. $addsApprovalStep: the change adds an approval step, or (an API
     * replacement) the new steps include one. Otherwise the workflow's own
     * steps decide: an approval step (APPROVAL_WORKFLOW_STEPS_REFUSED) or a
     * gated job step (GATED_JOB_WORKFLOW_STEPS_REFUSED) locks them. A change
     * that adds a gated job step is stepsAddRefusal()'s to tell.
     */
    public function workflowStepsChangeRefusal(int $userId, WorkflowTemplate $template, bool $addsApprovalStep): ?string
    {
        return $this->refusalFor($userId, $addsApprovalStep ? self::APPROVAL_STEP_ADD_REFUSED : $this->stepsLock($template));
    }

    /**
     * Why $userId may not add $added to the steps of $template (web: the
     * new step; API: the steps that replace all of them), or null when they
     * may. In this order: an approval step among them
     * (APPROVAL_STEP_ADD_REFUSED), a gated job step among them
     * (GATED_JOB_STEP_ADD_REFUSED, see includesGatedJobStep()), then what
     * locks the workflow's steps (workflowStepsChangeRefusal()).
     *
     * @param array<int, WorkflowStep> $added
     */
    public function stepsAddRefusal(int $userId, WorkflowTemplate $template, array $added): ?string
    {
        if (self::includesApprovalStep($added)) {
            return $this->workflowStepsChangeRefusal($userId, $template, true);
        }
        $reason = $this->includesGatedJobStep($userId, $added) ? self::GATED_JOB_STEP_ADD_REFUSED : $this->stepsLock($template);

        return $this->refusalFor($userId, $reason);
    }

    /**
     * Whether $template has an approval step. The type is compared exactly,
     * as dispatch compares it: the column's collation ignores case and
     * trailing spaces, and a step of type "APPROVAL" never asks for a
     * decision (dispatch fails it).
     */
    public function hasApprovalStep(WorkflowTemplate $template): bool
    {
        return WorkflowStep::find()
            ->where(['workflow_template_id' => $template->id])
            ->andWhere('CAST([[step_type]] AS BINARY) = :approval', [':approval' => WorkflowStep::TYPE_APPROVAL])
            ->exists();
    }

    /**
     * Whether $template has a gated job step: a job step whose job template
     * has an approval rule. Only job templates that are not deleted count, as
     * a step of a deleted one runs nothing; the type is compared exactly, as
     * dispatch compares it.
     */
    public function hasGatedJobStep(WorkflowTemplate $template): bool
    {
        return WorkflowStep::find()
            ->alias('ws')
            ->innerJoin(['jt' => JobTemplate::tableName()], '[[jt.id]] = [[ws.job_template_id]]')
            ->where(['ws.workflow_template_id' => $template->id, 'jt.deleted_at' => null])
            ->andWhere(['not', ['jt.approval_rule_id' => null]])
            ->andWhere('CAST([[ws.step_type]] AS BINARY) = :job', [':job' => WorkflowStep::TYPE_JOB])
            ->exists();
    }

    /**
     * Whether $steps include an approval step, compared as dispatch compares
     * the type. For the steps a request submits.
     *
     * @param iterable<WorkflowStep> $steps
     */
    public static function includesApprovalStep(iterable $steps): bool
    {
        foreach ($steps as $step) {
            if ($step->step_type === WorkflowStep::TYPE_APPROVAL) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $steps (submitted, not saved) include a gated job step, among
     * the job templates $userId may see. A job template the user may not see
     * counts as none: the step is refused as not found, and the answer must
     * not tell whether that template has a rule. The ID is read as the
     * step's integer rule reads it ("05" and "+5" stand for 5); a value
     * that is no ID is no job template.
     *
     * @param iterable<WorkflowStep> $steps
     */
    public function includesGatedJobStep(int $userId, iterable $steps): bool
    {
        $integer = new NumberValidator(['integerOnly' => true]);
        $ids = [];
        foreach ($steps as $step) {
            $id = $step->job_template_id;
            if ($step->step_type === WorkflowStep::TYPE_JOB && (is_int($id) || is_string($id)) && $integer->validate($id)) {
                $ids[] = (int)$id;
            }
        }
        $gated = self::gatedJobTemplateIds($ids);
        if ($gated === []) {
            return false;
        }

        return count($this->access()->classifyStepTemplateIds($userId, $gated)['missing']) < count($gated);
    }

    /**
     * Those of the job templates $ids that have an approval rule, deleted
     * ones left out, ascending.
     *
     * @param array<int, int> $ids
     * @return list<int>
     */
    public static function gatedJobTemplateIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        // andWhere(): find() leaves deleted templates out.
        $gated = JobTemplate::find()
            ->select('job_template.id')
            ->andWhere(['job_template.id' => array_values(array_unique($ids))])
            ->andWhere(['not', ['job_template.approval_rule_id' => null]])
            ->orderBy(['job_template.id' => SORT_ASC])
            ->column();

        return array_values(array_map('intval', $gated));
    }

    /**
     * What locks the steps of $template for users who may not assign
     * approval rules, or null when nothing does.
     */
    private function stepsLock(WorkflowTemplate $template): ?string
    {
        if ($this->hasApprovalStep($template)) {
            return self::APPROVAL_WORKFLOW_STEPS_REFUSED;
        }

        return $this->hasGatedJobStep($template) ? self::GATED_JOB_WORKFLOW_STEPS_REFUSED : null;
    }

    /**
     * $reason, unless there is none or $userId may assign approval rules.
     */
    private function refusalFor(int $userId, ?string $reason): ?string
    {
        return $reason !== null && !$this->mayAssign($userId) ? $reason : null;
    }

    private function access(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');

        return $checker;
    }
}
