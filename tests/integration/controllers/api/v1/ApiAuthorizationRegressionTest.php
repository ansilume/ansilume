<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\AnalyticsController;
use app\controllers\api\v1\ApprovalRulesController;
use app\controllers\api\v1\CredentialsController;
use app\controllers\api\v1\NotificationTemplatesController;
use app\controllers\api\v1\SchedulesController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApiToken;
use app\models\ApprovalRule;
use app\models\NotificationTemplate;
use app\models\Schedule;
use app\models\User;
use app\models\WorkflowJob;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * Regression: endpoints without a permission check did their work for any
 * token. These tests go through runAction(), so they prove that a rejected
 * request changes nothing, not just that the gate says no.
 */
class ApiAuthorizationRegressionTest extends WebControllerTestCase
{
    /**
     * @param list<string> $roles
     */
    private function authenticateWithRoles(array $roles): User
    {
        $user = $this->createUser('authz');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        foreach ($roles as $roleName) {
            $role = $auth->getRole($roleName);
            $this->assertNotNull($role, $roleName);
            $auth->assign($role, (string)$user->id);
        }
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'authz-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);

        return $user;
    }

    private function assertForbidden(mixed $result): void
    {
        $this->assertNull($result, 'the action must not run');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], \Yii::$app->response->data);
    }

    public function testViewerTokenCannotLaunchAWorkflow(): void
    {
        $viewer = $this->authenticateWithRoles(['viewer']);
        $workflow = $this->createWorkflowTemplate((int)$viewer->id);
        $before = WorkflowJob::find()->count();

        $result = (new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app))
            ->runAction('launch', ['id' => $workflow->id]);

        $this->assertForbidden($result);
        $this->assertSame($before, WorkflowJob::find()->count());
    }

    public function testViewerTokenCannotCreateOrFireNotificationTemplates(): void
    {
        $viewer = $this->authenticateWithRoles(['viewer']);
        $existing = $this->createNotificationTemplate((int)$viewer->id);
        $before = NotificationTemplate::find()->count();
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['name' => 'injected', 'channel' => 'email', 'config' => ['emails' => ['x@example.com']]]);
        $controller = new NotificationTemplatesController('api/v1/notification-templates', \Yii::$app);

        $this->assertForbidden($controller->runAction('create'));
        $this->assertSame($before, NotificationTemplate::find()->count());

        $this->assertForbidden($controller->runAction('test', ['id' => $existing->id]));
    }

    public function testViewerTokenCannotToggleASchedule(): void
    {
        $viewer = $this->authenticateWithRoles(['viewer']);
        $userId = (int)$viewer->id;
        $project = $this->createProject($userId);
        $template = $this->createJobTemplate(
            $project->id,
            $this->createInventory($userId)->id,
            $this->createRunnerGroup($userId)->id,
            $userId
        );
        $schedule = new Schedule();
        $schedule->name = 'authz-schedule-' . uniqid('', true);
        $schedule->job_template_id = $template->id;
        $schedule->cron_expression = '0 * * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = $userId;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $schedule->save(false);

        $result = (new SchedulesController('api/v1/schedules', \Yii::$app))
            ->runAction('toggle', ['id' => $schedule->id]);

        $this->assertForbidden($result);
        $schedule->refresh();
        $this->assertTrue((bool)$schedule->enabled);
    }

    public function testTokenWithoutRolesCannotCreateApprovalRules(): void
    {
        $this->authenticateWithRoles([]);
        $before = ApprovalRule::find()->count();
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['name' => 'injected', 'approver_type' => 'role', 'approver_config' => ['role' => 'viewer']]);

        $result = (new ApprovalRulesController('api/v1/approval-rules', \Yii::$app))->runAction('create');

        $this->assertForbidden($result);
        $this->assertSame($before, ApprovalRule::find()->count());
    }

    /**
     * The real exploit path: production gives every signed-in user the viewer
     * role (authManager defaultRoles), so the API only leaked credentials
     * once an admin had removed credential.view from viewer to hide them.
     */
    public function testCredentialsFollowTheViewerRole(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertInstanceOf(\yii\rbac\DbManager::class, $auth);
        $previousDefaults = $auth->defaultRoles;
        $auth->defaultRoles = ['viewer'];
        try {
            $this->authenticateWithRoles([]);
            $controller = new CredentialsController('api/v1/credentials', \Yii::$app);
            $listed = $controller->runAction('index');
            $this->assertIsArray($listed, 'viewer holds credential.view by default');
            $this->assertArrayHasKey('data', $listed);

            $viewer = $auth->getRole('viewer');
            $permission = $auth->getPermission('credential.view');
            $this->assertNotNull($viewer);
            $this->assertNotNull($permission);
            $auth->removeChild($viewer, $permission);

            $this->assertForbidden((new CredentialsController('api/v1/credentials', \Yii::$app))->runAction('index'));
        } finally {
            $auth->defaultRoles = $previousDefaults;
        }
    }

    public function testCsvExportNeedsTheExportPermission(): void
    {
        $this->authenticateWithRoles(['viewer']);
        $this->setQueryParams(['format' => 'csv']);

        $result = (new AnalyticsController('api/v1/analytics', \Yii::$app))->runAction('summary');

        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    public function testCsvExportWorksWithTheExportPermission(): void
    {
        $this->authenticateWithRoles(['operator']);
        $this->setQueryParams(['format' => 'csv']);

        (new AnalyticsController('api/v1/analytics', \Yii::$app))->runAction('summary');

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertStringContainsString('text/csv', (string)\Yii::$app->response->headers->get('Content-Type'));
    }
}
