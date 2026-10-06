<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\RedactingRedisConnection;
use app\tests\FakeRedisServer;
use PHPUnit\Framework\TestCase;
use yii\db\Exception;
use yii\redis\Connection;
use yii\redis\SocketException;

/**
 * Regression: yii2-redis appends the raw command to its error messages, so a
 * failed AUTH produced "Redis command was: AUTH <password>", which the health
 * check printed to docker logs and bin/diagnose.
 *
 * Uses a one-shot fake Redis server in a child process, so the real socket
 * code of yii2-redis runs and no Redis server is needed.
 */
class RedactingRedisConnectionTest extends TestCase
{
    private const CANARY = 'leak-canary-redis-password';

    private ?FakeRedisServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function startFakeRedis(?string $reply): int
    {
        $this->server = FakeRedisServer::start($reply);

        return $this->server->port;
    }

    /**
     * @param class-string<Connection> $class
     */
    private function connection(string $class, int $port, ?string $password): Connection
    {
        return new $class([
            'hostname' => '127.0.0.1',
            'port' => $port,
            'database' => null,
            'password' => $password,
            'connectionTimeout' => 5,
            'dataTimeout' => 5,
        ]);
    }

    private function openAndCatch(Connection $connection): \Throwable
    {
        try {
            $connection->open();
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('opening the connection should have failed');
    }

    public function testStockConnectionLeaksThePasswordOnAuthFailure(): void
    {
        // Control case: proves the leak this class exists to prevent.
        $port = $this->startFakeRedis("-WRONGPASS invalid username-password pair\r\n");

        $error = $this->openAndCatch($this->connection(Connection::class, $port, self::CANARY));

        $this->assertStringContainsString(self::CANARY, $error->getMessage());
    }

    public function testAuthErrorReplyIsRedacted(): void
    {
        $port = $this->startFakeRedis("-WRONGPASS invalid username-password pair\r\n");

        $error = $this->openAndCatch($this->connection(RedactingRedisConnection::class, $port, self::CANARY));

        $this->assertInstanceOf(Exception::class, $error);
        $this->assertNotInstanceOf(SocketException::class, $error);
        $this->assertStringContainsString('WRONGPASS', $error->getMessage());
        $this->assertStringContainsString(RedactingRedisConnection::REDACTED_NOTE, $error->getMessage());
        $this->assertStringNotContainsString(self::CANARY, $error->getMessage());
        $this->assertNull($error->getPrevious(), 'the original exception (with the password) must not be chained');
    }

    /**
     * The path the password actually leaked through: the queue (and the
     * session) send AUTH on their first command and let the error escape into
     * worker logs, app.log and error pages.
     *
     * @param class-string<Connection> $class
     */
    private function queuePushError(string $class): string
    {
        $port = $this->startFakeRedis("-WRONGPASS invalid username-password pair\r\n");
        $queue = new \yii\queue\redis\Queue([
            'redis' => [
                'class' => $class,
                'hostname' => '127.0.0.1',
                'port' => $port,
                'database' => null,
                'password' => self::CANARY,
            ],
            'serializer' => \app\components\AllowlistQueueSerializer::class,
        ]);
        try {
            $queue->push(new \app\jobs\SyncProjectJob(['projectId' => 1]));
        } catch (\Throwable $e) {
            return $e->getMessage();
        } finally {
            $this->server?->stop();
            $this->server = null;
        }
        $this->fail('pushing with a rejected password should have failed');
    }

    public function testQueuePushWithAWrongPasswordNoLongerLeaksIt(): void
    {
        $this->assertStringContainsString(self::CANARY, $this->queuePushError(Connection::class));

        $message = $this->queuePushError(RedactingRedisConnection::class);

        $this->assertStringContainsString('WRONGPASS', $message);
        $this->assertStringNotContainsString(self::CANARY, $message);
    }

    /**
     * Regression: yii2-redis pools the socket before AUTH. After a rejected
     * AUTH the next command ran on that socket unauthenticated and, because
     * SELECT never ran, against database 0. A server without a password
     * (".env has one, Redis does not") then silently mixed the databases.
     */
    public function testCommandsAfterARejectedAuthNeverRunUnauthenticated(): void
    {
        $this->server = FakeRedisServer::start(
            "+OK\r\n",
            true,
            "-ERR AUTH <password> called without any password configured for the default user.\r\n"
        );
        $connection = $this->connection(RedactingRedisConnection::class, $this->server->port, self::CANARY);
        $connection->database = 3;

        foreach (['first', 'second'] as $attempt) {
            try {
                $connection->executeCommand('SET', ['cache-key', 'value']);
                $this->fail("the {$attempt} command ran although AUTH was rejected");
            } catch (Exception $e) {
                $this->assertStringContainsString('called without any password configured', $e->getMessage(), $attempt);
                $this->assertStringNotContainsString(self::CANARY, $e->getMessage());
            }
        }
        $this->assertFalse($connection->getIsActive(), 'the rejected connection must not stay open');
    }

    public function testAuthSocketErrorIsRedacted(): void
    {
        $port = $this->startFakeRedis(null);

        $error = $this->openAndCatch($this->connection(RedactingRedisConnection::class, $port, self::CANARY));

        $this->assertInstanceOf(SocketException::class, $error);
        $this->assertStringContainsString(RedactingRedisConnection::REDACTED_NOTE, $error->getMessage());
        $this->assertStringNotContainsString(self::CANARY, $error->getMessage());
    }

    public function testNonAuthCommandsAreUntouched(): void
    {
        $port = $this->startFakeRedis("-ERR unknown command\r\n");
        $connection = $this->connection(RedactingRedisConnection::class, $port, null);

        try {
            $connection->executeCommand('GET', ['some-key']);
            $this->fail('the scripted error reply should have raised an exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('Redis command was: GET some-key', $e->getMessage());
            $this->assertStringNotContainsString('redacted', $e->getMessage());
        }
    }

    public function testSuccessfulCommandsPassThrough(): void
    {
        $port = $this->startFakeRedis("+PONG\r\n");
        $connection = $this->connection(RedactingRedisConnection::class, $port, null);

        $this->assertTrue($connection->executeCommand('PING'));
    }

    public function testRedactWithoutCommandMarkerStillHidesEverything(): void
    {
        $this->assertSame(
            'Redis AUTH failed. ' . RedactingRedisConnection::REDACTED_NOTE,
            RedactingRedisConnection::redact('anything')
        );
    }
}
