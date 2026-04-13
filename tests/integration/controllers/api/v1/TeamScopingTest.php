<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\InventoriesController;
use app\controllers\api\v1\JobsController;
use app\controllers\api\v1\JobTemplatesController;
use app\controllers\api\v1\ProjectsController;
use app\controllers\api\v1\SchedulesController;
use app\models\ApiToken;
use app\models\Inventory;
use app\models\Job;
use app\models\Project;
use app\models\Schedule;
use app\models\TeamProject;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Team scoping integration tests — verifies that team-based access
 * restrictions are enforced across all API controllers.
 *
 * Setup: two teams, each with their own project. A user in team A
 * should only see team A's resources, not team B's.
 */
class TeamScopingTest extends WebControllerTestCase
{
    // -------------------------------------------------------------------------
    // Projects API
    // -------------------------------------------------------------------------

    public function testProjectIndexFiltersRestrictedProjects(): void
    {
        [$member, $projectA, $projectB] = $this->buildTwoTeamScenario();
        $this->authenticateUser($member);

        $ctrl = new ProjectsController('api/v1/projects', \Yii::$app);
        $result = $ctrl->actionIndex();

        $ids = array_column($result['data'], 'id');
        $this->assertContains($projectA->id, $ids, 'Team member sees own project');
        $this->assertNotContains($projectB->id, $ids, 'Team member does not see other team project');
    }

    public function testProjectViewAllowsAccessibleProject(): void
    {
        [$member, $projectA] = $this->buildTwoTeamScenario();
        $this->authenticateUser($member);

        $ctrl = new ProjectsController('api/v1/projects', \Yii::$app);
        $result = $ctrl->actionView($projectA->id);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame($projectA->id, $result['data']['id']);
    }

