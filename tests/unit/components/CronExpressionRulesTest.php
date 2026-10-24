<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\CronExpressionRules;
use app\models\Schedule;
use Cron\CronExpression;
use PHPUnit\Framework\TestCase;

/**
 * Regression: the cron library accepts expressions that it then never runs,
 * or runs at other times than cron does, and the schedule rule took every
 * expression the library accepted: "5-1 * * * *" was saved and never ran.
 * testTheLibraryStillRunsTheseOtherwiseThanCron pins each kind, so a library
 * update that fixes one shows which rule may go.
 */
class CronExpressionRulesTest extends TestCase
{
    /** The part a refusal names, and how it says to write that part. */
    private const SUGGESTION = '~(?:the (?:range|list) )?(?<part>\S+) of the [a-z-]+ field.*(?:as in|Write it as) (?<suggestion>\S+)\.$~';

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedProvider(): array
    {
        $accepted = [
            '0 2 * * *', '*/5 * * * *', '0 */4 * * *', '30 3 * * 1-5', '0 0 1 * *', '0 9-17 * * MON-FRI',
            '15,45 * * * *', '0 0 * * 0', '0 0 * * 7', '@daily', '@HOURLY', '@yearly', '0 12 * * SUN',
            '*/15 9-17 * * 1-5', '0 0 L * *', '0 0 * * 5L', '0 0 * * SUNL', '0 0 * * 1#1', '0 0 * * mon#2',
            '0 0 15W * *', '0 0 28W * *', '0 4 8-14 * *', '0 0,12 1 */2 *', '23 0-23/2 * * *',
            '0 0 * jan,apr,jul,oct *', '0 0 * * mon,wed,fri', '0 0 29 2 *', '0 0 31 2 1', '0 0 * * 5-7',
            '0 0 * * 0-6', '0 0 * * 1-7', '0 0 * * FRI-7', '0 0 * * SAT-7', '0 0 * * 0-7', '0 0 * * SAT,SUN',
            '*/58 * * * *', '0 */22 * * *', '0 0 */30 * *', '0 0 1 */11 *', '0 0 * * */6', '0 0 * * 1-7/2',
            '0 0 * * 0-6/2', '0 0 * * 0-7/1', '0 0 * * 1#2,5L', ' 0 2 * * * ', "0\t2 * * *", '05 * * * *',
            '0 0 ? * 1', '0 0 1 * ?', '0-59 * * * *', '1-30/5 * * * *', '0-30/45 * * * *', '59 23 31 12 *',
            '0,30 * * * *', '30,0 * * * *', '10,5 * * * *', '0-5,30 * * * *', '0,0-5 * * * *', '0 6,18 * * *',
            '0 1,9-17 * * *', '0 1-3,5-7 * * *', '0 0-2,22-23 * * *', '0 1-23/2,2 * * *', '0 */4,1 * * *',
            '0 5,* * * *', '0 *,8 * * *', '30 *,0-5 * * *', '0-4/2,5,6-58/2 * * * *', '05,10-50 * * * *',
        ];

        return array_combine($accepted, array_map(static fn (string $cron): array => [$cron], $accepted));
    }

    /**
     * @dataProvider acceptedProvider
     */
    public function testAnExpressionThatRunsAsItSaysHasNoProblem(string $cron): void
    {
        $this->assertTrue(CronExpression::isValidExpression($cron), 'the library accepts it');
        $this->assertNull(CronExpressionRules::problem($cron));
    }

