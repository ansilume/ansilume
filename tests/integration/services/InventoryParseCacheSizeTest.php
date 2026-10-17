<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Inventory;
use app\services\AnsibleInventoryRunner;
use app\services\InventoryService;
use app\tests\integration\DbTestCase;
use yii\base\Event;
use yii\db\BaseActiveRecord;
use yii\db\Exception as DbException;

/**
 * How much of a parse InventoryService::resolveAndCache() keeps.
 *
 * inventory.parsed_hosts was TEXT (65,535 bytes), and the JSON of a parse
 * is longer than the inventory's content: for about 1,200 hosts, or for 150
 * hosts with group variables, which ansible-inventory --list copies to every
 * host. The save failed (SQLSTATE 22001, driver error 1406), and Parse
 * Inventory answered a server error. m000088 makes the column MEDIUMTEXT.
 * A parse longer than even MEDIUMTEXT holds, or one the column refuses as
 * too long, is returned to the page but not kept: PARSED_TOO_LARGE says so
 * instead, until the next parse.
 *
 * The runner is a stub, so no ansible-inventory process runs.
 */
class InventoryParseCacheSizeTest extends DbTestCase
{
    /** The most bytes a MEDIUMTEXT column stores. */
    private const MEDIUMTEXT_BYTES = 16777215;

    /** The driver's own text of a value too long for its column, as MariaDB words it. */
    private const TOO_LONG = "Data too long for column 'parsed_hosts' at row 1";

    /** @var list<\Closure> the handlers this test put on Inventory, taken off in tearDown() */
    private array $handlers = [];

    protected function tearDown(): void
    {
        foreach ($this->handlers as $handler) {
            Event::off(Inventory::class, BaseActiveRecord::EVENT_BEFORE_UPDATE, $handler);
        }
        $this->handlers = [];
        parent::tearDown();
    }

    /**
     * regression: the JSON of a parse longer than TEXT holds made the save
     * fail (SQLSTATE 22001, driver error 1406), so Parse Inventory answered
     * a server error for an inventory of a few thousand hosts, also one
     * whose content TEXT held. MEDIUMTEXT keeps it.
     */
    public function testRegressionAParseLongerThanTextWasNotKept(): void
    {
        $inventory = $this->inventory();
        $service = $this->service(StubInventoryRunner::withHosts(3000));

        $result = $service->resolveAndCache($inventory);

        $this->assertNull($result['error']);
        $this->assertCount(3000, $result['hosts']);
        $inventory->refresh();
        $this->assertGreaterThan(65535, strlen((string)$inventory->parsed_hosts), 'longer than TEXT holds');
        $this->assertNull($inventory->parsed_error);
        $cached = $service->getCached($inventory);
        $this->assertNotNull($cached);
        $this->assertNull($cached['error']);
        $this->assertSame($result['hosts'], $cached['hosts']);
        $this->assertSame($result['groups'], $cached['groups']);
    }

    /**
     * regression: a save that the column refused as too long (SQLSTATE
     * 22001, driver error 1406: here a column that is still TEXT) ended the
     * parse with a server error. Now the page gets the parse, nothing of it
     * is kept, and PARSED_TOO_LARGE says why. The error is recognised by its
     * codes: the message here does not say "too long".
     */
    public function testRegressionASaveRefusedAsTooLongEndedTheParse(): void
    {
        $inventory = $this->inventory();
        $saves = $this->watchSaves(['22001', 1406, self::TOO_LONG], 'The statement failed.');
        $before = time();

        $result = $this->service(StubInventoryRunner::withHosts(3000))->resolveAndCache($inventory);

        $this->assertNull($result['error'], 'the page gets the parse');
        $this->assertCount(3000, $result['hosts']);
        $this->assertCount(2, $saves, 'the refused save, then one without the hosts');
        $this->assertGreaterThan(65535, $saves[0]['bytes']);
        $this->assertSame(0, $saves[1]['bytes']);
        $inventory->refresh();
        $this->assertNull($inventory->parsed_hosts, 'nothing of the parse is kept');
        $this->assertGreaterThanOrEqual($before, (int)$inventory->parsed_at);
        $this->assertTooLargeIsKept($inventory, $saves[0]['bytes']);
    }

