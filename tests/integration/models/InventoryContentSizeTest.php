<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\components\DbErrorSummary;
use app\models\Inventory;
use app\tests\integration\DbTestCase;

/**
 * The content of a static inventory that the form accepts fits its column:
 * 262,144 characters (Inventory's string rule), multibyte ones included,
 * are stored whole; one more is a validation error.
 *
 * The model twin of
 * AddInventoryApprovalRulesMigrationTest::testRegressionContentTheFormAcceptsDidNotFitTheColumn:
 * on a schema before m000088, where inventory.content is TEXT (65,535
 * bytes), the round trip fails with SQLSTATE 22001.
 */
class InventoryContentSizeTest extends DbTestCase
{
    /** The most characters Inventory's string rule accepts as content. */
    private const MAX_CHARACTERS = 262144;

    /**
     * regression: the form accepted 262,144 characters, but the TEXT column
     * held 65,535 bytes, so a longer static inventory failed with a server
     * error (SQLSTATE 22001) instead of being saved.
     */
    public function testRegressionTheLongestContentTheFormAcceptsIsStoredWhole(): void
    {
        $content = $this->yamlOfCharacters(self::MAX_CHARACTERS);
        $this->assertSame(self::MAX_CHARACTERS, mb_strlen($content, 'UTF-8'));
        $this->assertGreaterThan(65535, strlen($content), 'more bytes than TEXT holds');
        $inventory = $this->inventoryWith($content);

        try {
            $saved = $inventory->save();
        } catch (\yii\db\Exception $e) {
            $this->fail(sprintf('The content was not stored: %s (SQLSTATE %s, driver error %s).', ...DbErrorSummary::of($e)));
        }

        $this->assertTrue($saved, implode(' ', $inventory->getFirstErrors()));
        $stored = Inventory::findOne($inventory->id);
        $this->assertInstanceOf(Inventory::class, $stored);
        $this->assertSame(strlen($content), strlen((string)$stored->content));
        $this->assertTrue($content === $stored->content, 'the content comes back unchanged');
    }

    public function testOneCharacterMoreIsRefusedByTheForm(): void
    {
        $content = $this->yamlOfCharacters(self::MAX_CHARACTERS + 1);
        $inventory = $this->inventoryWith($content);
        $before = (int)Inventory::find()->count();

        $this->assertFalse($inventory->save());

        $this->assertSame(['Content should contain at most 262,144 characters.'], $inventory->getErrors('content'));
        $this->assertSame($before, (int)Inventory::find()->count(), 'nothing is stored');
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * A static inventory as YAML of exactly $characters characters, most of
     * them two bytes long in UTF-8.
     */
    private function yamlOfCharacters(int $characters): string
    {
        $prefix = "all:\n  vars:\n    note: ";

        return $prefix . str_repeat('ä', $characters - mb_strlen($prefix, 'UTF-8'));
    }

    private function inventoryWith(string $content): Inventory
    {
        $inventory = new Inventory();
        $inventory->name = 'long-content-' . uniqid('', true);
        $inventory->inventory_type = Inventory::TYPE_STATIC;
        $inventory->content = $content;
        $inventory->created_by = (int)$this->createUser('long_content')->id;

        return $inventory;
    }
}