    /**
     * Each expression the library accepts, with the reason the rules give.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedProvider(): array
    {
        $backwards = static fn (string $range, string $field, string $split): string => sprintf(CronExpressionRules::RANGE_BACKWARDS, $range, $field, $split);
        $tooLarge = static fn (string $step, string $field, int $largest): string => sprintf(CronExpressionRules::STEP_TOO_LARGE, $step, $field, $largest);

        return [
            'a minute range that runs backwards' => ['5-1 * * * *', $backwards('5-1', 'minute', '0-1,5-59')],
            'an hour range that runs backwards' => ['* 5-1 * * *', $backwards('5-1', 'hour', '0-1,5-23')],
            'an hour range that would wrap around midnight' => ['0 22-2 * * *', $backwards('22-2', 'hour', '0-2,22-23')],
            'a day range that runs backwards' => ['* * 5-1 * *', $backwards('5-1', 'day-of-month', '1,5-31')],
            'a month range that runs backwards' => ['* * * 5-1 *', $backwards('5-1', 'month', '1,5-12')],
            'a weekday range that runs backwards' => ['* * * * 5-1', $backwards('5-1', 'day-of-week', '0-1,5-6')],
            'a weekday range from Saturday that runs backwards' => ['* * * * 6-2', $backwards('6-2', 'day-of-week', '0-2,6')],
            'a range of names that runs backwards' => ['* * * DEC-JAN *', $backwards('DEC-JAN', 'month', '1,12')],
            'a range of weekday names that runs backwards' => ['* * * * FRI-MON', $backwards('FRI-MON', 'day-of-week', '0-1,5-6')],
            'a backwards range in a list' => ['1,2,3-1 * * * *', $backwards('3-1', 'minute', '0-1,3-59')],
            'a backwards range with a step' => ['5-1/2 * * * *', $backwards('5-1/2', 'minute', '0,5-59/2')],
            'a backwards range with a step to the end' => ['59-10/5 * * * *', $backwards('59-10/5', 'minute', '0-10/5,59')],
            'a backwards range with a step on both sides' => ['50-10/5 * * * *', $backwards('50-10/5', 'minute', '0-10/5,50-59/5')],
            'a backwards hour range with a step' => ['0 23-4/10 * * *', $backwards('23-4/10', 'hour', '0,23')],
            'a backwards month range of a name and a number with a step' => ['0 0 * DEC-7/5 *', $backwards('DEC-7/5', 'month', '1-7/5,12')],
            'a backwards range whose split would be out of order in its list' => [
                '8,50-5 * * * *',
                sprintf(CronExpressionRules::RANGE_BACKWARDS_UNSPLIT, '50-5', 'minute'),
            ],
            'a backwards range whose split would not fit' => [
                '30-10 0 1,8,15,22,29 JAN,FEB,MAR,APR,MAY,JUN,JUL,AUG,SEP,OCT *',
                sprintf(CronExpressionRules::RANGE_BACKWARDS_UNSPLIT, '30-10', 'minute'),
            ],
            'a weekday range ending with Sunday as 0' => ['* * * * 5-0', sprintf(CronExpressionRules::SUNDAY_ZERO_LAST, '5-0', '5-7')],
            'a weekday name range ending with Sunday as 0' => ['0 0 * * FRI-0', sprintf(CronExpressionRules::SUNDAY_ZERO_LAST, 'FRI-0', 'FRI-7')],
            'a weekday range ending with Sunday as 00' => ['0 0 * * 6-00', sprintf(CronExpressionRules::SUNDAY_ZERO_LAST, '6-00', '6-7')],
            'every day as 1-0' => ['0 0 * * 1-0', sprintf(CronExpressionRules::SUNDAY_ZERO_LAST, '1-0', '1-7')],
            'a weekday name range ending with Sunday as 0, with a step' => ['0 0 * * FRI-0/2', sprintf(CronExpressionRules::SUNDAY_ZERO_LAST, 'FRI-0/2', '5-7/2')],
            'Sunday as 7 at the start of a range' => ['* * * * 7-3', sprintf(CronExpressionRules::SUNDAY_FIRST, '7-3', '0-3')],
            'SUN at the start of a range' => ['0 0 * * SUN-SAT', sprintf(CronExpressionRules::SUNDAY_FIRST, 'SUN-SAT', '0-SAT')],
            'SUN at the start of a range to 7' => ['0 0 * * SUN-7', sprintf(CronExpressionRules::SUNDAY_FIRST, 'SUN-7', '0-7')],
            'SUN at the start of a range with a step' => ['0 0 * * SUN-SAT/2', sprintf(CronExpressionRules::SUNDAY_FIRST, 'SUN-SAT/2', '0-6/2')],
            'SUN at the start of a range to 7 with a step' => ['0 0 * * SUN-7/2', sprintf(CronExpressionRules::SUNDAY_FIRST, 'SUN-7/2', '0-6/2')],
            'Sunday as 7 at the start of a range with a step' => ['0 0 * * 7-3/2', sprintf(CronExpressionRules::SUNDAY_FIRST, '7-3/2', '0-3/2')],
            'a too large step of a range with Sunday first' => ['0 0 * * SUN-SAT/9', $tooLarge('/9', 'day-of-week', 7)],
            'Sunday alone as 7-7' => ['* * * * 7-7', sprintf(CronExpressionRules::SUNDAY_ALONE, '7-7')],
            'Sunday alone as 7-0' => ['* * * * 7-0', sprintf(CronExpressionRules::SUNDAY_ALONE, '7-0')],
            'Sunday alone as 0-0' => ['0 0 * * 0-0', sprintf(CronExpressionRules::SUNDAY_ALONE, '0-0')],
            'Sunday alone as 0-SUN' => ['0 0 * * 0-SUN', sprintf(CronExpressionRules::SUNDAY_ALONE, '0-SUN')],
            'Sunday alone as SUN-SUN' => ['0 0 * * SUN-SUN', sprintf(CronExpressionRules::SUNDAY_ALONE, 'SUN-SUN')],
            'a weekday range ending with the name SUN' => ['0 0 * * FRI-SUN', sprintf(CronExpressionRules::SUNDAY_LAST, 'FRI-SUN', 'FRI-7')],
            'the weekend as SAT-SUN' => ['0 0 * * sat-sun', sprintf(CronExpressionRules::SUNDAY_LAST, 'sat-sun', 'sat-7')],
            'every day as MON-SUN' => ['0 0 * * MON-SUN', sprintf(CronExpressionRules::SUNDAY_LAST, 'MON-SUN', 'MON-7')],
            'a number to the name SUN' => ['0 0 * * 1-SUN', sprintf(CronExpressionRules::SUNDAY_LAST, '1-SUN', '1-7')],
            'the weekend as SAT-SUN with a step' => ['0 0 * * SAT-SUN/2', sprintf(CronExpressionRules::SUNDAY_LAST, 'SAT-SUN/2', '6-7/2')],
            'a weekday step from 0 to 7' => ['0 0 * * 0-7/2', sprintf(CronExpressionRules::SUNDAY_STEP, '0-7/2', '0-6/2')],
            'an hour list out of order' => ['0 18,6 * * *', sprintf(CronExpressionRules::LIST_ORDER, '18,6', 'hour', '6,18')],
            'an hour list with a step before a value' => ['0 */6,3 * * *', sprintf(CronExpressionRules::LIST_ORDER, '*/6,3', 'hour', '0,3,6-18/6')],
            'a split hour range with steps' => [
                '0 20-23/2,0-4/2 * * *',
                sprintf(CronExpressionRules::LIST_ORDER, '20-23/2,0-4/2', 'hour', '0,2,4,20,22'),
            ],
            'a minute list the library sorts as text' => [
                '5-10,45 * * * *',
                sprintf(CronExpressionRules::LIST_ORDER, '5-10,45', 'minute', '5,6,7,8,9,10,45'),
            ],
            'an ascending minute list the library sorts as text' => [
                '5,10-12 * * * *',
                sprintf(CronExpressionRules::LIST_ORDER, '5,10-12', 'minute', '5,10,11,12'),
            ],
            'a minute list with a step before a value' => ['*/20,5 * * * *', sprintf(CronExpressionRules::LIST_ORDER, '*/20,5', 'minute', '0,5,20,40')],
            'a long hour list as a range' => ['0 1-22,0 * * *', sprintf(CronExpressionRules::LIST_ORDER, '1-22,0', 'hour', '0-22')],
            'a long minute list as ranges with steps' => [
                '*/2,5 * * * *',
                sprintf(CronExpressionRules::LIST_ORDER, '*/2,5', 'minute', '0,2,4,5,6-58/2'),
            ],
            'a long minute list whose shortest form fits' => [
                '*/6,*/4,3 * * * *',
                sprintf(CronExpressionRules::LIST_ORDER, '*/6,*/4,3', 'minute', '0,3,4,6,8,12,16-20/2,24,28-32/2,36,40-44/2,48,52-56/2'),
            ],
            'a long minute list whose shortest form starts with two digits' => [
                '*/3,*/8,27 * * * *',
                sprintf(CronExpressionRules::LIST_ORDER, '*/3,*/8,27', 'minute', '0-6/3,08,09-15/3,16,18-30/3,32,33-39/3,40,42-54/3,56,57'),
            ],
            'an hour list whose shortest form fits beside long fields' => [
                '26 14,*/5,5-16/2 1-15,16-31 1,2,3,4,5,6,7,8,9,10,11,12 *',
                sprintf(CronExpressionRules::LIST_ORDER, '14,*/5,5-16/2', 'hour', '0,5,7,9-11,13-15,20'),
            ],
            'a long minute list with a value before a range' => [
                '10-50,5 * * * *',
                sprintf(CronExpressionRules::LIST_ORDER, '10-50,5', 'minute', '05,10-50'),
            ],
            'a minute list too long in any order the scheduler walks' => [
                '*/2,*/3 * * * *',
                sprintf(CronExpressionRules::LIST_TOO_LONG, '*/2,*/3', 'minute', 64),
            ],
            'an asterisk in a minute list' => ['*,5 * * * *', sprintf(CronExpressionRules::ASTERISK_IN_LIST, '*,5')],
            'an asterisk after a value in a minute list' => ['5,* * * * *', sprintf(CronExpressionRules::ASTERISK_IN_LIST, '5,*')],
            'a minute step of 59' => ['*/59 * * * *', $tooLarge('/59', 'minute', 59)],
            'a minute step of 90' => ['*/90 * * * *', $tooLarge('/90', 'minute', 59)],
            'a minute step of 59 after a range' => ['0-59/59 * * * *', $tooLarge('/59', 'minute', 59)],
            'an hour step of 23' => ['0 */23 * * *', $tooLarge('/23', 'hour', 23)],
            'a day step of 32' => ['0 0 */32 * *', $tooLarge('/32', 'day-of-month', 31)],
            'a month step of 13' => ['0 0 1 */13 *', $tooLarge('/13', 'month', 12)],
            'a weekday step of 9' => ['0 0 * * */9', $tooLarge('/9', 'day-of-week', 7)],
            'a step after weekday names' => [
                '0 0 * * MON-FRI/2',
                sprintf(CronExpressionRules::STEP_AFTER_NAMES, 'MON-FRI/2', 'day-of-week', '1-5/2'),
            ],
            'a step after month names' => [
                '0 0 * JAN-DEC/3 *',
                sprintf(CronExpressionRules::STEP_AFTER_NAMES, 'JAN-DEC/3', 'month', '1-12/3'),
            ],
            'Sunday alone as 0-SUN with a step' => ['0 0 * * 0-SUN/2', sprintf(CronExpressionRules::SUNDAY_ALONE, '0-SUN/2')],
            'a step after names that is too large' => ['0 0 1 JAN-DEC/12 *', $tooLarge('/12', 'month', 12)],
            'a step of three digits' => ['*/100 * * * *', $tooLarge('/100', 'minute', 59)],
            'W after the 29th' => ['0 0 29W 2 *', sprintf(CronExpressionRules::NEAREST_WEEKDAY_LATE, '29W')],
            'W after the 31st' => ['0 0 31W * *', sprintf(CronExpressionRules::NEAREST_WEEKDAY_LATE, '31W')],
            'W after L' => ['0 0 LW * *', CronExpressionRules::INVALID],
            'W after a range' => ['0 0 1-15W * *', CronExpressionRules::INVALID],
            'W after a step' => ['0 0 */2W * *', CronExpressionRules::INVALID],
            'L after a range of weekdays' => ['0 0 * * 1-5L', CronExpressionRules::INVALID],
            '# after a range of weekdays' => ['0 0 * * 1-5#2', CronExpressionRules::INVALID],
            'a step after L' => ['0 0 * * 5L/2', CronExpressionRules::INVALID],
            'a range after L' => ['0 0 L-2 * *', CronExpressionRules::INVALID],
            'a step after a name' => ['0 0 * JAN/2 *', CronExpressionRules::INVALID],
            'a step after a question mark' => ['0 0 ?/2 * *', CronExpressionRules::INVALID],
            'a nth weekday with a leading zero' => ['0 0 * * 1#05', CronExpressionRules::INVALID],
            'a nth weekday with a sign' => ['0 0 * * 1#+2', CronExpressionRules::INVALID],
            'a step with a sign' => ['*/+5 * * * *', CronExpressionRules::INVALID],
            'a value with a sign' => ['+5 * * * *', CronExpressionRules::INVALID],
            'a value with an exponent' => ['1e1 * * * *', CronExpressionRules::INVALID],
            'a value of three digits' => ['000 0 * * *', CronExpressionRules::INVALID],
            'two steps' => ['*/5/2 * * * *', CronExpressionRules::INVALID],
        ];
    }

    /**
     * @dataProvider refusedProvider
     */
    public function testAnExpressionTheLibraryAcceptsButRunsOtherwiseIsRefused(string $cron, string $problem): void
    {
        $this->assertTrue(CronExpression::isValidExpression($cron), 'the library accepts it');
        $this->assertSame($problem, CronExpressionRules::problem($cron));
    }

    /**
     * The refusals that suggest how to write the part they name, with the
     * expression that follows the suggestion.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function suggestionProvider(): array
    {
        $suggestions = [];
        foreach (self::refusedProvider() as $case => [$cron, $problem]) {
            if (preg_match(self::SUGGESTION, $problem, $match) === 1) {
                $at = (int)strpos($cron, $match['part']);
                $suggestions[$case] = [$cron, substr_replace($cron, $match['suggestion'], $at, strlen($match['part']))];
            }
        }

        return $suggestions;
    }

    /**
     * Review finding: following a refusal's suggestion could get the next
     * refusal. The list order suggested single numbers that made the
     * expression too long (every second minute and minute 5 as
     * 0,2,4,5,6,...,58: 94 characters), a
     * range that runs backwards was told to split as in 0-2,22-23, which the
     * day-of-month, month and day-of-week fields refuse, and a weekday range
     * ending with Sunday as 0 (5-0, Friday to Sunday) to put the smaller value
     * first, which makes it Sunday to Friday; it is told 5-7 now.
     *
     * @dataProvider suggestionProvider
     */
    public function testWhatARefusalSuggestsIsAccepted(string $cron, string $followed): void
    {
        $this->assertNotSame($cron, $followed);
        $this->assertLessThanOrEqual(Schedule::CRON_FIELD_MAX_LENGTH, strlen($followed), $followed);
        $this->assertNull(CronExpressionRules::problem($followed), $followed);
        $before = (array)preg_split('/\s+/', trim($cron));
        $after = (array)preg_split('/\s+/', trim($followed));
        foreach ([CronExpression::MINUTE => 59, CronExpression::HOUR => 23] as $position => $largest) {
            $this->assertSame(self::values((string)$before[$position], $largest), self::values((string)$after[$position], $largest), 'it means the same values');
        }
    }

    /**
     * The values a minute or hour field means, as a suggestion keeps them: a
     * range that runs backwards wraps around the end, and its step starts
     * again at 0.
     *
     * @return list<int>
     */
    private static function values(string $field, int $largest): array
    {
        $values = [];
        foreach (explode(',', $field) as $item) {
            preg_match('~^(?<from>\*|[0-9]+)(?:-(?<to>[0-9]+))?(?:/(?<step>[0-9]+))?$~', $item, $match);
            $from = $match['from'] === '*' ? 0 : (int)$match['from'];
            $to = $match['from'] === '*' ? $largest : (int)(($match['to'] ?? '') === '' ? $from : $match['to']);
            foreach ($from <= $to ? [[$from, $to]] : [[0, $to], [$from, $largest]] as [$low, $high]) {
                for ($value = $low; $value <= $high; $value += max(1, (int)($match['step'] ?? 1))) {
                    $values[$value] = $value;
                }
            }
        }
        sort($values);

        return $values;
    }

    /**
     * The suggestion for a list takes the characters the other fields leave:
     * single numbers where they fit, else ranges with minutes of two digits,
     * and none where nothing fits.
     */
    public function testAListSuggestionFitsBesideTheOtherFields(): void
    {
        $others = ' 1-15,16-28,29,30 1,2,3,4,5,6,7,8,9,10,11,12 1-5';
        $order = static fn (string $suggestion): string => sprintf(CronExpressionRules::LIST_ORDER, '5-10,45', 'minute', $suggestion);

        $this->assertSame($order('5,6,7,8,9,10,45'), CronExpressionRules::problem('5-10,45 * * * *'));
        $this->assertSame($order('05-10,45'), CronExpressionRules::problem('5-10,45 0' . $others));
        $this->assertSame(
            sprintf(CronExpressionRules::LIST_TOO_LONG, '5-10,45', 'minute', Schedule::CRON_FIELD_MAX_LENGTH),
            CronExpressionRules::problem('5-10,45 0,12' . $others . ',6,0'),
        );
        $this->assertSame(Schedule::CRON_FIELD_MAX_LENGTH, strlen('5-10,45 0,12' . $others . ',6,0'));
    }

    /**
     * problem() expects an expression the library accepts; one it would
     * refuse gets INVALID as well.
     *
     * @return array<string, array{0: string}>
     */
    public static function libraryRefusesProvider(): array
    {
        return [
            'four fields' => ['0 2 * *'],
            'a step of 0' => ['*/0 * * * *'],
            'an asterisk in a range' => ['*-5 * * * *'],
            'a value with a step' => ['5/10 * * * *'],
            'a minute past its range' => ['60 * * * *'],
            'a name in the minute field' => ['MON * * * *'],
            'a weekday past its range with L' => ['0 0 * * 8L'],
            'the 32nd with W' => ['0 0 32W * *'],
            'the 0th with W' => ['0 0 0W * *'],
            'an unknown name' => ['0 0 * * FOO'],
            'a weekday range past its range' => ['0 0 * * 1-8'],
        ];
    }

    /**
     * @dataProvider libraryRefusesProvider
     */
    public function testAnExpressionTheLibraryRefusesIsInvalid(string $cron): void
    {
        $this->assertSame(CronExpressionRules::INVALID, CronExpressionRules::problem($cron));
    }

    /**
     * What the library does with expressions the rules refuse, from Saturday,
     * 2026-10-24 10:00 UTC: if one of these changes, the library was fixed
     * there, and the rule that refuses it can be reconsidered. 5-0 runs from
     * Friday to Sunday, as 5-7, which its refusal suggests.
     *
     * @return array<string, array{0: string, 1: list<string>|string}>
     */
    public static function libraryQuirkProvider(): array
    {
        return [
            'a step of 90 minutes wraps around to minute 30' => ['*/90 * * * *', ['2026-10-24 10:30', '2026-10-24 11:30']],
            'a step of 59 minutes runs at minute 59 only' => ['*/59 * * * *', ['2026-10-24 10:59', '2026-10-24 11:59']],
            'a step after weekday names runs on Sundays' => ['0 0 * * MON-FRI/2', ['2026-10-25 00:00', '2026-11-01 00:00']],
            'Sunday as 7 at the start of a range runs on every day' => ['0 0 * * 7-7', ['2026-10-25 00:00', '2026-10-26 00:00']],
            'W after the 31st runs on the 1st of a shorter month' => ['0 0 31W * *', ['2026-10-30 00:00', '2026-11-01 00:00']],
            'SUN at the start of a range never runs' => ['0 0 * * SUN-SAT', 'Impossible CRON expression'],
            'Sunday alone as 0-0 runs on every day' => ['0 0 * * 0-0', ['2026-10-25 00:00', '2026-10-26 00:00']],
            'a range ending with the name SUN skips Sundays' => ['0 0 * * SAT-SUN', ['2026-10-31 00:00', '2026-11-07 00:00']],
            'a weekday step from 0 to 7 skips Sundays' => ['0 0 * * 0-7/2', ['2026-10-27 00:00', '2026-10-29 00:00']],
            'an hour list out of order skips 06:00' => ['0 18,6 * * *', ['2026-10-24 18:00', '2026-10-25 18:00']],
            'a minute list sorted as text skips minutes 5 to 10' => ['5-10,45 * * * *', ['2026-10-24 10:45', '2026-10-24 11:45']],
            'an asterisk in a minute list runs at minute 0 and the value only' => ['*,5 * * * *', ['2026-10-24 10:05', '2026-10-24 11:00']],
            'a weekday range ending with Sunday as 0 runs from Friday to Sunday, as 5-7' => ['0 0 * * 5-0', ['2026-10-25 00:00', '2026-10-30 00:00']],
            'a weekday range that runs backwards never runs' => ['* * * * 5-1', 'Impossible CRON expression'],
            'the 30th of February never runs' => ['0 0 30 2 *', 'Impossible CRON expression'],
        ];
    }

    /**
     * Review finding: an hour list with an asterisk, such as *,8, was refused
     * as one whose hours the scheduler would skip, but the library runs it
     * every hour, as cron does: an asterisk matches every hour, so the
     * library walks no such list.
     */
    public function testAnHourListWithAnAsteriskRunsEveryHour(): void
    {
        $cron = new CronExpression('0 *,8 * * *');
        $run = new \DateTimeImmutable('2026-10-24 10:00', new \DateTimeZone('UTC'));
        $runs = [];
        for ($i = 0; $i < 24; $i++) {
            $run = $cron->getNextRunDate($run, 0, false, 'UTC');
            $runs[] = $run->format('H:i');
        }

        $this->assertNull(CronExpressionRules::problem('0 *,8 * * *'));
        $this->assertSame(array_map(static fn (int $hour): string => sprintf('%02d:00', ($hour + 11) % 24), range(0, 23)), $runs);
    }

    /**
     * @dataProvider libraryQuirkProvider
     * @param list<string>|string $runs the next two runs, or the message of the library's RuntimeException
     */
    public function testTheLibraryStillRunsTheseOtherwiseThanCron(string $cron, array|string $runs): void
    {
        $cron = new CronExpression($cron);
        $from = new \DateTimeImmutable('2026-10-24 10:00', new \DateTimeZone('UTC'));
        try {
            $first = $cron->getNextRunDate($from, 0, false, 'UTC');
            $second = $cron->getNextRunDate($first, 0, false, 'UTC');
            $actual = [$first->format('Y-m-d H:i'), $second->format('Y-m-d H:i')];
        } catch (\RuntimeException $e) {
            $actual = $e->getMessage();
        }

        $this->assertSame($runs, $actual);
    }
}
