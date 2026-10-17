<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\InventoryController;
use app\services\InventoryService;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\StubInventoryRunner;
use yii\web\Response;

/**
 * Parse Inventory (InventoryController::actionParseHosts()) on an inventory
 * whose parse is longer than TEXT holds. The inventoryService component
 * gets a stub runner, so no ansible-inventory process runs.
 */
class InventoryParseHostsTest extends WebControllerTestCase
{
    use RestoresComponents;

    protected function setUp(): void
    {
        parent::setUp();
        $service = new InventoryService();
        $service->setRunner(StubInventoryRunner::withHosts(3000));
        $this->swapComponent('inventoryService', $service);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * regression: inventory.parsed_hosts was TEXT, so the parse of 3,000
     * hosts (more than 65,535 bytes of JSON) could not be kept: the save
     * failed with SQLSTATE 22001 (driver error 1406), and Parse Inventory
     * answered a server error instead of the hosts.
     */
    public function testRegressionParseInventoryOfALargeInventoryAnsweredAServerError(): void
    {
        $user = $this->createUser('parse_hosts');
        // No project: every signed-in user may see it.
        $inventory = $this->createInventory((int)$user->id);
        $this->loginAs($user);

        $response = (new InventoryController('inventory', \Yii::$app))->actionParseHosts((int)$inventory->id);

        $this->assertSame(Response::FORMAT_JSON, $response->format);
        $this->assertIsArray($response->data);
        $this->assertNull($response->data['error']);
        $this->assertIsArray($response->data['hosts']);
        $this->assertCount(3000, $response->data['hosts']);
        $this->assertSame(['ansible_host' => '10.0.11.184', 'role' => 'web'], $response->data['hosts']['host-3000.example.com']);
        $inventory->refresh();
        $this->assertGreaterThan(65535, strlen((string)$inventory->parsed_hosts), 'kept, though longer than TEXT holds');
        $this->assertNull($inventory->parsed_error);
    }
}
