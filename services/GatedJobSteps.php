<?php

declare(strict_types=1);

namespace app\services;

use app\components\WholeNumberValidator;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use yii\db\ActiveQuery;

/**
 * Gated job steps in SQL: job steps whose job template is gated, by its own
 * approval rule or by the rule of its inventory, as JobTemplateGate decides
 * it (JobTemplateGate::joinInventory(), gatedCondition()). Only admins add
 * such a step or change the steps of a workflow that has one
 * (ApprovalRuleAssignmentPolicy, security rule 12).
 *
 * What gates a step is its source, as JobTemplateGate::source() names it:
 * SOURCE_JOB_TEMPLATE whenever the template has a rule of its own, also when
 * its inventory has one; SOURCE_INVENTORY when only its inventory has one.
 * Where steps of both kinds meet, the job template is named first.
 *
 * Only job templates that are not deleted count, as a step of a deleted one
 * runs nothing, and only steps of the exact type job, as dispatch compares
 * it. Every read is a plain read, made when asked: a job template or an
 * inventory that gets a rule locks the workflows that use it from then on.
 */
final class GatedJobSteps
{
    /**
     * The source of each of the job templates $ids that is gated, keyed by
     * its ID in ascending order; deleted templates, ungated ones and unknown
     * IDs are left out. One query, none without IDs.
     *
     * @param array<int, int> $ids
     * @return array<int, string> job template ID => JobTemplateGate::SOURCE_*
     */
    public static function sourcesOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        // andWhere(): find() leaves deleted templates out.
        $query = JobTemplate::find()
            ->select(['job_template.id', 'job_template.approval_rule_id'])
            ->andWhere(['job_template.id' => array_values(array_unique($ids))]);
        JobTemplateGate::joinInventory($query, 'job_template');
        /** @var list<array{id: int|string, approval_rule_id: int|string|null}> $rows */
        $rows = $query->andWhere(JobTemplateGate::gatedCondition('job_template'))
            ->orderBy(['job_template.id' => SORT_ASC])
            ->asArray()
            ->all();
        $sources = [];
        foreach ($rows as $row) {
            $sources[(int)$row['id']] = $row['approval_rule_id'] === null
                ? JobTemplateGate::SOURCE_INVENTORY
                : JobTemplateGate::SOURCE_JOB_TEMPLATE;
        }

        return $sources;
    }

    /**
     * What gates the job steps of the workflow template $workflowTemplateId:
     * SOURCE_JOB_TEMPLATE when one of them has a job template with a rule of
     * its own, else SOURCE_INVENTORY when the inventory of one of their job
     * templates has a rule; null when none is gated. At most two queries.
     *
     * The second asks for any gate (gatedCondition()), so a job template
     * that gets a rule of its own between the two still counts: the
     * workflow fails closed, named by its inventory.
     */
    public static function sourceIn(int $workflowTemplateId): ?string
    {
        if (self::liveJobSteps($workflowTemplateId)->andWhere(['not', ['jt.approval_rule_id' => null]])->exists()) {
            return JobTemplateGate::SOURCE_JOB_TEMPLATE;
        }
        $query = self::liveJobSteps($workflowTemplateId);
        JobTemplateGate::joinInventory($query, 'jt');
        $gated = $query->andWhere(JobTemplateGate::gatedCondition('jt'))->exists();

        return $gated ? JobTemplateGate::SOURCE_INVENTORY : null;
    }

    /**
     * What gates the job steps $steps (submitted, not saved) among the job
     * templates $userId may see: SOURCE_JOB_TEMPLATE before SOURCE_INVENTORY,
     * null when none is gated. A job template the user may not see counts as
     * none: the step is refused as not found, and the answer must not tell
     * whether that template, or its inventory, has a rule. The ID is read as
     * the step's integer rule reads it ("05" and "+5" stand for 5); a value
     * that is no ID is no job template.
     *
     * @param iterable<WorkflowStep> $steps
     */
    public static function visibleSourceOf(int $userId, iterable $steps): ?string
    {
        $sources = self::sourcesOf(self::jobTemplateIdsOf($steps));
        if ($sources === []) {
            return null;
        }
        $hidden = self::access()->classifyStepTemplateIds($userId, array_keys($sources))['missing'];
        $visible = array_diff_key($sources, array_flip($hidden));
        if (in_array(JobTemplateGate::SOURCE_JOB_TEMPLATE, $visible, true)) {
            return JobTemplateGate::SOURCE_JOB_TEMPLATE;
        }

        return $visible === [] ? null : JobTemplateGate::SOURCE_INVENTORY;
    }

    /**
     * The job template IDs of the job steps among $steps (the exact type
     * job), read as the step's integer rule reads them.
     *
     * @param iterable<WorkflowStep> $steps
     * @return list<int>
     */
    private static function jobTemplateIdsOf(iterable $steps): array
    {
        $integer = new WholeNumberValidator(['integerOnly' => true]);
        $ids = [];
        foreach ($steps as $step) {
            $id = $step->job_template_id;
            if ($step->step_type === WorkflowStep::TYPE_JOB && (is_int($id) || is_string($id)) && $integer->validate($id)) {
                $ids[] = (int)$id;
            }
        }

        return $ids;
    }

    /**
     * The job steps of the workflow template $workflowTemplateId whose job
     * template (alias jt) is not deleted; the type compared exactly.
     */
    private static function liveJobSteps(int $workflowTemplateId): ActiveQuery
    {
        return WorkflowStep::find()
            ->alias('ws')
            ->innerJoin(['jt' => JobTemplate::tableName()], '[[jt.id]] = [[ws.job_template_id]]')
            ->where(['ws.workflow_template_id' => $workflowTemplateId, 'jt.deleted_at' => null])
            ->andWhere('CAST([[ws.step_type]] AS BINARY) = :job', [':job' => WorkflowStep::TYPE_JOB]);
    }

    private static function access(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');

        return $checker;
    }
}
