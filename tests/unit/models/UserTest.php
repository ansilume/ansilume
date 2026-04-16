<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\User;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for User pure helpers — setPassword, validatePassword, generateAuthKey, isActive.
 * These methods rely on Yii::$app->security which is available in the test bootstrap.
 */
class UserTest extends TestCase
{
    // ── setPassword / validatePassword ────────────────────────────────────────

    public function testSetPasswordCreatesHash(): void
    {
        $user = $this->makeUser();
        $user->setPassword('secret123');
        $this->assertNotEmpty($user->password_hash);
        $this->assertNotSame('secret123', $user->password_hash);
    }

    public function testValidatePasswordReturnsTrueForCorrectPassword(): void
    {
        $user = $this->makeUser();
        $user->setPassword('correctpassword');
        $this->assertTrue($user->validatePassword('correctpassword'));
    }

    public function testValidatePasswordReturnsFalseForWrongPassword(): void
    {
        $user = $this->makeUser();
        $user->setPassword('correctpassword');
        $this->assertFalse($user->validatePassword('wrongpassword'));
    }

    public function testDifferentPasswordsProduceDifferentHashes(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $user1->setPassword('password1');
        $user2->setPassword('password2');
        $this->assertNotSame($user1->password_hash, $user2->password_hash);
    }

    public function testSamePasswordProducesDifferentHashesDueToBcryptSalt(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $user1->setPassword('samepassword');
        $user2->setPassword('samepassword');
        // bcrypt salts differ each time
        $this->assertNotSame($user1->password_hash, $user2->password_hash);
    }

    public function testSetPasswordRotatesAuthKey(): void
    {
        // Regression: stolen session cookies (bound to auth_key) must be
        // invalidated on password change, so setPassword() must also rotate
        // auth_key — not just password_hash.
        $user = $this->makeUser();
        $user->generateAuthKey();
        $before = (string)$user->auth_key;
        $this->assertNotSame('', $before);

        $user->setPassword('anynewpassword');

        $this->assertNotSame($before, (string)$user->auth_key, 'setPassword() must rotate auth_key');
        $this->assertFalse($user->validateAuthKey($before), 'old auth_key must no longer validate');
    }

    // ── generateAuthKey ───────────────────────────────────────────────────────

    public function testGenerateAuthKeyCreatesNonEmptyKey(): void
    {
        $user = $this->makeUser();
        $user->generateAuthKey();
        $this->assertNotEmpty($user->auth_key);
    }

    public function testGenerateAuthKeyProducesUniqueKeys(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $user1->generateAuthKey();
        $user2->generateAuthKey();
        $this->assertNotSame($user1->auth_key, $user2->auth_key);
    }

    // ── validateAuthKey ───────────────────────────────────────────────────────

    public function testValidateAuthKeyReturnsTrueForMatchingKey(): void
    {
        $user = $this->makeUser();
        $user->generateAuthKey();
        $this->assertTrue($user->validateAuthKey($user->auth_key));
    }

    public function testValidateAuthKeyReturnsFalseForWrongKey(): void
    {
        $user = $this->makeUser();
        $user->generateAuthKey();
        $this->assertFalse($user->validateAuthKey('wrong-key'));
    }

    // ── isActive ──────────────────────────────────────────────────────────────

    public function testIsActiveReturnsTrueForActiveStatus(): void
    {
        $user = $this->makeUser(User::STATUS_ACTIVE);
        $this->assertTrue($user->isActive());
    }

    public function testIsActiveReturnsFalseForInactiveStatus(): void
    {
        $user = $this->makeUser(User::STATUS_INACTIVE);
        $this->assertFalse($user->isActive());
    }

    // ── isLocal / isLdap / markAsLdapManaged ──────────────────────────────────

    public function testIsLocalReturnsTrueForLocalAuthSource(): void
    {
        $user = $this->makeUser();
        $user->auth_source = User::AUTH_SOURCE_LOCAL;
        $this->assertTrue($user->isLocal());
        $this->assertFalse($user->isLdap());
    }

    public function testIsLdapReturnsTrueForLdapAuthSource(): void
    {
        $user = $this->makeUser();
        $user->auth_source = User::AUTH_SOURCE_LDAP;
        $this->assertTrue($user->isLdap());
        $this->assertFalse($user->isLocal());
    }

    public function testMarkAsLdapManagedSetsAuthSourceAndSentinel(): void
    {
        $user = $this->makeUser();
        $user->markAsLdapManaged();
        $this->assertSame(User::AUTH_SOURCE_LDAP, $user->auth_source);
        $this->assertSame(User::LDAP_PASSWORD_SENTINEL, $user->password_hash);
        $this->assertTrue($user->isLdap());
    }

    public function testValidatePasswordAlwaysFailsForLdapUser(): void
    {
        $user = $this->makeUser();
        $user->markAsLdapManaged();
        $this->assertFalse($user->validatePassword('anything'));
        $this->assertFalse($user->validatePassword(User::LDAP_PASSWORD_SENTINEL));
    }

    public function testSetPasswordThrowsForLdapUser(): void
    {
        $user = $this->makeUser();
        $user->markAsLdapManaged();
        $this->expectException(\LogicException::class);
        $user->setPassword('whatever');
    }

    public function testGeneratePasswordResetTokenThrowsForLdapUser(): void
    {
        $user = $this->makeUser();
        $user->markAsLdapManaged();
        $this->expectException(\LogicException::class);
        $user->generatePasswordResetToken();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeUser(int $status = User::STATUS_ACTIVE): User
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $user->method('attributes')->willReturn(
            ['id', 'username', 'email', 'password_hash', 'auth_key',
             'password_reset_token', 'status', 'is_superadmin', 'auth_source',
             'ldap_dn', 'ldap_uid', 'last_synced_at', 'created_at', 'updated_at']
        );
        $user->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($user, [
            'id'                   => 1,
            'username'             => 'testuser',
            'email'                => 'test@example.com',
            'password_hash'        => '',
            'auth_key'             => '',
            'password_reset_token' => null,
            'status'               => $status,
            'is_superadmin'        => false,
            'auth_source'          => User::AUTH_SOURCE_LOCAL,
            'ldap_dn'              => null,
            'ldap_uid'             => null,
            'last_synced_at'       => null,
            'created_at'           => null,
            'updated_at'           => null,
        ]);
        return $user;
    }
}
