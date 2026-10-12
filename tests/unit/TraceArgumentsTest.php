<?php

declare(strict_types=1);

namespace app\tests\unit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * TraceArguments finds a value wherever an unmarked argument carries it,
 * also where the string form of the trace cannot show it, and fails when
 * the trace records no arguments, so its checks cannot pass for nothing.
 */
class TraceArgumentsTest extends TestCase
{
    private const MARKER = 'trace-arguments-marker-3141';
    private const UNMARKED_FRAME = 'an argument of frame #0, ' . self::class . '::thrownByUnmarked()';

    public function testAMarkedArgumentPasses(): void
    {
        TraceArguments::assertSecretAbsent(self::MARKER, self::thrownByMarked(self::MARKER));
    }

    /**
     * The string form shows the first 15 characters of a text and an array
     * as "Array": the marker never shows there.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function unmarkedArgumentProvider(): array
    {
        return [
            'after the 15th character of a text' => ['{"db_password":"' . self::MARKER . '"}'],
            'a value in a nested array' => [['vars' => ['db_password' => self::MARKER]]],
            'a key in an array' => [[self::MARKER => true]],
        ];
    }

    /**
     * @dataProvider unmarkedArgumentProvider
     */
    public function testAnUnmarkedArgumentFails(mixed $argument): void
    {
        $thrown = self::thrownByUnmarked($argument);
        $this->assertStringNotContainsString(self::MARKER, (string)$thrown, 'the string form does not show it');

        $this->assertCheckFails($thrown, self::UNMARKED_FRAME);
    }

    public function testAnArgumentInTheTraceOfAnEarlierExceptionFails(): void
    {
        $earlier = self::thrownByUnmarked([self::MARKER]);

        $this->assertCheckFails(new \RuntimeException('later', 0, $earlier), self::UNMARKED_FRAME);
    }

    public function testTheMessageFails(): void
    {
        $this->assertCheckFails(new \RuntimeException('Bad ' . self::MARKER), 'the message and the string form');
    }

    /**
     * An object shows as its class name on the error page; it is not
     * entered.
     */
    public function testAnObjectIsNotEntered(): void
    {
        TraceArguments::assertSecretAbsent(self::MARKER, self::thrownByUnmarked((object)['value' => self::MARKER]));
    }

    public function testATraceWithoutArgumentsFails(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '1');
        try {
            $thrown = self::thrownByUnmarked(self::MARKER);
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }

        $this->assertCheckFails($thrown, 'the trace records arguments');
    }

    private function assertCheckFails(\Throwable $thrown, string $expected): void
    {
        try {
            TraceArguments::assertSecretAbsent(self::MARKER, $thrown);
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString($expected, $e->getMessage());

            return;
        }
        $this->fail('the check passed');
    }

    private static function thrownByMarked(#[\SensitiveParameter] mixed $value): \RuntimeException
    {
        return new \RuntimeException(get_debug_type($value));
    }

    private static function thrownByUnmarked(mixed $value): \RuntimeException
    {
        return new \RuntimeException(get_debug_type($value));
    }
}
