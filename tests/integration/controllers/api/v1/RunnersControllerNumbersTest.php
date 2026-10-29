<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\RunnersController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Runner;
use app\models\RunnerGroup;
use app\tests\integration\controllers\SendsJsonNumbers;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The target_group_id of POST /api/v1/runners/{id}/move. A value that is no
 * runner group ID, such as a JSON number beyond the int range, a fraction, a
 * boolean, text or a list, finds no group: 404 ("Target runner group not
 * found."), as an unknown ID; the runner stays where it is and nothing is
 * logged as a failure. A group ID, also as digits, moves the runner as
 * before. The bodies are JSON texts, decoded as the request's JSON parser
 * decodes them (SendsJsonNumbers).
 *
 * Regression: a JSON number beyond the int range answered 500 on PHP 8.5:
 * the (int) cast of the controller warned "The float 1.0E+30 is not
 * representable as an int, cast occurred", and the error handler turned the
 * warning into an exception. Up to PHP 8.4 the cast wrapped it to an
 * arbitrary group ID, and moved the runner into the group of a fraction's
 * whole part, or into group 1 for true and a list of one.
 */
class RunnersControllerNumbersTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    private const NOT_FOUND = 'Target runner group not found.';

    private RunnerGroup $source;

    private RunnerGroup $target;

    private Runner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUserWithRole('numbers-runners', 'admin');
        $this->source = $this->createRunnerGroup((int)$admin->id);
        $this->target = $this->createRunnerGroup((int)$admin->id);
        $this->runner = $this->createRunner((int)$this->source->id, (int)$admin->id);
        ['raw' => $raw] = ApiToken::generate((int)$admin->id, 'numbers-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAMoveToAGroupIdBeyondTheIntRangeFindsNoGroup(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $body = $this->sendJsonText('{"target_group_id": ' . $number . '}');
        $this->assertIsFloat($body['target_group_id'], 'the parser gives a float');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('move', ['id' => $this->runner->id]));

        $this->assertSame(404, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::NOT_FOUND]], $result);
        $this->assertSame((int)$this->source->id, Runner::findOne($this->runner->id)?->runner_group_id, 'the runner stays in its group');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Other values that are no group ID; %d is the target group's ID. Up to
     * now a fraction, a space and an exponent moved the runner into that
     * group, true and a list of one into group 1, text and false found no
     * group already.
     *
     * @return array<string, array{0: string}>
     */
    public static function noGroupIdProvider(): array
    {
        return [
            'fraction' => ['%d.5'],
            'decimal text' => ['"%d.5"'],
            'digits after a space' => ['" %d"'],
            'exponent text' => ['"%de0"'],
            'text' => ['"abc"'],
            'empty text' => ['""'],
            'true' => ['true'],
            'false' => ['false'],
            'a list of the ID' => ['[%d]'],
        ];
    }

    /**
     * @dataProvider noGroupIdProvider
     */
    public function testAMoveToAValueThatIsNoGroupIdFindsNoGroup(string $value): void
    {
        $this->sendJsonText('{"target_group_id": ' . sprintf($value, $this->target->id) . '}');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('move', ['id' => $this->runner->id]));

        $this->assertSame(404, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::NOT_FOUND]], $result);
        $this->assertSame((int)$this->source->id, Runner::findOne($this->runner->id)?->runner_group_id, 'the runner stays in its group');
        $this->assertSame([], $problems);
    }

    /**
     * What moves the runner as before; %d is the target group's ID.
     *
     * @return array<string, array{0: string}>
     */
    public static function groupIdProvider(): array
    {
        return [
            'the ID' => ['%d'],
            'the ID as digits' => ['"%d"'],
            'the ID with a leading zero' => ['"0%d"'],
            'the ID as a whole float' => ['%d.0'],
        ];
    }

    /**
     * @dataProvider groupIdProvider
     */
    public function testAMoveToAGroupIdMovesTheRunnerAsBefore(string $value): void
    {
        $this->sendJsonText('{"target_group_id": ' . sprintf($value, $this->target->id) . '}');

        $result = $this->api()->runAction('move', ['id' => $this->runner->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertIsArray($result);
        $this->assertSame((int)$this->target->id, $result['data']['runner_group_id'] ?? null);
        $this->assertSame((int)$this->target->id, Runner::findOne($this->runner->id)?->runner_group_id);
    }

    /**
     * The other answers stay: no target group, and the group the runner is in.
     */
    public function testAMissingAndTheSameGroupAreRefusedAsBefore(): void
    {
        $this->sendJsonText('{"target_group_id": null}');
        $this->assertSame(['error' => ['message' => 'target_group_id is required.']], $this->api()->runAction('move', ['id' => $this->runner->id]));
        $this->assertSame(422, \Yii::$app->response->statusCode);

        $this->sendJsonText('{"target_group_id": "' . $this->source->id . '"}');
        $this->assertSame(['error' => ['message' => 'Runner is already in that group.']], $this->api()->runAction('move', ['id' => $this->runner->id]));
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    // -- Helpers ------------------------------------------------------------------

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    private function api(): RunnersController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new RunnersController('api/v1/runners', $app);
    }
}
