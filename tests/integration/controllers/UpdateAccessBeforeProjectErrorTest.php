<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\JobTemplatesController;
use app\controllers\BaseController;
use app\controllers\InventoryController;
use app\controllers\JobTemplateController;
use app\models\ApiToken;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
use yii\web\ForbiddenHttpException;

/**
 * The order that docs/updating.md, web/openapi.yaml (JobTemplateRejected,
 * InventoryInput) and JobTemplateController::actionUpdate() state for a
 * project_id that is no project ID on an update: the access check of the
 * stored project comes first, so a caller who may not change the stored job
 * template or inventory gets 403, whatever project_id the body holds, and
 * nothing is written. The project's 422 comes before the access check of the
 * project the body names and before the lock only, as for a caller who may
 * change the template. InventoriesControllerNumbersTest pins the same order
 * for PUT /api/v1/inventories.
 *
 * Review finding: the docs said that such a project_id is refused before
 * every access check, which holds on a create only.
 */
class UpdateAccessBeforeProjectErrorTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    /** project_id values that are no project ID: a fraction, a JSON number beyond the int range, text. */
    private const NO_PROJECT_IDS = ['5.5', '1e30', '"abc"'];

    /** The integer rule's message for such a project_id. */
    private const NOT_A_PROJECT_ID = 'Project Id must be an integer.';

    public function testAnApiUpdateOfAJobTemplateIs403FirstForACallerWhoMayNotChangeIt(): void
    {
        $template = $this->templateOfATeam();
        $before = JobTemplate::findOne($template->id)?->getAttributes();

        $this->authenticate($this->createUserWithRole('order-jt-api-outsider', 'operator'));
        foreach (self::NO_PROJECT_IDS as $value) {
            $this->assertSame(['error' => ['message' => 'Forbidden.']], $this->apiUpdate($template, $value), $value);
            $this->assertSame(403, \Yii::$app->response->statusCode, $value);
        }
        $this->assertSame($before, JobTemplate::findOne($template->id)?->getAttributes(), 'nothing was saved');

        // A caller who may change the template gets the project's error instead.
        $this->authenticate($this->createUserWithRole('order-jt-api-admin', 'admin'));
        foreach (self::NO_PROJECT_IDS as $value) {
            $this->assertSame(['error' => ['message' => self::NOT_A_PROJECT_ID]], $this->apiUpdate($template, $value), $value);
            $this->assertSame(422, \Yii::$app->response->statusCode, $value);
        }
        $this->assertSame($before, JobTemplate::findOne($template->id)?->getAttributes(), 'nothing was saved');
    }

    public function testAWebUpdateOfAJobTemplateIs403FirstForAUserWhoMayNotChangeIt(): void
    {
        $template = $this->templateOfATeam();
        $before = JobTemplate::findOne($template->id)?->getAttributes();
        $this->loginAs($this->createUserWithRole('order-jt-web-outsider', 'operator'));

        foreach (self::NO_PROJECT_IDS as $value) {
            $this->sendJsonText('{"JobTemplate": {"name": "order-renamed-template", "project_id": ' . $value . '}}');
            try {
                (new JobTemplateController('job-template', $this->app()))->actionUpdate((int)$template->id);
                $this->fail('the stored template is in a project of another team: ' . $value);
            } catch (ForbiddenHttpException $e) {
                $this->assertSame(BaseController::MODIFY_FORBIDDEN, $e->getMessage(), $value);
            }
        }
        $this->assertSame($before, JobTemplate::findOne($template->id)?->getAttributes(), 'nothing was saved');
    }

    public function testAWebUpdateOfAnInventoryIs403FirstForAUserWhoMayNotChangeIt(): void
    {
        $inventory = $this->inventoryOfATeam();
        $before = Inventory::findOne($inventory->id)?->getAttributes();
        $this->loginAs($this->createUserWithRole('order-inv-web-outsider', 'operator'));

        foreach (self::NO_PROJECT_IDS as $value) {
            $this->sendJsonText('{"Inventory": {"name": "order-renamed-inventory", "project_id": ' . $value . '}}');
            try {
                (new InventoryController('inventory', $this->app()))->actionUpdate((int)$inventory->id);
                $this->fail('the stored inventory is in a project of another team: ' . $value);
            } catch (ForbiddenHttpException $e) {
                $this->assertSame(BaseController::MODIFY_FORBIDDEN, $e->getMessage(), $value);
            }
        }
        $this->assertSame($before, Inventory::findOne($inventory->id)?->getAttributes(), 'nothing was saved');
    }

    // -- Helpers ------------------------------------------------------------------

    /** A job template in a project that a team has, so that only its members and admins may change it. */
    private function templateOfATeam(): JobTemplate
    {
        $owner = (int)$this->createUser('order-owner')->id;
        $project = (int)$this->createProject($owner)->id;
        $this->createTeamProject((int)$this->createTeam($owner)->id, $project);
        $inventory = $this->createInventory($owner);
        $inventory->project_id = $project;
        $inventory->save(false);

        return $this->createJobTemplate($project, (int)$inventory->id, (int)$this->createRunnerGroup($owner)->id, $owner);
    }

    /** An inventory in a project that a team has. */
    private function inventoryOfATeam(): Inventory
    {
        $owner = (int)$this->createUser('order-inv-owner')->id;
        $project = (int)$this->createProject($owner)->id;
        $this->createTeamProject((int)$this->createTeam($owner)->id, $project);
        $inventory = $this->createInventory($owner);
        $inventory->project_id = $project;
        $inventory->save(false);

        return $inventory;
    }

    /**
     * PUT /api/v1/job-templates/{id} with a new name and $projectId as
     * written in the JSON body.
     *
     * @return mixed what the action answers
     */
    private function apiUpdate(JobTemplate $template, string $projectId): mixed
    {
        \Yii::$app->response->statusCode = 200;
        $this->sendJsonText('{"name": "order-renamed-template", "project_id": ' . $projectId . '}', 'PUT');

        return (new JobTemplatesController('api/v1/job-templates', $this->app()))->runAction('update', ['id' => $template->id]);
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'order-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    private function app(): \yii\base\Module
    {
        $this->assertNotNull(\Yii::$app);

        return \Yii::$app;
    }
}
