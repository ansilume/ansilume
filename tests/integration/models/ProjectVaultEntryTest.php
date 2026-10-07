<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Project;
use app\models\ProjectVaultEntry;
use app\tests\integration\DbTestCase;

/**
 * One encrypted file or inline vault value of a project's last vault scan.
 */
class ProjectVaultEntryTest extends DbTestCase
{
    // -- relations and lifetime ------------------------------------------------------

    public function testAProjectListsItsEntriesByPathAndLine(): void
    {
        $project = $this->createProject((int)$this->createUser('vault-entry')->id);
        // Stored in an order that is neither the wanted one nor its reverse.
        $this->storedEntry((int)$project->id, 'group_vars/all.yml', ProjectVaultEntry::KIND_INLINE, 12, 'db_password');
        $this->storedEntry((int)$project->id, 'vars/secrets.yml', ProjectVaultEntry::KIND_FILE, null, null);
        $this->storedEntry((int)$project->id, 'group_vars/all.yml', ProjectVaultEntry::KIND_INLINE, 3, 'app_secret');

        $project = Project::findOne((int)$project->id);
        $this->assertNotNull($project);

        $this->assertSame(
            ['group_vars/all.yml:3', 'group_vars/all.yml:12', 'vars/secrets.yml:'],
            array_map(static fn (ProjectVaultEntry $entry): string => $entry->path . ':' . ($entry->line ?? ''), $project->vaultEntries)
        );
        foreach ($project->vaultEntries as $entry) {
            $this->assertSame((int)$project->id, (int)$entry->project->id);
        }
    }

    public function testEntriesAreNotSharedBetweenProjects(): void
    {
        $userId = (int)$this->createUser('vault-entry')->id;
        $project = $this->createProject($userId);
        $other = $this->createProject($userId);
        $this->storedEntry((int)$other->id, 'vars/secrets.yml', ProjectVaultEntry::KIND_FILE, null, null);

        $this->assertSame([], $project->vaultEntries);
        $this->assertCount(1, $other->vaultEntries);
    }

    public function testEntriesGoWithTheirProject(): void
    {
        $project = $this->createProject((int)$this->createUser('vault-entry')->id);
        $this->storedEntry((int)$project->id, 'vars/secrets.yml', ProjectVaultEntry::KIND_FILE, null, null);

        $this->assertSame(1, $project->delete());

        $this->assertSame(0, (int)ProjectVaultEntry::find()->where(['project_id' => $project->id])->count());
    }

    // -- helpers -----------------------------------------------------------------

    private function entry(string $path, string $kind, ?int $line, ?string $key): ProjectVaultEntry
    {
        $entry = new ProjectVaultEntry();
        $entry->path = $path;
        $entry->kind = $kind;
        $entry->line = $line;
        $entry->var_key = $key;

        return $entry;
    }

    private function storedEntry(int $projectId, string $path, string $kind, ?int $line, ?string $key): void
    {
        $entry = $this->entry($path, $kind, $line, $key);
        $entry->project_id = $projectId;
        $entry->format_version = '1.1';
        $entry->fingerprint = hash('sha256', $path . ':' . $line);
        $entry->save(false);
    }
}
