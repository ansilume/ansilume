<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\UserController;
use app\models\AuditLog;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The only active superadmin in the web UI (User::activeSuperadminExists()).
 * A superadmin who is inactive cannot sign in, so they keep nobody in: the
 * only active superadmin neither clears their own flag nor deactivates their
 * account through the Edit User form, nor is deleted, while every other
 * superadmin is inactive: 403 UserController::ONLY_SUPERADMIN, and nothing
 * is saved or audited. With a second active superadmin both may, and an
 * inactive superadmin may be demoted, deactivated and deleted.
 *
 * Regression (wave 5e of 2.11.0): the guards counted every superadmin,
 * active or not. With a second superadmin who was inactive, the last active
 * superadmin deactivated or demoted themselves, and no superadmin could sign
 * in any more; since 2.11.0 an admin may not reactivate a superadmin
 * (UserChangePolicy), and setup/admin aborted while any superadmin existed.
 */
final class UserControllerOnlyActiveSuperadminTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /**
     * The changes of their own Edit User form that would leave the only
     * active superadmin unable to act as one, with the status and the flag
     * they leave when another active superadmin remains.
     *
     * @return array<string, array{0: array<string, string>, 1: int, 2: bool}>
     */
    public static function ownLosingChangeProvider(): array
    {
        return [
            'deactivating their account' => [['status' => (string)User::STATUS_INACTIVE], User::STATUS_INACTIVE, true],
            'clearing their superadmin flag' => [['is_superadmin' => '0'], User::STATUS_ACTIVE, false],
        ];
    }

    /**
     * regression: the only active superadmin, whose second superadmin was
     * inactive, saved their own Edit User form with the status inactive or
     * the superadmin flag cleared, and it was saved: no superadmin could
     * sign in any more. 403 ONLY_SUPERADMIN now; nothing of the form is
     * saved or audited.
     *
     * @dataProvider ownLosingChangeProvider
     * @param array<string, string> $change
     */
    public function testRegressionTheOnlyActiveSuperadminKeepsTheirAccountWhileAnInactiveOneExists(array $change): void
    {
        $active = $this->createUserWithRole('web_sa_only_active', 'admin');
        $inactive = $this->createUserWithRole('web_sa_inactive', 'admin');
        $this->makeTheOnlySuperadmins([$active], [$inactive]);
        $this->loginAs($active);
        $before = $this->rowOf($active);
        $this->setPost(['UserForm' => $this->formOf($active, $change + ['email' => 'lost-' . uniqid('', true) . '@example.com'])]);

        try {
            $this->makeController()->actionUpdate($active->id);
            $this->fail('expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('Cannot remove, demote or deactivate the only active superadmin account.', $e->getMessage());
            $this->assertSame(UserController::ONLY_SUPERADMIN, $e->getMessage());
        }
        $this->assertSame($before, $this->rowOf($active), 'nothing of the form is saved');
        $this->assertNull($this->updateAudit($active->id));
        $this->assertSame(1, $this->activeSuperadmins());
    }

    /**
     * regression: Delete of the only active superadmin passed while an
     * inactive superadmin existed. A superadmin deletes a superadmin
     * (UserChangePolicy), so the actor here lost the flag meanwhile: their
     * session still says superadmin. 403 ONLY_SUPERADMIN now, and the user
     * stays, roles included.
     */
    public function testRegressionTheOnlyActiveSuperadminIsNotDeletedWhileAnInactiveOneExists(): void
    {
        $actor = $this->createUserWithRole('web_sa_demoted_meanwhile', 'admin');
        $actor->is_superadmin = true;
        $this->loginAs($actor);
        $active = $this->createUserWithRole('web_sa_only_active', 'admin');
        $this->makeTheOnlySuperadmins([$active], [$this->createUserWithRole('web_sa_inactive', 'admin')]);
        $this->assertTrue((bool)$actor->is_superadmin, 'precondition: the session says superadmin');

        try {
            $this->makeController()->actionDelete($active->id);
            $this->fail('expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(UserController::ONLY_SUPERADMIN, $e->getMessage());
        }
        $this->assertNotNull(User::findOne($active->id));
        $this->assertTrue($this->auth()->checkAccess((string)$active->id, 'admin'), 'the roles stay');
        $this->assertNull($this->deletionAudit($active->id));
    }

    /**
     * With a second active superadmin, a superadmin deactivates their own
     * account or clears their own flag, and the change is saved.
     *
     * @dataProvider ownLosingChangeProvider
     * @param array<string, string> $change
     */
    public function testWithASecondActiveSuperadminTheyDeactivateOrDemoteThemselves(array $change, int $status, bool $superadmin): void
    {
        $self = $this->createUserWithRole('web_sa_self', 'admin');
        $second = $this->createUserWithRole('web_sa_second_active', 'admin');
        $this->makeTheOnlySuperadmins([$self, $second], [$this->createUserWithRole('web_sa_inactive', 'admin')]);
        $this->loginAs($self);
        $this->setPost(['UserForm' => $this->formOf($self, $change)]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($self->id);

        $this->assertSame(['view', 'id' => $self->id], $ctrl->capturedRedirect, 'the form was saved');
        $self->refresh();
        $this->assertSame([$status, $superadmin], [(int)$self->status, (bool)$self->is_superadmin]);
        $this->assertNotNull($this->updateAudit($self->id));
    }

    /**
     * The only active superadmin demotes an inactive superadmin, saves the
     * form of another with the status inactive, and deletes that one: an
     * inactive superadmin keeps nobody in.
     */
    public function testTheOnlyActiveSuperadminDemotesDeactivatesAndDeletesInactiveSuperadmins(): void
    {
        $active = $this->createUserWithRole('web_sa_only_active', 'admin');
        $demoted = $this->createUserWithRole('web_sa_inactive_demoted', 'admin');
        $deleted = $this->createUserWithRole('web_sa_inactive_deleted', 'admin');
        $this->makeTheOnlySuperadmins([$active], [$demoted, $deleted]);
        $this->loginAs($active);

        $this->setPost(['UserForm' => $this->formOf($demoted, ['is_superadmin' => '0'])]);
        $ctrl = $this->makeController();
        $ctrl->actionUpdate($demoted->id);
        $this->assertSame(['view', 'id' => $demoted->id], $ctrl->capturedRedirect, 'the demotion was saved');
        $this->assertFalse((bool)$this->stored($demoted)->is_superadmin);

        $email = 'deactivated-' . uniqid('', true) . '@example.com';
        $this->setPost(['UserForm' => $this->formOf($deleted, ['status' => (string)User::STATUS_INACTIVE, 'email' => $email])]);
        $ctrl = $this->makeController();
        $ctrl->actionUpdate($deleted->id);
        $this->assertSame(['view', 'id' => $deleted->id], $ctrl->capturedRedirect, 'the form was saved');
        $stored = $this->stored($deleted);
        $this->assertSame([$email, User::STATUS_INACTIVE, true], [$stored->email, (int)$stored->status, (bool)$stored->is_superadmin]);

        $ctrl = $this->makeController();
        $ctrl->actionDelete($deleted->id);
        $this->assertSame(['index'], $ctrl->capturedRedirect);
        $this->assertNull(User::findOne($deleted->id));
        $this->assertNotNull($this->deletionAudit($deleted->id));
        $this->assertSame(1, $this->activeSuperadmins());
    }

    /**
     * The guards keep the only active superadmin, never an inactive one:
     * with no superadmin active at all, an inactive superadmin is demoted
     * and another deleted (setup/admin then creates a superadmin who can
     * sign in). The actor lost the flag meanwhile; their session still says
     * superadmin.
     */
    public function testAnInactiveSuperadminIsDemotedOrDeletedWhileNoSuperadminIsActive(): void
    {
        $actor = $this->createUserWithRole('web_sa_demoted_meanwhile', 'admin');
        $actor->is_superadmin = true;
        $this->loginAs($actor);
        $demoted = $this->createUserWithRole('web_sa_inactive_demoted', 'admin');
        $this->makeTheOnlySuperadmins([], [$demoted]);

        $this->setPost(['UserForm' => $this->formOf($demoted, ['is_superadmin' => '0'])]);
        $ctrl = $this->makeController();
        $ctrl->actionUpdate($demoted->id);
        $this->assertSame(['view', 'id' => $demoted->id], $ctrl->capturedRedirect, 'the demotion was saved');
        $this->assertFalse((bool)$this->stored($demoted)->is_superadmin);

        $deleted = $this->createUserWithRole('web_sa_inactive_deleted', 'admin');
        $this->makeTheOnlySuperadmins([], [$deleted]);
        $ctrl = $this->makeController();
        $ctrl->actionDelete($deleted->id);
        $this->assertSame(['index'], $ctrl->capturedRedirect);
        $this->assertNull(User::findOne($deleted->id));
    }

    // -- Helpers --------------------------------------------------------------

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

    /**
     * The edit form of $user as the browser posts it, with $changes applied.
     *
     * @param array<string, string> $changes
     * @return array<string, string>
     */
    private function formOf(User $user, array $changes): array
    {
        return array_merge([
            'username' => $user->username,
            'email' => $user->email,
            'password' => '',
            'role' => 'admin',
            'status' => (string)$user->status,
            'is_superadmin' => $user->is_superadmin ? '1' : '0',
        ], $changes);
    }

    /**
     * Every column of the user as stored.
     *
     * @return array<string, mixed>
     */
    private function rowOf(User $user): array
    {
        $row = User::find()->where(['id' => $user->id])->asArray()->one();
        $this->assertIsArray($row, "User #{$user->id}");

        return $row;
    }

    private function stored(User $user): User
    {
        $stored = User::findOne($user->id);
        $this->assertNotNull($stored, "User #{$user->id}");

        return $stored;
    }

    private function auth(): \yii\rbac\ManagerInterface
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);

        return $auth;
    }

    private function deletionAudit(int $userId): ?AuditLog
    {
        return AuditLog::findOne(['action' => AuditLog::ACTION_USER_DELETED, 'object_type' => 'user', 'object_id' => $userId]);
    }

    private function updateAudit(int $userId): ?AuditLog
    {
        return AuditLog::findOne(['action' => AuditLog::ACTION_USER_UPDATED, 'object_type' => 'user', 'object_id' => $userId]);
    }

    private function makeController(): UserController
    {
        return new class ('user', \Yii::$app) extends UserController {
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = $url;
                $response = new Response();
                $response->content = 'redirected';
                return $response;
            }
        };
    }
}
