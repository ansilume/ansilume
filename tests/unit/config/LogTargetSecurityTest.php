<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use app\components\RedactingFileTarget;
use PHPUnit\Framework\TestCase;
use yii\base\Application;
use yii\log\FileTarget;
use yii\log\Logger;
use yii\web\IdentityInterface;
use yii\web\Request;
use yii\web\Session;
use yii\web\User;

/**
 * Regression: Yii's default `logVars` on log targets appended the complete
 * $_SERVER context — including APP_SECRET_KEY, DB_PASSWORD, DB_ROOT_PASSWORD,
 * COOKIE_VALIDATION_KEY, and RUNNER_BOOTSTRAP_SECRET from the container
 * environment — to runtime/logs/app.log whenever a log entry was flushed.
 *
 * Every log target in every application config must disable context variable
 * dumping explicitly via `logVars => []` so secrets can never reach log files.
 *
 * The console target keeps SQL statements out of the log, and the web target
 * keeps session IDs out of it.
 *
 * Every log target is a RedactingFileTarget, which writes an exception logged
 * as such with a database error in its chain without its message
 * (tests/integration/components/VendorLoggedDatabaseErrorTest).
 */
class LogTargetSecurityTest extends TestCase
{
    private const SQL_CANARY = 'canary-sql-value-5521';
    private const SESSION_CANARY = 'canarysessionid0123456789abcdefg';

    private string $logFile = '';

    /** @var array<string, mixed> */
    private array $savedComponents = [];

    /** @var array<string, mixed> */
    private array $savedServer = [];

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        foreach ($this->savedComponents as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->savedComponents = [];
        if ($this->logFile !== '' && file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function configFileProvider(): array
    {
        return [
            'web' => ['web.php'],
            'console' => ['console.php'],
            'test' => ['test.php'],
        ];
    }

    /**
     * @dataProvider configFileProvider
     */
    public function testAllLogTargetsDisableContextVariableDumping(string $file): void
    {
        foreach ($this->logTargets($file) as $index => $target) {
            $this->assertArrayHasKey(
                'logVars',
                $target,
                "{$file}: log target #{$index} does not set logVars — Yii's default dumps "
                . '$_SERVER (including all secret env vars) into the log file'
            );
            $this->assertSame(
                [],
                $target['logVars'],
                "{$file}: log target #{$index} must set logVars to an empty array"
            );
        }
    }

    /**
     * Guard: every log target writes an exception that code logs itself
     * (\Yii::error($e), as yii\db\Connection does) without the message of a
     * database error in its chain. Yii's FileTarget, or any other target
     * class, would write it as PHP prints it, the SQL statement with its
     * values included.
     *
     * @dataProvider configFileProvider
     */
    public function testEveryLogTargetWritesDatabaseErrorsWithoutTheirMessages(string $file): void
    {
        foreach ($this->logTargets($file) as $index => $target) {
            $class = $target['class'] ?? null;
            $this->assertIsString($class, "{$file}: log target #{$index} names its class");
            $this->assertTrue(
                is_a($class, RedactingFileTarget::class, true),
                "{$file}: log target #{$index} is {$class}, not " . RedactingFileTarget::class . ' or a subclass of it'
            );
        }
    }

    /**
     * The tests' application logs through the target of config/test.php.
     */
    public function testTheTestApplicationLogsThroughTheRedactingTarget(): void
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Application::class, $app);
        $targets = $app->getLog()->targets;

        $this->assertNotEmpty($targets);
        foreach ($targets as $name => $target) {
            $this->assertInstanceOf(RedactingFileTarget::class, $target, "log target {$name}");
        }
    }

    /**
     * Regression: the console target logged info for every category, and
     * yii\db\Command logs each SQL statement at info with its values filled
     * in. Every job that `schedule/run` launched wrote its extra vars,
     * survey password answers included, to runtime/logs/app.log; so did
     * migrations and the queue worker.
     *
     * The categories are the ones yii\db\Command::logQuery() uses.
     */
    public function testTheConsoleLogKeepsSqlStatementsOut(): void
    {
        $target = $this->fileTarget('console.php');

        $target->collect([
            $this->message(
                "INSERT INTO `job` (`extra_vars`) VALUES ('{\"db_password\":\"" . self::SQL_CANARY . "\"}')",
                Logger::LEVEL_INFO,
                'yii\db\Command::execute'
            ),
            $this->message(
                "SELECT * FROM `user` WHERE `password_reset_token`='" . self::SQL_CANARY . "'",
                Logger::LEVEL_INFO,
                'yii\db\Command::query'
            ),
            $this->message('Schedule #7 launched job #42.', Logger::LEVEL_INFO, 'app\services\ScheduleService::launchSchedule'),
        ], true);

        $written = (string)file_get_contents($this->logFile);
        $this->assertStringNotContainsString(self::SQL_CANARY, $written);
        $this->assertStringNotContainsString('yii\db\Command', $written);
        $this->assertStringContainsString('Schedule #7 launched job #42.', $written);
    }

