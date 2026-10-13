<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\RunnerGroupController;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Runner;
use app\models\RunnerGroup;
use app\services\RunnerGroupDeletionService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The runner group page shows when self-registration re-issued a runner's
 * token, so a hijacked or duplicated RUNNER_NAME becomes visible.
 *
 * Deleting a group refuses, as the API does, while runners belong to it or
 * job templates run on it.
 */
class RunnerGroupControllerActionTest extends WebControllerTestCase
{
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