    /**
     * regression: a JSON longer than MEDIUMTEXT holds is not sent to the
     * database at all, where it would fail, larger than the packets the
     * server accepts: it is returned to the page, and PARSED_TOO_LARGE is
     * kept instead.
     */
    public function testRegressionAParseLongerThanMediumtextEndedTheParse(): void
    {
        $inventory = $this->inventory();
        $saves = $this->watchSaves();
        // One host whose variable alone is longer than MEDIUMTEXT holds.
        $output = '{"_meta":{"hostvars":{"web1":{"notes":"' . str_repeat('x', self::MEDIUMTEXT_BYTES) . '"}}},'
            . '"all":{"children":["ungrouped"]},"ungrouped":{"hosts":["web1"]}}';

        $result = $this->service(new StubInventoryRunner($output))->resolveAndCache($inventory);

        $this->assertNull($result['error'], 'the page gets the parse');
        $this->assertSame(['web1'], array_keys($result['hosts']));
        $this->assertSame([0], array_column($saves->getArrayCopy(), 'bytes'), 'one save, without the hosts');
        $inventory->refresh();
        $this->assertNull($inventory->parsed_hosts);
        $json = (string)json_encode(['groups' => $result['groups'], 'hosts' => $result['hosts'], 'notices' => $result['notices'] ?? []], JSON_UNESCAPED_SLASHES);
        $this->assertGreaterThan(self::MEDIUMTEXT_BYTES, strlen($json));
        $this->assertTooLargeIsKept($inventory, strlen($json));
    }

    /**
     * A parse that is too large to keep also replaces the cache of an
     * earlier parse: its hosts are not shown as the current ones.
     */
    public function testAParseTooLargeToKeepReplacesTheEarlierCache(): void
    {
        $inventory = $this->inventory();
        $this->service(StubInventoryRunner::withHosts(3))->resolveAndCache($inventory);
        $inventory->refresh();
        $this->assertStringContainsString('host-0003.example.com', (string)$inventory->parsed_hosts, 'the earlier parse is kept');
        $saves = $this->watchSaves(['22001', 1406, self::TOO_LONG], 'The statement failed.');

        $this->service(StubInventoryRunner::withHosts(3000))->resolveAndCache($inventory);

        $inventory->refresh();
        $this->assertNull($inventory->parsed_hosts);
        $this->assertTooLargeIsKept($inventory, $saves[0]['bytes']);
    }

    /**
     * @return array<string, array{0: array{0: string, 1: int, 2: string}}>
     */
    public static function otherDatabaseErrorProvider(): array
    {
        return [
            'a lock wait timeout, worded like a value too long' => [['HY000', 1205, self::TOO_LONG]],
            'a deadlock' => [['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction']],
        ];
    }

    /**
     * Any other database error is thrown as before, and nothing is kept:
     * the message, which says "too long" here, does not count.
     *
     * @dataProvider otherDatabaseErrorProvider
     * @param array{0: string, 1: int, 2: string} $errorInfo
     */
    public function testAnyOtherDatabaseErrorIsThrownAsBefore(array $errorInfo): void
    {
        $inventory = $this->inventory();
        $this->watchSaves($errorInfo, 'SQLSTATE[22001]: String data, right truncated: 1406 ' . self::TOO_LONG);

        try {
            $this->service(StubInventoryRunner::withHosts(3000))->resolveAndCache($inventory);
            $this->fail('expected the database error');
        } catch (DbException $e) {
            $this->assertSame($errorInfo, $e->errorInfo);
        }

        $inventory->refresh();
        $this->assertNull($inventory->parsed_at, 'nothing is kept');
        $this->assertNull($inventory->parsed_hosts);
        $this->assertNull($inventory->parsed_error);
    }

