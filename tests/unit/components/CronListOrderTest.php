<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\CronListOrder;
use PHPUnit\Framework\TestCase;

/**
 * The model of how the cron library walks minute and hour lists, and the
 * lists it suggests instead; the library's own behaviour is pinned in
 * CronExpressionRulesTest.
 */
class CronListOrderTest extends TestCase
{
    /**
     * @return array<string, array{0: list<string>, 1: bool, 2: list<int>|null}>
     */
    public static function listProvider(): array
    {
        $everySecond = range(0, 58, 2);

        return [
            'hours in ascending order' => [['6', '18'], false, null],
            'hours as written, out of order' => [['18', '6'], false, [6, 18]],
            'hours that overlap without skipping any' => [['1-9', '2-5', '23'], false, null],
            'an hour after a step that starts below it' => [['*/6', '3'], false, [0, 3, 6, 12, 18]],
            'minutes the library sorts as numbers' => [['30', '0'], true, null],
            'minutes the library sorts as text' => [['5-10', '45'], true, [5, 6, 7, 8, 9, 10, 45]],
            'minutes with two digits, which it sorts as text in order' => [['05-10', '45'], true, null],
            'minutes it leaves out when it steps on from a run' => [['0-58/2', '0-59/29'], true, [...range(0, 28, 2), 29, ...range(30, 58, 2)]],
            'a step larger than its range' => [['0-30/45', '50'], true, null],
            'an asterisk in a minute list' => [['*', '5'], true, range(0, 59)],
            'an asterisk in an hour list, which it does not walk' => [['5', '*'], false, null],
            'an asterisk with a step in a minute list' => [['*/2', '5'], true, [0, 2, 4, 5, ...array_slice($everySecond, 3)]],
        ];
    }

    /**
     * @dataProvider listProvider
     * @param list<string> $items
     * @param list<int>|null $skipped
     */
    public function testSkipped(array $items, bool $minutes, ?array $skipped): void
    {
        $this->assertSame($skipped, CronListOrder::skipped($items, $minutes, 0, $minutes ? 59 : 23));
    }

    /**
     * @return array<string, array{0: list<int>, 1: bool, 2: int, 3: string|null}>
     */
    public static function suggestionProvider(): array
    {
        $everySecondAndFive = [0, 2, 4, 5, ...range(6, 58, 2)];
        $everySixthAndFourthAnd3 = [0, 3, 4, 6, 8, 12, 16, 18, 20, 24, 28, 30, 32, 36, 40, 42, 44, 48, 52, 54, 56];
        $everyThirdAndEighthAnd27 = array_values(array_filter(range(0, 59), static fn (int $minute): bool => $minute % 3 === 0 || $minute % 8 === 0));
        $everySecondAndThird = array_values(array_filter(range(0, 59), static fn (int $minute): bool => $minute % 2 === 0 || $minute % 3 === 0));

        return [
            'every hour' => [range(0, 23), false, 1, '*'],
            'single numbers' => [[6, 18], false, 10, '6,18'],
            'single numbers in exactly the room' => [[6, 18], false, 4, '6,18'],
            'no room' => [[6, 18], false, 3, null],
            'single numbers where a range is as long' => [[0, 3, 6], false, 60, '0,3,6'],
            'a step where it is shorter' => [[0, 3, 6, 12, 18], false, 60, '0,3,6-18/6'],
            'a range where it is shorter' => [range(0, 22), false, 60, '0-22'],
            'a range where single numbers do not fit' => [range(0, 22), false, 4, '0-22'],
            'single numbers where the library sorts the range as text' => [[5, 6, 7, 8, 9, 10, 45], true, 60, '5,6,7,8,9,10,45'],
            'minutes of two digits where single numbers do not fit' => [[5, 6, 7, 8, 9, 10, 45], true, 14, '05-10,45'],
            'ranges with steps, the fewest of two equally short lists' => [$everySecondAndFive, true, 60, '0,2,4,5,6-58/2'],
            'the shortest list, not the longest runs' => [[5, 7, 9, 10, 11], false, 60, '5,7,9-11'],
            'a list the library walks in order, of the shortest ones' => [$everySixthAndFourthAnd3, true, 56, '0,3,4,6,8,12,16-20/2,24,28-32/2,36,40-44/2,48,52-56/2'],
            'starts of two digits where the shortest list is out of order' => [$everyThirdAndEighthAnd27, true, 64, '0-6/3,08,09-15/3,16,18-30/3,32,33-39/3,40,42-54/3,56,57'],
            'a value before a range, in two digits' => [[5, ...range(10, 50)], true, 60, '05,10-50'],
            'minutes that fit in no form' => [$everySecondAndThird, true, 60, null],
            'hours as a range, which the library walks as written' => [[5, 6, 7, 8, 9, 10, 20], false, 8, '5-10,20'],
        ];
    }

    /**
     * Review findings: the suggestion was the values as single numbers, which
     * could be longer than an expression may be (94 characters for every
     * second minute and minute 5), and then the longest runs, which left out
     * shorter lists the library walks in order (every sixth and every fourth
     * minute and minute 3 got none).
     *
     * @dataProvider suggestionProvider
     * @param list<int> $values
     */
    public function testSuggestion(array $values, bool $minutes, int $room, ?string $suggestion): void
    {
        $largest = $minutes ? 59 : 23;

        $this->assertSame($suggestion, CronListOrder::suggestion($values, $minutes, 0, $largest, $room));
        if ($suggestion !== null && $suggestion !== '*') {
            $this->assertNull(CronListOrder::skipped(explode(',', $suggestion), $minutes, 0, $largest), 'the library walks it in order');
        }
    }
}
