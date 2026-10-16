<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\RunnerGroupsController;
use app\controllers\api\v1\RunnersController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Runner;
use app\models\RunnerGroup;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * What web/openapi.yaml says about who manages runner groups and runners
 * (CLAUDE.md security rule 16) is what the API does: each endpoint names
 * the permission its controller checks and who of the built-in roles holds
 * it, as the migrated roles of the test database grant them, and every 403
 * these endpoints give is the answer the spec documents, sent before the
 * 404 of an unknown ID and before anything is written.
 *
 * Regression (runner takeover, fixed by m000087): up to 2.11.0 the spec said
 * only "Requires runner-group.update" for the endpoints that move runners
 * and return a new raw runner token, while the operator role held that
 * permission, and documented the operators' 403 nowhere. Each test fails
 * on the spec from before.
 */
class OpenApiRunnerManagementContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use TeamScopeFixtures;

    /** The sentence of an endpoint whose permission only admins hold. */
    private const ADMINS_ONLY = 'which of the built-in roles only admin holds (403 `Forbidden.` otherwise, operators included)';

    /** The sentence of an endpoint every built-in role may call. */
    private const EVERY_ROLE = 'which every built-in role holds';

    /** The response every refused write of runner groups and runners gives. */
    private const REFUSAL = '#/components/responses/RunnerManagementForbidden';

    /**
     * The writes of runner groups and runners: path, method, controller
     * ('groups' or 'runners'), action and whether the path has an ID.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function writeProvider(): array
    {
        return [
            'POST /api/v1/runner-groups' => ['/api/v1/runner-groups', 'post', 'groups', 'create'],
            'PUT /api/v1/runner-groups/{id}' => ['/api/v1/runner-groups/{id}', 'put', 'groups', 'update'],
            'DELETE /api/v1/runner-groups/{id}' => ['/api/v1/runner-groups/{id}', 'delete', 'groups', 'delete'],
            'DELETE /api/v1/runners/{id}' => ['/api/v1/runners/{id}', 'delete', 'runners', 'delete'],
            'POST /api/v1/runners/{id}/move' => ['/api/v1/runners/{id}/move', 'post', 'runners', 'move'],
            'POST /api/v1/runners/{id}/regenerate-token' => ['/api/v1/runners/{id}/regenerate-token', 'post', 'runners', 'regenerate-token'],
        ];
    }

    /**
     * The reads of runner groups and runners.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function readProvider(): array
    {
        return [
            'GET /api/v1/runner-groups' => ['/api/v1/runner-groups', 'get', 'groups', 'index'],
            'GET /api/v1/runner-groups/{id}' => ['/api/v1/runner-groups/{id}', 'get', 'groups', 'view'],
            'GET /api/v1/runners' => ['/api/v1/runners', 'get', 'runners', 'index'],
            'GET /api/v1/runners/{id}' => ['/api/v1/runners/{id}', 'get', 'runners', 'view'],
        ];
    }

    /**
     * Every endpoint names the permission its controller checks, and says
     * who of the built-in roles holds it, as the migrated roles grant it:
     * the writes only admin, the reads every built-in role.
     */
    public function testEveryEndpointNamesItsPermissionAndWhoHoldsIt(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $held = [];
        foreach (['viewer', 'operator', 'admin'] as $role) {
            $held[$role] = array_keys($auth->getPermissionsByRole($role));
        }
        foreach ([[self::writeProvider(), self::ADMINS_ONLY, ['admin']], [self::readProvider(), self::EVERY_ROLE, ['viewer', 'operator', 'admin']]] as [$endpoints, $sentence, $holders]) {
            foreach ($endpoints as $label => [$path, $method, $controller, $action]) {
                $permission = $this->rulesOf($controller)[$action] ?? null;
                $this->assertIsString($permission, $label);
                $this->assertStringContainsString('Requires `' . $permission . '`, ' . $sentence, $this->textOf(['paths', $path, $method, 'description']), $label);
                foreach ($held as $role => $permissions) {
                    $this->assertSame(in_array($role, $holders, true), in_array($permission, $permissions, true), "{$label}: whether {$role} holds {$permission}.");
                }
            }
        }
    }

    /**
     * An operator is refused every write with the answer the spec's
     * RunnerManagementForbidden documents, also for an ID that does not
     * exist, and nothing is written; the spec references that response for
     * every write.
     *
     * @dataProvider writeProvider
     */
    public function testAnOperatorGetsTheDocumentedRefusal(string $path, string $method, string $controller, string $action): void
    {
        $this->assertSame(self::REFUSAL, $this->dig($this->spec(), ['paths', $path, $method, 'responses', '403', '$ref'], "{$method} {$path} documents its 403."));
        $documented = $this->dig($this->spec(), ['components', 'responses', 'RunnerManagementForbidden', 'content', 'application/json', 'example'], 'The refusal has an example.');
        $owner = $this->createUserWithRole('openapi-runner-owner', 'admin');
        $group = $this->createRunnerGroup((int)$owner->id);
        $runner = $this->createRunner((int)$group->id, (int)$owner->id);
        $this->authenticateAs($this->createUserWithRole('openapi-runner-operator', 'operator'));
        $auditBefore = (int)AuditLog::find()->max('id');
        $id = $controller === 'groups' ? (int)$group->id : (int)$runner->id;

        foreach ([$id, $id + 100000] as $target) {
            $this->setBody(['name' => 'openapi-renamed', 'target_group_id' => (int)$group->id]);
            $answer = $this->call($controller, $action, str_contains($path, '{id}') ? ['id' => $target] : []);
            $this->assertSame(403, \Yii::$app->response->statusCode, "{$method} {$path}");
            $this->assertSame($documented, $answer, "{$method} {$path} answers as the spec documents.");
        }
        $this->assertSame($auditBefore, (int)AuditLog::find()->max('id'), 'Nothing is audited.');
        $stored = Runner::findOne($runner->id);
        $this->assertNotNull($stored, 'The runner stays.');
        $this->assertSame($runner->token_hash, $stored->token_hash, 'Its token stays.');
        $this->assertSame((int)$group->id, (int)$stored->runner_group_id, 'It stays in its group.');
        $this->assertSame($group->name, RunnerGroup::findOne($group->id)?->name, 'The group stays.');
    }

    /**
     * A user without runner-group.view, here one without any role, is
     * refused the reads with 403, as each read documents it.
     *
     * @dataProvider readProvider
     */
    public function testAReadWithoutRunnerGroupViewIsRefusedAsDocumented(string $path, string $method, string $controller, string $action): void
    {
        $this->assertSame('#/components/responses/Forbidden', $this->dig($this->spec(), ['paths', $path, $method, 'responses', '403', '$ref'], "{$method} {$path} documents its 403."));
        $owner = $this->createUserWithRole('openapi-runner-reader-owner', 'admin');
        $group = $this->createRunnerGroup((int)$owner->id);
        $runner = $this->createRunner((int)$group->id, (int)$owner->id);
        $this->authenticateAs($this->createUser('openapi-runner-nobody'));

        $answer = $this->call($controller, $action, str_contains($path, '{id}') ? ['id' => $controller === 'groups' ? (int)$group->id : (int)$runner->id] : []);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $answer);
    }

    /**
     * The tags say who manages runner groups and runners and why, and the
     * refusal says what each write needs; only regenerate-token answers with
     * a token, which the old token no longer opens.
     */
    public function testTheTagsTheRefusalAndTheTokenSayWhatTheCodeDoes(): void
    {
        $tags = [];
        foreach ((array)($this->spec()['tags'] ?? []) as $tag) {
            $this->assertIsArray($tag);
            $tags[(string)($tag['name'] ?? '')] = trim((string)preg_replace('/\s+/', ' ', (string)($tag['description'] ?? '')));
        }
        foreach (['`runner-group.view`, which every built-in role holds', 'need `runner-group.create`, `runner-group.update` and `runner-group.delete`, ' . self::ADMINS_ONLY, 'runner groups have no team scope', 'whoever holds `runner-group.update` can take over the jobs of every runner group', 'A runner group has no token: each runner has one of its own. Self-registration with the bootstrap secret, through the runner API outside `/api/v1/`, creates the group `default` for a runner that names no group, where no group has that name.'] as $phrase) {
            $this->assertStringContainsString($phrase, $tags['Runner Groups'] ?? '', 'The Runner Groups tag');
        }
        foreach (['Moving and deleting runners and regenerating their tokens need `runner-group.update`, ' . self::ADMINS_ONLY, 'No response of these endpoints carries a token, except that of regenerate-token.'] as $phrase) {
            $this->assertStringContainsString($phrase, $tags['Runners'] ?? '', 'The Runners tag');
        }
        $refusal = $this->textOf(['components', 'responses', 'RunnerManagementForbidden', 'description']);
        foreach (['`runner-group.create` to create a runner group, `runner-group.delete` to delete one, and `runner-group.update` to change one and to move or delete a runner or regenerate its token.', 'The check comes first, before the `404` of an unknown ID: nothing is changed or audited, and no token is returned.'] as $phrase) {
            $this->assertStringContainsString($phrase, $refusal);
        }
        $this->assertSame(['runner-group.create', 'runner-group.delete', 'runner-group.update'], [$this->rulesOf('groups')['create'] ?? null, $this->rulesOf('groups')['delete'] ?? null, $this->rulesOf('groups')['update'] ?? null]);

        $runnerProperties = array_keys((array)($this->schema('Runner')['properties'] ?? []));
        $this->assertSame([], array_values(array_filter($runnerProperties, static fn (int|string $name): bool => str_contains((string)$name, 'token'))), 'A runner carries no token.');
        $owner = $this->createUserWithRole('openapi-runner-token-owner', 'admin');
        $runner = $this->createRunner((int)$this->createRunnerGroup((int)$owner->id)->id, (int)$owner->id);
        $old = Runner::generateToken();
        $runner->token_hash = $old['hash'];
        $runner->save(false);
        $this->authenticateAs($owner);
        $answer = $this->call('runners', 'regenerate-token', ['id' => (int)$runner->id]);
        $this->assertIsArray($answer);
        $token = $this->data($answer)['token'] ?? null;
        $this->assertIsString($token);
        $this->assertSame((int)$runner->id, Runner::findByToken($token)?->id, 'The new token opens the runner.');
        $this->assertNull(Runner::findByToken($old['raw']), 'The old token stops working at once.');
        $this->assertStringContainsString(
            'Returns the new raw token (shown once); the old token stops working at once. Whoever holds the token receives the jobs of the runner\'s group with their decrypted credentials.',
            $this->textOf(['paths', '/api/v1/runners/{id}/regenerate-token', 'post', 'description'])
        );
    }

    /**
     * The permission each action of the runner groups ('groups') or runners
     * controller requires.
     *
     * @return array<string, string>
     */
    private function rulesOf(string $controller): array
    {
        $ctrl = $this->controller($controller);
        $rules = (new \ReflectionMethod($ctrl, 'apiAccessRules'))->invoke($ctrl);
        $this->assertIsArray($rules);

        return array_map('strval', $rules);
    }

    private function controller(string $controller): RunnerGroupsController|RunnersController
    {
        return $controller === 'groups'
            ? new RunnerGroupsController('api/v1/runner-groups', $this->app())
            : new RunnersController('api/v1/runners', $this->app());
    }

    /**
     * Runs $action as a request does, and gives its answer: what the action
     * returns, or what the permission check put in the response.
     *
     * @param array<string, int> $params
     */
    private function call(string $controller, string $action, array $params = []): mixed
    {
        \Yii::$app->response->statusCode = 200;
        \Yii::$app->response->data = null;
        $result = $this->controller($controller)->runAction($action, $params);

        return $result ?? \Yii::$app->response->data;
    }

    private function authenticateAs(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'openapi-runner-token');
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
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    /**
     * The text at $keys of the spec, on one line.
     *
     * @param list<string> $keys
     */
    private function textOf(array $keys): string
    {
        $text = $this->dig($this->spec(), $keys, 'web/openapi.yaml has no ' . implode('.', $keys) . '.');
        $this->assertIsString($text);

        return trim((string)preg_replace('/\s+/', ' ', $text));
    }
}
