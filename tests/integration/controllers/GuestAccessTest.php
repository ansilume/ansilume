<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use yii\filters\VerbFilter;
use yii\helpers\Inflector;
use yii\helpers\Url;
use yii\rbac\BaseManager;
use yii\web\Controller;
use yii\web\Response;

/**
 * A visitor who has not signed in reaches the public pages only. A durable
 * guard of the web controllers' access rules against the default role:
 * - the production default role (config/web.php) is active, as on a real
 *   installation, where every signed-in user holds it;
 * - every action of every web controller under controllers/ runs its real
 *   filters for a signed-out visitor and must send them to the login page,
 *   unless PUBLIC lists the action with the reason; the API under
 *   controllers/api/ authenticates with tokens and refuses guests itself;
 * - a PUBLIC action lets the visitor through, so the list stays exact;
 * - the completeness checks fail for a file under controllers/ that the scan
 *   does not cover, for a PUBLIC entry that names no action, and for a
 *   controller the web application reaches outside the scan.
 *
 * In dev mode (YII_ENV=dev) config/web.php also loads the debug and gii
 * modules, which signed-out visitors reach as well (allowedIPs '*'): this
 * test runs with YII_ENV=test and does not see them, so a dev stack must
 * never be reachable by others.
 *
 * regression: signed-out visitors were let in wherever an access rule named
 * a viewer permission (credential/index, project/index, runner-group/index,
 * approval-rule/index, analytics/index and their view pages):
 * yii\rbac\DbManager applied the default role to the user ID null of a
 * visitor who had not signed in. app\components\AuthManager applies it to
 * signed-in users only.
 */
final class GuestAccessTest extends WebControllerTestCase
{
    /** Why the E2E hooks are public. */
    private const E2E_HOOK = 'Playwright drives server-side triggers through it without a session; it answers 404 unless YII_DEBUG is on, and acts on e2e- records only (E2eController).';

    /** What a signed-out visitor may reach: route => why. */
    private const PUBLIC = [
        'site/login' => 'The sign-in form.',
        'site/verify-totp' => 'The second step of signing in with TOTP; it needs the pending sign-in that site/login keeps in the session.',
        'site/forgot-password' => 'Asks for a password reset mail.',
        'site/reset-password' => 'Sets a new password with the token of that mail.',
        'site/error' => 'The error page, which any visitor may be shown.',
        'trigger/fire' => 'An inbound trigger authenticates with the job template\'s trigger token, not with a session.',
        'trigger/fire-workflow' => 'An inbound trigger authenticates with the workflow template\'s trigger token, not with a session.',
        'trigger/not-found' => 'Answers 404 for every other path under trigger/.',
        'health/index' => 'Load balancers and monitoring probe it without credentials.',
        'metrics/index' => 'Monitoring scrapes it without credentials; it reports counts, never names (MetricsController: restrict it by network policy).',
        'e2e/fire-schedule' => self::E2E_HOOK,
        'e2e/create-cancelable-job' => self::E2E_HOOK,
        'e2e/seed-syncing-project' => self::E2E_HOOK,
        'e2e/create-deleted-template-request' => self::E2E_HOOK,
    ];

    /** The files under controllers/, outside api/, that hold no controller: path => why. */
    private const NOT_CONTROLLERS = [
        'BaseController.php' => 'The abstract base class of the web controllers.',
        'traits/TeamScopingTrait.php' => 'A trait of the web controllers.',
    ];

    /** The pages a signed-in user without a role reaches through the default role. */
    private const DEFAULT_ROLE_ROUTES = ['credential/index', 'project/index', 'runner-group/index', 'approval-rule/index', 'analytics/index'];

    /** What a browser asks for when it opens a page. */
    private const BROWSER_ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

    private const PASSED = 'passed the filters';
    private const LOGIN = 'sent to the login page';

    /** @var string[] */
    private array $savedDefaultRoles = [];

