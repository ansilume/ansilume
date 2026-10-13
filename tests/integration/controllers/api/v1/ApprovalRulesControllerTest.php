<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ApprovalRulesController;
use app\models\ApiToken;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\services\ApprovalRuleWriteService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Json;

/**
 * REST API for approval rules, through runAction() with a Bearer token, so
 * the permission check runs as for a real request: only admins create and
 * change rules, a rule in use is not deleted (409 with used_by), and
 * job_template_id, which never gated a template, is refused.
 */
class ApprovalRulesControllerTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** One test swaps in a write service that refuses; restore the real one. */
    protected function tearDown(): void
    {
        \Yii::$app->set('approvalRuleWriteService', ['class' => ApprovalRuleWriteService::class]);
        parent::tearDown();
    }

    private const SERIALIZED_KEYS = [
        'id', 'name', 'description', 'required_approvals', 'timeout_minutes', 'timeout_action',
        'approver_type', 'approver_config', 'job_template_id', 'created_by', 'created_at', 'updated_at',
    ];

    // -- Who may do what ----------------------------------------------------------

    /**
     * Regression: an operator could change any rule's approvers, and so
     * neutralise every gate that used the rule.
     */
    public function testAnOperatorTokenCannotCreateChangeOrDeleteRules(): void
    {
        $operator = $this->authenticateWithRoles(['operator']);
        $rule = $this->ruleOf($operator);
        $before = ApprovalRule::find()->count();

        $this->setBody(['name' => 'operator-rule', 'approver_type' => 'users', 'approver_config' => ['user_ids' => [(int)$operator->id]]]);
        $this->assertForbidden($this->call('create'));
        $this->assertSame($before, ApprovalRule::find()->count());

        $this->setBody(['approver_config' => ['user_ids' => []], 'required_approvals' => 1]);
        $this->assertForbidden($this->call('update', ['id' => $rule->id]));
        $this->assertForbidden($this->call('delete', ['id' => $rule->id]));

        $stored = ApprovalRule::findOne($rule->id);
        $this->assertNotNull($stored);
        $this->assertSame(['user_ids' => [(int)$operator->id]], $stored->getParsedConfig(), 'the approvers stay');
    }

    public function testOperatorsAndViewersStillReadRules(): void
    {
        foreach (['operator', 'viewer'] as $role) {
            $user = $this->authenticateWithRoles([$role]);
            $rule = $this->ruleOf($user);

            $list = $this->call('index');
            $this->assertSame(200, \Yii::$app->response->statusCode, $role);
            $this->assertContains((int)$rule->id, array_column($this->listOf($list), 'id'), $role);
            $this->assertSame((int)$rule->id, $this->dataOf($this->call('view', ['id' => $rule->id]))['id'], $role);
        }
    }

    public function testAnAdminCreatesARuleAndGetsItAsStored(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $other = $this->createUser('rule-api-forged');
        $this->setBody([
            'name' => 'api-rule-' . uniqid(),
            'approver_type' => ApprovalRule::APPROVER_TYPE_USERS,
            'approver_config' => ['user_ids' => [(int)$admin->id]],
            'job_template_id' => null,
            'created_by' => (int)$other->id,
        ]);

        $data = $this->dataOf($this->call('create'));

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame(self::SERIALIZED_KEYS, array_keys($data));
        $this->assertSame(1, $data['required_approvals'], 'the database default, as stored');
        $this->assertSame(ApprovalRule::TIMEOUT_ACTION_REJECT, $data['timeout_action']);
        $this->assertNull($data['timeout_minutes']);
        $this->assertSame(['user_ids' => [(int)$admin->id]], $data['approver_config']);
        $this->assertNull($data['job_template_id']);
        $this->assertSame((int)$admin->id, $data['created_by'], 'the caller, not the body');
        $this->assertSame('api', $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_CREATED, (int)$data['id'])['source']);
    }

    public function testASuperadminWithoutRolesManagesRules(): void
    {
        $superadmin = $this->authenticateWithRoles([]);
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->setBody(['name' => 'superadmin-rule', 'approver_type' => 'role', 'approver_config' => ['role' => 'admin']]);

        $data = $this->dataOf($this->call('create'));

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->setBody(['required_approvals' => 2]);
        $this->assertSame(2, $this->dataOf($this->call('update', ['id' => $data['id']]))['required_approvals']);
        $this->call('delete', ['id' => $data['id']]);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertNull(ApprovalRule::findOne($data['id']));
    }

    public function testACustomRoleWithTheUpdatePermissionChangesButDoesNotCreate(): void
    {
        $manager = $this->authenticateWithRoles([$this->customRole(['approval-rule.view', 'approval-rule.update'])]);
        $rule = $this->ruleOf($manager);

        $this->setBody(['timeout_minutes' => 45, 'timeout_action' => ApprovalRule::TIMEOUT_ACTION_APPROVE]);
        $data = $this->dataOf($this->call('update', ['id' => $rule->id]));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(45, $data['timeout_minutes']);
        $this->assertSame(ApprovalRule::TIMEOUT_ACTION_APPROVE, $data['timeout_action']);
        $this->assertSame(
            ['timeout_minutes' => ['from' => null, 'to' => 45], 'timeout_action' => ['from' => 'reject', 'to' => 'approve']],
            $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_UPDATED, (int)$rule->id)['changes']
        );

        $this->setBody(['name' => 'not-allowed', 'approver_type' => 'role', 'approver_config' => ['role' => 'admin']]);
        $this->assertForbidden($this->call('create'));
    }

    // -- View ---------------------------------------------------------------------

    public function testViewReportsTheUsageTheCallerMaySee(): void
    {
        $s = $this->teamScope();
        $rule = $this->ruleOf($s['admin']);
        foreach (['own', 'foreign'] as $key) {
            $s[$key]->approval_rule_id = (int)$rule->id;
            $s[$key]->save(false);
        }
        $workflow = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['own']->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 5, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $this->pendingRequest($rule, $s['own'], $s['admin']);
        $this->authenticateAs($s['member']);

        $usage = $this->dataOf($this->call('view', ['id' => $rule->id]))['used_by'];

        $this->assertSame([
            'in_use' => true,
            'job_templates' => [[
                'id' => (int)$s['own']->id,
                'name' => (string)$s['own']->name,
                'project_id' => (int)$s['own']->project_id,
                'project_name' => $s['own']->project->name,
            ]],
            'hidden_job_template_count' => 1,
            'workflow_steps' => [[
                'workflow_template_id' => (int)$workflow->id,
                'workflow_template_name' => (string)$workflow->name,
                'step_id' => (int)$step->id,
                'step_name' => (string)$step->name,
            ]],
            'hidden_workflow_step_count' => 0,
            'pending_request_count' => 1,
        ], $usage);
    }

    public function testAMissingRuleIs404(): void
    {
        $this->authenticateWithRoles(['admin']);

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->call('view', ['id' => 987654321]);
    }

    // -- Delete -------------------------------------------------------------------

    /**
     * Regression: deleting a rule silently ungated its templates and deleted
     * its pending requests.
     */
    public function testDeletingARuleInUseIs409WithWhatUsesIt(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $rule = $this->ruleOf($admin);
        $template = $this->templateOf($admin);
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);
        $request = $this->pendingRequest($rule, $template, $admin);

        $result = $this->call('delete', ['id' => $rule->id]);

        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertIsArray($result);
        $error = $result['error'];
        $this->assertSame(
            'Approval rule ' . json_encode($rule->name) . ' is in use by 1 job template(s) and 1 pending approval request(s). '
            . 'Remove it from them, and let pending requests be decided or their jobs canceled, before deleting it.',
            $error['message']
        );
        $this->assertTrue($error['used_by']['in_use']);
        $this->assertSame([(int)$template->id], array_column($error['used_by']['job_templates'], 'id'));
        $this->assertSame(1, $error['used_by']['pending_request_count']);
        $this->assertNotNull(ApprovalRule::findOne($rule->id));
        $template->refresh();
        $this->assertSame((int)$rule->id, (int)$template->approval_rule_id, 'the template stays gated');
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
    }

    /**
     * Regression: deleting a rule deleted its pending requests (ON DELETE
     * CASCADE), and their jobs waited for a decision that could never come.
     */
    public function testARuleWithOnlyAPendingRequestIsNotDeleted(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $rule = $this->ruleOf($admin);
        $request = $this->pendingRequest($rule, $this->templateOf($admin), $admin);

        $result = $this->call('delete', ['id' => $rule->id]);

        $this->assertSame(409, \Yii::$app->response->statusCode);
        $this->assertIsArray($result);
        $this->assertSame(1, $result['error']['used_by']['pending_request_count']);
        $this->assertSame([], $result['error']['used_by']['job_templates']);
        $this->assertNotNull(ApprovalRule::findOne($rule->id));
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertSame((int)$rule->id, (int)$request->approval_rule_id);
    }

    public function testDeletingAnUnusedRuleWorksAndIsAudited(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $rule = $this->ruleOf($admin);

        $result = $this->call('delete', ['id' => $rule->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(['data' => ['deleted' => true]], $result);
        $this->assertNull(ApprovalRule::findOne($rule->id));
        $this->assertSame(ApprovalRuleWriteService::SOURCE_API, $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_DELETED, (int)$rule->id)['source']);
    }

    // -- Input --------------------------------------------------------------------

    public function testAJobTemplateIdIsRefusedAndNothingWritten(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $rule = $this->ruleOf($admin);
        $template = $this->templateOf($admin);
        $before = ApprovalRule::find()->count();

        $this->setBody(['name' => 'with-template', 'approver_type' => 'role', 'approver_config' => ['role' => 'admin'], 'job_template_id' => (int)$template->id]);
        $this->assertSame(['error' => ['message' => ApprovalRulesController::JOB_TEMPLATE_ID_REFUSED]], $this->call('create'));
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame($before, ApprovalRule::find()->count());

        $this->setBody(['name' => 'renamed', 'job_template_id' => (int)$template->id]);
        $this->assertSame(['error' => ['message' => ApprovalRulesController::JOB_TEMPLATE_ID_REFUSED]], $this->call('update', ['id' => $rule->id]));
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $rule->refresh();
        $this->assertNotSame('renamed', $rule->name);
        $this->assertNull($rule->job_template_id);

        $this->setBody(['name' => 'renamed', 'job_template_id' => null]);
        $this->assertSame('renamed', $this->dataOf($this->call('update', ['id' => $rule->id]))['name'], 'null is ignored');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidBodyProvider(): array
    {
        $valid = ['name' => 'api-invalid', 'approver_type' => 'role', 'approver_config' => ['role' => 'admin']];

        return [
            // Regression: the insert failed on the NOT NULL column with a server error.
            'no approver config' => [['name' => 'api-invalid', 'approver_type' => 'role'], 'Approver Config cannot be blank.'],
            'name is a list' => [['name' => ['x']] + $valid, 'Name must be a string.'],
            'approver type is an object' => [['approver_type' => ['role' => 'admin']] + $valid, 'Approver Type is invalid.'],
            'required approvals is text' => [['required_approvals' => 'two'] + $valid, 'Required Approvals must be an integer.'],
            'required approvals is a list' => [['required_approvals' => [2]] + $valid, 'Required Approvals must be an integer.'],
            'timeout is not a number' => [['timeout_minutes' => '1h'] + $valid, 'Timeout Minutes must be an integer.'],
            'approver config is a number' => [['approver_config' => 5] + $valid, 'Approver Config must be a string.'],
            'description is a list' => [['description' => ['x']] + $valid, 'Description must be a string.'],
            'unknown timeout action' => [['timeout_action' => 'ignore'] + $valid, 'Timeout Action is invalid.'],
            // The model rules the former ApprovalRulesApiTest checked on the
            // model alone, as the API answers them.
            'no name' => [['approver_type' => 'role', 'approver_config' => ['role' => 'admin']], 'Name cannot be blank.'],
            'no approver type' => [['name' => 'api-invalid', 'approver_config' => ['role' => 'admin']], 'Approver Type cannot be blank.'],
            'unknown approver type' => [['approver_type' => 'invalid'] + $valid, 'Approver Type is invalid.'],
            // Regression: the message named the column (Approver_config), not its label.
            'approver config is not JSON' => [['approver_config' => 'not-json'] + $valid, 'Approver Config must be valid JSON.'],
            'more required approvals than listed users' => [
                ['approver_type' => 'users', 'approver_config' => ['user_ids' => [1]], 'required_approvals' => 3] + $valid,
                'Required approvals (3) exceeds eligible approvers (1).',
            ],
        ];
    }

    /**
     * @dataProvider invalidBodyProvider
     * @param array<string, mixed> $body
     */
    public function testInvalidBodiesAre422(array $body, string $message): void
    {
        $this->authenticateWithRoles(['admin']);
        $before = ApprovalRule::find()->count();
        $this->setBody($body);

        $result = $this->call('create');

        $this->assertSame(['error' => ['message' => $message]], $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame($before, ApprovalRule::find()->count());
    }

    /**
     * As many required approvals as listed users is allowed: the count only
     * refuses more approvals than users.
     */
    public function testRequiredApprovalsEqualToTheListedUsersAreAccepted(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $userIds = [(int)$admin->id, (int)$this->createUser('rule-api-approver')->id, (int)$this->createUser('rule-api-approver')->id];
        $this->setBody([
            'name' => 'three-of-three',
            'approver_type' => ApprovalRule::APPROVER_TYPE_USERS,
            'approver_config' => ['user_ids' => $userIds],
            'required_approvals' => 3,
        ]);

        $data = $this->dataOf($this->call('create'));

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame(3, $data['required_approvals']);
        $this->assertSame(['user_ids' => $userIds], $data['approver_config']);
    }

    public function testAnInvalidUpdateIs422AndChangesNothing(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $rule = $this->ruleOf($admin);
        $this->setBody(['required_approvals' => 0, 'name' => 'not-saved']);

        $result = $this->call('update', ['id' => $rule->id]);

        $this->assertSame(['error' => ['message' => 'Required Approvals must be no less than 1.']], $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $rule->refresh();
        $this->assertNotSame('not-saved', $rule->name);
    }

    public function testARejectedWriteWithoutAMessageIsAGeneric422(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $rule = $this->ruleOf($admin);
        \Yii::$app->set('approvalRuleWriteService', new class extends ApprovalRuleWriteService {
            public function update(ApprovalRule $rule, string $source): bool
            {
                return false;
            }
        });
        $this->setBody(['description' => 'refused']);

        $result = $this->call('update', ['id' => $rule->id]);

        $this->assertSame(['error' => ['message' => 'Validation failed.']], $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
    }

    public function testScalarsAreTakenAsText(): void
    {
        $this->authenticateWithRoles(['admin']);
        $this->setBody(['name' => 4711, 'description' => null, 'approver_type' => 'role', 'approver_config' => '{"role":"admin"}', 'required_approvals' => '2']);

        $data = $this->dataOf($this->call('create'));

        $this->assertSame('4711', $data['name']);
        $this->assertNull($data['description']);
        $this->assertSame(2, $data['required_approvals']);
        $this->assertSame(['role' => 'admin'], $data['approver_config']);
    }

    // -- approver_config is a JSON object -------------------------------------------

    /**
     * Regression: approver_config of a rule without approvers was returned
     * as a JSON list. A role or team rule saved without a choice stores {}
     * (as the web form does), and the API returned [] for it, although the
     * spec documents an object: a client generated from the spec failed to
     * read the rule, and the whole list with it.
     */
    public function testRegressionApproverConfigOfARuleWithoutApproversWasReturnedAsAJsonList(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $rule = $this->createApprovalRule((int)$admin->id, ApprovalRule::APPROVER_TYPE_ROLE, '{}');

        $responses = [
            'view' => $this->dataOf($this->call('view', ['id' => $rule->id])),
            'index' => $this->itemOf($this->call('index'), (int)$rule->id),
        ];

        foreach ($responses as $action => $data) {
            $this->assertSame('{}', json_encode($data['approver_config']), $action);
            $this->assertSame('{}', Json::encode($data['approver_config']), "{$action}, as the response formatter encodes it");
        }
    }

    /**
     * {} in a body arrives as an empty array; it is stored as {} and
     * returned as an object by create and update alike.
     */
    public function testAnEmptyApproverConfigIsStoredAndReturnedAsAnObject(): void
    {
        $this->authenticateWithRoles(['admin']);
        $this->setBody(['name' => 'role-without-choice', 'approver_type' => ApprovalRule::APPROVER_TYPE_ROLE, 'approver_config' => []]);

        $created = $this->dataOf($this->call('create'));

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame('{}', Json::encode($created['approver_config']));
        $this->assertSame('{}', $this->storedConfigOf((int)$created['id']));

        $this->setBody(['approver_config' => ['role' => 'admin']]);
        $updated = $this->dataOf($this->call('update', ['id' => $created['id']]));
        $this->assertSame(['role' => 'admin'], $updated['approver_config']);

        $this->setBody(['approver_config' => []]);
        $cleared = $this->dataOf($this->call('update', ['id' => $created['id']]));
        $this->assertSame('{}', Json::encode($cleared['approver_config']));
        $this->assertSame('{}', $this->storedConfigOf((int)$created['id']));
    }

    /**
     * A list that an older version stored names no approvers; it is
     * returned as {}, as the spec documents.
     */
    public function testAListStoredByAnOlderVersionIsReturnedAsAnEmptyObject(): void
    {
        $admin = $this->authenticateWithRoles(['admin']);
        $rule = $this->createApprovalRule((int)$admin->id, ApprovalRule::APPROVER_TYPE_ROLE, '[7, 12]');

        $data = $this->dataOf($this->call('view', ['id' => $rule->id]));

        $this->assertSame('{}', Json::encode($data['approver_config']));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function configThatIsNoObjectProvider(): array
    {
        return [
            'a list' => [[7, 12]],
            'a list as text' => ['[7, 12]'],
            'a number as text' => ['7'],
            'null as text' => ['null'],
            'a string as JSON' => ['"operator"'],
        ];
    }

    /**
     * Regression: an approver config that is JSON but no object was stored
     * (and named no approvers).
     *
     * @dataProvider configThatIsNoObjectProvider
     */
    public function testAnApproverConfigThatIsNoJsonObjectIs422(mixed $config): void
    {
        $this->authenticateWithRoles(['admin']);
        $before = ApprovalRule::find()->count();
        $this->setBody(['name' => 'not-an-object', 'approver_type' => ApprovalRule::APPROVER_TYPE_ROLE, 'approver_config' => $config]);

        $result = $this->call('create');

        $this->assertSame(['error' => ['message' => 'Approver Config must be a JSON object.']], $result);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame($before, ApprovalRule::find()->count());
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * The item with $id from a list response.
     *
     * @return array<string, mixed>
     */
    private function itemOf(mixed $result, int $id): array
    {
        foreach ($this->listOf($result) as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }
        $this->fail("Rule #{$id} is missing from the list.");
    }

    private function storedConfigOf(int $ruleId): string
    {
        $rule = ApprovalRule::findOne($ruleId);
        $this->assertNotNull($rule);

        return (string)$rule->approver_config;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function call(string $action, array $params = []): mixed
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return (new ApprovalRulesController('api/v1/approval-rules', $app))->runAction($action, $params);
    }

    /**
     * @param list<string> $roles
     */
    private function authenticateWithRoles(array $roles): User
    {
        $user = $this->createUser('rule-api');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        foreach ($roles as $roleName) {
            $role = $auth->getRole($roleName);
            $this->assertNotNull($role, $roleName);
            $auth->assign($role, (string)$user->id);
        }
        $this->authenticateAs($user);

        return $user;
    }

    private function authenticateAs(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'rule-api-token');
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
     * @param list<string> $permissions
     */
    private function customRole(array $permissions): string
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('rule-api-role-' . uniqid());
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }

        return $role->name;
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

    private function assertForbidden(mixed $result): void
    {
        $this->assertNull($result, 'the action must not run');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], \Yii::$app->response->data);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataOf(mixed $result): array
    {
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $result): array
    {
        $this->assertIsArray($result);
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    private function ruleOf(User $creator): ApprovalRule
    {
        $rule = $this->createApprovalRule((int)$creator->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [(int)$creator->id]]));
        $stored = ApprovalRule::findOne($rule->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    private function templateOf(User $owner): JobTemplate
    {
        $userId = (int)$owner->id;

        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    private function pendingRequest(ApprovalRule $rule, JobTemplate $template, User $launcher): ApprovalRequest
    {
        $job = $this->createJob((int)$template->id, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->save(false);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditOf(string $action, int $ruleId): array
    {
        /** @var AuditLog|null $entry */
        $entry = AuditLog::find()
            ->where(['action' => $action, 'object_type' => 'approval_rule', 'object_id' => $ruleId])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($entry);
        $metadata = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($metadata);

        return $metadata;
    }
}
