<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\JobInventory;
use app\models\Job;
use PHPUnit\Framework\TestCase;
use yii\log\Logger;

/**
 * JobInventory without the database: the inventory ID a decoded runner
 * payload records, labels and API fields, and that a payload without an
 * inventory costs no query. The reads of inventory rows are tested in
 * tests/integration/components/JobInventoryTest.php.
 */
class JobInventoryTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: int|null}>
     */
    public static function payloadProvider(): array
    {
        return [
            'a positive int' => [['inventory_id' => 5], 5],
            'a string of digits' => [['inventory_id' => '12'], 12],
            'digits with leading zeros' => [['inventory_id' => '007'], 7],
            'zero' => [['inventory_id' => 0], null],
            'the string zero' => [['inventory_id' => '0'], null],
            'a negative int' => [['inventory_id' => -3], null],
            'a negative string' => [['inventory_id' => '-3'], null],
            'true' => [['inventory_id' => true], null],
            'false' => [['inventory_id' => false], null],
            'a float' => [['inventory_id' => 5.0], null],
            'a decimal string' => [['inventory_id' => '5.0'], null],
            'a padded string' => [['inventory_id' => ' 5'], null],
            'an empty string' => [['inventory_id' => ''], null],
            'digits and letters' => [['inventory_id' => '5a'], null],
            'a list' => [['inventory_id' => [5]], null],
            'null' => [['inventory_id' => null], null],
            'no inventory_id' => [['template_id' => 5], null],
            'an empty payload' => [[], null],
        ];
    }

    /**
     * @dataProvider payloadProvider
     *
     * @param array<string, mixed> $payload
     */
    public function testIdOfReadsAPositiveIdOnly(array $payload, ?int $expected): void
    {
        $this->assertSame($expected, JobInventory::idOf($payload));
    }

    public function testNoneHasNothing(): void
    {
        $none = JobInventory::none();

        $this->assertNull($none->id);
        $this->assertNull($none->name);
        $this->assertFalse($none->exists);
        $this->assertSame('—', $none->label());
        $this->assertSame(JobInventory::NONE, $none->label());
        $this->assertSame(['inventory_id' => null, 'inventory_name' => null], $none->apiFields());
    }

    public function testTheLabelIsTheNameElseTheIdElseNone(): void
    {
        $named = $this->make(17, 'web-hosts', true);
        $unnamed = $this->make(17, null, false);

        $this->assertSame('web-hosts', $named->label());
        $this->assertSame('Inventory #17', $unnamed->label());
        $this->assertSame(sprintf(JobInventory::UNNAMED, 17), $unnamed->label());
        $this->assertSame(['inventory_id' => 17, 'inventory_name' => 'web-hosts'], $named->apiFields());
        $this->assertSame(['inventory_id' => 17, 'inventory_name' => null], $unnamed->apiFields());
        $this->assertSame('(deleted)', JobInventory::DELETED);
    }

    /**
     * A name is given back as stored: the pages encode it.
     */
    public function testTheLabelKeepsTheNameAsStored(): void
    {
        $name = '<img src=x onerror=alert(1)> "q" \'a\'';

        $this->assertSame($name, $this->make(3, $name, true)->label());
    }

    public function testNoJobAndPayloadsWithoutAnInventoryCostNoQuery(): void
    {
        $results = [];

        $queries = $this->queriesOf(static function () use (&$results): void {
            $results['no job'] = JobInventory::ofJob(null);
            $results['payloads'] = JobInventory::ofPayloads([
                4 => [],
                9 => ['template_id' => 2, Job::PAYLOAD_INVENTORY_NAME => 'named, but without an ID'],
                12 => ['inventory_id' => 0],
            ]);
            $results['empty'] = JobInventory::ofPayloads([]);
        });

        $this->assertSame([], $queries);
        $this->assertEquals(JobInventory::none(), $results['no job']);
        $this->assertSame([4, 9, 12], array_keys($results['payloads']), 'keyed as given, by job ID');
        foreach ($results['payloads'] as $inventory) {
            $this->assertEquals(JobInventory::none(), $inventory);
        }
        $this->assertSame([], $results['empty']);
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * A JobInventory as ofPayloads() makes it, through its private
     * constructor.
     */
    private function make(?int $id, ?string $name, bool $exists): JobInventory
    {
        $factory = \Closure::bind(
            static fn (?int $id, ?string $name, bool $exists): JobInventory => new JobInventory($id, $name, $exists),
            null,
            JobInventory::class
        );
        $this->assertInstanceOf(\Closure::class, $factory);

        return $factory($id, $name, $exists);
    }

    /**
     * The database messages $action logs.
     *
     * @return list<string>
     */
    private function queriesOf(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $queries = [];
        foreach ($logger->messages as $message) {
            if (str_starts_with((string)$message[2], 'yii\db\\')) {
                $queries[] = is_string($message[0]) ? $message[0] : get_debug_type($message[0]);
            }
        }

        return $queries;
    }
}
