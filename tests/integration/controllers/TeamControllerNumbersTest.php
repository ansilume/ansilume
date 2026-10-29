<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\TeamController;
use app\models\AuditLog;
use app\models\Team;
use app\models\TeamMember;
use app\models\TeamProject;
use app\tests\integration\TeamScopeFixtures;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The user_id of POST /team/add-member and the project_id of POST
 * /team/add-project, sent as JSON by a signed-in admin (the request's JSON
 * parser serves the web forms too). A value that is no ID, such as a JSON
 * number beyond the int range, a fraction, a boolean, text or a list, is
 * answered as a missing one always was: 400 (TeamController::USER_ID_REQUIRED,
 * PROJECT_ID_REQUIRED), with nothing written, audited or flashed. An ID,
 * also as digits, is added as before. Through runAction(), so the access
 * filter and the verb filter run as for a real request.
 *
 * Regression: a JSON number beyond the int range answered 500 on PHP 8.5:
 * the (int) cast of the action warned "The float 1.0E+30 is not
 * representable as an int, cast occurred", and the error handler turned the
 * warning into an exception. Up to PHP 8.4 the cast wrapped it to an
 * arbitrary ID, whose insert then failed on the foreign key, and took a
 * fraction as the user or project of its whole part and true as #1.
 */
class TeamControllerNumbersTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUserWithRole('numbers-team-web', 'admin');
        $this->loginAs($admin);
        $this->team = $this->createTeam((int)$admin->id);
        // Started here: a session component that starts within logged() warns that PHP's session of an earlier test is active.
        $this->session()->removeAllFlashes();
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAMemberWhoseUserIdIsBeyondTheIntRangeIsABadRequest(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $body = $this->sendJsonText('{"user_id": ' . $number . '}');
        $this->assertIsFloat($body['user_id'], 'the parser gives a float');

        $this->assertBadRequest('add-member', 'user_id required.');
        $this->assertSame('user_id required.', TeamController::USER_ID_REQUIRED);
        $this->assertNothingWritten($auditBefore);
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAProjectWhoseIdIsBeyondTheIntRangeIsABadRequest(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"project_id": ' . $number . ', "role": "operator"}');

        $this->assertBadRequest('add-project', 'project_id required.');
        $this->assertSame('project_id required.', TeamController::PROJECT_ID_REQUIRED);
        $this->assertNothingWritten($auditBefore);
    }

    /**
     * Other values that are no ID; %d is the ID of an existing user or
     * project. Up to now a fraction, a space, an exponent, true and a list
     * of one were taken as an ID; text, false and a missing value were
     * refused already.
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
    public function testAMemberWhoseUserIdIsNoIdIsABadRequest(string $value): void
    {
        $user = $this->createUser('numbers-team-web-member');
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText($value === '' ? '{}' : '{"user_id": ' . sprintf($value, $user->id) . '}');

        $this->assertBadRequest('add-member', TeamController::USER_ID_REQUIRED);
        $this->assertNothingWritten($auditBefore);
    }

    /**
     * @dataProvider noIdProvider
     */
    public function testAProjectWhoseIdIsNoIdIsABadRequest(string $value): void
    {
        $project = $this->createProject((int)$this->team->created_by);
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText($value === '' ? '{}' : '{"project_id": ' . sprintf($value, $project->id) . '}');

        $this->assertBadRequest('add-project', TeamController::PROJECT_ID_REQUIRED);
        $this->assertNothingWritten($auditBefore);
    }

    /**
     * What is added as before, as JSON and as the form posts it; %d is the
     * ID.
     *
     * @return array<string, array{0: string}>
     */
    public static function idProvider(): array
    {
        return [
            'the ID' => ['%d'],
            'the ID as digits, as the form posts it' => ['"%d"'],
            'the ID with a leading zero' => ['"0%d"'],
            'the ID as a whole float' => ['%d.0'],
        ];
    }

    /**
     * @dataProvider idProvider
     */
    public function testAMemberIsAddedAsBefore(string $value): void
    {
        $user = $this->createUser('numbers-team-web-member');
        $this->sendJsonText('{"user_id": ' . sprintf($value, $user->id) . '}');
        $ctrl = $this->controller();

        $ctrl->runAction('add-member', ['id' => $this->team->id]);

        $this->assertSame(['view', 'id' => (int)$this->team->id], $ctrl->redirectedTo);
        $this->assertSame(['success' => 'Member added.'], $this->session()->getAllFlashes(true));
        $this->assertTrue(TeamMember::find()->where(['team_id' => $this->team->id, 'user_id' => $user->id])->exists());
        $entry = AuditLog::findOne(['action' => AuditLog::ACTION_TEAM_MEMBER_ADDED, 'object_id' => $this->team->id]);
        $this->assertNotNull($entry, 'the addition is audited');
        $this->assertSame(['user_id' => (int)$user->id], json_decode((string)$entry->metadata, true));
    }

    /**
     * @dataProvider idProvider
     */
    public function testAProjectIsAssignedAsBefore(string $value): void
    {
        $project = $this->createProject((int)$this->team->created_by);
        $this->sendJsonText('{"project_id": ' . sprintf($value, $project->id) . ', "role": "operator"}');
        $ctrl = $this->controller();

        $ctrl->runAction('add-project', ['id' => $this->team->id]);

        $this->assertSame(['view', 'id' => (int)$this->team->id], $ctrl->redirectedTo);
        $this->assertSame(['success' => 'Project access granted.'], $this->session()->getAllFlashes(true));
        $assigned = TeamProject::findOne(['team_id' => $this->team->id, 'project_id' => $project->id]);
        $this->assertNotNull($assigned);
        $this->assertSame(TeamProject::ROLE_OPERATOR, $assigned->role);
    }

    // -- Helpers ------------------------------------------------------------------

    private function assertBadRequest(string $action, string $message): void
    {
        [$refusal, $problems] = $this->logged(function () use ($action): ?BadRequestHttpException {
            try {
                $this->controller()->runAction($action, ['id' => $this->team->id]);
            } catch (BadRequestHttpException $e) {
                return $e;
            }

            return null;
        });

        $this->assertInstanceOf(BadRequestHttpException::class, $refusal, 'answered 400');
        $this->assertSame($message, $refusal->getMessage());
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    private function assertNothingWritten(int $auditBefore): void
    {
        $this->assertSame(0, (int)TeamMember::find()->where(['team_id' => $this->team->id])->count(), 'no member was added');
        $this->assertSame(0, (int)TeamProject::find()->where(['team_id' => $this->team->id])->count(), 'no project was assigned');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $this->session()->getAllFlashes(), 'nothing was flashed');
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
     * @return TeamController&object{redirectedTo: mixed}
     */
    private function controller(): TeamController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new class ('team', $app) extends TeamController {
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
