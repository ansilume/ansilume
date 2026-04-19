<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\JobTemplate;
use app\models\Project;
use app\services\LintService;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for LintService guard-clause paths and core logic.
 *
 * Uses an anonymous subclass to stub out isAvailable() and execute() so that
 * no real ansible-lint process is spawned. store() and storeProject() are also
 * overridden to capture what would be persisted without hitting the database.
 */
class LintServiceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeService(bool $available = true, string $execOutput = 'ok', int $execExit = 0): LintService
    {
        return new class ($available, $execOutput, $execExit) extends LintService {
            public array $stored         = [];
            public array $storedProjects = [];

            public function __construct(
                private readonly bool $available,
                private readonly string $execOutput,
                private readonly int $execExit,
            ) {
            }

            protected function isAvailable(): bool
            {
                return $this->available;
            }

            protected function execute(?string $playbook, string $cwd): array
            {
                return [$this->execOutput, $this->execExit];
            }

            protected function store(JobTemplate $template, ?int $exitCode, string $output): void
            {
                $this->stored[] = ['exitCode' => $exitCode, 'output' => $output];
            }

            protected function storeProject(Project $project, ?int $exitCode, string $output): void
            {
                $this->storedProjects[] = ['exitCode' => $exitCode, 'output' => $output];
            }
        };
    }

    private function makeTemplate(array $attributes, ?Project $project = null): JobTemplate
    {
        $t = $this->getMockBuilder(JobTemplate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
        $t->method('save')->willReturn(true);

        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($t, $attributes);

        $relRef = new \ReflectionProperty(BaseActiveRecord::class, '_related');
        $relRef->setAccessible(true);
        $relRef->setValue($t, ['project' => $project]);

        return $t;
    }

    private function makeProject(string $scmType, ?string $localPath): Project
    {
        $p = $this->getMockBuilder(Project::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
        $p->method('save')->willReturn(true);

        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($p, ['scm_type' => $scmType, 'local_path' => $localPath]);

        return $p;
    }

    // -------------------------------------------------------------------------
    // runForTemplate — guard clauses
    // -------------------------------------------------------------------------

    public function testRunForTemplateStoresErrorWhenProjectIsNull(): void
    {
        $svc      = $this->makeService();
        $template = $this->makeTemplate(['playbook' => 'site.yml'], null);

        $svc->runForTemplate($template);

        $this->assertCount(1, $svc->stored);
        $this->assertNull($svc->stored[0]['exitCode']);
        $this->assertStringContainsString('No project', $svc->stored[0]['output']);
    }

    public function testRunForTemplateStoresErrorWhenManualProjectHasNoLocalPath(): void
    {
        $svc      = $this->makeService();
        $project  = $this->makeProject(Project::SCM_TYPE_MANUAL, null);
        $template = $this->makeTemplate(['playbook' => 'site.yml'], $project);

        $svc->runForTemplate($template);

        $this->assertCount(1, $svc->stored);
        $this->assertNull($svc->stored[0]['exitCode']);
        $this->assertStringContainsString('No local path', $svc->stored[0]['output']);
    }

    public function testRunForTemplateStoresErrorWhenManualProjectPathNotFound(): void
    {
        $svc      = $this->makeService();
        $project  = $this->makeProject(Project::SCM_TYPE_MANUAL, '/tmp/ansilume_test_nonexistent_path_xyz');
        $template = $this->makeTemplate(['playbook' => 'site.yml'], $project);

        $svc->runForTemplate($template);

        $this->assertCount(1, $svc->stored);
        $this->assertNull($svc->stored[0]['exitCode']);
        $this->assertStringContainsString('Project path not found', $svc->stored[0]['output']);
    }

    public function testRunForTemplateStoresErrorWhenPlaybookNotFound(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_test_' . uniqid('', true);
        mkdir($dir);

        try {
            $svc      = $this->makeService();
            $project  = $this->makeProject(Project::SCM_TYPE_MANUAL, $dir);
            $template = $this->makeTemplate(['playbook' => 'missing_playbook.yml'], $project);

            $svc->runForTemplate($template);

            $this->assertCount(1, $svc->stored);
            $this->assertNull($svc->stored[0]['exitCode']);
            $this->assertStringContainsString('Playbook not found', $svc->stored[0]['output']);
        } finally {
            rmdir($dir);
        }
    }

    public function testRunForTemplateSkipsWhenAnsibleLintNotAvailable(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_test_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/site.yml', "---\n- hosts: all\n");

        try {
            $svc      = $this->makeService(available: false);
            $project  = $this->makeProject(Project::SCM_TYPE_MANUAL, $dir);
            $template = $this->makeTemplate(['playbook' => 'site.yml'], $project);

            $svc->runForTemplate($template);

            // Nothing stored — silent skip
            $this->assertCount(0, $svc->stored);
        } finally {
            unlink($dir . '/site.yml');
            rmdir($dir);
        }
    }

    public function testRunForTemplateStoresResultOnSuccess(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_test_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/site.yml', "---\n- hosts: all\n");

        try {
            $svc      = $this->makeService(available: true, execOutput: 'Passed: 5', execExit: 0);
            $project  = $this->makeProject(Project::SCM_TYPE_MANUAL, $dir);
            $template = $this->makeTemplate(['playbook' => 'site.yml'], $project);

            $svc->runForTemplate($template);

            $this->assertCount(1, $svc->stored);
            $this->assertSame(0, $svc->stored[0]['exitCode']);
            $this->assertSame('Passed: 5', $svc->stored[0]['output']);
        } finally {
            unlink($dir . '/site.yml');
            rmdir($dir);
        }
    }

    public function testRunForTemplateStoresIssuesWhenExitCodeNonZero(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_test_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/site.yml', "---\n- hosts: all\n");

        try {
            $svc      = $this->makeService(available: true, execOutput: 'Rule violation: name', execExit: 2);
            $project  = $this->makeProject(Project::SCM_TYPE_MANUAL, $dir);
            $template = $this->makeTemplate(['playbook' => 'site.yml'], $project);

            $svc->runForTemplate($template);

            $this->assertCount(1, $svc->stored);
            $this->assertSame(2, $svc->stored[0]['exitCode']);
            $this->assertStringContainsString('violation', $svc->stored[0]['output']);
        } finally {
            unlink($dir . '/site.yml');
            rmdir($dir);
        }
    }

    // -------------------------------------------------------------------------
    // runForProject — guard clauses
    // -------------------------------------------------------------------------

    public function testRunForProjectSkipsEarlyWhenManualProjectHasNoPath(): void
    {
        $svc     = $this->makeService();
        $project = $this->makeProject(Project::SCM_TYPE_MANUAL, null);

        $svc->runForProject($project);

        $this->assertCount(0, $svc->storedProjects);
    }

    public function testRunForProjectSkipsEarlyWhenManualProjectHasEmptyPath(): void
    {
        $svc     = $this->makeService();
        $project = $this->makeProject(Project::SCM_TYPE_MANUAL, '');

        $svc->runForProject($project);

        $this->assertCount(0, $svc->storedProjects);
    }

    public function testRunForProjectStoresErrorWhenPathNotFound(): void
    {
        $svc     = $this->makeService();
        $project = $this->makeProject(Project::SCM_TYPE_MANUAL, '/tmp/ansilume_test_no_such_dir_xyz');

        $svc->runForProject($project);

        $this->assertCount(1, $svc->storedProjects);
        $this->assertNull($svc->storedProjects[0]['exitCode']);
        $this->assertStringContainsString('Project path not found', $svc->storedProjects[0]['output']);
    }

    public function testRunForProjectSkipsWhenAnsibleLintNotAvailable(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_proj_' . uniqid('', true);
        mkdir($dir);

        try {
            $svc     = $this->makeService(available: false);
            $project = $this->makeProject(Project::SCM_TYPE_MANUAL, $dir);

            $svc->runForProject($project);

            $this->assertCount(0, $svc->storedProjects);
        } finally {
            rmdir($dir);
        }
    }

    public function testRunForProjectStoresResultOnSuccess(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_proj_' . uniqid('', true);
        mkdir($dir);

        try {
            $svc     = $this->makeService(available: true, execOutput: 'All good', execExit: 0);
            $project = $this->makeProject(Project::SCM_TYPE_MANUAL, $dir);

            $svc->runForProject($project);

            $this->assertCount(1, $svc->storedProjects);
            $this->assertSame(0, $svc->storedProjects[0]['exitCode']);
            $this->assertSame('All good', $svc->storedProjects[0]['output']);
        } finally {
            rmdir($dir);
        }
    }

    // -------------------------------------------------------------------------
    // runForProject — git project workspace not found
    // -------------------------------------------------------------------------

    public function testRunForProjectStoresGitWorkspaceNotFoundMessage(): void
    {
        // Create a service subclass that also stubs resolveProjectPath for git
        $svc = new class extends LintService {
            public array $stored         = [];
            public array $storedProjects = [];

            protected function isAvailable(): bool
            {
                return true;
            }

            protected function execute(?string $playbook, string $cwd): array
            {
                return ['ok', 0];
            }

            protected function resolveProjectPath(Project $project): ?string
            {
                // Simulate git project with a resolved but non-existent path
                return '/tmp/ansilume_nonexistent_workspace_' . uniqid('', true);
            }

            protected function store(JobTemplate $template, ?int $exitCode, string $output): void
            {
                $this->stored[] = ['exitCode' => $exitCode, 'output' => $output];
            }

            protected function storeProject(Project $project, ?int $exitCode, string $output): void
            {
                $this->storedProjects[] = ['exitCode' => $exitCode, 'output' => $output];
            }
        };

        $project = $this->makeProject(Project::SCM_TYPE_GIT, null);
        $svc->runForProject($project);

        $this->assertCount(1, $svc->storedProjects);
        $this->assertNull($svc->storedProjects[0]['exitCode']);
        $this->assertStringContainsString('sync the project first', $svc->storedProjects[0]['output']);
    }

    // -------------------------------------------------------------------------
    // runForTemplate — empty execute output gets "(no output)"
    // -------------------------------------------------------------------------

    public function testRunForTemplateStoresNoOutputFallbackWhenExecuteReturnsEmpty(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_test_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/site.yml', "---\n- hosts: all\n");

        try {
            $svc      = $this->makeService(available: true, execOutput: '', execExit: 0);
            $project  = $this->makeProject(Project::SCM_TYPE_MANUAL, $dir);
            $template = $this->makeTemplate(['playbook' => 'site.yml'], $project);

            $svc->runForTemplate($template);

            $this->assertCount(1, $svc->stored);
            $this->assertSame(0, $svc->stored[0]['exitCode']);
            $this->assertSame('(no output)', $svc->stored[0]['output']);
        } finally {
            unlink($dir . '/site.yml');
            rmdir($dir);
        }
    }

    public function testRunForProjectStoresNoOutputFallbackWhenExecuteReturnsEmpty(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_proj_' . uniqid('', true);
        mkdir($dir);

        try {
            $svc     = $this->makeService(available: true, execOutput: '', execExit: 0);
            $project = $this->makeProject(Project::SCM_TYPE_MANUAL, $dir);

            $svc->runForProject($project);

            $this->assertCount(1, $svc->storedProjects);
            $this->assertSame(0, $svc->storedProjects[0]['exitCode']);
            $this->assertSame('(no output)', $svc->storedProjects[0]['output']);
        } finally {
            rmdir($dir);
        }
    }

    // -------------------------------------------------------------------------
    // runForTemplate — git project "sync first" message
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Regression: issue #16 — ansible-lint cache dir must be writable
    // -------------------------------------------------------------------------

    /**
     * Regression test for issue #16: ansible-lint emits warnings about
     * /.ansible not being writable. The execute() method must create a
     * writable .ansible dir in the CWD and pass it as ANSIBLE_HOME.
     */
    public function testExecuteCreatesWritableCacheDirInCwd(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/services/LintService.php'
        );
        $this->assertNotFalse($source);

        $this->assertStringContainsString(
            'ANSIBLE_HOME',
            $source,
            'execute() must set ANSIBLE_HOME so ansible-lint has a writable cache dir'
        );

        // The .ansible dir must be created inside $cwd before proc_open
        $this->assertStringContainsString(
            "/.ansible",
            $source,
            'execute() must create .ansible cache dir inside project CWD'
        );

        // proc_open must receive an env argument (5th parameter)
        $this->assertMatchesRegularExpression(
            '/proc_open\s*\(\s*\$cmd\s*,\s*\$descriptors\s*,\s*\$pipes\s*,\s*\$cwd\s*,\s*\$env\s*\)/',
            $source,
            'proc_open in execute() must pass $env as 5th argument'
        );
    }

    /**
     * Regression test for issue #16: verify that execute() creates .ansible
     * dir inside the CWD so ansible-compat does not warn about /.ansible.
     */
    public function testExecuteCreatesDotAnsibleDirInCwd(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_cache_' . uniqid('', true);
        mkdir($dir);

        try {
            // Use a subclass that exposes execute() as public
            $svc = new class extends LintService {
                public array $stored = [];
                public array $storedProjects = [];

                /** @return array{0: string, 1: int} */
                public function callExecute(?string $playbook, string $cwd): array
                {
                    return $this->execute($playbook, $cwd);
                }

                protected function store(JobTemplate $template, ?int $exitCode, string $output): void
                {
                    $this->stored[] = ['exitCode' => $exitCode, 'output' => $output];
                }

                protected function storeProject(Project $project, ?int $exitCode, string $output): void
                {
                    $this->storedProjects[] = ['exitCode' => $exitCode, 'output' => $output];
                }
            };

            // Before execute, .ansible should not exist
            $this->assertDirectoryDoesNotExist($dir . '/.ansible');

            // execute() will try to run ansible-lint (may or may not be installed),
            // but the key assertion is that it creates the .ansible cache dir
            $svc->callExecute(null, $dir);

            $this->assertDirectoryExists(
                $dir . '/.ansible',
                'execute() must create .ansible cache dir in CWD to prevent ansible-compat warnings'
            );
        } finally {
            // Recursively clean up — ansible-lint may create nested dirs
            $this->removeDir($dir);
        }
    }

    public function testRunForTemplateStoresGitWorkspaceNotFoundMessage(): void
    {
        // Use a subclass that stubs resolveProjectPath to return a non-existent dir
        // to hit the git "sync the project first" branch in runForTemplate
        $svc = new class extends LintService {
            public array $stored         = [];
            public array $storedProjects = [];

            protected function isAvailable(): bool
            {
                return true;
            }

            protected function execute(?string $playbook, string $cwd): array
            {
                return ['ok', 0];
            }

            protected function resolveProjectPath(Project $project): ?string
            {
                return '/tmp/ansilume_nonexistent_ws_' . uniqid('', true);
            }

            protected function store(JobTemplate $template, ?int $exitCode, string $output): void
            {
                $this->stored[] = ['exitCode' => $exitCode, 'output' => $output];
            }

            protected function storeProject(Project $project, ?int $exitCode, string $output): void
            {
                $this->storedProjects[] = ['exitCode' => $exitCode, 'output' => $output];
            }
        };

        $project  = $this->makeProject(Project::SCM_TYPE_GIT, null);
        $template = $this->makeTemplate(['playbook' => 'site.yml'], $project);

        $svc->runForTemplate($template);

        $this->assertCount(1, $svc->stored);
        $this->assertNull($svc->stored[0]['exitCode']);
        $this->assertStringContainsString('sync the project first', $svc->stored[0]['output']);
    }

    // -------------------------------------------------------------------------
    // Regression: ensureCacheDir must survive an unusable .ansible path
    //
    // Original bug: queue-worker ran as root, synced an SCM project, and its
    // auto-lint-after-sync created $cwd/.ansible owned by root mode 755.
    // When the web (www-data) user later clicked "Run Lint" it hit
    //   CRITICAL:root:Unhandled exception when retrieving 'DEFAULT_LOCAL_TMP':
    //   [Errno 13] Permission denied: '.../.ansible/tmp/ansible-local-…'
    // The infrastructure fix drops worker to www-data so both sides are the
    // same user. These tests pin the defensive fallback in ensureCacheDir:
    // if the CWD-local cache dir can't be used, it must fall back to a
    // unique, writable temp dir instead of returning an unusable path.
    //
    // The regression scenario (dir exists but is not writable by the current
    // user) relies on is_writable(), which is bypassed for root. To get
    // coverage that actually executes in every environment we use a regular
    // file at $cwd/.ansible — mkdir fails for both root and www-data in that
    // case, exercising the same fallback branch.
    // -------------------------------------------------------------------------
    public function testEnsureCacheDirFallsBackWhenDotAnsiblePathIsBlockedByFile(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_cache_blocked_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/.ansible', '');

        try {
            $svc = new class extends LintService {
                public function callEnsureCacheDir(string $cwd): string
                {
                    return $this->ensureCacheDir($cwd);
                }
            };

            $result = $svc->callEnsureCacheDir($dir);

            $this->assertNotSame(
                $dir . '/.ansible',
                $result,
                'ensureCacheDir must not return the CWD-local path when mkdir cannot succeed there.'
            );
            $this->assertTrue(
                is_dir($result) && is_writable($result),
                'Fallback cache dir must exist and be writable: ' . $result
            );
            $this->assertStringStartsWith(
                sys_get_temp_dir() . '/ansilume-ansible-',
                $result,
                'Fallback should live under sys_get_temp_dir() with a deterministic prefix.'
            );
        } finally {
            unlink($dir . '/.ansible');
            rmdir($dir);
        }
    }

    /**
     * The same scenario chmod-0 a real pre-existing cache dir produces in
     * production (non-root worker inherits a root-owned .ansible). Covered
     * only when running as an unprivileged user, since root bypasses mode.
     */
    public function testEnsureCacheDirFallsBackWhenDotAnsibleDirIsNotWritable(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('is_writable() returns true for root regardless of perms.');
        }

        $dir = sys_get_temp_dir() . '/ansilume_lint_cache_ro_' . uniqid('', true);
        mkdir($dir);
        $cache = $dir . '/.ansible';
        mkdir($cache);
        chmod($cache, 0);

        try {
            $svc = new class extends LintService {
                public function callEnsureCacheDir(string $cwd): string
                {
                    return $this->ensureCacheDir($cwd);
                }
            };

            $result = $svc->callEnsureCacheDir($dir);

            $this->assertNotSame(
                $cache,
                $result,
                'ensureCacheDir must not return the CWD-local .ansible when it is not writable.'
            );
            $this->assertStringStartsWith(sys_get_temp_dir() . '/ansilume-ansible-', $result);
        } finally {
            chmod($cache, 0o755);
            rmdir($cache);
            rmdir($dir);
        }
    }

    /**
     * The exact scenario the E2E test caught: a CWD owned by another user
     * with no .ansible yet. mkdir would fail with a PHP warning that Yii
     * promotes to an exception — the defensive code must detect an
     * unwritable parent and skip straight to the fallback.
     */
    public function testEnsureCacheDirFallsBackWhenParentCwdIsNotWritable(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('is_writable() returns true for root regardless of perms.');
        }

        $dir = sys_get_temp_dir() . '/ansilume_lint_cache_parent_' . uniqid('', true);
        mkdir($dir);
        chmod($dir, 0o555); // readable+executable, not writable — parent blocks mkdir

        try {
            $svc = new class extends LintService {
                public function callEnsureCacheDir(string $cwd): string
                {
                    return $this->ensureCacheDir($cwd);
                }
            };

            $result = $svc->callEnsureCacheDir($dir);

            $this->assertStringStartsWith(
                sys_get_temp_dir() . '/ansilume-ansible-',
                $result,
                'ensureCacheDir must fall back without raising when the parent CWD is not writable.'
            );
            $this->assertFileDoesNotExist($dir . '/.ansible', 'No leftover .ansible should have been attempted in the parent.');
        } finally {
            chmod($dir, 0o755);
            rmdir($dir);
        }
    }

    public function testEnsureCacheDirUsesCwdLocalDirWhenWritable(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_cache_rw_' . uniqid('', true);
        mkdir($dir);

        try {
            $svc = new class extends LintService {
                public function callEnsureCacheDir(string $cwd): string
                {
                    return $this->ensureCacheDir($cwd);
                }
            };

            $result = $svc->callEnsureCacheDir($dir);

            $this->assertSame(
                $dir . '/.ansible',
                $result,
                'When CWD is writable ensureCacheDir must return $cwd/.ansible (keeps cache per-project).'
            );
            $this->assertDirectoryExists($result);
            $this->assertTrue(is_writable($result));
        } finally {
            if (is_dir($dir . '/.ansible')) {
                rmdir($dir . '/.ansible');
            }
            rmdir($dir);
        }
    }

    public function testEnsureCacheDirFallbackIsDeterministicPerCwd(): void
    {
        $dir = sys_get_temp_dir() . '/ansilume_lint_cache_det_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/.ansible', '');

        try {
            $svc = new class extends LintService {
                public function callEnsureCacheDir(string $cwd): string
                {
                    return $this->ensureCacheDir($cwd);
                }
            };

            $first  = $svc->callEnsureCacheDir($dir);
            $second = $svc->callEnsureCacheDir($dir);

            $this->assertSame(
                $first,
                $second,
                'Fallback must hash the CWD so repeat calls reuse the same cache dir.'
            );
        } finally {
            unlink($dir . '/.ansible');
            rmdir($dir);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
