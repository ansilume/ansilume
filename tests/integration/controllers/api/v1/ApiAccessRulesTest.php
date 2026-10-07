<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\BaseApiController;
use app\models\ApiToken;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\base\InlineAction;

/**
 * Every REST action declares the permission it requires, and the base
 * controller enforces it before the action runs.
 *
 * Regression: WorkflowTemplates, WorkflowJobs, NotificationTemplates,
 * ApprovalRules, Approvals and Analytics checked no permission at all, and
 * several other read endpoints (credentials, schedules, runners, runner
 * groups, projects, inventories, job templates, jobs) checked none either.
 * A viewer token could launch workflows, toggle schedules or create and
 * fire notification templates, and the API showed credentials to users the
 * web UI hides them from.
 */
class ApiAccessRulesTest extends WebControllerTestCase
{
    /**
     * The permission per action, mirroring the web controllers' access rules.
     * '@' = any signed-in user.
     */
    private const EXPECTED = [
        'Analytics' => [
            'summary' => 'analytics.view', 'template-reliability' => 'analytics.view',
            'project-activity' => 'analytics.view', 'user-activity' => 'analytics.view',
            'host-health' => 'analytics.view', 'job-trend' => 'analytics.view',
            'workflow-summary' => 'analytics.view', 'workflow-activity' => 'analytics.view',
            'approval-summary' => 'analytics.view', 'runner-activity' => 'analytics.view',
        ],
        'ApprovalRules' => [
            'index' => 'approval-rule.view', 'view' => 'approval-rule.view', 'create' => 'approval-rule.create',
            'update' => 'approval-rule.update', 'delete' => 'approval-rule.delete',
        ],
        'Approvals' => [
            'index' => 'approval.view', 'view' => 'approval.view',
            'approve' => 'approval.decide', 'reject' => 'approval.decide',
        ],
        'AuditLogs' => ['index' => 'user.view', 'view' => 'user.view'],
        'CredentialAssignments' => ['index' => 'job-template.update', 'create' => 'job-template.update'],
        'Credentials' => [
            'index' => 'credential.view', 'view' => 'credential.view', 'create' => 'credential.create',
            'update' => 'credential.update', 'delete' => 'credential.delete',
        ],
        'Inventories' => [
            'index' => 'inventory.view', 'view' => 'inventory.view', 'create' => 'inventory.create',
            'update' => 'inventory.update', 'delete' => 'inventory.delete',
        ],
        'JobTemplates' => [
            'index' => 'job-template.view', 'view' => 'job-template.view', 'create' => 'job-template.create',
            'update' => 'job-template.update', 'delete' => 'job-template.delete',
        ],
        'Jobs' => [
            'index' => 'job.view', 'view' => 'job.view', 'create' => 'job.launch', 'cancel' => 'job.cancel',
            'artifacts' => 'job.view', 'download-artifact' => 'job.view', 'artifact-content' => 'job.view',
            'download-all-artifacts' => 'job.view',
        ],
        'Ldap' => ['test' => 'admin'],
        'NotificationTemplates' => [
            'index' => 'notification-template.view', 'view' => 'notification-template.view',
            'create' => 'notification-template.create', 'update' => 'notification-template.update',
            'test' => 'notification-template.update', 'delete' => 'notification-template.delete',
        ],
        'Profile' => ['change-password' => '@'],
        'ProjectVault' => ['view' => 'project.view', 'scan' => 'project.update'],
        'Projects' => [
            'index' => 'project.view', 'view' => 'project.view', 'create' => 'project.create',
            'update' => 'project.update', 'sync' => 'project.update', 'delete' => 'project.delete',
        ],
        'Roles' => [
            'index' => 'role.view', 'view' => 'role.view', 'permissions' => 'role.view',
            'create' => 'role.create', 'update' => 'role.update', 'delete' => 'role.delete',
        ],
        'RunnerGroups' => [
            'index' => 'runner-group.view', 'view' => 'runner-group.view', 'create' => 'runner-group.create',
            'update' => 'runner-group.update', 'delete' => 'runner-group.delete',
        ],
        'Runners' => [
            'index' => 'runner-group.view', 'view' => 'runner-group.view', 'move' => 'runner-group.update',
            'delete' => 'runner-group.update', 'regenerate-token' => 'runner-group.update',
        ],
        'Schedules' => [
            'index' => 'job.launch', 'view' => 'job.launch', 'create' => 'job.launch',
            'update' => 'job.launch', 'delete' => 'job.launch', 'toggle' => 'job.launch',
        ],
        'System' => ['artifact-stats' => 'user.view'],
        'Teams' => [
            'index' => 'admin', 'view' => 'admin', 'create' => 'admin', 'update' => 'admin', 'delete' => 'admin',
            'add-member' => 'admin', 'remove-member' => 'admin', 'add-project' => 'admin', 'remove-project' => 'admin',
        ],
        'Users' => [
            'index' => 'user.view', 'view' => 'user.view', 'create' => 'user.create',
            'update' => 'user.update', 'delete' => 'user.delete',
        ],
        'Webhooks' => ['index' => 'admin', 'view' => 'admin', 'create' => 'admin', 'update' => 'admin', 'delete' => 'admin'],
        'WorkflowJobs' => [
            'index' => 'workflow.view', 'view' => 'workflow.view',
            'cancel' => 'workflow.cancel', 'resume' => 'workflow.launch',
        ],
        'WorkflowTemplates' => [
            'index' => 'workflow-template.view', 'view' => 'workflow-template.view',
            'create' => 'workflow-template.create', 'update' => 'workflow-template.update',
            'delete' => 'workflow-template.delete', 'launch' => 'workflow.launch',
        ],
    ];

