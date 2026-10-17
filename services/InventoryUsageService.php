<?php

declare(strict_types=1);

namespace app\services;

use app\components\InventoryUsage;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use yii\base\Component;
use yii\db\ActiveQuery;

/**
 * Finds the job templates that use an inventory:
 *
 * - live job templates, listed as far as the viewer may see them
 *   ({@see VisibleJobTemplates}), each with its own approval rule, and
 *   counted all;
 * - soft-deleted job templates, only counted: they keep their inventory
 *   (job_template.inventory_id is NOT NULL with a RESTRICT foreign key)
 *   for the history of their jobs, until their project is deleted.
 *
 * templatesWithAnotherRule() finds the live job templates whose own
 * approval rule differs from a rule the inventory would get: a launch waits
 * for one rule only.
 *
 * Both read with plain reads only, never with a locking read: an inventory
 * save locks its own row first and must not lock job_template rows, which
 * launches and template saves lock before inventory rows.
 */
class InventoryUsageService extends Component
{
    /**
     * The job templates that use inventory $i, as far as the viewer may see
     * them; a missing viewer sees none.
     */
    public function forInventory(Inventory $i, ?int $viewerId): InventoryUsage
    {
        $id = (int)$i->id;
        $visible = VisibleJobTemplates::all($this->liveTemplates($id), $viewerId, ['project', 'approvalRule']);

        return new InventoryUsage(
            (string)$i->name,
            array_map(static fn (JobTemplate $t): array => self::entry($t), $visible),
            (int)$this->liveTemplates($id)->count(),
            (int)JobTemplate::findWithDeleted()
                ->andWhere(['job_template.inventory_id' => $id])
                ->andWhere(['not', ['job_template.deleted_at' => null]])
                ->count()
        );
    }

    /**
     * The live job templates on the inventory whose own approval rule is
     * set and differs from $ruleId: the names of those the viewer may see,
     * by name, and the number of them all. Templates with the same rule or
     * none, and soft-deleted ones, are left out.
     *
     * @return array{names: list<string>, total: int}
     */
    public function templatesWithAnotherRule(int $inventoryId, int $ruleId, ?int $viewerId): array
    {
        $visible = VisibleJobTemplates::all($this->withAnotherRule($inventoryId, $ruleId), $viewerId, []);

        return [
            'names' => array_map(static fn (JobTemplate $t): string => (string)$t->name, $visible),
            'total' => (int)$this->withAnotherRule($inventoryId, $ruleId)->count(),
        ];
    }

    /**
     * Live job templates on the inventory (find() leaves out soft-deleted
     * ones).
     */
    private function liveTemplates(int $inventoryId): ActiveQuery
    {
        return JobTemplate::find()->andWhere(['job_template.inventory_id' => $inventoryId]);
    }

    /**
     * Live job templates on the inventory with an own rule other than
     * $ruleId. SQL's <> is never true for NULL, so a template without a
     * rule of its own is left out too.
     */
    private function withAnotherRule(int $inventoryId, int $ruleId): ActiveQuery
    {
        return $this->liveTemplates($inventoryId)->andWhere(['<>', 'job_template.approval_rule_id', $ruleId]);
    }

    /**
     * One entry of the list: the template, its project and its own approval
     * rule, read from the loaded relation, so the rule's ID and name are
     * both set or both null (the RESTRICT foreign key keeps a rule that a
     * template names).
     *
     * @return array{id: int, name: string, project_id: int, project_name: string|null, approval_rule_id: int|null, approval_rule_name: string|null}
     */
    private static function entry(JobTemplate $t): array
    {
        /** @var Project|null $project */
        $project = $t->project;
        /** @var ApprovalRule|null $rule */
        $rule = $t->approvalRule;

        return [
            'id' => (int)$t->id,
            'name' => (string)$t->name,
            'project_id' => (int)$t->project_id,
            'project_name' => $project?->name,
            'approval_rule_id' => $rule === null ? null : (int)$rule->id,
            'approval_rule_name' => $rule?->name,
        ];
    }
}
