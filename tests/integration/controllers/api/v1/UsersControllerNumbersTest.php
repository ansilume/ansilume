<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\UsersController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\User;
use app\services\RoleGrantPolicy;
use app\services\UserChangePolicy;
use app\tests\integration\controllers\SendsJsonNumbers;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The status of POST and PUT /api/v1/users. A status that is no whole
 * number, such as a JSON number beyond the int range, a fraction, a
 * boolean, null or text, answers 422 (UsersController::INVALID_STATUS, the
 * model's error for a status it does not know) before anything is written;
 * nothing is logged as a failure. A whole number, also as digits with a
 * sign or leading zeros, reaches the model's rule as before: 0 deactivates,
 * 10 activates, any other number answers the same 422. The bodies are JSON
 * texts, decoded as the request's JSON parser decodes them
 * (SendsJsonNumbers).
 *
 * The refusal comes where the order lists of POST and PUT in
 * web/openapi.yaml name it: after the refusal of a user the caller may not
 * change (UserChangePolicy, first after the 404: CLAUDE.md security rule
 * 14), the 422s of auth_source and of an unknown role and the 403 of a role
 * the caller may not assign; before the 403 of is_superadmin from a caller
 * who is no superadmin, the 422s of the only active superadmin and the 422s
 * of the password.
 *
 * Regression: a JSON number beyond the int range answered 500 on PHP 8.5:
 * the (int) cast of the controller warned "The float 1.0E+30 is not
 * representable as an int, cast occurred", and the error handler turned the
 * warning into an exception. Up to PHP 8.4 the cast made 1e999, false, null,
 * "" and text status 0 and deactivated the user, and took 10.5 as 10; so
 * such a status, a valid 0, let the checks after it answer instead.
 */
class UsersControllerNumbersTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    private const CREATED_NAME = 'numbers-created-user';

    /** The password of the users the tests create; a test value, not a secret. */
    private const PASSWORD = 'numbers-password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn($this->createUserWithRole('numbers-users', 'admin'));
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAnUpdateWithAStatusBeyondTheIntRangeIsRefused(string $number): void
    {
        $user = $this->createUserWithRole('numbers-users-target', 'viewer');
        $auditBefore = $this->lastAuditId();
        $body = $this->sendJsonText('{"email": "numbers-renamed@example.com", "status": ' . $number . '}', 'PUT');
        $this->assertIsFloat($body['status'], 'the parser gives a float');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('update', ['id' => $user->id]));

        $this->assertRefused($result);
        $stored = User::findOne($user->id);
        $this->assertNotNull($stored);
        $this->assertSame(User::STATUS_ACTIVE, $stored->status, 'the user stays active');
        $this->assertSame($user->email, $stored->email, 'nothing was saved');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testACreateWithAStatusBeyondTheIntRangeIsRefused(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText($this->createBody($number));

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('create'));

        $this->assertRefused($result);
        $this->assertNull(User::findOne(['username' => self::CREATED_NAME]), 'no user was created');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Other values that are no whole number. Up to now false, null, "",
     * text and " 0" deactivated the user, 10.5 and "10.5" kept it active;
     * true, as 1, was refused already. The floats whose text at PHP's
     * precision of 14 digits reads as a whole number are a regression of
     * their own: 0.999999999999999 (text "1") was 0 and deactivated the
     * user with 200, 9.99999999999999 (text "10") was 9.
     *
     * @return array<string, array{0: string}>
     */
    public static function noWholeNumberProvider(): array
    {
        return [
            'fraction' => ['10.5'],
            'a fraction just below 1, whose text reads "1"' => ['0.999999999999999'],
            'a fraction just below 10, whose text reads "10"' => ['9.99999999999999'],
            'decimal text' => ['"10.5"'],
            'digits after a space' => ['" 0"'],
            'text' => ['"abc"'],
            'empty text' => ['""'],
            'true' => ['true'],
            'false' => ['false'],
            'null' => ['null'],
        ];
    }

    /**
     * @dataProvider noWholeNumberProvider
     */
    public function testAnUpdateWithAStatusThatIsNoWholeNumberIsRefused(string $value): void
    {
        $user = $this->createUserWithRole('numbers-users-target', 'viewer');
        $this->sendJsonText('{"status": ' . $value . '}', 'PUT');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('update', ['id' => $user->id]));

        $this->assertRefused($result);
        $this->assertSame(User::STATUS_ACTIVE, User::findOne($user->id)?->status, 'the user stays active');
        $this->assertSame([], $problems);
    }

    /**
     * Fractions whose text at PHP's precision of 14 digits reads as a whole
     * number, sent with the flag by an admin who is no superadmin.
     *
     * @return array<string, array{0: string}>
     */
    public static function nearWholeStatusProvider(): array
    {
        return [
            'just below 1, text "1"' => ['0.999999999999999'],
            'just below 10, text "10"' => ['9.99999999999999'],
            'just above 10, text "10"' => ['10.00000000000001'],
        ];
    }

    /**
     * Regression: Yii's integer rule read such a status as the whole number
     * of its text, and SubmittedInteger::of() cast it to its truncation, 0,
     * 9 or 10. So the status passed the check of UsersController::applyBody(),
     * and the checks after it answered: {"status": 0.999999999999999,
     * "is_superadmin": true} from an admin who is no superadmin got 403
     * RoleGrantPolicy::SUPERADMIN_REFUSED, and without the flag the user was
     * deactivated (noWholeNumberProvider). The status is refused first now,
     * as every fraction is (WholeNumberValidator): 422, the user as stored,
     * nothing audited.
     *
     * @dataProvider nearWholeStatusProvider
     */
    public function testRegressionAStatusWhoseTextReadsAsAWholeNumberIsRefusedFirst(string $status): void
    {
        $user = $this->createUserWithRole('numbers-users-target', 'viewer');
        $stored = $this->userRow((int)$user->id);
        $auditBefore = $this->lastAuditId();
        $body = $this->sendJsonText('{"status": ' . $status . ', "is_superadmin": true}', 'PUT');
        $this->assertIsFloat($body['status'], 'the parser gives a float');
        \Yii::$app->response->statusCode = 200;

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('update', ['id' => $user->id]));

        $this->assertRefused($result);
        $this->assertSame($stored, $this->userRow((int)$user->id), 'nothing was written');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Whole numbers reach the model's rule as before.
     *
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function wholeNumberProvider(): array
    {
        return [
            'inactive' => ['0', User::STATUS_INACTIVE],
            'inactive as digits' => ['"0"', User::STATUS_INACTIVE],
            'active' => ['10', User::STATUS_ACTIVE],
            'active as digits' => ['"10"', User::STATUS_ACTIVE],
            'active as a whole float' => ['10.0', User::STATUS_ACTIVE],
            'active as digits with a sign' => ['"+10"', User::STATUS_ACTIVE],
            'active as digits with a leading zero' => ['"010"', User::STATUS_ACTIVE],
            'a status the model does not know' => ['5', null],
            'a negative number' => ['-1', null],
        ];
    }

    /**
     * @dataProvider wholeNumberProvider
     */
    public function testAnUpdateTakesAWholeNumberAsBefore(string $value, ?int $stored): void
    {
        $user = $this->createUserWithRole('numbers-users-target', 'viewer');
        $this->sendJsonText('{"status": ' . $value . '}', 'PUT');

        $result = $this->api()->runAction('update', ['id' => $user->id]);

        if ($stored === null) {
            $this->assertRefused($result);
            $this->assertSame(User::STATUS_ACTIVE, User::findOne($user->id)?->status);
            return;
        }
        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertIsArray($result);
        $this->assertSame($stored, $result['data']['status'] ?? null);
        $this->assertSame($stored, User::findOne($user->id)?->status);
    }

    /**
     * INVALID_STATUS is the model's own error for a status it does not know.
     */
    public function testTheRefusalIsTheModelsErrorForAnUnknownStatus(): void
    {
        $user = new User();
        $user->status = 5;
        $user->validate(['status']);

        $this->assertSame(UsersController::INVALID_STATUS, $user->getFirstError('status'));
        $this->assertSame('Status is invalid.', UsersController::INVALID_STATUS);
    }

    public function testACreateTakesAStatusAsBefore(): void
    {
        $this->sendJsonText($this->createBody('0'));

        $result = $this->api()->runAction('create');

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame(User::STATUS_INACTIVE, User::findOne(['username' => self::CREATED_NAME])?->status);
    }

    // -- Where the refusal comes in the answers ---------------------------------

    /**
     * The checks that come after the status in the order of the answers of
     * POST, each with a body whose whole-number status lets the check
     * answer, and that answer: is_superadmin from a caller who is no
     * superadmin, and the password.
     *
     * @return array<string, array{0: array<string, mixed>, 1: int, 2: string}>
     */
    public static function createCheckAfterTheStatusProvider(): array
    {
        return [
            'is_superadmin from a caller who is no superadmin' => [self::newUser(['password' => self::PASSWORD, 'status' => User::STATUS_ACTIVE, 'is_superadmin' => true]), 403, RoleGrantPolicy::SUPERADMIN_REFUSED],
            'no password' => [self::newUser(['status' => User::STATUS_ACTIVE]), 422, 'Password is required.'],
            'a password that is too short' => [self::newUser(['password' => 'abc', 'status' => User::STATUS_ACTIVE]), 422, 'Password must be at least 5 characters.'],
        ];
    }

    /**
     * Regression: the status was cast before these checks ran, so "abc"
     * was status 0, a valid one, and the body got the answer of the check:
     * an admin who is no superadmin and sent {"status": "abc",
     * "is_superadmin": true} got 403 RoleGrantPolicy::SUPERADMIN_REFUSED,
     * and a body without a password or with a short one got the 422 of the
     * password. A status that is no whole number is refused first now
     * (UsersController::applyBody()), as the order of POST in
     * web/openapi.yaml says. No user is created, nothing is audited.
     *
     * @dataProvider createCheckAfterTheStatusProvider
     * @param array<string, mixed> $body
     */
    public function testRegressionACreateRefusesTheStatusBeforeTheChecksAfterIt(array $body, int $code, string $answer): void
    {
        $auditBefore = $this->lastAuditId();
        [$checked] = $this->answerOf('create', 'POST', $body);
        $this->assertAnswer($code, $answer, $checked, 'with a whole-number status the check answers');

        [$result, $problems] = $this->answerOf('create', 'POST', ['status' => 'abc'] + $body);

        $this->assertRefused($result);
        $this->assertNull(User::findOne(['username' => self::CREATED_NAME]), 'no user was created');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * The checks that come after the status in the order of the answers of
     * PUT, each with a body whose whole-number status lets the check answer
     * for a viewer, and that answer.
     *
     * @return array<string, array{0: array<string, mixed>, 1: int, 2: string}>
     */
    public static function updateCheckAfterTheStatusProvider(): array
    {
        return [
            'is_superadmin from a caller who is no superadmin' => [['status' => User::STATUS_ACTIVE, 'is_superadmin' => true], 403, RoleGrantPolicy::SUPERADMIN_REFUSED],
            'a password that is too short' => [['status' => User::STATUS_ACTIVE, 'password' => 'abc'], 422, 'Password must be at least 5 characters.'],
        ];
    }

    /**
     * Regression: as for POST, an admin who is no superadmin sent PUT
     * {"status": "abc", "is_superadmin": true} and got 403
     * RoleGrantPolicy::SUPERADMIN_REFUSED, and a password that is too short
     * got its 422. The status is refused first now, as the order of PUT
     * says; the user stays as stored, nothing is audited.
     *
     * @dataProvider updateCheckAfterTheStatusProvider
     * @param array<string, mixed> $body
     */
    public function testRegressionAnUpdateRefusesTheStatusBeforeTheChecksAfterIt(array $body, int $code, string $answer): void
    {
        $user = $this->createUserWithRole('numbers-users-target', 'viewer');
        $stored = $this->userRow((int)$user->id);
        $auditBefore = $this->lastAuditId();
        [$checked] = $this->answerOf('update', 'PUT', $body, ['id' => $user->id]);
        $this->assertAnswer($code, $answer, $checked, 'with a whole-number status the check answers');

        [$result, $problems] = $this->answerOf('update', 'PUT', ['status' => 'abc'] + $body, ['id' => $user->id]);

        $this->assertRefused($result);
        $this->assertSame($stored, $this->userRow((int)$user->id), 'nothing was written');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * The 422s of the only active superadmin on their own account, which
     * come after the status in the order of PUT, each with a body whose
     * whole-number status lets it answer.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function onlyActiveSuperadminCheckProvider(): array
    {
        return [
            'deactivating them' => [['status' => User::STATUS_INACTIVE], UsersController::ONLY_SUPERADMIN_DEACTIVATED],
            'taking their flag' => [['status' => User::STATUS_ACTIVE, 'is_superadmin' => false], UsersController::ONLY_SUPERADMIN_DEMOTED],
        ];
    }

    /**
     * Regression: the only active superadmin sent PUT {"status": "abc"} on
     * their own account and got 422 UsersController::ONLY_SUPERADMIN_DEACTIVATED,
     * and with is_superadmin false ONLY_SUPERADMIN_DEMOTED: the cast made
     * "abc" 0. A status that is no whole number is refused first now
     * (INVALID_STATUS), as the order of PUT says; they stay the only active
     * superadmin, nothing is audited.
     *
     * @dataProvider onlyActiveSuperadminCheckProvider
     * @param array<string, mixed> $body
     */
    public function testRegressionTheOnlyActiveSuperadminGetsTheStatusRefusalFirst(array $body, string $answer): void
    {
        $only = $this->theOnlyActiveSuperadmin();
        $this->signIn($only);
        $stored = $this->userRow((int)$only->id);
        $auditBefore = $this->lastAuditId();
        [$checked] = $this->answerOf('update', 'PUT', $body, ['id' => $only->id]);
        $this->assertAnswer(422, $answer, $checked, 'with a whole-number status the check answers');

        [$result, $problems] = $this->answerOf('update', 'PUT', ['status' => 'abc'] + $body, ['id' => $only->id]);

        $this->assertRefused($result);
        $this->assertSame($stored, $this->userRow((int)$only->id), 'nothing was written');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * The checks of the body that come before the status in the order of
     * the answers, with a body that gets their 422 whatever the status: a
     * change or an unknown value of auth_source, and an unknown role, sent
     * by the admin of setUp().
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function checkBeforeTheStatusProvider(): array
    {
        $unknownRole = sprintf(UsersController::INVALID_ROLE, 'admin, operator, viewer');

        return [
            'PUT with another auth_source' => ['update', ['status' => 'abc', 'auth_source' => User::AUTH_SOURCE_LDAP], 'auth_source is immutable once the user exists.'],
            'PUT with an unknown role' => ['update', ['status' => 'abc', 'role' => 'superuser'], $unknownRole],
            'POST with an unknown auth_source' => ['create', self::newUser(['password' => self::PASSWORD, 'status' => 'abc', 'auth_source' => 'directory']), 'Invalid auth_source. Must be "local" or "ldap".'],
            'POST with an unknown role' => ['create', self::newUser(['password' => self::PASSWORD, 'status' => 'abc', 'role' => 'superuser']), $unknownRole],
        ];
    }

    /**
     * @dataProvider checkBeforeTheStatusProvider
     * @param array<string, mixed> $body
     */
    public function testTheChecksOfTheBodyBeforeTheStatusAnswerFirst(string $action, array $body, string $answer): void
    {
        $user = $this->createUserWithRole('numbers-users-target', 'viewer');
        $stored = $this->userRow((int)$user->id);
        $create = $action === 'create';

        [$result] = $this->answerOf($action, $create ? 'POST' : 'PUT', $body, $create ? [] : ['id' => $user->id]);

        $this->assertAnswer(422, $answer, $result, 'the check before the status');
        $this->assertSame($stored, $this->userRow((int)$user->id), 'nothing was written');
        $this->assertNull(User::findOne(['username' => self::CREATED_NAME]), 'no user was created');
    }

    /**
     * The refusal of a user the caller may not change comes first after the
     * 404, before the status too (UserChangePolicy, CLAUDE.md security rule
     * 14): the admin of setUp(), who is no superadmin, sends
     * {"status": "abc"} for a superadmin and gets 403
     * UserChangePolicy::CHANGE_REFUSED.
     */
    public function testTheRefusalOfAUserTheCallerMayNotChangeComesBeforeTheStatus(): void
    {
        $superadmin = $this->createUser('numbers-users-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $stored = $this->userRow((int)$superadmin->id);

        [$result] = $this->answerOf('update', 'PUT', ['status' => 'abc'], ['id' => $superadmin->id]);

        $this->assertAnswer(403, UserChangePolicy::CHANGE_REFUSED, $result, 'the refusal of the user first');
        $this->assertSame($stored, $this->userRow((int)$superadmin->id), 'nothing was written');
    }

    /**
     * A role the caller may not assign answers 403 before the status
     * (RoleGrantPolicy::ROLE_REFUSED): a help desk that may create and
     * change users, but not assign admin, sends it with {"status": "abc"}
     * to POST and to PUT for a user without a role.
     */
    public function testARoleTheCallerMayNotAssignIsRefusedBeforeTheStatus(): void
    {
        $user = $this->createUser('numbers-users-roleless');
        $this->signIn($this->helpDesk());
        $refused = sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin');

        [$created] = $this->answerOf('create', 'POST', self::newUser(['password' => self::PASSWORD, 'status' => 'abc', 'role' => 'admin']));
        $this->assertAnswer(403, $refused, $created, 'POST');
        [$updated] = $this->answerOf('update', 'PUT', ['status' => 'abc', 'role' => 'admin'], ['id' => $user->id]);
        $this->assertAnswer(403, $refused, $updated, 'PUT');

        $this->assertNull(User::findOne(['username' => self::CREATED_NAME]), 'no user was created');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $this->assertSame([], $auth->getAssignments((string)$user->id), 'no role was assigned');
    }

    // -- Helpers ------------------------------------------------------------------

    private function createBody(string $status): string
    {
        return '{"username": "' . self::CREATED_NAME . '", "email": "numbers-created@example.com", '
            . '"password": "' . self::PASSWORD . '", "status": ' . $status . '}';
    }

    /**
     * The body of POST for the user CREATED_NAME, with $fields.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function newUser(array $fields): array
    {
        return $fields + ['username' => self::CREATED_NAME, 'email' => 'numbers-created@example.com'];
    }

    /**
     * Sends $body as the JSON body of a $method request and runs $action of
     * the API with $params, as the signed-in user: what it answered, and
     * the errors and warnings it logged.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $params
     * @return array{0: mixed, 1: list<string>}
     */
    private function answerOf(string $action, string $method, array $body, array $params = []): array
    {
        $this->sendJsonText(json_encode($body, JSON_THROW_ON_ERROR), $method);
        \Yii::$app->response->statusCode = 200;

        return $this->logged(fn (): mixed => $this->api()->runAction($action, $params));
    }

    private function assertAnswer(int $code, string $message, mixed $result, string $what): void
    {
        $this->assertSame(
            [$code, ['error' => ['message' => $message]]],
            [\Yii::$app->response->statusCode, $result],
            $what
        );
    }

    /**
     * Signs $user in for the next requests, with an API token of their own.
     */
    private function signIn(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'numbers-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    /**
     * A new user, made the only active superadmin inside the test's
     * transaction: every other superadmin loses the flag.
     */
    private function theOnlyActiveSuperadmin(): User
    {
        $only = $this->createUser('numbers-only-superadmin');
        User::updateAll(['is_superadmin' => 0], ['is_superadmin' => 1]);
        $only->is_superadmin = true;
        $only->save(false);
        $this->assertFalse(User::activeSuperadminExists((int)$only->id), 'precondition: no other active superadmin');

        return $only;
    }

    /**
     * A user whose only role grants user.view, user.create and user.update:
     * a help desk that may create and change users, but not assign admin.
     */
    private function helpDesk(): User
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('numbers-help-desk-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        foreach (['user.view', 'user.create', 'user.update'] as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $user = $this->createUser('numbers-help-desk');
        $auth->assign($role, (string)$user->id);

        return $user;
    }

    /**
     * Every column of the user as stored, to show that nothing was written.
     *
     * @return array<string, mixed>
     */
    private function userRow(int $userId): array
    {
        $row = User::find()->where(['id' => $userId])->asArray()->one();
        $this->assertIsArray($row, "User #{$userId}");

        return $row;
    }

    /**
     * $result is the 422 of a status that is no whole number. Quoted, not
     * UsersController::INVALID_STATUS, so that the tests run against the
     * code from before the constant too.
     */
    private function assertRefused(mixed $result): void
    {
        $this->assertSame(
            [422, ['error' => ['message' => 'Status is invalid.']]],
            [\Yii::$app->response->statusCode, $result]
        );
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    private function api(): UsersController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new UsersController('api/v1/users', $app);
    }
}
