<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\controllers\api\v1\UsersController;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Integration tests for the LDAP-aware paths of the Users API.
 *
 * Covers:
 *  - auth_source defaults to local when omitted
 *  - LDAP creation requires no password and accepts ldap_dn / ldap_uid
 *  - Local creation rejects empty password
 *  - auth_source is immutable after creation (422)
 *  - Password changes are blocked for LDAP-backed users (422)
 *  - LDAP metadata changes are ignored on local users
 *  - serialize() exposes auth_source / ldap_dn / ldap_uid / last_synced_at
 */
class UsersApiLdapTest extends WebControllerTestCase
{
    private function makeAdmin(): User
    {
        $admin = $this->createUser('api-admin');
        $admin->is_superadmin = true;
        $admin->save(false);

        $auth = \Yii::$app->authManager;
        $role = $auth->getRole('admin');
        if ($role !== null) {
            $auth->revokeAll((string)$admin->id);
            $auth->assign($role, (string)$admin->id);
        }
        return $admin;
    }

    private function makeController(): UsersController
    {
        return new UsersController('api/v1/users', \Yii::$app);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateLocalUserRequiresPassword(): void
    {
        $this->loginAs($this->makeAdmin());
        $this->setPost([
            'username' => 'no-pw-' . uniqid('', true),
            'email' => 'nopw-' . uniqid('', true) . '@example.com',
            'auth_source' => User::AUTH_SOURCE_LOCAL,
            // no password
        ]);

        $result = $this->makeController()->actionCreate();
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertStringContainsString('Password is required', $result['error']['message']);
    }

    public function testCreateLocalUserDefaultsAuthSourceToLocal(): void
    {
        $this->loginAs($this->makeAdmin());
        $this->setPost([
            'username' => 'localdef-' . uniqid('', true),
            'email' => 'localdef-' . uniqid('', true) . '@example.com',
            'password' => 'longenough',
        ]);

        $result = $this->makeController()->actionCreate();
        $this->assertArrayHasKey('data', $result);
        $this->assertSame(User::AUTH_SOURCE_LOCAL, $result['data']['auth_source']);
        $this->assertNull($result['data']['ldap_dn']);
        $this->assertNull($result['data']['ldap_uid']);
    }

    public function testCreateLdapUserSucceedsWithoutPassword(): void
    {
        $this->loginAs($this->makeAdmin());
        $username = 'ldap-create-' . uniqid('', true);
        $this->setPost([
            'username' => $username,
            'email' => $username . '@example.com',
            'auth_source' => User::AUTH_SOURCE_LDAP,
            'ldap_dn' => 'uid=' . $username . ',dc=test',
            'ldap_uid' => 'guid-' . $username,
        ]);

        $result = $this->makeController()->actionCreate();
        $this->assertArrayHasKey('data', $result);
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame(User::AUTH_SOURCE_LDAP, $result['data']['auth_source']);
        $this->assertSame('uid=' . $username . ',dc=test', $result['data']['ldap_dn']);
        $this->assertSame('guid-' . $username, $result['data']['ldap_uid']);

        $persisted = User::findOne($result['data']['id']);
        $this->assertNotNull($persisted);
        $this->assertSame(User::LDAP_PASSWORD_SENTINEL, $persisted->password_hash);
        $this->assertTrue($persisted->isLdap());
    }

    public function testCreateLdapUserRejectsPassword(): void
    {
        $this->loginAs($this->makeAdmin());
        $this->setPost([
            'username' => 'ldap-pw-' . uniqid('', true),
            'email' => 'ldap-pw-' . uniqid('', true) . '@example.com',
            'auth_source' => User::AUTH_SOURCE_LDAP,
            'password' => 'should-be-rejected',
        ]);

        $result = $this->makeController()->actionCreate();
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertStringContainsString('not accepted', $result['error']['message']);
    }

    public function testCreateRejectsInvalidAuthSource(): void
    {
        $this->loginAs($this->makeAdmin());
        $this->setPost([
            'username' => 'invalid-as-' . uniqid('', true),
            'email' => 'invalid-as-' . uniqid('', true) . '@example.com',
            'auth_source' => 'oauth',
        ]);

        $result = $this->makeController()->actionCreate();
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertStringContainsString('Invalid auth_source', $result['error']['message']);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function testUpdateRejectsAuthSourceChangeFromLocalToLdap(): void
    {
        $this->loginAs($this->makeAdmin());
        $local = $this->createUser('flip-local');

        $this->setPost(['auth_source' => User::AUTH_SOURCE_LDAP]);
        $result = $this->makeController()->actionUpdate($local->id);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertStringContainsString('immutable', $result['error']['message']);

        $refreshed = User::findOne($local->id);
        $this->assertSame(User::AUTH_SOURCE_LOCAL, $refreshed->auth_source);
    }

    public function testUpdateRejectsAuthSourceChangeFromLdapToLocal(): void
    {
        $this->loginAs($this->makeAdmin());

        $ldapUser = new User();
        $ldapUser->username = 'flip-ldap-' . uniqid('', true);
        $ldapUser->email = $ldapUser->username . '@example.com';
        $ldapUser->markAsLdapManaged();
        $ldapUser->generateAuthKey();
        $ldapUser->status = User::STATUS_ACTIVE;
        $ldapUser->created_at = time();
        $ldapUser->updated_at = time();
        $ldapUser->save(false);

        $this->setPost(['auth_source' => User::AUTH_SOURCE_LOCAL]);
        $result = $this->makeController()->actionUpdate($ldapUser->id);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);

        $refreshed = User::findOne($ldapUser->id);
        $this->assertTrue($refreshed->isLdap());
        $this->assertSame(User::LDAP_PASSWORD_SENTINEL, $refreshed->password_hash);
    }

    public function testUpdateAllowsAuthSourceWhenUnchanged(): void
    {
        $this->loginAs($this->makeAdmin());
        $local = $this->createUser('keep-local');

        $this->setPost([
            'auth_source' => User::AUTH_SOURCE_LOCAL,
            'email' => 'updated-' . uniqid('', true) . '@example.com',
        ]);
        $result = $this->makeController()->actionUpdate($local->id);

        $this->assertArrayHasKey('data', $result);
        $this->assertStringStartsWith('updated-', $result['data']['email']);
    }

    public function testUpdateRejectsPasswordForLdapUser(): void
    {
        $this->loginAs($this->makeAdmin());
        $ldapUser = new User();
        $ldapUser->username = 'no-pw-ldap-' . uniqid('', true);
        $ldapUser->email = $ldapUser->username . '@example.com';
        $ldapUser->markAsLdapManaged();
        $ldapUser->generateAuthKey();
        $ldapUser->status = User::STATUS_ACTIVE;
        $ldapUser->created_at = time();
        $ldapUser->updated_at = time();
        $ldapUser->save(false);

        $this->setPost(['password' => 'newpassword123']);
        $result = $this->makeController()->actionUpdate($ldapUser->id);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertStringContainsString('Cannot set password', $result['error']['message']);

        // Sentinel must remain — confirms no setPassword() leak happened.
        $refreshed = User::findOne($ldapUser->id);
        $this->assertSame(User::LDAP_PASSWORD_SENTINEL, $refreshed->password_hash);
    }

    public function testUpdateAllowsLdapMetadataForLdapUser(): void
    {
        $this->loginAs($this->makeAdmin());
        $ldapUser = new User();
        $ldapUser->username = 'meta-ldap-' . uniqid('', true);
        $ldapUser->email = $ldapUser->username . '@example.com';
        $ldapUser->markAsLdapManaged();
        $ldapUser->generateAuthKey();
        $ldapUser->status = User::STATUS_ACTIVE;
        $ldapUser->created_at = time();
        $ldapUser->updated_at = time();
        $ldapUser->save(false);

        $this->setPost([
            'ldap_dn' => 'uid=newdn,dc=test',
            'ldap_uid' => 'guid-rebound',
        ]);
        $result = $this->makeController()->actionUpdate($ldapUser->id);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('uid=newdn,dc=test', $result['data']['ldap_dn']);
        $this->assertSame('guid-rebound', $result['data']['ldap_uid']);
    }

    public function testUpdateIgnoresLdapMetadataForLocalUser(): void
    {
        // Local user must never grow ldap_dn / ldap_uid via the API — those
        // fields would mislead the lifecycle sync into treating the account
        // as directory-managed even though auth_source is still 'local'.
        $this->loginAs($this->makeAdmin());
        $local = $this->createUser('ignore-meta');

        $this->setPost([
            'ldap_dn' => 'uid=evil,dc=test',
            'ldap_uid' => 'guid-evil',
        ]);
        $result = $this->makeController()->actionUpdate($local->id);

        $this->assertArrayHasKey('data', $result);

        $refreshed = User::findOne($local->id);
        $this->assertNull($refreshed->ldap_dn);
        $this->assertNull($refreshed->ldap_uid);
    }

    public function testUpdateAllowsPasswordChangeForLocalUser(): void
    {
        $this->loginAs($this->makeAdmin());
        $local = $this->createUser('pw-local');
        $oldHash = (string)$local->password_hash;

        $this->setPost(['password' => 'updated-password-x']);
        $result = $this->makeController()->actionUpdate($local->id);

        $this->assertArrayHasKey('data', $result);

        $refreshed = User::findOne($local->id);
        $this->assertNotSame($oldHash, $refreshed->password_hash);
        $this->assertTrue($refreshed->validatePassword('updated-password-x'));
    }

    // ── serialize ─────────────────────────────────────────────────────────────

    public function testViewExposesLdapFields(): void
    {
        $this->loginAs($this->makeAdmin());

        $ldapUser = new User();
        $ldapUser->username = 'view-ldap-' . uniqid('', true);
        $ldapUser->email = $ldapUser->username . '@example.com';
        $ldapUser->markAsLdapManaged();
        $ldapUser->ldap_dn = 'uid=visible,dc=test';
        $ldapUser->ldap_uid = 'guid-visible';
        $ldapUser->last_synced_at = 1700000000;
        $ldapUser->generateAuthKey();
        $ldapUser->status = User::STATUS_ACTIVE;
        $ldapUser->created_at = time();
        $ldapUser->updated_at = time();
        $ldapUser->save(false);

        $result = $this->makeController()->actionView($ldapUser->id);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame(User::AUTH_SOURCE_LDAP, $result['data']['auth_source']);
        $this->assertSame('uid=visible,dc=test', $result['data']['ldap_dn']);
        $this->assertSame('guid-visible', $result['data']['ldap_uid']);
        $this->assertSame(1700000000, $result['data']['last_synced_at']);
        $this->assertArrayNotHasKey('password_hash', $result['data']);
    }
}
