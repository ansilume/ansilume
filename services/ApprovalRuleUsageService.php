<?php

declare(strict_types=1);

namespace app\services;

use app\components\ApprovalRuleUsage;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;
use yii\db\ActiveQuery;
use yii\db\Query;

/**
 * Finds where an approval rule is used:
 *
 * - live job templates that it gates. Soft-deleted ones cannot run; the
 *   delete of the rule detaches them instead of counting them.
 * - steps of live workflows that carry it, whatever their type: a step of a
 *   type that older versions saved, such as "APPROVAL", keeps its rule too.
 * - pending approval requests whose job still waits for the decision. A
 *   pending request whose job no longer waits decides nothing.
 *
 * Totals count everything; the lists only hold what the viewer may see, so
 * the usage of a rule never reveals job templates or workflows of other
 * teams.
 */
class ApprovalRuleUsageService extends Component
{
    public function forRule(ApprovalRule $rule, ?int $viewerId): ApprovalRuleUsage
    {
        $id = (int)$rule->id;

        return new ApprovalRuleUsage(
            (string)$rule->name,
            $this->visibleTemplates($id, $viewerId),
            (int)$this->templateQuery($id)->count(),
            $this->visibleSteps($id, $viewerId),
            (int)$this->stepQuery($id)->count(),
            $this->pendingRequestCount($id)
        );
    }

    /**
     * Live job templates gated by the rule (find() leaves out soft-deleted ones).
     */
    private function templateQuery(int $ruleId): ActiveQuery
    {
        return JobTemplate::find()->andWhere(['job_template.approval_rule_id' => $ruleId]);
    }

    /**
     * @return list<array{id: int, name: string, project_id: int, project_name: string|null}>
     */
    private function visibleTemplates(int $ruleId, ?int $viewerId): array
    {
        $query = $this->templateQuery($ruleId)
            ->with('project')
            ->orderBy(['job_template.name' => SORT_ASC, 'job_template.id' => SORT_ASC]);
        $filter = $this->projects()->buildChildResourceFilter($viewerId, 'job_template.project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        /** @var list<JobTemplate> $templates */
        $templates = $query->all();

        return array_map(static fn (JobTemplate $t): array => [
            'id' => (int)$t->id,
            'name' => (string)$t->name,
            'project_id' => (int)$t->project_id,
            'project_name' => $t->project->name ?? null,
        ], $templates);
    }

    /**
     * Steps of live workflows that carry the rule.
     */
    private function stepQuery(int $ruleId): Query
    {
        return (new Query())
            ->from(['ws' => WorkflowStep::tableName()])
            ->innerJoin(['wt' => WorkflowTemplate::tableName()], '[[wt]].[[id]] = [[ws]].[[workflow_template_id]]')
            ->where(['ws.approval_rule_id' => $ruleId, 'wt.deleted_at' => null]);
    }

    /**
     * @return list<array{workflow_template_id: int, workflow_template_name: string, step_id: int, step_name: string}>
     */
    private function visibleSteps(int $ruleId, ?int $viewerId): array
    {
        $query = $this->stepQuery($ruleId)
            ->select(['step_id' => 'ws.id', 'step_name' => 'ws.name', 'workflow_template_id' => 'ws.workflow_template_id', 'workflow_template_name' => 'wt.name'])
            ->orderBy(['wt.name' => SORT_ASC, 'ws.workflow_template_id' => SORT_ASC, 'ws.step_order' => SORT_ASC, 'ws.id' => SORT_ASC]);
        $filter = $this->workflows()->buildWorkflowTemplateFilter($viewerId, 'ws.workflow_template_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        $steps = [];
        foreach ($query->all() as $row) {
            $steps[] = [
                'workflow_template_id' => (int)$row['workflow_template_id'],
                'workflow_template_name' => (string)$row['workflow_template_name'],
                'step_id' => (int)$row['step_id'],
                'step_name' => (string)$row['step_name'],
            ];
        }

        return $steps;
    }

    private function pendingRequestCount(int $ruleId): int
    {
        return (int)ApprovalRequest::find()
            ->alias('ar')
            ->innerJoin(['j' => Job::tableName()], '[[j]].[[id]] = [[ar]].[[job_id]]')
            ->where([
                'ar.approval_rule_id' => $ruleId,
                'ar.status' => ApprovalRequest::STATUS_PENDING,
                'j.status' => Job::STATUS_PENDING_APPROVAL,
            ])
            ->count();
    }

    private function projects(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');

        return $checker;
    }

    private function workflows(): WorkflowAccessChecker
    {
        /** @var WorkflowAccessChecker $checker */
        $checker = \Yii::$app->get('workflowAccessChecker');

        return $checker;
    }
}
