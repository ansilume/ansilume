<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\Inventory;
use app\services\GatedInventoryLock;
use app\services\InventoryWriteService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * How the gated inventory lock reads an integer column that a request
 * filled (the project a form or a body loads, the approval rule that
 * InventoryRuleAssignment sets), before the model's validation: as the
 * integer rule reads a whole number (SubmittedInteger), never with the
 * column's type cast, an (int) cast.
 *
 * The same whole number in another form is no change ("7", "07", "+7",
 * 7.0); a value the integer rule refuses always is one: a JSON number
 * beyond the int range, a fraction, text with digits, a boolean. So the lock
 * refuses it for users it applies to (CHANGE_REFUSED), and for everyone else
 * the request keeps it, and the model's rule refuses it; nothing is written.
 *
 * Regression: on PHP 8.5 the cast warned for a JSON number beyond the int
 * range ("The float 1.0E+30 is not representable as an int, cast occurred"
 * in yii\db\ColumnSchema, from GatedInventoryLock::sameValue()), the error
 * handler turned the warning into an exception, and an admin's PUT
 * /api/v1/inventories/{id} {"approval_rule_id": 1e30} answered 500. Up to
 * PHP 8.4 the cast took a fraction or "7abc" for the stored 7, so the write
 * service put the stored value back in silence, and 1e999 for 0.
 */
class GatedInventoryLockNumbersTest extends DbTestCase
{
    use InventoryWriteFixtures;
    use TeamScopeFixtures;

    protected function tearDown(): void
    {
        $this->detachInventoryHandlers();
        parent::tearDown();
    }

    /**
     * JSON numbers beyond the int range, as the request's JSON parser
     * decodes them.
     *
     * @return array<string, array{0: string}>
     */
    public static function beyondTheIntRangeProvider(): array
    {
        return [
            '1e30' => ['1e30'],
            'one more than the largest int' => ['9223372036854775808'],
            '1e999, infinity' => ['1e999'],
            'minus 1e30' => ['-1e30'],
        ];
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testANumberBeyondTheIntRangeIsAChangeOfAnIntegerColumn(string $number): void
    {
        $value = self::decoded($number);
        $this->assertIsFloat($value, 'the parser gives a float');
        $inventory = $this->staticInventory((int)$this->inventoryAdmin()->id);
        foreach (['project_id' => 7, 'approval_rule_id' => null, 'parsed_at' => 1700000000] as $column => $stored) {
            $old = $inventory->getOldAttributes();
            $old[$column] = $stored;
            $original = $inventory->getAttribute($column);
            $inventory->setAttributes([$column => $value], false);

            $this->assertSame([$column], GatedInventoryLock::changedAttributes($inventory, $old), $column);
            $inventory->setAttributes([$column => $original], false);
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}> the value against the stored 7, and whether it is the same
     */
    public static function wholeNumberProvider(): array
    {
        return [
            'the int' => [7, true],
            'its digits' => ['7', true],
            'a leading zero' => ['07', true],
            'a plus sign' => ['+7', true],
            'a whole float' => [7.0, true],
            'another int' => [8, false],
            'a fraction' => [7.5, false],
            'decimal text' => ['7.0', false],
            'digits and text' => ['7abc', false],
            'a space before the digits' => [' 7', false],
            'exponent text' => ['7e0', false],
            'true' => [true, false],
            'false' => [false, false],
            'none' => [null, false],
        ];
    }

    /**
     * Up to now the type cast took a fraction, "7abc", "7e0", a space and
     * true (for 1) for the same ID.
     *
     * @dataProvider wholeNumberProvider
     */
    public function testAnIntegerColumnComparesWholeNumbers(mixed $value, bool $same): void
    {
        $inventory = $this->staticInventory((int)$this->inventoryAdmin()->id);
        $old = $inventory->getOldAttributes();
        $old['project_id'] = 7;
        $inventory->setAttributes(['project_id' => $value], false);

        $this->assertSame($same ? [] : ['project_id'], GatedInventoryLock::changedAttributes($inventory, $old));
    }

    /**
     * A stored value as the database driver returns it, digits included, is
     * the same as the model's int; none is the same as none.
     */
    public function testStoredDigitsAndNoneCompareAsBefore(): void
    {
        $inventory = $this->staticInventory((int)$this->inventoryAdmin()->id);
        $old = $inventory->getOldAttributes();
        $old['parsed_at'] = '1700000000';
        $inventory->setAttributes(['parsed_at' => 1700000000, 'project_id' => null], false);
        $old['project_id'] = '';

        $this->assertSame([], GatedInventoryLock::changedAttributes($inventory, $old));
    }

    /**
     * An admin's rule beyond the int range, through the write service as
     * the API and the web form call it: the model's error, nothing written,
     * nothing audited.
     *
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAnAdminsRuleBeyondTheIntRangeIsRefusedByTheModel(string $number): void
    {
        $admin = $this->inventoryAdmin();
        $inventory = $this->staticInventory((int)$admin->id);
        $before = $this->storedRow((int)$inventory->id);
        $audits = (int)AuditLog::find()->count();
        $inventory->name = 'numbers-renamed-' . uniqid('', true);

        $saved = (new InventoryWriteService())->update(
            $inventory,
            ['approval_rule_id' => self::decoded($number)],
            (int)$admin->id,
            InventoryWriteService::SOURCE_API
        );

        $this->assertFalse($saved);
        $this->assertSame(['Approval rule must be an integer.'], $inventory->getErrors('approval_rule_id'));
        $this->assertSame($before, $this->storedRow((int)$inventory->id), 'nothing was written');
        $this->assertSame($audits, (int)AuditLog::find()->count(), 'nothing was audited');
    }

    /**
     * For a user the lock applies to, a project beyond the int range is a
     * change of a gated inventory's hosts like any other value: refused
     * before validation, nothing written.
     *
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testTheLockRefusesAProjectBeyondTheIntRangeOnAGatedInventory(string $number): void
    {
        $operator = $this->inventoryOperator();
        $inventory = $this->gate($this->staticInventory((int)$operator->id), $this->createApprovalRule((int)$operator->id));
        $before = $this->storedRow((int)$inventory->id);
        $inventory->setAttributes(['project_id' => self::decoded($number)], false);

        $refusal = $this->refusalOf(fn () => (new InventoryWriteService())->update($inventory, [], (int)$operator->id, InventoryWriteService::SOURCE_WEB));

        $this->assertSame(GatedInventoryLock::CHANGE_REFUSED, $refusal->getMessage());
        $this->assertSame($before, $this->storedRow((int)$inventory->id), 'nothing was written');
    }

    /**
     * The stored rule decides whether the inventory is gated, also as the
     * driver returns it: digits of a positive ID are a rule, 0 and none are
     * none.
     *
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function storedRuleProvider(): array
    {
        return [
            'an ID' => [5, true],
            'its digits' => ['5', true],
            'zero' => [0, false],
            'zero as digits' => ['0', false],
            'none' => [null, false],
        ];
    }

    /**
     * @dataProvider storedRuleProvider
     */
    public function testAStoredRuleGatesTheInventory(mixed $stored, bool $gated): void
    {
        $inventory = $this->staticInventory((int)$this->inventoryAdmin()->id);
        $inventory->setOldAttribute('approval_rule_id', $stored);

        $this->assertSame($gated, GatedInventoryLock::isGated($inventory));
    }

    /**
     * The JSON number $number as the request's JSON parser decodes it.
     */
    private static function decoded(string $number): mixed
    {
        $body = json_decode('{"value": ' . $number . '}', true);
        self::assertIsArray($body);

        return $body['value'];
    }
}
