<?php

declare(strict_types=1);

namespace app\tests\unit\services\audit;

use app\services\AuditService;
use app\services\audit\AuditTargetInterface;
use PHPUnit\Framework\TestCase;
use yii\log\Logger;

class AuditServiceDispatchTest extends TestCase
{
    public function testLogDispatchesToAllTargets(): void
    {
        $targetA = $this->createMock(AuditTargetInterface::class);
        $targetB = $this->createMock(AuditTargetInterface::class);

        $targetA->expects($this->once())->method('send');
        $targetB->expects($this->once())->method('send');

        $service = new AuditService();
        $service->targets = [$targetA, $targetB];

        $service->log('test.dispatch', 'test', 1);
    }

    public function testFailingTargetDoesNotBlockOthers(): void
    {
        $badTarget  = $this->createMock(AuditTargetInterface::class);
        $goodTarget = $this->createMock(AuditTargetInterface::class);

        $badTarget->method('send')->willThrowException(new \RuntimeException('fail'));
        $goodTarget->expects($this->once())->method('send');

        $service = new AuditService();
        $service->targets = [$badTarget, $goodTarget];

        // Should not throw — bad target failure is logged, good target still runs
        $service->log('test.resilience', 'test', 1);
    }

    public function testLogBuildsCorrectEntryShape(): void
    {
        $captured = null;
        $target   = $this->createMock(AuditTargetInterface::class);
        $target->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (array $entry) use (&$captured) {
                $captured = $entry;
            });

        $service = new AuditService();
        $service->targets = [$target];

        $service->log('user.created', 'user', 5, 99, ['username' => 'alice']);

        $this->assertIsArray($captured);
        $this->assertSame('user.created', $captured['action']);
        $this->assertSame('user', $captured['object_type']);
        $this->assertSame(5, $captured['object_id']);
        $this->assertSame(99, $captured['user_id']);
        $this->assertIsInt($captured['created_at']);

        $meta = json_decode($captured['metadata'], true);
        $this->assertIsArray($meta);
        $this->assertSame('alice', $meta['username']);
    }

    public function testEmptyTargetsDoesNotThrow(): void
    {
        $service = new AuditService();
        $service->targets = [];

        $service->log('test.no_targets');
        $this->assertTrue(true); // no exception = pass
    }

    /**
     * Regression: a failing audit target was logged with the exception's
     * message. The database target's error carries the INSERT statement with
     * the entry's values, its metadata among them, so those values ended up
     * in the application log. A database error is now logged with its class,
     * SQLSTATE and driver error code only, and the other targets still get
     * the entry.
     */
    public function testRegressionADatabaseErrorOfATargetWasLoggedWithTheStatementAndItsValues(): void
    {
        $failing = $this->createMock(AuditTargetInterface::class);
        $failing->method('send')->willThrowException(new \yii\db\Exception(
            "SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'user_agent' at row 1\n"
            . "The SQL being executed was: INSERT INTO `audit_log` (`metadata`) VALUES ('{\"name\":\"marker-4711\"}')",
            ['22001', 1406, "Data too long for column 'user_agent' at row 1"]
        ));
        $other = $this->createMock(AuditTargetInterface::class);
        $other->expects($this->once())->method('send');
        $service = new AuditService();
        $service->targets = [$failing, $other];

        $errors = $this->errorsLoggedBy(fn () => $service->log('test.db_error', 'test', 1, null, ['name' => 'marker-4711']));

        $this->assertCount(1, $errors);
        $this->assertStringNotContainsString('marker-4711', $errors[0], 'no value of the entry is logged');
        $this->assertSame(
            'AuditService: target ' . get_class($failing) . ' failed: yii\db\Exception (SQLSTATE 22001, driver error 1406)',
            $errors[0]
        );
        $this->assertSame(
            sprintf(AuditService::TARGET_FAILED, get_class($failing), 'yii\db\Exception (SQLSTATE 22001, driver error 1406)'),
            $errors[0]
        );
    }

    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function targetErrorProvider(): array
    {
        return [
            'a duplicate key, whose driver message quotes the value' => [
                new \yii\db\IntegrityException(
                    "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'marker-4711'",
                    ['23000', 1062, "Duplicate entry 'marker-4711' for key 'PRIMARY'"]
                ),
                'yii\db\IntegrityException (SQLSTATE 23000, driver error 1062)',
            ],
            'a PDOException that escaped the wrapping' => [
                new \PDOException("SQLSTATE[HY000]: General error: 2006 MySQL server has gone away 'marker-4711'"),
                'PDOException (SQLSTATE -, driver error -)',
            ],
            'an error of another kind keeps its message' => [
                new \RuntimeException('Splunk HEC answered HTTP 503'),
                'Splunk HEC answered HTTP 503',
            ],
        ];
    }

    /**
     * @dataProvider targetErrorProvider
     */
    public function testAFailingTargetIsLoggedWithWhatMayBeSaidAboutItsError(\Throwable $error, string $text): void
    {
        $failing = $this->createMock(AuditTargetInterface::class);
        $failing->method('send')->willThrowException($error);
        $service = new AuditService();
        $service->targets = [$failing];

        $errors = $this->errorsLoggedBy(fn () => $service->log('test.target_error', 'test', 1));

        $this->assertSame([sprintf(AuditService::TARGET_FAILED, get_class($failing), $text)], $errors);
        $this->assertStringNotContainsString('marker-4711', $errors[0]);
    }

    /**
     * The errors AuditService logs while $action runs.
     *
     * @return list<string>
     */
    private function errorsLoggedBy(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $errors = [];
        foreach ($logger->messages as $message) {
            if ($message[1] === Logger::LEVEL_ERROR && $message[2] === AuditService::class && is_string($message[0])) {
                $errors[] = $message[0];
            }
        }

        return $errors;
    }
}
