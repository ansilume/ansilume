<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApiToken;
use app\models\JobTemplate;
use app\models\TeamProject;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\TeamScopeFixtures;

/**
 * Whom a workflow's inbound trigger runs as, in the API: has_trigger_token
 * and trigger_user_id.
 *
 * Regression: a workflow's inbound trigger runs as the user who generated
 * its token (the workflow's creator for older tokens), but the API did not
 * say whether a token exists or whom it runs as, so API-only operators
 * could not tell why a trigger was refused.
 *
 * Regression: the API then returned both fields to every caller who may view
 * the workflow, viewers and team viewers included, while the workflow page
 * shows the trigger card only to users who may change the workflow
 * (workflow-template.update and operator access to the project of every job
 * step).
 */
class WorkflowTemplatesControllerTriggerTest extends WebControllerTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    private WorkflowTemplatesController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app);
    }

    /**
     * Callers who may change the workflow: the RBAC role of teamScope()'s
     * member, the caller, and the teamScope() templates of the workflow's job
     * steps (none: a pause step only), in every trigger token state.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: string}>
     */
    public static function mayChangeProvider(): array
    {
        return self::inEveryTokenState([
            'an admin, another team\'s template' => ['operator', 'admin', ['foreign']],
            'a team operator' => ['operator', 'member', ['own']],
            'an operator, a template of no team' => ['operator', 'member', ['open']],
            'an operator, no job step' => ['operator', 'member', []],
        ]);
    }

    /**
     * Callers who may view the workflow but not change it, as in
     * mayChangeProvider().
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: string}>
     */
    public static function mayOnlyViewProvider(): array
    {
        return self::inEveryTokenState([
            'a team viewer with the operator role' => ['operator', 'member', ['viewed']],
            'a team viewer of one step' => ['operator', 'member', ['own', 'viewed']],
            'a viewer, a template of no team' => ['viewer', 'member', ['open']],
            'a viewer whose team operates the project' => ['viewer', 'member', ['own']],
        ]);
    }

    /**
     * @dataProvider mayChangeProvider
     * @param list<string> $stepTemplates
     */
    public function testViewAndIndexSayWhomTheTriggerRunsAsToCallersWhoMayChangeTheWorkflow(
        string $memberRole,
        string $caller,
        array $stepTemplates,
        string $state
    ): void {
        $s = $this->teamScope($memberRole);
        $creator = $this->createUser('wt_api_creator');
        $workflow = $this->workflowOf($s, $creator, $stepTemplates);
        $runsAs = $this->giveTriggerToken($workflow, $state);
        $this->authenticate($s[$caller]);

        foreach ($this->viewedAndListed($workflow) as $action => $item) {
            $this->assertSame($creator->id, $item['created_by'], $action);
            $this->assertArrayHasKey('has_trigger_token', $item, $action);
            $this->assertArrayHasKey('trigger_user_id', $item, $action);
            $this->assertSame($runsAs !== null, $item['has_trigger_token'], $action);
            $this->assertSame($runsAs, $item['trigger_user_id'], $action);
        }
    }

    /**
     * Regression: viewers and team viewers learned from the API whether a
     * workflow has a trigger and whom it runs as.
     *
     * @dataProvider mayOnlyViewProvider
     * @param list<string> $stepTemplates
     */
    public function testViewAndIndexSayNothingAboutTheTriggerToCallersWhoMayOnlyViewTheWorkflow(
        string $memberRole,
        string $caller,
        array $stepTemplates,
        string $state
    ): void {
        $s = $this->teamScope($memberRole);
        $workflow = $this->workflowOf($s, $this->createUser('wt_api_creator'), $stepTemplates);
        $this->giveTriggerToken($workflow, $state);
        $this->authenticate($s[$caller]);

        foreach ($this->viewedAndListed($workflow) as $action => $item) {
            $this->assertSame($workflow->id, $item['id'], $action);
            $this->assertArrayNotHasKey('has_trigger_token', $item, $action);
            $this->assertArrayNotHasKey('trigger_user_id', $item, $action);
        }
    }

    /**
     * The list decides for all its workflows at once: more workflows, on job
     * templates of more projects, cost no more queries.
     */
    public function testTheListDecidesWhoMayChangeItsWorkflowsWithAFixedNumberOfQueries(): void
    {
        $s = $this->teamScope();
        $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->authenticate($s['member']);
        // The first call loads the table schemas.
        $fewRows = count($this->ctrl->actionIndex()['data']);
        $few = $this->queriesOf(fn () => $this->ctrl->actionIndex());

        $operated = $this->workflowsInNewProjects($s, TeamProject::ROLE_OPERATOR, 3);
        $viewed = $this->workflowsInNewProjects($s, TeamProject::ROLE_VIEWER, 3);
        $many = $this->queriesOf(fn () => $this->ctrl->actionIndex());
        $listed = array_column($this->ctrl->actionIndex()['data'], null, 'id');

        $this->assertCount($fewRows + 6, $listed, 'the six new workflows are listed');
        $this->assertSame($few, $many, 'and cost no more queries');
        foreach ($operated as $workflow) {
            $item = $listed[$workflow->id];
            $this->assertArrayHasKey('has_trigger_token', $item, 'operated');
            $this->assertTrue($item['has_trigger_token'], 'operated');
            $this->assertSame($s['admin']->id, $item['trigger_user_id'], 'operated');
        }
        foreach ($viewed as $workflow) {
            $this->assertArrayNotHasKey('has_trigger_token', $listed[$workflow->id], 'viewed');
            $this->assertArrayNotHasKey('trigger_user_id', $listed[$workflow->id], 'viewed');
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function changingCallerProvider(): array
    {
        return [
            'an admin' => ['admin'],
            'a team operator' => ['member'],
        ];
    }

    /**
     * @dataProvider changingCallerProvider
     */
    public function testCreateAndUpdateResponsesSayWhomTheTriggerRunsAs(string $caller): void
    {
        $s = $this->teamScope();
        $user = $s[$caller];
        $this->authenticate($user);
        $this->setBody([
            'name' => 'wt-runs-as-' . uniqid('', true),
            'steps' => [['name' => 'deploy', 'job_template_id' => $s['own']->id]],
        ]);

        $created = $this->data($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame($user->id, $created['created_by']);
        $this->assertArrayHasKey('has_trigger_token', $created);
        $this->assertArrayHasKey('trigger_user_id', $created);
        $this->assertFalse($created['has_trigger_token']);
        $this->assertNull($created['trigger_user_id']);

        $workflow = WorkflowTemplate::findOne($created['id']);
        $this->assertNotNull($workflow);
        $generator = $this->createUser('wt_api_generator');
        $workflow->generateTriggerToken($generator->id);
        $this->setBody(['name' => 'wt-runs-as-renamed-' . uniqid('', true)]);

        $updated = $this->data($this->ctrl->actionUpdate($workflow->id));

        $this->assertSame($user->id, $updated['created_by']);
        $this->assertArrayHasKey('has_trigger_token', $updated);
        $this->assertTrue($updated['has_trigger_token']);
        $this->assertSame($generator->id, $updated['trigger_user_id']);
    }

    /**
     * Regression: a caller who may create workflow templates but not change
     * them was told about the new workflow's trigger.
     */
    public function testTheCreateResponseOfACallerWhoMayNotChangeWorkflowsSaysNothingAboutTheTrigger(): void
    {
        $this->authenticateWithPermissions(['workflow-template.view', 'workflow-template.create']);
        $this->setBody(['name' => 'wt-create-only-' . uniqid('', true)]);

        $created = $this->data($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertNotNull(WorkflowTemplate::findOne($created['id']));
        $this->assertArrayNotHasKey('has_trigger_token', $created);
        $this->assertArrayNotHasKey('trigger_user_id', $created);
    }

    public function testNoResponseContainsTheTriggerTokenOrItsHash(): void
    {
        $admin = $this->createUserWithRole('wt_api_admin', 'admin');
        $workflow = $this->createWorkflowTemplate($admin->id);
        $raw = $workflow->generateTriggerToken($admin->id);
        $hash = (string)$workflow->trigger_token;
        $this->authenticate($admin);
        $this->setBody(['name' => 'wt-token-hidden-' . uniqid('', true)]);

        $responses = [
            'view' => $this->ctrl->actionView($workflow->id),
            'index' => $this->ctrl->actionIndex(),
            'update' => $this->ctrl->actionUpdate($workflow->id),
        ];

        foreach ($responses as $action => $response) {
            $json = (string)json_encode($response);
            $this->assertStringContainsString('"has_trigger_token":true', $json, $action);
            $this->assertStringNotContainsString($raw, $json, $action);
            $this->assertStringNotContainsString($hash, $json, $action);
        }
        $viewed = $this->data($responses['view']);
        $this->assertArrayNotHasKey('trigger_token', $viewed);
        $this->assertArrayNotHasKey('trigger_token_created_by', $viewed);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Each case of $cases once for every trigger token state.
     *
     * @param array<string, array{0: string, 1: string, 2: list<string>}> $cases
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: string}>
     */
    private static function inEveryTokenState(array $cases): array
    {
        $states = [
            'no token' => 'none',
            'a generated token' => 'generated',
            'a token from before the generator was recorded' => 'legacy',
        ];
        $provided = [];
        foreach ($cases as $case => [$memberRole, $caller, $stepTemplates]) {
            foreach ($states as $stateName => $state) {
                $provided["{$case}, {$stateName}"] = [$memberRole, $caller, $stepTemplates, $state];
            }
        }

        return $provided;
    }

    /**
     * A workflow by $creator with a job step on each of the teamScope()
     * templates named in $stepTemplates, or a pause step only.
     *
     * @param array<string, mixed> $scope teamScope()
     * @param list<string> $stepTemplates
     */
    private function workflowOf(array $scope, User $creator, array $stepTemplates): WorkflowTemplate
    {
        if ($stepTemplates === []) {
            $workflow = $this->createWorkflowTemplate($creator->id);
            $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_PAUSE);
            return $workflow;
        }
        $templateIds = [];
        foreach ($stepTemplates as $key) {
            $template = $scope[$key];
            $this->assertInstanceOf(JobTemplate::class, $template);
            $templateIds[] = (int)$template->id;
        }

        return $this->createWorkflowWithJobSteps($creator->id, ...$templateIds);
    }

    /**
     * Put the workflow into a trigger token state; returns whom its trigger
     * then runs as, null without a token. A generated token is generated by
     * a user of its own.
     */
    private function giveTriggerToken(WorkflowTemplate $workflow, string $state): ?int
    {
        if ($state === 'generated') {
            $generatorId = $this->createUser('wt_api_generator')->id;
            $workflow->generateTriggerToken($generatorId);
            return $generatorId;
        }
        if ($state === 'legacy') {
            $workflow->trigger_token = hash('sha256', 'legacy-' . uniqid('', true));
            $workflow->trigger_token_created_by = null;
            $workflow->save(false, ['trigger_token', 'trigger_token_created_by']);
            return (int)$workflow->created_by;
        }
        return null;
    }

    /**
     * The workflow as actionView() and actionIndex() return it.
     *
     * @return array{view: array<string, mixed>, index: array<string, mixed>}
     */
    private function viewedAndListed(WorkflowTemplate $workflow): array
    {
        $viewed = $this->data($this->ctrl->actionView($workflow->id));
        $listed = array_column($this->ctrl->actionIndex()['data'], null, 'id')[$workflow->id] ?? null;
        $this->assertIsArray($listed, 'the workflow is listed');

        return ['view' => $viewed, 'index' => $listed];
    }

    /**
     * $count workflows, each with a job step on a template of a new project
     * that a new team of teamScope()'s member holds with $role, each with a
     * trigger token the admin generated.
     *
     * @param array{member: User, admin: User, own: JobTemplate} $scope teamScope()
     * @return list<WorkflowTemplate>
     */
    private function workflowsInNewProjects(array $scope, string $role, int $count): array
    {
        $adminId = $scope['admin']->id;
        $team = $this->createTeam($adminId);
        $this->addTeamMember($team->id, $scope['member']->id);
        $workflows = [];
        for ($i = 0; $i < $count; $i++) {
            $project = $this->createProject($adminId);
            $this->createTeamProject($team->id, $project->id, $role);
            $template = $this->createJobTemplate(
                $project->id,
                (int)$scope['own']->inventory_id,
                (int)$scope['own']->runner_group_id,
                $adminId
            );
            $workflow = $this->createWorkflowWithJobSteps($adminId, $template->id);
            $workflow->generateTriggerToken($adminId);
            $workflows[] = $workflow;
        }

        return $workflows;
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'workflow-trigger-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * Signs in a token user whose only role holds exactly $permissions.
     *
     * @param list<string> $permissions
     */
    private function authenticateWithPermissions(array $permissions): User
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('wt-trigger-api-' . uniqid());
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $user = $this->createUser('wt_trigger_api');
        $auth->assign($role, (string)$user->id);
        $this->authenticate($user);

        return $user;
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

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function data(array $result): array
    {
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        /** @var array<string, mixed> $data */
        $data = $result['data'];

        return $data;
    }
}
