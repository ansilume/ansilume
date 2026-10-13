<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\services\JobLaunchInput;
use app\services\JobLaunchRejectedException;
use app\tests\integration\DbTestCase;

/**
 * JobLaunchInput checks the launch fields that go into the job record as
 * they are given, limit and verbosity, with the job record's own rules and
 * as JobLaunchService puts them in: a limit that is not empty as text, a
 * verbosity that is set as a whole number, a list or an object as neither.
 */
class JobLaunchInputTest extends DbTestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, string>}>
     */
    public static function refusedProvider(): array
    {
        return [
            'a verbosity above 5' => [['verbosity' => 6], ['verbosity' => 'Verbosity must be no greater than 5.']],
            'a verbosity below 0' => [['verbosity' => '-2'], ['verbosity' => 'Verbosity must be no less than 0.']],
            'a list as the verbosity' => [['verbosity' => [3]], ['verbosity' => 'Verbosity must be an integer.']],
            'a limit of 256 characters' => [['limit' => str_repeat('l', 256)], ['limit' => 'Limit should contain at most 255 characters.']],
            'an object as the limit' => [['limit' => ['host' => 'web']], ['limit' => 'Limit must be a string.']],
            'both: the limit first' => [
                ['verbosity' => 99, 'limit' => [1], 'extra_vars' => ['a' => 1]],
                ['limit' => 'Limit must be a string.', 'verbosity' => 'Verbosity must be no greater than 5.'],
            ],
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
