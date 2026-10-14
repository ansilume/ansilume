<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use PHPUnit\Framework\TestCase;
use yii\base\Application;
use yii\debug\panels\RequestPanel;
use yii\web\Request;
use yii\web\Response;

/**
 * What the yii2-debug request panel (YII_ENV dev, config/debug.php) keeps of
 * a request's secrets in runtime/debug/, where everyone who reaches /debug
 * reads them: nothing.
 *
 * regression: the panel censored only the trigger, API and runner tokens.
 * It kept the raw request body and $_POST (the password of a sign-in, new
 * passwords, credential secrets, survey answers, channel secrets), $_COOKIE
 * and the Cookie header (the session and remember-me cookies, so whoever
 * read them could take over the account), and $_SERVER, which holds every
 * variable of .env (phpdotenv writes them there): APP_SECRET_KEY, the
 * database and Redis passwords, the runner bootstrap secret.
 *
 * The censor list names what the panel saves: `requestBody` (with its Raw
 * and Decoded parts), `POST.<form>.<field>` and `POST.<field>`, `COOKIE`,
 * `SERVER.<name>`, `SESSION.<key>`, `GET.<param>`, `actionParams.<param>`,
 * and request headers in lower case; response headers as PHP sends them
 * (`Set-Cookie`). Assertions use booleans and counts: a failure never prints
 * the saved data.
 */
class DebugCensorTest extends TestCase
{
    /** Canaries: test values, not secrets. Each must be absent from the saved data. */
    private const CANARIES = [
        'login' => 'canary-login-password-5521',
        'body' => 'canary-raw-body-password-1180',
        'new-user' => 'canary-user-form-password-3307',
        'reset' => 'canary-reset-password-9913',
        'reset-confirm' => 'canary-reset-confirm-2271',
        'current' => 'canary-current-password-8812',
        'new' => 'canary-new-password-4470',
        'new-confirm' => 'canary-new-confirm-6618',
        'totp' => 'canary-totp-recovery-code-7734',
        'credential' => 'canary-credential-secret-0091',
        'survey' => 'canary-survey-answer-5530',
        'overrides' => 'canary-launch-extra-vars-6604',
        'schedule' => 'canary-schedule-extra-vars-2219',
        'notification' => 'canary-notification-config-7781',
        'webhook' => 'canary-webhook-secret-3345',
        'scm-url' => 'canary-scm-url-token-1166',
        'api-password' => 'canary-api-password-9902',
        'api-secret' => 'canary-api-secret-4431',
        'api-config' => 'canary-api-config-1278',
        'api-scm-url' => 'canary-api-scm-url-5583',
        'api-extra-vars' => 'canary-api-extra-vars-6617',
        'bootstrap' => 'canary-bootstrap-secret-7720',
        'session-cookie' => 'canary-session-cookie-3390',
        'identity-cookie' => 'canary-identity-cookie-8854',
        'auth-key' => 'canary-session-auth-key-0067',
        'url-token' => 'canary-url-token-4482',
        'action-token' => 'canary-action-token-1931',
        'basic-auth' => 'canary-basic-auth-password-2208',
    ];

    /** The secrets of .env.example, which phpdotenv writes into $_SERVER; each gets a canary. */
    private const ENV_SECRETS = [
        'APP_SECRET_KEY',
        'COOKIE_VALIDATION_KEY',
        'DB_PASSWORD',
        'DB_ROOT_PASSWORD',
        'LDAP_BIND_PASSWORD',
        'REDIS_PASSWORD',
        'RUNNER_BOOTSTRAP_SECRET',
        'SMTP_PASSWORD',
    ];

    /** @var array<string, mixed> */
    private array $savedComponents = [];

    /** @var array<string, array<mixed>|null> the superglobals the test changed */
    private array $savedGlobals = [];

    /** @var array<mixed> */
    private array $savedRequestedParams = [];

