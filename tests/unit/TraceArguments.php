<?php

declare(strict_types=1);

namespace app\tests\unit;

use PHPUnit\Framework\Assert;

/**
 * Shows that a value stays out of an exception: out of its message and
 * string form, and out of every argument its trace recorded.
 *
 * The string form alone shows little of an argument: PHP prints the first
 * 15 characters of a text (zend.exception_string_param_max_len) and an
 * array as "Array". A value inside an array, or after the 15th character
 * of a text, never shows there, marked or not. Yii's debug error page
 * shows the trace's arguments themselves: texts in full, arrays entry by
 * entry with their keys, an object as its class name. So every text in the
 * arguments is checked, in arrays at any depth, keys included; objects are
 * not entered, and a #[\SensitiveParameter] argument is such an object
 * (SensitiveParameterValue), whose value is never read here.
 *
 * The trace must record arguments: with zend.exception_ignore_args = On, as
 * in the images, it records none, and every check would pass whatever the
 * code does. phpunit.xml turns it off for the tests
 * (tests/unit/config/PhpunitTraceArgumentsTest.php checks that), and the
 * assertion fails when a call in the trace recorded no arguments.
 */
final class TraceArguments
{
    /**
     * The frames of include, require and eval: PHP gives an include or
     * require frame the file name as its argument even with
     * zend.exception_ignore_args = On, and an eval frame none.
     */
    private const NOT_CALLS = ['include', 'include_once', 'require', 'require_once', 'eval'];

    private const ARGUMENTS_OFF = 'zend.exception_ignore_args must be 0, as phpunit.xml sets it';

    /**
     * Asserts that $secret is in no message, string form or trace argument
     * of $thrown and the exceptions before it (getPrevious()).
     */
    public static function assertSecretAbsent(string $secret, \Throwable $thrown): void
    {
        Assert::assertStringNotContainsString($secret, (string)$thrown, 'the message and the string form');
        for ($current = $thrown; $current !== null; $current = $current->getPrevious()) {
            $calls = array_filter(
                $current->getTrace(),
                static fn (array $frame): bool => !in_array($frame['function'], self::NOT_CALLS, true)
            );
            Assert::assertNotSame([], $calls, 'the trace holds a function call');
            foreach ($calls as $index => $frame) {
                $function = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];
                $where = "frame #{$index}, {$function}()";
                $recorded = "the trace records arguments ({$where}): " . self::ARGUMENTS_OFF;
                Assert::assertArrayHasKey('args', $frame, $recorded);
                foreach (self::texts($frame['args'] ?? []) as $text) {
                    Assert::assertStringNotContainsString($secret, $text, "an argument of {$where}");
                }
            }
        }
    }

    /**
     * Every text in $values, in arrays at any depth, keys included.
     *
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function texts(array $values): array
    {
        $texts = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $texts[] = $key;
            }
            if (is_string($value)) {
                $texts[] = $value;
            } elseif (is_array($value)) {
                array_push($texts, ...self::texts($value));
            }
        }

        return $texts;
    }
}
