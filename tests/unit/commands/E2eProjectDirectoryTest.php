<?php

declare(strict_types=1);

namespace app\tests\unit\commands;

use app\commands\E2eProjectDirectory;
use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * The directory of e2e-project (E2eProjectDirectory): in the runtime
 * directory, which the app and the queue-worker, where lint runs, both see
 * in a git checkout; with the playbook site.yml, written when it is missing,
 * and the owner of the runtime directory when the seed runs as root; the
 * E2E teardown removes it. Each test points @runtime at a directory of its
 * own, so it never touches the fixtures of an E2E run.
 */
final class E2eProjectDirectoryTest extends TestCase
{
    private string $runtime;

    private string $previousRuntime;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir() . '/ansilume_e2e_project_runtime_' . uniqid('', true);
        mkdir($this->runtime);
        $this->previousRuntime = (string)\Yii::getAlias('@runtime');
        \Yii::setAlias('@runtime', $this->runtime);
    }

    protected function tearDown(): void
    {
        \Yii::setAlias('@runtime', $this->previousRuntime);
        FileHelper::removeDirectory($this->runtime);
    }

    public function testCreateWritesThePlaybookIntoTheRuntimeDirectoryOnce(): void
    {
        $path = $this->directory()->create();

        $this->assertSame('@runtime/e2e-project', E2eProjectDirectory::DIRECTORY);
        $this->assertSame($this->runtime . '/e2e-project', $path);
        $this->assertSame(E2eProjectDirectory::PLAYBOOK, file_get_contents($path . '/site.yml'));
        $this->assertStringContainsString('  hosts: localhost', E2eProjectDirectory::PLAYBOOK);

        file_put_contents($path . '/site.yml', "---\n# changed\n");
        $this->assertSame($path, $this->directory()->create());
        $this->assertSame("---\n# changed\n", file_get_contents($path . '/site.yml'), 'a playbook that is there stays');
        $this->assertSame([], $this->log);
    }

    /**
     * Seeded as root, the directory and the playbook get the owner of the
     * runtime directory, here www-data's: the queue-worker's user writes
     * ansible-lint's cache there, and the user of a checkout removes them.
     */
    public function testCreateGivesTheFilesTheOwnerOfTheRuntimeDirectory(): void
    {
        $wwwData = function_exists('posix_getpwnam') ? posix_getpwnam('www-data') : false;
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || $wwwData === false) {
            $this->markTestSkipped('The files get another owner only when the seed runs as root.');
        }
        chown($this->runtime, (int)$wwwData['uid']);
        chgrp($this->runtime, (int)$wwwData['gid']);

        $path = $this->directory()->create();

        foreach ([$path, $path . '/site.yml'] as $file) {
            $this->assertSame((int)$wwwData['uid'], fileowner($file), $file);
            $this->assertSame((int)$wwwData['gid'], filegroup($file), $file);
        }
        $this->assertSame(0o755, fileperms($path) & 0o777);
    }

    public function testRemoveDeletesTheDirectoryAndSaysSoOnce(): void
    {
        $path = $this->directory()->create();

        $this->directory()->remove();
        $this->directory()->remove();

        $this->assertDirectoryDoesNotExist($path);
        $this->assertSame(["  Deleted e2e project directory {$path}.\n"], $this->log);
    }

    private function directory(): E2eProjectDirectory
    {
        return new E2eProjectDirectory(function (string $line): void {
            $this->log[] = $line;
        });
    }
}
