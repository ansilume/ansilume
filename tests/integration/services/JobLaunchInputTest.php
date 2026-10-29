<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\services\JobLaunchInput;
use app\services\JobLaunchRejectedException;
use app\tests\integration\DbTestCase;

/**
 * JobLaunchInput checks the launch fields that go into the job record as
 * they are given, limit and verbosity, with the job record's own rules and
 * as JobLaunchService puts them in: a limit that is not empty as text, a
 * verbosity that is set as the record's integer rule reads it, a whole
 * number as an int and anything else as it is, for the rule to refuse; a
 * list or an object as neither, also an empty one, which the rules skip.
 *
 * Regression: the verbosity was cast with (int) before the check. A JSON
 * number beyond the int range, which the request's JSON parser gives as a
 * float, made the cast warn on PHP 8.5 ("The float 1.0E+30 is not
 * representable as an int, cast occurred"), and the error handler turned
 * the warning into an exception; up to PHP 8.4 the cast wrapped it, and
 * infinity became 0. A fraction, text that is no number and a boolean were
 * cast to a whole number and taken.
 *
 * Regression: an empty list or object ([] or {}) as the limit or the
 * verbosity had no errors: the record's rules skip an empty value, and the
 * launch took it for none, so POST /api/v1/jobs and a job trigger launched
 * the job with the template's limit and verbosity (2.13.1 ran it at
 * verbosity 0).
 */
