<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\TeamsController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
use app\models\User;
use app\tests\integration\controllers\SendsJsonNumbers;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The user_id of POST /api/v1/teams/{id}/members and the project_id of
 * POST /api/v1/teams/{id}/projects. A value that is no ID, such as a JSON
 * number beyond the int range, a fraction, a boolean, text or a list, is
 * answered as a missing or non-numeric one always was: 404 for user #0 or
 * project #0, which does not exist (TeamsController::USER_NOT_FOUND,
 * PROJECT_NOT_FOUND); nothing is written and nothing is logged as a
 * failure. An ID, also as digits, is taken as before. The bodies are JSON
 * texts, decoded as the request's JSON parser decodes them
 * (SendsJsonNumbers).
 *
 * Regression: a JSON number beyond the int range answered 500 on PHP 8.5:
 * the (int) cast of the controller warned "The float 1.0E+30 is not
 * representable as an int, cast occurred", and the error handler turned the
 * warning into an exception. Up to PHP 8.4 the cast wrapped it to an
 * arbitrary ID, made 1e999 0, and took a fraction as the user or project of
 * its whole part, and true and a list of one as user or project 1.
 */
class TeamsControllerNumbersTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    private User $admin;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('numbers-teams', 'admin');
        $this->team = $this->createTeam((int)$this->admin->id);
        ['raw' => $raw] = ApiToken::generate((int)$this->admin->id, 'numbers-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAMemberWhoseUserIdIsBeyondTheIntRangeIsNotFound(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $body = $this->sendJsonText('{"user_id": ' . $number . '}');
        $this->assertIsFloat($body['user_id'], 'the parser gives a float');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('add-member', ['id' => $this->team->id]));

        $this->assertSame(404, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'User #0 not found.']], $result);
        $this->assertSame(['error' => ['message' => sprintf(TeamsController::USER_NOT_FOUND, 0)]], $result);
        $this->assertSame(0, (int)TeamMember::find()->where(['team_id' => $this->team->id])->count(), 'no member was added');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAProjectWhoseIdIsBeyondTheIntRangeIsNotFound(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"project_id": ' . $number . ', "role": "operator"}');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('add-project', ['id' => $this->team->id]));

        $this->assertSame(404, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Project #0 not found.']], $result);
        $this->assertSame(['error' => ['message' => sprintf(TeamsController::PROJECT_NOT_FOUND, 0)]], $result);
        $this->assertSame(0, (int)TeamProject::find()->where(['team_id' => $this->team->id])->count(), 'no project was assigned');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Other values that are no ID; %d is the ID of an existing user or
     * project. Up to now a fraction, a space, an exponent, true and a list
     * of one were taken as an ID, text and false as #0.
     *
     * @return array<string, array{0: string}>
     */
    public static function noIdProvider(): array
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
            'missing' => [''],
        ];
    }

    /**
     * @dataProvider noIdProvider
     */
    public function testAMemberWhoseUserIdIsNoIdIsNotFound(string $value): void
    {
        $user = $this->createUser('numbers-teams-member');
        $this->sendJsonText($value === '' ? '{}' : '{"user_id": ' . sprintf($value, $user->id) . '}');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('add-member', ['id' => $this->team->id]));

        $this->assertSame(404, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'User #0 not found.']], $result);
        $this->assertSame(0, (int)TeamMember::find()->where(['team_id' => $this->team->id])->count(), 'no member was added');
        $this->assertSame([], $problems);
    }

    /**
     * @dataProvider noIdProvider
     */
    public function testAProjectWhoseIdIsNoIdIsNotFound(string $value): void
    {
        $project = $this->createProject((int)$this->admin->id);
        $this->sendJsonText($value === '' ? '{}' : '{"project_id": ' . sprintf($value, $project->id) . '}');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('add-project', ['id' => $this->team->id]));

        $this->assertSame(404, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Project #0 not found.']], $result);
        $this->assertSame(0, (int)TeamProject::find()->where(['team_id' => $this->team->id])->count(), 'no project was assigned');
        $this->assertSame([], $problems);
    }

    /**
     * What is taken as before; %d is the ID.
     *
     * @return array<string, array{0: string}>
     */
    public static function idProvider(): array
    {
        return [
            'the ID' => ['%d'],
            'the ID as digits' => ['"%d"'],
            'the ID with a leading zero' => ['"0%d"'],
            'the ID as a whole float' => ['%d.0'],
        ];
    }

    /**
     * @dataProvider idProvider
     */
    public function testAMemberIsAddedAsBefore(string $value): void
    {
        $user = $this->createUser('numbers-teams-member');
        $this->sendJsonText('{"user_id": ' . sprintf($value, $user->id) . '}');

        $result = $this->api()->runAction('add-member', ['id' => $this->team->id]);

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertTrue(TeamMember::find()->where(['team_id' => $this->team->id, 'user_id' => $user->id])->exists());
        $this->assertTrue(
            AuditLog::find()->where(['action' => AuditLog::ACTION_TEAM_MEMBER_ADDED, 'object_id' => $this->team->id])->exists(),
            'the addition is audited'
        );
    }

    /**
     * @dataProvider idProvider
     */
    public function testAProjectIsAssignedAsBefore(string $value): void
    {
        $project = $this->createProject((int)$this->admin->id);
        $this->sendJsonText('{"project_id": ' . sprintf($value, $project->id) . ', "role": "operator"}');

        $result = $this->api()->runAction('add-project', ['id' => $this->team->id]);

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $assigned = TeamProject::findOne(['team_id' => $this->team->id, 'project_id' => $project->id]);
        $this->assertNotNull($assigned);
        $this->assertSame(TeamProject::ROLE_OPERATOR, $assigned->role);
    }

    /**
     * An unknown ID is not found, with the ID, as before.
     */
    public function testAnUnknownIdIsNotFoundAsBefore(): void
    {
        $this->sendJsonText('{"user_id": 2147483647}');

        $result = $this->api()->runAction('add-member', ['id' => $this->team->id]);

        $this->assertSame(404, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'User #2147483647 not found.']], $result);
    }

    // -- Helpers ------------------------------------------------------------------

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    private function api(): TeamsController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new TeamsController('api/v1/teams', $app);
    }
}
