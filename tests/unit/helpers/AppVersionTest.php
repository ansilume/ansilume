<?php

declare(strict_types=1);

namespace app\tests\unit\helpers;

use app\helpers\AppVersion;
use PHPUnit\Framework\TestCase;

class AppVersionTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'ansilume_version_');
    }

    protected function tearDown(): void
    {
        AppVersion::withPath(null);
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testCurrentReadsTrimmedFileContents(): void
    {
        file_put_contents($this->tmpFile, "  2.3.15\n");
        AppVersion::withPath($this->tmpFile);

        $this->assertSame('2.3.15', AppVersion::current());
    }

    public function testCurrentReturnsUnknownWhenFileMissing(): void
    {
        AppVersion::withPath('/var/empty/nope-' . uniqid('', true));
        $this->assertSame(AppVersion::UNKNOWN, AppVersion::current());
    }

    public function testCurrentReturnsUnknownWhenFileEmpty(): void
    {
        file_put_contents($this->tmpFile, '');
        AppVersion::withPath($this->tmpFile);
        $this->assertSame(AppVersion::UNKNOWN, AppVersion::current());
    }

    public function testCurrentReturnsUnknownForWhitespaceOnlyFile(): void
    {
        file_put_contents($this->tmpFile, "   \n\t  \n");
        AppVersion::withPath($this->tmpFile);
        $this->assertSame(AppVersion::UNKNOWN, AppVersion::current());
    }

    public function testWithPathNullRestoresDefaultLookup(): void
    {
        AppVersion::withPath('/var/empty/nope');
        $this->assertSame(AppVersion::UNKNOWN, AppVersion::current());

        AppVersion::withPath(null);
        // The repo's VERSION file exists in the test container — the helper
        // should resolve to whatever that contains. We only assert it's
        // non-empty and not the UNKNOWN sentinel; the literal version
        // string changes on every release cut.
        $resolved = AppVersion::current();
        $this->assertNotSame(AppVersion::UNKNOWN, $resolved);
        $this->assertNotSame('', $resolved);
    }
}