    protected function setUp(): void
    {
        foreach (['_SERVER', '_POST', '_COOKIE', '_GET', '_SESSION'] as $name) {
            $this->savedGlobals[$name] = $GLOBALS[$name] ?? null;
        }
        $app = \Yii::$app;
        $this->assertInstanceOf(Application::class, $app);
        $this->savedRequestedParams = $app->requestedParams ?? [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedGlobals['_SERVER'] ?? [];
        $_POST = $this->savedGlobals['_POST'] ?? [];
        $_COOKIE = $this->savedGlobals['_COOKIE'] ?? [];
        $_GET = $this->savedGlobals['_GET'] ?? [];
        if ($this->savedGlobals['_SESSION'] === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->savedGlobals['_SESSION'];
        }
        $app = \Yii::$app;
        $this->assertInstanceOf(Application::class, $app);
        $app->requestedParams = $this->savedRequestedParams;
        foreach ($this->savedComponents as $id => $definition) {
            $app->set($id, $definition);
        }
        $this->savedComponents = [];
    }

    /**
     * regression: a sign-in posted to a dev install left its password, the
     * session cookie and the remember-me cookie in the debug data.
     */
    public function testRegressionTheRequestPanelKeptTheSecretsOfARequest(): void
    {
        $data = $this->saveRequestWithSecrets();

        $saved = serialize($data);
        $leaked = array_keys(array_filter($this->canaries(), static fn (string $canary): bool => str_contains($saved, $canary)));
        $this->assertSame([], $leaked, 'secrets (named by their test key) that reached the debug data');
    }

    /**
     * Censoring names what carries a secret, nothing else: the rest of the
     * request stays readable for debugging.
     */
    public function testTheRestOfTheRequestIsKept(): void
    {
        $data = $this->saveRequestWithSecrets();

        $post = $data['POST'] ?? null;
        $this->assertIsArray($post);
        $this->assertIsArray($post['LoginForm'] ?? null);
        $this->assertSame('e2e-admin', $post['LoginForm']['username'] ?? null);
        $this->assertSame('****', $post['LoginForm']['password'] ?? null);
        $this->assertSame('kept-name', $post['name'] ?? null);
        $server = $data['SERVER'] ?? null;
        $this->assertIsArray($server);
        $this->assertSame('POST', $server['REQUEST_METHOD'] ?? null);
        $this->assertSame('ansilume', $server['DB_NAME'] ?? null, 'a .env variable that is no secret');
        $this->assertSame('****', $server['APP_SECRET_KEY'] ?? null);
        $headers = $data['requestHeaders'] ?? null;
        $this->assertIsArray($headers);
        $this->assertSame('****', $headers['cookie'] ?? null);
        $this->assertSame('text/html', $headers['accept'] ?? null);
        $this->assertSame('****', $data['COOKIE'] ?? null);
    }

    /**
     * Every secret of .env.example reaches $_SERVER (phpdotenv writes it
     * there) and is censored there: a secret added to .env.example fails here
     * until config/debug.php censors it.
     */
    public function testEverySecretOfTheEnvironmentIsCensored(): void
    {
        $example = file_get_contents(dirname(__DIR__, 3) . '/.env.example');
        $this->assertIsString($example);
        preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=/m', $example, $matches);
        $secrets = array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $name): bool => (bool)preg_match('/PASSWORD|SECRET|TOKEN|_KEY$/', $name)
        )));

        sort($secrets);
        $this->assertSame(self::ENV_SECRETS, $secrets, 'the secrets of .env.example, which the regression test plants');
        $missing = array_values(array_diff(array_map(static fn (string $name): string => 'SERVER.' . $name, $secrets), $this->censored()));
        $this->assertSame([], $missing, 'secrets of .env.example the request panel does not censor');
    }

    /**
     * The response's Set-Cookie header carries the session cookie that a
     * sign-in starts. The panel takes response headers from headers_list(),
     * which is empty without a web server, so this checks the name as PHP
     * sends it.
     */
    public function testTheResponseCookieHeaderIsCensored(): void
    {
        $this->assertContains('Set-Cookie', $this->censored());
    }

    /**
     * What the configured request panel saves for a POST with secrets in
     * every place the panel reads.
     *
     * @return array<string, mixed>
     */
    private function saveRequestWithSecrets(): array
    {
        $c = self::CANARIES;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_SERVER['HTTP_COOKIE'] = 'PHPSESSID=' . $c['session-cookie'] . '; _identity=' . $c['identity-cookie'];
        $_SERVER['PHP_AUTH_PW'] = $c['basic-auth'];
        $_SERVER['DB_NAME'] = 'ansilume';
        foreach (self::ENV_SECRETS as $name) {
            $_SERVER[$name] = $this->canaries()[$name];
        }
        $_COOKIE = ['PHPSESSID' => $c['session-cookie'], '_identity' => $c['identity-cookie']];
        $_GET = ['token' => $c['url-token']];
        $_SESSION = ['__id' => 7, '__authKey' => $c['auth-key']];
        $_POST = [
            'LoginForm' => ['username' => 'e2e-admin', 'password' => $c['login']],
            'UserForm' => ['password' => $c['new-user']],
            'PasswordResetForm' => ['password' => $c['reset'], 'password_confirm' => $c['reset-confirm']],
            'ChangePasswordForm' => ['current_password' => $c['current'], 'new_password' => $c['new'], 'new_password_confirm' => $c['new-confirm']],
            'TotpVerifyForm' => ['code' => $c['totp']],
            'TotpSetupForm' => ['code' => $c['totp']],
            'TotpDisableForm' => ['code' => $c['totp']],
            'secrets' => ['password' => $c['credential'], 'private_key' => $c['credential']],
            'survey' => ['db_password' => $c['survey']],
            'overrides' => ['extra_vars' => '{"db_password":"' . $c['overrides'] . '"}'],
            'Schedule' => ['extra_vars' => '{"db_password":"' . $c['schedule'] . '"}'],
            'NotificationTemplate' => ['config' => '{"bot_token":"' . $c['notification'] . '"}'],
            'Webhook' => ['secret' => $c['webhook']],
            'Project' => ['scm_url' => 'https://deploy:' . $c['scm-url'] . '@git.example.com/r.git'],
            'password' => $c['api-password'],
            'secret' => $c['api-secret'],
            'config' => ['routing_key' => $c['api-config']],
            'scm_url' => 'https://x:' . $c['api-scm-url'] . '@git.example.com/r.git',
            'extra_vars' => '{"db_password":"' . $c['api-extra-vars'] . '"}',
            'bootstrap_secret' => $c['bootstrap'],
            'name' => 'kept-name',
        ];
        $app = \Yii::$app;
        $this->assertInstanceOf(Application::class, $app);
        $app->requestedParams = ['token' => $c['action-token']];
        $request = new Request(['cookieValidationKey' => 'test-key']);
        $request->setRawBody('LoginForm%5Bpassword%5D=' . $c['body']);
        $request->setBodyParams($_POST);
        $this->install('request', $request);
        $this->install('response', new Response());

        $data = $this->panel()->save();
        $this->assertIsArray($data);
        $named = [];
        foreach ($data as $key => $value) {
            $named[(string)$key] = $value;
        }

        return $named;
    }

    /**
     * CANARIES and a canary for each of ENV_SECRETS.
     *
     * @return array<string, string>
     */
    private function canaries(): array
    {
        $canaries = self::CANARIES;
        foreach (self::ENV_SECRETS as $name) {
            $canaries[$name] = 'canary-env-' . strtolower($name);
        }

        return $canaries;
    }

    private function panel(): RequestPanel
    {
        $config = require dirname(__DIR__, 3) . '/config/debug.php';
        $this->assertIsArray($config);
        $this->assertIsArray($config['panels']['request'] ?? null);
        $panel = \Yii::createObject(['id' => 'request'] + $config['panels']['request']);
        $this->assertInstanceOf(RequestPanel::class, $panel);

        return $panel;
    }

    /**
     * @return list<string>
     */
    private function censored(): array
    {
        return array_values(array_map('strval', $this->panel()->censoredVariableNames));
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
