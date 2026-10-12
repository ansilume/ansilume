<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\TriggerTokenResolver;
use app\controllers\api\v1\TriggerTokensController;
use app\controllers\TriggerController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowTemplate;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\InvalidRouteException;
use yii\base\Module;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;
use yii\web\UrlManager;

/**
 * The trigger token endpoints of the REST API: generate (or replace) and
 * revoke the token of a job or workflow template, with the permissions,
 * audit actions and run-as rules of the web UI.
 *
 * Requests go through the URL rules of config/web.php to the controller,
 * as in the web application, so the verb of each endpoint counts.
 */
class TriggerTokensControllerTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private const CONTROLLER_ROUTE = 'api/v1/trigger-tokens/';

    /**
     * The documented verb and path segment of each action:
     * <verb> /api/v1/<segment>/{id}/trigger-token.
     */
    private const ENDPOINTS = [
        'generate-for-job-template' => ['POST', 'job-templates'],
        'revoke-for-job-template' => ['DELETE', 'job-templates'],
        'generate-for-workflow-template' => ['POST', 'workflow-templates'],
        'revoke-for-workflow-template' => ['DELETE', 'workflow-templates'],
    ];

    // ── Job templates ────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mayChangeJobTemplateProvider(): array
    {
        return [
            'a team operator, the team\'s project' => ['member', 'own'],
            'an operator, a project of no team' => ['member', 'open'],
            'an admin, another team\'s project' => ['admin', 'foreign'],
        ];
    }

    /**
     * @dataProvider mayChangeJobTemplateProvider
     */
    public function testGeneratingAJobTemplateTokenReturnsItOnceAndRunsTheTriggerAsTheCaller(string $caller, string $key): void
    {
        $s = $this->teamScope();
        $template = $this->jobTemplateOf($s, $key);
        $callerId = (int)$s[$caller]->id;
        $this->authenticate($s[$caller]);

        $data = $this->call('generate-for-job-template', (int)$template->id, 201);

        $this->assertSame(['token', 'trigger_user_id'], array_keys($data));
        $this->assertIsString($data['token']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['token']);
        $this->assertSame($callerId, $data['trigger_user_id']);
        $this->assertSame('no-store', \Yii::$app->response->headers->get('Cache-Control'), 'a response with a secret is not cached');
        $template->refresh();
        $this->assertSame(hash('sha256', $data['token']), $template->trigger_token, 'only the hash is stored');
        $this->assertSame($callerId, $template->getTriggerUserId());
        $audit = $this->onlyAudit(AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED, 'job_template', (int)$template->id);
        $this->assertSame($callerId, (int)$audit->user_id);
        $this->assertSame(['name' => $template->name, 'source' => 'api'], json_decode((string)$audit->metadata, true));
        $this->assertStringNotContainsString($data['token'], (string)json_encode($audit->attributes));
    }

    /**
     * Generating again replaces the token and rebinds the trigger to the
     * caller; the old token stops working.
     */
    public function testGeneratingReplacesTheTokenAndRebindsTheTrigger(): void
    {
        $s = $this->teamScope();
        $own = $s['own'];
        $old = $own->generateTriggerToken((int)$s['admin']->id);
        $this->authenticate($s['member']);

        $data = $this->call('generate-for-job-template', (int)$own->id, 201);

        $this->assertNotSame($old, $data['token']);
        $this->assertNull(JobTemplate::findByTriggerToken($old));
        $this->assertSame((int)$own->id, JobTemplate::findByTriggerToken((string)$data['token'])?->id);
        $own->refresh();
        $this->assertSame((int)$s['member']->id, $own->getTriggerUserId());
    }

    /**
     * The token the API returns fires the trigger in the header, as the
     * caller.
     */
    public function testTheReturnedTokenFiresTheTriggerAsTheCaller(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $data = $this->call('generate-for-job-template', (int)$s['own']->id, 201);

        \Yii::$app->request->getHeaders()->removeAll();
        \Yii::$app->request->getHeaders()->set(TriggerTokenResolver::HEADER, (string)$data['token']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $result = (new TriggerController('trigger', \Yii::$app))->actionFire();

        $this->assertSame(201, $result->statusCode);
        $this->assertIsArray($result->data);
        $this->assertSame((int)$s['member']->id, (int)Job::findOne($result->data['job_id'] ?? 0)?->launched_by);
    }

    public function testRevokingAJobTemplateTokenIsIdempotent(): void
    {
        $s = $this->teamScope();
        $own = $s['own'];
        $own->generateTriggerToken((int)$s['member']->id);
        $this->authenticate($s['member']);

        $this->assertSame(['revoked' => true], $this->call('revoke-for-job-template', (int)$own->id, 200));
        $own->refresh();
        $this->assertNull($own->trigger_token);
        $this->assertNull($own->trigger_token_created_by);
        $audit = $this->onlyAudit(AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED, 'job_template', (int)$own->id);
        $this->assertSame((int)$s['member']->id, (int)$audit->user_id);
        $this->assertSame(['name' => $own->name, 'source' => 'api'], json_decode((string)$audit->metadata, true));

        $this->assertSame(['revoked' => true], $this->call('revoke-for-job-template', (int)$own->id, 200), 'again: same answer');
        $this->onlyAudit(AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED, 'job_template', (int)$own->id);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function jobTemplateTheMemberMayNotChangeProvider(): array
    {
        return [
            'generate, project the team only views' => ['generate-for-job-template', 'viewed'],
            'revoke, project the team only views' => ['revoke-for-job-template', 'viewed'],
            'generate, another team\'s project' => ['generate-for-job-template', 'foreign'],
            'revoke, another team\'s project' => ['revoke-for-job-template', 'foreign'],
        ];
    }

    /**
     * @dataProvider jobTemplateTheMemberMayNotChangeProvider
     */
    public function testJobTemplateTokensNeedOperatorAccessToTheProject(string $action, string $key): void
    {
        $s = $this->teamScope();
        $template = $this->jobTemplateOf($s, $key);
        $template->generateTriggerToken((int)$s['admin']->id);
        $template->refresh();
        $hash = $template->trigger_token;
        $this->authenticate($s['member']);

        $this->assertForbidden($action, (int)$template->id);

        $template->refresh();
        $this->assertSame($hash, $template->trigger_token);
        $this->assertSame((int)$s['admin']->id, $template->getTriggerUserId());
        $this->assertNoTokenAudit((int)$template->id);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function everyActionProvider(): array
    {
        return [
            'generate-for-job-template' => ['generate-for-job-template'],
            'revoke-for-job-template' => ['revoke-for-job-template'],
            'generate-for-workflow-template' => ['generate-for-workflow-template'],
            'revoke-for-workflow-template' => ['revoke-for-workflow-template'],
        ];
    }

    /**
     * @dataProvider everyActionProvider
     */
    public function testAViewerIsStoppedByTheAccessRules(string $action): void
    {
        $s = $this->teamScope('viewer');
        $workflow = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['open']->id);
        $this->authenticate($s['member']);

        $id = str_contains($action, 'workflow') ? (int)$workflow->id : (int)$s['open']->id;
        $this->assertForbidden($action, $id);
        $this->assertNoTokenAudit($id);
    }

    /**
     * @dataProvider everyActionProvider
     */
    public function testACallWithoutAnApiTokenIs401(string $action): void
    {
        $s = $this->teamScope();

        $this->expectException(UnauthorizedHttpException::class);
        $this->send($action, (int)$s['open']->id);
    }

    /**
     * @dataProvider everyActionProvider
     */
    public function testAnUnknownTemplateIs404(string $action): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['admin']);

        $this->expectException(NotFoundHttpException::class);
        $this->send($action, 99999999);
    }

    // ── Workflow templates ───────────────────────────────────────────────────

    public function testGeneratingAWorkflowTemplateTokenReturnsItOnceAndRunsTheTriggerAsTheCaller(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['own']->id, (int)$s['open']->id);
        $this->authenticate($s['member']);

        $data = $this->call('generate-for-workflow-template', (int)$workflow->id, 201);

        $this->assertIsString($data['token']);
        $this->assertSame((int)$s['member']->id, $data['trigger_user_id']);
        $this->assertSame('no-store', \Yii::$app->response->headers->get('Cache-Control'));
        $workflow->refresh();
        $this->assertSame(hash('sha256', $data['token']), $workflow->trigger_token);
        $this->assertSame((int)$s['member']->id, $workflow->getTriggerUserId());
        $audit = $this->onlyAudit(AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_GENERATED, 'workflow_template', (int)$workflow->id);
        $this->assertSame(['name' => $workflow->name, 'source' => 'api'], json_decode((string)$audit->metadata, true));
    }

    public function testRevokingAWorkflowTemplateTokenIsIdempotent(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['own']->id);
        $workflow->generateTriggerToken((int)$s['admin']->id);
        $this->authenticate($s['member']);

        $this->assertSame(['revoked' => true], $this->call('revoke-for-workflow-template', (int)$workflow->id, 200));
        $this->assertSame(['revoked' => true], $this->call('revoke-for-workflow-template', (int)$workflow->id, 200));

        $workflow->refresh();
        $this->assertNull($workflow->trigger_token);
        $this->onlyAudit(AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_REVOKED, 'workflow_template', (int)$workflow->id);
    }

    /**
     * A workflow trigger launches every job step as the user who generated
     * the token, so it needs operator access to every job step's project.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function workflowTheMemberMayNotChangeProvider(): array
    {
        return [
            'generate, a step of another team' => ['generate-for-workflow-template', 'foreign'],
            'revoke, a step of another team' => ['revoke-for-workflow-template', 'foreign'],
            'generate, a step the team only views' => ['generate-for-workflow-template', 'viewed'],
        ];
    }

    /**
     * @dataProvider workflowTheMemberMayNotChangeProvider
     */
    public function testWorkflowTemplateTokensNeedOperatorAccessToEveryJobStep(string $action, string $key): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps((int)$s['admin']->id, (int)$s['own']->id, (int)$this->jobTemplateOf($s, $key)->id);
        $workflow->generateTriggerToken((int)$s['admin']->id);
        $workflow->refresh();
        $hash = $workflow->trigger_token;
        $this->authenticate($s['member']);

        $this->assertForbidden($action, (int)$workflow->id);

        $workflow->refresh();
        $this->assertSame($hash, $workflow->trigger_token);
        $this->assertNoTokenAudit((int)$workflow->id);
    }

    // ── Verbs ────────────────────────────────────────────────────────────────

    /**
     * Every verb but the one an action takes, on the default route
     * /api/v1/trigger-tokens/<action>?id=N, which Yii resolves for any verb.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function otherVerbOnTheDefaultRouteProvider(): array
    {
        $cases = [];
        foreach (self::ENDPOINTS as $action => [$verb]) {
            foreach (['GET', 'HEAD', 'PUT', 'PATCH', $verb === 'POST' ? 'DELETE' : 'POST'] as $other) {
                $cases["{$other} {$action}"] = [$action, $other, $verb];
            }
        }

        return $cases;
    }

    /**
     * Regression (PRE-1): every verb reached the actions through the default
     * route, so with an API token a GET on
     * /api/v1/trigger-tokens/generate-for-job-template?id=N replaced the
     * template's trigger token, and a GET on revoke-for-… revoked it. Now any
     * verb but the action's own answers 405 in the API's error shape, before
     * authentication: nothing is generated, revoked or audited, and the API
     * token is not even marked as used.
     *
     * @dataProvider otherVerbOnTheDefaultRouteProvider
     */
    public function testAnyOtherVerbOnTheDefaultRouteIs405AndChangesNothing(string $action, string $other, string $allowed): void
    {
        $s = $this->teamScope();
        $template = $this->templateWithTokenOf($s, $action);
        $before = $this->tokenState($template);
        $apiToken = $this->authenticate($s['member']);

        [$routed, $result] = $this->request($other, self::CONTROLLER_ROUTE . $action, ['id' => (string)$template->id]);

        $this->assertSame($action, $routed);
        $this->assertMethodNotAllowed($allowed, $result);
        $this->assertSame($before, $this->tokenState($template), 'nothing generated or revoked');
        $this->assertNoTokenAudit((int)$template->id);
        $apiToken->refresh();
        $this->assertNull($apiToken->last_used_at, 'answered before authentication');
    }

    /**
     * The verb is checked first: a request without an API token gets the
     * 405 as well, not a 401.
     *
     * @dataProvider everyActionProvider
     */
    public function testTheVerbIsCheckedBeforeAuthentication(string $action): void
    {
        $s = $this->teamScope();
        $template = $this->templateWithTokenOf($s, $action);
        $before = $this->tokenState($template);
        [$verb] = self::ENDPOINTS[$action];

        [, $result] = $this->request('GET', self::CONTROLLER_ROUTE . $action, ['id' => (string)$template->id]);

        $this->assertMethodNotAllowed($verb, $result);
        $this->assertSame($before, $this->tokenState($template));
        $this->assertNoTokenAudit((int)$template->id);
    }

    /**
     * GET, HEAD, PUT and PATCH on the documented path of a job template and
     * of a workflow template, /api/v1/<segment>/{id}/trigger-token, whose URL
     * rules take POST and DELETE only. The action names the template kind.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function otherVerbOnTheDocumentedPathProvider(): array
    {
        $cases = [];
        foreach (['job template' => 'generate-for-job-template', 'workflow template' => 'generate-for-workflow-template'] as $kind => $action) {
            foreach (['GET', 'HEAD', 'PUT', 'PATCH'] as $other) {
                $cases["{$other}, {$kind}"] = [$other, $action];
            }
        }

        return $cases;
    }

    /**
     * Any other verb on a documented path never reaches the controller: it
     * gets the same 404 as every other unknown API path, and nothing is
     * generated, revoked or audited. A verb filter for the whole API is
     * planned separately; until then these paths answer like the rest of
     * the API.
     *
     * @dataProvider otherVerbOnTheDocumentedPathProvider
     */
    public function testAnyOtherVerbOnTheDocumentedPathIs404AndChangesNothing(string $other, string $action): void
    {
        $s = $this->teamScope();
        $template = $this->templateWithTokenOf($s, $action);
        $before = $this->tokenState($template);
        $apiToken = $this->authenticate($s['member']);

        try {
            $this->handle($other, $this->documentedPath($action, (int)$template->id));
            $this->fail("{$other} on the documented path was served");
        } catch (NotFoundHttpException $e) {
            $this->assertSame(404, $e->statusCode);
            $this->assertSame('Page not found.', $e->getMessage(), 'the 404 of every unknown path');
        }

        $this->assertSame($before, $this->tokenState($template), 'nothing generated or revoked');
        $this->assertNoTokenAudit((int)$template->id);
        $apiToken->refresh();
        $this->assertNull($apiToken->last_used_at, 'the API token was not even checked');
    }

    /**
     * The 405 does not depend on the request path: without a request URI,
     * as in a console run, it names the action's own verb as well.
     *
     * @dataProvider everyActionProvider
     */
    public function testWithoutARequestUriThe405NamesTheActionsOwnVerb(string $action): void
    {
        $s = $this->teamScope();
        $template = $this->templateWithTokenOf($s, $action);
        $before = $this->tokenState($template);
        $this->authenticate($s['member']);
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        unset($_SERVER['REQUEST_URI']);

        try {
            $result = $this->controller()->runAction($action, ['id' => (string)$template->id]);
        } finally {
            if ($uri !== null) {
                $_SERVER['REQUEST_URI'] = $uri;
            }
        }

        $this->assertMethodNotAllowed(self::ENDPOINTS[$action][0], $result);
        $this->assertSame($before, $this->tokenState($template));
        $this->assertNoTokenAudit((int)$template->id);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function controller(): TriggerTokensController
    {
        return new TriggerTokensController('api/v1/trigger-tokens', \Yii::$app);
    }

    /**
     * Send $verb to $path as the web application does: the URL rules of
     * config/web.php resolve the route and its parameters (query parameters
     * included), and the controller runs the action, filters included.
     *
     * @param array<string, string> $query
     * @return array{0: string, 1: mixed} the action id and the action's result
     */
    private function request(string $verb, string $path, array $query = []): array
    {
        $_SERVER['REQUEST_METHOD'] = $verb;
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setPathInfo($path);
        $request->setQueryParams($query);
        $parsed = $this->urlManager()->parseRequest($request);
        $this->assertIsArray($parsed, "{$verb} {$path} resolves");
        [$route, $params] = $parsed;
        $this->assertIsString($route);
        $this->assertIsArray($params);
        $this->assertStringStartsWith(self::CONTROLLER_ROUTE, $route, "{$verb} /{$path} reaches the trigger tokens controller");
        $action = substr($route, strlen(self::CONTROLLER_ROUTE));

        return [$action, $this->controller()->runAction($action, $params + $query)];
    }

    /**
     * Handle $verb on $path as yii\web\Application::handleRequest() does: the
     * URL rules of config/web.php resolve the route, the web application's
     * controllers run it, and a route that no controller and action serve is
     * a 404.
     *
     * @return mixed the action's result
     * @throws NotFoundHttpException when nothing serves the route
     */
    private function handle(string $verb, string $path): mixed
    {
        $_SERVER['REQUEST_METHOD'] = $verb;
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setPathInfo($path);
        $request->setQueryParams([]);
        $parsed = $this->urlManager()->parseRequest($request);
        $this->assertIsArray($parsed, "{$verb} {$path} resolves");
        [$route, $params] = $parsed;
        $this->assertIsString($route);
        $this->assertIsArray($params);
        // The web application's controller lookup: controllerNamespace app\controllers.
        $web = new Module('web', null, ['controllerNamespace' => 'app\controllers']);
        try {
            return $web->runAction($route, $params);
        } catch (InvalidRouteException $e) {
            throw new NotFoundHttpException('Page not found.', $e->getCode(), $e);
        }
    }

    /**
     * The documented request for $action on template $id: its verb on
     * /api/v1/<segment>/{id}/trigger-token.
     */
    private function send(string $action, int $id): mixed
    {
        [$routed, $result] = $this->request(self::ENDPOINTS[$action][0], $this->documentedPath($action, $id));
        $this->assertSame($action, $routed, 'the documented request reaches the action');

        return $result;
    }

    private function documentedPath(string $action, int $id): string
    {
        return 'api/v1/' . self::ENDPOINTS[$action][1] . '/' . $id . '/trigger-token';
    }

    /**
     * A UrlManager with the URL rules of the web application.
     */
    private function urlManager(): UrlManager
    {
        /** @var array{components: array{urlManager: array{rules: array<mixed>}}} $config */
        $config = require dirname(__DIR__, 5) . '/config/web.php';

        return new UrlManager([
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => false,
            'rules' => $config['components']['urlManager']['rules'],
            'cache' => false,
            'baseUrl' => '',
            'scriptUrl' => '/index.php',
        ]);
    }

    /**
     * Send the documented request for $action, access rules included, and
     * return the data of a successful response.
     *
     * @return array<string, mixed>
     */
    private function call(string $action, int $id, int $status): array
    {
        $result = $this->send($action, $id);

        $this->assertSame($status, \Yii::$app->response->statusCode);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    private function assertForbidden(string $action, int $id): void
    {
        $result = $this->send($action, $id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result ?? \Yii::$app->response->data);
    }

    /**
     * 405 in the API's error shape with an Allow header that names the
     * action's verb, and the action did not run.
     */
    private function assertMethodNotAllowed(string $allowed, mixed $result): void
    {
        $response = \Yii::$app->response;
        $this->assertSame(405, $response->statusCode);
        $this->assertSame(
            ['error' => ['message' => "Method Not Allowed. This URL can only handle the following request methods: {$allowed}."]],
            $response->data
        );
        $this->assertSame($allowed, $response->headers->get('Allow'));
        $this->assertNull($result, 'the action did not run');
    }

    /**
     * The template of $action's kind (a workflow whose only job step the
     * member may operate) with a trigger token the admin generated, which
     * the member may change.
     *
     * @param array{member: User, admin: User, own: JobTemplate} $scope
     */
    private function templateWithTokenOf(array $scope, string $action): JobTemplate|WorkflowTemplate
    {
        $template = str_contains($action, 'workflow')
            ? $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['own']->id)
            : $scope['own'];
        $template->generateTriggerToken((int)$scope['admin']->id);

        return $template;
    }

    /**
     * The stored token hash and the user the trigger runs as.
     *
     * @return array{0: string|null, 1: int|null}
     */
    private function tokenState(JobTemplate|WorkflowTemplate $template): array
    {
        $template->refresh();
        $this->assertNotNull($template->trigger_token, 'the template has a token');

        return [$template->trigger_token, $template->getTriggerUserId()];
    }

    private function onlyAudit(string $action, string $objectType, int $objectId): AuditLog
    {
        /** @var AuditLog[] $entries */
        $entries = AuditLog::find()->where(['action' => $action, 'object_type' => $objectType, 'object_id' => $objectId])->all();
        $this->assertCount(1, $entries, $action);

        return $entries[0];
    }

    private function assertNoTokenAudit(int $objectId): void
    {
        $this->assertFalse(AuditLog::find()->where(['like', 'action', 'trigger-token'])->andWhere(['object_id' => $objectId])->exists());
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function jobTemplateOf(array $scope, string $key): JobTemplate
    {
        $template = $scope[$key];
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template;
    }

    private function authenticate(User $user): ApiToken
    {
        ['token' => $token, 'raw' => $raw] = ApiToken::generate((int)$user->id, 'trigger-tokens-test');
        \Yii::$app->request->getHeaders()->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);

        return $token;
    }
}
