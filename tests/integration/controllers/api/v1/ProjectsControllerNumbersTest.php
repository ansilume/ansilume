<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ProjectsController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Project;
use app\models\User;
use app\tests\integration\controllers\SendsJsonNumbers;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The scm_credential_id of POST and PUT /api/v1/projects reaches the model
 * as sent. A value that is no credential ID, such as a JSON number beyond
 * the int range, a fraction, a boolean or text, answers 422 with the
 * model's error, before anything is written; nothing is logged as a
 * failure. A credential ID, also as digits, is taken as before, and null
 * means no credential; "" too now, as the form's empty choice. The bodies
 * are JSON texts, decoded as the request's JSON parser decodes them
 * (SendsJsonNumbers).
 *
 * Regression: a JSON number beyond the int range answered 500 on PHP 8.5:
 * the (int) cast of the controller warned "The float 1.0E+30 is not
 * representable as an int, cast occurred", and the error handler turned the
 * warning into an exception. Up to PHP 8.4 the cast wrapped it to an
 * arbitrary credential ID ("Scm Credential Id is invalid."), took a fraction
 * as the credential of its whole part and true as credential 1.
 */
class ProjectsControllerNumbersTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    /** The model's error for a scm_credential_id its integer rule refuses. */
    private const NOT_A_CREDENTIAL_ID = 'Scm Credential Id must be an integer.';

    private const CREATED_NAME = 'numbers-created-project';

    private User $operator;

    private int $credentialId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = $this->createUserWithRole('numbers-project', 'operator');
        $this->credentialId = (int)$this->createCredential((int)$this->operator->id)->id;
        ['raw' => $raw] = ApiToken::generate((int)$this->operator->id, 'numbers-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testACreateWithACredentialIdBeyondTheIntRangeIsRefused(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $body = $this->sendJsonText($this->createBody($number));
        $this->assertIsFloat($body['scm_credential_id'], 'the parser gives a float');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('create'));

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::NOT_A_CREDENTIAL_ID]], $result);
        $this->assertNull(Project::findOne(['name' => self::CREATED_NAME]), 'no project was created');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAnUpdateWithACredentialIdBeyondTheIntRangeIsRefused(string $number): void
    {
        $project = $this->projectWithTheCredential();
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"name": "numbers-renamed-project", "scm_credential_id": ' . $number . '}', 'PUT');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('update', ['id' => $project->id]));

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::NOT_A_CREDENTIAL_ID]], $result);
        $stored = Project::findOne($project->id);
        $this->assertNotNull($stored);
        $this->assertSame($this->credentialId, $stored->scm_credential_id, 'the project keeps its credential');
        $this->assertSame($project->name, $stored->name, 'nothing was saved');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Other values that are no credential ID; %d is a credential's ID. Up
     * to now a fraction, a space or an exponent was taken as that
     * credential, true as credential 1, and text and false were looked up
     * as credential 0 ("Scm Credential Id is invalid.").
     *
     * @return array<string, array{0: string}>
     */
    public static function noCredentialIdProvider(): array
    {
        return [
            'fraction' => ['%d.5'],
            'decimal text' => ['"%d.5"'],
            'digits after a space' => ['" %d"'],
            'exponent text' => ['"%de0"'],
            'text' => ['"abc"'],
            'true' => ['true'],
            'false' => ['false'],
        ];
    }

    /**
     * @dataProvider noCredentialIdProvider
     */
    public function testAnUpdateWithAValueThatIsNoCredentialIdIsRefused(string $value): void
    {
        $project = $this->projectWithTheCredential();
        $other = (int)$this->createCredential((int)$this->operator->id)->id;
        $this->sendJsonText('{"scm_credential_id": ' . sprintf($value, $other) . '}', 'PUT');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('update', ['id' => $project->id]));

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::NOT_A_CREDENTIAL_ID]], $result);
        $this->assertSame($this->credentialId, Project::findOne($project->id)?->scm_credential_id, 'the project keeps its credential');
        $this->assertSame([], $problems);
    }

    /**
     * What is taken as before; %d is the credential's ID. null is no
     * credential.
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
        ];
    }

    /**
     * @dataProvider takenAsBeforeProvider
     */
    public function testACreateTakesACredentialIdAsBefore(string $value, bool $withTheCredential): void
    {
        $this->sendJsonText($this->createBody(sprintf($value, $this->credentialId)));

        $result = $this->api()->runAction('create');

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $stored = Project::findOne(['name' => self::CREATED_NAME]);
        $this->assertNotNull($stored);
        $this->assertSame($withTheCredential ? $this->credentialId : null, $stored->scm_credential_id);
    }

    /**
     * "" is no credential, as the empty choice of the project form: up to
     * now it was looked up as credential 0 and answered 422 ("Scm
     * Credential Id is invalid.").
     */
    public function testAnEmptyCredentialIdIsNoCredential(): void
    {
        $project = $this->projectWithTheCredential();
        $this->sendJsonText('{"scm_credential_id": ""}', 'PUT');

        $result = $this->api()->runAction('update', ['id' => $project->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertNull(Project::findOne($project->id)?->scm_credential_id);
    }

    public function testAnUpdateChangesTheCredentialAsBefore(): void
    {
        $project = $this->projectWithTheCredential();
        $other = (int)$this->createCredential((int)$this->operator->id)->id;
        $this->sendJsonText('{"scm_credential_id": ' . $other . '}', 'PUT');

        $result = $this->api()->runAction('update', ['id' => $project->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame($other, Project::findOne($project->id)?->scm_credential_id);
    }

    // -- Helpers ------------------------------------------------------------------

    private function createBody(string $credentialId): string
    {
        return '{"name": "' . self::CREATED_NAME . '", "scm_type": "manual", "scm_credential_id": ' . $credentialId . '}';
    }

    /**
     * A manual project of no team, with the credential of setUp().
     */
    private function projectWithTheCredential(): Project
    {
        $project = $this->createProject((int)$this->operator->id);
        $project->scm_credential_id = $this->credentialId;
        $project->save(false);

        return $project;
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    private function api(): ProjectsController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new ProjectsController('api/v1/projects', $app);
    }
}
