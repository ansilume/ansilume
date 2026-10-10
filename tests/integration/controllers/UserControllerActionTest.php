<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\UserController;
use app\models\AuditLog;
use app\models\User;
use app\services\UserDeletionService;
use app\tests\integration\TeamScopeFixtures;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Deleting and demoting users from the web UI.
 */
class UserControllerActionTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /**
     * Regression: a user whom other records still refer to (here: a project
     * they created) failed on the foreign key with a server error.
     */
    public function testDeletingAUserOtherRecordsReferToKeepsThemAndExplainsWhy(): void
    {
        $this->loginAs($this->createUserWithRole('web_del_admin', 'admin'));
        $target = $this->createUserWithRole('web_del_referenced', 'operator');
        $this->createProject($target->id);

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete($target->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(['view', 'id' => $target->id], $ctrl->capturedRedirect);
        $this->assertSame(
            ['danger' => (new UserDeletionService())->refusalMessage($target)],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertNotNull(User::findOne($target->id));
        $this->assertSame(['operator'], $this->roleNames($target->id));
        $this->assertNull($this->deletionAudit($target->id), 'only deletes are audited');
    }

    /**
     * Regression: the web UI deleted the user but left their role
     * assignments behind (auth_assignment has no foreign key to user).
     */
    public function testDeletingAnUnreferencedUserRemovesThemAndTheirRoles(): void
    {
        $this->loginAs($this->createUserWithRole('web_del_admin', 'admin'));
        $target = $this->createUserWithRole('web_del_free', 'operator');

        $ctrl = $this->makeController();
        $result = $ctrl->actionDelete($target->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(['index'], $ctrl->capturedRedirect);
        $this->assertSame(
            ['success' => 'User "' . $target->username . '" deleted.'],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertNull(User::findOne($target->id));
        $this->assertSame([], $this->roleNames($target->id));
        $audit = $this->deletionAudit($target->id);
        $this->assertNotNull($audit);
        $this->assertSame(['username' => $target->username], json_decode((string)$audit->metadata, true));
    }

    public function testAUserCannotDeleteThemselves(): void
    {
        $admin = $this->createUserWithRole('web_del_self', 'admin');
        $this->loginAs($admin);

        try {
            $this->makeController()->actionDelete($admin->id);
            $this->fail('expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('You cannot perform this action on your own account.', $e->getMessage());
        }
        $this->assertNotNull(User::findOne($admin->id));
        $this->assertSame(['admin'], $this->roleNames($admin->id));
    }

    // -- The only superadmin --------------------------------------------------

    public function testTheOnlySuperadminCannotBeDeleted(): void
    {
        $this->loginAs($this->createUserWithRole('web_su_admin', 'admin'));
        $target = $this->createUserWithRole('web_su_only', 'admin');
        $this->makeTheOnlySuperadmins($target);

        try {
            $this->makeController()->actionDelete($target->id);
            $this->fail('expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('Cannot remove or demote the only superadmin account.', $e->getMessage());
        }
        $this->assertTrue($this->isSuperadmin($target->id));
        $this->assertSame(['admin'], $this->roleNames($target->id));
        $this->assertNull($this->deletionAudit($target->id));
    }

    public function testASuperadminCanBeDeletedWhileAnotherRemains(): void
    {
        $this->loginAs($this->createUserWithRole('web_su_admin', 'admin'));
        $target = $this->createUserWithRole('web_su_target', 'admin');
        $this->makeTheOnlySuperadmins($target, $this->createUser('web_su_other'));

        $ctrl = $this->makeController();
        $ctrl->actionDelete($target->id);

        $this->assertSame(['index'], $ctrl->capturedRedirect);
        $this->assertNull(User::findOne($target->id));
        $this->assertSame([], $this->roleNames($target->id));
        $this->assertNotNull($this->deletionAudit($target->id));
    }

    public function testTheOnlySuperadminCannotBeDemoted(): void
    {
        $this->loginAs($this->createUserWithRole('web_su_admin', 'admin'));
        $target = $this->createUserWithRole('web_su_only', 'admin');
        $this->makeTheOnlySuperadmins($target);
        $email = $target->email;
        $this->setPost(['UserForm' => $this->formOf($target, [
            'is_superadmin' => '0',
            'email' => 'demoted-' . uniqid('', true) . '@example.com',
        ])]);

        try {
            $this->makeController()->actionUpdate($target->id);
            $this->fail('expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame('Cannot remove or demote the only superadmin account.', $e->getMessage());
        }
        $this->assertTrue($this->isSuperadmin($target->id));
        $target->refresh();
        $this->assertSame($email, $target->email, 'nothing of the form is saved');
        $this->assertSame(['admin'], $this->roleNames($target->id));
        $this->assertNull($this->updateAudit($target->id));
    }

    public function testASuperadminCanBeDemotedWhileAnotherRemains(): void
    {
        $this->loginAs($this->createUserWithRole('web_su_admin', 'admin'));
        $target = $this->createUserWithRole('web_su_target', 'admin');
        $this->makeTheOnlySuperadmins($target, $this->createUser('web_su_other'));
        $this->setPost(['UserForm' => $this->formOf($target, ['is_superadmin' => '0'])]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate($target->id);

        $this->assertNull($ctrl->capturedView, 'the form was saved, not shown again');
        $this->assertSame(['view', 'id' => $target->id], $ctrl->capturedRedirect);
        $this->assertFalse($this->isSuperadmin($target->id));
        $this->assertSame(['admin'], $this->roleNames($target->id));
        $this->assertNotNull($this->updateAudit($target->id));
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function roleNames(int $userId): array
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $names = array_keys($auth->getRolesByUser((string)$userId));
        sort($names);
        return $names;
    }

    private function deletionAudit(int $userId): ?AuditLog
    {
        return AuditLog::findOne(['action' => AuditLog::ACTION_USER_DELETED, 'object_type' => 'user', 'object_id' => $userId]);
    }

    private function updateAudit(int $userId): ?AuditLog
    {
        return AuditLog::findOne(['action' => AuditLog::ACTION_USER_UPDATED, 'object_type' => 'user', 'object_id' => $userId]);
    }

    /**
     * Makes $users the only superadmins, inside the test's transaction.
     */
    private function makeTheOnlySuperadmins(User ...$users): void
    {
        User::updateAll(['is_superadmin' => 0], ['is_superadmin' => 1]);
        foreach ($users as $user) {
            $user->is_superadmin = true;
            $user->save(false);
        }
        $this->assertSame(count($users), (int)User::find()->where(['is_superadmin' => 1])->count());
    }

    private function isSuperadmin(int $userId): bool
    {
        return (bool)User::find()->select('is_superadmin')->where(['id' => $userId])->scalar();
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

    private function makeController(): UserController
    {
        return new class ('user', \Yii::$app) extends UserController {
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            /** @var string|null the view render() was asked for */
            public ?string $capturedView = null;

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
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
