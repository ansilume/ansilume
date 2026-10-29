<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;

/**
 * PHP-FPM of the app image tracks no upload progress:
 * session.upload_progress.enabled = Off.
 *
 * Regression: neither ini file set it, and PHP's default is On. PHP's own
 * session handler then wrote a session file to /tmp for every multipart
 * request that named the upload progress field, a signed-out one too,
 * before Ansilume's code ran: the way into CVE-2026-115560, which PHP has
 * not fixed yet. Ansilume keeps its sessions in Redis and shows no upload
 * progress, so turning it off changes nothing else.
 *
 * Which services load these files, ExceptionIgnoreArgsTest pins. The runner
 * image's own ini (docker/runner/php.ini) needs no such line: the runner
 * runs PHP's CLI, which reads no request body.
 */
class SessionUploadProgressTest extends TestCase
{
    use RendersDeployRole;

    private const SETTING = 'session.upload_progress.enabled';

    public function testTheSettingIsOneOfPhp(): void
    {
        $this->assertArrayHasKey(self::SETTING, ini_get_all('session'));
    }

    public function testTheServerIniTurnsItOff(): void
    {
        $file = dirname(__DIR__, 3) . '/docker/php/php.ini';
        $this->assertFileExists($file);
        $this->assertTurnsItOff((string)file_get_contents($file), 'docker/php/php.ini');
    }

    public function testTheDeployRolesIniTurnsItOff(): void
    {
        $this->assertTurnsItOff($this->renderDeployFile('php.ini'), 'deploy/roles/ansilume/templates/php.ini');
    }

    private function assertTurnsItOff(string $ini, string $file): void
    {
        // PHP's normal ini scanner, as for php.ini: Off, no, false and none read as "", 0 as "0".
        $parsed = parse_ini_string($ini);
        $this->assertIsArray($parsed, $file . ' parses');
        $this->assertArrayHasKey(self::SETTING, $parsed, $file . ' sets ' . self::SETTING);
        $this->assertContains($parsed[self::SETTING], ['', '0'], $file . ' sets ' . self::SETTING . ' = Off');
    }
}
