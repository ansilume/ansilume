<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\BaseApiController;
use app\models\ApiToken;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Project;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\Schedule;
use app\models\Team;
use app\models\User;
use app\models\Webhook;
use app\models\WorkflowStep;
use app\services\ldap\FakeLdapClient;
use app\services\ldap\LdapConfig;
use app\services\ldap\LdapService;
use app\tests\integration\controllers\WebControllerTestCase;

/**
 * A superadmin holds every permission in the REST API, also without any
 * RBAC role, as BaseApiController::userCan() and the web UI's access rules
 * say.
 *
 * Regression: the gate in beforeAction() let a roleless superadmin pass, but
 * most writes checked the permission again with \Yii::$app->user->can(),
 * which knows nothing of superadmins, and answered 403. These tests call the
 * actions through runAction() with a Bearer token, so the gate runs too.
 */
class ApiSuperadminTest extends WebControllerTestCase
{
    private mixed $originalLdapService = null;
    /** @var array<string, mixed>|null */
    private ?array $originalLdapParams = null;
    private bool $ldapReplaced = false;

    protected function tearDown(): void
    {
        if ($this->ldapReplaced) {
            \Yii::$app->set('ldapService', $this->originalLdapService);
            if ($this->originalLdapParams === null) {
                unset(\Yii::$app->params['ldap']);
            } else {
                \Yii::$app->params['ldap'] = $this->originalLdapParams;
            }
        }
        parent::tearDown();
    }

    /**
     * regression: a superadmin without RBAC roles got 403 from POST, PUT and
     * DELETE /api/v1/job-templates, although the approval rule decisions
     * count superadmins as admins.
     */
    public function testARolelessSuperadminCreatesUpdatesAndDeletesAJobTemplate(): void
    {
        $superadmin = $this->tokenUser(true, null);
        $ownerId = (int)$superadmin->id;
        $body = [
            'name' => 'superadmin-template-' . uniqid('', true),
            'project_id' => (int)$this->createProject($ownerId)->id,
            'inventory_id' => (int)$this->createInventory($ownerId)->id,
            'runner_group_id' => (int)$this->createRunnerGroup($ownerId)->id,
            'playbook' => 'site.yml',
        ];

        $created = $this->callAs($superadmin, 'JobTemplates', 'create', 'POST', [], $body);
        $this->assertSame(201, $created['status'], 'create');
        $id = $this->idOf($created['response']);
        $template = JobTemplate::findOne($id);
        $this->assertNotNull($template);
        $this->assertSame($ownerId, (int)$template->created_by);

        $updated = $this->callAs($superadmin, 'JobTemplates', 'update', 'PUT', ['id' => $id], ['name' => 'superadmin-renamed']);
        $this->assertSame(200, $updated['status'], 'update');
        $template->refresh();
        $this->assertSame('superadmin-renamed', $template->name);

        $deleted = $this->callAs($superadmin, 'JobTemplates', 'delete', 'DELETE', ['id' => $id]);
        $this->assertSame(200, $deleted['status'], 'delete');
        $this->assertSame(['data' => ['deleted' => true, 'canceled_jobs' => 0, 'disabled_schedules' => 0]], $deleted['response']);
        $this->assertNull(JobTemplate::findOne($id), 'soft-deleted');
    }

