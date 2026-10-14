<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\User;
use app\models\UserForm;
use app\services\RoleGrantPolicy;
use PHPUnit\Framework\TestCase;
use yii\rbac\ManagerInterface;

/**
 * Tests for UserForm — role options and validation rules.
 * DB-requiring validators (unique) are excluded; we test pure logic.
 * fromUser() reads the roles assigned to the user from the database:
 * tests/integration/models/UserFormAssignedRoleTest.php. The forms here are
 * a superadmin's, who may assign every role (RoleGrantPolicy), unless a test
 * says otherwise; what others may give: UserFormGrantTest.
 */
class UserFormTest extends TestCase
{
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

    /**
     * Fails closed: a form without a signed-in user, as in a console
     * application, assigns no role and takes no superadmin flag.
     */
    public function testWithoutASignedInUserTheFormAssignsNoRoleAndTakesNoFlag(): void
    {
        $form = new UserForm();
        $this->assertTrue($form->load(['UserForm' => ['role' => 'viewer', 'is_superadmin' => '1']]));

        $this->assertFalse($form->validate(['role']));
        $this->assertSame([sprintf(RoleGrantPolicy::ROLE_REFUSED, 'viewer')], $form->getErrors('role'));
        $this->assertFalse($form->is_superadmin, 'the posted flag is ignored');
        $this->assertFalse($form->canSetSuperadmin());
    }

    public function testASuperadminsFormTakesTheFlag(): void
    {
        $form = $this->makeForm();
        $this->assertTrue($form->load(['is_superadmin' => '1'], ''));

        $this->assertTrue($form->is_superadmin);
        $this->assertTrue($form->canSetSuperadmin());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Returns a superadmin's UserForm subclass that strips unique validators
     * so tests don't need a DB connection.
     */
    private function makeForm(array $overrides = []): UserForm
    {
        /** @var ManagerInterface $auth */
        $auth = \Yii::$app->authManager;
        $grants = new RoleGrantPolicy($auth, new User(['is_superadmin' => true]));
        $form = new class ($grants) extends UserForm {
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
}