    /**
     * The console log still has the application's own info lines, warnings,
     * and database errors (logged under the exception's class).
     */
    public function testTheConsoleLogKeepsEverythingElse(): void
    {
        $target = $this->fileTarget('console.php');

        $target->collect([
            $this->message('Schedule #7 launched job #42.', Logger::LEVEL_INFO, 'app\services\ScheduleService::launchSchedule'),
            $this->message('[12] app\jobs\SyncProjectJob (attempt: 1) - Started', Logger::LEVEL_INFO, 'yii\queue\Queue'),
            $this->message('SQLSTATE[HY000] [2002] Connection refused', Logger::LEVEL_ERROR, 'yii\db\Exception'),
            $this->message('Opening DB connection: mysql:host=db;dbname=ansilume', Logger::LEVEL_INFO, 'yii\db\Connection::open'),
            $this->message('Runner heartbeat is late.', Logger::LEVEL_WARNING, 'app\services\RunnerService'),
        ], true);

        $written = (string)file_get_contents($this->logFile);
        $this->assertStringContainsString('[info][app\services\ScheduleService::launchSchedule] Schedule #7 launched job #42.', $written);
        $this->assertStringContainsString('[info][yii\queue\Queue] [12] app\jobs\SyncProjectJob (attempt: 1) - Started', $written);
        $this->assertStringContainsString('[error][yii\db\Exception] SQLSTATE[HY000] [2002] Connection refused', $written);
        $this->assertStringContainsString('[info][yii\db\Connection::open] Opening DB connection', $written);
        $this->assertStringContainsString('[warning][app\services\RunnerService] Runner heartbeat is late.', $written);
    }

    /**
     * Regression: the web target used Yii's default prefix, which puts the
     * session ID into every error and warning line, so anyone who could
     * read app.log could take over the session of the user it names.
     */
    public function testTheWebLogNamesTheClientAndUserButNotTheSession(): void
    {
        $this->installWebRequest('203.0.113.7', 42);
        $target = $this->fileTarget('web.php');

        $target->collect([$this->message('Not found.', Logger::LEVEL_WARNING, 'yii\web\HttpException:404')], true);

        $written = (string)file_get_contents($this->logFile);
        $this->assertStringContainsString(' [203.0.113.7][42][warning][yii\web\HttpException:404] Not found.', $written);
        $this->assertStringNotContainsString(self::SESSION_CANARY, $written);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function logTargets(string $file): array
    {
        $config = require dirname(__DIR__, 3) . '/config/' . $file;

        $this->assertIsArray($config['components'] ?? null, "{$file}: components missing");
        $log = $config['components']['log'] ?? null;
        $this->assertIsArray($log, "{$file}: log component missing");
        $targets = $log['targets'] ?? null;
        $this->assertIsArray($targets, "{$file}: log targets missing");
        $this->assertNotEmpty($targets, "{$file}: log targets empty");
        $list = [];
        foreach ($targets as $index => $target) {
            $this->assertIsArray($target, "{$file}: log target #{$index} is not an array config");
            $list[] = $target;
        }

        return $list;
    }

    /**
     * The only file target of $file, writing to a temporary file instead of
     * runtime/logs/app.log.
     */
    private function fileTarget(string $file): FileTarget
    {
        $targets = $this->logTargets($file);
        $this->assertCount(1, $targets, "{$file}: expected one log target");
        $logFile = tempnam(sys_get_temp_dir(), 'ansilume-log-target-');
        $this->assertIsString($logFile);
        unlink($logFile);
        $this->logFile = $logFile;
        $definition = ['logFile' => $logFile] + $targets[0];
        $this->assertIsString($definition['class'] ?? null, "{$file}: the log target names its class");
        /** @var array{class: class-string} $definition */
        $target = \Yii::createObject($definition);
        $this->assertInstanceOf(FileTarget::class, $target);

        return $target;
    }

    /**
     * @return array{0: string, 1: int, 2: string, 3: float}
     */
    private function message(string $text, int $level, string $category): array
    {
        return [$text, $level, $category, microtime(true)];
    }

    /**
     * Makes the application look like it is serving a web request from $ip
     * for the user $userId, with an active session whose ID is the canary.
     * tearDown() restores the components.
     */
    private function installWebRequest(string $ip, int $userId): void
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        $user = new User(['identityClass' => \app\models\User::class, 'enableSession' => false, 'enableAutoLogin' => false]);
        $user->setIdentity($this->identity($userId));
        $session = new class () extends Session {
            public string $canary = '';

            public function getIsActive()
            {
                return $this->canary !== '';
            }

            public function getId()
            {
                return $this->canary;
            }

            public function close(): void
            {
            }
        };
        $session->canary = self::SESSION_CANARY;
        $this->install('request', new Request(['cookieValidationKey' => 'test-key']));
        $this->install('user', $user);
        $this->install('session', $session);
    }

    /**
     * Installs $component as $id and instantiates it, as a request that used
     * it would have: log prefixes only look at instantiated components.
     */
    private function install(string $id, object $component): void
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Application::class, $app);
        if (!array_key_exists($id, $this->savedComponents)) {
            $this->savedComponents[$id] = $app->getComponents(true)[$id] ?? null;
        }
        $app->set($id, $component);
        $this->assertSame($component, $app->get($id));
    }

    private function identity(int $id): IdentityInterface
    {
        return new class ($id) implements IdentityInterface {
            public function __construct(private int $id)
            {
            }

            public static function findIdentity($id)
            {
                return null;
            }

            public static function findIdentityByAccessToken($token, $type = null)
            {
                return null;
            }

            public function getId()
            {
                return $this->id;
            }

            public function getAuthKey()
            {
                return null;
            }

            public function validateAuthKey($authKey)
            {
                return false;
            }
        };
    }
}
