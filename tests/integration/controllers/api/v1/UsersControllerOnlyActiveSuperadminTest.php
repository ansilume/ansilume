<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\UsersController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * The only active superadmin in the users API (User::activeSuperadminExists()).
 * A superadmin who is inactive cannot sign in, so they keep nobody in: PUT
 * /api/v1/users/{id} of the only active superadmin answers 422 for
 * is_superadmin false (UsersController::ONLY_SUPERADMIN_DEMOTED) and for a
 * status other than active (ONLY_SUPERADMIN_DEACTIVATED), and DELETE answers
 * 422 (ONLY_SUPERADMIN_DELETED), while every other superadmin is inactive;
 * nothing is written. With a second active superadmin both changes pass,
 * and an inactive superadmin may be demoted, deactivated and deleted.
 *
 * Regression (wave 5e of 2.11.0): the checks counted every superadmin,
 * active or not. With a second superadmin who was inactive, the last active
 * superadmin deactivated or demoted themselves (200), and no superadmin
 * could sign in any more; since 2.11.0 an admin may not reactivate a
 * superadmin (UserChangePolicy), and setup/admin aborted while any
 * superadmin existed.
 */
final class UsersControllerOnlyActiveSuperadminTest extends WebControllerTestCase
{
    private UsersController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new UsersController('api/v1/users', \Yii::$app);
    }

    /**
     * The bodies that would leave the only active superadmin unable to act
     * as one, with the 422 they get while every other superadmin is
     * inactive, and the status and flag they leave when another active
     * superadmin remains.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: int, 3: bool}>
     */
    public static function ownLosingChangeProvider(): array
    {
        return [
            'status 0' => [['status' => User::STATUS_INACTIVE], 'Cannot deactivate the only active superadmin.', User::STATUS_INACTIVE, true],
            'is_superadmin false' => [['is_superadmin' => false], 'Cannot demote the only active superadmin.', User::STATUS_ACTIVE, false],
        ];
    }

    /**
     * regression: the only active superadmin, whose second superadmin was
     * inactive, sent PUT {status: 0} or {is_superadmin: false} for their own
     * account and got 200: no superadmin could sign in any more. 422 with
     * UsersController::ONLY_SUPERADMIN_DEACTIVATED or
     * ONLY_SUPERADMIN_DEMOTED now; nothing is written or audited.
     *
     * @dataProvider ownLosingChangeProvider
     * @param array<string, mixed> $body
     */
    public function testRegressionTheOnlyActiveSuperadminKeepsTheirAccountWhileAnInactiveOneExists(array $body, string $refusal): void
    {
        $active = $this->createUser('api-sa-only-active');
        $this->makeTheOnlySuperadmins([$active], [$this->createUser('api-sa-inactive')]);
        $this->authenticate($active);
        $before = $this->userRow((int)$active->id);

        $this->setBody($body + ['email' => 'lost-' . uniqid('', true) . '@example.com']);
        $result = $this->ctrl->actionUpdate((int)$active->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $refusal]], $result);
        $this->assertContains($refusal, [UsersController::ONLY_SUPERADMIN_DEACTIVATED, UsersController::ONLY_SUPERADMIN_DEMOTED]);
        $this->assertSame($before, $this->userRow((int)$active->id), 'nothing is written');
        $this->assertNull($this->audit(AuditLog::ACTION_USER_UPDATED, (int)$active->id));
        $this->assertSame(1, $this->activeSuperadmins());
    }

    /**
     * regression: DELETE of the only active superadmin answered 200 while an
     * inactive superadmin existed. A superadmin deletes a superadmin
     * (UserChangePolicy), so the caller here lost the flag after the token
     * was read. 422 UsersController::ONLY_SUPERADMIN_DELETED now, and the
     * user stays.
     */
    public function testRegressionTheOnlyActiveSuperadminIsNotDeletedWhileAnInactiveOneExists(): void
    {
        $this->authenticate($this->superadmin('api-sa-demoted-meanwhile'));
        $active = $this->createUser('api-sa-only-active');
        $this->makeTheOnlySuperadmins([$active], [$this->createUser('api-sa-inactive')]);

        $result = $this->ctrl->actionDelete((int)$active->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Cannot delete the only active superadmin.']], $result);
        $this->assertSame(UsersController::ONLY_SUPERADMIN_DELETED, $result['error']['message']);
        $this->assertNotNull(User::findOne($active->id));
        $this->assertNull($this->audit(AuditLog::ACTION_USER_DELETED, (int)$active->id));
    }

    /**
     * With a second active superadmin, a superadmin deactivates their own
     * account or clears their own flag (200).
     *
     * @dataProvider ownLosingChangeProvider
     * @param array<string, mixed> $body
     */
    public function testWithASecondActiveSuperadminTheyDeactivateOrDemoteThemselves(array $body, string $refusal, int $status, bool $superadmin): void
    {
        $self = $this->createUser('api-sa-self');
        $this->makeTheOnlySuperadmins([$self, $this->createUser('api-sa-second-active')], [$this->createUser('api-sa-inactive')]);
        $this->authenticate($self);

        $this->setBody($body);
        $result = $this->ctrl->actionUpdate((int)$self->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, 'not ' . $refusal);
        $this->assertArrayHasKey('data', $result);
        $self->refresh();
        $this->assertSame([$status, $superadmin], [(int)$self->status, (bool)$self->is_superadmin]);
        $this->assertNotNull($this->audit(AuditLog::ACTION_USER_UPDATED, (int)$self->id));
    }

    /**
     * The only active superadmin demotes an inactive superadmin, sends
     * status 0 for another, and deletes that one (200 each): an inactive
     * superadmin keeps nobody in.
     */
    public function testTheOnlyActiveSuperadminDemotesDeactivatesAndDeletesInactiveSuperadmins(): void
    {
        $active = $this->createUser('api-sa-only-active');
        $demoted = $this->createUser('api-sa-inactive-demoted');
        $deleted = $this->createUser('api-sa-inactive-deleted');
        $this->makeTheOnlySuperadmins([$active], [$demoted, $deleted]);
        $this->authenticate($active);

        $this->setBody(['is_superadmin' => false]);
        $this->assertArrayHasKey('data', $this->ctrl->actionUpdate((int)$demoted->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertFalse($this->isSuperadmin((int)$demoted->id));

        $email = 'deactivated-' . uniqid('', true) . '@example.com';
        $this->setBody(['status' => User::STATUS_INACTIVE, 'email' => $email]);
        $this->assertArrayHasKey('data', $this->ctrl->actionUpdate((int)$deleted->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $row = $this->userRow((int)$deleted->id);
        $this->assertSame([$email, User::STATUS_INACTIVE, true], [$row['email'], (int)$row['status'], (bool)$row['is_superadmin']]);

        $this->assertSame(['data' => ['deleted' => true]], $this->ctrl->actionDelete((int)$deleted->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertNull(User::findOne($deleted->id));
        $this->assertSame(1, $this->activeSuperadmins());
    }

    /**
     * The checks keep the only active superadmin, never an inactive one:
     * with no superadmin active at all, an inactive superadmin is demoted
     * and another deleted (setup/admin then creates a superadmin who can
     * sign in). The caller lost the flag after the token was read.
     */
    public function testAnInactiveSuperadminIsDemotedOrDeletedWhileNoSuperadminIsActive(): void
    {
        $this->authenticate($this->superadmin('api-sa-demoted-meanwhile'));
        $demoted = $this->createUser('api-sa-inactive-demoted');
        $this->makeTheOnlySuperadmins([], [$demoted]);

        $this->setBody(['is_superadmin' => false]);
        $this->assertArrayHasKey('data', $this->ctrl->actionUpdate((int)$demoted->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertFalse($this->isSuperadmin((int)$demoted->id));

        $deleted = $this->createUser('api-sa-inactive-deleted');
        $this->makeTheOnlySuperadmins([], [$deleted]);
        $this->assertSame(['data' => ['deleted' => true]], $this->ctrl->actionDelete((int)$deleted->id));
        $this->assertNull(User::findOne($deleted->id));
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * A user with the superadmin flag, as stored when the test signs them in.
     */
    private function superadmin(string $label): User
    {
        $user = $this->createUser($label);
        $user->is_superadmin = true;
        $user->save(false);

        return $user;
    }

    /**
     * Makes the users of $active and $inactive the only superadmins, inside
     * the test's transaction, those of $inactive deactivated.
     *
     * @param list<User> $active
     * @param list<User> $inactive
     */
    private function makeTheOnlySuperadmins(array $active, array $inactive): void
    {
        User::updateAll(['is_superadmin' => 0], ['is_superadmin' => 1]);
        foreach ($active as $user) {
            $user->refresh();
            $user->is_superadmin = true;
            $user->status = User::STATUS_ACTIVE;
            $user->save(false);
        }
        foreach ($inactive as $user) {
            $user->refresh();
            $user->is_superadmin = true;
            $user->status = User::STATUS_INACTIVE;
            $user->save(false);
        }
        $this->assertSame(count($active), $this->activeSuperadmins(), 'precondition: the active superadmins');
        $this->assertSame(count($active) + count($inactive), (int)User::find()->where(['is_superadmin' => 1])->count());
    }

    private function activeSuperadmins(): int
    {
        return (int)User::find()->where(['is_superadmin' => 1, 'status' => User::STATUS_ACTIVE])->count();
    }

    private function isSuperadmin(int $userId): bool
    {
        return (bool)User::find()->select('is_superadmin')->where(['id' => $userId])->scalar();
    }

    /**
     * Signs $user in with an API token of their own.
     */
    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
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

    private function audit(string $action, int $userId): ?AuditLog
    {
        return AuditLog::findOne(['action' => $action, 'object_type' => 'user', 'object_id' => $userId]);
    }
}
