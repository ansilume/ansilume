<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use PHPUnit\Framework\TestCase;
use yii\base\Application;
use yii\debug\panels\RequestPanel;
use yii\web\Request;
use yii\web\Response;

/**
 * The yii2-debug module (dev and source installs: YII_ENV dev) saves every
 * request's headers, $_SERVER and session to runtime/debug/. Its request
 * panel censors the trigger and API tokens there.
 *
 * The test builds only the request panel from config/debug.php: the whole
 * module would hook every event of the test process. Assertions on the saved
 * data use booleans, so a failure never prints the saved $_SERVER, which
 * holds the environment's secrets.
 *
 * Only the YII_ENV dev branch of config/web.php loads config/debug.php, and
 * PHPUnit runs with YII_ENV test, so a child PHP process evaluates web.php as
 * a dev install does.
 */
class DebugModuleConfigTest extends TestCase
{
    private const TRIGGER_TOKEN = 'canary-trigger-token-77f1e0c2';
    private const API_TOKEN = 'canary-api-token-3b9d51aa';
    private const FLASH_TOKEN = 'canary-flash-token-c41d0e93';

    /**
     * Evaluates config/web.php (the repository root is the first argument)
     * with YII_ENV dev and prints its debug module and bootstrap list as
     * JSON: only those, the rest of the config holds the cookie validation
     * key. YII_ENV is defined before Yii.php, which derives YII_ENV_DEV from
     * it, and Yii is loaded, because web.php configures \Yii::$container
     * while it loads.
     */
    private const DEV_WEB_CONFIG_PROBE = <<<'PHP'
        <?php

        declare(strict_types=1);

        define('YII_ENV', 'dev');
        define('YII_DEBUG', false);
        $root = $argv[1];
        require $root . '/vendor/autoload.php';
        require $root . '/vendor/yiisoft/yii2/Yii.php';
        $config = require $root . '/config/web.php';
        $expected = require $root . '/config/debug.php';
        $debug = $config['modules']['debug'] ?? null;
        echo json_encode([
            'same' => $debug === $expected,
            'debug' => $debug,
            'expected' => $expected,
            'bootstrap' => $config['bootstrap'] ?? null,
        ]);

        PHP;

    /** @var array<string, mixed> */
    private array $savedComponents = [];

    /** @var array<string, mixed> */
    private array $savedServer = [];

    /** @var array<string, mixed>|null */
    private ?array $savedSession = null;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        $this->savedSession = $_SESSION ?? null;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        if ($this->savedSession === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->savedSession;
        }
        foreach ($this->savedComponents as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->savedComponents = [];
    }

    public function testTheDebugModuleIsTheYii2DebugModule(): void
    {
        $config = $this->debugConfig();

        $this->assertSame('yii\debug\Module', $config['class'] ?? null);
    }

    /**
     * Regression guard: config/web.php configured the module inline, without
     * the request panel's censoring. Going back to that, or to any config
     * other than config/debug.php, kept every other test green, while dev and
     * source installs wrote the tokens to runtime/debug/ again.
     */
    public function testTheDevWebConfigLoadsThisDebugConfig(): void
    {
        $result = $this->devWebConfig();

        $this->assertSame($result['expected'] ?? null, $result['debug'] ?? null, 'modules.debug of config/web.php with YII_ENV dev');
        $this->assertTrue($result['same'] ?? null, 'modules.debug is exactly what config/debug.php returns');
        $bootstrap = $result['bootstrap'] ?? null;
        $this->assertIsArray($bootstrap);
        $this->assertContains('debug', $bootstrap, 'the module is bootstrapped, so its panels see every request');
    }

