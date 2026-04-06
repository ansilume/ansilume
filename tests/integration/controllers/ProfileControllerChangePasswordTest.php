<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ProfileController;
use app\models\User;

class ProfileControllerChangePasswordTest extends WebControllerTestCase
{
    public function testChangePasswordSucceeds(): void
    {
        $user = $this->createUser();
        $user->setPassword('oldpassword');
        $user->save(false);
        $this->loginAs($user);

        $this->setPost([
            'ChangePasswordForm' => [
                'current_password' => 'oldpassword',
                'new_password' => 'newpassword123',
                'new_password_confirm' => 'newpassword123',
            ],
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionChangePassword();

        $this->assertInstanceOf(\yii\web\Response::class, $result);

        // Verify password actually changed
        $refreshed = User::findOne($user->id);
        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->validatePassword('newpassword123'));
        $this->assertFalse($refreshed->validatePassword('oldpassword'));
    }

    public function testChangePasswordRejectsWrongCurrent(): void
    {
        $user = $this->createUser();
        $user->setPassword('correctpassword');
        $user->save(false);
        $this->loginAs($user);

        $this->setPost([
            'ChangePasswordForm' => [
                'current_password' => 'wrongpassword',
                'new_password' => 'newpassword123',
                'new_password_confirm' => 'newpassword123',
            ],
        ]);

        $ctrl = $this->makeController();
        $ctrl->actionChangePassword();

        // Password should not have changed
        $refreshed = User::findOne($user->id);
        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->validatePassword('correctpassword'));
    }

    public function testChangePasswordRejectsMismatch(): void
    {
        $user = $this->createUser();
        $user->setPassword('oldpassword');
        $user->save(false);
        $this->loginAs($user);

        $this->setPost([
            'ChangePasswordForm' => [
                'current_password' => 'oldpassword',
                'new_password' => 'newpassword123',
                'new_password_confirm' => 'different123',
            ],
        ]);

        $ctrl = $this->makeController();
        $ctrl->actionChangePassword();

        // Password should not have changed
        $refreshed = User::findOne($user->id);
        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->validatePassword('oldpassword'));
    }

    public function testChangePasswordRejectsShortPassword(): void
    {
        $user = $this->createUser();
        $user->setPassword('oldpassword');
        $user->save(false);
        $this->loginAs($user);

        $this->setPost([
            'ChangePasswordForm' => [
                'current_password' => 'oldpassword',
                'new_password' => 'short',
                'new_password_confirm' => 'short',
            ],
        ]);

        $ctrl = $this->makeController();
        $ctrl->actionChangePassword();

        // Password should not have changed
        $refreshed = User::findOne($user->id);
        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->validatePassword('oldpassword'));
    }

    public function testChangePasswordCreatesAuditLog(): void
    {
        $user = $this->createUser();
        $user->setPassword('oldpassword');
        $user->save(false);
        $this->loginAs($user);

        $this->setPost([
            'ChangePasswordForm' => [
                'current_password' => 'oldpassword',
                'new_password' => 'newpassword123',
                'new_password_confirm' => 'newpassword123',
            ],
        ]);

        $ctrl = $this->makeController();
        $ctrl->actionChangePassword();

        $log = \app\models\AuditLog::find()
            ->where([
                'action' => \app\models\AuditLog::ACTION_PASSWORD_CHANGED,
                'object_type' => 'user',
                'object_id' => $user->id,
            ])
            ->one();

        $this->assertNotNull($log, 'Audit log entry should exist for password change.');
    }

    private function makeController(): ProfileController
    {
        return new class ('profile', \Yii::$app) extends ProfileController {
            /** @param string|array<int|string, mixed> $url */
            public function redirect($url, $statusCode = 302): \yii\web\Response
            {
                $r = new \yii\web\Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
