<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\RunnerGroupController;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\User;
use app\services\RunnerGroupDeletionService;
use yii\helpers\Html;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The runner group page shows when self-registration re-issued a runner's
 * token, so a hijacked or duplicated RUNNER_NAME becomes visible.
 *
 * Deleting a group refuses, as the API does, while runners belong to it or
 * job templates run on it. Creating and changing a group never take its
 * creator from the form. The list says how runners get their tokens.
 */
class RunnerGroupControllerActionTest extends WebControllerTestCase
{
    use RendersRealViews;

    /**
     * @return RunnerGroupController&object{capturedParams: array<string, mixed>, redirectedTo: mixed}
     */
    private function makeController(): RunnerGroupController
    {
        /** @var \yii\base\Module $app the test application */
        $app = \Yii::$app;

        return new class ('runner-group', $app) extends RunnerGroupController {
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

    private function reregistered(int $runnerId, int $at): void
    {
        \Yii::$app->db->createCommand()->insert(AuditLog::tableName(), [
            'action' => AuditLog::ACTION_RUNNER_REREGISTERED,
            'object_type' => 'runner',
            'object_id' => $runnerId,
            'created_at' => $at,
        ])->execute();
    }

    public function testViewPassesTheReregistrationSummaryOfTheGroupsRunners(): void
    {
        $user = $this->createUser('rg-view');
        $this->loginAs($user);
        $group = $this->createRunnerGroup((int)$user->id);
        $quiet = $this->createRunner($group->id, (int)$user->id);
        $busy = $this->createRunner($group->id, (int)$user->id);
        $elsewhere = $this->createRunner($this->createRunnerGroup((int)$user->id)->id, (int)$user->id);
        $now = time();
        $this->reregistered($busy->id, $now - 3600);
        $this->reregistered($busy->id, $now - 60);
        $this->reregistered($elsewhere->id, $now - 60);

        $ctrl = $this->makeController();
        $ctrl->actionView($group->id);

        $this->assertSame([$busy->id => ['last_at' => $now - 60, 'recent' => 2]], $ctrl->capturedParams['reregistrations']);
        $this->assertArrayNotHasKey($quiet->id, $ctrl->capturedParams['reregistrations']);
    }

    // -- actionDelete() ---------------------------------------------------------------

    public function testAGroupNothingUsesIsDeleted(): void
    {
        $user = $this->createUser('rg-delete');
        $this->loginAs($user);
        $group = $this->createRunnerGroup((int)$user->id);

        $ctrl = $this->makeController();
        $ctrl->actionDelete((int)$group->id);

        $this->assertSame(['index'], $ctrl->redirectedTo);
        $this->assertSame(['success' => 'Runner group deleted.'], \Yii::$app->session->getAllFlashes());
        $this->assertNull(RunnerGroup::findOne($group->id));
        $this->assertSame(['name' => $group->name], $this->deletedAuditContext((int)$group->id));
    }

    /**
     * Regression: the web runner-group delete removed a group that job
     * templates use. The API refused it with 422, but the web UI deleted the
     * group, and the foreign key cleared the runner group of its templates:
     * no runner claimed their jobs any more, and a template with an approval
     * rule changed without an admin. Now the group stays, and its page shows
     * the API's refusal.
     */
    public function testRegressionTheWebDeleteRemovedAGroupThatJobTemplatesUse(): void
    {
        $user = $this->createUser('rg-templates');
        $this->loginAs($user);
        $group = $this->createRunnerGroup((int)$user->id);
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$group->id,
            (int)$user->id
        );

        $ctrl = $this->makeController();
        $ctrl->actionDelete((int)$group->id);

        $this->assertSame(['view', 'id' => (int)$group->id], $ctrl->redirectedTo);
        $this->assertSame(
            ['danger' => "Cannot delete \"{$group->name}\": 1 job template(s) reference this group."],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertSame(
            ['danger' => sprintf(RunnerGroupDeletionService::TEMPLATES_REFUSED, $group->name, 1)],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertNotNull(RunnerGroup::findOne($group->id), 'the group stays');
        $this->assertSame((int)$group->id, (int)JobTemplate::findOne($template->id)?->runner_group_id, 'the template keeps it');
        $this->assertNull($this->deletedAuditContext((int)$group->id), 'nothing deleted, nothing audited');
    }

    /**
     * Regression: the web runner-group delete removed a group that runners
     * belong to, and its runners with it (foreign key ON DELETE CASCADE).
     */
    public function testRegressionTheWebDeleteRemovedAGroupWithItsRunners(): void
    {
        $user = $this->createUser('rg-runners');
        $this->loginAs($user);
        $group = $this->createRunnerGroup((int)$user->id);
        $runner = $this->createRunner($group->id, (int)$user->id);

        $ctrl = $this->makeController();
        $ctrl->actionDelete((int)$group->id);

        $this->assertSame(['view', 'id' => (int)$group->id], $ctrl->redirectedTo);
        $this->assertSame(
            ['danger' => "Cannot delete \"{$group->name}\": 1 runner(s) still belong to this group."],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertNotNull(RunnerGroup::findOne($group->id), 'the group stays');
        $this->assertNotNull(Runner::findOne($runner->id), 'and so does its runner');
        $this->assertNull($this->deletedAuditContext((int)$group->id));
    }

    public function testAGroupWhoseTemplatesAreDeletedIsDeleted(): void
    {
        $user = $this->createUser('rg-softdel');
        $this->loginAs($user);
        $group = $this->createRunnerGroup((int)$user->id);
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$group->id,
            (int)$user->id
        );
        $this->assertTrue($template->softDelete());

        $ctrl = $this->makeController();
        $ctrl->actionDelete((int)$group->id);

        $this->assertSame(['success' => 'Runner group deleted.'], \Yii::$app->session->getAllFlashes());
        $this->assertNull(RunnerGroup::findOne($group->id));
    }

    public function testDeletingAGroupThatDoesNotExistIsNotFound(): void
    {
        $this->loginAs($this->createUser('rg-missing'));

        $this->expectException(NotFoundHttpException::class);
        $this->makeController()->actionDelete(999999999);
    }

    // -- actionCreate() and actionUpdate(): the creator -------------------------------

    /**
     * Regression (review of the runner step, finding 4): created_by was a
     * safe attribute of RunnerGroup, and actionCreate() loads the form after
     * it sets the creator, so a posted RunnerGroup[created_by] made another
     * user the creator of a new group.
     */
    public function testRegressionAPostedCreatorIsIgnoredOnCreate(): void
    {
        $admin = $this->signedInAdmin('rg-create-admin');
        $other = $this->createUser('rg-create-forged');
        $name = 'rg-created-' . uniqid();
        $this->setPost(['RunnerGroup' => ['name' => $name, 'description' => 'made by an admin', 'created_by' => (string)$other->id]]);

        $ctrl = $this->makeController();
        $this->assertInstanceOf(Response::class, $ctrl->runAction('create'));

        $group = RunnerGroup::findOne(['name' => $name]);
        $this->assertNotNull($group, 'the rest of the form is saved');
        $this->assertSame((int)$admin->id, (int)$group->created_by, 'the creator is the signed-in user');
        $this->assertSame('made by an admin', $group->description);
        $this->assertSame(['view', 'id' => (int)$group->id], $ctrl->redirectedTo);
    }

    /**
     * Regression (review of the runner step, finding 4): a posted
     * RunnerGroup[created_by] changed the creator of an existing group.
     */
    public function testRegressionAPostedCreatorIsIgnoredOnUpdate(): void
    {
        $owner = $this->createUser('rg-update-owner');
        $group = $this->createRunnerGroup((int)$owner->id);
        $this->signedInAdmin('rg-update-admin');
        $other = $this->createUser('rg-update-forged');
        $this->setPost(['RunnerGroup' => ['name' => 'rg-renamed-' . uniqid(), 'created_by' => (string)$other->id]]);

        $ctrl = $this->makeController();
        $this->assertInstanceOf(Response::class, $ctrl->runAction('update', ['id' => (int)$group->id]));

        $group->refresh();
        $this->assertSame((int)$owner->id, (int)$group->created_by, 'the creator stays');
        $this->assertStringStartsWith('rg-renamed-', $group->name, 'the rest of the form is saved');
        $this->assertSame(['view', 'id' => (int)$group->id], $ctrl->redirectedTo);
    }

    // -- actionIndex(): the note on runner tokens ------------------------------------

    /**
     * Regression: the list of runner groups said that runners register
     * against a group "using the group token", but a runner group has no
     * token. Each runner has one of its own, which an admin hands out on the
     * group's page, or which self-registration with the bootstrap secret
     * gives it; the note says so to every role, with or without groups.
     */
    public function testRegressionTheListSaysEachRunnerHasATokenOfItsOwn(): void
    {
        $this->createRunnerGroup((int)$this->createUser('rg-note-owner')->id);
        foreach (['viewer', 'operator', 'admin'] as $role) {
            $this->signedInAs('rg-note-' . $role, $role);
            foreach (['with groups' => $this->listPage(null), 'without groups' => $this->listPage([])] as $case => $html) {
                $this->assertStringContainsString(
                    '<span data-testid="runner-tokens-note">' . Html::encode(RunnerGroupController::RUNNER_TOKENS_NOTE) . '</span>',
                    $html,
                    "{$role}, {$case}"
                );
                $this->assertStringNotContainsString('group token', $html, "{$role}, {$case}");
                $this->assertStringContainsString('href="https://github.com/ansilume/ansilume/blob/main/docs/runners.md"', $html, "{$role}, {$case}: the docs link stays");
            }
        }
        $this->assertSame([], preg_grep('/token/', RunnerGroup::getTableSchema()->getColumnNames()), 'A runner group has no token.');
        $this->assertNotNull(Runner::getTableSchema()->getColumn('token_hash'), 'Each runner has one.');
    }

    /**
     * A user with the admin role, signed in: the access rules of create and
     * update run as for a real request (runAction()).
     */
    private function signedInAdmin(string $suffix): User
    {
        return $this->signedInAs($suffix, 'admin');
    }

    private function signedInAs(string $suffix, string $roleName): User
    {
        $user = $this->createUser($suffix);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole($roleName);
        $this->assertNotNull($role, $roleName);
        $auth->assign($role, (string)$user->id);
        $this->loginAs($user);

        return $user;
    }

    /**
     * The list of runner groups as the real view renders it: the groups of
     * the database (null), or those of $groups.
     *
     * @param list<RunnerGroup>|null $groups
     */
    private function listPage(?array $groups): string
    {
        /** @var \yii\base\Module $app the test application */
        $app = \Yii::$app;
        $ctrl = new RunnerGroupController('runner-group', $app);
        $ctrl->layout = false;
        $render = $groups === null
            ? static fn (): string => $ctrl->actionIndex()
            : static fn (): string => $ctrl->render('index', ['groups' => $groups, 'total' => [], 'online' => [], 'templateCounts' => []]);

        return $this->withRealViews($ctrl, '/runner-group/index', $render);
    }

    /**
     * @return array<string, mixed>|null the context of the group's runner_group.deleted entry; null when there is none
     */
    private function deletedAuditContext(int $groupId): ?array
    {
        $entries = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_RUNNER_GROUP_DELETED, 'object_type' => 'runner_group', 'object_id' => $groupId])
            ->all();
        if ($entries === []) {
            return null;
        }
        $this->assertCount(1, $entries);
        $context = json_decode((string)$entries[0]->metadata, true);
        $this->assertIsArray($context);

        return $context;
    }
}
