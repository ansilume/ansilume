<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\ApproverConfigIds;
use PHPUnit\Framework\TestCase;

/**
 * ApproverConfigIds reads the team and the users that an approval rule's
 * parsed approver config names as the integer rule reads an ID: every form
 * of an ID is that ID, anything else names nobody. The config is a JSON
 * object a form or the API stored, so its numbers can lie beyond the int
 * range, whose (int) cast warns since PHP 8.5.
 */
class ApproverConfigIdsTest extends TestCase
{
    /**
     * Every form of an ID that the integer rule accepts.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function idProvider(): array
    {
        return ['an int' => [7], 'digits' => ['7'], 'a leading zero' => ['07'], 'a plus sign' => ['+7'], 'a whole float' => [7.0]];
    }

    /**
     * @dataProvider idProvider
     */
    public function testEveryFormOfAnIdNamesThatTeamAndUser(mixed $id): void
    {
        $this->assertSame(7, ApproverConfigIds::teamOf(['team_id' => $id]));
        $this->assertSame([7], ApproverConfigIds::usersOf(['user_ids' => [$id]]));
    }

    /**
     * Values that name nobody: numbers beyond the int range, as the JSON
     * decoder gives 1e30, 9223372036854775808 and 1e999, a fraction, text,
     * booleans, a list and none.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function noIdProvider(): array
    {
        return [
            '1e30' => [1e30],
            'one more than the largest int' => [(float)'9223372036854775808'],
            'infinity' => [INF],
            'minus infinity' => [-INF],
            'a fraction' => [7.5],
            'decimal text' => ['7.0'],
            'text' => ['abc'],
            'digits and text' => ['7abc'],
            'true' => [true],
            'false' => [false],
            'a list' => [[7]],
            'none' => [null],
            'empty text' => [''],
        ];
    }

    /**
     * @dataProvider noIdProvider
     */
    public function testAnyOtherValueNamesNobody(mixed $value): void
    {
        $this->assertNull(ApproverConfigIds::teamOf(['team_id' => $value]));
        $this->assertSame([3], ApproverConfigIds::usersOf(['user_ids' => [$value, 3]]), 'the other users stay');
    }

    /**
     * The users keep their order, and a user listed twice stays listed
     * twice, as before; a missing key or user_ids that is no list names no
     * user, and a missing team_id no team.
     */
    public function testTheUsersKeepTheirOrderAndAConfigWithoutThemNamesNobody(): void
    {
        $this->assertSame([9, 3, 9], ApproverConfigIds::usersOf(['user_ids' => ['9', 3, 9.0]]));
        $this->assertSame([], ApproverConfigIds::usersOf([]));
        $this->assertSame([], ApproverConfigIds::usersOf(['user_ids' => 7]));
        $this->assertSame([], ApproverConfigIds::usersOf(['user_ids' => '7']));
        $this->assertNull(ApproverConfigIds::teamOf([]));
        $this->assertNull(ApproverConfigIds::teamOf(['role' => 'operator']));
    }
}