    /** @var list<array{global: string, name: string, set: bool, value: mixed}> what this test changed in $_ENV and $_SERVER */
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        parent::setUp();
        $auth = $this->auth();
        $this->savedDefaultRoles = $auth->getDefaultRoles();
        $defaultRoles = $this->webComponent('authManager')['defaultRoles'] ?? null;
        $this->assertIsArray($defaultRoles);
        $this->assertNotEmpty($defaultRoles, 'precondition: the web application gives signed-in users a default role');
        $auth->setDefaultRoles($defaultRoles);

        // YII_DEBUG on, as on the dev and E2E stacks, so that the E2E hooks
        // are open as well and must be listed; and a browser's request.
        $this->setGlobal('ENV', 'YII_DEBUG', '1');
        $this->setGlobal('SERVER', 'HTTP_ACCEPT', self::BROWSER_ACCEPT);
        $this->setGlobal('SERVER', 'HTTP_X_REQUESTED_WITH', null);
        $this->setGlobal('SERVER', 'REQUEST_METHOD', 'GET');

        $loginUrl = $this->webComponent('user')['loginUrl'] ?? null;
        $this->assertSame($loginUrl, \Yii::$app->user->loginUrl, 'precondition: the login page of config/web.php');
    }

    protected function tearDown(): void
    {
        $this->auth()->setDefaultRoles($this->savedDefaultRoles);
        foreach (array_reverse($this->savedGlobals) as $saved) {
            self::writeGlobal($saved['global'], $saved['name'], $saved['set'], $saved['value']);
        }
        $this->savedGlobals = [];
        parent::tearDown();
    }

    /**
     * One case per web controller under controllers/.
     *
     * @return array<string, array{0: class-string<Controller>, 1: string}>
     */
    public static function controllerProvider(): array
    {
        $cases = [];
        foreach (self::controllers() as $id => $class) {
            $cases[$id] = [$class, $id];
        }

        return $cases;
    }

    /**
     * regression: signed-out visitors passed every access rule that names a
     * viewer permission and read the credentials, the projects, the runner
     * groups, the approval rules and more. Every action of every web
     * controller sends a signed-out visitor to the login page, unless it is
     * PUBLIC, which lets them through.
     *
     * @param class-string<Controller> $class
     * @dataProvider controllerProvider
     */
    public function testRegressionASignedOutVisitorIsSentToTheLoginPage(string $class, string $id): void
    {
        $this->assertTrue(\Yii::$app->user->getIsGuest(), 'precondition: nobody has signed in');
        $controller = $this->controller($class, $id);
        $actionIds = $this->actionIds($controller);
        $this->assertNotEmpty($actionIds, "{$class} has actions");

        $wrong = [];
        foreach ($actionIds as $actionId) {
            $route = $id . '/' . $actionId;
            $expected = isset(self::PUBLIC[$route]) ? self::PASSED : self::LOGIN;
            $outcome = $this->outcome($controller, $actionId);
            if ($outcome !== $expected) {
                $wrong[$route] = sprintf('%s, expected: %s', $outcome, $expected);
            }
        }

        $this->assertSame([], $wrong, 'what the filters did with a signed-out visitor');
    }

    /**
     * A control: the default role is active here, and a signed-in user
     * without a role still holds it, so the guard above tests what a real
     * installation does.
     */
    public function testASignedInUserWithoutARoleStillHoldsTheDefaultRole(): void
    {
        $user = $this->createUser('guest_access_roleless');
        $this->assertSame([], $this->auth()->getAssignments((string)$user->id), 'precondition: no role is assigned');
        $this->loginAs($user);
        $controllers = self::controllers();

        foreach (self::DEFAULT_ROLE_ROUTES as $route) {
            [$id, $actionId] = explode('/', $route);
            $this->assertArrayHasKey($id, $controllers);
            $controller = $this->controller($controllers[$id], $id);
            $this->assertSame(self::PASSED, $this->outcome($controller, $actionId), $route);
        }
    }

    /**
     * Every file under controllers/, outside api/, is a web controller the
     * scan covers, or listed in NOT_CONTROLLERS with the reason.
     */
    public function testEveryFileUnderControllersIsScanned(): void
    {
        $files = self::controllerFiles();
        foreach (array_keys(self::NOT_CONTROLLERS) as $relative) {
            $this->assertContains($relative, $files, "NOT_CONTROLLERS lists {$relative}, which does not exist");
            $this->assertFalse(self::isWebController(self::classOf($relative)), "{$relative} is a controller: remove it from NOT_CONTROLLERS");
        }
        $scanned = array_values(self::controllers());
        foreach ($files as $relative) {
            if (isset(self::NOT_CONTROLLERS[$relative])) {
                continue;
            }
            $class = self::classOf($relative);
            $this->assertTrue(self::isWebController($class), "{$relative} is no web controller: list it in NOT_CONTROLLERS with the reason");
            $this->assertContains($class, $scanned, "{$relative} is not scanned");
        }
        $this->assertContains('app\controllers\CredentialController', $scanned, 'precondition: the scan finds the controllers');
    }

    /**
     * Every PUBLIC entry names an action of a scanned controller.
     */
    public function testEveryPublicEntryNamesAnAction(): void
    {
        $controllers = self::controllers();
        foreach (array_keys(self::PUBLIC) as $route) {
            [$id, $actionId] = explode('/', $route);
            $this->assertArrayHasKey($id, $controllers, "PUBLIC lists {$route}, but no controller {$id} is scanned");
            $this->assertContains($actionId, $this->actionIds($this->controller($controllers[$id], $id)), "PUBLIC lists {$route}, which is no action");
        }
    }

    /**
     * The web application reaches no controller outside the scan: it loads
     * no module, and its controllerMap maps only the token-authenticated
     * API controllers (controllers/api/) and scanned controllers.
     */
    public function testTheWebApplicationReachesNoControllerOutsideTheScan(): void
    {
        $config = self::webConfig();
        $this->assertArrayNotHasKey('modules', $config, 'a module brings controllers this guard does not scan');
        $this->assertSame('app\controllers', $config['controllerNamespace'] ?? 'app\controllers');
        $scanned = array_values(self::controllers());
        $map = $config['controllerMap'] ?? [];
        $this->assertIsArray($map);
        foreach ($map as $id => $target) {
            $class = is_array($target) ? ($target['class'] ?? null) : $target;
            $this->assertIsString($class, "controllerMap {$id}");
            if (!str_starts_with($class, 'app\controllers\api\\')) {
                $this->assertContains($class, $scanned, "controllerMap {$id} maps a controller the scan does not cover");
            }
        }
    }

    /**
     * What the filters of $controller do with a signed-out visitor's request
     * for $actionId, sent with the HTTP method the action accepts: PASSED,
     * LOGIN or what else happened.
     */
    private function outcome(Controller $controller, string $actionId): string
    {
        /** @var \yii\base\Action<Controller>|null $action */
        $action = $controller->createAction($actionId);
        if ($action === null) {
            return 'no such action';
        }
        $_SERVER['REQUEST_METHOD'] = $this->methodOf($controller, $actionId);
        \Yii::$app->set('response', new Response());
        try {
            $passed = $controller->beforeAction($action);
        } catch (\Throwable $e) {
            return sprintf('%s: %s', get_class($e), $e->getMessage());
        }
        if ($passed) {
            return self::PASSED;
        }
        /** @var Response $response */
        $response = \Yii::$app->getResponse();

        return $this->isRedirectToTheLoginPage($response)
            ? self::LOGIN
            : sprintf('refused with %d, Location %s', $response->getStatusCode(), (string)$response->getHeaders()->get('Location'));
    }

    private function isRedirectToTheLoginPage(Response $response): bool
    {
        $location = $response->getHeaders()->get('Location');
        $loginUrl = \Yii::$app->user->loginUrl;
        $this->assertIsArray($loginUrl);
        $loginRoute = $loginUrl;
        $loginRoute[0] = '/' . ltrim((string)$loginRoute[0], '/');

        return $response->getStatusCode() === 302
            && is_string($location)
            && parse_url($location, PHP_URL_PATH) === Url::to($loginRoute)
            && parse_url($location, PHP_URL_QUERY) === null;
    }

    /**
     * The HTTP method the action accepts: the first one its VerbFilter
     * allows, else GET.
     */
    private function methodOf(Controller $controller, string $actionId): string
    {
        foreach ($controller->getBehaviors() as $behavior) {
            if ($behavior instanceof VerbFilter) {
                $allowed = $behavior->actions[$actionId] ?? $behavior->actions['*'] ?? null;
                if (is_array($allowed) && $allowed !== []) {
                    return strtoupper((string)reset($allowed));
                }
            }
        }

        return 'GET';
    }

    /**
     * @return list<string> the IDs of the actions of $controller: actions() and its action methods
     */
    private function actionIds(Controller $controller): array
    {
        $ids = array_map('strval', array_keys($controller->actions()));
        foreach ((new \ReflectionClass($controller))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (preg_match('/^action([A-Z]\w*)$/', $method->getName(), $name) === 1) {
                $ids[] = Inflector::camel2id($name[1]);
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param class-string<Controller> $class
     */
    private function controller(string $class, string $id): Controller
    {
        return new $class($id, \Yii::$app);
    }

    private function auth(): BaseManager
    {
        $auth = \Yii::$app->getAuthManager();
        $this->assertInstanceOf(BaseManager::class, $auth);

        return $auth;
    }

    /**
     * Sets $_ENV[$name] ($global 'ENV') or $_SERVER[$name] ('SERVER') for
     * this test; null unsets it. tearDown() restores what was there.
     */
    private function setGlobal(string $global, string $name, ?string $value): void
    {
        $globals = $global === 'ENV' ? $_ENV : $_SERVER;
        $this->savedGlobals[] = [
            'global' => $global,
            'name' => $name,
            'set' => array_key_exists($name, $globals),
            'value' => $globals[$name] ?? null,
        ];
        self::writeGlobal($global, $name, $value !== null, $value);
    }

    private static function writeGlobal(string $global, string $name, bool $set, mixed $value): void
    {
        if ($global === 'ENV') {
            if ($set) {
                $_ENV[$name] = $value;
            } else {
                unset($_ENV[$name]);
            }

            return;
        }
        if ($set) {
            $_SERVER[$name] = $value;
        } else {
            unset($_SERVER[$name]);
        }
    }

    /**
     * The web controllers the scan covers: controller ID => class, from every
     * file under controllers/ outside api/ that NOT_CONTROLLERS does not list.
     *
     * @return array<string, class-string<Controller>>
     */
    private static function controllers(): array
    {
        $controllers = [];
        foreach (self::controllerFiles() as $relative) {
            if (isset(self::NOT_CONTROLLERS[$relative])) {
                continue;
            }
            /** @var class-string<Controller> $class */
            $class = self::classOf($relative);
            $directory = dirname($relative);
            $prefix = $directory === '.' ? '' : $directory . '/';
            $controllers[$prefix . Inflector::camel2id(basename($relative, 'Controller.php'))] = $class;
        }
        ksort($controllers);

        return $controllers;
    }

    /**
     * @return list<string> every PHP file under controllers/ outside api/, relative to controllers/
     */
    private static function controllerFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/controllers/';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($root));
            if ($file->isFile() && str_ends_with($relative, '.php') && !str_starts_with($relative, 'api/')) {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }

    private static function classOf(string $relative): string
    {
        return 'app\controllers\\' . str_replace('/', '\\', substr($relative, 0, -strlen('.php')));
    }

    private static function isWebController(string $class): bool
    {
        return class_exists($class)
            && is_subclass_of($class, Controller::class)
            && !(new \ReflectionClass($class))->isAbstract();
    }

    /**
     * @return array<string, mixed> the configuration of the component $id in config/web.php
     */
    private function webComponent(string $id): array
    {
        $components = self::webConfig()['components'] ?? null;
        $component = is_array($components) ? ($components[$id] ?? null) : null;
        if (!is_array($component)) {
            $this->fail("config/web.php configures no component {$id}");
        }
        /** @var array<string, mixed> $component */
        return $component;
    }

    /**
     * @return array<string, mixed>
     */
    private static function webConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3) . '/config/web.php';

        return $config;
    }
}
