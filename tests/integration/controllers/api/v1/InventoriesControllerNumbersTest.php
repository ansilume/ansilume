<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\InventoriesController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\User;
use app\tests\integration\controllers\SendsJsonNumbers;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The project_id of POST and PUT /api/v1/inventories. A value that is no
 * project ID, such as a JSON number beyond the int range, a fraction, a
 * boolean or text, answers 422 with the model's error for it, before the
 * access check reads it and before anything is written; nothing is logged
 * as a failure. A project ID, also as digits, is taken as before, and null
 * and "" still mean no project. The bodies are JSON texts, decoded as the
 * request's JSON parser decodes them (SendsJsonNumbers).
 *
 * Regression: a JSON number beyond the int range answered 500 on PHP 8.5:
 * the (int) cast of the controller warned "The float 1.0E+30 is not
 * representable as an int, cast occurred", and the error handler turned the
 * warning into an exception. Up to PHP 8.4 the cast wrapped it to an
 * arbitrary project ID, made 1e999 project 0, took a fraction as the project
 * of its whole part and true as project 1.
 */
class InventoriesControllerNumbersTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    /** The model's error for a project_id its integer rule refuses. */
    private const NOT_A_PROJECT_ID = 'Project Id must be an integer.';

    private const CREATED_NAME = 'numbers-created-inventory';

    private const CONTENT = "all:\n  hosts:\n    web1.example.com:\n";

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testACreateWithAProjectIdBeyondTheIntRangeIsRefused(string $number): void
    {
        $this->authenticate($this->createUserWithRole('numbers-inv-create', 'operator'));
        $auditBefore = $this->lastAuditId();
        $body = $this->sendJsonText($this->createBody($number));
        $this->assertIsFloat($body['project_id'], 'the parser gives a float');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('create'));

        $this->assertRefused($result);
        $this->assertNull(Inventory::findOne(['name' => self::CREATED_NAME]), 'no inventory was created');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAnUpdateWithAProjectIdBeyondTheIntRangeIsRefused(string $number): void
    {
        $operator = $this->createUserWithRole('numbers-inv-update', 'operator');
        $this->authenticate($operator);
        $projectId = (int)$this->createProject((int)$operator->id)->id;
        $inventory = $this->inventoryIn($projectId);
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"name": "numbers-renamed-inventory", "project_id": ' . $number . '}', 'PUT');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('update', ['id' => $inventory->id]));

        $this->assertRefused($result);
        $stored = Inventory::findOne($inventory->id);
        $this->assertNotNull($stored);
        $this->assertSame($projectId, $stored->project_id, 'the inventory stays in its project');
        $this->assertSame($inventory->name, $stored->name, 'nothing was saved');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Other values that are no project ID; %d is an existing project's ID.
     * Up to now a fraction was taken as the project of its whole part, true
     * as project 1, and text, false, a sign or a space before the digits
     * as project 0 or the digits' project.
     *
     * @return array<string, array{0: string}>
     */
    public static function noProjectIdProvider(): array
    {
        return [
            'fraction' => ['%d.5'],
            'decimal text' => ['"%d.5"'],
            'text' => ['"abc"'],
            'digits after a space' => ['" %d"'],
            'exponent text' => ['"%de0"'],
            'true' => ['true'],
            'false' => ['false'],
        ];
    }

    /**
     * @dataProvider noProjectIdProvider
     */
    public function testAnUpdateWithAValueThatIsNoProjectIdIsRefused(string $value): void
    {
        $operator = $this->createUserWithRole('numbers-inv-other', 'operator');
        $this->authenticate($operator);
        $from = (int)$this->createProject((int)$operator->id)->id;
        $to = (int)$this->createProject((int)$operator->id)->id;
        $inventory = $this->inventoryIn($from);
        $this->sendJsonText('{"project_id": ' . sprintf($value, $to) . '}', 'PUT');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('update', ['id' => $inventory->id]));

        $this->assertRefused($result);
        $this->assertSame($from, Inventory::findOne($inventory->id)?->project_id, 'the inventory stays in its project');
        $this->assertSame([], $problems);
    }

    /**
     * What is taken as before; %d is the project's ID. null and "" mean no
     * project.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function takenAsBeforeProvider(): array
    {
        return [
            'the ID' => ['%d', true],
            'the ID as digits' => ['"%d"', true],
            'the ID with a leading zero' => ['"0%d"', true],
            'the ID with a plus' => ['"+%d"', true],
            'the ID as a whole float' => ['%d.0', true],
            'null' => ['null', false],
            'empty text' => ['""', false],
        ];
    }

    /**
     * @dataProvider takenAsBeforeProvider
     */
    public function testACreateTakesAProjectIdAsBefore(string $value, bool $inTheProject): void
    {
        $operator = $this->createUserWithRole('numbers-inv-valid', 'operator');
        $this->authenticate($operator);
        $projectId = (int)$this->createProject((int)$operator->id)->id;
        $this->sendJsonText($this->createBody(sprintf($value, $projectId)));

        $result = $this->api()->runAction('create');

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $expected = $inTheProject ? $projectId : null;
        $this->assertIsArray($result);
        $this->assertIsArray($result['data'] ?? null);
        $this->assertArrayHasKey('project_id', $result['data']);
        $this->assertSame($expected, $result['data']['project_id']);
        $stored = Inventory::findOne(['name' => self::CREATED_NAME]);
        $this->assertNotNull($stored);
        $this->assertSame($expected, $stored->project_id);
    }

    /**
     * The normal answer of an update that moves an inventory: unchanged.
     */
    public function testAnUpdateMovesAnInventoryAsBefore(): void
    {
        $operator = $this->createUserWithRole('numbers-inv-move', 'operator');
        $this->authenticate($operator);
        $from = (int)$this->createProject((int)$operator->id)->id;
        $to = (int)$this->createProject((int)$operator->id)->id;
        $inventory = $this->inventoryIn($from);
        $this->sendJsonText('{"project_id": ' . $to . '}', 'PUT');

        $result = $this->api()->runAction('update', ['id' => $inventory->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertIsArray($result);
        $this->assertSame($to, $result['data']['project_id'] ?? null);
        $this->assertSame($to, Inventory::findOne($inventory->id)?->project_id);
    }

    /**
     * Bodies that answer 422 from a caller who may change the inventory: a
     * project_id that is no ID, and a list or an object (ApiBodyValues).
     *
     * @return array<string, array{0: string}>
     */
    public static function refusedBodyProvider(): array
    {
        return [
            'a fraction' => ['5.5'],
            'text' => ['"abc"'],
            'an empty list' => ['[]'],
            'an empty object' => ['{}'],
            'a list of an ID' => ['[5]'],
        ];
    }

    /**
     * The order the class docblock states: on PUT the stored inventory's
     * access check comes before those 422s, so a caller who may not change
     * the inventory gets 403 whatever the body holds, and nothing is written.
     *
     * @dataProvider refusedBodyProvider
     */
    public function testAnUpdateByACallerWhoMayNotChangeTheInventoryIs403First(string $value): void
    {
        $owner = (int)$this->createUser('numbers-inv-team-owner')->id;
        $project = (int)$this->createProject($owner)->id;
        $this->createTeamProject((int)$this->createTeam($owner)->id, $project);
        $inventory = $this->inventoryIn($project);
        $this->authenticate($this->createUserWithRole('numbers-inv-outsider', 'operator'));
        $before = Inventory::findOne($inventory->id)?->getAttributes();
        $this->sendJsonText('{"name": "numbers-renamed-inventory", "project_id": ' . $value . '}', 'PUT');

        $result = $this->api()->runAction('update', ['id' => $inventory->id]);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertSame($before, Inventory::findOne($inventory->id)?->getAttributes(), 'nothing was saved');
    }

    // -- Helpers ------------------------------------------------------------------

    private function createBody(string $projectId): string
    {
        return '{"name": "' . self::CREATED_NAME . '", "inventory_type": "static", "content": '
            . (string)json_encode(self::CONTENT) . ', "project_id": ' . $projectId . '}';
    }

    private function inventoryIn(int $projectId): Inventory
    {
        $inventory = $this->createInventory((int)$this->createUser('numbers-inv-owner')->id);
        $inventory->content = self::CONTENT;
        $inventory->project_id = $projectId;
        $inventory->save(false);

        return $inventory;
    }

    private function assertRefused(mixed $result): void
    {
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::NOT_A_PROJECT_ID]], $result);
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'numbers-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    private function api(): InventoriesController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new InventoriesController('api/v1/inventories', $app);
    }
}
