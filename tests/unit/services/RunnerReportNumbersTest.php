<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Job;
use app\services\RunnerReportNumbers;
use PHPUnit\Framework\TestCase;

/**
 * RunnerReportNumbers reads the whole numbers of a runner's reports (the
 * sequence of a log chunk, the exit code of a completion, the seq and
 * duration_ms of a task result) as the model's integer rule reads them, and
 * only in the range of what stores them. The tests run under the
 * application's error handler, which turns a warning into an exception, so a
 * warning here fails them.
 *
 * Regression: the runner API cast such numbers with (int), and a JSON number
 * beyond the int range (1e30, 9223372036854775808, 1e999 for infinity)
 * answered 500 on PHP 8.5; a whole number beyond the column's range failed
 * the insert or update on every version.
 */
class RunnerReportNumbersTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed}>
     */
    public static function noWholeNumberProvider(): array
    {
        $json = json_decode('[1e30, -1e30, 9223372036854775808, 1e999, -1e999]', true);
        if (!is_array($json)) {
            throw new \LogicException('The JSON text of the provider is valid.');
        }

        return [
            'JSON 1e30' => [$json[0]],
            'JSON -1e30' => [$json[1]],
            'JSON 9223372036854775808, one more than the largest int' => [$json[2]],
            'JSON 1e999, infinity' => [$json[3]],
            'JSON -1e999, negative infinity' => [$json[4]],
            'fraction' => [1.5],
            'decimal text' => ['1.5'],
            'exponent text' => ['1e3'],
            'text' => ['abc'],
            'empty text' => [''],
            'null' => [null],
            'true' => [true],
            'false' => [false],
            'list' => [[1]],
        ];
    }

    /**
     * @dataProvider noWholeNumberProvider
     */
    public function testNoReaderTakesAValueThatIsNoWholeNumber(mixed $value): void
    {
        $this->assertNull(RunnerReportNumbers::sequence($value));
        $this->assertNull(RunnerReportNumbers::exitCode($value));
        $this->assertNull(RunnerReportNumbers::taskNumber($value));
    }

    /**
     * The reader, its lowest and its highest whole number, the message that
     * refuses any other.
     *
     * @return array<string, array{0: callable(mixed): ?int, 1: int, 2: int, 3: string}>
     */
    public static function rangeProvider(): array
    {
        return [
            'sequence: the job log\'s unsigned column' => [
                [RunnerReportNumbers::class, 'sequence'], 0, 4294967295, RunnerReportNumbers::SEQUENCE_REFUSED,
            ],
            'exit code: the job record\'s rule' => [
                [RunnerReportNumbers::class, 'exitCode'], -256, 255, RunnerReportNumbers::EXIT_CODE_REFUSED,
            ],
            'seq and duration_ms: the task list\'s columns' => [
                [RunnerReportNumbers::class, 'taskNumber'], -2147483648, 2147483647, RunnerReportNumbers::TASK_NUMBER_REFUSED,
            ],
        ];
    }

    /**
     * @dataProvider rangeProvider
     * @param callable(mixed): ?int $read
     */
    public function testEachReaderTakesTheWholeNumbersOfItsRangeOnly(callable $read, int $lowest, int $highest, string $message): void
    {
        $this->assertSame($lowest, $read($lowest));
        $this->assertSame($highest, $read($highest));
        $this->assertSame($highest, $read((string)$highest), 'digits');
        $this->assertSame($highest, $read('+0' . $highest), 'digits with a sign and a leading zero');
        $this->assertSame($lowest, $read((float)$lowest), 'a whole float');
        $this->assertNull($read($lowest - 1));
        $this->assertNull($read($highest + 1));
        $this->assertNull($read(PHP_INT_MIN));
        $this->assertNull($read(PHP_INT_MAX));
        $this->assertNull($read('99999999999999999999'), 'digits beyond the int range, which the integer rule reads as the largest int');
        $this->assertStringContainsString(sprintf('must be a whole number from %d to %d.', $lowest, $highest), $message);
    }

    /**
     * A completion writes its exit code without the job record's validation,
     * so the reader takes exactly the exit codes the record's rule takes.
     */
    public function testTheExitCodeRangeIsTheJobRecordsRule(): void
    {
        $rules = array_values(array_filter((new Job())->rules(), static fn (array $rule): bool => $rule[0] === ['exit_code']));
        $this->assertCount(1, $rules, 'one rule for the exit code');
        [$rule] = $rules;
        $this->assertSame('integer', $rule[1]);

        $this->assertSame($rule['min'], RunnerReportNumbers::exitCode($rule['min']));
        $this->assertSame($rule['max'], RunnerReportNumbers::exitCode($rule['max']));
        $this->assertNull(RunnerReportNumbers::exitCode($rule['min'] - 1));
        $this->assertNull(RunnerReportNumbers::exitCode($rule['max'] + 1));
    }

    public function testATaskListThatCanBeSavedIsAccepted(): void
    {
        $this->assertNull(RunnerReportNumbers::taskListRefusal([]));
        $this->assertNull(RunnerReportNumbers::taskListRefusal([
            ['seq' => 0, 'name' => 'Gathering Facts', 'duration_ms' => 812],
            ['seq' => '1', 'duration_ms' => 2.0],
            ['seq' => -1, 'duration_ms' => -3],
            ['seq' => null, 'duration_ms' => null],
            ['name' => 'no numbers'],
            [],
        ]));
    }

    public function testTheFirstTaskThatCannotBeSavedIsNamed(): void
    {
        $body = json_decode('{"tasks": [{"seq": 0, "duration_ms": 1e30}, {"seq": 1e999}]}', true);
        $this->assertIsArray($body);
        $this->assertIsArray($body['tasks']);

        $this->assertSame(
            'tasks[0].duration_ms must be a whole number from -2147483648 to 2147483647.',
            RunnerReportNumbers::taskListRefusal($body['tasks'])
        );
        $this->assertSame(
            'tasks[1].seq must be a whole number from -2147483648 to 2147483647.',
            RunnerReportNumbers::taskListRefusal([['seq' => 0], ['seq' => INF, 'duration_ms' => INF]]),
            'seq is looked at before duration_ms'
        );
        $this->assertSame(
            'tasks[2] must be an object.',
            RunnerReportNumbers::taskListRefusal([['seq' => 0], ['seq' => 1], 'ok: [web1]'])
        );
        $this->assertSame(
            'tasks[web1] must be an object.',
            RunnerReportNumbers::taskListRefusal(['web1' => 5]),
            'a JSON object of tasks is named by its keys'
        );
    }
}
