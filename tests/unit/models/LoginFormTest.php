<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\LoginForm;
use app\models\User;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LoginForm validation logic.
 * validatePassword is tested with a mocked User.
 */
class LoginFormTest extends TestCase
{
    public function testRequiresUsername(): void
    {
        $form = new LoginForm();
        $form->password = 'secret123';
        $form->validate(['username']);
        $this->assertTrue($form->hasErrors('username'));
    }

    public function testRequiresPassword(): void
    {
        $form = new LoginForm();
        $form->username = 'admin';
        $form->validate(['password']);
        $this->assertTrue($form->hasErrors('password'));
    }

    public function testRememberMeDefaultIsTrue(): void
    {
        $form = new LoginForm();
        $this->assertTrue($form->rememberMe);
    }

    public function testValidatePasswordFailsWhenUserNotFound(): void
    {
        $form = new class extends LoginForm {
            protected function getUser(): ?User
            {
                return null;
            }
        };
        $form->username = 'no_such_user';
        $form->password = 'anything';
        $form->validate(['password']);
        $this->assertTrue($form->hasErrors('password'));
    }

    public function testValidatePasswordFailsWhenPasswordWrong(): void
    {
        // Create a real User stub that validates password
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validatePassword', 'isLocal', 'isLdap'])
            ->getMock();
        $user->method('validatePassword')->willReturn(false);
        $user->method('isLocal')->willReturn(true);
        $user->method('isLdap')->willReturn(false);

        $form = new LoginForm();
        $form->username = 'admin';
        $form->password = 'wrongpassword';

        // Inject the mock user via reflection
        $ref = new \ReflectionProperty(LoginForm::class, '_user');
        $ref->setAccessible(true);
        $ref->setValue($form, $user);

        $form->validate();
        $this->assertTrue($form->hasErrors('password'));
    }

    public function testValidatePasswordPassesWhenPasswordCorrect(): void
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validatePassword', 'isLocal', 'isLdap'])
            ->getMock();
        $user->method('validatePassword')->willReturn(true);
        $user->method('isLocal')->willReturn(true);
        $user->method('isLdap')->willReturn(false);

        $form = new LoginForm();
        $form->username = 'admin';
        $form->password = 'correctpassword';

        $ref = new \ReflectionProperty(LoginForm::class, '_user');
        $ref->setAccessible(true);
        $ref->setValue($form, $user);

        $form->validate(['password']);
        $this->assertFalse($form->hasErrors('password'));
    }

    public function testRequiresTotpReturnsFalseWhenNoUser(): void
    {
        $form = new class extends LoginForm {
            protected function getUser(): ?User
            {
                return null;
            }
        };
        $this->assertFalse($form->requiresTotp());
    }
}
