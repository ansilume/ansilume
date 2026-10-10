<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobTemplatesController;
use app\models\ApiToken;
use app\models\JobTemplate;
use app\models\TeamProject;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\TeamScopeFixtures;

/**
 * Whom a job template's inbound trigger runs as, in the API:
 * has_trigger_token and trigger_user_id.
 *
 * Regression: a trigger runs as the user who generated its token (the
 * template's creator for older tokens), but the API showed neither the
 * creator nor the token's state, so API-only operators could not tell whom a
 * trigger runs as.
 *
 * Regression: the API then returned both fields to every caller who may view
 * the template, viewers and team viewers included, while the template page
 * shows the trigger card only to users who may change the template
 * (job-template.update and operator access to its project).
 */
class JobTemplatesControllerTriggerTest extends WebControllerTestCase
{
    use CountsQueries;
    use TeamScopeFixtures;

    private JobTemplatesController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new JobTemplatesController('api/v1/job-templates', \Yii::$app);
    }

    /**
     * Callers who may change the template: the RBAC role of teamScope()'s
     * member, the caller and the template (teamScope() keys), in every
     * trigger token state.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function mayChangeProvider(): array
    {
        return self::inEveryTokenState([
            'an admin, another team\'s project' => ['operator', 'admin', 'foreign'],
            'a team operator' => ['operator', 'member', 'own'],
            'an operator, a project of no team' => ['operator', 'member', 'open'],
        ]);
    }

    /**
     * Callers who may view the template but not change it, as in
     * mayChangeProvider().
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function mayOnlyViewProvider(): array
    {
        return self::inEveryTokenState([
            'a team viewer with the operator role' => ['operator', 'member', 'viewed'],
            'a viewer, a project of no team' => ['viewer', 'member', 'open'],
            'a viewer whose team operates the project' => ['viewer', 'member', 'own'],
        ]);
    }

    /**
     * @dataProvider mayChangeProvider
     */
    public function testViewAndIndexSayWhomTheTriggerRunsAsToCallersWhoMayChangeTheTemplate(
        string $memberRole,
        string $caller,
        string $templateKey,
        string $state
    ): void {
        $s = $this->teamScope($memberRole);
        $template = $this->templateOf($s, $templateKey);
        $runsAs = $this->giveTriggerToken($template, $state);
        $this->authenticate($s[$caller]);

        foreach ($this->viewedAndListed($template) as $action => $item) {
            $this->assertSame((int)$s['admin']->id, $item['created_by'], $action);
            $this->assertArrayHasKey('has_trigger_token', $item, $action);
            $this->assertArrayHasKey('trigger_user_id', $item, $action);
            $this->assertSame($runsAs !== null, $item['has_trigger_token'], $action);
            $this->assertSame($runsAs, $item['trigger_user_id'], $action);
        }
    }

    /**
     * Regression: viewers and team viewers learned from the API whether a
     * template has a trigger and whom it runs as.
     *
     * @dataProvider mayOnlyViewProvider
     */
    public function testViewAndIndexSayNothingAboutTheTriggerToCallersWhoMayOnlyViewTheTemplate(
        string $memberRole,
        string $caller,
        string $templateKey,
        string $state
    ): void {
        $s = $this->teamScope($memberRole);
        $template = $this->templateOf($s, $templateKey);
        $this->giveTriggerToken($template, $state);
        $this->authenticate($s[$caller]);

        foreach ($this->viewedAndListed($template) as $action => $item) {
            $this->assertSame((int)$template->id, $item['id'], $action);
            $this->assertArrayNotHasKey('has_trigger_token', $item, $action);
            $this->assertArrayNotHasKey('trigger_user_id', $item, $action);
        }
    }

    /**
     * The list decides for all its templates at once: more templates, each
     * in a project of its own, cost no more queries.
     */
    public function testTheListDecidesWhoMayChangeItsTemplatesWithAFixedNumberOfQueries(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        // The first call loads the table schemas.
        $fewRows = count($this->ctrl->actionIndex()['data']);
        $few = $this->queriesOf(fn () => $this->ctrl->actionIndex());

        $operated = $this->templatesInNewProjects($s, TeamProject::ROLE_OPERATOR, 3);
        $viewed = $this->templatesInNewProjects($s, TeamProject::ROLE_VIEWER, 3);
        $many = $this->queriesOf(fn () => $this->ctrl->actionIndex());
        $listed = array_column($this->ctrl->actionIndex()['data'], null, 'id');

        $this->assertCount($fewRows + 6, $listed, 'the six new templates are listed');
        $this->assertSame($few, $many, 'and cost no more queries');
        foreach ($operated as $template) {
            $item = $listed[$template->id];
            $this->assertArrayHasKey('has_trigger_token', $item, 'operated');
            $this->assertTrue($item['has_trigger_token'], 'operated');
            $this->assertSame((int)$s['admin']->id, $item['trigger_user_id'], 'operated');
        }
        foreach ($viewed as $template) {
            $this->assertArrayNotHasKey('has_trigger_token', $listed[$template->id], 'viewed');
            $this->assertArrayNotHasKey('trigger_user_id', $listed[$template->id], 'viewed');
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
        $this->authenticate($s[$caller]);
        $this->setBody($this->templateBody($s['own']));

        $created = $this->data($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame((int)$s[$caller]->id, $created['created_by']);
        $this->assertArrayHasKey('has_trigger_token', $created);
        $this->assertArrayHasKey('trigger_user_id', $created);
        $this->assertFalse($created['has_trigger_token']);
        $this->assertNull($created['trigger_user_id']);

        $template = JobTemplate::findOne($created['id']);
        $this->assertNotNull($template);
        $generator = (int)$this->createUser('jt-generator')->id;
        $template->generateTriggerToken($generator);
        $this->setBody(['name' => 'api-runs-as-renamed-' . uniqid('', true)]);

        $updated = $this->data($this->ctrl->actionUpdate((int)$template->id));

        $this->assertSame((int)$s[$caller]->id, $updated['created_by']);
        $this->assertArrayHasKey('has_trigger_token', $updated);
        $this->assertTrue($updated['has_trigger_token']);
        $this->assertSame($generator, $updated['trigger_user_id']);
    }

    /**
     * Regression: a caller who may create job templates but not change them
     * was told about the new template's trigger.
     */
    public function testTheCreateResponseOfACallerWhoMayNotChangeTemplatesSaysNothingAboutTheTrigger(): void
    {
        $s = $this->teamScope();
        $this->authenticateWithPermissions(['job-template.view', 'job-template.create']);
        $this->setBody($this->templateBody($s['open']));

        $created = $this->data($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertNotNull(JobTemplate::findOne($created['id']));
        $this->assertArrayNotHasKey('has_trigger_token', $created);
        $this->assertArrayNotHasKey('trigger_user_id', $created);
    }

    public function testNoResponseContainsTheTriggerTokenOrItsHash(): void
    {
        $s = $this->teamScope();
        $template = $s['own'];
        $raw = $template->generateTriggerToken((int)$s['admin']->id);
        $hash = (string)$template->trigger_token;
        $this->authenticate($s['admin']);
        $this->setBody(['name' => 'api-token-hidden-' . uniqid('', true)]);

        $responses = [
            'view' => $this->ctrl->actionView((int)$template->id),
            'index' => $this->ctrl->actionIndex(),
            'update' => $this->ctrl->actionUpdate((int)$template->id),
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
     * @param array<string, array{0: string, 1: string, 2: string}> $cases
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function inEveryTokenState(array $cases): array
    {
        $states = [
            'no token' => 'none',
            'a generated token' => 'generated',
            'a token from before the generator was recorded' => 'legacy',
        ];
        $provided = [];
        foreach ($cases as $case => [$memberRole, $caller, $template]) {
            foreach ($states as $stateName => $state) {
                $provided["{$case}, {$stateName}"] = [$memberRole, $caller, $template, $state];
            }
        }

        return $provided;
    }

    /**
     * Put the template into a trigger token state; returns whom its trigger
     * then runs as, null without a token. A generated token is generated by
     * a user of its own.
     */
    private function giveTriggerToken(JobTemplate $template, string $state): ?int
    {
        if ($state === 'generated') {
            $generator = (int)$this->createUser('jt-generator')->id;
            $template->generateTriggerToken($generator);
            return $generator;
        }
        if ($state === 'legacy') {
            $template->trigger_token = hash('sha256', 'legacy-' . uniqid('', true));
            $template->trigger_token_created_by = null;
            $template->save(false, ['trigger_token', 'trigger_token_created_by']);
            return (int)$template->created_by;
        }
        return null;
    }

    /**
     * The template as actionView() and actionIndex() return it.
     *
     * @return array{view: array<string, mixed>, index: array<string, mixed>}
     */
    private function viewedAndListed(JobTemplate $template): array
    {
        $viewed = $this->data($this->ctrl->actionView((int)$template->id));
        $listed = array_column($this->ctrl->actionIndex()['data'], null, 'id')[$template->id] ?? null;
        $this->assertIsArray($listed, 'the template is listed');

        return ['view' => $viewed, 'index' => $listed];
    }

    /**
     * @param array<string, mixed> $scope teamScope()
     */
    private function templateOf(array $scope, string $key): JobTemplate
    {
        $template = $scope[$key];
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template;
    }

    /**
     * $count job templates, each in a new project that a new team of
     * teamScope()'s member holds with $role, each with a trigger token the
     * admin generated.
     *
     * @param array{member: User, admin: User, own: JobTemplate} $scope teamScope()
     * @return list<JobTemplate>
     */
    private function templatesInNewProjects(array $scope, string $role, int $count): array
    {
        $adminId = (int)$scope['admin']->id;
        $team = $this->createTeam($adminId);
        $this->addTeamMember((int)$team->id, (int)$scope['member']->id);
        $templates = [];
        for ($i = 0; $i < $count; $i++) {
            $project = $this->createProject($adminId);
            $this->createTeamProject((int)$team->id, (int)$project->id, $role);
            $template = $this->createJobTemplate(
                (int)$project->id,
                (int)$scope['own']->inventory_id,
                (int)$scope['own']->runner_group_id,
                $adminId
            );
            $template->generateTriggerToken($adminId);
            $templates[] = $template;
        }

        return $templates;
    }

    /**
     * A new template like $like: same project, inventory and runner group.
     *
     * @return array<string, mixed>
     */
    private function templateBody(JobTemplate $like): array
    {
        return [
            'name' => 'api-runs-as-' . uniqid('', true),
            'project_id' => (int)$like->project_id,
            'inventory_id' => (int)$like->inventory_id,
            'runner_group_id' => (int)$like->runner_group_id,
            'playbook' => 'site.yml',
        ];
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'job-template-trigger-test');
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
        $role = $auth->createRole('jt-trigger-api-' . uniqid());
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $user = $this->createUser('jt-trigger-api');
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
