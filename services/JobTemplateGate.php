<?php

declare(strict_types=1);

namespace app\services;

use app\components\SubmittedId;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use yii\db\Query;

/**
 * The one answer to "which approval rule gates this job template": its own
 * rule (job_template.approval_rule_id), else the rule of its inventory
 * (inventory.approval_rule_id). The launch, the claim, the workflow
 * pre-flight, the locks of templates and inventories, the gated job steps,
 * the route map, the pages and the API all ask this class, so they agree.
 *
 * A launch waits for one rule only: where the template has a rule of its
 * own and its inventory another one, the rules differ and a launch is
 * refused (rulesDiffer(), refusal()).
 *
 * of() reads a template as it was loaded, and its relations as loaded
 * (eagerly with RELATIONS, else lazily); it ignores deleted_at. The
 * inventory's rule is read with getAttribute(): an inventory model without
 * the column (code running before m000088, or a schema cached from before
 * it) has no rule, and nothing throws. joinInventory() and gatedCondition()
 * say the same in SQL, and readInventoryRules() reads the rules of
 * inventory rows as stored, also before m000088.
 */
final class JobTemplateGate
{
    /** The template's own rule gates it (ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE). */
    public const SOURCE_JOB_TEMPLATE = ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE;

    /** Its inventory's rule gates it (ApprovalRequest::REQUIRED_BY_INVENTORY). */
    public const SOURCE_INVENTORY = ApprovalRequest::REQUIRED_BY_INVENTORY;

    /** The relations of() reads, to eager-load on job templates; workflow steps prefix them with 'jobTemplate.'. */
    public const RELATIONS = ['approvalRule', 'inventory.approvalRule'];

    /** The alias of the inventory that joinInventory() joins and gatedCondition() reads. */
    public const INVENTORY_ALIAS = 'gate_inventory';

    /** The launch page's note when the template's own rule gates it; sprintf(): the rule's name. */
    public const LAUNCH_NOTE = 'The job will wait for approval before it runs: rule "%s" (job template).';

    /** The launch page's note when the inventory's rule gates it; sprintf(): the rule's name, the inventory's name. */
    public const LAUNCH_NOTE_INVENTORY = 'The job will wait for approval before it runs: rule "%1$s" (inventory "%2$s").';

    /** The schedule page's note when the template's own rule gates it; sprintf(): the rule's name. */
    public const SCHEDULE_NOTE = 'Each run waits for approval: rule "%s" (job template).';

    /** The schedule page's note when the inventory's rule gates it; sprintf(): the rule's name, the inventory's name. */
    public const SCHEDULE_NOTE_INVENTORY = 'Each run waits for approval: rule "%1$s" (inventory "%2$s").';

    /**
     * @param int $templateId the template's ID; 0 for a new template
     * @param int|null $templateRuleId the template's own rule ID
     * @param int $inventoryId the template's inventory_id as loaded; 0 for none
     * @param string|null $inventoryName the inventory's name; null when its row is gone
     * @param int|null $inventoryRuleId the inventory's rule ID
     * @param ApprovalRule|null $rule the gating rule, the template's own first; null when ungated or its row is gone
     * @param ApprovalRule|null $inventoryRule the inventory's rule, also when the template has one of its own
     * @param JobTemplate $template the template of() was given, whose project is the approvers' audience
     */
    private function __construct(
        public readonly int $templateId,
        public readonly ?int $templateRuleId,
        public readonly int $inventoryId,
        public readonly ?string $inventoryName,
        public readonly ?int $inventoryRuleId,
        public readonly ?ApprovalRule $rule,
        public readonly ?ApprovalRule $inventoryRule,
        private readonly JobTemplate $template
    ) {
    }

    /**
     * The gate of $t as loaded. Its approvalRule relations are read only for
     * a rule ID, so they cost no query when they are loaded (RELATIONS) or
     * when there is no rule.
     */
    public static function of(JobTemplate $t): self
    {
        $templateRuleId = self::ruleIdOf($t->getAttribute('approval_rule_id'));
        /** @var Inventory|null $inventory */
        $inventory = $t->inventory;
        $inventoryRuleId = $inventory === null ? null : self::ruleIdOf($inventory->getAttribute('approval_rule_id'));
        /** @var ApprovalRule|null $inventoryRule */
        $inventoryRule = $inventory === null || $inventoryRuleId === null ? null : $inventory->approvalRule;
        /** @var ApprovalRule|null $templateRule */
        $templateRule = $templateRuleId === null ? null : $t->approvalRule;

        return new self(
            self::idOf($t->getAttribute('id')),
            $templateRuleId,
            self::idOf($t->getAttribute('inventory_id')),
            $inventory?->name,
            $inventoryRuleId,
            $templateRuleId === null ? $inventoryRule : $templateRule,
            $inventoryRule,
            $t
        );
    }