    /**
     * One endpoint of every other API controller whose actions checked the
     * permission again (two for inventories, whose create and delete no
     * other test calls through the controller).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function endpointProvider(): array
    {
        return [
            'audit logs: list' => ['AuditLogs', 'index', 'GET', 200],
            'inventories: create' => ['Inventories', 'create', 'POST', 201],
            'inventories: delete' => ['Inventories', 'delete', 'DELETE', 200],
            'jobs: cancel' => ['Jobs', 'cancel', 'POST', 200],
            'ldap: connection test' => ['Ldap', 'test', 'GET', 200],
            'projects: delete' => ['Projects', 'delete', 'DELETE', 200],
            'roles: list' => ['Roles', 'index', 'GET', 200],
            'runner groups: create' => ['RunnerGroups', 'create', 'POST', 201],
            'runners: delete' => ['Runners', 'delete', 'DELETE', 200],
            'schedules: delete' => ['Schedules', 'delete', 'DELETE', 200],
            'system: artifact stats' => ['System', 'artifact-stats', 'GET', 200],
            'teams: create' => ['Teams', 'create', 'POST', 201],
            'users: list' => ['Users', 'index', 'GET', 200],
            'webhooks: delete' => ['Webhooks', 'delete', 'DELETE', 200],
        ];
    }

    /**
     * regression: a superadmin without RBAC roles got 403 from these
     * endpoints, which an admin may call. The LDAP test knew superadmins
     * already; it is here because its check moved to userCan() as well.
     *
     * @dataProvider endpointProvider
     */
    public function testARolelessSuperadminIsAnsweredAsAnAdmin(string $controller, string $action, string $verb, int $expected): void
    {
        $asAdmin = $this->callPrepared($this->tokenUser(false, 'admin'), $controller, $action, $verb);
        $asSuperadmin = $this->callPrepared($this->tokenUser(true, null), $controller, $action, $verb);

        $this->assertSame($expected, $asAdmin['status'], 'the admin');
        $this->assertTrue($asAdmin['done'], "the admin's call did its work");
        $this->assertSame($asAdmin['status'], $asSuperadmin['status'], 'a superadmin without roles is answered as an admin');
        $this->assertSame(array_keys($asAdmin['response']), array_keys($asSuperadmin['response']));
        $this->assertTrue($asSuperadmin['done'], "the superadmin's call did its work");
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function workflowStepProvider(): array
    {
        return [
            'add a step' => ['create', 'POST', 201],
            'change a step' => ['update', 'PUT', 200],
            'move a step' => ['move', 'POST', 200],
            'remove a step' => ['delete', 'DELETE', 200],
        ];
    }

    /**
     * The per-step endpoints of the workflow API, on a workflow with an
     * approval step, whose steps only admins may change (rule 12): a
     * superadmin without RBAC roles passes the gate, the team scope and the
     * policy, as an admin does.
     *
     * @dataProvider workflowStepProvider
     */
    public function testARolelessSuperadminChangesTheStepsOfAGatedWorkflow(string $action, string $verb, int $expected): void
    {
        $asAdmin = $this->changeStep($this->tokenUser(false, 'admin'), $action, $verb);
        $asSuperadmin = $this->changeStep($this->tokenUser(true, null), $action, $verb);

        $this->assertSame($expected, $asAdmin['status'], 'the admin');
        $this->assertTrue($asAdmin['done'], "the admin's call did its work");
        $this->assertSame($expected, $asSuperadmin['status'], 'a superadmin without roles is answered as an admin');
        $this->assertSame(['data', 'change'], array_keys($asSuperadmin['response']));
        $this->assertTrue($asSuperadmin['done'], "the superadmin's call did its work");
    }

    // -- helpers ------------------------------------------------------------------

    /**
     * $action of the per-step controller on the pause step of a new
     * workflow whose first step is an approval step, as $user.
     *
     * @return array{status: int, response: array<string, mixed>, done: bool}
     */
    private function changeStep(User $user, string $action, string $verb): array
    {
        $ownerId = (int)$user->id;
        $workflow = $this->createWorkflowTemplate($ownerId);
        $rule = $this->createApprovalRule($ownerId, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$ownerId]]));
        $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $stepId = (int)$this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_PAUSE)->id;
        $params = ['id' => (int)$workflow->id, 'stepId' => $stepId];
        [$params, $body, $done] = match ($action) {
            'create' => [['id' => (int)$workflow->id], ['name' => 'superadmin-step', 'step_type' => 'pause'], static fn (): bool => WorkflowStep::find()->where(['workflow_template_id' => $workflow->id, 'name' => 'superadmin-step'])->exists()],
            'update' => [$params, ['name' => 'superadmin-renamed'], static fn (): bool => WorkflowStep::findOne($stepId)?->name === 'superadmin-renamed'],
            'move' => [$params, ['position' => 'start'], static fn (): bool => (int)WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])->select('id')->scalar() === $stepId],
            default => [$params, [], static fn (): bool => WorkflowStep::findOne($stepId) === null],
        };

        return $this->callAs($user, 'WorkflowSteps', $action, $verb, $params, $body) + ['done' => $done()];
    }

    /**
     * @return array{status: int, response: array<string, mixed>, done: bool}
     */
    private function callPrepared(User $user, string $controller, string $action, string $verb): array
    {
        $request = $this->prepare($controller, $action, (int)$user->id);
        $answer = $this->callAs($user, $controller, $action, $verb, $request['params'], $request['body']);

        return $answer + ['done' => ($request['done'])()];
    }

    /**
     * The request for one endpoint, with fixtures owned by $ownerId: the
     * action's parameters, the body, and whether the call did its work
     * (always true for a read).
     *
     * @return array{params: array<string, mixed>, body: array<string, mixed>, done: callable(): bool}
     */
    private function prepare(string $controller, string $action, int $ownerId): array
    {
        $read = ['params' => [], 'body' => [], 'done' => static fn (): bool => true];

        return match ($controller) {
            'Inventories' => $action === 'create'
                ? $this->creating(Inventory::class, 'superadmin-inventory-', ['inventory_type' => Inventory::TYPE_STATIC, 'content' => "all:\n  hosts:\n    web-1:\n"])
                : $this->deleting((int)$this->createInventory($ownerId)->id, Inventory::class),
            'Jobs' => $this->canceling($this->createJob((int)$this->template($ownerId)->id, $ownerId)),
            'Ldap' => $this->withFakeLdap($read),
            'Projects' => $this->deleting((int)$this->createProject($ownerId)->id, Project::class),
            'RunnerGroups' => $this->creating(RunnerGroup::class, 'superadmin-group-'),
            'Runners' => $this->deleting((int)$this->createRunner((int)$this->createRunnerGroup($ownerId)->id, $ownerId)->id, Runner::class),
            'Schedules' => $this->deleting((int)$this->schedule($ownerId)->id, Schedule::class),
            'Teams' => $this->creating(Team::class, 'superadmin-team-'),
            'Webhooks' => $this->deleting((int)$this->createWebhook($ownerId)->id, Webhook::class),
            default => $read,
        };
    }

    /**
     * @param class-string<\yii\db\ActiveRecord> $class
     * @return array{params: array<string, mixed>, body: array<string, mixed>, done: callable(): bool}
     */
    private function deleting(int $id, string $class): array
    {
        return ['params' => ['id' => $id], 'body' => [], 'done' => static fn (): bool => $class::findOne($id) === null];
    }

    /**
     * @param class-string<\yii\db\ActiveRecord> $class
     * @param array<string, mixed> $fields the body besides the name
     * @return array{params: array<string, mixed>, body: array<string, mixed>, done: callable(): bool}
     */
    private function creating(string $class, string $prefix, array $fields = []): array
    {
        $name = $prefix . uniqid('', true);

        return [
            'params' => [],
            'body' => ['name' => $name] + $fields,
            'done' => static fn (): bool => $class::find()->where(['name' => $name])->exists(),
        ];
    }

    /**
     * @return array{params: array<string, mixed>, body: array<string, mixed>, done: callable(): bool}
     */
    private function canceling(Job $job): array
    {
        $id = (int)$job->id;

        return [
            'params' => ['id' => $id],
            'body' => [],
            'done' => static fn (): bool => Job::findOne($id)?->status === Job::STATUS_CANCELED,
        ];
    }

    /**
     * The LDAP endpoint talks to a fake directory.
     *
     * @param array{params: array<string, mixed>, body: array<string, mixed>, done: callable(): bool} $request
     * @return array{params: array<string, mixed>, body: array<string, mixed>, done: callable(): bool}
     */
    private function withFakeLdap(array $request): array
    {
        if (!$this->ldapReplaced) {
            $this->originalLdapService = \Yii::$app->getComponents(true)['ldapService'] ?? null;
            $params = \Yii::$app->params['ldap'] ?? null;
            $this->originalLdapParams = is_array($params) ? $params : null;
            $this->ldapReplaced = true;
        }
        $config = ['enabled' => true, 'host' => 'fake', 'baseDn' => 'dc=test', 'attrUsername' => 'uid', 'attrEmail' => 'mail', 'attrUid' => 'entryUUID'];
        \Yii::$app->params['ldap'] = $config;
        $service = new LdapService();
        $service->client = new FakeLdapClient(LdapConfig::fromArray($config));
        \Yii::$app->set('ldapService', $service);

        return $request;
    }

    private function template(int $ownerId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );
    }

    private function schedule(int $ownerId): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'superadmin-schedule-' . uniqid('', true);
        $schedule->job_template_id = (int)$this->template($ownerId)->id;
        $schedule->cron_expression = '0 * * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = false;
        $schedule->created_by = $ownerId;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $schedule->save(false);

        return $schedule;
    }

    /**
     * A user for API token calls: a superadmin, and/or holding $role (null:
     * no RBAC role at all).
     */
    private function tokenUser(bool $superadmin, ?string $role): User
    {
        $user = $this->createUser($superadmin ? 'api-superadmin' : 'api-admin');
        $user->is_superadmin = $superadmin;
        $user->save(false);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        if ($role !== null) {
            $item = $auth->getRole($role);
            $this->assertNotNull($item, $role);
            $auth->assign($item, (string)$user->id);
        }
        $this->assertSame($role === null ? [] : [$role], array_keys($auth->getAssignments((string)$user->id)));

        return $user;
    }

    /**
     * Run $action of the API controller $controller as $user, through
     * runAction() (gate included), and return the status and the answer.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $body
     * @return array{status: int, response: array<string, mixed>}
     */
    private function callAs(User $user, string $controller, string $action, string $verb, array $params = [], array $body = []): array
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'superadmin-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
        $_SERVER['REQUEST_METHOD'] = $verb;
        \Yii::$app->response->statusCode = 200;
        \Yii::$app->response->data = null;

        /** @var class-string<BaseApiController> $class */
        $class = 'app\\controllers\\api\\v1\\' . $controller . 'Controller';
        $result = (new $class('api/v1/' . strtolower($controller), \Yii::$app))->runAction($action, $params);
        $response = is_array($result) ? $result : \Yii::$app->response->data;
        $this->assertIsArray($response, "{$controller}/{$action} answers with an array");

        return ['status' => \Yii::$app->response->statusCode, 'response' => $response];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function idOf(array $response): int
    {
        $data = $response['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertArrayHasKey('id', $data);

        return (int)$data['id'];
    }
}
