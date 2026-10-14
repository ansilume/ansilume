<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\WorkflowStepPosition;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowStepPosition (contract C2): the factories and accessors, and how
 * the Position field of the web form and the bodies of the API are read. A
 * whole number is an int or a string of digits from 1; booleans, floats,
 * signs, spaces and 0 are refused, and so is a number too large for an int.
 * In an API body, a key sent as null counts as not sent. Each refusal is one
 * of the six texts.
 */
class WorkflowStepPositionTest extends TestCase
{
    private const START = ['start', null, null];
    private const END = ['end', null, null];
    private const UP = ['up', null, null];
    private const DOWN = ['down', null, null];
    private const TOO_LARGE = '99999999999999999999';

    public function testTheTextsKeepTheirWording(): void
    {
        $this->assertSame(
            'Choose a position: at the start, at the end, or after a step of this workflow.',
            WorkflowStepPosition::FORM_INVALID
        );
        $this->assertSame('Send either position or after_step_id, not both.', WorkflowStepPosition::BOTH_GIVEN);
        $this->assertSame('Send exactly one of position, after_step_id and direction.', WorkflowStepPosition::MOVE_NEEDS_ONE);
        $this->assertSame('position must be "start", "end" or a whole number from 1.', WorkflowStepPosition::POSITION_INVALID);
        $this->assertSame('after_step_id must be the ID of a step of this workflow.', WorkflowStepPosition::AFTER_INVALID);
        $this->assertSame('direction must be "up" or "down".', WorkflowStepPosition::DIRECTION_INVALID);
    }

    public function testEachFactoryNamesItsKindAndOnlyItsOwnValue(): void
    {
        $this->assertSame(self::START, self::shape(WorkflowStepPosition::start()));
        $this->assertSame(self::END, self::shape(WorkflowStepPosition::end()));
        $this->assertSame(['after', 12, null], self::shape(WorkflowStepPosition::after(12)));
        $this->assertSame(['at', null, 3], self::shape(WorkflowStepPosition::at(3)));
        $this->assertSame(self::UP, self::shape(WorkflowStepPosition::up()));
        $this->assertSame(self::DOWN, self::shape(WorkflowStepPosition::down()));
        $this->assertSame(
            ['start', 'end', 'after', 'at', 'up', 'down'],
            [
                WorkflowStepPosition::KIND_START,
                WorkflowStepPosition::KIND_END,
                WorkflowStepPosition::KIND_AFTER,
                WorkflowStepPosition::KIND_AT,
                WorkflowStepPosition::KIND_UP,
                WorkflowStepPosition::KIND_DOWN,
            ]
        );
        $this->assertSame(['keep', 'after:'], [WorkflowStepPosition::WEB_KEEP, WorkflowStepPosition::WEB_AFTER_PREFIX]);
    }

    /**
     * @return array<string, array{0: mixed, 1: list<int|string|null>|null}>
     */
    public static function webAddProvider(): array
    {
        return [
            'missing: at the end' => [null, self::END],
            'empty: at the end' => ['', self::END],
            'end' => ['end', self::END],
            'start' => ['start', self::START],
            'after a step' => ['after:12', ['after', 12, null]],
            'after a step, leading zeros read away' => ['after:007', ['after', 7, null]],
            'after the largest int' => ['after:' . PHP_INT_MAX, ['after', PHP_INT_MAX, null]],
        ];
    }

    /**
     * @dataProvider webAddProvider
     * @param list<int|string|null>|null $expected
     */
    public function testTheAddFormTakesItsValuesAndDefaultsToTheEnd(mixed $value, ?array $expected): void
    {
        $this->assertSame($expected, self::shape(WorkflowStepPosition::fromWeb($value, false)));
    }

    /**
     * @return array<string, array{0: mixed, 1: list<int|string|null>|null}>
     */
    public static function webEditProvider(): array
    {
        return [
            'missing: keep' => [null, null],
            'empty: keep' => ['', null],
            'keep' => ['keep', null],
            'end' => ['end', self::END],
            'start' => ['start', self::START],
            'after a step' => ['after:5', ['after', 5, null]],
        ];
    }

    /**
     * @dataProvider webEditProvider
     * @param list<int|string|null>|null $expected
     */
    public function testTheEditFormTakesItsValuesAndDefaultsToKeep(mixed $value, ?array $expected): void
    {
        $this->assertSame($expected, self::shape(WorkflowStepPosition::fromWeb($value, true)));
    }

