<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\UsersController;
use app\models\ApiToken;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The users API and user.ldap_sync_disabled, the mark of an account that
 * ldap/sync deactivated because the directory no longer had the user
 * (CLAUDE.md security rule 15): every answer with a user carries it as
 * ldap_sync_disabled, read-only. POST and PUT ignore it in the body, as they
 * ignore the other read-only fields, and a PUT that changes the status
 * clears it.
 */
class UsersLdapSyncMarkTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private UsersController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new UsersController('api/v1/users', \Yii::$app);
        $admin = $this->createUserWithRole('mark_api_admin', 'admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        ['raw' => $raw] = ApiToken::generate((int)$admin->id, 'mark-api');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    public function testEveryAnswerWithAUserCarriesTheMark(): void
    {
        $bySync = $this->deactivatedByLdapSync('view_sync');
        $inAnsilume = $this->ldapUser('view_ansilume');
        $inAnsilume->status = User::STATUS_INACTIVE;
        $inAnsilume->save(false);

        $this->assertTrue($this->data($this->ctrl->actionView((int)$bySync->id))['ldap_sync_disabled']);
        $this->assertFalse($this->data($this->ctrl->actionView((int)$inAnsilume->id))['ldap_sync_disabled']);

        \Yii::$app->request->setQueryParams(['page' => '1']);
        $listed = $this->ctrl->actionIndex();
        $this->assertArrayHasKey('data', $listed);
        $this->assertIsArray($listed['data']);
        $byId = [];
        foreach ($listed['data'] as $row) {
            $this->assertIsArray($row);
            $this->assertIsBool($row['ldap_sync_disabled'] ?? null, 'every listed user carries the mark');
            $byId[(int)$row['id']] = $row['ldap_sync_disabled'];
        }
        $this->assertTrue($byId[(int)$bySync->id] ?? null, 'the newest users are on the first page');
        $this->assertFalse($byId[(int)$inAnsilume->id] ?? null);
    }

    /**
     * regression: PUT /api/v1/users/{id} left the mark when it changed the
     * status.
     */
    public function testRegressionAPutThatChangesTheStatusClearsTheMark(): void
    {
        $user = $this->deactivatedByLdapSync('put_status');
        $this->setBody(['status' => User::STATUS_ACTIVE]);

        $data = $this->data($this->ctrl->actionUpdate((int)$user->id));

        $this->assertSame(User::STATUS_ACTIVE, $data['status']);
        $this->assertFalse($data['ldap_sync_disabled']);
        $this->assertSame([User::STATUS_ACTIVE, false], $this->stateOf($user));
    }

    public function testAPutThatKeepsTheStatusKeepsTheMark(): void
    {
        $user = $this->deactivatedByLdapSync('put_keep');
        $email = 'api-kept-' . uniqid('', true) . '@example.com';
        $this->setBody(['email' => $email, 'status' => User::STATUS_INACTIVE]);

        $data = $this->data($this->ctrl->actionUpdate((int)$user->id));

        $this->assertSame($email, $data['email']);
        $this->assertTrue($data['ldap_sync_disabled']);
        $this->assertSame([User::STATUS_INACTIVE, true], $this->stateOf($user));
    }

    /**
     * Read-only: a body that sets or clears the mark changes nothing.
     */
    public function testAPutIgnoresTheMarkInTheBody(): void
    {
        $marked = $this->deactivatedByLdapSync('put_clear');
        $unmarked = $this->ldapUser('put_set');
        $unmarked->status = User::STATUS_INACTIVE;
        $unmarked->save(false);

        $this->setBody(['ldap_sync_disabled' => false]);
        $this->assertTrue($this->data($this->ctrl->actionUpdate((int)$marked->id))['ldap_sync_disabled']);
        $this->setBody(['ldap_sync_disabled' => true]);
        $this->assertFalse($this->data($this->ctrl->actionUpdate((int)$unmarked->id))['ldap_sync_disabled']);

        $this->assertSame([User::STATUS_INACTIVE, true], $this->stateOf($marked));
        $this->assertSame([User::STATUS_INACTIVE, false], $this->stateOf($unmarked));
    }

    public function testAPostIgnoresTheMarkInTheBody(): void
    {
        $username = 'api-mark-new-' . uniqid();
        $this->setBody([
            'username' => $username,
            'email' => $username . '@example.com',
            'auth_source' => User::AUTH_SOURCE_LDAP,
            'status' => User::STATUS_INACTIVE,
            'ldap_sync_disabled' => true,
        ]);

        $data = $this->data($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertFalse($data['ldap_sync_disabled']);
        $created = User::findOne(['username' => $username]);
        $this->assertNotNull($created);
        $this->assertSame([User::STATUS_INACTIVE, false], $this->stateOf($created));
    }

    private function deactivatedByLdapSync(string $suffix): User
    {
        $user = $this->ldapUser($suffix);
        $user->status = User::STATUS_INACTIVE;
        $user->ldap_sync_disabled = true;
        $user->save(false);

        return $user;
    }

    private function ldapUser(string $suffix): User
    {
        $user = $this->createUser('mark_api_' . $suffix);
        $user->markAsLdapManaged();
        $user->ldap_dn = 'uid=' . $user->username . ',dc=test';
        $user->ldap_uid = 'guid-' . $user->username;
        $user->save(false);

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    /**
     * The user of a successful answer.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function data(array $result): array
    {
        $this->assertArrayHasKey('data', $result, json_encode($result) ?: '');
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    /**
     * @return array{0: int, 1: bool} the stored status and mark
     */
    private function stateOf(User $user): array
    {
        $fresh = User::findOne($user->id);
        $this->assertNotNull($fresh);

        return [(int)$fresh->status, (bool)$fresh->ldap_sync_disabled];
    }
}
