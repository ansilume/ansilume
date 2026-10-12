<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use PHPUnit\Framework\TestCase;

/**
 * The tests see trace arguments as a host without
 * zend.exception_ignore_args does: phpunit.xml sets it to 0.
 *
 * The images set it On (docker/php/php.ini, docker/runner/php.ini, see
 * tests/unit/docker/ExceptionIgnoreArgsTest.php), and the dev containers and
 * CI run the tests with those ini files. With it On, PHP records no argument
 * in any trace, so every test that shows a value stays out of a trace
 * passes whatever the code does.
 *
 * Regression (fix round 4): once the images set it On, every such test
 * passed even without the #[\SensitiveParameter] mark it should prove,
 * except the few that turned the setting off themselves.
 */
class PhpunitTraceArgumentsTest extends TestCase
{
    private const SETTING = 'zend.exception_ignore_args';

    public function testTheTestRunRecordsTraceArguments(): void
    {
        $this->assertSame('0', ini_get(self::SETTING), 'phpunit.xml sets ' . self::SETTING . ' to 0');
        $this->assertStringContainsString("throwWith('guard-4711')", self::traceOf('guard-4711'));
    }

    public function testPhpunitXmlSetsIt(): void
    {
        $file = dirname(__DIR__, 3) . '/phpunit.xml';
        $this->assertFileExists($file);
        $xml = simplexml_load_file($file);
        $this->assertNotFalse($xml, 'phpunit.xml parses');

        $values = $xml->xpath('/phpunit/php/ini[@name="' . self::SETTING . '"]/@value');
        $this->assertIsArray($values);
        $this->assertSame(['0'], array_map('strval', $values));
    }

    private static function traceOf(string $marker): string
    {
        try {
            self::throwWith($marker);
        } catch (\RuntimeException $e) {
            return $e->getTraceAsString();
        }
    }

    /**
     * @throws \RuntimeException always, with $marker as a plain argument in its trace
     */
    private static function throwWith(string $marker): never
    {
        throw new \RuntimeException('A plain argument of ' . strlen($marker) . ' characters.');
    }
}