    /**
     * A failed parse keeps its error, as before.
     */
    public function testAParseErrorIsKeptAsBefore(): void
    {
        $inventory = $this->inventory();
        $error = 'ansible-inventory failed (exit 1): no inventory was parsed';

        $result = $this->service(StubInventoryRunner::failing($error))->resolveAndCache($inventory);

        $this->assertSame($error, $result['error']);
        $inventory->refresh();
        $this->assertNull($inventory->parsed_hosts);
        $this->assertSame($error, $inventory->parsed_error);
        $this->assertNotNull($inventory->parsed_at);
    }

    /**
     * regression (2.12.0, after the review): a parse error longer than
     * inventory.parsed_error holds (TEXT, 65,535 bytes), such as a long
     * stderr of ansible-inventory, made the save fail (SQLSTATE 22001,
     * driver error 1406), so Parse Inventory answered a server error. The
     * error is kept cut to what the column holds, between two characters
     * and ending in ERROR_CUT; whoever ran the parse gets it whole.
     */
    public function testRegressionAParseErrorLongerThanItsColumnEndedTheParse(): void
    {
        $inventory = $this->inventory();
        $error = 'ansible-inventory failed (exit 1): ' . str_repeat('é', 40000);

        $result = $this->service(StubInventoryRunner::failing($error))->resolveAndCache($inventory);

        $this->assertSame($error, $result['error'], 'whoever ran the parse gets the whole error');
        $inventory->refresh();
        $kept = (string)$inventory->parsed_error;
        $this->assertLessThanOrEqual(65535, strlen($kept));
        $this->assertGreaterThan(65000, strlen($kept), 'cut only as far as the column needs');
        $this->assertStringEndsWith(InventoryService::ERROR_CUT, $kept);
        $this->assertStringStartsWith(substr($kept, 0, -strlen(InventoryService::ERROR_CUT)), $error);
        $this->assertTrue(mb_check_encoding($kept, 'UTF-8'), 'cut between two characters');
        $this->assertNull($inventory->parsed_hosts);
        $this->assertNotNull($inventory->parsed_at);
    }

    // -- Helpers ----------------------------------------------------------------

    private function assertTooLargeIsKept(Inventory $inventory, int $bytes): void
    {
        $expected = sprintf(InventoryService::PARSED_TOO_LARGE, $bytes);
        $this->assertSame(
            "The parsed hosts and groups are too large to keep ({$bytes} bytes): parse the inventory again to see them.",
            $expected
        );
        $this->assertSame($expected, $inventory->parsed_error);
        $this->assertSame(['groups' => [], 'hosts' => [], 'error' => $expected, 'notices' => []], (new InventoryService())->getCached($inventory));
    }

    /**
     * A static inventory that was never parsed; the stub runner ignores its
     * content.
     */
    private function inventory(): Inventory
    {
        return $this->createInventory((int)$this->createUser('parse_size')->id);
    }

    private function service(AnsibleInventoryRunner $runner): InventoryService
    {
        $service = new InventoryService();
        $service->setRunner($runner);

        return $service;
    }

    /**
     * Records every save of an inventory: the bytes of its parsed_hosts and
     * its parsed_error. With $errorInfo, the first one fails with that
     * database error and $message, before any statement is sent.
     *
     * @param array{0: string, 1: int, 2: string}|null $errorInfo
     * @return \ArrayObject<int, array{bytes: int, error: string|null}>
     */
    private function watchSaves(?array $errorInfo = null, string $message = ''): \ArrayObject
    {
        /** @var \ArrayObject<int, array{bytes: int, error: string|null}> $saves */
        $saves = new \ArrayObject();
        $handler = static function (Event $event) use ($saves, $errorInfo, $message): void {
            $inventory = $event->sender;
            if (!$inventory instanceof Inventory) {
                return;
            }
            $saves[] = ['bytes' => strlen((string)$inventory->parsed_hosts), 'error' => $inventory->parsed_error];
            if ($errorInfo !== null && count($saves) === 1) {
                throw new DbException($message, $errorInfo);
            }
        };
        Event::on(Inventory::class, BaseActiveRecord::EVENT_BEFORE_UPDATE, $handler);
        $this->handlers[] = $handler;

        return $saves;
    }
}
