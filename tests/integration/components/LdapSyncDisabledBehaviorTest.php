<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\LdapSyncDisabledBehavior;
use app\models\User;
use app\tests\integration\DbTestCase;

/**
 * user.ldap_sync_disabled follows every change of a user's status: a save
 * that changes the status clears the mark unless it sets the mark itself, a
 * save that leaves the status as it is keeps it (LdapSyncDisabledBehavior,
 * attached in User::behaviors(); CLAUDE.md security rule 15). Every status
 * writer saves the User, so none of them can forget the mark: the status
 * toggle, the Edit User form and PUT /api/v1/users/{id} are proven in
 * UserLdapSyncMarkTest and UsersLdapSyncMarkTest.
 */
class LdapSyncDisabledBehaviorTest extends DbTestCase
{
    public function testAChangeOfTheStatusClearsTheMark(): void
    {
        $user = $this->deactivatedByLdapSync();

        $user->status = User::STATUS_ACTIVE;
        $this->assertTrue($user->save(false));

        $this->assertSame([User::STATUS_ACTIVE, false], $this->stored($user));
    }

    /**
     * Deactivated again after it was activated, the account has no mark:
     * deactivated in Ansilume.
     */
    public function testDeactivatingAnActiveAccountLeavesNoMark(): void
    {
        $user = $this->deactivatedByLdapSync();
        $user->status = User::STATUS_ACTIVE;
        $user->save(false);

        $user->status = User::STATUS_INACTIVE;
        $user->save(false);

        $this->assertSame([User::STATUS_INACTIVE, false], $this->stored($user));
    }

    public function testASaveThatKeepsTheStatusKeepsTheMark(): void
    {
        $user = $this->deactivatedByLdapSync();

        $user->email = 'renamed-' . uniqid('', true) . '@example.com';
        $user->status = User::STATUS_INACTIVE;
        $this->assertTrue($user->save(false));

        $this->assertSame([User::STATUS_INACTIVE, true], $this->stored($user));
    }

    /**
     * A status loaded as the form posts it ("0") is the stored status: no
     * change, so the mark stays.
     */
    public function testTheSameStatusAsATextIsNoChange(): void
    {
        $user = $this->deactivatedByLdapSync();

        $user->status = '0';
        $user->save(false);

        $this->assertSame([User::STATUS_INACTIVE, true], $this->stored($user));
    }

    /**
     * ldap/sync deactivates an account and sets the mark in one save.
     */
    public function testASaveThatSetsTheMarkWithTheStatusKeepsIt(): void
    {
        $user = $this->createUser('mark-set');

        $user->status = User::STATUS_INACTIVE;
        $user->ldap_sync_disabled = true;
        $user->save(false);

        $this->assertSame([User::STATUS_INACTIVE, true], $this->stored($user));
    }

    /**
     * The provisioner activates an account ldap/sync deactivated and clears
     * the mark in one save.
     */
    public function testASaveThatClearsTheMarkWithTheStatusClearsIt(): void
    {
        $user = $this->deactivatedByLdapSync();

        $user->status = User::STATUS_ACTIVE;
        $user->ldap_sync_disabled = false;
        $user->save(false);

        $this->assertSame([User::STATUS_ACTIVE, false], $this->stored($user));
    }

    /**
     * A save limited to the status (as the E2E seed resets its users with)
     * leaves the mark out of its UPDATE; the behaviour saves it right after.
     */
    public function testASaveLimitedToTheStatusClearsTheMarkToo(): void
    {
        $user = $this->deactivatedByLdapSync();

        $user->status = User::STATUS_ACTIVE;
        $this->assertTrue($user->save(false, ['status']));

        $this->assertSame([User::STATUS_ACTIVE, false], $this->stored($user));
        $this->assertFalse($user->isAttributeChanged(LdapSyncDisabledBehavior::COLUMN), 'the model holds what is stored');
    }

    /**
     * A save limited to other attributes saves no status, so the mark stays
     * as stored.
     */
    public function testASaveLimitedToOtherAttributesLeavesTheMarkAsStored(): void
    {
        $user = $this->deactivatedByLdapSync();
        $email = 'only-email-' . uniqid('', true) . '@example.com';

        $user->email = $email;
        $user->status = User::STATUS_ACTIVE;
        $this->assertTrue($user->save(false, ['email']));

        $this->assertSame([User::STATUS_INACTIVE, true], $this->stored($user));
        $this->assertSame($email, User::findOne($user->id)?->email);
    }

    /**
     * A new account keeps the mark it is created with: only an update
     * changes a status.
     */
    public function testANewAccountKeepsTheMarkItIsCreatedWith(): void
    {
        $user = new User();
        $user->username = 'mark-new-' . uniqid('', true);
        $user->email = $user->username . '@example.com';
        $user->markAsLdapManaged();
        $user->generateAuthKey();
        $user->status = User::STATUS_INACTIVE;
        $user->ldap_sync_disabled = true;
        $this->assertTrue($user->save(false));

        $this->assertSame([User::STATUS_INACTIVE, true], $this->stored($user));
    }

    /**
     * An inactive LDAP account that ldap/sync deactivated: status and mark
     * stored in one save.
     */
    private function deactivatedByLdapSync(): User
    {
        $user = $this->createUser('mark');
        $user->markAsLdapManaged();
        $user->status = User::STATUS_INACTIVE;
        $user->ldap_sync_disabled = true;
        $user->save(false);
        $this->assertSame([User::STATUS_INACTIVE, true], $this->stored($user), 'the fixture');

        return $user;
    }

    /**
     * The status and the mark as the database holds them.
     *
     * @return array{0: int, 1: bool}
     */
    private function stored(User $user): array
    {
        $row = (new \yii\db\Query())
            ->select(['status', 'ldap_sync_disabled'])
            ->from(User::tableName())
            ->where(['id' => $user->id])
            ->one();
        $this->assertIsArray($row);

        return [(int)$row['status'], (bool)$row['ldap_sync_disabled']];
    }
}
