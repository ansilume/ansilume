<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\RunnerGroupsController;
use app\controllers\api\v1\RunnersController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Only admins manage runners through the REST API (security rule 16). Since
 * m000087_000000_restrict_runner_management the operator role holds neither
 * runner-group.create nor runner-group.update: an operator token is refused
 * (403) creating or changing a runner group and moving, deleting or
 * regenerating the token of a runner, and nothing is written; listing and
 * reading runner groups and runners stays open to it. A runner receives the
 * decrypted credentials, inventory and extra vars of every job of its group,
 * whatever the team, and regenerate-token answers with the raw token.
 *
 * Through runAction() with a Bearer token, so the permission check runs as for
 * a real request, with the roles of the migrated test database: on the RBAC
 * before m000087 (`migrate/down 1`) the operator tests fail.
 */
class RunnerManagementRbacTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private const CREATED_GROUP = 'api-rbac-created-group';

    private const RENAMED_GROUP = 'api-rbac-renamed-group';

    /**
     * Every request that creates or changes a runner group, or moves,
     * deletes or regenerates the token of a runner.
     *
     * @return array<string, array{0: string}>
     */
    public static function managementRequestProvider(): array
    {
        $labels = [
            'POST /api/v1/runner-groups',
            'PUT /api/v1/runner-groups/{id}',
            'POST /api/v1/runners/{id}/move',
            'DELETE /api/v1/runners/{id}',
            'POST /api/v1/runners/{id}/regenerate-token',
        ];

        return array_combine($labels, array_map(static fn (string $label): array => [$label], $labels));
    }

    // -- Refused --------------------------------------------------------------

    /**
     * Regression: an operator token regenerated any runner's token and got
     * the raw token back, so took the runner over, moved runners between
     * groups, deleted them, and created and changed runner groups.
     *
     * @dataProvider managementRequestProvider
     */
    public function testRegressionAnOperatorTokenIsRefusedAndNothingIsWritten(string $label): void
    {
        $fleet = $this->fleet();
        $this->authenticateWithRoles(['operator']);

        $this->assertRefusedWithoutAWrite($fleet, $label);
    }

    /**
     * @dataProvider managementRequestProvider
     */
    public function testAViewerTokenIsRefusedAsBefore(string $label): void
    {
        $fleet = $this->fleet();
        $this->authenticateWithRoles(['viewer']);

        $this->assertRefusedWithoutAWrite($fleet, $label);
    }

    public function testOperatorsAndViewersStillListAndReadRunnerGroupsAndRunners(): void
    {
        $fleet = $this->fleet();
        $groupId = (int)$fleet['group']->id;
        $runnerId = (int)$fleet['runner']->id;
        foreach (['operator', 'viewer'] as $role) {
            $this->authenticateWithRoles([$role]);

            $this->assertContains($groupId, array_column($this->listOf($this->call('runner-groups', 'index')), 'id'), $role);
            $this->assertSame($groupId, $this->dataOf($this->call('runner-groups', 'view', ['id' => $groupId]))['id'], $role);
            $this->setQueryParams(['group_id' => (string)$groupId]);
            $this->assertSame([$runnerId], array_column($this->listOf($this->call('runners', 'index')), 'id'), $role);
            $this->setQueryParams([]);
            $runner = $this->dataOf($this->call('runners', 'view', ['id' => $runnerId]));
            $this->assertSame($runnerId, $runner['id'], $role);
            $this->assertArrayNotHasKey('token', $runner, $role);
            $this->assertArrayNotHasKey('token_hash', $runner, $role);
        }
    }

    // -- Admins ---------------------------------------------------------------

    public function testAnAdminCreatesAndChangesRunnerGroups(): void
    {
        $fleet = $this->fleet();
        $this->authenticateWithRoles(['admin']);

        $this->setBody(['name' => self::CREATED_GROUP, 'description' => 'created by an admin']);
        $created = $this->dataOf($this->call('runner-groups', 'create'));
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame(self::CREATED_GROUP, $created['name']);
        $this->assertSame(1, $this->auditCount(AuditLog::ACTION_RUNNER_GROUP_CREATED, (int)$created['id'], 'runner_group'));

        $groupId = (int)$fleet['group']->id;
        $this->setBody(['name' => self::RENAMED_GROUP]);
        $updated = $this->dataOf($this->call('runner-groups', 'update', ['id' => $groupId]));
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(self::RENAMED_GROUP, $updated['name']);
        $this->assertSame(self::RENAMED_GROUP, RunnerGroup::findOne($groupId)?->name);
        $this->assertSame(1, $this->auditCount(AuditLog::ACTION_RUNNER_GROUP_UPDATED, $groupId, 'runner_group'));
    }

    public function testAnAdminRegeneratesATokenMovesAndDeletesARunner(): void
    {
        $fleet = $this->fleet();
        $runnerId = (int)$fleet['runner']->id;
        $this->authenticateWithRoles(['admin']);

        $this->setBody([]);
        $regenerated = $this->dataOf($this->call('runners', 'regenerate-token', ['id' => $runnerId]));
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertIsString($regenerated['token']);
        $this->assertSame($runnerId, Runner::findByToken($regenerated['token'])?->id, 'the raw token, shown once');
        $this->assertNull(Runner::findByToken($fleet['token']), 'the old token stops working');
        $this->assertSame(1, $this->auditCount(AuditLog::ACTION_RUNNER_TOKEN_REGENERATED, $runnerId));

        $this->setBody(['target_group_id' => (int)$fleet['target']->id]);
        $moved = $this->dataOf($this->call('runners', 'move', ['id' => $runnerId]));
        $this->assertSame((int)$fleet['target']->id, $moved['runner_group_id']);
        $this->assertSame(1, $this->auditCount(AuditLog::ACTION_RUNNER_UPDATED, $runnerId));

        $this->setBody([]);
        $this->assertSame(['deleted' => true], $this->dataOf($this->call('runners', 'delete', ['id' => $runnerId])));
        $this->assertNull(Runner::findOne($runnerId));
        $this->assertSame(1, $this->auditCount(AuditLog::ACTION_RUNNER_DELETED, $runnerId));
    }

    /**
     * The documented limit (docs/runners.md): a custom role with
     * runner-group.update still regenerates runner tokens and gets the raw
     * token, and moves runners.
     */
    public function testACustomRoleWithRunnerGroupUpdateStillManagesRunners(): void
    {
        $fleet = $this->fleet();
        $runnerId = (int)$fleet['runner']->id;
        $this->authenticateAs($this->userWithCustomRole(['runner-group.view', 'runner-group.update']));

        $this->setBody([]);
        $regenerated = $this->dataOf($this->call('runners', 'regenerate-token', ['id' => $runnerId]));
        $this->assertIsString($regenerated['token']);
        $this->assertSame($runnerId, Runner::findByToken($regenerated['token'])?->id);

        $this->setBody(['target_group_id' => (int)$fleet['target']->id]);
        $this->assertSame((int)$fleet['target']->id, $this->dataOf($this->call('runners', 'move', ['id' => $runnerId]))['runner_group_id']);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Two runner groups of an admin, the first with a runner whose raw token
     * is known.
     *
     * @return array{group: RunnerGroup, target: RunnerGroup, runner: Runner, token: string}
     */
    private function fleet(): array
    {
        $owner = $this->createUserWithRole('api-runner-rbac-owner', 'admin');
        $group = $this->createRunnerGroup((int)$owner->id);
        $target = $this->createRunnerGroup((int)$owner->id);
        $runner = $this->createRunner((int)$group->id, (int)$owner->id);
        $token = Runner::generateToken();
        $runner->token_hash = $token['hash'];
        $runner->save(false);

        return ['group' => $group, 'target' => $target, 'runner' => $runner, 'token' => $token['raw']];
    }

    /**
     * $label of managementRequestProvider() is refused with 403 before the
     * action runs, answers no token, and leaves the runners, the groups and
     * the audit log as they were.
     *
     * @param array{group: RunnerGroup, target: RunnerGroup, runner: Runner, token: string} $fleet
     */
    private function assertRefusedWithoutAWrite(array $fleet, string $label): void
    {
        $before = $this->snapshot();

        $result = $this->send($fleet, $label);

        $this->assertNull($result, "{$label}: the action must not run");
        $this->assertSame(403, \Yii::$app->response->statusCode, $label);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], \Yii::$app->response->data, "{$label}: no token in the answer");
        $this->assertSame($before, $this->snapshot(), "{$label}: no runner, group or audit entry written");
        $this->assertSame((int)$fleet['runner']->id, Runner::findByToken($fleet['token'])?->id, "{$label}: the runner keeps its token");
        $this->assertNull(RunnerGroup::findOne(['name' => [self::CREATED_GROUP, self::RENAMED_GROUP]]), $label);
    }

    /**
     * Sends $label of managementRequestProvider() through runAction().
     *
     * @param array{group: RunnerGroup, target: RunnerGroup, runner: Runner, token: string} $fleet
     */
    private function send(array $fleet, string $label): mixed
    {
        $groupId = (int)$fleet['group']->id;
        $runnerId = (int)$fleet['runner']->id;
        $requests = [
            'POST /api/v1/runner-groups' => ['runner-groups', 'create', [], ['name' => self::CREATED_GROUP]],
            'PUT /api/v1/runner-groups/{id}' => ['runner-groups', 'update', ['id' => $groupId], ['name' => self::RENAMED_GROUP]],
            'POST /api/v1/runners/{id}/move' => ['runners', 'move', ['id' => $runnerId], ['target_group_id' => (int)$fleet['target']->id]],
            'DELETE /api/v1/runners/{id}' => ['runners', 'delete', ['id' => $runnerId], []],
            'POST /api/v1/runners/{id}/regenerate-token' => ['runners', 'regenerate-token', ['id' => $runnerId], []],
        ];
        $this->assertArrayHasKey($label, $requests);
        [$controllerId, $action, $params, $body] = $requests[$label];
        $this->setBody($body);
        \Yii::$app->response->statusCode = 200;
        \Yii::$app->response->data = null;

        return $this->call($controllerId, $action, $params);
    }

    /**
     * What a refused request must leave as it is.
     *
     * @return array{runners: array<mixed>, groups: array<mixed>, audit: int}
     */
    private function snapshot(): array
    {
        return [
            'runners' => Runner::find()->select(['id', 'runner_group_id', 'name', 'token_hash', 'updated_at'])->orderBy(['id' => SORT_ASC])->asArray()->all(),
            'groups' => RunnerGroup::find()->select(['id', 'name', 'description', 'updated_at'])->orderBy(['id' => SORT_ASC])->asArray()->all(),
            'audit' => (int)AuditLog::find()->max('id'),
        ];
    }

    private function auditCount(string $action, int $objectId, string $objectType = 'runner'): int
    {
        return (int)AuditLog::find()
            ->where(['action' => $action, 'object_type' => $objectType, 'object_id' => $objectId])
            ->count();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function call(string $controllerId, string $action, array $params = []): mixed
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');
        $ctrl = $controllerId === 'runners'
            ? new RunnersController('api/v1/runners', $app)
            : new RunnerGroupsController('api/v1/runner-groups', $app);

        return $ctrl->runAction($action, $params);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataOf(mixed $result): array
    {
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);

        /** @var array<string, mixed> */
        return $result['data'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $result): array
    {
        $this->assertSame(200, \Yii::$app->response->statusCode);

        /** @var list<array<string, mixed>> */
        return $this->dataOf($result);
    }

    /**
     * @param list<string> $roles
     */
    private function authenticateWithRoles(array $roles): User
    {
        $user = $this->createUser('api-runner-rbac');
        foreach ($roles as $roleName) {
            $this->assignRole((int)$user->id, $roleName);
        }
        $this->authenticateAs($user);

        return $user;
    }

    private function authenticateAs(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'api-runner-rbac-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        // A fresh user component: the identity of an earlier call must not stay.
        \Yii::$app->set('user', [
            'class' => \yii\web\User::class,
            'identityClass' => User::class,
            'enableSession' => false,
            'enableAutoLogin' => false,
        ]);
    }

    /**
     * A user whose only role is a custom one with $permissions.
     *
     * @param list<string> $permissions
     */
    private function userWithCustomRole(array $permissions): User
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('api-runner-managers-' . uniqid());
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $user = $this->createUser('api-runner-rbac-custom');
        $auth->assign($role, (string)$user->id);

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
}
