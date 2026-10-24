<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\services\ProjectFilesystemScanner;
use app\tests\unit\RunsUnprivileged;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProjectFilesystemScanner — playbook detection and directory tree.
 */
class ProjectFilesystemScannerTest extends TestCase
{
    use RunsUnprivileged;

    private string $tmpDir;

    /** A directory outside the project, for the symlinks that lead out of it. */
    private string $outside;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ansilume_scanner_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->outside = $this->tmpDir . '-outside';
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        $this->removeDir($this->outside);
    }

    /**
     * Never follows symlinks: a link is removed, not the tree it points at.
     * A directory the tests took the permissions of gets them back first.
     */
    private function removeDir(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) {
            if (is_link($dir) || file_exists($dir)) {
                unlink($dir);
            }
            return;
        }
        chmod($dir, 0755);
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $this->removeDir($dir . '/' . $item);
        }
        rmdir($dir);
    }

    private function playbook(string $relative, int $mode = 0644): void
    {
        $path = $this->tmpDir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, "- hosts: all\n");
        chmod($path, $mode);
    }

    // ── looksLikePlaybook ────────────────────────────────────────────────────

    public function testLooksLikePlaybookReturnsTrueForPlaybook(): void
    {
        $file = $this->tmpDir . '/site.yml';
        file_put_contents($file, "---\n- hosts: all\n  tasks:\n    - debug: msg=hello\n");

        $scanner = new ProjectFilesystemScanner();
        $this->assertTrue($scanner->looksLikePlaybook($file));
    }

    public function testLooksLikePlaybookReturnsFalseForRoleFile(): void
    {
        $file = $this->tmpDir . '/main.yml';
        file_put_contents($file, "---\nsome_var: true\nanother: false\n");

        $scanner = new ProjectFilesystemScanner();
        $this->assertFalse($scanner->looksLikePlaybook($file));
    }

    public function testLooksLikePlaybookReturnsFalseForHiddenFiles(): void
    {
        $file = $this->tmpDir . '/.hidden.yml';
        file_put_contents($file, "---\n- hosts: all\n");

        $scanner = new ProjectFilesystemScanner();
        $this->assertFalse($scanner->looksLikePlaybook($file));
    }

    public function testLooksLikePlaybookExcludesRequirementsYml(): void
    {
        $file = $this->tmpDir . '/requirements.yml';
        file_put_contents($file, "---\n- src: some.role\n");

        $scanner = new ProjectFilesystemScanner();
        $this->assertFalse($scanner->looksLikePlaybook($file));
    }

    public function testLooksLikePlaybookExcludesGalaxyYml(): void
    {
        $file = $this->tmpDir . '/galaxy.yml';
        file_put_contents($file, "---\n- name: collection\n");

        $scanner = new ProjectFilesystemScanner();
        $this->assertFalse($scanner->looksLikePlaybook($file));
    }

    public function testLooksLikePlaybookHandlesCommentsBeforeList(): void
    {
        $file = $this->tmpDir . '/playbook.yml';
        file_put_contents($file, "---\n# This is a comment\n\n- hosts: all\n");

        $scanner = new ProjectFilesystemScanner();
        $this->assertTrue($scanner->looksLikePlaybook($file));
    }

    public function testLooksLikePlaybookReturnsFalseForEmptyFile(): void
    {
        $file = $this->tmpDir . '/empty.yml';
        file_put_contents($file, '');

        $scanner = new ProjectFilesystemScanner();
        $this->assertFalse($scanner->looksLikePlaybook($file));
    }

    // ── detectPlaybooks ──────────────────────────────────────────────────────

    public function testDetectPlaybooksFindsRootLevelPlaybooks(): void
    {
        file_put_contents($this->tmpDir . '/site.yml', "---\n- hosts: all\n");
        file_put_contents($this->tmpDir . '/vars.yml', "---\nsome_var: true\n");

        $scanner = new ProjectFilesystemScanner();
        $result = $scanner->detectPlaybooks($this->tmpDir);

        $this->assertContains('site.yml', $result);
        $this->assertNotContains('vars.yml', $result);
    }

    public function testDetectPlaybooksFindsPlaybooksSubdir(): void
    {
        mkdir($this->tmpDir . '/playbooks', 0755, true);
        file_put_contents($this->tmpDir . '/playbooks/deploy.yaml', "---\n- hosts: web\n");
        file_put_contents($this->tmpDir . '/playbooks/rollback.yml', "---\n- hosts: web\n");

        $scanner = new ProjectFilesystemScanner();
        $result = $scanner->detectPlaybooks($this->tmpDir);

        $this->assertContains('playbooks/deploy.yaml', $result);
        $this->assertContains('playbooks/rollback.yml', $result);
    }

    public function testDetectPlaybooksFindsNestedPlaybooks(): void
    {
        mkdir($this->tmpDir . '/playbooks/infra', 0755, true);
        file_put_contents($this->tmpDir . '/playbooks/infra/setup.yml', "---\n- hosts: all\n");

        $scanner = new ProjectFilesystemScanner();
        $result = $scanner->detectPlaybooks($this->tmpDir);

        $this->assertContains('playbooks/infra/setup.yml', $result);
    }

    public function testDetectPlaybooksExcludesRequirements(): void
    {
        file_put_contents($this->tmpDir . '/requirements.yml', "---\n- src: some.role\n");

        $scanner = new ProjectFilesystemScanner();
        $result = $scanner->detectPlaybooks($this->tmpDir);

        $this->assertEmpty($result);
    }

    public function testDetectPlaybooksReturnsSorted(): void
    {
        file_put_contents($this->tmpDir . '/z_playbook.yml', "---\n- hosts: all\n");
        file_put_contents($this->tmpDir . '/a_playbook.yml', "---\n- hosts: all\n");

        $scanner = new ProjectFilesystemScanner();
        $result = $scanner->detectPlaybooks($this->tmpDir);

        $this->assertSame(['a_playbook.yml', 'z_playbook.yml'], $result);
    }

    public function testDetectPlaybooksReturnsEmptyForEmptyDir(): void
    {
        $scanner = new ProjectFilesystemScanner();
        $this->assertSame([], $scanner->detectPlaybooks($this->tmpDir));
    }

    // ── buildTree ────────────────────────────────────────────────────────────

    public function testBuildTreeReturnsFilesAndDirs(): void
    {
        file_put_contents($this->tmpDir . '/file1.txt', 'x');
        mkdir($this->tmpDir . '/subdir');
        file_put_contents($this->tmpDir . '/subdir/file2.txt', 'y');

        $scanner = new ProjectFilesystemScanner();
        $tree = $scanner->buildTree($this->tmpDir, $this->tmpDir);

        $this->assertSame('subdir', $tree[0]['name']);
        $this->assertSame('dir', $tree[0]['type']);
        $this->assertSame('file1.txt', $tree[1]['name']);
        $this->assertSame('file', $tree[1]['type']);

        $this->assertSame('file2.txt', $tree[0]['children'][0]['name']);
        $this->assertSame('subdir/file2.txt', $tree[0]['children'][0]['rel']);
    }

    public function testBuildTreeHidesHiddenEntries(): void
    {
        file_put_contents($this->tmpDir . '/.hidden', 'x');
        mkdir($this->tmpDir . '/.git');
        file_put_contents($this->tmpDir . '/visible.txt', 'y');

        $scanner = new ProjectFilesystemScanner();
        $tree = $scanner->buildTree($this->tmpDir, $this->tmpDir);

        $names = array_column($tree, 'name');
        $this->assertNotContains('.hidden', $names);
        $this->assertNotContains('.git', $names);
        $this->assertContains('visible.txt', $names);
    }

    public function testBuildTreeRespectsMaxDepth(): void
    {
        mkdir($this->tmpDir . '/a/b/c', 0755, true);
        file_put_contents($this->tmpDir . '/a/b/c/deep.txt', 'x');

        $scanner = new ProjectFilesystemScanner();
        $tree = $scanner->buildTree($this->tmpDir, $this->tmpDir, 0, 2);

        $this->assertSame('a', $tree[0]['name']);
        $this->assertSame('b', $tree[0]['children'][0]['name']);
        $this->assertSame([], $tree[0]['children'][0]['children']);
    }

    public function testBuildTreeSortsDirsBeforeFiles(): void
    {
        file_put_contents($this->tmpDir . '/z_file.txt', 'x');
        mkdir($this->tmpDir . '/a_dir');
        file_put_contents($this->tmpDir . '/a_file.txt', 'x');

        $scanner = new ProjectFilesystemScanner();
        $tree = $scanner->buildTree($this->tmpDir, $this->tmpDir);

        $this->assertSame('dir', $tree[0]['type']);
        $this->assertSame('a_dir', $tree[0]['name']);
        $this->assertSame('file', $tree[1]['type']);
        $this->assertSame('a_file.txt', $tree[1]['name']);
        $this->assertSame('file', $tree[2]['type']);
        $this->assertSame('z_file.txt', $tree[2]['name']);
    }

    public function testBuildTreeReturnsEmptyForEmptyDir(): void
    {
        $scanner = new ProjectFilesystemScanner();
        $this->assertSame([], $scanner->buildTree($this->tmpDir, $this->tmpDir));
    }

    // ── what the web server cannot read ──────────────────────────────────────

    /**
     * Regression: the project page answered 500 when the web server could
     * not list a directory of the project: scandir() warned, and the error
     * handler turned the warning into an exception.
     *
     * @requires extension posix
     */
    public function testRegressionADirectoryTheWebServerMayNotListIsNamed(): void
    {
        mkdir($this->tmpDir . '/private');
        file_put_contents($this->tmpDir . '/private/hosts', 'x');
        chmod($this->tmpDir . '/private', 0o000);
        $this->playbook('site.yml');

        [$playbooks, $tree, $unreadable] = $this->scanUnprivileged();

        $this->assertSame(['site.yml'], $playbooks);
        $this->assertSame([['private', 'dir', []], ['site.yml', 'file', []]], self::shape($tree));
        $this->assertSame(['private'], $unreadable);
    }

    /**
     * @requires extension posix
     */
    public function testAProjectDirectoryTheWebServerMayNotListIsNamedAsDot(): void
    {
        $this->playbook('site.yml');
        chmod($this->tmpDir, 0o000);

        [$playbooks, $tree, $unreadable] = $this->scanUnprivileged();

        $this->assertSame([], $playbooks);
        $this->assertSame([], $tree);
        $this->assertSame(['.'], $unreadable);
    }

    /**
     * Regression: a playbook the web server may not read made
     * file_get_contents() warn, and the project page answered 500. The other
     * playbooks are still found.
     *
     * @requires extension posix
     */
    public function testRegressionAPlaybookTheWebServerMayNotReadIsNamed(): void
    {
        $this->playbook('site.yml');
        $this->playbook('secret.yml', 0o000);
        $this->playbook('playbooks/deploy.yml');
        $this->playbook('playbooks/private.yml', 0o000);

        [$playbooks, , $unreadable] = $this->scanUnprivileged();

        $this->assertSame(['playbooks/deploy.yml', 'site.yml'], $playbooks);
        $this->assertSame(['playbooks/private.yml', 'secret.yml'], $unreadable);
    }

    /**
     * Regression: RecursiveDirectoryIterator threw for a directory below
     * playbooks/ that the web server may not list, and the project page
     * answered 500. The playbooks beside it are still found.
     *
     * @requires extension posix
     */
    public function testRegressionADirectoryBelowPlaybooksTheWebServerMayNotListIsNamed(): void
    {
        $this->playbook('playbooks/deploy.yml');
        $this->playbook('playbooks/locked/secret.yml');
        chmod($this->tmpDir . '/playbooks/locked', 0o000);

        [$playbooks, $tree, $unreadable] = $this->scanUnprivileged();

        $this->assertSame(['playbooks/deploy.yml'], $playbooks);
        $this->assertSame([['playbooks', 'dir', [['locked', 'dir', []], ['deploy.yml', 'file', []]]]], self::shape($tree));
        $this->assertSame(['playbooks/locked'], $unreadable);
    }

    /**
     * A directory the web server may list but not enter: its entries are
     * listed, but cannot be examined or read, as with the project directory
     * of the E2E fixture E2eLintLockedProjectSeeder.
     *
     * @requires extension posix
     */
    public function testTheEntriesOfADirectoryTheWebServerMayListButNotEnterAreNamed(): void
    {
        $this->playbook('playbooks/deploy.yml');
        $this->playbook('site.yml');
        chmod($this->tmpDir . '/playbooks', 0o644);

        [$playbooks, $tree, $unreadable] = $this->scanUnprivileged();

        $this->assertSame(['site.yml'], $playbooks);
        $this->assertSame([['playbooks', 'dir', [['deploy.yml', 'file', []]]], ['site.yml', 'file', []]], self::shape($tree));
        $this->assertSame(['playbooks/deploy.yml'], $unreadable);
    }

    /**
     * Review finding: an entry that a sync, or a git pull in a manual
     * project's directory, removed after the scan listed it was named as one
     * the web server cannot read, and the project page asked for permissions
     * nothing lacked. It is left out. The scanner here lists names that are
     * gone, as a listing made just before their removal does; #[\Override]
     * fails the test when the scanner's entries() is no longer there to
     * override, which would make it pass without testing anything.
     */
    public function testAnEntryRemovedAfterItWasListedIsLeftOut(): void
    {
        $this->playbook('site.yml');
        $this->playbook('playbooks/deploy.yml');
        $scanner = new class () extends ProjectFilesystemScanner {
            #[\Override]
            protected function entries(string $dir): ?array
            {
                $names = parent::entries($dir);

                return $names === null ? null : [...$names, 'removed', 'removed.yml'];
            }
        };

        $playbooks = $scanner->detectPlaybooks($this->tmpDir);
        $tree = $scanner->buildTree($this->tmpDir, $this->tmpDir);

        $this->assertSame(['playbooks/deploy.yml', 'site.yml'], $playbooks);
        $this->assertSame([['playbooks', 'dir', [['deploy.yml', 'file', []]]], ['site.yml', 'file', []]], self::shape($tree));
        $this->assertSame([], $scanner->unreadable($this->tmpDir));
    }

    /**
     * A directory removed after it was listed, before it is listed itself,
     * is not named either.
     */
    public function testADirectoryRemovedBeforeItIsListedIsNotNamed(): void
    {
        $this->playbook('playbooks/deploy.yml');
        mkdir($this->tmpDir . '/roles');
        $scanner = new class () extends ProjectFilesystemScanner {
            #[\Override]
            protected function entries(string $dir): ?array
            {
                if (basename($dir) === 'roles') {
                    rmdir($dir);
                }

                return parent::entries($dir);
            }
        };

        $tree = $scanner->buildTree($this->tmpDir, $this->tmpDir);

        $this->assertSame([['playbooks', 'dir', [['deploy.yml', 'file', []]]], ['roles', 'dir', []]], self::shape($tree));
        $this->assertSame([], $scanner->unreadable($this->tmpDir));
    }

    /**
     * Review finding: a playbook that git replaced while the scan read it was
     * named as one the web server cannot read, as git removes a changed file
     * and then writes it anew: a git pull in a manual project's directory put
     * a false note on the page. In a directory that can be entered, an entry
     * that cannot be examined was absent at that moment: it is examined once
     * more, and read once it is back.
     */
    public function testAPlaybookGitRewritesWhileTheScanReadsItIsReadNotNamed(): void
    {
        $base = ReplacedFileWrapper::PROTOCOL . '://project';
        $scan = static fn (callable $read): array => ReplacedFileWrapper::with(ReplacedFileWrapper::REWRITTEN, static function () use ($read, $base): array {
            $scanner = new ProjectFilesystemScanner();

            return [$read($scanner), $scanner->unreadable($base)];
        });

        $playbooks = $scan(static fn (ProjectFilesystemScanner $scanner): array => $scanner->detectPlaybooks($base));
        $tree = $scan(static fn (ProjectFilesystemScanner $scanner): array => self::shape($scanner->buildTree($base, $base)));

        $this->assertSame([['playbooks/site.yml'], []], $playbooks);
        $this->assertSame([[['playbooks', 'dir', [['site.yml', 'file', []]]]], []], $tree);
    }

    /**
     * A playbook whose first open fails, as one does in git's gap between
     * the removal and the new file, is opened once more and read.
     */
    public function testAPlaybookThatOpensOnlyTheSecondTimeIsRead(): void
    {
        $base = ReplacedFileWrapper::PROTOCOL . '://project';

        [$playbooks, $unreadable] = ReplacedFileWrapper::with(ReplacedFileWrapper::REOPENED, static function () use ($base): array {
            $scanner = new ProjectFilesystemScanner();

            return [$scanner->detectPlaybooks($base), $scanner->unreadable($base)];
        });

        $this->assertSame(['playbooks/site.yml'], $playbooks);
        $this->assertSame([], $unreadable);
    }

    /**
     * A playbook removed between the check that it is a file and its read is
     * not named (VanishingFileWrapper removes it as it is opened).
     */
    public function testAPlaybookRemovedBeforeItIsReadIsNotNamed(): void
    {
        $base = VanishingFileWrapper::PROTOCOL . '://project';
        $scanner = new ProjectFilesystemScanner();

        $looksLikePlaybook = VanishingFileWrapper::with(static fn (): bool => $scanner->looksLikePlaybook($base . '/site.yml'));

        $this->assertFalse($looksLikePlaybook);
        $this->assertSame([], $scanner->unreadable($base));
    }

    /**
     * Regression: a directory named like a playbook was read as one, and
     * file_get_contents() raised "Is a directory", which the project page
     * answered with 500.
     */
    public function testRegressionADirectoryNamedLikeAPlaybookIsNoPlaybook(): void
    {
        mkdir($this->tmpDir . '/site.yml');
        $this->playbook('deploy.yml');

        $scanner = new ProjectFilesystemScanner();

        $this->assertSame(['deploy.yml'], $scanner->detectPlaybooks($this->tmpDir));
        $this->assertFalse($scanner->looksLikePlaybook($this->tmpDir . '/site.yml'));
        $this->assertSame([], $scanner->unreadable($this->tmpDir));
    }

    /**
     * Regression: a symlink named like a playbook whose target is gone made
     * file_get_contents() warn, and the project page answered 500.
     */
    public function testRegressionADanglingSymlinkNamedLikeAPlaybookIsNoPlaybook(): void
    {
        symlink($this->tmpDir . '/gone.yml', $this->tmpDir . '/site.yml');

        $scanner = new ProjectFilesystemScanner();

        $this->assertSame([], $scanner->detectPlaybooks($this->tmpDir));
        $this->assertSame([['site.yml', 'link', []]], self::shape($scanner->buildTree($this->tmpDir, $this->tmpDir)));
        $this->assertSame([], $scanner->unreadable($this->tmpDir));
    }

    /**
     * Regression: the tree followed a symlink to a directory outside the
     * project, so a repository with a link to /etc made the project page list
     * the server's directories, and answer 500 at the first one the web
     * server may not list. A symlink is a link node now, never followed.
     *
     * @requires extension posix
     */
    public function testRegressionASymlinkToADirectoryOutsideTheProjectIsNotFollowed(): void
    {
        mkdir($this->outside . '/private', 0755, true);
        file_put_contents($this->outside . '/passwd', 'x');
        chmod($this->outside . '/private', 0o000);
        symlink($this->outside, $this->tmpDir . '/etc');

        [$playbooks, $tree, $unreadable] = $this->scanUnprivileged();

        $this->assertSame([], $playbooks);
        $this->assertSame([['etc', 'link', []]], self::shape($tree));
        $this->assertSame([], $unreadable);
    }

    /**
     * A symlink to a directory of the project is not followed either: a link
     * to the project directory itself was expanded at every level.
     */
    public function testASymlinkToTheProjectDirectoryIsShownOnce(): void
    {
        symlink($this->tmpDir, $this->tmpDir . '/loop');
        $this->playbook('site.yml');

        $tree = (new ProjectFilesystemScanner())->buildTree($this->tmpDir, $this->tmpDir);

        $this->assertSame([['loop', 'link', []], ['site.yml', 'file', []]], self::shape($tree));
    }

    public function testASymlinkedPlaybookInsideTheProjectIsDetected(): void
    {
        $this->playbook('playbooks/site.yml');
        symlink($this->tmpDir . '/playbooks/site.yml', $this->tmpDir . '/site.yml');

        $this->assertSame(['playbooks/site.yml', 'site.yml'], (new ProjectFilesystemScanner())->detectPlaybooks($this->tmpDir));
    }

    /**
     * A playbook symlink that leads out of the project is not read, nor is a
     * playbooks/ directory that is a symlink.
     */
    public function testSymlinksOutOfTheProjectAreNotRead(): void
    {
        mkdir($this->outside, 0755);
        file_put_contents($this->outside . '/site.yml', "- hosts: all\n");
        symlink($this->outside . '/site.yml', $this->tmpDir . '/site.yml');
        symlink($this->outside, $this->tmpDir . '/playbooks');

        $scanner = new ProjectFilesystemScanner();

        $this->assertSame([], $scanner->detectPlaybooks($this->tmpDir));
        $this->assertSame([], $scanner->unreadable($this->tmpDir));
    }

    /**
     * Below playbooks/, a symlinked directory is not entered.
     */
    public function testASymlinkedDirectoryBelowPlaybooksIsNotEntered(): void
    {
        $this->playbook('shared/site.yml');
        $this->playbook('playbooks/deploy.yml');
        symlink($this->tmpDir . '/shared', $this->tmpDir . '/playbooks/shared');

        $this->assertSame(['playbooks/deploy.yml'], (new ProjectFilesystemScanner())->detectPlaybooks($this->tmpDir));
    }

    public function testBuildTreeSortsDirectoriesFirstThenLinksAndFilesByName(): void
    {
        file_put_contents($this->tmpDir . '/c.txt', 'x');
        symlink($this->tmpDir . '/c.txt', $this->tmpDir . '/b-link');
        mkdir($this->tmpDir . '/z-dir');
        file_put_contents($this->tmpDir . '/a.txt', 'x');
        symlink($this->tmpDir . '/a.txt', $this->tmpDir . '/d-link');

        $tree = (new ProjectFilesystemScanner())->buildTree($this->tmpDir, $this->tmpDir);

        $this->assertSame(
            [['z-dir', 'dir', []], ['a.txt', 'file', []], ['b-link', 'link', []], ['c.txt', 'file', []], ['d-link', 'link', []]],
            self::shape($tree)
        );
    }

    /**
     * Both scans of one scanner name a path once; unreadable() names them
     * relative to the directory it is given.
     *
     * @requires extension posix
     */
    public function testAPathBothScansCannotReadIsNamedOnce(): void
    {
        $this->playbook('playbooks/locked/a.yml');
        $this->playbook('locked.yml', 0o000);
        chmod($this->tmpDir . '/playbooks/locked', 0o000);

        [, , $unreadable] = $this->scanUnprivileged();

        $this->assertSame(['locked.yml', 'playbooks/locked'], $unreadable);
    }

    /**
     * Scans the project directory as the web server would, with a user who
     * may not read what the tests deny to others.
     *
     * @return array{0: string[], 1: array<int, array{name: string, rel: string, type: string, children: array<int, mixed>}>, 2: list<string>}
     */
    private function scanUnprivileged(): array
    {
        return self::unprivileged(function (): array {
            $scanner = new ProjectFilesystemScanner();
            $playbooks = $scanner->detectPlaybooks($this->tmpDir);
            $tree = $scanner->buildTree($this->tmpDir, $this->tmpDir);

            return [$playbooks, $tree, $scanner->unreadable($this->tmpDir)];
        });
    }

    /**
     * The tree as [name, type, children] lists, the paths left out.
     *
     * @param array<int, array{name: string, rel: string, type: string, children: array<int, mixed>}> $tree
     * @return list<array{0: string, 1: string, 2: list<mixed>}>
     */
    private static function shape(array $tree): array
    {
        return array_values(array_map(
            static fn (array $node): array => [$node['name'], $node['type'], self::shape($node['children'])],
            $tree
        ));
    }
}