    /**
     * The ID of the gating rule: the template's own, else its inventory's;
     * null when nothing gates the template.
     */
    public function ruleId(): ?int
    {
        return $this->templateRuleId ?? $this->inventoryRuleId;
    }

    /**
     * Whether a launch of the template waits for an approval rule (or is
     * refused because that rule is gone, or two rules meet).
     */
    public function isGated(): bool
    {
        return $this->ruleId() !== null;
    }

    /**
     * What gates the template: SOURCE_JOB_TEMPLATE whenever it has a rule of
     * its own, also when its inventory has the same one; SOURCE_INVENTORY
     * when only its inventory has one; null when it is not gated.
     */
    public function source(): ?string
    {
        if ($this->templateRuleId !== null) {
            return self::SOURCE_JOB_TEMPLATE;
        }

        return $this->inventoryRuleId === null ? null : self::SOURCE_INVENTORY;
    }

    /**
     * Whether the template and its inventory have different rules; rule and
     * ruleId() are then the template's.
     */
    public function rulesDiffer(): bool
    {
        return $this->templateRuleId !== null
            && $this->inventoryRuleId !== null
            && $this->templateRuleId !== $this->inventoryRuleId;
    }

    /**
     * Whether the template is gated by a rule whose row no longer exists.
     */
    public function ruleMissing(): bool
    {
        return $this->isGated() && $this->rule === null;
    }

    /**
     * Why user #$launchedBy may not launch the template, checked in this
     * order: the template and its inventory have different rules
     * (JobLaunchService::RULES_DIFFER); the gating rule is gone
     * (JobLaunchService::RULE_MISSING for the template's own rule,
     * INVENTORY_RULE_MISSING for its inventory's); too few eligible
     * approvers besides the launcher among those who may see the template's
     * project, also for the rule of its inventory
     * (ApproverEligibility::jobLaunchRefusal()). Null when the launch may go
     * ahead. Reads, writes nothing.
     */
    public function refusal(int $launchedBy, ApproverEligibility $e): ?string
    {
        if ($this->rulesDiffer()) {
            return sprintf(JobLaunchService::RULES_DIFFER, $this->templateId, $this->inventoryId);
        }
        if ($this->ruleMissing()) {
            return $this->source() === self::SOURCE_INVENTORY
                ? sprintf(JobLaunchService::INVENTORY_RULE_MISSING, $this->templateId, $this->inventoryId)
                : sprintf(JobLaunchService::RULE_MISSING, $this->templateId);
        }

        return $this->rule === null ? null : $e->jobLaunchRefusal($this->rule, $this->template, $launchedBy);
    }

    /**
     * The launch page's note on the approval a launch waits for
     * (LAUNCH_NOTE, LAUNCH_NOTE_INVENTORY), with the names as stored: the
     * page encodes them. Null unless the template is gated by one rule that
     * exists.
     */
    public function launchNote(): ?string
    {
        return $this->note(self::LAUNCH_NOTE, self::LAUNCH_NOTE_INVENTORY);
    }

    /**
     * The schedule page's note on the approval each run waits for
     * (SCHEDULE_NOTE, SCHEDULE_NOTE_INVENTORY), with the names as stored.
     * Null unless the template is gated by one rule that exists.
     */
    public function scheduleNote(): ?string
    {
        return $this->note(self::SCHEDULE_NOTE, self::SCHEDULE_NOTE_INVENTORY);
    }

    /**
     * The API's read-only approval_gate of the template: the gating rule's
     * ID and name (null once its row is gone), and what required it (its
     * source). Null when the template is not gated.
     *
     * @return array{approval_rule_id: int, approval_rule_name: string|null, required_by: string}|null
     */
    public function toApi(): ?array
    {
        $ruleId = $this->ruleId();
        if ($ruleId === null) {
            return null;
        }

        return [
            'approval_rule_id' => $ruleId,
            'approval_rule_name' => $this->rule?->name,
            'required_by' => (string)$this->source(),
        ];
    }

    /**
     * Joins the inventory of the job template $templateAlias to $q, as
     * $inventoryAlias: LEFT JOIN {{%inventory}} <i> ON <i>.id = <t>.inventory_id.
     */
    public static function joinInventory(Query $q, string $templateAlias, string $inventoryAlias = self::INVENTORY_ALIAS): void
    {
        $q->leftJoin(
            [$inventoryAlias => Inventory::tableName()],
            "[[{$inventoryAlias}.id]] = [[{$templateAlias}.inventory_id]]"
        );
    }

