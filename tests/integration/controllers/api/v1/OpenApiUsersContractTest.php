<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\UsersController;
use app\models\ApiToken;
use app\models\TeamProject;
use app\models\User;
use app\services\RoleGrantPolicy;
use app\services\UserChangePolicy;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\web\NotFoundHttpException;
use yii\web\Request;

/**
 * What web/openapi.yaml says about who may give roles and the superadmin
 * flag through POST /api/v1/users and PUT /api/v1/users/{id}, and about who
 * may change or delete a user through PUT and DELETE /api/v1/users/{id}, is
 * what UsersController answers: the 403 of POST is UserGrantForbidden, that
 * of PUT UserUpdateForbidden and that of DELETE UserDeleteForbidden, whose
 * examples are the answers of a caller without the permission, of a help
 * desk that may not change the user (UserChangePolicy::CHANGE_REFUSED) or
 * may not assign admin (RoleGrantPolicy::ROLE_REFUSED), of a caller who
 * holds every permission of admin but not the role and changes a user with
 * it (UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED) or gives it
 * (RoleGrantPolicy::ADMIN_ROLE_REFUSED), of a team rule that refuses
 * (UserChangePolicy::TEAM_ACCESS_REFUSED) and of a caller who is no
 * superadmin and sends is_superadmin
 * (RoleGrantPolicy::SUPERADMIN_REFUSED); the operations name the answers in
 * the controller's order; the role and is_superadmin of UserInput and the
 * role of User say how a role is kept, replaced and refused.
 *
 * Regression (wave 5, R-SEC-3/R-SEC-4): the spec said a role "replaces
 * every role assigned to the user", documented no 403 for a role or the
 * flag, and the API took both from anyone with user.create or
 * user.update. Regression (wave 5c): PUT and DELETE documented no 403 for a
 * user the caller may not change, and the API let anyone with user.update
 * or user.delete change or delete any user, a superadmin included. Each
 * fails on the spec from before.
 *
 * Regression (wave 5d): the spec named no refusal for a user whose teams
 * give them more than the caller's teams give the caller
 * (UserChangePolicy::TEAM_ACCESS_REFUSED), for the admin role from a caller
 * who holds its permissions but not the role
 * (RoleGrantPolicy::ADMIN_ROLE_REFUSED), nor the 422 of deactivating the
 * only superadmin (UsersController::ONLY_SUPERADMIN_DEACTIVATED), which the
 * API answered 200 to. Fails on the spec from before.
 *
 * Regression (wave 5e): the spec quoted the answers about "the only
 * superadmin", for whom inactive superadmins counted; only active
 * superadmins count now, and an inactive superadmin may be demoted,
 * deactivated and deleted. Fails on the spec from before.
 *
 * Regression (wave 5f): a caller who holds every permission of the admin
 * role but not the role got UserChangePolicy::CHANGE_REFUSED for a user
 * with the admin role, which told them they lack permissions they hold;
 * the API answers UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED now, and the
 * spec names it (the adminUser examples of UserUpdateForbidden and
 * UserDeleteForbidden). Fails on the spec from before.
 *
 * Regression (wave 5g): where the user's teams refused as well, the API
 * answered UserChangePolicy::CHANGE_REFUSED and the spec said so; both say
 * UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED now, whatever the teams, and
 * the spec says the team refusal comes where the roles allow the change.
 * Fails on the spec from before.
 *
 * Regression (PHP 8.5 move): POST and PUT answer 422
 * UsersController::INVALID_STATUS for a status that is no whole number
 * before the 403 of is_superadmin and the 422s of the only active
 * superadmin, but their order lists named no status; and UserInput.status
 * said that the API refuses every status but an integer or its digits,
 * while it takes "+10" and "010" for 10. Fails on the spec from before.
 */
class OpenApiUsersContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use OpenApiWorkflowAssertions;

    private const USERS = '/api/v1/users';

    private const USER = '/api/v1/users/{id}';

    /** How the description of an operation starts the order of its answers, its last sentence. */
    private const ORDER = 'The answers come in this order: ';

    /**
     * regression: POST and PUT documented the generic 403 only.
     */
    public function testRegressionTheGrantRefusalsAreDocumentedAsTheApiAnswersThem(): void
    {
        $roleRefused = sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin');
        $this->assertSame('#/components/responses/UserGrantForbidden', $this->specAt(['paths', self::USERS, 'post', 'responses', '403', '$ref']), 'post ' . self::USERS);
        $this->assertSame('#/components/responses/UserUpdateForbidden', $this->specAt(['paths', self::USER, 'put', 'responses', '403', '$ref']), 'put ' . self::USER);
        $adminRefused = sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, 'admin');
        $this->assertSame(
            ['Forbidden.', $roleRefused, $adminRefused, RoleGrantPolicy::SUPERADMIN_REFUSED],
            $this->exampleMessages(self::USERS, 'post', '403')
        );
        $this->assertSame(
            ['Forbidden.', UserChangePolicy::CHANGE_REFUSED, UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED, UserChangePolicy::TEAM_ACCESS_REFUSED, $roleRefused, $adminRefused, RoleGrantPolicy::SUPERADMIN_REFUSED],
            $this->exampleMessages(self::USER, 'put', '403')
        );

        $nobody = $this->createUser('spec-grant-nobody');
        $this->authenticate($nobody);
        $this->setBody(['email' => 'spec-grant-' . uniqid('', true) . '@example.com']);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $this->controller()->actionUpdate((int)$nobody->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);

        $helpDesk = $this->userWithRole($this->customRole(['user.view', 'user.update']));
        $target = $this->createUser('spec-grant-target');
        $this->authenticate($helpDesk);
        $this->setBody(['role' => 'admin']);
        $this->assertSame(['error' => ['message' => $roleRefused]], $this->controller()->actionUpdate((int)$target->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->setBody(['is_superadmin' => true]);
        $this->assertSame(['error' => ['message' => RoleGrantPolicy::SUPERADMIN_REFUSED]], $this->controller()->actionUpdate((int)$target->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);

        $creator = $this->userWithRole($this->customRole(['user.create']));
        $this->authenticate($creator);
        $username = 'spec-grant-created-' . uniqid();
        $this->setBody(['username' => $username, 'email' => $username . '@example.com', 'password' => 'securepassword', 'is_superadmin' => true]);
        $this->assertSame(['error' => ['message' => RoleGrantPolicy::SUPERADMIN_REFUSED]], $this->controller()->actionCreate());
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertFalse(User::find()->where(['username' => $username])->exists(), 'Nothing is created.');
    }

    /**
     * regression (wave 5c): PUT and DELETE documented no 403 for a user the
     * caller may not change. The 403 of PUT is UserUpdateForbidden and that
     * of DELETE UserDeleteForbidden, whose change example is
     * UserChangePolicy::CHANGE_REFUSED, the answer to a help desk that
     * changes a superadmin or deletes an admin; it comes after the 403 of the
     * permission and the 404 of an unknown user, and before the 422 of
     * auth_source and of one's own account; nothing is written.
     */
    public function testRegressionTheChangeRefusalIsDocumentedAsTheApiAnswersIt(): void
    {
        $this->assertSame('#/components/responses/UserDeleteForbidden', $this->specAt(['paths', self::USER, 'delete', 'responses', '403', '$ref']));
        $this->assertSame(['Forbidden.', UserChangePolicy::CHANGE_REFUSED, UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED, UserChangePolicy::TEAM_ACCESS_REFUSED], $this->exampleMessages(self::USER, 'delete', '403'));
        $refused = ['error' => ['message' => UserChangePolicy::CHANGE_REFUSED]];

        $superadmin = $this->createUser('spec-change-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $email = (string)$superadmin->email;
        $helpDesk = $this->userWithRole($this->customRole(['user.view', 'user.update']));
        $this->authenticate($helpDesk);
        $this->setBody(['email' => 'spec-change-' . uniqid('', true) . '@example.com', 'auth_source' => 'ldap']);
        $this->assertSame($refused, $this->controller()->actionUpdate((int)$superadmin->id), 'before the 422 of auth_source');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame($email, (string)User::findOne($superadmin->id)?->email, 'Nothing is written.');
        $this->setBody(['email' => 'spec-change-' . uniqid('', true) . '@example.com']);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $this->controller()->actionDelete((int)$superadmin->id), 'Without user.delete: the 403 of the permission.');
        $this->assertSame(403, \Yii::$app->response->statusCode);

        $admin = $this->userWithRole('admin');
        $deleter = $this->userWithRole($this->customRole(['user.view', 'user.update', 'user.delete']));
        $this->authenticate($deleter);
        $this->assertSame($refused, $this->controller()->actionDelete((int)$admin->id));
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertNotNull(User::findOne($admin->id), 'Nothing is deleted.');
        try {
            $this->controller()->actionDelete((int)(User::find()->max('id')) + 1000);
            $this->fail('An unknown user is a 404, before the refusal.');
        } catch (NotFoundHttpException) {
            // The 404 comes before the refusal.
        }
        $this->assertSame(['error' => ['message' => 'Cannot delete your own account.']], $this->controller()->actionDelete((int)$deleter->id), 'One\'s own account is no refusal of the rule.');
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    /**
     * regression (wave 5d): the spec named no refusal for the team rule nor
     * for the admin role, and no 422 for deactivating the only superadmin.
     * The examples are what the API answers: a help desk in no team who
     * changes or deletes a member of a team with a restricted project
     * (UserChangePolicy::TEAM_ACCESS_REFUSED); a caller who holds every
     * permission of the admin role but not the role and gives it
     * (RoleGrantPolicy::ADMIN_ROLE_REFUSED); and the only active superadmin
     * who deactivates their own account
     * (UsersController::ONLY_SUPERADMIN_DEACTIVATED). Nothing is written.
     */
    public function testRegressionTheTeamTheAdminRoleAndTheOnlySuperadminAnswersAreDocumented(): void
    {
        $this->assertContains(UsersController::ONLY_SUPERADMIN_DEACTIVATED, $this->exampleMessages(self::USER, 'put', '422'));
        $owner = $this->createUser('spec-team-owner');
        $team = $this->createTeam((int)$owner->id);
        $this->createTeamProject((int)$team->id, (int)$this->createProject((int)$owner->id)->id, TeamProject::ROLE_VIEWER);
        $member = $this->createUser('spec-team-member');
        $this->addTeamMember((int)$team->id, (int)$member->id);
        $email = (string)$member->email;
        $this->authenticate($this->userWithRole($this->customRole(['user.view', 'user.update', 'user.delete'])));
        $teamRefused = ['error' => ['message' => UserChangePolicy::TEAM_ACCESS_REFUSED]];
        $this->setBody(['email' => 'spec-team-' . uniqid('', true) . '@example.com']);
        $this->assertSame($teamRefused, $this->controller()->actionUpdate((int)$member->id), 'PUT of a team member');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame($teamRefused, $this->controller()->actionDelete((int)$member->id), 'DELETE of a team member');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame($email, (string)User::findOne($member->id)?->email, 'Nothing is written.');

        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $everyPermission = $this->customRole(array_map('strval', array_keys($auth->getPermissionsByRole('admin'))));
        $this->authenticate($this->userWithRole($everyPermission));
        $adminRefused = ['error' => ['message' => sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, 'admin')]];
        $target = $this->createUser('spec-admin-target');
        $this->setBody(['role' => 'admin']);
        $this->assertSame($adminRefused, $this->controller()->actionUpdate((int)$target->id), 'PUT with the admin role');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame([], $auth->getRolesByUser((string)$target->id), 'No role is assigned.');
        $username = 'spec-admin-created-' . uniqid();
        $this->setBody(['username' => $username, 'email' => $username . '@example.com', 'password' => 'securepassword', 'role' => 'admin']);
        $this->assertSame($adminRefused, $this->controller()->actionCreate(), 'POST with the admin role');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertFalse(User::find()->where(['username' => $username])->exists(), 'Nothing is created.');

        $only = $this->createUser('spec-only-superadmin');
        User::updateAll(['is_superadmin' => 0], ['is_superadmin' => 1]);
        $only->is_superadmin = true;
        $only->save(false);
        $this->authenticate($only);
        $this->setBody(['status' => User::STATUS_INACTIVE]);
        $this->assertSame(['error' => ['message' => UsersController::ONLY_SUPERADMIN_DEACTIVATED]], $this->controller()->actionUpdate((int)$only->id));
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(User::STATUS_ACTIVE, (int)User::findOne($only->id)?->status, 'Nothing is written.');
    }

    /**
     * regression (wave 5f): a caller who holds every permission of the
     * admin role through another role, but not the role, got
     * UserChangePolicy::CHANGE_REFUSED for a user with the admin role. PUT
     * and DELETE answer UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED, as the
     * operations and the adminUser examples say, and write nothing; also
     * where the user's teams refuse as well (regression of wave 5g:
     * CHANGE_REFUSED there).
     */
    public function testRegressionTheAdminRoleRefusalOfAChangeIsDocumentedAsTheApiAnswersIt(): void
    {
        $put = $this->specText(['paths', self::USER, 'put', 'description']);
        $this->assertStringContainsString(
            'A caller who holds every permission those roles grant, but neither is a superadmin nor holds the `admin` role, gets 403 `' . UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED
                . '` in its place for a user who is no superadmin and holds the `admin` role or a role that contains it, whatever the user\'s teams; nothing is written.',
            $put
        );
        $this->assertStringNotContainsString('unless the user\'s teams refuse the change as well', $put, 'The teams do not decide the refusal.');
        $delete = $this->specText(['paths', self::USER, 'delete', 'description']);
        $this->assertStringContainsString(
            '403 `' . UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED . '` (a user who is no superadmin, with the `admin` role or a role that contains it, from a caller who holds every permission of the user\'s roles but not the `admin` role, whatever the teams)',
            $delete
        );
        $this->assertStringNotContainsString('when the teams do not refuse too', $delete, 'The teams do not decide the refusal.');
        foreach (['UserUpdateForbidden', 'UserDeleteForbidden'] as $response) {
            $this->assertSame(
                UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED,
                $this->specAt(['components', 'responses', $response, 'content', 'application/json', 'examples', 'adminUser', 'value', 'error', 'message']),
                $response
            );
        }

        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $this->authenticate($this->userWithRole($this->customRole(array_map('strval', array_keys($auth->getPermissionsByRole('admin'))))));
        $admin = $this->userWithRole('admin');
        $email = (string)$admin->email;
        $refused = ['error' => ['message' => UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED]];
        $this->setBody(['email' => 'spec-admin-change-' . uniqid('', true) . '@example.com']);
        $this->assertSame($refused, $this->controller()->actionUpdate((int)$admin->id), 'PUT of a user with the admin role');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame($refused, $this->controller()->actionDelete((int)$admin->id), 'DELETE of a user with the admin role');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $stored = User::findOne($admin->id);
        $this->assertNotNull($stored, 'Nothing is deleted.');
        $this->assertSame($email, (string)$stored->email, 'Nothing is written.');
        $this->assertArrayHasKey('admin', $auth->getAssignments((string)$admin->id), 'The role stays.');

        $team = $this->createTeam((int)$admin->id);
        $this->createTeamProject((int)$team->id, (int)$this->createProject((int)$admin->id)->id, TeamProject::ROLE_VIEWER);
        $this->addTeamMember((int)$team->id, (int)$admin->id);
        $this->assertSame($refused, $this->controller()->actionUpdate((int)$admin->id), 'PUT, where the teams refuse as well.');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame($refused, $this->controller()->actionDelete((int)$admin->id), 'DELETE, where the teams refuse as well.');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertNotNull(User::findOne($admin->id), 'Nothing is deleted.');
    }

    /**
     * regression (wave 5e): the spec said "the only superadmin", and the
     * API counted inactive superadmins as others, so the last active
     * superadmin could deactivate or demote themselves while the only other
     * superadmin was inactive. The operations say only active superadmins
     * count, and the API answers so: with an inactive superadmin beside
     * them, the only active superadmin gets the 422 examples of PUT, and
     * demotes, deactivates and deletes the inactive one.
     */
    public function testRegressionOnlyActiveSuperadminsCountAsTheSpecSays(): void
    {
        $put = $this->specText(['paths', self::USER, 'put', 'description']);
        $this->assertStringContainsString('An inactive superadmin does not count as another superadmin, and may be demoted or deactivated.', $put);
        $this->assertStringContainsString(
            'Cannot delete your own account or the only active superadmin (422); an inactive superadmin may be deleted.',
            $this->specText(['paths', self::USER, 'delete', 'description'])
        );
        $this->assertStringContainsString(
            'taking the superadmin flag from the only active superadmin or deactivating them',
            $this->specText(['paths', self::USER, 'put', 'responses', '422', 'description'])
        );
        $this->assertSame(['role must be one of: admin, operator, viewer.', UsersController::ONLY_SUPERADMIN_DEACTIVATED], $this->exampleMessages(self::USER, 'put', '422'));

        $only = $this->createUser('spec-active-superadmin');
        $inactive = $this->createUser('spec-inactive-superadmin');
        User::updateAll(['is_superadmin' => 0], ['is_superadmin' => 1]);
        $only->is_superadmin = true;
        $only->save(false);
        $inactive->is_superadmin = true;
        $inactive->status = User::STATUS_INACTIVE;
        $inactive->save(false);
        $this->authenticate($only);
        foreach ([[['status' => User::STATUS_INACTIVE], UsersController::ONLY_SUPERADMIN_DEACTIVATED], [['is_superadmin' => false], UsersController::ONLY_SUPERADMIN_DEMOTED]] as [$body, $message]) {
            $this->setBody($body);
            $this->assertSame(['error' => ['message' => $message]], $this->controller()->actionUpdate((int)$only->id), (string)json_encode($body));
            $this->assertSame(422, \Yii::$app->response->statusCode);
        }
        $stored = User::findOne($only->id);
        $this->assertNotNull($stored);
        $this->assertTrue((bool)$stored->is_superadmin, 'Nothing is written.');
        $this->assertSame(User::STATUS_ACTIVE, (int)$stored->status, 'Nothing is written.');

        $this->setBody(['is_superadmin' => false, 'status' => User::STATUS_INACTIVE]);
        $this->controller()->actionUpdate((int)$inactive->id);
        $this->assertSame(200, \Yii::$app->response->statusCode, 'An inactive superadmin may be demoted and deactivated.');
        $this->assertFalse((bool)User::findOne($inactive->id)?->is_superadmin);
        $other = $this->createUser('spec-inactive-superadmin');
        $other->is_superadmin = true;
        $other->status = User::STATUS_INACTIVE;
        $other->save(false);
        $this->assertSame(['data' => ['deleted' => true]], $this->controller()->actionDelete((int)$other->id), 'An inactive superadmin may be deleted.');
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertNull(User::findOne($other->id));
    }

    /**
     * The operations say who may give a role and the flag, who may change
     * and delete a user, and in which order the refusals come
     * (UsersControllerTest and UsersControllerChangeTest pin the order).
     */
    public function testTheOperationsSayWhoMayGiveARoleAndTheFlag(): void
    {
        $post = $this->specText(['paths', self::USERS, 'post', 'description']);
        $this->assertStringContainsString('A `role` needs every permission the role grants, through its child roles too, unless the caller is a superadmin; `is_superadmin: true` needs a superadmin. Either refusal answers 403 and creates nothing.', $post);
        $adminRule = 'The `admin` role, which gives every team-restricted project on top of its permissions, and a role that contains it are given only by a superadmin or a caller who holds the `admin` role: a caller who holds every permission of it through another role gets 403 `'
            . sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, 'admin') . '`';
        $this->assertStringContainsString($adminRule, $post);
        $this->assertStringContainsString(
            'The answers come in this order: 403 `Forbidden.` without `user.create`; 422 for an unknown `auth_source` or `role`; 403 for a role the caller may not assign; 422 `'
                . UsersController::INVALID_STATUS . '` for a `status` that is no whole number; 403 for `is_superadmin: true` from a caller who is no superadmin; 422 for a password or another field that is not valid, such as a `status` other than 0 and 10.',
            $post
        );
        $put = $this->specText(['paths', self::USER, 'put', 'description']);
        $this->assertStringContainsString('A `role` the user already has, one of the roles assigned to them, keeps every role assigned to the user, so a `role` read with `GET` and sent back keeps them all', $put);
        $this->assertStringContainsString('Only a superadmin changes `is_superadmin`: a value that would set or clear the flag answers 403 for anyone else, and the current value is accepted.', $put);
        $this->assertStringContainsString($adminRule, $put);
        $this->assertStringContainsString(
            'The answers come in this order: 403 `Forbidden.` without `user.update`; 404 for an unknown user; 403 for a user the caller may not change, for their roles or their teams; 422 for a change of `auth_source` or an unknown `role`; 403 for a role the caller may not assign; 422 `'
                . UsersController::INVALID_STATUS . '` for a `status` that is no whole number; 403 for a change of `is_superadmin` from a caller who is no superadmin; 422 `'
                . UsersController::ONLY_SUPERADMIN_DEMOTED . '` or `' . UsersController::ONLY_SUPERADMIN_DEACTIVATED . '`; 422 for a password or another field that is not valid, such as a `status` other than 0 and 10.',
            $put
        );
        $this->assertStringContainsString(
            'The only active superadmin changes their own account, but taking their superadmin flag (`is_superadmin: false`) answers 422 `' . UsersController::ONLY_SUPERADMIN_DEMOTED
                . '`, and deactivating them (a `status` other than 10) answers 422 `' . UsersController::ONLY_SUPERADMIN_DEACTIVATED . '`; both change nothing.',
            $put
        );
        $teamRule = 'A caller who neither is a superadmin nor holds the `admin` role changes a user only when, for every team-restricted project the user\'s teams give the user, the caller\'s own teams give the caller that project with at least the same role (operator over viewer; the highest role of their teams counts); anyone else gets 403 `'
            . UserChangePolicy::TEAM_ACCESS_REFUSED . '` where the roles allow the change, and nothing is written.';
        $this->assertStringContainsString($teamRule, $put);
        $changeRule = 'A caller who is no superadmin changes only a user who is no superadmin and all of whose assigned roles they may assign, that is, whose roles grant no permission the caller does not hold; a user without an assigned role may be changed by everyone with `user.update`. Anyone else gets 403 `'
            . UserChangePolicy::CHANGE_REFUSED . '` and nothing is written.';
        $this->assertStringContainsString('Requires `user.update`. ' . $changeRule, $put);
        $delete = $this->specText(['paths', self::USER, 'delete', 'description']);
        $this->assertStringContainsString(
            'Requires `user.delete`. A caller who is no superadmin deletes only a user who is no superadmin and all of whose assigned roles they may assign, and, unless the caller holds the `admin` role, whose teams give them no project beyond what the caller\'s teams give the caller, with at least the same role; anyone else gets 403 `'
                . UserChangePolicy::CHANGE_REFUSED . '`, 403 `' . UserChangePolicy::ADMIN_ROLE_CHANGE_REFUSED
                . '` (a user who is no superadmin, with the `admin` role or a role that contains it, from a caller who holds every permission of the user\'s roles but not the `admin` role, whatever the teams) or 403 `'
                . UserChangePolicy::TEAM_ACCESS_REFUSED . '` and nothing is deleted.',
            $delete
        );
        $this->assertStringContainsString(
            'The answers come in this order: 403 `Forbidden.` without `user.delete`; 404 for an unknown user; 403 for a user the caller may not change, for their roles or their teams; 422 `Cannot delete your own account.`; 422 `'
                . UsersController::ONLY_SUPERADMIN_DELETED . '`; 409 for a user other records still refer to.',
            $delete
        );
        $this->assertSame(
            'The caller lacks `user.create`, may not assign the `role` (one that grants permissions the caller does not hold, or the `admin` role or a role that contains it, from a caller who does not hold the `admin` role), or is no superadmin and would set `is_superadmin`. Nothing was written.',
            $this->specText(['components', 'responses', 'UserGrantForbidden', 'description'])
        );
        $this->assertSame(
            'The caller lacks `user.update`, may not change the user (a superadmin, a user with a role that grants permissions the caller does not hold, a user with the `admin` role or a role that contains it, from a caller who does not hold the `admin` role, or a user whose teams give them more access to a project than the caller\'s teams give the caller), may not assign the `role` (one that grants permissions the caller does not hold, or the `admin` role or a role that contains it, from a caller who does not hold the `admin` role), or is no superadmin and would set or clear `is_superadmin`. Nothing was written.',
            $this->specText(['components', 'responses', 'UserUpdateForbidden', 'description'])
        );
        $this->assertSame(
            'The caller lacks `user.delete`, or may not change the user: a superadmin, a user with a role that grants permissions the caller does not hold, a user with the `admin` role or a role that contains it, from a caller who does not hold the `admin` role, or a user whose teams give them more access to a project than the caller\'s teams give the caller. Nothing was deleted.',
            $this->specText(['components', 'responses', 'UserDeleteForbidden', 'description'])
        );
    }

    /**
     * The role and the flag of UserInput and the role of User say what a
     * role keeps and replaces, and quote the refusals; both roles stay
     * nullable (UsersControllerTest pins the 422 of an unknown role).
     */
    public function testTheRoleAndTheFlagAreDescribedAsTheApiTreatsThem(): void
    {
        $this->assertSame(
            'The role assigned to the user, the first by name when several are; null when none is. A default role, such as viewer, which every signed-in user holds, is answered only when it is also assigned to the user. Sent back with `PUT`, it keeps every role assigned to the user.',
            $this->specText(['components', 'schemas', 'User', 'properties', 'role', 'description'])
        );
        $this->assertTrue($this->specAt(['components', 'schemas', 'User', 'properties', 'role', 'nullable']));
        $this->assertSame(
            'The role to assign: one of the roles the instance has. A role the user already has, one of the roles assigned to them, keeps every role assigned to the user; another role replaces them all; leave it out, or send null or an empty string, to keep them. Another value answers 422. Assigning a role needs every permission it grants, through its child roles too, unless the caller is a superadmin; else 403 `'
                . sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin') . '` The `admin` role, and a role that contains it, also needs a caller who holds the `admin` role, unless the caller is a superadmin; else 403 `'
                . sprintf(RoleGrantPolicy::ADMIN_ROLE_REFUSED, 'admin') . '`',
            $this->specText(['components', 'schemas', 'UserInput', 'properties', 'role', 'description'])
        );
        $this->assertTrue($this->specAt(['components', 'schemas', 'UserInput', 'properties', 'role', 'nullable']));
        $this->assertStringContainsString(
            'a value that would set or clear the flag answers 403 `' . RoleGrantPolicy::SUPERADMIN_REFUSED . '` for anyone else; the current value is accepted.',
            $this->specText(['components', 'schemas', 'UserInput', 'properties', 'is_superadmin', 'description'])
        );
        $this->assertSame('boolean', $this->specAt(['components', 'schemas', 'UserInput', 'properties', 'is_superadmin', 'type']));
    }

    /**
     * regression (PHP 8.5 move): POST and PUT answer 422
     * UsersController::INVALID_STATUS for a status that is no whole number
     * (UsersController::applyBody()) after the 403 of a role the caller may
     * not assign and before the 403 of is_superadmin and, for PUT, the 422s
     * of the only active superadmin, but their order lists named no status
     * and put those answers before every 422 of a field. Both lists name it
     * between the two 403s, the 422s of POST and PUT name the status (the
     * 422 of POST named only the password and the role), and the API answers
     * so: the role refusal comes first, the status refusal before that of
     * the flag; nothing is written. UsersControllerNumbersTest pins the other
     * answers before and after it.
     */
    public function testRegressionTheOrderListsNameTheStatusRefusalWhereTheApiAnswersIt(): void
    {
        $status = '422 `' . UsersController::INVALID_STATUS . '` for a `status` that is no whole number';
        $role = '403 for a role the caller may not assign';
        $this->assertSame(
            [$role, $status, '403 for `is_superadmin: true` from a caller who is no superadmin'],
            $this->neighboursIn(self::USERS, 'post', $status)
        );
        $this->assertSame(
            [$role, $status, '403 for a change of `is_superadmin` from a caller who is no superadmin'],
            $this->neighboursIn(self::USER, 'put', $status)
        );
        foreach ([self::USERS => 'post', self::USER => 'put'] as $path => $method) {
            $this->assertStringContainsString(
                'a `status` that is no whole number or neither 0 nor 10 (`' . UsersController::INVALID_STATUS . '`)',
                $this->specText(['paths', $path, $method, 'responses', '422', 'description']),
                "The 422 of {$method} {$path} names the status."
            );
        }

        $target = $this->createUser('spec-order-target');
        $email = (string)$target->email;
        $this->authenticate($this->userWithRole($this->customRole(['user.view', 'user.create', 'user.update'])));
        $username = 'spec-order-created-' . uniqid();
        $create = ['username' => $username, 'email' => $username . '@example.com', 'password' => 'securepassword', 'status' => 'abc'];
        $update = ['email' => 'spec-order-' . uniqid('', true) . '@example.com', 'status' => 'abc'];
        $answers = [
            'the role first' => [['role' => 'admin'], 403, sprintf(RoleGrantPolicy::ROLE_REFUSED, 'admin')],
            'the status before the flag' => [['is_superadmin' => true], 422, UsersController::INVALID_STATUS],
        ];
        foreach ($answers as $what => [$check, $code, $message]) {
            $this->setBody($check + $create);
            $this->assertSame(['error' => ['message' => $message]], $this->controller()->actionCreate(), "POST: {$what}");
            $this->assertSame($code, \Yii::$app->response->statusCode, "POST: {$what}");
            $this->setBody($check + $update);
            $this->assertSame(['error' => ['message' => $message]], $this->controller()->actionUpdate((int)$target->id), "PUT: {$what}");
            $this->assertSame($code, \Yii::$app->response->statusCode, "PUT: {$what}");
        }
        $this->assertFalse(User::find()->where(['username' => $username])->exists(), 'Nothing is created.');
        $this->assertSame($email, (string)User::findOne($target->id)?->email, 'Nothing is written.');
    }

    /**
     * regression (PHP 8.5 move): UserInput.status said the API takes a
     * status "as an integer or its digits as text" and refuses anything
     * else, but it takes 10.0, "+10" and "010" for 10. It names them now,
     * and what it refuses with 422 UsersController::INVALID_STATUS, a
     * fraction however close to 0 or 10 included, which the integer rule
     * took for the whole number of its text of 14 digits until
     * WholeNumberValidator, and a list or an object, also an empty one,
     * which 2.13.1 took for 0; UsersControllerNumbersTest and
     * ListsForWholeNumbersTest pin each value it names.
     */
    public function testRegressionTheStatusIsDescribedAsTheApiReadsIt(): void
    {
        $status = ['components', 'schemas', 'UserInput', 'properties', 'status'];
        $this->assertSame([User::STATUS_INACTIVE, User::STATUS_ACTIVE], $this->specAt([...$status, 'enum']));
        $this->assertSame('integer', $this->specAt([...$status, 'type']));
        $this->assertSame(
            '10 = active, 0 = inactive: an integer (10.0 included) or its digits as text, with an optional sign or leading zeros (`"+10"`, `"010"`).'
                . ' Anything else, `true`, `false`, `null`, `""`, `5`, `10.5`, any other fraction however close to 0 or 10 (`0.999999999999999`,'
                . ' `9.99999999999999`), a list or an object, also an empty one (`[]`, `{}`), and text such as `"abc"` included, answers 422 `'
                . UsersController::INVALID_STATUS . '` before anything is written; releases up to 2.13.1 took `false`, `null`, `""`, `[]`, `{}`,'
                . ' such text and a fraction below 1 for 0, which deactivated the user.',
            $this->specText([...$status, 'description'])
        );
    }

    /**
     * The answer before $answer, $answer and the answer after it, in the
     * order the description of $method $path gives its answers in (ORDER).
     *
     * @return list<string>
     */
    private function neighboursIn(string $path, string $method, string $answer): array
    {
        $description = $this->specText(['paths', $path, $method, 'description']);
        $start = strpos($description, self::ORDER);
        $this->assertNotFalse($start, "{$method} {$path} gives no order of its answers.");
        $order = explode('; ', (string)preg_replace('/\.$/', '', substr($description, $start + strlen(self::ORDER))));
        $at = array_search($answer, $order, true);
        $this->assertIsInt($at, "{$method} {$path} names no {$answer} in the order of its answers: " . implode(' | ', $order));

        return array_slice($order, max(0, $at - 1), 3);
    }

    private function controller(): UsersController
    {
        \Yii::$app->response->statusCode = 200;

        return new UsersController('api/v1/users', $this->app());
    }

    /**
     * Signs $user in with an API token of their own.
     */
    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'spec');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
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

    /**
     * A user whose only role is $role.
     */
    private function userWithRole(string $role): User
    {
        $user = $this->createUser('spec-grant');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $item = $auth->getRole($role);
        $this->assertNotNull($item, $role);
        $auth->assign($item, (string)$user->id);

        return $user;
    }

    /**
     * A custom role holding $permissions only, as an admin builds one in the
     * role editor (a help desk role: user.view and user.update).
     *
     * @param list<string> $permissions
     */
    private function customRole(array $permissions): string
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('spec-grant-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }

        return $role->name;
    }
}
