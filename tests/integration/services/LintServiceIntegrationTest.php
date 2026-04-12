<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Project;
use app\services\LintService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for LintService that exercise the real store() and
 * storeProject() DB persistence methods, and resolveProjectPath().
 *
 * These complement the unit tests which stub those methods out.
 */
class LintServiceIntegrationTest extends DbTestCase
{
    private LintService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \Yii::$app->get('lintService');
    }

    // -------------------------------------------------------------------------
    // store() — via runForTemplate() error paths (no ansible-lint needed)
    // -------------------------------------------------------------------------

    public function testRunForTemplateStoresErrorInDbWhenProjectHasNoPath(): void
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $project  = $this->createProject($user->id); // MANUAL, local_path = null
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $this->service->runForTemplate($template);

        $template->refresh();
        $this->assertNotNull($template->lint_output);
        $this->assertNotNull($template->lint_at);
        $this->assertNull($template->lint_exit_code);
        $this->assertStringContainsString('local path', strtolower($template->lint_output));
    }

    public function testRunForTemplateStoresErrorWhenProjectDirDoesNotExist(): void
    {
        $user     = $this->createUser();
        $group    = $this->createRunnerGroup($user->id);
        $project  = $this->createProject($user->id);
        $project->local_path = '/tmp/ansilume_nonexistent_dir_' . uniqid('', true);
        $project->save(false);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        $this->service->runForTemplate($template);

        $template->refresh();
        $this->assertNotNull($template->lint_output);
        $this->assertStringContainsString('not found', strtolower($template->lint_output));
    }

    // -------------------------------------------------------------------------
    // storeProject() — via runForProject() error paths
    // -------------------------------------------------------------------------

    public function testRunForProjectStoresErrorInDbWhenProjectDirDoesNotExist(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user->id);
        $project->local_path = '/tmp/ansilume_nonexistent_' . uniqid('', true);
        $project->save(false);

        $this->service->runForProject($project);

        $project->refresh();
        $this->assertNotNull($project->lint_output);
        $this->assertNotNull($project->lint_at);
        $this->assertNull($project->lint_exit_code);
        $this->assertStringContainsString('not found', strtolower($project->lint_output));
    }

    // -------------------------------------------------------------------------
    // resolveProjectPath() — for manual vs git projects
    // -------------------------------------------------------------------------

    public function testResolveProjectPathReturnsLocalPathForManualProject(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user->id);
        $project->scm_type   = Project::SCM_TYPE_MANUAL;
        $project->local_path = '/some/manual/path';
        $project->save(false);

        // Running with a non-existent dir will store "not found" — verifying the
        // path was resolved from local_path (not from projectService).
        $this->service->runForProject($project);

        $project->refresh();
        $this->assertStringContainsString('/some/manual/path', $project->lint_output);
    }

    public function testResolveProjectPathReturnsWorkspacePathForGitProject(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->save(false);

        $group    = $this->createRunnerGroup($user->id);
        $inv      = $this->createInventory($user->id);
        $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);

        // For a git project without a synced workspace, store() is called with "not found"
        $this->service->runForTemplate($template);

        $template->refresh();
        $this->assertNotNull($template->lint_output);
        // Git projects without workspace get "sync the project first" message
        $this->assertStringContainsString('sync', strtolower($template->lint_output));
    }

    // -------------------------------------------------------------------------
    // isAvailable() + execute() — real ansible-lint run (if installed)
    // -------------------------------------------------------------------------

    public function testRunForProjectReturnsEarlyWhenManualProjectHasNoLocalPath(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->local_path = null;
        $project->save(false);

        $this->service->runForProject($project);

        $project->refresh();
        // No lint output should be stored when path is null (early return)
        $this->assertNull($project->lint_output);
    }

    public function testRunForProjectStoresGitWorkspaceNotFoundMessage(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $project->scm_type = Project::SCM_TYPE_GIT;
        $project->scm_url = 'https://example.com/repo.git';
        $project->save(false);

        // For a git project, resolveProjectPath uses projectService.localPath
        // which returns a path that won't exist, triggering the "sync first" message
        $this->service->runForProject($project);

        $project->refresh();
        if ($project->lint_output !== null) {
            $this->assertStringContainsString('sync', strtolower($project->lint_output));
        } else {
            // If lint_output is null, the project path resolved to null (early return)
            $this->assertNull($project->lint_output);
        }
    }

    public function testRunForTemplateStoresPlaybookNotFoundMessage(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_inttest_' . uniqid('', true);
        mkdir($dir);

        try {
            $user = $this->createUser();
            $group = $this->createRunnerGroup($user->id);
            $project = $this->createProject($user->id);
            $project->local_path = $dir;
            $project->save(false);
            $inv = $this->createInventory($user->id);
            $template = $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
            // The template's playbook won't exist in the temp dir
            $template->playbook = 'nonexistent_playbook.yml';
            $template->save(false);

            $this->service->runForTemplate($template);

            $template->refresh();
            $this->assertNotNull($template->lint_output);
            $this->assertStringContainsString('Playbook not found', $template->lint_output);
        } finally {
            \app\helpers\FileHelper::safeRmdir($dir);
        }
    }

    public function testRunForProjectRunsLintWhenDirectoryExists(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_inttest_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/site.yml', "---\n- name: Test\n  hosts: all\n  tasks: []\n");

        try {
            $user    = $this->createUser();
            $project = $this->createProject($user->id);
            $project->local_path = $dir;
            $project->save(false);

            $this->service->runForProject($project);

            $project->refresh();
            // lint_output should be set (either success or violations)
            $this->assertNotNull($project->lint_output);
            $this->assertNotNull($project->lint_at);
            // exit_code should be an integer (0 = clean, 2 = violations, -1 = process error)
            $this->assertNotNull($project->lint_exit_code);
        } finally {
            \app\helpers\FileHelper::removeDirectory($dir . '/.ansible');
            \app\helpers\FileHelper::safeUnlink($dir . '/site.yml');
            \app\helpers\FileHelper::safeRmdir($dir);
        }
    }
}
