<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\User;
use app\models\UserForm;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for UserForm — role options, validation rules, fromUser().
 * DB-requiring validators (unique) are excluded; we test pure logic.
 */
class UserFormTest extends TestCase
{
    private mixed $originalAuthManager = null;

    protected function setUp(): void
    {
        if (\Yii::$app->has('authManager')) {
            $this->originalAuthManager = \Yii::$app->get('authManager');
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalAuthManager !== null) {
            \Yii::$app->set('authManager', $this->originalAuthManager);
        }
    }

    public function testRoleOptionsContainsThreeEntries(): void
    {
        $options = UserForm::roleOptions();
        $this->assertCount(3, $options);
        $this->assertArrayHasKey('viewer', $options);
        $this->assertArrayHasKey('operator', $options);
        $this->assertArrayHasKey('admin', $options);
    }

    public function testRoleOptionsValuesAreStrings(): void
    {
        foreach (UserForm::roleOptions() as $label) {
            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }

    public function testRequiresUsername(): void
    {
        $form = $this->makeForm(['username' => '']);
        $form->validate(['username']);
        $this->assertTrue($form->hasErrors('username'));
    }

    public function testRequiresEmail(): void
    {
        $form = $this->makeForm(['email' => '']);
        $form->validate(['email']);
        $this->assertTrue($form->hasErrors('email'));
    }

    public function testRequiresRole(): void
    {
        $form = $this->makeForm(['role' => '']);
        $form->validate(['role']);
        $this->assertTrue($form->hasErrors('role'));
    }

    public function testInvalidEmailFailsValidation(): void
    {
        $form = $this->makeForm(['email' => 'not-an-email']);
        $form->validate(['email']);
        $this->assertTrue($form->hasErrors('email'));
    }

    public function testValidEmailPassesValidation(): void
    {
        $form = $this->makeForm(['email' => 'user@example.com']);
        $form->validate(['email']);
        $this->assertFalse($form->hasErrors('email'));
    }

    public function testPasswordTooShortFailsValidation(): void
    {
        $form = $this->makeForm(['password' => 'short']);
        $form->validate(['password']);
        $this->assertTrue($form->hasErrors('password'));
    }

    public function testPasswordLongEnoughPassesValidation(): void
    {
        $form = $this->makeForm(['password' => 'longenough']);
        $form->validate(['password']);
        $this->assertFalse($form->hasErrors('password'));
    }

    public function testInvalidRoleFailsValidation(): void
    {
        $form = $this->makeForm(['role' => 'superroot']);
        $form->validate(['role']);
        $this->assertTrue($form->hasErrors('role'));
    }

    public function testValidRolesPassValidation(): void
    {
        foreach (array_keys(UserForm::roleOptions()) as $role) {
            $form = $this->makeForm(['role' => $role]);
            $form->validate(['role']);
            $this->assertFalse($form->hasErrors('role'), "Role '{$role}' should be valid");
        }
    }

    public function testFromUserPopulatesFields(): void
    {
        $user = $this->makeUser(5, 'alice', 'alice@example.com');

        $auth = $this->getMockBuilder(\yii\rbac\ManagerInterface::class)->getMock();
        $auth->method('getRolesByUser')->willReturn([]);
        \Yii::$app->set('authManager', $auth);

        $form = UserForm::fromUser($user);
        $this->assertSame('alice', $form->username);
        $this->assertSame('alice@example.com', $form->email);
        $this->assertSame(User::STATUS_ACTIVE, $form->status);
        $this->assertFalse($form->is_superadmin);
    }

    public function testFromUserSetsRoleFromAuthManager(): void
    {
        $user = $this->makeUser(7, 'bob', 'bob@x.com');

        $roleObj       = new \yii\rbac\Role();
        $roleObj->name = 'operator';

        $auth = $this->getMockBuilder(\yii\rbac\ManagerInterface::class)->getMock();
        $auth->method('getRolesByUser')->willReturn(['operator' => $roleObj]);
        \Yii::$app->set('authManager', $auth);

        $form = UserForm::fromUser($user);
        $this->assertSame('operator', $form->role);
    }

    public function testAuthSourceOptionsContainsBothChoices(): void
    {
        $opts = UserForm::authSourceOptions();
        $this->assertArrayHasKey(User::AUTH_SOURCE_LOCAL, $opts);
        $this->assertArrayHasKey(User::AUTH_SOURCE_LDAP, $opts);
    }

    public function testInvalidAuthSourceFailsValidation(): void
    {
        $form = $this->makeForm(['auth_source' => 'wat']);
        $form->validate(['auth_source']);
        $this->assertTrue($form->hasErrors('auth_source'));
    }

    public function testLocalUserStillRequiresPasswordOnCreate(): void
    {
        $form = $this->makeForm(['auth_source' => User::AUTH_SOURCE_LOCAL, 'password' => '']);
        $form->validate();
        $this->assertTrue($form->hasErrors('password'));
    }

    public function testLdapUserDoesNotRequirePasswordOnCreate(): void
    {
        $form = $this->makeForm([
            'auth_source' => User::AUTH_SOURCE_LDAP,
            'username' => 'ldapuser',
            'email' => 'ldap@example.com',
            'role' => 'viewer',
            'password' => '',
        ]);
        $form->validate();
        $this->assertFalse($form->hasErrors('password'));
    }

    public function testLdapDnLengthIsValidated(): void
    {
        $form = $this->makeForm(['ldap_dn' => str_repeat('x', 513)]);
        $form->validate(['ldap_dn']);
        $this->assertTrue($form->hasErrors('ldap_dn'));
    }

    public function testLdapUidLengthIsValidated(): void
    {
        $form = $this->makeForm(['ldap_uid' => str_repeat('x', 256)]);
        $form->validate(['ldap_uid']);
        $this->assertTrue($form->hasErrors('ldap_uid'));
    }

    public function testFromUserPopulatesLdapFields(): void
    {
        $user = $this->makeUser(11, 'ldapuser', 'l@x.com');
        $user->auth_source = User::AUTH_SOURCE_LDAP;
        $user->ldap_dn = 'uid=ldapuser,dc=test';
        $user->ldap_uid = 'guid-1';

        $auth = $this->getMockBuilder(\yii\rbac\ManagerInterface::class)->getMock();
        $auth->method('getRolesByUser')->willReturn([]);
        \Yii::$app->set('authManager', $auth);

        $form = UserForm::fromUser($user);
        $this->assertSame(User::AUTH_SOURCE_LDAP, $form->auth_source);
        $this->assertSame('uid=ldapuser,dc=test', $form->ldap_dn);
        $this->assertSame('guid-1', $form->ldap_uid);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Returns a UserForm subclass that strips unique validators so tests
     * don't need a DB connection.
     */
    private function makeForm(array $overrides = []): UserForm
    {
        $form = new class extends UserForm {
            public function rules(): array
            {
                return array_values(array_filter(parent::rules(), static function ($rule) {
                    return ($rule[1] ?? null) !== 'unique';
                }));
            }
        };
        foreach ($overrides as $k => $v) {
            $form->$k = $v;
        }
        return $form;
    }

    private function makeUser(int $id, string $username, string $email): User
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $user->method('attributes')->willReturn([
            'id', 'username', 'email', 'status', 'is_superadmin',
            'auth_source', 'ldap_dn', 'ldap_uid', 'last_synced_at',
        ]);
        $user->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($user, [
            'id'             => $id,
            'username'       => $username,
            'email'          => $email,
            'status'         => User::STATUS_ACTIVE,
            'is_superadmin'  => false,
            'auth_source'    => User::AUTH_SOURCE_LOCAL,
            'ldap_dn'        => null,
            'ldap_uid'       => null,
            'last_synced_at' => null,
        ]);
        return $user;
    }
}
