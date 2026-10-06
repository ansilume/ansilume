<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\RunnerGroupController;
use app\models\AuditLog;

/**
 * The runner group page shows when self-registration re-issued a runner's
 * token, so a hijacked or duplicated RUNNER_NAME becomes visible.
 */
class RunnerGroupControllerActionTest extends WebControllerTestCase
{
    /**
     * @return RunnerGroupController&object{capturedParams: array<string, mixed>}
     */
    private function makeController(): RunnerGroupController
    {
        return new class ('runner-group', \Yii::$app) extends RunnerGroupController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
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
}
