<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\RunnerController;
use app\models\AuditLog;
use app\models\Runner;
use app\models\RunnerGroup;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * The group_id of POST /runner/create and the target_group_id of POST
 * /runner/move, sent as JSON by a signed-in admin (the request's JSON parser
 * serves the web forms too). A value that is no runner group ID, such as a
 * JSON number beyond the int range, a fraction, a boolean, text or a list,
 * finds no group, as an unknown ID: the danger flash "Runner group not
 * found." or "Target runner group not found.", no runner registered or
 * moved, nothing audited, nothing logged as a failure. A group ID, also as
 * digits, registers or moves as before. Through runAction(), so the access
 * filter and the verb filter run as for a real request.
 *
 * Regression: a JSON number beyond the int range answered 500 on PHP 8.5:
 * the (int) cast of the action warned "The float 1.0E+30 is not
 * representable as an int, cast occurred", and the error handler turned the
 * warning into an exception. Up to PHP 8.4 the cast wrapped it to an
 * arbitrary group ID, and registered or moved a runner in the group of a
 * fraction's whole part, or in group 1 for true and a list of one.
 */
class RunnerControllerNumbersTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    private const REGISTERED = 'numbers-registered-runner';

    private RunnerGroup $source;

    private RunnerGroup $target;

    private Runner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUserWithRole('numbers-runner-web', 'admin');
        $this->loginAs($admin);
        $this->source = $this->createRunnerGroup((int)$admin->id);
        $this->target = $this->createRunnerGroup((int)$admin->id);
        $this->runner = $this->createRunner((int)$this->source->id, (int)$admin->id);
        // Started here: a session component that starts within logged() warns that PHP's session of an earlier test is active.
        $this->session()->removeAllFlashes();
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testARegistrationInAGroupIdBeyondTheIntRangeFindsNoGroup(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $body = $this->sendJsonText($this->registration($number));
        $this->assertIsFloat($body['group_id'], 'the parser gives a float');

        $this->assertNoRegistration($auditBefore);
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAMoveToAGroupIdBeyondTheIntRangeFindsNoGroup(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"target_group_id": ' . $number . '}');

        $this->assertNoMove($auditBefore);
    }

    /**
     * Other values that are no group ID; %d is the ID of an existing group.
     * Up to now a fraction, a space, an exponent, true and a list of one
     * were taken as a group ID; text and false found no group already.
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
            'true' => ['true'],
            'false' => ['false'],
            'a list of the ID' => ['[%d]'],
        ];
    }

    /**
     * @dataProvider noGroupIdProvider
     */
    public function testARegistrationInAValueThatIsNoGroupIdFindsNoGroup(string $value): void
    {
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText($this->registration(sprintf($value, $this->target->id)));

        $this->assertNoRegistration($auditBefore);
    }

    /**
     * @dataProvider noGroupIdProvider
     */
    public function testAMoveToAValueThatIsNoGroupIdFindsNoGroup(string $value): void
    {
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"target_group_id": ' . sprintf($value, $this->target->id) . '}');

        $this->assertNoMove($auditBefore);
    }

    /**
     * What registers and moves as before; %d is the group's ID.
     *
     * @return array<string, array{0: string}>
     */
    public static function groupIdProvider(): array
    {
        return [
            'the ID' => ['%d'],
            'the ID as digits, as the form posts it' => ['"%d"'],
            'the ID with a leading zero' => ['"0%d"'],
            'the ID as a whole float' => ['%d.0'],
        ];
    }

    /**
     * @dataProvider groupIdProvider
     */
    public function testARunnerIsRegisteredAsBefore(string $value): void
    {
        $this->sendJsonText($this->registration(sprintf($value, $this->target->id)));
        $ctrl = $this->controller();

        $ctrl->runAction('create');

        $runner = Runner::findOne(['name' => self::REGISTERED]);
        $this->assertNotNull($runner);
        $this->assertSame((int)$this->target->id, $runner->runner_group_id);
        $this->assertSame(['/runner-group/view', 'id' => (int)$this->target->id], $ctrl->redirectedTo);
        $flash = $this->session()->getFlash('runner_token');
        $this->assertIsArray($flash, 'the token card shows the token once');
        $this->assertSame((int)$runner->id, Runner::findByToken((string)($flash['raw_token'] ?? ''))?->id);
    }

    /**
     * @dataProvider groupIdProvider
     */
    public function testARunnerIsMovedAsBefore(string $value): void
    {
        $this->sendJsonText('{"target_group_id": ' . sprintf($value, $this->target->id) . '}');
        $ctrl = $this->controller();

        $ctrl->runAction('move', ['id' => $this->runner->id]);

        $this->assertSame((int)$this->target->id, Runner::findOne($this->runner->id)?->runner_group_id);
        $this->assertSame(['/runner-group/view', 'id' => (int)$this->target->id], $ctrl->redirectedTo);
        $this->assertSame(
            ['success' => sprintf('Runner "%s" moved to group "%s".', $this->runner->name, $this->target->name)],
            $this->session()->getAllFlashes(true)
        );
    }

    public function testAMoveToTheGroupTheRunnerIsInIsRefusedAsBefore(): void
    {
        $this->sendJsonText('{"target_group_id": ' . $this->source->id . '}');
        $ctrl = $this->controller();

        $ctrl->runAction('move', ['id' => $this->runner->id]);

        $this->assertSame(['/runner-group/view', 'id' => (int)$this->source->id], $ctrl->redirectedTo);
        $this->assertSame(['warning' => 'Runner is already in that group.'], $this->session()->getAllFlashes(true));
    }

    // -- Helpers ------------------------------------------------------------------

    private function registration(string $groupId): string
    {
        return '{"group_id": ' . $groupId . ', "name": "' . self::REGISTERED . '", "description": ""}';
    }

    private function assertNoRegistration(int $auditBefore): void
    {
        $ctrl = $this->controller();

        [, $problems] = $this->logged(fn (): mixed => $ctrl->runAction('create'));

        $this->assertSame(['/runner-group/index'], $ctrl->redirectedTo);
        $this->assertSame(['danger' => 'Runner group not found.'], $this->session()->getAllFlashes(true));
        $this->assertNull(Runner::findOne(['name' => self::REGISTERED]), 'no runner was registered');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    private function assertNoMove(int $auditBefore): void
    {
        $ctrl = $this->controller();

        [, $problems] = $this->logged(fn (): mixed => $ctrl->runAction('move', ['id' => $this->runner->id]));

        $this->assertSame(['/runner-group/view', 'id' => (int)$this->source->id], $ctrl->redirectedTo);
        $this->assertSame(['danger' => 'Target runner group not found.'], $this->session()->getAllFlashes(true));
        $this->assertSame((int)$this->source->id, Runner::findOne($this->runner->id)?->runner_group_id, 'the runner stays in its group');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    private function session(): \yii\web\Session
    {
        $session = \Yii::$app->session;
        $this->assertInstanceOf(\yii\web\Session::class, $session);

        return $session;
    }

    /**
     * @return RunnerController&object{redirectedTo: mixed}
     */
    private function controller(): RunnerController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new class ('runner', $app) extends RunnerController {
            public mixed $redirectedTo = null;

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $this->redirectedTo = $url;

                return new Response();
            }
        };
    }
}
