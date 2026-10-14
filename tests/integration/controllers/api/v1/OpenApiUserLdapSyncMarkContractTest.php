<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\LdapSyncDisabledBehavior;
use app\controllers\api\v1\UsersController;
use app\models\ApiToken;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Request;

/**
 * What web/openapi.yaml says about User.ldap_sync_disabled is what the users
 * API answers: every user it returns has exactly the fields of User, the
 * mark among them as a boolean, true for an account that ldap/sync
 * deactivated because the directory no longer had the user; POST and PUT
 * ignore it in the body (UserInput does not document it), and a PUT that
 * changes the status clears it (LdapSyncDisabledBehavior, CLAUDE.md
 * security rule 15). UsersLdapSyncMarkTest covers the API in full.
 *
 * Regression (wave 5g of 2.11.0): the API answers ldap_sync_disabled, which
 * the spec did not document, so User no longer listed exactly the fields
 * the API returns. Fails on the spec from before.
 */
class OpenApiUserLdapSyncMarkContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use OpenApiWorkflowAssertions;
    use TeamScopeFixtures;

    private const USER = '/api/v1/users/{id}';

    /**
     * regression: User did not document ldap_sync_disabled. It documents
     * it as a read-only boolean, UserInput does not document it, and the
     * answers of GET of one user and of the list match User exactly.
     */
    public function testRegressionTheMarkIsDocumentedAsTheApiAnswersIt(): void
    {
        $property = ['components', 'schemas', 'User', 'properties', LdapSyncDisabledBehavior::COLUMN];
        $this->assertSame('boolean', $this->specAt([...$property, 'type']));
        $this->assertTrue($this->specAt([...$property, 'readOnly']));
        $this->assertSame(
            'True when `ldap/sync` deactivated the account because the directory no longer had the user: the user\'s next LDAP sign-in, or an `ldap/sync` that finds them, activates it again.'
                . ' False for every other account; one deactivated in Ansilume stays inactive whatever the directory says. Read-only: POST and PUT ignore it, and a PUT that changes `status` clears it.',
            $this->specText([...$property, 'description'])
        );
        $input = $this->specAt(['components', 'schemas', 'UserInput', 'properties']);
        $this->assertIsArray($input);
        $this->assertArrayNotHasKey(LdapSyncDisabledBehavior::COLUMN, $input, 'UserInput does not document a read-only field.');

        $marked = $this->ldapUser('spec-mark-sync', true);
        $this->signInAsSuperadmin();
        $one = $this->data($this->controller()->actionView((int)$marked->id));
        $this->assertMatchesSchema('User', $one);
        $this->assertTrue($one[LdapSyncDisabledBehavior::COLUMN], 'An account that ldap/sync deactivated.');

        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setQueryParams(['page' => '1']);
        $this->assertMatchesSchema('User', $this->listItem($this->controller()->actionIndex(), (int)$marked->id));
    }

    /**
     * The PUT operation and User.ldap_sync_disabled say that a change of
     * the status clears the mark and that the body does not set it, and so
     * the API answers.
     */
    public function testAChangeOfTheStatusClearsTheMarkAndTheBodyDoesNotSetIt(): void
    {
        $this->assertStringContainsString(
            'A change of `status` clears `ldap_sync_disabled`, so an LDAP account deactivated this way stays inactive whatever the directory says.',
            $this->specText(['paths', self::USER, 'put', 'description'])
        );
        $marked = $this->ldapUser('spec-mark-status', true);
        $unmarked = $this->ldapUser('spec-mark-body', false);
        $this->signInAsSuperadmin();

        $this->setBody([LdapSyncDisabledBehavior::COLUMN => true]);
        $this->assertFalse($this->data($this->controller()->actionUpdate((int)$unmarked->id))[LdapSyncDisabledBehavior::COLUMN], 'PUT ignores the mark in the body.');
        $this->assertSame(200, \Yii::$app->response->statusCode);

        $this->setBody(['status' => User::STATUS_ACTIVE]);
        $answer = $this->data($this->controller()->actionUpdate((int)$marked->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertMatchesSchema('User', $answer);
        $this->assertSame(User::STATUS_ACTIVE, $answer['status']);
        $this->assertFalse($answer[LdapSyncDisabledBehavior::COLUMN], 'A change of the status clears the mark.');
        $this->assertFalse((bool)User::findOne($marked->id)?->ldap_sync_disabled, 'The stored mark is cleared too.');
    }

    private function controller(): UsersController
    {
        \Yii::$app->response->statusCode = 200;

        return new UsersController('api/v1/users', $this->app());
    }

    /**
     * Signs a new superadmin in with an API token of their own.
     */
    private function signInAsSuperadmin(): void
    {
        $superadmin = $this->createUserWithRole('spec-mark-admin', 'admin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        ['raw' => $raw] = ApiToken::generate((int)$superadmin->id, 'spec');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * An inactive LDAP account, marked as one that ldap/sync deactivated
     * when $deactivatedByLdapSync, else as one deactivated in Ansilume.
     */
    private function ldapUser(string $suffix, bool $deactivatedByLdapSync): User
    {
        $user = $this->createUser($suffix);
        $user->markAsLdapManaged();
        $user->ldap_dn = 'uid=' . $user->username . ',dc=test';
        $user->ldap_uid = 'guid-' . $user->username;
        $user->status = User::STATUS_INACTIVE;
        $user->ldap_sync_disabled = $deactivatedByLdapSync;
        $user->save(false);

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams($body);
    }
}
