<?php

declare(strict_types=1);

namespace app\components;

use yii\queue\ExecEvent;
use yii\queue\LogBehavior;

/**
 * The queue's log behaviour (config/console.php, 'as log'): yii2-queue's,
 * except that a job that ends with an exception whose chain holds a
 * database error is logged as ExceptionLogText words it, never with a
 * message that can carry the SQL statement or the Redis command with its
 * values. Any other error is logged as yii2-queue logs it.
 */
class QueueLogBehavior extends LogBehavior
{
    /**
     * Logs "<job> is finished with error: <error>." as yii2-queue does, with
     * the error as ExceptionLogText::redacted() gives it. The event keeps its
     * error for every other handler.
     */
    public function afterError(ExecEvent $event): void
    {
        $error = $event->error;
        if ($error !== null) {
            $event->error = ExceptionLogText::redacted($error);
        }
        try {
            parent::afterError($event);
        } finally {
            $event->error = $error;
        }
    }
}