    /**
     * @return class-string<BaseApiController>
     */
    private static function controllerClass(string $name): string
    {
        /** @var class-string<BaseApiController> $class */
        $class = 'app\\controllers\\api\\v1\\' . $name . 'Controller';

        return $class;
    }

    private function controller(string $name): BaseApiController
    {
        $class = self::controllerClass($name);

        return new $class('api/v1/' . strtolower($name), \Yii::$app);
    }

    /**
     * @return array<string, string>
     */
    private function rulesOf(BaseApiController $controller): array
    {
        $method = new \ReflectionMethod($controller, 'apiAccessRules');
        /** @var array<string, string> $rules */
        $rules = $method->invoke($controller);

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function actionIdsOf(BaseApiController $controller): array
    {
        $ids = [];
        foreach ((new \ReflectionClass($controller))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (preg_match('/^action([A-Z]\w*)$/', $method->getName(), $m) === 1) {
                $ids[] = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $m[1]));
            }
        }
        sort($ids);

        return $ids;
    }

    public function testEveryApiControllerIsCovered(): void
    {
        $found = [];
        foreach ((array)glob(dirname(__DIR__, 5) . '/controllers/api/v1/*Controller.php') as $file) {
            $name = basename((string)$file, 'Controller.php');
            if ($name !== 'BaseApi') {
                $found[] = $name;
            }
        }
        sort($found);
        $expected = array_keys(self::EXPECTED);
        sort($expected);

        $this->assertSame($expected, $found, 'a new API controller needs an entry in EXPECTED');
    }

    public function testEveryActionHasTheExpectedRule(): void
    {
        foreach (self::EXPECTED as $name => $expected) {
            $controller = $this->controller($name);
            $rules = $this->rulesOf($controller);
            $actual = array_map(static fn (string $p): string => $p === BaseApiController::AUTHENTICATED ? '@' : $p, $rules);
            ksort($actual);
            ksort($expected);

            $this->assertSame($expected, $actual, "{$name}: rules differ from the expected matrix");
            $this->assertSame(array_keys($expected), $this->actionIdsOf($controller), "{$name}: every action needs a rule");
        }
    }

    public function testEveryRuleNamesAnExistingPermissionOrRole(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        foreach (self::EXPECTED as $name => $rules) {
            foreach ($rules as $action => $permission) {
                if ($permission === '@') {
                    continue;
                }
                $item = $auth->getPermission($permission) ?? $auth->getRole($permission);
                $this->assertNotNull($item, "{$name}/{$action}: unknown permission {$permission}");
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function ruleProvider(): array
    {
        $cases = [];
        foreach (self::EXPECTED as $name => $rules) {
            foreach ($rules as $action => $permission) {
                $cases["{$name}/{$action}"] = [$name, $action, $permission];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider ruleProvider
     */
    public function testGateEnforcesTheRule(string $name, string $action, string $permission): void
    {
        $this->authenticateWith([]);
        $allowedWithoutPermission = $this->passesGate($name, $action);
        if ($permission === '@') {
            $this->assertTrue($allowedWithoutPermission, 'any signed-in user may call this action');
            return;
        }
        $this->assertFalse($allowedWithoutPermission, 'a user without the permission must be stopped');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], \Yii::$app->response->data);

        $this->authenticateWith([$permission]);
        $this->assertTrue($this->passesGate($name, $action), "a user holding {$permission} must pass");
    }

    private function passesGate(string $name, string $action): bool
    {
        \Yii::$app->response->statusCode = 200;
        \Yii::$app->response->data = null;
        $controller = $this->controller($name);
        $method = 'action' . str_replace(' ', '', ucwords(str_replace('-', ' ', $action)));

        return $controller->beforeAction(new InlineAction($action, $controller, $method));
    }

    /**
     * Like createUser(), without the bcrypt hash: these users only ever
     * authenticate by API token, and hashing made each case cost a second.
     */
    private function createTokenUser(): User
    {
        $user = new User();
        $user->username = 'rules_' . uniqid('', true);
        $user->email = 'rules_' . uniqid('', true) . '@example.com';
        $user->password_hash = 'not-used-by-token-auth';
        $user->auth_key = \Yii::$app->security->generateRandomString();
        $user->status = User::STATUS_ACTIVE;
        $user->created_at = time();
        $user->updated_at = time();
        $user->save(false);

        return $user;
    }

    /**
     * @param list<string> $items permissions or roles the user receives
     */
    private function authenticateWith(array $items): void
    {
        $user = $this->createTokenUser();
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        foreach ($items as $itemName) {
            $role = $auth->getRole($itemName);
            if ($role === null) {
                $role = $auth->createRole('rules-test-' . uniqid());
                $auth->add($role);
                $permission = $auth->getPermission($itemName);
                $this->assertNotNull($permission);
                $auth->addChild($role, $permission);
            }
            $auth->assign($role, (string)$user->id);
        }
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'rules-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }
}
