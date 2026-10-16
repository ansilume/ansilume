<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\RunnerController;
use app\controllers\RunnerGroupController;
use app\models\AuditLog;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Only admins manage runners in the web UI (security rule 16). Since
 * m000087_000000_restrict_runner_management the operator role holds neither
 * runner-group.create nor runner-group.update: an operator is refused (403)
 * every request that registers a runner, regenerates its token, moves or
 * deletes it, or creates or changes a runner group, and nothing is written.
 * A runner claims every queued job of its group, whatever the team, and
 * receives the job's decrypted credentials, inventory and extra vars, so
 * whoever registers a runner or holds its token can take those jobs over.
 *
 * Through runAction(), so the access filter runs as for a real request, with
 * the roles of the migrated test database: on the RBAC before m000087
 * (`migrate/down 1`) the operator tests fail.
 */
class RunnerManagementRbacTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;

    /** yii\filters\AccessControl's 403 for a signed-in user. */
    private const DENIED = 'You are not allowed to perform this action.';

    private const REGISTERED_RUNNER = 'rbac-registered-runner';

    private const CREATED_GROUP = 'rbac-created-group';

    private const RENAMED_GROUP = 'rbac-renamed-group';

    /**
     * Every request that registers, regenerates, moves or deletes a runner,
     * or creates or changes a runner group.
     *
     * @return array<string, array{0: string}>
     */
    public static function managementRequestProvider(): array
    {
        $labels = [
            'POST /runner/create',
            'POST /runner/regenerate-token',
            'POST /runner/move',
            'POST /runner/delete',
            'GET /runner-group/create',
            'POST /runner-group/create',
            'GET /runner-group/update',
            'POST /runner-group/update',
        ];

        return array_combine($labels, array_map(static fn (string $label): array => [$label], $labels));
    }

    // -- Refused --------------------------------------------------------------

    /**
     * Regression: the operator role held runner-group.create and
     * runner-group.update (m000025). An operator registered a runner in any
     * runner group and got its raw token, regenerated any runner's token and
     * so took the runner over, moved runners between groups, deleted them,
     * and created and changed runner groups.
     *
     * @dataProvider managementRequestProvider
     */
    public function testRegressionAnOperatorIsRefusedAndNothingIsWritten(string $label): void
    {
        $fleet = $this->fleet();
        $this->signedIn('operator');

        $this->assertRefusedWithoutAWrite($fleet, $label);
    }

    /**
     * @dataProvider managementRequestProvider
     */
    public function testAViewerIsRefusedAsBefore(string $label): void
    {
        $fleet = $this->fleet();
        $this->signedIn('viewer');

        $this->assertRefusedWithoutAWrite($fleet, $label);
    }

    public function testOperatorsAndViewersStillSeeRunnerGroupsAndTheirRunners(): void
    {
        $fleet = $this->fleet();
        foreach (['operator', 'viewer'] as $role) {
            $this->signedIn($role);

            $this->assertSame('rendered:index', $this->groupController()->runAction('index'), $role);
            $view = $this->groupController();
            $this->assertSame('rendered:view', $view->runAction('view', ['id' => (int)$fleet['group']->id]), $role);
            $this->assertContainsEquals((int)$fleet['runner']->id, array_map(
                static fn (Runner $runner): int => (int)$runner->id,
                $this->runnersOf($view->capturedParams)
            ), $role);
        }
    }

    // -- Admins ---------------------------------------------------------------

    public function testAnAdminRegistersARunnerAndSeesItsTokenOnce(): void
    {
        $fleet = $this->fleet();
        $this->signedIn('admin');
        $this->setPost(['group_id' => (string)$fleet['group']->id, 'name' => self::REGISTERED_RUNNER, 'description' => '']);

        $ctrl = $this->runnerController();
        $ctrl->runAction('create');

        $runner = Runner::findOne(['name' => self::REGISTERED_RUNNER, 'runner_group_id' => (int)$fleet['group']->id]);
        $this->assertNotNull($runner);
        $this->assertSame(['/runner-group/view', 'id' => (int)$fleet['group']->id], $ctrl->redirectedTo);
        $this->assertSame((int)$runner->id, Runner::findByToken($this->flashedToken(self::REGISTERED_RUNNER))?->id, 'the token card shows its token');
        $this->assertSame(1, $this->auditCount(AuditLog::ACTION_RUNNER_CREATED, (int)$runner->id));
    }

    public function testAnAdminRegeneratesATokenMovesAndDeletesARunner(): void
    {
        $fleet = $this->fleet();
        $runnerId = (int)$fleet['runner']->id;
        $this->signedIn('admin');

        $this->setPost([]);
        $this->runnerController()->runAction('regenerate-token', ['id' => $runnerId]);
        $this->assertNull(Runner::findByToken($fleet['token']), 'the old token stops working');
        $this->assertSame($runnerId, Runner::findByToken($this->flashedToken($fleet['runner']->name))?->id);
        $this->assertSame(1, $this->auditCount(AuditLog::ACTION_RUNNER_TOKEN_REGENERATED, $runnerId));

        $this->setPost(['target_group_id' => (string)$fleet['target']->id]);
        $move = $this->runnerController();
        $move->runAction('move', ['id' => $runnerId]);
        $this->assertSame(['/runner-group/view', 'id' => (int)$fleet['target']->id], $move->redirectedTo);
        $this->assertSame((int)$fleet['target']->id, (int)Runner::findOne($runnerId)?->runner_group_id);
        $this->assertSame(1, $this->auditCount(AuditLog::ACTION_RUNNER_UPDATED, $runnerId));

        $this->setPost([]);
        $this->runnerController()->runAction('delete', ['id' => $runnerId]);
        $this->assertNull(Runner::findOne($runnerId));
        $this->assertSame(1, $this->auditCount(AuditLog::ACTION_RUNNER_DELETED, $runnerId));
    }

    public function testAnAdminCreatesAndChangesRunnerGroups(): void
    {
        $fleet = $this->fleet();
        $groupId = (int)$fleet['group']->id;
        $this->signedIn('admin');

        $this->assertSame('rendered:form', $this->groupController()->runAction('create'));
        $this->setPost(['RunnerGroup' => ['name' => self::CREATED_GROUP]]);
        $this->assertInstanceOf(Response::class, $this->groupController()->runAction('create'));
        $created = RunnerGroup::findOne(['name' => self::CREATED_GROUP]);
        $this->assertNotNull($created);
        $this->assertSame(1, $this->auditCount(AuditLog::ACTION_RUNNER_GROUP_CREATED, (int)$created->id, 'runner_group'));

        $this->asGet();
        $this->assertSame('rendered:form', $this->groupController()->runAction('update', ['id' => $groupId]));
        $this->setPost(['RunnerGroup' => ['name' => self::RENAMED_GROUP]]);
        $this->assertInstanceOf(Response::class, $this->groupController()->runAction('update', ['id' => $groupId]));
        $this->assertSame(self::RENAMED_GROUP, RunnerGroup::findOne($groupId)?->name);
        $this->assertSame(1, $this->auditCount(AuditLog::ACTION_RUNNER_GROUP_UPDATED, $groupId, 'runner_group'));
    }

    /**
     * The documented limit (docs/runners.md): a custom role with
     * runner-group.update still registers runners, regenerates their tokens
     * and moves them, and with runner-group.create creates groups. Whoever
     * holds such a role can take over the jobs of every runner group.
     */
    public function testACustomRoleWithThePermissionsStillManagesRunners(): void
    {
        $fleet = $this->fleet();
        $this->loginAs($this->userWithCustomRole(['runner-group.view', 'runner-group.create', 'runner-group.update']));

        $this->setPost(['group_id' => (string)$fleet['group']->id, 'name' => self::REGISTERED_RUNNER, 'description' => '']);
        $this->runnerController()->runAction('create');
        $registered = Runner::findByToken($this->flashedToken(self::REGISTERED_RUNNER));
        $this->assertNotNull($registered);
        $this->assertSame((int)$fleet['group']->id, (int)$registered->runner_group_id);

        $this->session()->removeAllFlashes();
        $this->setPost([]);
        $this->runnerController()->runAction('regenerate-token', ['id' => (int)$fleet['runner']->id]);
        $this->assertSame((int)$fleet['runner']->id, Runner::findByToken($this->flashedToken($fleet['runner']->name))?->id);

        $this->setPost(['target_group_id' => (string)$fleet['target']->id]);
        $this->runnerController()->runAction('move', ['id' => (int)$fleet['runner']->id]);
        $this->assertSame((int)$fleet['target']->id, (int)Runner::findOne($fleet['runner']->id)?->runner_group_id);

        $this->asGet();
        $this->assertSame('rendered:form', $this->groupController()->runAction('create'));
    }

    // -- The pages ------------------------------------------------------------

    /**
     * Operators and viewers see the group and its runners, and in place of
     * the Add Runner form a note that only admins manage runners: no Add
     * Runner form, no Regen Token, Move or Delete, no Edit Group.
     */
    public function testOperatorsAndViewersSeeTheNoteInsteadOfTheRunnerControls(): void
    {
        $fleet = $this->fleet();
        foreach (['operator', 'viewer'] as $role) {
            $this->signedIn($role);

            $html = $this->groupPage($fleet['group']);

            $this->assertStringContainsString($this->note(), $html, $role);
            $this->assertStringContainsString(
                Html::encode('Only admins register, move and delete runners and regenerate their tokens: that needs runner-group.update.'),
                $html,
                $role
            );
            $this->assertStringContainsString('<span class="fw-semibold">' . Html::encode($fleet['runner']->name) . '</span>', $html, $role);
            foreach ($this->runnerControls($fleet) as $control) {
                $this->assertStringNotContainsString($control, $html, "{$role}: {$control}");
            }
        }
    }

    public function testAnAdminSeesTheAddRunnerFormAndTheRunnerControlsButNoNote(): void
    {
        $fleet = $this->fleet();
        $this->signedIn('admin');

        $html = $this->groupPage($fleet['group']);

        foreach ($this->runnerControls($fleet) as $control) {
            $this->assertStringContainsString($control, $html, $control);
        }
        $this->assertStringNotContainsString('runner-management-admin-only', $html);
        $this->assertStringNotContainsString(Html::encode(RunnerGroupController::RUNNER_MANAGEMENT_ADMIN_ONLY), $html);
    }

    public function testACustomRoleWithRunnerGroupUpdateSeesTheFormButNoNote(): void
    {
        $fleet = $this->fleet();
        $this->loginAs($this->userWithCustomRole(['runner-group.view', 'runner-group.update']));

        $html = $this->groupPage($fleet['group']);

        $this->assertStringContainsString('action="/runner/create"', $html);
        $this->assertStringNotContainsString('runner-management-admin-only', $html);
    }

    /**
     * A group without runners: "Add one above" only for those who see the
     * Add Runner form.
     */
    public function testTheEmptyGroupPointsAtTheFormOnlyForThoseWhoHaveIt(): void
    {
        $owner = $this->createUser('runner-rbac-empty');
        $group = $this->createRunnerGroup((int)$owner->id);

        foreach (['operator', 'viewer'] as $role) {
            $this->signedIn($role);
            $html = $this->groupPage($group);
            $this->assertStringContainsString($this->emptyGroup(RunnerGroupController::NO_RUNNERS), $html, $role);
            $this->assertStringNotContainsString(RunnerGroupController::NO_RUNNERS_ADD_ONE, $html, $role);
            $this->assertStringContainsString($this->note(), $html, $role);
        }

        $this->signedIn('admin');
        $html = $this->groupPage($group);
        $this->assertStringContainsString($this->emptyGroup(RunnerGroupController::NO_RUNNERS_ADD_ONE), $html);
        $this->assertSame(['No runners yet.', 'No runners yet. Add one above.'], [RunnerGroupController::NO_RUNNERS, RunnerGroupController::NO_RUNNERS_ADD_ONE]);
    }

    public function testTheListOffersNewGroupEditAndDeleteOnlyToAdmins(): void
    {
        $fleet = $this->fleet();
        $groupId = (int)$fleet['group']->id;
        $controls = [
            'href="/runner-group/create"',
            'href="/runner-group/update?id=' . $groupId . '"',
            'action="/runner-group/delete?id=' . $groupId . '"',
        ];

        foreach (['operator', 'viewer'] as $role) {
            $this->signedIn($role);
            $html = $this->listPage();
            $this->assertStringContainsString('href="/runner-group/view?id=' . $groupId . '"', $html, $role);
            foreach ($controls as $control) {
                $this->assertStringNotContainsString($control, $html, "{$role}: {$control}");
            }
        }

        $this->signedIn('admin');
        $html = $this->listPage();
        foreach ($controls as $control) {
            $this->assertStringContainsString($control, $html, "admin: {$control}");
        }
    }

    /**
     * Regression: the empty runner group list linked "Create one" to the
     * create page for everyone, also for users who may not create groups.
     */
    public function testTheEmptyListOffersCreateOneOnlyToThoseWhoMayCreate(): void
    {
        foreach (['viewer', 'operator'] as $role) {
            $this->signedIn($role);
            $html = $this->emptyListPage();
            $this->assertStringContainsString('No runner groups yet.', $html, $role);
            $this->assertStringNotContainsString('Create one', $html, $role);
            $this->assertStringNotContainsString('/runner-group/create', $html, $role);
        }

        $this->signedIn('admin');
        $this->assertStringContainsString('<a href="/runner-group/create">Create one</a> to get started.', $this->emptyListPage());
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
        $owner = $this->createUserWithRole('runner-rbac-owner', 'admin');
        $group = $this->createRunnerGroup((int)$owner->id);
        $target = $this->createRunnerGroup((int)$owner->id);
        $runner = $this->createRunner((int)$group->id, (int)$owner->id);
        $token = Runner::generateToken();
        $runner->token_hash = $token['hash'];
        $runner->save(false);

        return ['group' => $group, 'target' => $target, 'runner' => $runner, 'token' => $token['raw']];
    }

    /**
     * $label of managementRequestProvider() is refused by the access filter,
     * and leaves the runners, the groups, the audit log and the session as
     * they were: no raw token reaches a flash.
     *
     * @param array{group: RunnerGroup, target: RunnerGroup, runner: Runner, token: string} $fleet
     */
    private function assertRefusedWithoutAWrite(array $fleet, string $label): void
    {
        $before = $this->snapshot();

        try {
            $this->send($fleet, $label);
            $this->fail("{$label}: expected the access filter to refuse it");
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(self::DENIED, $e->getMessage(), $label);
        }

        $this->assertSame($before, $this->snapshot(), "{$label}: no runner, group or audit entry written");
        $this->assertSame([], $this->session()->getAllFlashes(), "{$label}: no flash, so no raw token");
        $this->assertSame((int)$fleet['runner']->id, Runner::findByToken($fleet['token'])?->id, "{$label}: the runner keeps its token");
        $this->assertNull(Runner::findOne(['name' => self::REGISTERED_RUNNER]), $label);
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
            'POST /runner/create' => ['runner', 'create', [], ['group_id' => (string)$groupId, 'name' => self::REGISTERED_RUNNER, 'description' => '']],
            'POST /runner/regenerate-token' => ['runner', 'regenerate-token', ['id' => $runnerId], []],
            'POST /runner/move' => ['runner', 'move', ['id' => $runnerId], ['target_group_id' => (string)$fleet['target']->id]],
            'POST /runner/delete' => ['runner', 'delete', ['id' => $runnerId], []],
            'GET /runner-group/create' => ['runner-group', 'create', [], null],
            'POST /runner-group/create' => ['runner-group', 'create', [], ['RunnerGroup' => ['name' => self::CREATED_GROUP]]],
            'GET /runner-group/update' => ['runner-group', 'update', ['id' => $groupId], null],
            'POST /runner-group/update' => ['runner-group', 'update', ['id' => $groupId], ['RunnerGroup' => ['name' => self::RENAMED_GROUP]]],
        ];
        $this->assertArrayHasKey($label, $requests);
        [$controllerId, $action, $params, $post] = $requests[$label];
        if ($post === null) {
            $this->asGet();
        } else {
            $this->setPost($post);
        }
        $ctrl = $controllerId === 'runner' ? $this->runnerController() : $this->groupController();

        return $ctrl->runAction($action, $params);
    }

    /**
     * The next request is a GET without a body.
     */
    private function asGet(): void
    {
        $this->setPost([]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
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

    /**
     * The controls of the runner group page that manage runners: the Add
     * Runner form, Regen Token, Move and Delete of the fleet's runner, and
     * Edit Group.
     *
     * @param array{group: RunnerGroup, target: RunnerGroup, runner: Runner, token: string} $fleet
     * @return list<string>
     */
    private function runnerControls(array $fleet): array
    {
        $runnerId = (int)$fleet['runner']->id;

        return [
            '<div class="card-header">Add Runner</div>',
            'action="/runner/create"',
            'action="/runner/regenerate-token?id=' . $runnerId . '"',
            '>Regen Token</button>',
            'action="/runner/move?id=' . $runnerId . '"',
            'action="/runner/delete?id=' . $runnerId . '"',
            'href="/runner-group/update?id=' . (int)$fleet['group']->id . '"',
            '>Edit Group</a>',
        ];
    }

    private function note(): string
    {
        return '<p class="text-muted small mb-4" data-testid="runner-management-admin-only">'
            . Html::encode(RunnerGroupController::RUNNER_MANAGEMENT_ADMIN_ONLY) . '</p>';
    }

    /**
     * What the runner group page of a group without runners shows in place
     * of the list of its runners: $text.
     */
    private function emptyGroup(string $text): string
    {
        return '<div class="card-body text-muted small" data-testid="runner-group-empty">' . Html::encode($text) . '</div>';
    }

    /**
     * The raw token of the data flash runner_token, which the runner group
     * page shows once; it must name $runnerName.
     */
    private function flashedToken(string $runnerName): string
    {
        $flash = $this->session()->getFlash('runner_token');
        $this->assertIsArray($flash);
        $this->assertSame($runnerName, $flash['runner_name'] ?? null);
        $token = $flash['raw_token'] ?? null;
        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        return $token;
    }

    private function auditCount(string $action, int $objectId, string $objectType = 'runner'): int
    {
        return (int)AuditLog::find()
            ->where(['action' => $action, 'object_type' => $objectType, 'object_id' => $objectId])
            ->count();
    }

    /**
     * @param array<string, mixed> $params what the view action rendered with
     * @return list<Runner>
     */
    private function runnersOf(array $params): array
    {
        $runners = $params['runners'] ?? null;
        $this->assertIsArray($runners);

        return array_values(array_filter($runners, static fn (mixed $runner): bool => $runner instanceof Runner));
    }

    private function signedIn(string $role): User
    {
        $user = $this->createUserWithRole('runner-rbac-' . $role, $role);
        $this->loginAs($user);

        return $user;
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
        $role = $auth->createRole('runner-managers-' . uniqid());
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $user = $this->createUser('runner-rbac-custom');
        $auth->assign($role, (string)$user->id);

        return $user;
    }

    private function groupPage(RunnerGroup $group): string
    {
        $ctrl = new RunnerGroupController('runner-group', $this->app());
        $ctrl->layout = false;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        return $this->withRealViews($ctrl, '/runner-group/view?id=' . $group->id, static fn (): string => $ctrl->actionView((int)$group->id));
    }

    private function listPage(): string
    {
        $ctrl = new RunnerGroupController('runner-group', $this->app());
        $ctrl->layout = false;

        return $this->withRealViews($ctrl, '/runner-group/index', static fn (): string => $ctrl->actionIndex());
    }

    /**
     * The runner group list as it shows without any group.
     */
    private function emptyListPage(): string
    {
        $ctrl = new RunnerGroupController('runner-group', $this->app());
        $ctrl->layout = false;

        return $this->withRealViews(
            $ctrl,
            '/runner-group/index',
            static fn (): string => $ctrl->render('index', ['groups' => [], 'total' => [], 'online' => [], 'templateCounts' => []])
        );
    }

    private function session(): \yii\web\Session
    {
        $session = \Yii::$app->session;
        $this->assertInstanceOf(\yii\web\Session::class, $session);

        return $session;
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return $app;
    }

    /**
     * @return RunnerController&object{redirectedTo: mixed}
     */
    private function runnerController(): RunnerController
    {
        return new class ('runner', $this->app()) extends RunnerController {
            public mixed $redirectedTo = null;

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $this->redirectedTo = $url;
                return new Response();
            }
        };
    }

    /**
     * @return RunnerGroupController&object{capturedParams: array<string, mixed>, redirectedTo: mixed}
     */
    private function groupController(): RunnerGroupController
    {
        return new class ('runner-group', $this->app()) extends RunnerGroupController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public mixed $redirectedTo = null;

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                $this->redirectedTo = $url;
                return new Response();
            }
        };
    }
}