class JobLaunchInputTest extends DbTestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, string>}>
     */
    public static function refusedProvider(): array
    {
        $notAnInteger = ['verbosity' => 'Verbosity must be an integer.'];
        $notAString = ['limit' => 'Limit must be a string.'];
        // As the request's JSON parser gives them: floats, 1e999 infinity.
        $beyondTheIntRange = json_decode('[1e30, 9223372036854775808, 1e999, -1e30, -1e999]', true);

        return [
            'a verbosity above 5' => [['verbosity' => 6], ['verbosity' => 'Verbosity must be no greater than 5.']],
            'a verbosity below 0' => [['verbosity' => '-2'], ['verbosity' => 'Verbosity must be no less than 0.']],
            'a list as the verbosity' => [['verbosity' => [3]], $notAnInteger],
            'the JSON number 1e30' => [['verbosity' => $beyondTheIntRange[0]], $notAnInteger],
            'the JSON number 9223372036854775808' => [['verbosity' => $beyondTheIntRange[1]], $notAnInteger],
            'the JSON number 1e999, infinity' => [['verbosity' => $beyondTheIntRange[2]], $notAnInteger],
            'the JSON number -1e30' => [['verbosity' => $beyondTheIntRange[3]], $notAnInteger],
            'the JSON number -1e999, minus infinity' => [['verbosity' => $beyondTheIntRange[4]], $notAnInteger],
            '1e30 as text' => [['verbosity' => '1e30'], $notAnInteger],
            'a fraction' => [['verbosity' => 2.5], $notAnInteger],
            'a fraction as text' => [['verbosity' => '2.5'], $notAnInteger],
            'text that is no number' => [['verbosity' => 'abc'], $notAnInteger],
            'true' => [['verbosity' => true], $notAnInteger],
            'false' => [['verbosity' => false], $notAnInteger],
            'a number with a space before it' => [['verbosity' => ' 2'], $notAnInteger],
            // Text is no float: a digit string beyond the int range reads as PHP_INT_MAX, as before.
            'digits beyond the int range as text' => [
                ['verbosity' => '9223372036854775808'],
                ['verbosity' => 'Verbosity must be no greater than 5.'],
            ],
            'a limit of 256 characters' => [['limit' => str_repeat('l', 256)], ['limit' => 'Limit should contain at most 255 characters.']],
            'an object as the limit' => [['limit' => ['host' => 'web']], ['limit' => 'Limit must be a string.']],
            'both: the limit first' => [
                ['verbosity' => 99, 'limit' => [1], 'extra_vars' => ['a' => 1]],
                ['limit' => 'Limit must be a string.', 'verbosity' => 'Verbosity must be no greater than 5.'],
            ],
            // Regression: each of these had no errors, as the record's rules skip an empty value.
            'an empty list as the verbosity' => [['verbosity' => []], $notAnInteger],
            // The request's JSON parser and a job trigger's json_decode() give {} as an empty list.
            'an empty object as the verbosity' => [json_decode('{"verbosity": {}}', true), $notAnInteger],
            'an empty list as the limit' => [['limit' => []], $notAString],
            'an empty object as the limit' => [json_decode('{"limit": {}}', true), $notAString],
            'an empty list as both: the limit first' => [['verbosity' => [], 'limit' => []], $notAString + $notAnInteger],
            // As a form body posts overrides[verbosity][]= and overrides[limit][]=.
            'a list of one empty text as the verbosity' => [['verbosity' => ['']], $notAnInteger],
            'a list of one empty text as the limit' => [['limit' => ['']], $notAString],
        ];
    }

    /**
     * @dataProvider refusedProvider
     * @param array<string, mixed> $overrides
     * @param array<string, string> $errors
     */
    public function testInputTheJobRecordRefusesHasItsErrors(array $overrides, array $errors): void
    {
        $this->assertSame($errors, JobLaunchInput::errors($overrides));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function takenProvider(): array
    {
        return [
            'nothing' => [[]],
            'only extra vars and check mode' => [['extra_vars' => '{"a": 1}', 'check_mode' => 'yes']],
            'an empty limit, not given' => [['limit' => '']],
            'no verbosity' => [['verbosity' => null]],
            'a limit of 255 characters' => [['limit' => str_repeat('l', 255)]],
            'a number as the limit, as text' => [['limit' => 12]],
            'verbosity 0 with a limit' => [['verbosity' => 0, 'limit' => 'web']],
            'verbosity 5 as text' => [['verbosity' => '5']],
            'verbosity 5 as the JSON number 5.0' => [['verbosity' => 5.0]],
            'verbosity 3 as text with a sign and a zero' => [['verbosity' => '+03']],
            'an empty verbosity, none' => [['verbosity' => '']],
            'no limit' => [['limit' => null]],
            'an empty limit and an empty verbosity, none' => [['limit' => '', 'verbosity' => '']],
        ];
    }

    /**
     * @dataProvider takenProvider
     * @param array<string, mixed> $overrides
     */
    public function testInputTheJobRecordTakesHasNoErrors(array $overrides): void
    {
        $this->assertSame([], JobLaunchInput::errors($overrides));
        JobLaunchInput::check($overrides);
    }

    /**
     * An empty list or object is refused with the message the job record's
     * rule gives any list: an empty one is no limit and no verbosity
     * either, although the rules skip it as they skip every empty value.
     */
    public function testAnEmptyListHasTheMessageTheJobRecordGivesAList(): void
    {
        $job = new Job();
        $job->setAttributes(['limit' => ['web'], 'verbosity' => [3]], false);
        $this->assertFalse($job->validate(['limit', 'verbosity']));

        $this->assertSame(
            ['limit' => $job->getFirstError('limit'), 'verbosity' => $job->getFirstError('verbosity')],
            JobLaunchInput::errors(['limit' => [], 'verbosity' => []])
        );
    }

    public function testTheRefusalIsTheErrorsInTheirOrder(): void
    {
        $this->assertSame(
            'Limit must be a string. Verbosity must be no greater than 5.',
            JobLaunchInput::refusal(['limit' => 'Limit must be a string.', 'verbosity' => 'Verbosity must be no greater than 5.'])
        );
        $this->assertSame('', JobLaunchInput::refusal([]));
    }

    public function testCheckRefusesWithTheRefusal(): void
    {
        try {
            JobLaunchInput::check(['limit' => str_repeat('l', 300), 'verbosity' => -1]);
            $this->fail('expected a JobLaunchRejectedException');
        } catch (JobLaunchRejectedException $e) {
            $this->assertSame('Limit should contain at most 255 characters. Verbosity must be no less than 0.', $e->getMessage());
        }
    }
}
