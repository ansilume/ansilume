<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\VersionedAssetUrl;
use PHPUnit\Framework\TestCase;

/**
 * The URLs of static files carry a version from their content, so browsers
 * load an updated file at once: nginx serves web/ without Cache-Control, and
 * browsers kept the old Extra Vars editor script for days after an update.
 */
class VersionedAssetUrlTest extends TestCase
{
    private string $webRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->webRoot = sys_get_temp_dir() . '/versioned-asset-url-' . bin2hex(random_bytes(6));
        mkdir($this->webRoot . '/js', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->webRoot . '/js/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->webRoot . '/js');
        rmdir($this->webRoot);
        parent::tearDown();
    }

    public function testTheVersionIsTheStartOfTheContentsSha256(): void
    {
        file_put_contents($this->webRoot . '/js/editor.js', 'console.log("v1");');

        $this->assertSame(
            '/js/editor.js?v=' . substr(hash('sha256', 'console.log("v1");'), 0, VersionedAssetUrl::VERSION_LENGTH),
            VersionedAssetUrl::of('/js/editor.js', $this->webRoot)
        );
    }

    /**
     * An update of the file gives it a new URL, which no browser has cached;
     * the same content keeps its URL, whenever and wherever it was built.
     */
    public function testTheVersionChangesWithTheContentOnly(): void
    {
        $file = $this->webRoot . '/js/editor.js';
        file_put_contents($file, 'console.log("v1");');
        $first = VersionedAssetUrl::of('/js/editor.js', $this->webRoot);
        touch($file, time() + 3600);
        $touched = VersionedAssetUrl::of('/js/editor.js', $this->webRoot);
        file_put_contents($file, 'console.log("v2");');
        $changed = VersionedAssetUrl::of('/js/editor.js', $this->webRoot);

        $this->assertSame($first, $touched);
        $this->assertNotSame($first, $changed);
        $this->assertMatchesRegularExpression('#^/js/editor\.js\?v=[0-9a-f]{12}$#', $changed);
    }

    public function testAMissingFileKeepsItsPath(): void
    {
        $this->assertSame('/js/missing.js', VersionedAssetUrl::of('/js/missing.js', $this->webRoot));
        $this->assertSame('/js', VersionedAssetUrl::of('/js', $this->webRoot), 'a directory is no file');
    }

    /**
     * Without a web root the application's web/ is used: the Extra Vars
     * editor's script and stylesheet get the version of their content.
     */
    public function testTheApplicationsFilesAreVersionedByTheirContent(): void
    {
        $webRoot = dirname(__DIR__, 3) . '/web';
        foreach (['/js/extra-vars-editor.js', '/css/extra-vars-editor.css'] as $path) {
            $hash = hash_file('sha256', $webRoot . $path);
            $this->assertIsString($hash, $path);
            $this->assertSame($path . '?v=' . substr($hash, 0, 12), VersionedAssetUrl::of($path), $path);
        }
    }
}