    public function testProjectViewDeniesInaccessibleProject(): void
    {
        [$member, , $projectB] = $this->buildTwoTeamScenario();
        $this->authenticateUser($member);

        $ctrl = new ProjectsController('api/v1/projects', \Yii::$app);
        $result = $ctrl->actionView($projectB->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testProjectUpdateDeniesViewerRole(): void
    {
        [$member, $projectA] = $this->buildTwoTeamScenario(TeamProject::ROLE_VIEWER);
        $this->authenticateUserWithRole($member, 'operator');
        $this->setBody(['name' => 'hacked-name']);

        $ctrl = new ProjectsController('api/v1/projects', \Yii::$app);
        $ctrl->actionUpdate($projectA->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testProjectDeleteDeniesNonTeamMember(): void
    {
        [$member, , $projectB] = $this->buildTwoTeamScenario();
        $this->authenticateUserWithRole($member, 'operator');

        $ctrl = new ProjectsController('api/v1/projects', \Yii::$app);
        $ctrl->actionDelete($projectB->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -------------------------------------------------------------------------
    // Job Templates API
    // -------------------------------------------------------------------------

    public function testJobTemplateIndexFiltersRestrictedTemplates(): void
    {
        [$member, $projectA, $projectB, $templateA, $templateB] = $this->buildTemplateScenario();
        $this->authenticateUser($member);

        $ctrl = new JobTemplatesController('api/v1/job-templates', \Yii::$app);
        $result = $ctrl->actionIndex();

        $ids = array_column($result['data'], 'id');
        $this->assertContains($templateA->id, $ids, 'Team member sees own template');
        $this->assertNotContains($templateB->id, $ids, 'Team member does not see other team template');
    }

    public function testJobTemplateViewAllowsAccessible(): void
    {
        [$member, , , $templateA] = $this->buildTemplateScenario();
        $this->authenticateUser($member);

        $ctrl = new JobTemplatesController('api/v1/job-templates', \Yii::$app);
        $result = $ctrl->actionView($templateA->id);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame($templateA->id, $result['data']['id']);
    }

    public function testJobTemplateViewDeniesInaccessible(): void
    {
        [$member, , , , $templateB] = $this->buildTemplateScenario();
        $this->authenticateUser($member);

        $ctrl = new JobTemplatesController('api/v1/job-templates', \Yii::$app);
        $ctrl->actionView($templateB->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testJobTemplateDeleteDeniesNonTeamMember(): void
    {
        [$member, , , , $templateB] = $this->buildTemplateScenario();
        $this->authenticateUserWithRole($member, 'operator');

        $ctrl = new JobTemplatesController('api/v1/job-templates', \Yii::$app);
        $ctrl->actionDelete($templateB->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -------------------------------------------------------------------------
    // Inventories API
    // -------------------------------------------------------------------------

    public function testInventoryIndexFiltersRestrictedInventories(): void
    {
        [$member, $invA, $invB] = $this->buildInventoryScenario();
        $this->authenticateUser($member);

        $ctrl = new InventoriesController('api/v1/inventories', \Yii::$app);
        $result = $ctrl->actionIndex();

        $ids = array_column($result['data'], 'id');
        $this->assertContains($invA->id, $ids, 'Team member sees own inventory');
        $this->assertNotContains($invB->id, $ids, 'Team member does not see other team inventory');
    }

    public function testInventoryViewDeniesInaccessible(): void
    {
        [$member, , $invB] = $this->buildInventoryScenario();
        $this->authenticateUser($member);

        $ctrl = new InventoriesController('api/v1/inventories', \Yii::$app);
        $ctrl->actionView($invB->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testGlobalInventoryVisibleToAll(): void
    {
        [$member] = $this->buildTwoTeamScenario();
        $this->authenticateUser($member);

        // Create a global inventory (no project_id)
        $globalInv = $this->createInventory($member->id);

        $ctrl = new InventoriesController('api/v1/inventories', \Yii::$app);
        $result = $ctrl->actionView($globalInv->id);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame($globalInv->id, $result['data']['id']);
    }

    // -------------------------------------------------------------------------
    // Jobs API
    // -------------------------------------------------------------------------

    public function testJobIndexFiltersRestrictedJobs(): void
    {
        [$member, , , $templateA, $templateB] = $this->buildTemplateScenario();
        $this->authenticateUser($member);

        $jobA = $this->createJob($templateA->id, $member->id);
        $jobB = $this->createJob($templateB->id, $member->id);

        $ctrl = new JobsController('api/v1/jobs', \Yii::$app);
        $result = $ctrl->actionIndex();

        $ids = array_column($result['data'], 'id');
        $this->assertContains($jobA->id, $ids, 'Team member sees own job');
        $this->assertNotContains($jobB->id, $ids, 'Team member does not see other team job');
    }

    public function testJobViewDeniesInaccessibleJob(): void
    {
        [$member, , , , $templateB] = $this->buildTemplateScenario();
        $this->authenticateUser($member);

        $jobB = $this->createJob($templateB->id, $member->id);

        $ctrl = new JobsController('api/v1/jobs', \Yii::$app);
        $ctrl->actionView($jobB->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testJobLaunchDeniesInaccessibleTemplate(): void
    {
        [$member, , , , $templateB] = $this->buildTemplateScenario();
        $this->authenticateUserWithRole($member, 'operator');

        $this->setBody(['template_id' => $templateB->id]);

        $ctrl = new JobsController('api/v1/jobs', \Yii::$app);
        $ctrl->actionCreate();

        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -------------------------------------------------------------------------
    // Schedules API
    // -------------------------------------------------------------------------

    public function testScheduleIndexFiltersRestrictedSchedules(): void
    {
        [$member, , , $templateA, $templateB] = $this->buildTemplateScenario();
        $this->authenticateUser($member);

        $schedA = $this->createSchedule($templateA->id, $member->id);
        $schedB = $this->createSchedule($templateB->id, $member->id);

        $ctrl = new SchedulesController('api/v1/schedules', \Yii::$app);
        $result = $ctrl->actionIndex();

        $ids = array_column($result['data'], 'id');
        $this->assertContains($schedA->id, $ids, 'Team member sees own schedule');
        $this->assertNotContains($schedB->id, $ids, 'Team member does not see other team schedule');
    }

    public function testScheduleViewDeniesInaccessible(): void
    {
        [$member, , , , $templateB] = $this->buildTemplateScenario();
        $this->authenticateUser($member);

        $schedB = $this->createSchedule($templateB->id, $member->id);

        $ctrl = new SchedulesController('api/v1/schedules', \Yii::$app);
        $ctrl->actionView($schedB->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    // -------------------------------------------------------------------------
    // Admin bypass
    // -------------------------------------------------------------------------

    public function testAdminSeesAllProjects(): void
    {
        [, $projectA, $projectB] = $this->buildTwoTeamScenario();
        $admin = $this->createUser('admin');
        \Yii::$app->db->createCommand()
            ->update('{{%user}}', ['is_superadmin' => 1], ['id' => $admin->id])
            ->execute();
        $this->authenticateUser($admin);

        $ctrl = new ProjectsController('api/v1/projects', \Yii::$app);
        $result = $ctrl->actionIndex();

        $ids = array_column($result['data'], 'id');
        $this->assertContains($projectA->id, $ids);
        $this->assertContains($projectB->id, $ids);
    }

    public function testAdminSeesAllTemplates(): void
    {
        [, , , $templateA, $templateB] = $this->buildTemplateScenario();
        $admin = $this->createUser('admin');
        \Yii::$app->db->createCommand()
            ->update('{{%user}}', ['is_superadmin' => 1], ['id' => $admin->id])
            ->execute();
        $this->authenticateUser($admin);

        $ctrl = new JobTemplatesController('api/v1/job-templates', \Yii::$app);
        $result = $ctrl->actionIndex();

        $ids = array_column($result['data'], 'id');
        $this->assertContains($templateA->id, $ids);
        $this->assertContains($templateB->id, $ids);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a scenario with two teams, two projects, and a user in team A only.
     *
     * @return array{0: \app\models\User, 1: Project, 2: Project}
     */
    private function buildTwoTeamScenario(string $roleA = TeamProject::ROLE_OPERATOR): array
    {
        $owner = $this->createUser('owner');
        $member = $this->createUser('member');

        $projectA = $this->createProject($owner->id);
        $projectB = $this->createProject($owner->id);

        $teamA = $this->createTeam($owner->id);
        $teamB = $this->createTeam($owner->id);

        $this->createTeamProject($teamA->id, $projectA->id, $roleA);
        $this->createTeamProject($teamB->id, $projectB->id, TeamProject::ROLE_OPERATOR);

        $this->addTeamMember($teamA->id, $member->id);
        // member is NOT in teamB

        return [$member, $projectA, $projectB];
    }

    /**
     * @return array{0: \app\models\User, 1: Project, 2: Project, 3: \app\models\JobTemplate, 4: \app\models\JobTemplate}
     */
    private function buildTemplateScenario(): array
    {
        [$member, $projectA, $projectB] = $this->buildTwoTeamScenario();
        $group = $this->createRunnerGroup($member->id);
        $invA = $this->createInventory($member->id);
        $invB = $this->createInventory($member->id);

        $templateA = $this->createJobTemplate($projectA->id, $invA->id, $group->id, $member->id);
        $templateB = $this->createJobTemplate($projectB->id, $invB->id, $group->id, $member->id);

        return [$member, $projectA, $projectB, $templateA, $templateB];
    }

    /**
     * @return array{0: \app\models\User, 1: Inventory, 2: Inventory}
     */
    private function buildInventoryScenario(): array
    {
        [$member, $projectA, $projectB] = $this->buildTwoTeamScenario();

        $invA = $this->createInventory($member->id);
        $invA->project_id = $projectA->id;
        $invA->save(false);

        $invB = $this->createInventory($member->id);
        $invB->project_id = $projectB->id;
        $invB->save(false);

        return [$member, $invA, $invB];
    }

    private function createSchedule(int $templateId, int $createdBy): Schedule
    {
        $s = new Schedule();
        $s->name = 'test-schedule-' . uniqid('', true);
        $s->job_template_id = $templateId;
        $s->cron_expression = '0 0 * * *';
        $s->timezone = 'UTC';
        $s->enabled = true;
        $s->created_by = $createdBy;
        $s->created_at = time();
        $s->updated_at = time();
        $s->save(false);
        return $s;
    }

    private function authenticateUser(\app\models\User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    private function authenticateUserWithRole(\app\models\User $user, string $role): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $rbacRole = $auth->getRole($role);
        $this->assertNotNull($rbacRole, "RBAC role '{$role}' must exist");
        $auth->assign($rbacRole, (string)$user->id);
        $this->authenticateUser($user);
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
