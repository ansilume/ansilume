<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\VaultAssignmentResult;
use app\controllers\api\v1\CredentialAssignmentsController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Credential;
use app\models\JobTemplate;
use app\models\TeamProject;
use app\models\User;
use app\services\CredentialWriteService;
use app\services\VaultCredentialAssignmentService;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * REST API for assigning one vault password to several job templates:
 * GET and POST /api/v1/credentials/{id}/job-templates. Both need
 * job-template.update (the gate) and credential.view (checked in the
 * action). A request that fails the pre-checks changes nothing.
 */
class CredentialAssignmentsControllerTest extends WebControllerTestCase
{
    private const NOT_A_VAULT = 'Only vault passwords can be assigned to several job templates at once.';
    private const NOT_A_LIST = 'job_template_ids must be a non-empty list of job template IDs.';

    private CredentialAssignmentsController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = $this->controller();
    }

    // -- GET: candidates ------------------------------------------------------

    public function testIndexListsTheTemplatesTheCallerMayChangeWithTheirVaultPasswords(): void
    {
        $operator = $this->authenticateWithRoles(['operator']);
        $userId = (int)$operator->id;
        $vault = $this->storedVault($userId, 'index-secret-value');
        $first = $this->storedVault($userId);
        $second = $this->storedVault($userId);
        $without = $this->template($userId);
        $withThis = $this->template($userId);
        $this->setPrimary($withThis, $vault);
        // Saved before a template was limited to one vault password.
        $withTwo = $this->template($userId);
        $this->setPrimary($withTwo, $first);
        $this->attach($withTwo, $second, 1);
        $viewOnly = $this->teamTemplate($operator, TeamProject::ROLE_VIEWER);
        $foreign = $this->foreignTemplate();

        $result = $this->ctrl->actionIndex((int)$vault->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $rows = $this->rowsById($this->dataOf($result));
        $this->assertSame([
            'id' => (int)$without->id,
            'name' => $without->name,
            'project_id' => (int)$without->project_id,
            'project_name' => $without->project->name,
            'current' => VaultCredentialAssignmentService::STATE_NONE,
            'vaults' => [],
        ], $rows[(int)$without->id]);
        $this->assertSame(VaultCredentialAssignmentService::STATE_THIS, $rows[(int)$withThis->id]['current']);
        $this->assertSame([['id' => (int)$vault->id, 'name' => $vault->name]], $rows[(int)$withThis->id]['vaults']);
        $this->assertSame(VaultCredentialAssignmentService::STATE_MULTIPLE, $rows[(int)$withTwo->id]['current']);
        $this->assertSame(
            [['id' => (int)$first->id, 'name' => $first->name], ['id' => (int)$second->id, 'name' => $second->name]],
            $rows[(int)$withTwo->id]['vaults']
        );
        $this->assertArrayNotHasKey((int)$viewOnly->id, $rows, 'templates the caller may only view are not offered');
        $this->assertArrayNotHasKey((int)$foreign->id, $rows, 'other teams\' templates are not listed');
        $this->assertStringNotContainsString('index-secret-value', (string)json_encode($result));
    }

    public function testIndexOfANonVaultCredentialIs422(): void
    {
        $userId = (int)$this->authenticateWithRoles(['operator'])->id;
        $token = $this->storedCredential($userId, Credential::TYPE_TOKEN, ['token' => 'tok-not-a-vault']);

        $result = $this->ctrl->actionIndex((int)$token->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::NOT_A_VAULT]], $result);
    }

    public function testIndexOfAMissingCredentialIs404(): void
    {
        $this->authenticateWithRoles(['operator']);
        $missingId = $this->missingCredentialId();

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage("Credential #{$missingId} not found.");
        $this->ctrl->actionIndex($missingId);
    }

    // -- POST: assign ---------------------------------------------------------

    public function testCreateAssignsTheVaultAndReportsEveryTemplate(): void
    {
        $userId = (int)$this->authenticateWithRoles(['operator'])->id;
        $vault = $this->storedVault($userId);
        $other = $this->storedVault($userId);
        $without = $this->template($userId);
        $withOther = $this->template($userId);
        $this->setPrimary($withOther, $other);
        $withThis = $this->template($userId);
        $this->attach($withThis, $vault, 0);
        // Request order is kept and a repeated id counts once.
        $this->setBody(['job_template_ids' => [(int)$withOther->id, (int)$without->id, (int)$withThis->id, (int)$without->id]]);

        $result = $this->ctrl->actionCreate((int)$vault->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $data = $this->dataOf($result);
        $this->assertSame(['credential_id', 'credential_name', 'results', 'summary'], array_keys($data));
        $this->assertSame((int)$vault->id, $data['credential_id']);
        $this->assertSame($vault->name, $data['credential_name']);
        $this->assertSame($this->sortedKeys([
            $this->item($withOther, VaultAssignmentResult::REPLACED, [['id' => (int)$other->id, 'name' => $other->name]]),
            $this->item($without, VaultAssignmentResult::ASSIGNED),
            $this->item($withThis, VaultAssignmentResult::UNCHANGED),
        ]), $this->sortedKeys($data['results']));
        $this->assertSame(['assigned' => 1, 'replaced' => 1, 'unchanged' => 1, 'failed' => 0], $data['summary']);

        $this->assertSame([(int)$vault->id], $this->credentialIds($without));
        $this->assertSame([(int)$vault->id], $this->credentialIds($withOther));
        $this->assertSame((int)$vault->id, (int)$this->reload($withOther)->credential_id, 'the vault password takes over the primary slot');
        $this->assertSame([(int)$vault->id], $this->credentialIds($withThis));
        $audits = $this->templateAudits((int)$withOther->id);
        $this->assertCount(1, $audits);
        $this->assertSame('api', $audits[0]['source']);
        $this->assertSame(['credential_id' => (int)$vault->id, 'replaced' => [(int)$other->id]], $audits[0]['vault_assignment']);
        $this->assertSame('api', $this->templateAudits((int)$without->id)[0]['source']);
        $this->assertSame([], $this->templateAudits((int)$withThis->id), 'an unchanged template is not saved');
    }

    public function testATemplateThatFailsValidationIsReportedAndTheOthersAreStillAssigned(): void
    {
        $userId = (int)$this->authenticateWithRoles(['operator'])->id;
        $vault = $this->storedVault($userId);
        // Stored with a value the API rejects, so saving it fails validation.
        $invalid = $this->template($userId);
        $invalid->forks = 500;
        $invalid->save(false);
        $valid = $this->template($userId);
        $this->setBody(['job_template_ids' => [(int)$invalid->id, (int)$valid->id]]);

        $data = $this->dataOf($this->ctrl->actionCreate((int)$vault->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame($this->sortedKeys([
            $this->item($invalid, VaultAssignmentResult::FAILED, [], 'Forks must be no greater than 200.'),
            $this->item($valid, VaultAssignmentResult::ASSIGNED),
        ]), $this->sortedKeys($data['results']));
        $this->assertSame(['assigned' => 1, 'replaced' => 0, 'unchanged' => 0, 'failed' => 1], $data['summary']);
        $this->assertSame([], $this->credentialIds($invalid));
        $this->assertSame([(int)$vault->id], $this->credentialIds($valid));
    }

    public function testCreateWithANonVaultCredentialIs422AndChangesNothing(): void
    {
        $userId = (int)$this->authenticateWithRoles(['operator'])->id;
        $token = $this->storedCredential($userId, Credential::TYPE_TOKEN, ['token' => 'tok-not-a-vault']);
        $template = $this->template($userId);
        $this->setBody(['job_template_ids' => [(int)$template->id]]);

        $result = $this->ctrl->actionCreate((int)$token->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::NOT_A_VAULT]], $result);
        $this->assertSame([], $this->credentialIds($template));
    }

    public function testCreateOnAMissingCredentialIs404(): void
    {
        $this->authenticateWithRoles(['operator']);
        $missingId = $this->missingCredentialId();
        $this->setBody(['job_template_ids' => [1]]);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage("Credential #{$missingId} not found.");
        $this->ctrl->actionCreate($missingId);
    }

    public function testAVaultWithoutAUsableSecretIs422AndChangesNothing(): void
    {
        $userId = (int)$this->authenticateWithRoles(['operator'])->id;
        // Stored before secrets were required.
        $vault = $this->createCredential($userId, Credential::TYPE_VAULT);
        $template = $this->template($userId);
        $this->setBody(['job_template_ids' => [(int)$template->id]]);

        $result = $this->ctrl->actionCreate((int)$vault->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => 'The vault password has no usable secret, so jobs would fail. Enter it on the credential page first.']],
            $result
        );
        $this->assertSame([], $this->credentialIds($template));
    }

    /**
     * Each body is built around the id of a template the caller may change,
     * so a rejection is about the shape of job_template_ids alone.
     *
     * @return array<string, array{0: \Closure(int): array<string, mixed>}>
     */
    public static function invalidJobTemplateIdsProvider(): array
    {
        return [
            'missing' => [static fn (int $id): array => ['ids' => [$id]]],
            'null' => [static fn (int $id): array => ['job_template_ids' => null]],
            'a single id' => [static fn (int $id): array => ['job_template_ids' => $id]],
            'an id as a string' => [static fn (int $id): array => ['job_template_ids' => (string)$id]],
            'an empty list' => [static fn (int $id): array => ['job_template_ids' => []]],
            'an object' => [static fn (int $id): array => ['job_template_ids' => ['template' => $id]]],
            'a nested list' => [static fn (int $id): array => ['job_template_ids' => [[$id]]]],
            'zero next to a valid id' => [static fn (int $id): array => ['job_template_ids' => [$id, 0]]],
            'a negative id' => [static fn (int $id): array => ['job_template_ids' => [$id, -$id]]],
            'a name' => [static fn (int $id): array => ['job_template_ids' => [$id, 'deploy']]],
            'a fraction' => [static fn (int $id): array => ['job_template_ids' => [$id + 0.5]]],
            'a null entry' => [static fn (int $id): array => ['job_template_ids' => [$id, null]]],
        ];
    }

    /**
     * @dataProvider invalidJobTemplateIdsProvider
     * @param \Closure(int): array<string, mixed> $body
     */
    public function testJobTemplateIdsMustBeANonEmptyListOfIds(\Closure $body): void
    {
        $userId = (int)$this->authenticateWithRoles(['operator'])->id;
        $vault = $this->storedVault($userId);
        $template = $this->template($userId);
        $this->setBody($body((int)$template->id));

        $result = $this->ctrl->actionCreate((int)$vault->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::NOT_A_LIST]], $result);
        $this->assertSame([], $this->credentialIds($template));
    }

    public function testMoreThanTheMaximumNumberOfTemplatesIs422(): void
    {
        $vault = $this->storedVault((int)$this->authenticateWithRoles(['operator'])->id);
        $this->setBody(['job_template_ids' => range(1, VaultCredentialAssignmentService::MAX_TEMPLATES + 1)]);

        $result = $this->ctrl->actionCreate((int)$vault->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'At most 500 job templates can be assigned at once.']], $result);
    }

    public function testUnknownTemplateIdsAre422AndNameTheIds(): void
    {
        $userId = (int)$this->authenticateWithRoles(['operator'])->id;
        $vault = $this->storedVault($userId);
        $template = $this->template($userId);
        $missingId = (int)JobTemplate::findWithDeleted()->max('id') + 1000;
        $this->setBody(['job_template_ids' => [(int)$template->id, $missingId]]);

        $result = $this->ctrl->actionCreate((int)$vault->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => "Job template(s) not found: #{$missingId}.", 'job_template_ids' => [$missingId]]],
            $result
        );
        $this->assertSame([], $this->credentialIds($template), 'nothing is written when one id is unknown');
    }

    /**
     * Another team's template is reported like an unknown id, so the answer
     * does not reveal that it exists.
     */
    public function testAnotherTeamsTemplateIsReportedAsNotFound(): void
    {
        $userId = (int)$this->authenticateWithRoles(['operator'])->id;
        $vault = $this->storedVault($userId);
        $open = $this->template($userId);
        $foreign = $this->foreignTemplate();
        $this->setBody(['job_template_ids' => [(int)$open->id, (int)$foreign->id]]);

        $result = $this->ctrl->actionCreate((int)$vault->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => "Job template(s) not found: #{$foreign->id}.", 'job_template_ids' => [(int)$foreign->id]]],
            $result
        );
        $this->assertSame([], $this->credentialIds($open));
        $this->assertSame([], $this->credentialIds($foreign));
    }

    public function testTemplatesTheCallerMayOnlyViewAre403AndNameTheIds(): void
    {
        $operator = $this->authenticateWithRoles(['operator']);
        $userId = (int)$operator->id;
        $vault = $this->storedVault($userId);
        $open = $this->template($userId);
        $viewOnly = $this->teamTemplate($operator, TeamProject::ROLE_VIEWER);
        $this->setBody(['job_template_ids' => [(int)$open->id, (int)$viewOnly->id]]);

        $result = $this->ctrl->actionCreate((int)$vault->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => "You may not change job template(s) #{$viewOnly->id}.", 'job_template_ids' => [(int)$viewOnly->id]]],
            $result
        );
        $this->assertSame([], $this->credentialIds($open), 'nothing is written when one template is not operable');
        $this->assertSame([], $this->credentialIds($viewOnly));
    }

    // -- Authorization through runAction --------------------------------------

    public function testAViewerTokenIsStoppedByTheGateAndChangesNothing(): void
    {
        $userId = (int)$this->authenticateWithRoles(['viewer'])->id;
        $vault = $this->storedVault($userId);
        $template = $this->template($userId);
        $this->setBody(['job_template_ids' => [(int)$template->id]]);

        $this->assertForbiddenByTheGate($this->controller()->runAction('create', ['id' => $vault->id]));
        $this->assertSame([], $this->credentialIds($template));
        $this->assertSame([], $this->templateAudits((int)$template->id));

        $this->freshResponse();
        $this->assertForbiddenByTheGate($this->controller()->runAction('index', ['id' => $vault->id]));
    }

    /**
     * job-template.update passes the gate, credential.view is checked in the
     * action: there the action runs and answers 403 itself.
     */
    public function testAnEditorWithoutCredentialViewIsStoppedByTheActionAndChangesNothing(): void
    {
        $userId = (int)$this->authenticateAsTemplateEditor()->id;
        $vault = $this->storedVault($userId);
        $template = $this->template($userId);
        $this->setBody(['job_template_ids' => [(int)$template->id]]);

        $this->assertForbiddenByTheAction($this->controller()->runAction('create', ['id' => $vault->id]));
        $this->assertSame([], $this->credentialIds($template));

        $this->freshResponse();
        $this->assertForbiddenByTheAction($this->controller()->runAction('index', ['id' => $vault->id]));

        // The same answer for a credential that does not exist: ids cannot be probed.
        $this->freshResponse();
        $this->assertForbiddenByTheAction($this->controller()->runAction('create', ['id' => $this->missingCredentialId()]));
    }

    public function testAnOperatorTokenAssignsThroughTheGate(): void
    {
        $userId = (int)$this->authenticateWithRoles(['operator'])->id;
        $vault = $this->storedVault($userId);
        $template = $this->template($userId);
        $this->setBody(['job_template_ids' => [(int)$template->id]]);

        $result = $this->controller()->runAction('create', ['id' => $vault->id]);

        $this->assertIsArray($result);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(['assigned' => 1, 'replaced' => 0, 'unchanged' => 0, 'failed' => 0], $this->dataOf($result)['summary']);
        $this->assertSame([(int)$vault->id], $this->credentialIds($template));
    }

    /**
     * Superadmins hold every permission, as BaseApiController::userCan()
     * promises: both the gate and the credential.view check let them through.
     */
    public function testASuperadminWithoutRolesMayListAndAssign(): void
    {
        $admin = $this->createUser('vault-assign-superadmin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->signIn($admin);
        $vault = $this->storedVault((int)$admin->id);
        $template = $this->template((int)$admin->id);

        $listed = $this->controller()->runAction('index', ['id' => $vault->id]);
        $this->assertArrayHasKey((int)$template->id, $this->rowsById($this->dataOf($listed)));

        $this->setBody(['job_template_ids' => [(int)$template->id]]);
        $result = $this->controller()->runAction('create', ['id' => $vault->id]);
        $this->assertSame(1, $this->dataOf($result)['summary']['assigned']);
        $this->assertSame([(int)$vault->id], $this->credentialIds($template));
    }

    public function testARequestWithoutATokenIsRejected(): void
    {
        $owner = $this->createUser('vault-assign-owner');
        $vault = $this->storedVault((int)$owner->id);

        $this->expectException(UnauthorizedHttpException::class);
        $this->controller()->runAction('index', ['id' => $vault->id]);
    }

    // -- Routing --------------------------------------------------------------

    public function testTheUrlRulesRouteGetAndPostToTheAssignmentActions(): void
    {
        $config = require \Yii::getAlias('@app') . '/config/web.php';
        $manager = new \yii\web\UrlManager([
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => false,
            'rules' => $config['components']['urlManager']['rules'],
            'cache' => false,
            'baseUrl' => '',
            'scriptUrl' => '/index.php',
        ]);
        $path = 'api/v1/credentials/42/job-templates';

        $this->assertSame(['api/v1/credential-assignments/index', ['id' => '42']], $manager->parseRequest($this->request('GET', $path)));
        $this->assertSame(['api/v1/credential-assignments/create', ['id' => '42']], $manager->parseRequest($this->request('POST', $path)));
        foreach (['PUT', 'PATCH', 'DELETE'] as $verb) {
            $parsed = $manager->parseRequest($this->request($verb, $path));
            $this->assertIsArray($parsed);
            $this->assertStringStartsNotWith('api/v1/credential-assignments/', $parsed[0], $verb);
        }
        $nonNumeric = $manager->parseRequest($this->request('GET', 'api/v1/credentials/abc/job-templates'));
        $this->assertIsArray($nonNumeric);
        $this->assertStringStartsNotWith('api/v1/credential-assignments/', $nonNumeric[0]);
        // The credential routes next to the new ones still work.
        $this->assertSame(['api/v1/credentials/view', ['id' => '42']], $manager->parseRequest($this->request('GET', 'api/v1/credentials/42')));
        $this->assertSame('/' . $path, $manager->createUrl(['api/v1/credential-assignments/index', 'id' => 42]));

        $this->assertSame(CredentialAssignmentsController::class, $config['controllerMap']['api/v1/credential-assignments']);
        $controller = \Yii::createObject($config['controllerMap']['api/v1/credential-assignments'], ['api/v1/credential-assignments', \Yii::$app]);
        $this->assertInstanceOf(CredentialAssignmentsController::class, $controller);
        foreach (['index' => 'actionIndex', 'create' => 'actionCreate'] as $actionId => $method) {
            $action = $controller->createAction($actionId);
            $this->assertInstanceOf(\yii\base\InlineAction::class, $action, $actionId);
            $this->assertSame($method, $action->actionMethod);
        }
    }

    // -- Helpers --------------------------------------------------------------

    private function controller(): CredentialAssignmentsController
    {
        return new CredentialAssignmentsController('api/v1/credential-assignments', \Yii::$app);
    }

    /**
     * @param list<string> $roles
     */
    private function authenticateWithRoles(array $roles): User
    {
        $user = $this->createUser('vault-assign-api');
        $auth = $this->authManager();
        foreach ($roles as $roleName) {
            $role = $auth->getRole($roleName);
            $this->assertNotNull($role, $roleName);
            $auth->assign($role, (string)$user->id);
        }

        return $this->signIn($user);
    }

    /**
     * A token whose user may change job templates but not see credentials:
     * a custom role that holds job-template.update only.
     */
    private function authenticateAsTemplateEditor(): User
    {
        $user = $this->createUser('vault-assign-api-editor');
        $auth = $this->authManager();
        $role = $auth->createRole('vault-assign-api-editor-' . uniqid());
        $auth->add($role);
        $permission = $auth->getPermission('job-template.update');
        $this->assertNotNull($permission);
        $auth->addChild($role, $permission);
        $auth->assign($role, (string)$user->id);

        return $this->signIn($user);
    }

    private function signIn(User $user): User
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'vault-assign-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);

        return $user;
    }

    private function authManager(): \yii\rbac\ManagerInterface
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);

        return $auth;
    }

    private function storedVault(int $userId, ?string $secret = null): Credential
    {
        return $this->storedCredential($userId, Credential::TYPE_VAULT, ['vault_password' => $secret ?? 'vault-secret-' . uniqid()]);
    }

    /**
     * @param array<string, string> $secrets
     */
    private function storedCredential(int $userId, string $type, array $secrets): Credential
    {
        $credential = new Credential();
        $credential->name = 'vault-assign-api-' . $type . '-' . uniqid('', true);
        $credential->credential_type = $type;
        $credential->created_by = $userId;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($credential, $secrets), (string)json_encode($credential->errors));

        return $credential;
    }

    private function template(int $userId, ?int $projectId = null): JobTemplate
    {
        return $this->createJobTemplate(
            $projectId ?? (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    /**
     * A template in a project restricted to a team of $member with $role.
     */
    private function teamTemplate(User $member, string $role): JobTemplate
    {
        $userId = (int)$member->id;
        $project = $this->createProject($userId);
        $team = $this->createTeam($userId);
        $this->addTeamMember((int)$team->id, $userId);
        $this->createTeamProject((int)$team->id, (int)$project->id, $role);

        return $this->template($userId, (int)$project->id);
    }

    /**
     * A template in a project restricted to a team the caller is not in.
     */
    private function foreignTemplate(): JobTemplate
    {
        $ownerId = (int)$this->createUser('vault-assign-api-owner')->id;
        $project = $this->createProject($ownerId);
        $team = $this->createTeam($ownerId);
        $this->addTeamMember((int)$team->id, $ownerId);
        $this->createTeamProject((int)$team->id, (int)$project->id);

        return $this->template($ownerId, (int)$project->id);
    }

    private function setPrimary(JobTemplate $template, Credential $credential): void
    {
        $template->credential_id = $credential->id;
        $template->save(false);
    }

    private function attach(JobTemplate $template, Credential $credential, int $sortOrder): void
    {
        \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
            'job_template_id' => $template->id,
            'credential_id' => $credential->id,
            'sort_order' => $sortOrder,
        ])->execute();
    }

    private function reload(JobTemplate $template): JobTemplate
    {
        $fresh = JobTemplate::findOne($template->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    /**
     * The template's credential ids as stored now, primary first.
     *
     * @return list<int>
     */
    private function credentialIds(JobTemplate $template): array
    {
        return array_column($this->reload($template)->credentialSnapshot(), 'id');
    }

    /**
     * @param list<array{id: int, name: string}> $replaced
     * @return array<string, mixed>
     */
    private function item(JobTemplate $template, string $status, array $replaced = [], ?string $error = null): array
    {
        return ['job_template_id' => (int)$template->id, 'name' => $template->name, 'status' => $status, 'replaced' => $replaced, 'error' => $error];
    }

    /**
     * The result items with their keys sorted, so items compare strictly
     * whatever order the keys were added in.
     *
     * @param mixed $items
     * @return list<array<string, mixed>>
     */
    private function sortedKeys($items): array
    {
        $this->assertIsArray($items);
        $sorted = [];
        foreach ($items as $item) {
            $this->assertIsArray($item);
            ksort($item);
            $sorted[] = $item;
        }

        return $sorted;
    }

    /**
     * @param mixed $rows
     * @return array<int, array<string, mixed>>
     */
    private function rowsById($rows): array
    {
        $this->assertIsArray($rows);
        $byId = [];
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $byId[(int)$row['id']] = $row;
        }

        return $byId;
    }

    /**
     * Metadata of the job-template.updated audit entries of a template, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function templateAudits(int $templateId): array
    {
        $logs = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_TEMPLATE_UPDATED, 'object_type' => 'job_template', 'object_id' => $templateId])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        $entries = [];
        foreach ($logs as $log) {
            $meta = json_decode((string)$log->metadata, true);
            $this->assertIsArray($meta);
            $entries[] = $meta;
        }

        return $entries;
    }

    private function missingCredentialId(): int
    {
        return (int)Credential::find()->max('id') + 1000;
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

    private function request(string $verb, string $pathInfo): \yii\web\Request
    {
        $request = new \yii\web\Request(['enableCsrfValidation' => false, 'cookieValidationKey' => 'test-key']);
        $request->setPathInfo($pathInfo);
        $request->headers->set('X-Http-Method-Override', $verb);

        return $request;
    }

    /**
     * Forget the status and body of the previous call within one test.
     */
    private function freshResponse(): void
    {
        \Yii::$app->set('response', new \yii\web\Response());
    }

    /**
     * @param mixed $result
     * @return array<int|string, mixed>
     */
    private function dataOf($result): array
    {
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    /**
     * The gate refuses before the action runs: runAction() returns nothing
     * and the gate writes the answer to the response.
     */
    private function assertForbiddenByTheGate(mixed $result): void
    {
        $this->assertNull($result, 'the action must not run');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], \Yii::$app->response->data);
    }

    /**
     * The action ran and refused itself: runAction() returns its answer.
     */
    private function assertForbiddenByTheAction(mixed $result): void
    {
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result);
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }
}
