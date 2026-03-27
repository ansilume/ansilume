<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\User;
use app\models\UserForm;
use app\tests\integration\DbTestCase;

/**
 * Integration tests for UserForm::save() and fromUser() — require real DB
 * for uniqueness validation and RBAC role assignment.
 */
class UserFormIntegrationTest extends DbTestCase
{
    public function testSaveCreatesNewUser(): void
    {
        $form = $this->makeValidForm();

        $before = (int)User::find()->count();
        $result = $form->save();
        $this->assertTrue($result, implode(', ', $form->getFirstErrors()));
        $this->assertSame($before + 1, (int)User::find()->count());
    }

    public function testSaveReturnsUserModel(): void
    {
        $form = $this->makeValidForm();
        $form->save();

        $this->assertNotNull($form->getUser());
        $this->assertInstanceOf(User::class, $form->getUser());
    }

    public function testSavePersistsUsername(): void
    {
        $form = $this->makeValidForm();
        $form->save();

        $this->assertSame($form->username, $form->getUser()->username);
    }

    public function testSaveFailsWhenPasswordMissingForNewUser(): void
    {
        $form           = $this->makeValidForm();
        $form->password = '';

        $result = $form->save();
        $this->assertFalse($result);
        $this->assertArrayHasKey('password', $form->errors);
    }

    public function testSaveFailsWhenUsernameAlreadyTaken(): void
    {
        $existing = $this->createUser('taken');

        $form           = $this->makeValidForm();
        $form->username = $existing->username;

        $result = $form->save();
        $this->assertFalse($result);
    }

    public function testFromUserLoadsExistingUserData(): void
    {
        $user = $this->createUser('editme');
        $user->refresh();
        $form = UserForm::fromUser($user);

        $this->assertSame($user->username, $form->username);
        $this->assertSame($user->email, $form->email);
    }

    public function testSaveUpdatesExistingUser(): void
    {
        $user = $this->createUser('updateme');
        $user->refresh();
        $form = UserForm::fromUser($user);
        $form->email    = 'updated_' . uniqid('', true) . '@example.com';
        $form->password = ''; // no password change

        $result = $form->save();
        $this->assertTrue($result, implode(', ', $form->getFirstErrors()));

        $user->refresh();
        $this->assertSame($form->email, $user->email);
    }

    public function testSaveWithNewPasswordHashesIt(): void
    {
        $user = $this->createUser('pwchange');
        $user->refresh();
        $form = UserForm::fromUser($user);
        $form->password = 'NewSecurePass1!';

        $form->save();

        $user->refresh();
        $this->assertTrue($user->validatePassword('NewSecurePass1!'));
    }

    public function testRoleOptionsReturnsExpectedKeys(): void
    {
        $options = UserForm::roleOptions();

        $this->assertArrayHasKey('viewer', $options);
        $this->assertArrayHasKey('operator', $options);
        $this->assertArrayHasKey('admin', $options);
    }

    public function testGetUserReturnsNullBeforeSave(): void
    {
        $form = new UserForm();
        $this->assertNull($form->getUser());
    }

    // -------------------------------------------------------------------------

    private function makeValidForm(string $usernameSuffix = ''): UserForm
    {
        $form           = new UserForm();
        $form->username = 'testform_' . ($usernameSuffix ?: uniqid('', true));
        $form->email    = 'form_' . uniqid('', true) . '@example.com';
        $form->password = 'ValidPass123!';
        $form->role     = 'viewer';
        $form->status   = User::STATUS_ACTIVE;
        return $form;
    }
}
