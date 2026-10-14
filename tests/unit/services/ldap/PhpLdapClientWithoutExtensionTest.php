<?php

declare(strict_types=1);

namespace app\tests\unit\services\ldap;

use app\services\ldap\LdapConfig;
use app\services\ldap\LdapException;
use app\services\ldap\PhpLdapClient;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * PhpLdapClient on a PHP without the ldap extension, as the released app
 * image was up to 2.10.1. The test PHP loads the extension, so the test runs
 * in a PHP process of its own, which PHPUnit starts with this process's
 * environment: PHP_INI_SCAN_DIR names a copy of the ini files this process
 * read, without those that load ldap.
 */
final class PhpLdapClientWithoutExtensionTest extends TestCase
{
    private static ?string $scanDir = null;

    private static string|false $previousScanDir = false;

    /**
     * Runs in this process before PHPUnit starts the test's process, and
     * again in that process, which has no ldap extension and skips it.
     */
    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('ldap') || self::$scanDir !== null) {
            return;
        }
        $dir = sys_get_temp_dir() . '/ansilume-without-ldap-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        foreach (explode(',', (string)php_ini_scanned_files()) as $ini) {
            $ini = trim($ini);
            if ($ini !== '' && preg_match('/^\s*extension\s*=\s*"?ldap(\.so)?"?\s*$/mi', (string)file_get_contents($ini)) !== 1) {
                copy($ini, $dir . '/' . basename($ini));
            }
        }
        self::$previousScanDir = getenv('PHP_INI_SCAN_DIR');
        putenv('PHP_INI_SCAN_DIR=' . $dir);
        self::$scanDir = $dir;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$scanDir === null) {
            return;
        }
        putenv(self::$previousScanDir === false ? 'PHP_INI_SCAN_DIR' : 'PHP_INI_SCAN_DIR=' . self::$previousScanDir);
        array_map('unlink', glob(self::$scanDir . '/*.ini') ?: []);
        rmdir(self::$scanDir);
        self::$scanDir = null;
    }

    #[RunInSeparateProcess]
    public function testWithoutTheExtensionTheClientCannotBeBuilt(): void
    {
        $this->assertFalse(extension_loaded('ldap'), 'the test process runs without the ldap extension');

        $this->expectException(LdapException::class);
        $this->expectExceptionMessage(PhpLdapClient::EXTENSION_NOT_LOADED);

        new PhpLdapClient(LdapConfig::fromArray([]));
    }
}
