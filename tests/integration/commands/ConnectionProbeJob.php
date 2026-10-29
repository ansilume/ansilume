<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * A queue job for WorkerJobDbConnectionTest: it asks the database which
 * connection it runs on and records the answer. With a session wait
 * timeout it first gives that connection one, so that the server closes it
 * once it sat idle that long, as a database closes an idle connection after
 * its wait_timeout. With $fail it throws after its query, as a job that
 * fails does.
 */
final class ConnectionProbeJob extends BaseObject implements JobInterface
{
    /** The error of a probe that is told to fail. */
    public const FAILED = 'The probe job failed, as it was told to.';

    /** @var list<int> the IDs of the connections the probes ran on, in the order they ran */
    public static array $connectionIds = [];

    /** Seconds the connection may sit idle before the server closes it; 0 keeps the server's wait_timeout. */
    public int $sessionWaitTimeout = 0;

    public bool $fail = false;

    public function execute($queue): void
    {
        $db = \Yii::$app->getDb();
        if ($this->sessionWaitTimeout > 0) {
            $db->createCommand('SET SESSION wait_timeout = ' . $this->sessionWaitTimeout)->execute();
        }
        self::$connectionIds[] = (int)$db->createCommand('SELECT CONNECTION_ID()')->queryScalar();
        if ($this->fail) {
            throw new \RuntimeException(self::FAILED);
        }
    }
}
