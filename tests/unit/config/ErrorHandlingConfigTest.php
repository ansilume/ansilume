<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use app\components\ConsoleErrorHandler;
use app\components\QueueLogBehavior;
use app\components\WebErrorHandler;
use PHPUnit\Framework\TestCase;

/**
 * Guard: every application config logs uncaught database errors without
 * their messages. The web and the console use Ansilume's error handlers
 * (Yii's own log the SQL statement with its values), the tests run the
 * console's, and every queue that logs its jobs uses QueueLogBehavior
 * (yii2-queue's LogBehavior logs it too). tests/integration/components/
 * UncaughtDatabaseErrorLogTest checks what they log.
 */
class ErrorHandlingConfigTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->savedEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->savedEnv;
    }

    public function testTheWebUsesItsErrorHandlerAndKeepsTheErrorPage(): void
    {
        $handler = $this->components('web.php')['errorHandler'] ?? null;

        $this->assertSame(['class' => WebErrorHandler::class, 'errorAction' => 'site/error'], $handler);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function consoleConfigProvider(): array
    {
        return ['console' => ['console.php'], 'tests (a console application)' => ['test.php']];
    }

    /**
     * @dataProvider consoleConfigProvider
     */
    public function testTheConsoleUsesItsErrorHandler(string $file): void
    {
        $this->assertSame(['class' => ConsoleErrorHandler::class], $this->components($file)['errorHandler'] ?? null);
    }

    public function testTheQueueWorkerLogsJobsWithTheQueueLogBehavior(): void
    {
        $queue = $this->components('console.php')['queue'] ?? null;

        $this->assertIsArray($queue);
        $this->assertSame(QueueLogBehavior::class, $queue['as log'] ?? null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function configProvider(): array
    {
        return ['web' => ['web.php'], 'console' => ['console.php'], 'test' => ['test.php']];
    }

    /**
     * A queue that gets a log behaviour gets QueueLogBehavior: the web only
     * pushes jobs, and the tests run none, so neither has one now.
     *
     * @dataProvider configProvider
     */
    public function testEveryQueueLogBehaviourIsTheQueueLogBehavior(string $file): void
    {
        $queue = $this->components($file)['queue'] ?? null;
        $this->assertIsArray($queue);

        foreach ($queue as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'as ') && is_a(self::className($value), \yii\queue\LogBehavior::class, true)) {
                $this->assertSame(QueueLogBehavior::class, self::className($value), "{$file}: queue behaviour '{$key}'");
            }
        }
    }

    /**
     * The tests' application runs with the console's error handler, as the
     * console does.
     */
    public function testTheTestApplicationRunsWithTheConsolesErrorHandler(): void
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        $this->assertInstanceOf(ConsoleErrorHandler::class, $app->getErrorHandler());
    }

    /**
     * @return array<string, mixed>
     */
    private function components(string $file): array
    {
        $config = require dirname(__DIR__, 3) . '/config/' . $file;
        $this->assertIsArray($config);
        $components = $config['components'] ?? null;
        $this->assertIsArray($components);

        return $components;
    }

    private static function className(mixed $definition): string
    {
        if (is_array($definition)) {
            $definition = $definition['class'] ?? '';
        }

        return is_string($definition) ? $definition : '';
    }
}
