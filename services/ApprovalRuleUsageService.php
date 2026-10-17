<?php

declare(strict_types=1);

namespace app\services;

use app\components\ApprovalRuleUsage;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Project;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use yii\base\Component;
use yii\db\ActiveQuery;
use yii\db\Query;

/**
 * Finds where an approval rule is used:
 *
 * - inventories that it gates. An inventory has no soft delete: while one
 *   has the rule, every launch of a job template that uses it waits for it.
 * - live job templates that it gates. Soft-deleted ones cannot run; the
 *   delete of the rule detaches them instead of counting them.
 * - steps of live workflows that carry it, whatever their type: a step of a
 *   type that older versions saved, such as "APPROVAL", keeps its rule too.
 * - pending approval requests whose job still waits for the decision. A
 *   pending request whose job no longer waits decides nothing.
 *
 * Totals count everything; the lists only hold what the viewer may see, so
 * the usage of a rule never reveals inventories, job templates or workflows
 * of other teams. An inventory without a project is visible to every
 * signed-in viewer. Everything is read without locks.
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
            $this->pendingRequestCount($id),
            $this->visibleInventories($id, $viewerId),
            (int)$this->inventoryQuery($id)->count()
        );
    }

    /**
     * Inventories gated by the rule. The condition names the column, so it
     * holds whatever the cached table schema says.
     */
    private function inventoryQuery(int $ruleId): ActiveQuery
    {
        return Inventory::find()->andWhere(['inventory.approval_rule_id' => $ruleId]);
    }

    /**
     * The inventories gated by the rule that the viewer may see, by name,
     * then ID: those without a project, and those of projects the viewer
     * may see. The filter is applied only when it is not null, as
     * VisibleJobTemplates does.
     *
     * @return list<array{id: int, name: string, project_id: int|null, project_name: string|null}>
     */
    private function visibleInventories(int $ruleId, ?int $viewerId): array
    {
        $query = $this->inventoryQuery($ruleId)
            ->with('project')
            ->orderBy(['inventory.name' => SORT_ASC, 'inventory.id' => SORT_ASC]);
        $filter = $this->projects()->buildChildResourceFilter($viewerId, 'inventory.project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        /** @var list<Inventory> $inventories */
        $inventories = $query->all();

        return array_map(
            static fn (Inventory $i): array => self::entry($i, $i->project_id === null ? null : (int)$i->project_id),
            $inventories
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
        $templates = VisibleJobTemplates::all($this->templateQuery($ruleId), $viewerId, ['project']);

        return array_map(static fn (JobTemplate $t): array => self::entry($t, (int)$t->project_id), $templates);
    }

    /**
     * One entry of the inventory and job template lists: the ID, the name
     * and the project. A job template always has a project; an inventory
     * may have none.
     *
     * @template TProject of int|null
     * @param TProject $projectId
     * @return array{id: int, name: string, project_id: TProject, project_name: string|null}
     */
    private static function entry(Inventory|JobTemplate $row, ?int $projectId): array
    {
        /** @var Project|null $project */
        $project = $row->project;

        return [
            'id' => (int)$row->id,
            'name' => (string)$row->name,
            'project_id' => $projectId,
            'project_name' => $project?->name,
        ];
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
