<?php

declare(strict_types=1);

namespace app\tests\unit\helpers;

use app\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

class FileHelperTest extends TestCase
{
    private string $dir;

    /** A directory outside $dir that symlinks in $dir point at. */
    private string $target;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ansilume_filehelper_' . uniqid('', true);
        $this->target = $this->dir . '-target';
        mkdir($this->dir . '/sub', 0755, true);
        mkdir($this->target, 0755);
        file_put_contents($this->target . '/keep.txt', 'keep');
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir . '/sub/link', $this->dir . '/link', $this->dir . '/sub/file.txt'] as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }
        foreach ([$this->dir . '/sub', $this->dir] as $path) {
            if (is_dir($path) && !is_link($path)) {
                rmdir($path);
            }
        }
        if (is_file($this->target . '/keep.txt')) {
            unlink($this->target . '/keep.txt');
        }
        rmdir($this->target);
    }

    public function testRemoveDirectoryRemovesTheTree(): void
    {
        file_put_contents($this->dir . '/sub/file.txt', 'x');

        $this->assertTrue(FileHelper::removeDirectory($this->dir));
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    /**
     * Regression: removeDirectory() followed a symlink to a directory and
     * removed what that directory held, as the E2E seeders do with the
     * checkouts they create again: a project fixture with a link to /etc
     * would have emptied /etc.
     */
    public function testRegressionRemoveDirectoryRemovesALinkNotTheDirectoryItPointsAt(): void
    {
        symlink($this->target, $this->dir . '/sub/link');

        $this->assertTrue(FileHelper::removeDirectory($this->dir));

        $this->assertDirectoryDoesNotExist($this->dir);
        $this->assertFileExists($this->target . '/keep.txt');
    }

    /**
     * Given a symlink itself, it removes the link and leaves its target.
     */
    public function testRemoveDirectoryOfASymlinkRemovesTheLink(): void
    {
        symlink($this->target, $this->dir . '/link');

        $this->assertTrue(FileHelper::removeDirectory($this->dir . '/link'));

        $this->assertFalse(is_link($this->dir . '/link'));
        $this->assertFileExists($this->target . '/keep.txt');
    }

    public function testRemoveDirectoryOfAPathThatDoesNotExistSucceeds(): void
    {
        $this->assertTrue(FileHelper::removeDirectory($this->dir . '/missing'));
    }
}