    /**
     * The SQL condition of isGated() for the job template $templateAlias,
     * whose inventory joinInventory() joined as $inventoryAlias: its own
     * rule or its inventory's is set. Callers keep their own
     * <t>.deleted_at IS NULL.
     *
     * @return array{0: string, 1: array{0: string, 1: array<string, null>}, 2: array{0: string, 1: array<string, null>}}
     */
    public static function gatedCondition(string $templateAlias, string $inventoryAlias = self::INVENTORY_ALIAS): array
    {
        return [
            'or',
            ['not', [$templateAlias . '.approval_rule_id' => null]],
            ['not', [$inventoryAlias . '.approval_rule_id' => null]],
        ];
    }

    /**
     * The approval rule of each inventory row of $inventoryIds as stored,
     * keyed by the inventory's ID: the rule's ID, or null for none. A row
     * that does not exist has no key (found: array_key_exists()). IDs that
     * are not positive, and duplicates, are dropped; without IDs nothing is
     * read and [] comes back.
     *
     * The one raw reader of inventory rows. One statement through the
     * application's db component: SELECT * (before m000088 the column is
     * missing, and the rule is null), never through the cached schema of
     * the models, so a schema cached from before m000088 cannot hide a
     * rule. A positive rule ID counts as a rule without a lookup: the
     * foreign key restricts the delete of a rule that an inventory uses.
     *
     * Lock order: with $lockInShareMode the rows are read and share-locked
     * (LOCK IN SHARE MODE) in ascending ID order, in this one statement.
     * Call it so only after the transaction's job_template row lock (FOR
     * UPDATE), or as the first statement of a template create, and name
     * every inventory row the transaction will read in that one call (a
     * later read of a row it named waits for nothing): a launch
     * (JobLaunchService) locks the template row, then its inventory; a
     * template save (GatedJobTemplateLock) the template row, then the stored
     * and the new inventory. An inventory save locks only its own row,
     * first, and never takes a locking read of job_template
     * (InventoryWriteService), so the orders cannot cross, with one
     * exception: a project delete (ProjectDeletionService) locks the project
     * row and then, through the foreign key's ON DELETE SET NULL
     * (fk_inventory_project_id), the rows of the project's inventories,
     * while a template save locks the row of its project only when it
     * writes the template. So a save that moves a template into a project
     * being deleted, while its inventory belongs to that project, can
     * deadlock with the delete (GatedJobTemplateLock).
     *
     * @param list<int> $inventoryIds
     * @return array<int, int|null>
     */
    public static function readInventoryRules(array $inventoryIds, bool $lockInShareMode): array
    {
        $params = [];
        foreach (self::lockOrder($inventoryIds) as $index => $id) {
            $params[':id' . $index] = $id;
        }
        if ($params === []) {
            return [];
        }
        $sql = 'SELECT * FROM {{%inventory}} WHERE [[id]] IN (' . implode(', ', array_keys($params)) . ') ORDER BY [[id]]'
            . ($lockInShareMode ? ' LOCK IN SHARE MODE' : '');
        /** @var list<array{id: int|string, approval_rule_id?: int|string|null}> $rows */
        $rows = \Yii::$app->db->createCommand($sql, $params)->queryAll();
        $rules = [];
        foreach ($rows as $row) {
            $rules[(int)$row['id']] = self::ruleIdOf($row['approval_rule_id'] ?? null);
        }

        return $rules;
    }

    /**
     * $note filled for the template's own rule, $inventoryNote for its
     * inventory's; null unless one rule gates the template and exists.
     */
    private function note(string $note, string $inventoryNote): ?string
    {
        $rule = $this->rule;
        if ($rule === null || $this->rulesDiffer()) {
            return null;
        }

        return $this->source() === self::SOURCE_INVENTORY
            ? sprintf($inventoryNote, $rule->name, (string)$this->inventoryName)
            : sprintf($note, $rule->name);
    }

    /**
     * The positive IDs of $ids, each once, in ascending order: the order in
     * which readInventoryRules() reads and locks them.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private static function lockOrder(array $ids): array
    {
        $positive = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $positive[$id] = $id;
            }
        }
        ksort($positive);

        return array_values($positive);
    }

    /**
     * An approval rule ID as stored or assigned: a whole number as the
     * integer rule reads it (SubmittedId) is an ID; anything else is none,
     * null and a number beyond the int range included (a JSON body's 1e30
     * or 1e999, whose (int) cast warns since PHP 8.5).
     */
    private static function ruleIdOf(mixed $value): ?int
    {
        return SubmittedId::of($value);
    }

    /**
     * A record ID as stored or assigned, read as ruleIdOf() reads it; 0 for
     * none (a new record).
     */
    private static function idOf(mixed $value): int
    {
        return SubmittedId::of($value) ?? 0;
    }
}
