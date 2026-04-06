<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\PasswordResetForm;
use app\models\User;
use app\tests\integration\DbTestCase;
use yii\base\InvalidArgumentException;

class PasswordResetFormTest extends DbTestCase
{
    public function testConstructRejectsUnknownToken(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or expired password reset token.');
        new PasswordResetForm('does-not-exist-' . uniqid());
    }

    public function testConstructAcceptsValidToken(): void
    {
        $user = $this->createUser();
        $user->generatePasswordResetToken();
        $user->save(false);
        $this->assertNotNull($user->password_reset_token);

        $form = new PasswordResetForm($user->password_reset_token);
        $this->assertSame($user->id, $form->getUser()->id);
    }

    public function testValidationRequiresBothFields(): void
    {
        $user = $this->createUser();
        $user->generatePasswordResetToken();
        $user->save(false);
        $this->assertNotNull($user->password_reset_token);

        $form = new PasswordResetForm($user->password_reset_token);
        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('password', $form->errors);
        $this->assertArrayHasKey('password_confirm', $form->errors);
    }

    public function testValidationEnforcesMinLength(): void
    {
        $user = $this->createUser();
        $user->generatePasswordResetToken();
        $user->save(false);
        $this->assertNotNull($user->password_reset_token);

        $form = new PasswordResetForm($user->password_reset_token);
        $form->password = 'short';
        $form->password_confirm = 'short';
        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('password', $form->errors);
    }

    public function testValidationEnforcesMatch(): void
    {
        $user = $this->createUser();
        $user->generatePasswordResetToken();
        $user->save(false);
        $this->assertNotNull($user->password_reset_token);

        $form = new PasswordResetForm($user->password_reset_token);
        $form->password = 'password123';
        $form->password_confirm = 'password456';
        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('password_confirm', $form->errors);
    }

    public function testResetPasswordUpdatesHashAndClearsToken(): void
    {
        $user = $this->createUser();
        $user->generatePasswordResetToken();
        $user->save(false);
        $this->assertNotNull($user->password_reset_token);
        $originalHash = $user->password_hash;

        $form = new PasswordResetForm($user->password_reset_token);
        $form->password = 'new-strong-password';
        $form->password_confirm = 'new-strong-password';
        $this->assertTrue($form->validate());
        $this->assertTrue($form->resetPassword());

        $reloaded = User::findOne($user->id);
        $this->assertNotNull($reloaded);
        $this->assertNotSame($originalHash, $reloaded->password_hash);
        $this->assertEmpty($reloaded->password_reset_token);
        $this->assertTrue(\Yii::$app->security->validatePassword('new-strong-password', $reloaded->password_hash));
    }

    public function testGetUserReturnsResolvedUser(): void
    {
        $user = $this->createUser();
        $user->generatePasswordResetToken();
        $user->save(false);
        $this->assertNotNull($user->password_reset_token);

        $form = new PasswordResetForm($user->password_reset_token);
        $this->assertInstanceOf(User::class, $form->getUser());
        $this->assertSame($user->id, $form->getUser()->id);
    }
}
