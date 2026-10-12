<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use app\controllers\api\v1\TriggerTokensController;
use PHPUnit\Framework\TestCase;
use yii\base\InlineAction;
use yii\web\Request;
use yii\web\UrlManager;

/**
 * The URL rules of config/web.php for inbound triggers and the trigger token
 * API, parsed by a UrlManager with the real rules.
 *
 * Regression: only POST /trigger/<lowercase hex> had a rule. GET, a trailing
 * slash or uppercase hex fell through to the route "trigger/<token>", whose
 * "Unable to resolve the request" error wrote the whole token to app.log.
 * Now every verb and both spellings reach trigger/fire (which answers 405 to
 * anything but POST), and any other path under trigger/ reaches
 * trigger/not-found: no route contains a path segment any more.
 */
class TriggerUrlRulesTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private function urlManager(): UrlManager
    {
        return new UrlManager([
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => false,
            'rules' => $this->config()['components']['urlManager']['rules'],
            'cache' => false,
            'baseUrl' => '',
            'scriptUrl' => '/index.php',
        ]);
    }

    /**
     * @return array{components: array{urlManager: array{rules: array<mixed>}}, controllerMap: array<string, string>}
     */
    private function config(): array
    {
        /** @var array{components: array{urlManager: array{rules: array<mixed>}}, controllerMap: array<string, string>} $config */
        $config = require dirname(__DIR__, 3) . '/config/web.php';

        return $config;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function parse(string $verb, string $path): array
    {
        $request = new Request(['enableCsrfValidation' => false, 'cookieValidationKey' => 'test-key']);
        $request->setPathInfo($path);
        $request->getHeaders()->set('X-Http-Method-Override', $verb);
        $parsed = $this->urlManager()->parseRequest($request);
        $this->assertIsArray($parsed, "{$verb} {$path}");
        /** @var array{0: string, 1: array<string, string>} $parsed */
        return $parsed;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, string>}>
     */
    public static function triggerPathProvider(): array
    {
        $upper = strtoupper(self::TOKEN);
        $cases = [
            'POST fire' => ['POST', 'trigger/fire', 'trigger/fire', []],
            'POST fire/' => ['POST', 'trigger/fire/', 'trigger/fire', []],
            'GET fire (405 from the action)' => ['GET', 'trigger/fire', 'trigger/fire', []],
            'POST fire-workflow' => ['POST', 'trigger/fire-workflow', 'trigger/fire-workflow', []],
            'POST fire-workflow/' => ['POST', 'trigger/fire-workflow/', 'trigger/fire-workflow', []],
            'POST <token>/' => ['POST', 'trigger/' . self::TOKEN . '/', 'trigger/fire', ['token' => self::TOKEN]],
            'POST <TOKEN>' => ['POST', 'trigger/' . $upper, 'trigger/fire', ['token' => $upper]],
            'POST <TOKEN>/' => ['POST', 'trigger/' . $upper . '/', 'trigger/fire', ['token' => $upper]],
        ];
        foreach (['POST', 'GET', 'PUT', 'DELETE', 'HEAD'] as $verb) {
            $cases["{$verb} <token>"] = [$verb, 'trigger/' . self::TOKEN, 'trigger/fire', ['token' => self::TOKEN]];
        }

        return $cases;
    }

    /**
     * @dataProvider triggerPathProvider
     * @param array<string, string> $params
     */
    public function testTriggerPathsReachTheTriggerActions(string $verb, string $path, string $route, array $params): void
    {
        $this->assertSame([$route, $params], $this->parse($verb, $path));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function otherTriggerPathProvider(): array
    {
        return [
            'not hex' => ['POST', 'trigger/not-a-token'],
            'one character short' => ['POST', 'trigger/' . substr(self::TOKEN, 1)],
            'one character long' => ['GET', 'trigger/' . self::TOKEN . 'a'],
            'token after the action' => ['POST', 'trigger/fire/' . self::TOKEN],
            'more path after the token' => ['DELETE', 'trigger/' . self::TOKEN . '/x'],
            'action with a suffix' => ['POST', 'trigger/fire-workflowx'],
        ];
    }

    /**
     * @dataProvider otherTriggerPathProvider
     */
    public function testAnyOtherPathUnderTriggerReachesTheNotFoundAction(string $verb, string $path): void
    {
        [$route] = $this->parse($verb, $path);

        $this->assertSame('trigger/not-found', $route, 'the route never holds the path');
    }

    public function testTheTriggerUrlsAreCreatedWithoutAToken(): void
    {
        $manager = $this->urlManager();

        $this->assertSame('/trigger/fire', $manager->createUrl(['trigger/fire']));
        $this->assertSame('/trigger/fire-workflow', $manager->createUrl(['trigger/fire-workflow']));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function tokenApiProvider(): array
    {
        return [
            'generate, job template' => ['POST', 'api/v1/job-templates/42/trigger-token', 'api/v1/trigger-tokens/generate-for-job-template'],
            'revoke, job template' => ['DELETE', 'api/v1/job-templates/42/trigger-token', 'api/v1/trigger-tokens/revoke-for-job-template'],
            'generate, workflow template' => ['POST', 'api/v1/workflow-templates/42/trigger-token', 'api/v1/trigger-tokens/generate-for-workflow-template'],
            'revoke, workflow template' => ['DELETE', 'api/v1/workflow-templates/42/trigger-token', 'api/v1/trigger-tokens/revoke-for-workflow-template'],
        ];
    }

    /**
     * @dataProvider tokenApiProvider
     */
    public function testTheTokenApiRoutesReachTheirActions(string $verb, string $path, string $route): void
    {
        $this->assertSame([$route, ['id' => '42']], $this->parse($verb, $path));
        $this->assertSame('/' . $path, $this->urlManager()->createUrl([$route, 'id' => 42]));

        [$otherRoute] = $this->parse('GET', $path);
        $this->assertStringStartsNotWith('api/v1/trigger-tokens/', $otherRoute, 'only POST and DELETE');
        [$nonNumeric] = $this->parse($verb, str_replace('42', 'abc', $path));
        $this->assertStringStartsNotWith('api/v1/trigger-tokens/', $nonNumeric);
    }

    public function testTheTokenApiControllerIsMappedWithItsActions(): void
    {
        $config = $this->config();
        $this->assertSame(TriggerTokensController::class, $config['controllerMap']['api/v1/trigger-tokens']);

        $controller = new TriggerTokensController('api/v1/trigger-tokens', \Yii::$app);
        foreach (['generate-for-job-template', 'revoke-for-job-template', 'generate-for-workflow-template', 'revoke-for-workflow-template'] as $id) {
            $this->assertInstanceOf(InlineAction::class, $controller->createAction($id), $id);
        }
    }

    public function testTheNeighbouringRoutesStillWork(): void
    {
        $this->assertSame(['api/v1/job-templates/view', ['id' => '42']], $this->parse('GET', 'api/v1/job-templates/42'));
        $this->assertSame(['api/v1/workflow-templates/launch', ['id' => '42']], $this->parse('POST', 'api/v1/workflow-templates/42/launch'));
        $this->assertSame(['job-template/generate-trigger-token', ['id' => '42']], $this->parse('POST', 'job-template/generate-trigger-token/42'));
        $this->assertSame(['workflow-template/view', []], $this->parse('GET', 'workflow-template/view'));
    }
}
