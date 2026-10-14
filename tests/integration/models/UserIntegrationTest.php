<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\User;
use app\tests\integration\DbTestCase;

class UserIntegrationTest extends DbTestCase
{
    public function testFindIdentityReturnsActiveUser(): void
    {
        $user = $this->createUser();

        $found = User::findIdentity($user->id);

        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->id);
    }

    public function testFindIdentityReturnsNullForInactiveUser(): void
    {
        $user = $this->createUser();
        \Yii::$app->db->createCommand()
            ->update('{{%user}}', ['status' => User::STATUS_INACTIVE], ['id' => $user->id])
            ->execute();

        $this->assertNull(User::findIdentity($user->id));
    }

    public function testFindIdentityReturnsNullForUnknownId(): void
    {
        $this->assertNull(User::findIdentity(999999));
    }

    public function testFindByUsernameReturnsActiveUser(): void
    {
        $user = $this->createUser('lookup');

        $found = User::findByUsername($user->username);

        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->id);
    }

    public function testFindByUsernameReturnsNullForUnknownUsername(): void
    {
        $this->assertNull(User::findByUsername('no_such_user_xyzabc'));
    }

    public function testValidatePasswordReturnsTrueForCorrectPassword(): void
    {
        $user = $this->createUser();
        // createUser() sets password hash for "test"

        $this->assertTrue($user->validatePassword('test'));
    }

    public function testValidatePasswordReturnsFalseForWrongPassword(): void
    {
        $user = $this->createUser();

        $this->assertFalse($user->validatePassword('wrongpassword'));
    }

    public function testSetPasswordChangesHash(): void
    {
        $user = $this->createUser();
        $oldHash = $user->password_hash;

        $user->setPassword('newpassword123');

        $this->assertNotSame($oldHash, $user->password_hash);
        $this->assertTrue($user->validatePassword('newpassword123'));
    }

    public function testGenerateAuthKeyProducesNonEmptyString(): void
    {
        $user = new User();
        $user->generateAuthKey();

        $this->assertNotEmpty($user->auth_key);
    }

    public function testValidateAuthKeyReturnsTrueForMatchingKey(): void
    {
        $user = $this->createUser();

        $this->assertTrue($user->validateAuthKey($user->auth_key));
    }

    public function testValidateAuthKeyReturnsFalseForWrongKey(): void
    {
        $user = $this->createUser();

        $this->assertFalse($user->validateAuthKey('wrong_key'));
    }

    public function testGetIdReturnsIntegerId(): void
    {
        $user = $this->createUser();

        $this->assertIsInt($user->getId());
        $this->assertSame($user->id, $user->getId());
    }

    public function testGetAuthKeyReturnsAuthKey(): void
    {
        $user = $this->createUser();

        $this->assertSame($user->auth_key, $user->getAuthKey());
    }

    public function testIsActiveReturnsTrueForActiveStatus(): void
    {
        $user = $this->createUser();
        // createUser sets status to 'active' (STATUS_ACTIVE = 10)

        $this->assertTrue($user->isActive());
    }

    public function testFindIdentityByAccessTokenReturnsNullForInvalidToken(): void
    {
        $found = User::findIdentityByAccessToken(str_repeat('x', 64));
        $this->assertNull($found);
    }

    // -- findIdentityByAccessToken with valid token ----------------------------

    public function testFindIdentityByAccessTokenReturnsUserForValidToken(): void
    {
        $user = $this->createUser();
        $result = \app\models\ApiToken::generate($user->id, 'test-token');

        $found = User::findIdentityByAccessToken($result['raw']);

        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->id);
    }

    public function testFindIdentityByAccessTokenReturnsNullForInactiveUserWithValidToken(): void
    {
        $user = $this->createUser();
        $result = \app\models\ApiToken::generate($user->id, 'test-token');

        \Yii::$app->db->createCommand()
            ->update('{{%user}}', ['status' => User::STATUS_INACTIVE], ['id' => $user->id])
            ->execute();

        $this->assertNull(User::findIdentityByAccessToken($result['raw']));
    }

    public function testFindIdentityByAccessTokenReturnsNullForExpiredToken(): void
    {
        $user = $this->createUser();
        $result = \app\models\ApiToken::generate($user->id, 'expired', time() - 1);

        $this->assertNull(User::findIdentityByAccessToken($result['raw']));
    }

    // -- findByUsername edge cases ----------------------------------------------

    public function testFindByUsernameReturnsNullForInactiveUser(): void
    {
        $user = $this->createUser('inactive');
        \Yii::$app->db->createCommand()
            ->update('{{%user}}', ['status' => User::STATUS_INACTIVE], ['id' => $user->id])
            ->execute();

        $this->assertNull(User::findByUsername($user->username));
    }

    // -- activeSuperadminExists --------------------------------------------------

    /**
     * Only a superadmin whose status is active counts, as the only status
     * that signs in: an inactive superadmin, and an active user without the
     * flag, do not.
     */
    public function testActiveSuperadminExistsCountsOnlyActiveSuperadmins(): void
    {
        $inactive = $this->createUser('sa_inactive');
        $this->createUser('not_sa');
        $this->onlySuperadmins([$inactive]);
        $inactive->status = User::STATUS_INACTIVE;
        $inactive->save(false);

        $this->assertFalse(User::activeSuperadminExists(), 'an inactive superadmin does not count');

        $active = $this->createUser('sa_active');
        $this->onlySuperadmins([$inactive, $active]);

        $this->assertTrue(User::activeSuperadminExists());
    }

    /**
     * With an ID, the user of that ID does not count: the only active
     * superadmin has no other, while a second active superadmin is one for
     * each of them; an inactive superadmin is one for nobody.
     */
    public function testActiveSuperadminExistsLeavesOutTheGivenUser(): void
    {
        $only = $this->createUser('sa_only_active');
        $inactive = $this->createUser('sa_inactive');
        $this->onlySuperadmins([$only, $inactive]);
        $inactive->status = User::STATUS_INACTIVE;
        $inactive->save(false);

        $this->assertFalse(User::activeSuperadminExists((int)$only->id), 'no other active superadmin');
        $this->assertTrue(User::activeSuperadminExists((int)$inactive->id), 'the active one is another');

        $second = $this->createUser('sa_second_active');
        $this->onlySuperadmins([$only, $inactive, $second]);

        $this->assertTrue(User::activeSuperadminExists((int)$only->id));
        $this->assertTrue(User::activeSuperadminExists((int)$second->id));
    }

    /**
     * Makes $users the only superadmins, inside the test's transaction.
     *
     * @param list<User> $users
     */
    private function onlySuperadmins(array $users): void
    {
        User::updateAll(['is_superadmin' => 0], ['is_superadmin' => 1]);
        foreach ($users as $user) {
            $user->refresh();
            $user->is_superadmin = true;
            $user->save(false);
        }
    }

    // -- isActive ---------------------------------------------------------------

    public function testIsActiveReturnsFalseForInactiveStatus(): void
    {
        $user = $this->createUser();
        $user->status = User::STATUS_INACTIVE;
        $this->assertFalse($user->isActive());
    }

    // -- password reset token ---------------------------------------------------

    public function testGeneratePasswordResetTokenCreatesTimestampedToken(): void
    {
        $user = $this->createUser();
        $user->generatePasswordResetToken();
        $this->assertNotNull($user->password_reset_token);
        $this->assertStringContainsString('_', $user->password_reset_token);

        // Token ends with underscore + timestamp
        $parts = explode('_', $user->password_reset_token);
        $timestamp = (int)end($parts);
        $this->assertGreaterThanOrEqual(time() - 2, $timestamp);
    }

    public function testRemovePasswordResetTokenClearsToken(): void
    {
        $user = $this->createUser();
        $user->generatePasswordResetToken();
        $this->assertNotNull($user->password_reset_token);

        $user->removePasswordResetToken();
        $this->assertNull($user->password_reset_token);
    }

    public function testIsPasswordResetTokenValidReturnsTrueForFreshToken(): void
    {
        $user = $this->createUser();
        $user->generatePasswordResetToken();
        $this->assertTrue($user->isPasswordResetTokenValid());
    }

    public function testIsPasswordResetTokenValidReturnsFalseForExpiredToken(): void
    {
        $user = $this->createUser();
        $user->password_reset_token = 'sometoken_' . (time() - User::PASSWORD_RESET_TOKEN_EXPIRE - 1);
        $this->assertFalse($user->isPasswordResetTokenValid());
    }

    public function testIsPasswordResetTokenValidReturnsFalseForNull(): void
    {
        $user = $this->createUser();
        $user->password_reset_token = null;
        $this->assertFalse($user->isPasswordResetTokenValid());
    }

    public function testIsPasswordResetTokenValidReturnsFalseForEmptyString(): void
    {
        $user = $this->createUser();
        $user->password_reset_token = '';
        $this->assertFalse($user->isPasswordResetTokenValid());
    }

    public function testFindByPasswordResetTokenReturnsUserForValidToken(): void
    {
        $user = $this->createUser();
        $user->generatePasswordResetToken();
        $user->save(false);

        $this->assertNotNull($user->password_reset_token);
        $found = User::findByPasswordResetToken($user->password_reset_token);
        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->id);
    }

    public function testFindByPasswordResetTokenReturnsNullForExpiredToken(): void
    {
        $user = $this->createUser();
        $user->password_reset_token = 'token_' . (time() - User::PASSWORD_RESET_TOKEN_EXPIRE - 10);
        $user->save(false);

        $this->assertNull(User::findByPasswordResetToken($user->password_reset_token));
    }

    public function testFindByPasswordResetTokenReturnsNullForEmptyString(): void
    {
        $this->assertNull(User::findByPasswordResetToken(''));
    }

    public function testFindByPasswordResetTokenReturnsNullForUnknownToken(): void
    {
        $this->assertNull(User::findByPasswordResetToken('nonexistent_' . time()));
    }

    public function testFindByPasswordResetTokenReturnsNullForInactiveUser(): void
    {
        $user = $this->createUser();
        $user->generatePasswordResetToken();
        $user->save(false);

        \Yii::$app->db->createCommand()
            ->update('{{%user}}', ['status' => User::STATUS_INACTIVE], ['id' => $user->id])
            ->execute();

        $this->assertNotNull($user->password_reset_token);
        $this->assertNull(User::findByPasswordResetToken($user->password_reset_token));
    }

    // -- validation -------------------------------------------------------------

    public function testValidationRequiresUsernameAndEmail(): void
    {
        $user = new User();
        $this->assertFalse($user->validate());
        $this->assertArrayHasKey('username', $user->getErrors());
        $this->assertArrayHasKey('email', $user->getErrors());
    }

    public function testValidationRejectsInvalidEmail(): void
    {
        $user = new User();
        $user->username = 'testuser';
        $user->email = 'not-an-email';
        $this->assertFalse($user->validate(['email']));
    }

    public function testValidationRejectsInvalidStatus(): void
    {
        $user = new User();
        $user->status = 99;
        $this->assertFalse($user->validate(['status']));
    }

    public function testValidationAcceptsValidStatus(): void
    {
        $user = new User();
        $user->status = User::STATUS_ACTIVE;
        $this->assertTrue($user->validate(['status']));
        $user->status = User::STATUS_INACTIVE;
        $this->assertTrue($user->validate(['status']));
    }

    public function testValidationEnforcesUniqueUsername(): void
    {
        $user1 = $this->createUser('dup');
        $user2 = new User();
        $user2->username = $user1->username;
        $user2->email = 'unique_' . uniqid('', true) . '@example.com';
        $this->assertFalse($user2->validate(['username']));
    }

    public function testValidationEnforcesUniqueEmail(): void
    {
        $user1 = $this->createUser('dup');
        $user2 = new User();
        $user2->username = 'unique_' . uniqid('', true);
        $user2->email = $user1->email;
        $this->assertFalse($user2->validate(['email']));
    }

    // -- attributeLabels -------------------------------------------------------

    public function testAttributeLabelsReturnsExpectedKeys(): void
    {
        $user = new User();
        $labels = $user->attributeLabels();
        $this->assertArrayHasKey('id', $labels);
        $this->assertArrayHasKey('username', $labels);
        $this->assertArrayHasKey('email', $labels);
        $this->assertArrayHasKey('status', $labels);
        $this->assertArrayHasKey('is_superadmin', $labels);
    }

    // -- tableName / behaviors --------------------------------------------------

    public function testTableName(): void
    {
        $this->assertSame('{{%user}}', User::tableName());
    }

    public function testTimestampBehaviorIsRegistered(): void
    {
        $user = new User();
        $behaviors = $user->behaviors();
        $this->assertContains(\yii\behaviors\TimestampBehavior::class, $behaviors);
    }
}
