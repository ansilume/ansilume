<?php

declare(strict_types=1);

namespace app\components;

/**
 * Which job templates use an inventory: the live ones, listed as far as the
 * viewer may see them, and the soft-deleted ones, only counted. An
 * inventory in use cannot be deleted: job_template.inventory_id is NOT NULL
 * with a RESTRICT foreign key, and a deleted job template keeps its
 * inventory, for the history of its jobs, until its project is deleted.
 *
 * The list holds only what the viewer may see; the totals count everything,
 * so the summary never names hidden job templates. Each entry carries the
 * template's own approval rule, for the inventory page.
 */
final class InventoryUsage
{
    /** The summary of an inventory in use: the quoted name, then what uses it. */
    public const SUMMARY_IN_USE = 'Inventory %1$s is in use by %2$s.';

    /** The summary of an inventory that nothing uses: the quoted name. */
    public const SUMMARY_NOT_IN_USE = 'Inventory %s is not in use.';

    /** The summary's part for the live job templates; sprintf(): their number. */
    public const PART_TEMPLATES = '%d job template(s)';

    /** The summary's part for the soft-deleted job templates; sprintf(): their number. */
    public const PART_DELETED = '%d deleted job template(s)';

    /** The job templates card of an inventory that no live job template uses. */
    public const CARD_EMPTY = 'No job template uses this inventory.';

    /** The card's line for the job templates the viewer may not see; sprintf(): their number. */
    public const CARD_HIDDEN = '+ %d job template(s) in projects you cannot see';

    /** The card's line for the soft-deleted job templates; sprintf(): their number. */
    public const CARD_DELETED = '%d deleted job template(s) still refer to this inventory, for the history of their jobs.';

    /**
     * @param list<array{id: int, name: string, project_id: int, project_name: string|null, approval_rule_id: int|null, approval_rule_name: string|null}> $jobTemplates
     *     the live job templates the viewer may see, each with its own approval rule
     * @param int $jobTemplateTotal every live job template that uses the inventory
     * @param int $deletedJobTemplateCount the soft-deleted job templates that still refer to it
     */
    public function __construct(
        public readonly string $inventoryName,
        public readonly array $jobTemplates,
        public readonly int $jobTemplateTotal,
        public readonly int $deletedJobTemplateCount,
    ) {
    }

    public function isInUse(): bool
    {
        return $this->jobTemplateTotal > 0 || $this->deletedJobTemplateCount > 0;
    }

    public function hiddenJobTemplateCount(): int
    {
        return max(0, $this->jobTemplateTotal - count($this->jobTemplates));
    }

    /**
     * One sentence for flashes and API errors. It names only the inventory
     * and counts the job templates: the viewer may not see them all.
     */
    public function summary(): string
    {
        $name = SurveyDefinition::quote($this->inventoryName);
        $parts = [];
        if ($this->jobTemplateTotal > 0) {
            $parts[] = sprintf(self::PART_TEMPLATES, $this->jobTemplateTotal);
        }
        if ($this->deletedJobTemplateCount > 0) {
            $parts[] = sprintf(self::PART_DELETED, $this->deletedJobTemplateCount);
        }
        if ($parts === []) {
            return sprintf(self::SUMMARY_NOT_IN_USE, $name);
        }

        return sprintf(self::SUMMARY_IN_USE, $name, implode(' and ', $parts));
    }

    /**
     * @return array{in_use: bool, job_templates: list<array{id: int, name: string, project_id: int, project_name: string|null, approval_rule_id: int|null, approval_rule_name: string|null}>, hidden_job_template_count: int, deleted_job_template_count: int}
     */
    public function toArray(): array
    {
        return [
            'in_use' => $this->isInUse(),
            'job_templates' => $this->jobTemplates,
            'hidden_job_template_count' => $this->hiddenJobTemplateCount(),
            'deleted_job_template_count' => $this->deletedJobTemplateCount,
        ];
    }
}
