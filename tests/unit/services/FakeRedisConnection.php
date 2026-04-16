<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use yii\redis\Connection;

/**
 * In-memory fake of yii\redis\Connection that records executed commands
 * and emulates INCR/EXPIRE/GET/DEL. Used by TotpRateLimiter tests to
 * exercise the atomic Redis branch without requiring a live Redis server.
 */
class FakeRedisConnection extends Connection
{
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $commands = [];

    /** @var array<string, int> */
    private array $store = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * @param string $name
     * @param array<int, mixed> $params
     * @return mixed
     */
    public function executeCommand($name, $params = [])
    {
        $this->commands[] = [$name, $params];
        $key = (string)($params[0] ?? '');
        switch ($name) {
            case 'INCR':
                $this->store[$key] = ($this->store[$key] ?? 0) + 1;
                return $this->store[$key];
            case 'EXPIRE':
                return 1;
            case 'GET':
                return isset($this->store[$key]) ? (string)$this->store[$key] : null;
            case 'DEL':
                unset($this->store[$key]);
                return 1;
        }
        return null;
    }

    public function open(): void
    {
        // No-op — never opens a real socket.
    }

    public function close(): void
    {
        // No-op
    }
}