    public function testKeepIsRefusedWhenAStepIsAdded(): void
    {
        $this->assertSame(WorkflowStepPosition::FORM_INVALID, WorkflowStepPosition::fromWeb('keep', false));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function webRefusedProvider(): array
    {
        return [
            'after without an ID' => ['after:'],
            'after step ID 0' => ['after:0'],
            'after step ID 00' => ['after:00'],
            'after a negative ID' => ['after:-1'],
            'after a signed ID' => ['after:+5'],
            'after an ID with a space before' => ['after: 5'],
            'after an ID with a space behind' => ['after:5 '],
            'after an ID with a newline behind' => ["after:5\n"],
            'after an ID with letters' => ['after:5x'],
            'after a decimal' => ['after:1.5'],
            'after an exponent' => ['after:1e3'],
            'after an ID too large for an int' => ['after:' . self::TOO_LARGE],
            'after a name' => ['after:build'],
            'after without a colon' => ['after5'],
            'the prefix in capitals' => ['AFTER:5'],
            'start in capitals' => ['Start'],
            'end in capitals' => ['END'],
            'end with a space before' => [' end'],
            'end with a space behind' => ['end '],
            'a direction: up' => ['up'],
            'a direction: down' => ['down'],
            'a bare number' => ['2'],
            'a bare 0' => ['0'],
            'an API position' => ['at:2'],
            'an int' => [2],
            'int 0' => [0],
            'a float' => [1.0],
            'true' => [true],
            'false' => [false],
            'an empty list' => [[]],
            'a list' => [['end']],
            'an object' => [new \stdClass()],
        ];
    }

    /**
     * @dataProvider webRefusedProvider
     */
    public function testAnyOtherWebValueIsRefusedOnAddAndEdit(mixed $value): void
    {
        $this->assertSame(WorkflowStepPosition::FORM_INVALID, WorkflowStepPosition::fromWeb($value, false), 'add');
        $this->assertSame(WorkflowStepPosition::FORM_INVALID, WorkflowStepPosition::fromWeb($value, true), 'edit');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<int|string|null>}>
     */
    public static function apiAcceptedProvider(): array
    {
        return [
            'position start' => [['position' => 'start'], self::START],
            'position end' => [['position' => 'end'], self::END],
            'position 1' => [['position' => 1], ['at', null, 1]],
            'position 3' => [['position' => 3], ['at', null, 3]],
            'position as digits' => [['position' => '3'], ['at', null, 3]],
            'position as digits, leading zeros read away' => [['position' => '03'], ['at', null, 3]],
            'the largest int as position' => [['position' => PHP_INT_MAX], ['at', null, PHP_INT_MAX]],
            'the largest int as digits' => [['position' => (string)PHP_INT_MAX], ['at', null, PHP_INT_MAX]],
            'after_step_id' => [['after_step_id' => 9], ['after', 9, null]],
            'after_step_id as digits' => [['after_step_id' => '9'], ['after', 9, null]],
            'after_step_id as digits, leading zeros read away' => [['after_step_id' => '009'], ['after', 9, null]],
            'after_step_id beside a null position' => [['position' => null, 'after_step_id' => 4], ['after', 4, null]],
            'position beside a null after_step_id' => [['position' => 'end', 'after_step_id' => null], self::END],
            'other keys are ignored' => [
                ['position' => 2, 'direction' => 'up', 'step_order' => 7, 'id' => 3, 'name' => 'Deploy'],
                ['at', null, 2],
            ],
        ];
    }

    /**
     * @dataProvider apiAcceptedProvider
     * @param array<string, mixed> $body
     * @param list<int|string|null> $expected
     */
    public function testAnApiAddOrUpdateTakesAPositionOrAnAfterStepId(array $body, array $expected): void
    {
        $this->assertSame($expected, self::shape(WorkflowStepPosition::fromApi($body, true)), 'create');
        $this->assertSame($expected, self::shape(WorkflowStepPosition::fromApi($body, false)), 'update');
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function apiNeitherProvider(): array
    {
        return [
            'an empty body' => [[]],
            'both sent as null' => [['position' => null, 'after_step_id' => null]],
            'a direction, which only a move reads' => [['direction' => 'up']],
            'only other fields' => [['name' => 'Deploy', 'step_order' => 3]],
        ];
    }

    /**
     * @dataProvider apiNeitherProvider
     * @param array<string, mixed> $body
     */
    public function testAnApiBodyWithoutAPositionAddsAtTheEndAndUpdatesInPlace(array $body): void
    {
        $this->assertSame(self::END, self::shape(WorkflowStepPosition::fromApi($body, true)), 'create');
        $this->assertNull(WorkflowStepPosition::fromApi($body, false), 'update: keep');
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function apiBothProvider(): array
    {
        return [
            'two valid values' => [['position' => 2, 'after_step_id' => 4]],
            'start and a step' => [['position' => 'start', 'after_step_id' => '4']],
            'two invalid values: the shape is refused first' => [['position' => 'bogus', 'after_step_id' => true]],
            'two empty strings' => [['position' => '', 'after_step_id' => '']],
        ];
    }

    /**
     * @dataProvider apiBothProvider
     * @param array<string, mixed> $body
     */
    public function testAnApiBodyWithPositionAndAfterStepIdIsRefused(array $body): void
    {
        $this->assertSame(WorkflowStepPosition::BOTH_GIVEN, WorkflowStepPosition::fromApi($body, true), 'create');
        $this->assertSame(WorkflowStepPosition::BOTH_GIVEN, WorkflowStepPosition::fromApi($body, false), 'update');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidPositionProvider(): array
    {
        return [
            'int 0' => [0],
            'a negative int' => [-1],
            'the smallest int' => [PHP_INT_MIN],
            'digits 0' => ['0'],
            'digits 00' => ['00'],
            'a negative number as text' => ['-1'],
            'a signed number as text' => ['+1'],
            'a space before' => [' 1'],
            'a space behind' => ['1 '],
            'a newline behind' => ["1\n"],
            'an empty string' => [''],
            'a decimal as text' => ['2.5'],
            'an exponent as text' => ['1e3'],
            'a number too large for an int' => [self::TOO_LARGE],
            'start in capitals' => ['Start'],
            'end in capitals' => ['END'],
            'keep, a web value' => ['keep'],
            'after a step, a web value' => ['after:3'],
            'a direction' => ['up'],
            'float 1.0' => [1.0],
            'float 2.5' => [2.5],
            'true' => [true],
            'false' => [false],
            'an empty list' => [[]],
            'a list' => [['start']],
            'an object' => [['at' => 2]],
        ];
    }

    /**
     * @dataProvider invalidPositionProvider
     */
    public function testAnApiPositionOtherThanStartEndOrAWholeNumberFromOneIsRefused(mixed $position): void
    {
        $this->assertSame(WorkflowStepPosition::POSITION_INVALID, WorkflowStepPosition::fromApi(['position' => $position], true), 'create');
        $this->assertSame(WorkflowStepPosition::POSITION_INVALID, WorkflowStepPosition::fromApi(['position' => $position], false), 'update');
        $this->assertSame(WorkflowStepPosition::POSITION_INVALID, WorkflowStepPosition::fromApiMove(['position' => $position]), 'move');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidAfterStepIdProvider(): array
    {
        return [
            'int 0' => [0],
            'a negative int' => [-3],
            'digits 0' => ['0'],
            'a negative number as text' => ['-3'],
            'a signed number as text' => ['+3'],
            'a space before' => [' 3'],
            'an empty string' => [''],
            'a name' => ['build'],
            'start' => ['start'],
            'end' => ['end'],
            'a decimal as text' => ['3.0'],
            'a number too large for an int' => [self::TOO_LARGE],
            'float 3.0' => [3.0],
            'true' => [true],
            'false' => [false],
            'an empty list' => [[]],
            'a list' => [[3]],
        ];
    }

    /**
     * @dataProvider invalidAfterStepIdProvider
     */
    public function testAnApiAfterStepIdOtherThanAWholeNumberFromOneIsRefused(mixed $stepId): void
    {
        $this->assertSame(WorkflowStepPosition::AFTER_INVALID, WorkflowStepPosition::fromApi(['after_step_id' => $stepId], true), 'create');
        $this->assertSame(WorkflowStepPosition::AFTER_INVALID, WorkflowStepPosition::fromApi(['after_step_id' => $stepId], false), 'update');
        $this->assertSame(WorkflowStepPosition::AFTER_INVALID, WorkflowStepPosition::fromApiMove(['after_step_id' => $stepId]), 'move');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<int|string|null>}>
     */
    public static function moveAcceptedProvider(): array
    {
        return [
            'position start' => [['position' => 'start'], self::START],
            'position end' => [['position' => 'end'], self::END],
            'position 2' => [['position' => 2], ['at', null, 2]],
            'position as digits' => [['position' => '2'], ['at', null, 2]],
            'after_step_id' => [['after_step_id' => 5], ['after', 5, null]],
            'after_step_id as digits' => [['after_step_id' => '5'], ['after', 5, null]],
            'direction up' => [['direction' => 'up'], self::UP],
            'direction down' => [['direction' => 'down'], self::DOWN],
            'one value beside two nulls' => [['position' => null, 'after_step_id' => null, 'direction' => 'down'], self::DOWN],
            'other keys are ignored' => [['direction' => 'up', 'step_id' => 3, 'step_order' => 1], self::UP],
        ];
    }

    /**
     * @dataProvider moveAcceptedProvider
     * @param array<string, mixed> $body
     * @param list<int|string|null> $expected
     */
    public function testAnApiMoveTakesExactlyOneOfItsThreeKeys(array $body, array $expected): void
    {
        $this->assertSame($expected, self::shape(WorkflowStepPosition::fromApiMove($body)));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function moveNeedsOneProvider(): array
    {
        return [
            'an empty body' => [[]],
            'all three sent as null' => [['position' => null, 'after_step_id' => null, 'direction' => null]],
            'only other keys' => [['step_id' => 3]],
            'position and after_step_id' => [['position' => 2, 'after_step_id' => 5]],
            'position and direction' => [['position' => 'start', 'direction' => 'up']],
            'after_step_id and direction' => [['after_step_id' => 5, 'direction' => 'down']],
            'all three' => [['position' => 1, 'after_step_id' => 5, 'direction' => 'up']],
            'two invalid values: the shape is refused first' => [['position' => 'bogus', 'direction' => 'sideways']],
        ];
    }

    /**
     * @dataProvider moveNeedsOneProvider
     * @param array<string, mixed> $body
     */
    public function testAnApiMoveWithNoneOrSeveralOfItsKeysIsRefused(array $body): void
    {
        $this->assertSame(WorkflowStepPosition::MOVE_NEEDS_ONE, WorkflowStepPosition::fromApiMove($body));
    }

    /**
     * @return array<string, array{0: mixed, 1: list<int|string|null>}>
     */
    public static function directionProvider(): array
    {
        return [
            'up' => ['up', self::UP],
            'down' => ['down', self::DOWN],
        ];
    }

    /**
     * @dataProvider directionProvider
     * @param list<int|string|null> $expected
     */
    public function testADirectionIsUpOrDown(mixed $direction, array $expected): void
    {
        $this->assertSame($expected, self::shape(WorkflowStepPosition::fromDirection($direction)), 'web');
        $this->assertSame($expected, self::shape(WorkflowStepPosition::fromApiMove(['direction' => $direction])), 'API move');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidDirectionProvider(): array
    {
        return [
            'up in capitals' => ['Up'],
            'down in capitals' => ['DOWN'],
            'a space before' => [' up'],
            'a space behind' => ['up '],
            'an empty string' => [''],
            'another word' => ['left'],
            'a position' => ['start'],
            'int 1' => [1],
            'int 0' => [0],
            'float 1.0' => [1.0],
            'true' => [true],
            'false' => [false],
            'a list' => [['up']],
        ];
    }

    /**
     * @dataProvider invalidDirectionProvider
     */
    public function testAnyOtherDirectionIsRefused(mixed $direction): void
    {
        $this->assertSame(WorkflowStepPosition::DIRECTION_INVALID, WorkflowStepPosition::fromDirection($direction), 'web');
        $this->assertSame(WorkflowStepPosition::DIRECTION_INVALID, WorkflowStepPosition::fromApiMove(['direction' => $direction]), 'API move');
    }

    public function testAMissingDirectionIsRefused(): void
    {
        $this->assertSame(WorkflowStepPosition::DIRECTION_INVALID, WorkflowStepPosition::fromDirection(null));
    }

    /**
     * A result as a value that assertSame() compares strictly: [kind, step
     * ID, position] for a position, else the refusal text or null.
     *
     * @return list<int|string|null>|string|null
     */
    private static function shape(WorkflowStepPosition|string|null $result): array|string|null
    {
        if (!$result instanceof WorkflowStepPosition) {
            return $result;
        }

        return [$result->kind(), $result->stepId(), $result->position()];
    }
}
