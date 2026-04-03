<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\Project;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for Project model — validateScmUrl and statusLabel.
 * No database required.
 */
class ProjectTest extends TestCase
{
    // ── statusLabel ───────────────────────────────────────────────────────────

    public function testStatusLabelKnownStatuses(): void
    {
        $this->assertSame('New', Project::statusLabel(Project::STATUS_NEW));
        $this->assertSame('Syncing', Project::statusLabel(Project::STATUS_SYNCING));
        $this->assertSame('Synced', Project::statusLabel(Project::STATUS_SYNCED));
        $this->assertSame('Error', Project::statusLabel(Project::STATUS_ERROR));
    }

    public function testStatusLabelUnknownReturnsRaw(): void
    {
        $this->assertSame('whatever', Project::statusLabel('whatever'));
    }

    // ── validateScmUrl ────────────────────────────────────────────────────────

    /** @dataProvider validUrlProvider */
    public function testValidScmUrlsPassValidation(string $url): void
    {
        $project = $this->makeProject(['scm_url' => $url, 'scm_type' => Project::SCM_TYPE_GIT]);
        $project->validateScmUrl();
        $this->assertFalse($project->hasErrors('scm_url'), "Expected '{$url}' to be valid");
    }

    public static function validUrlProvider(): array
    {
        return [
            ['https://github.com/org/repo.git'],
            ['http://internal-git.example.com/repo'],
            ['git@github.com:org/repo.git'],
            ['git@gitlab.com:group/project.git'],
            ['ssh@git.example.com:path/repo.git'],
            ['ssh://git@github.com/org/repo.git'],
        ];
    }

    /** @dataProvider invalidUrlProvider */
    public function testInvalidScmUrlsFailValidation(string $url): void
    {
        $project = $this->makeProject(['scm_url' => $url, 'scm_type' => Project::SCM_TYPE_GIT]);
        $project->validateScmUrl();
        $this->assertTrue($project->hasErrors('scm_url'), "Expected '{$url}' to be invalid");
    }

    public static function invalidUrlProvider(): array
    {
        return [
            ['ftp://example.com/repo.git'],
            ['not-a-url'],
            ['github.com/org/repo'],
            ['/local/path/repo'],
        ];
    }

    public function testEmptyUrlSkipsValidation(): void
    {
        $project = $this->makeProject(['scm_url' => '', 'scm_type' => Project::SCM_TYPE_GIT]);
        $project->validateScmUrl();
        $this->assertFalse($project->hasErrors('scm_url'));
    }

    // ── isHttpsScmUrl / isSshScmUrl ──────────────────────────────────────────

    public function testIsHttpsScmUrlForHttpsUrls(): void
    {
        $p = $this->makeProject(['scm_url' => 'https://github.com/org/repo.git']);
        $this->assertTrue($p->isHttpsScmUrl());
        $this->assertFalse($p->isSshScmUrl());
    }

    public function testIsHttpsScmUrlForHttpUrls(): void
    {
        $p = $this->makeProject(['scm_url' => 'http://internal.example.com/repo']);
        $this->assertTrue($p->isHttpsScmUrl());
        $this->assertFalse($p->isSshScmUrl());
    }

    public function testIsSshScmUrlForGitAtUrls(): void
    {
        $p = $this->makeProject(['scm_url' => 'git@github.com:org/repo.git']);
        $this->assertTrue($p->isSshScmUrl());
        $this->assertFalse($p->isHttpsScmUrl());
    }

    public function testIsSshScmUrlForSshProtocolUrls(): void
    {
        $p = $this->makeProject(['scm_url' => 'ssh://git@github.com/org/repo.git']);
        $this->assertTrue($p->isSshScmUrl());
        $this->assertFalse($p->isHttpsScmUrl());
    }

    public function testIsNeitherForEmptyUrl(): void
    {
        $p = $this->makeProject(['scm_url' => '']);
        $this->assertFalse($p->isHttpsScmUrl());
        $this->assertFalse($p->isSshScmUrl());
    }

    // ── constants ─────────────────────────────────────────────────────────────

    public function testScmTypeConstants(): void
    {
        $this->assertSame('git', Project::SCM_TYPE_GIT);
        $this->assertSame('manual', Project::SCM_TYPE_MANUAL);
    }

    private function makeProject(array $attrs = []): Project
    {
        $p = $this->getMockBuilder(Project::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $p->method('attributes')->willReturn(
            ['id', 'name', 'description', 'scm_type', 'scm_url', 'scm_branch',
             'local_path', 'scm_credential_id', 'status', 'last_synced_at',
             'last_sync_error', 'created_by', 'created_at', 'updated_at']
        );
        $p->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($p, array_merge([
            'scm_type' => Project::SCM_TYPE_GIT,
            'scm_url'  => '',
            'status'   => Project::STATUS_NEW,
        ], $attrs));
        return $p;
    }
}
