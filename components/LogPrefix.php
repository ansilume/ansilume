<?php

declare(strict_types=1);

namespace app\components;

use yii\web\Request;
use yii\web\User;

/**
 * The prefix of web log lines: "[client IP][user ID]".
 *
 * Yii's default prefix adds the session ID as a third field, and anyone who
 * can read app.log could take over the sessions it names. A log line needs
 * the client and the user, not the session.
 */
final class LogPrefix
{
    /**
     * For yii\log\Target::$prefix. Like Yii's default, it only looks at
     * components the request has already created, so logging never starts a
     * session or loads an identity.
     *
     * @param array<int, mixed> $message the log message; the prefix does not depend on it
     */
    public static function ipAndUser(array $message): string
    {
        $app = \Yii::$app;
        if ($app === null) {
            return '';
        }

        $request = $app->getRequest();
        $ip = $request instanceof Request ? $request->getUserIP() ?? '-' : '-';
        $user = $app->has('user', true) ? $app->get('user') : null;
        $identity = $user instanceof User ? $user->getIdentity(false) : null;
        $userId = $identity !== null ? (string)$identity->getId() : '-';

        return "[{$ip}][{$userId}]";
    }
}
