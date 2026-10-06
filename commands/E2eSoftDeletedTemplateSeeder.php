<?php

declare(strict_types=1);

namespace app\commands;

use app\models\JobTemplate;
use app\models\Project;

/**
 * Seeds a project whose only job template has been soft-deleted.
 *
 * Regression fixture: deleting such a project failed with an integrity
 * constraint violation (HTTP 500) because the guard counted only visible
 * templates while the RESTRICT foreign key still saw the soft-deleted row.
 * projects/crud.spec.ts deletes this project through the UI and expects a
 * success flash. Re-seeded from scratch on every run so the spec can delete it.
 */
class E2eSoftDeletedTemplateSeeder
{
    private const PROJECT_NAME = 'e2e-softdel-project';
    private const TEMPLATE_NAME = 'e2e-softdel-template';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId, int $inventoryId, int $runnerGroupId): void
    {
        $this->deleteExisting();

        $project = new Project();
        $project->name = self::PROJECT_NAME;
        $project->description = 'E2E project with a soft-deleted template';
        $project->scm_type = 'manual';
        $project->status = 'new';
        $project->created_by = $userId;
        $project->save(false);

        $template = new JobTemplate();
        $template->name = self::TEMPLATE_NAME;
        $template->description = 'Soft-deleted template still referencing its project';
        $template->project_id = $project->id;
        $template->inventory_id = $inventoryId;
        $template->runner_group_id = $runnerGroupId;
        $template->playbook = 'site.yml';
        $template->verbosity = 0;
        $template->forks = 5;
        $template->become = false;
        $template->timeout_minutes = 30;
        $template->created_by = $userId;
        $template->save(false);
        $template->softDelete();

        ($this->logger)("  Created project {$project->name} (ID {$project->id}) with soft-deleted template.\n");
    }

    private function deleteExisting(): void
    {
        // deleteAll() bypasses the deleted_at scope — remove hidden rows too.
        JobTemplate::deleteAll(['name' => self::TEMPLATE_NAME]);
        Project::deleteAll(['name' => self::PROJECT_NAME]);
    }
}
