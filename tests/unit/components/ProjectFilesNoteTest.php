<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\ProjectFilesNote;
use app\models\Project;
use PHPUnit\Framework\TestCase;

class ProjectFilesNoteTest extends TestCase
{
    public function testNoNoteWhenTheWebServerReadEverything(): void
    {
        $this->assertNull(ProjectFilesNote::of($this->project(), '/srv/project', [], true));
    }

    public function testThePathsItCannotReadAreNamed(): void
    {
        $this->assertSame(
            sprintf(ProjectFilesNote::UNREADABLE_PATHS, 'private, secret.yml'),
            ProjectFilesNote::of($this->project(), '/srv/project', ['private', 'secret.yml'], true)
        );
    }

    public function testAtMostTenPathsAreNamed(): void
    {
        $paths = array_map(static fn (int $i): string => sprintf('locked-%02d.yml', $i), range(1, 12));
        $shown = array_slice($paths, 0, ProjectFilesNote::SHOWN);
        $shown[] = sprintf(ProjectFilesNote::MORE, 2);

        $this->assertSame(
            sprintf(ProjectFilesNote::UNREADABLE_PATHS, implode(', ', $shown)),
            ProjectFilesNote::of($this->project(), '/srv/project', $paths, true)
        );
    }

    public function testADirectoryItCannotListWhereItFoundNothingIsNotShown(): void
    {
        $this->assertSame(ProjectFilesNote::UNREADABLE_DIRECTORY, ProjectFilesNote::of($this->project(), '/srv/project', ['.'], false));
    }

    /**
     * Review finding: a directory the web server may enter but not list
     * still gives the playbooks below playbooks/, under a note that said no
     * playbooks were shown, and the note hid the other paths. It names the
     * directory among them now.
     */
    public function testADirectoryItCannotListWhereItFoundPlaybooksIsNamedAmongThePaths(): void
    {
        $this->assertSame(
            sprintf(ProjectFilesNote::UNREADABLE_PATHS, ProjectFilesNote::ITSELF . ', playbooks/locked'),
            ProjectFilesNote::of($this->project(), '/srv/project', ['.', 'playbooks/locked'], true)
        );
    }

    /**
     * Review finding: a manual project whose directory the web server cannot
     * reach, below a directory it may not enter, got no note at all, only
     * the hint to sync, which a manual project cannot.
     */
    public function testAManualProjectWhoseDirectoryCannotBeReachedIsNamed(): void
    {
        $this->assertSame(
            sprintf(ProjectFilesNote::UNREACHABLE_DIRECTORY, '/root/playbooks'),
            ProjectFilesNote::of($this->project(Project::SCM_TYPE_MANUAL, '/root/playbooks'), null, [], false)
        );
    }

    public function testNoNoteWithoutADirectoryForAGitProjectOrAManualOneWithoutAPath(): void
    {
        $this->assertNull(ProjectFilesNote::of($this->project(Project::SCM_TYPE_GIT, '/srv/project'), null, [], false));
        $this->assertNull(ProjectFilesNote::of($this->project(Project::SCM_TYPE_MANUAL, null), null, [], false));
    }

    /**
     * Review finding: while a sync replaces files, the page named them as
     * paths the web server may not read.
     */
    public function testNoNoteWhileTheProjectSyncs(): void
    {
        $syncing = $this->project(Project::SCM_TYPE_GIT, null, Project::STATUS_SYNCING);

        $this->assertNull(ProjectFilesNote::of($syncing, '/srv/project', ['playbooks/new.yml'], true));
    }

    private function project(string $scmType = Project::SCM_TYPE_MANUAL, ?string $localPath = '/srv/project', string $status = Project::STATUS_SYNCED): Project
    {
        $project = new Project();
        $project->scm_type = $scmType;
        $project->local_path = $localPath;
        $project->status = $status;

        return $project;
    }
}
