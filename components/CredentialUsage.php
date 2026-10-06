<?php

declare(strict_types=1);

namespace app\components;

/**
 * Where a credential is used: job templates (as primary or additional
 * credential), projects (as SCM credential) and jobs that have not started
 * yet. Lists hold only what the viewer may see; totals count everything, so
 * the summary never names hidden resources.
 */
final class CredentialUsage
{
    /**
     * @param list<array{id: int, name: string, project_id: int, project_name: string|null, role: string}> $jobTemplates
     * @param list<array{id: int, name: string}> $projects
     */
    public function __construct(
        public readonly string $credentialName,
        public readonly array $jobTemplates,
        public readonly int $jobTemplateTotal,
        public readonly array $projects,
        public readonly int $projectTotal,
        public readonly int $pendingJobCount,
    ) {
    }

    public function isInUse(): bool
    {
        return $this->jobTemplateTotal > 0 || $this->projectTotal > 0 || $this->pendingJobCount > 0;
    }

    public function hiddenJobTemplateCount(): int
    {
        return max(0, $this->jobTemplateTotal - count($this->jobTemplates));
    }

    public function hiddenProjectCount(): int
    {
        return max(0, $this->projectTotal - count($this->projects));
    }

    public function summary(): string
    {
        $parts = [];
        if ($this->jobTemplateTotal > 0) {
            $parts[] = $this->jobTemplateTotal . ' job template(s)';
        }
        if ($this->projectTotal > 0) {
            $parts[] = $this->projectTotal . ' project(s)';
        }
        if ($this->pendingJobCount > 0) {
            $parts[] = $this->pendingJobCount . ' pending job(s)';
        }
        if ($parts === []) {
            return "Credential \"{$this->credentialName}\" is not in use.";
        }
        $last = array_pop($parts);
        $list = $parts === [] ? $last : implode(', ', $parts) . ' and ' . $last;

        return "Credential \"{$this->credentialName}\" is in use by {$list}.";
    }

    /**
     * @return array{in_use: bool, job_templates: list<array{id: int, name: string, project_id: int, project_name: string|null, role: string}>, projects: list<array{id: int, name: string}>, hidden_job_template_count: int, hidden_project_count: int, pending_job_count: int}
     */
    public function toArray(): array
    {
        return [
            'in_use' => $this->isInUse(),
            'job_templates' => $this->jobTemplates,
            'projects' => $this->projects,
            'hidden_job_template_count' => $this->hiddenJobTemplateCount(),
            'hidden_project_count' => $this->hiddenProjectCount(),
            'pending_job_count' => $this->pendingJobCount,
        ];
    }
}
