<?php

declare(strict_types=1);

namespace app\components;

/**
 * One place that turns REDIS_HOST / REDIS_PORT / REDIS_DB / REDIS_PASSWORD
 * into connection settings, for every yii2-redis component (cache, session,
 * queue) and for the raw phpredis clients.
 *
 * An empty REDIS_PASSWORD means "no AUTH". yii2-redis sends AUTH whenever the
 * password is not null, and an empty string would make it send a bare AUTH
 * that every server rejects, so empty becomes null here.
 */
final class RedisSettings
{
    public function __construct(
        public readonly string $hostname,
        public readonly int $port,
        public readonly int $database,
        #[\SensitiveParameter]
        private readonly ?string $password,
    ) {
    }

    /**
     * @param array<string, mixed> $env Usually $_ENV.
     */
    public static function fromEnvironment(array $env): self
    {
        $password = (string)($env['REDIS_PASSWORD'] ?? '');

        return new self(
            (string)($env['REDIS_HOST'] ?? 'redis'),
            (int)($env['REDIS_PORT'] ?? 6379),
            (int)($env['REDIS_DB'] ?? 0),
            $password === '' ? null : $password,
        );
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * Component config for a yii2-redis connection. Keep `retries` at its
     * default of 0: with retries the parent class logs failed commands, which
     * for AUTH would include the password.
     *
     * @return array{class: class-string, hostname: string, port: int, database: int, password: string|null}
     */
    public function connectionConfig(): array
    {
        return [
            'class' => RedactingRedisConnection::class,
            'hostname' => $this->hostname,
            'port' => $this->port,
            'database' => $this->database,
            'password' => $this->password,
        ];
    }

    /**
     * Connects, authenticates and selects the database on a phpredis client.
     */
    public function connectPhpRedis(\Redis $redis): \Redis
    {
        $redis->connect($this->hostname, $this->port);
        if ($this->password !== null && $redis->auth($this->password) !== true) {
            throw new \RuntimeException('Redis rejected REDIS_PASSWORD (AUTH failed).');
        }
        if ($this->database !== 0) {
            $redis->select($this->database);
        }

        return $redis;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'hostname' => $this->hostname,
            'port' => $this->port,
            'database' => $this->database,
            'password' => $this->password === null ? null : '***',
        ];
    }
}
