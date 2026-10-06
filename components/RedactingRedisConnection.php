<?php

declare(strict_types=1);

namespace app\components;

use yii\db\Exception;
use yii\redis\Connection;
use yii\redis\SocketException;

/**
 * yii2-redis connection that never puts the Redis password into an exception
 * and never keeps a connection whose AUTH failed.
 *
 * The parent class appends the raw command to its error messages
 * ("Redis command was: AUTH <password>"). Those messages end up in health
 * check output, docker logs, app.log and bin/diagnose. For AUTH, this class
 * cuts the command off and says that the credentials were redacted.
 *
 * The parent also pools the socket before it sends AUTH. After a rejected
 * AUTH, every later command would run on that socket unauthenticated and,
 * because the SELECT that follows AUTH never ran, against database 0. This
 * class closes the socket instead, so the next command connects and
 * authenticates again.
 */
class RedactingRedisConnection extends Connection
{
    private const COMMAND_MARKER = "\nRedis command was:";

    /**
     * Must not look like "AUTH x y": yii\redis\SocketException rewrites that
     * pattern itself.
     */
    public const REDACTED_NOTE = '(Redis credentials redacted)';

    private bool $discarding = false;

    /**
     * @param string $command
     * @param array<int, mixed> $params
     * @return mixed
     */
    protected function sendRawCommand($command, $params)
    {
        if ($this->discarding) {
            return $this->sendIgnoringErrors($command, $params);
        }
        if (!self::isAuth($params)) {
            return parent::sendRawCommand($command, $params);
        }

        try {
            return parent::sendRawCommand($command, $params);
        } catch (SocketException $e) {
            $this->discardConnection();
            throw new SocketException(self::redact($e->getMessage()), (string)$e->getCode());
        } catch (Exception $e) {
            $this->discardConnection();
            throw new Exception(self::redact($e->getMessage()), [], (string)$e->getCode());
        }
    }

    /**
     * Closes the pooled socket after a failed AUTH. close() sends QUIT first;
     * whatever the server answers to it must not replace the AUTH error.
     */
    private function discardConnection(): void
    {
        $this->discarding = true;
        try {
            $this->close();
        } finally {
            $this->discarding = false;
        }
    }

    /**
     * @param string $command
     * @param array<int, mixed> $params
     * @return mixed
     */
    private function sendIgnoringErrors($command, $params)
    {
        try {
            return parent::sendRawCommand($command, $params);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Strip the command (and with it every AUTH argument) from an error message.
     */
    public static function redact(string $message): string
    {
        $position = strpos($message, self::COMMAND_MARKER);
        $base = $position === false ? 'Redis AUTH failed.' : substr($message, 0, $position);

        return $base . ' ' . self::REDACTED_NOTE;
    }

    /**
     * @param array<int, mixed> $params
     */
    private static function isAuth(array $params): bool
    {
        return isset($params[0]) && is_string($params[0]) && strcasecmp($params[0], 'AUTH') === 0;
    }
}