    /**
     * A trigger token sent in X-Trigger-Token and an API token sent as
     * Authorization: Bearer reach the debug data neither as headers nor in
     * the saved $_SERVER.
     */
    public function testTheRequestPanelCensorsTheTokenHeaders(): void
    {
        $data = $this->saveRequest([
            'HTTP_X_TRIGGER_TOKEN' => self::TRIGGER_TOKEN,
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::API_TOKEN,
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $saved = serialize($data);
        $this->assertFalse(str_contains($saved, self::TRIGGER_TOKEN), 'The trigger token reached the debug data.');
        $this->assertFalse(str_contains($saved, self::API_TOKEN), 'The API token reached the debug data.');
        $this->assertSame('****', $this->savedHeader($data, 'x-trigger-token'));
        $this->assertSame('****', $this->savedHeader($data, 'authorization'));
        $this->assertSame('application/json', $this->savedHeader($data, 'accept'), 'other headers are kept');
    }

    /**
     * Generating a trigger token puts it into a flash for the next page,
     * which the panel saves with the session.
     */
    public function testTheRequestPanelCensorsANewTriggerTokenInTheSession(): void
    {
        $_SESSION = ['trigger_token_raw' => self::FLASH_TOKEN, '__flash' => ['trigger_token_raw' => 0]];

        $data = $this->saveRequest([]);

        $this->assertFalse(str_contains(serialize($data), self::FLASH_TOKEN), 'The new trigger token reached the debug data.');
    }

    /**
     * What config/web.php gives a dev install, from a child PHP process
     * ({@see DEV_WEB_CONFIG_PROBE}) with this process's environment, so .env
     * applies as usual.
     *
     * @return array<string, mixed>
     */
    private function devWebConfig(): array
    {
        $root = dirname(__DIR__, 3);
        $probe = tempnam(sys_get_temp_dir(), 'ansilume-dev-web-config-');
        $this->assertIsString($probe);
        try {
            file_put_contents($probe, self::DEV_WEB_CONFIG_PROBE);
            $process = proc_open(
                [PHP_BINARY, $probe, $root],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
                getenv()
            );
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $stdout = (string)stream_get_contents($pipes[1]);
            $stderr = (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), "config/web.php did not load with YII_ENV dev: {$stderr}");
        } finally {
            unlink($probe);
        }
        $result = json_decode($stdout, true);
        $this->assertIsArray($result, 'the probe printed JSON');

        $named = [];
        foreach ($result as $key => $value) {
            $named[(string)$key] = $value;
        }

        return $named;
    }

    /**
     * @return array<string, mixed>
     */
    private function debugConfig(): array
    {
        $config = require dirname(__DIR__, 3) . '/config/debug.php';
        $this->assertIsArray($config);
        $result = [];
        foreach ($config as $key => $value) {
            $result[(string)$key] = $value;
        }

        return $result;
    }

    /**
     * What the configured request panel saves for a web request with the
     * $_SERVER entries $server.
     *
     * @param array<string, string> $server
     * @return array<string, mixed>
     */
    private function saveRequest(array $server): array
    {
        $panels = $this->debugConfig()['panels'] ?? null;
        $this->assertIsArray($panels);
        $this->assertIsArray($panels['request'] ?? null);
        $panel = \Yii::createObject(['id' => 'request'] + $panels['request']);
        $this->assertInstanceOf(RequestPanel::class, $panel);
        foreach ($server as $name => $value) {
            $_SERVER[$name] = $value;
        }
        $this->install('request', new Request(['cookieValidationKey' => 'test-key']));
        $this->install('response', new Response());

        $data = $panel->save();
        $this->assertIsArray($data);
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string)$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function savedHeader(array $data, string $name): mixed
    {
        $headers = $data['requestHeaders'] ?? null;
        $this->assertIsArray($headers);
        $this->assertArrayHasKey($name, $headers);

        return $headers[$name];
    }

    private function install(string $id, object $component): void
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Application::class, $app);
        if (!array_key_exists($id, $this->savedComponents)) {
            $this->savedComponents[$id] = $app->getComponents(true)[$id] ?? null;
        }
        $app->set($id, $component);
    }
}
