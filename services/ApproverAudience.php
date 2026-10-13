<?php

declare(strict_types=1);

namespace app\services;

/**
 * Who may see what is to be approved, as sets of projects: a user sees it
 * when they may view every project of at least one set.
 *
 * A job template's request has one set, the template's project. A workflow
 * has one set too, the projects of its job steps' templates (none for a
 * workflow without job steps, which everyone sees). Without any set, only
 * users whom team scoping does not restrict see it: superadmins and admins,
 * as for a workflow whose job step lost its template.
 *
 * Team scoping applies only while some project is restricted to a team; the
 * caller decides that ({@see ApproverFilter}).
 */
final class ApproverAudience
{
    /**
     * @param list<list<int>> $projectSets
     */
    private function __construct(private readonly array $projectSets)
    {
    }

    /**
     * Users who may view every one of $projectIds: with none, everyone.
     *
     * @param list<int> $projectIds
     */
    public static function ofProjects(array $projectIds): self
    {
        return new self([array_values(array_unique($projectIds))]);
    }

    /**
     * Users who belong to at least one of $audiences: with none, only users
     * whom team scoping does not restrict.
     */
    public static function anyOf(self ...$audiences): self
    {
        $sets = [];
        foreach ($audiences as $audience) {
            foreach ($audience->projectSets as $projectIds) {
                $sets[] = $projectIds;
            }
        }

        return new self($sets);
    }

    /**
     * Only users whom team scoping does not restrict.
     */
    public static function unrestrictedOnly(): self
    {
        return new self([]);
    }

    /**
     * @return list<int> every project the audience names
     */
    public function projectIds(): array
    {
        return array_values(array_unique(array_merge([], ...$this->projectSets)));
    }

    /**
     * Whether a user belongs to the audience.
     *
     * @param callable(int): bool $mayView whether the user may view a project
     */
    public function admits(callable $mayView): bool
    {
        foreach ($this->projectSets as $projectIds) {
            $viewsAll = true;
            foreach ($projectIds as $projectId) {
                $viewsAll = $viewsAll && $mayView($projectId);
            }
            if ($viewsAll) {
                return true;
            }
        }

        return false;
    }
}
