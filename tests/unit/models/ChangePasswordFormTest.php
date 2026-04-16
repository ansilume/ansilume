<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\ChangePasswordForm;
use app\models\User;
use PHPUnit\Framework\TestCase;

class ChangePasswordFormTest extends TestCase
{
    private function makeUser(string $password = 'oldpassword'): User
    {
        $user = new User();
        $user->username = 'test-cpf-' . uniqid('', true);
        $user->email = $user->username . '@example.com';
        $user->setPassword($password);
        $user->generateAuthKey();
        $user->status = User::STATUS_ACTIVE;
        $user->created_at = time();
        $user->updated_at = time();
        $user->save(false);
        return $user;
    }

    public function testValidationRequiresAllFields(): void
    {
        $user = $this->makeUser();
        $model = new ChangePasswordForm($user);

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('current_password', $model->getErrors());
        $this->assertArrayHasKey('new_password', $model->getErrors());
        $this->assertArrayHasKey('new_password_confirm', $model->getErrors());
    }

    public function testRejectsWrongCurrentPassword(): void
    {
        $user = $this->makeUser('correctpassword');
        $model = new ChangePasswordForm($user);
        $model->current_password = 'wrongpassword';
        $model->new_password = 'newpassword123';
        $model->new_password_confirm = 'newpassword123';

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('current_password', $model->getErrors());
    }

    public function testRejectsShortNewPassword(): void
    {
        $user = $this->makeUser('oldpassword');
        $model = new ChangePasswordForm($user);
        $model->current_password = 'oldpassword';
        $model->new_password = 'short';
        $model->new_password_confirm = 'short';

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('new_password', $model->getErrors());
    }

    public function testRejectsMismatchedConfirmation(): void
    {
        $user = $this->makeUser('oldpassword');
        $model = new ChangePasswordForm($user);
        $model->current_password = 'oldpassword';
        $model->new_password = 'newpassword123';
        $model->new_password_confirm = 'different123';

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('new_password_confirm', $model->getErrors());
    }

    public function testChangesPasswordSuccessfully(): void
    {
        $user = $this->makeUser('oldpassword');
        $model = new ChangePasswordForm($user);
        $model->current_password = 'oldpassword';
        $model->new_password = 'newpassword123';
        $model->new_password_confirm = 'newpassword123';

        $this->assertTrue($model->changePassword());

        // Reload from DB
        $refreshed = User::findOne($user->id);
        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->validatePassword('newpassword123'));
        $this->assertFalse($refreshed->validatePassword('oldpassword'));
    }

    public function testChangePasswordReturnsFalseOnValidationError(): void
    {
        $user = $this->makeUser('oldpassword');
        $model = new ChangePasswordForm($user);
        $model->current_password = 'wrongpassword';
        $model->new_password = 'newpassword123';
        $model->new_password_confirm = 'newpassword123';

        $this->assertFalse($model->changePassword());
    }

    public function testGetUserReturnsUser(): void
    {
        $user = $this->makeUser();
        $model = new ChangePasswordForm($user);
        $this->assertSame($user, $model->getUser());
    }

    public function testAttributeLabels(): void
    {
        $user = $this->makeUser();
        $model = new ChangePasswordForm($user);
        $labels = $model->attributeLabels();

        $this->assertSame('Current Password', $labels['current_password']);
        $this->assertSame('New Password', $labels['new_password']);
        $this->assertSame('Confirm New Password', $labels['new_password_confirm']);
    }

    public function testLdapUserCannotChangePassword(): void
    {
        // LDAP-managed accounts must be rejected by validateNotLdap, even when
        // every other input would otherwise be valid. The directory owns the
        // credential; touching it locally would silently break login.
        $user = $this->makeUser('oldpassword');
        $user->markAsLdapManaged();
        $user->save(false);

        $model = new ChangePasswordForm($user);
        $model->current_password = 'oldpassword';
        $model->new_password = 'newpassword123';
        $model->new_password_confirm = 'newpassword123';

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('current_password', $model->getErrors());
        $errors = $model->getErrors('current_password');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('external directory', $errors[0]);
    }

    public function testLdapUserChangePasswordReturnsFalse(): void
    {
        $user = $this->makeUser('oldpassword');
        $user->markAsLdapManaged();
        $user->save(false);

        $model = new ChangePasswordForm($user);
        $model->current_password = 'oldpassword';
        $model->new_password = 'newpassword123';
        $model->new_password_confirm = 'newpassword123';

        $this->assertFalse($model->changePassword());

        // Sentinel hash must remain — LDAP login flow never validates against it.
        $refreshed = User::findOne($user->id);
        $this->assertNotNull($refreshed);
        $this->assertSame(User::LDAP_PASSWORD_SENTINEL, $refreshed->password_hash);
    }
}
