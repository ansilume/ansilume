<?php

declare(strict_types=1);

namespace app\components;

/**
 * Stands in for an exception whose chain holds a database error where Yii
 * prints or logs an exception itself (ExceptionLogText::redacted()): the
 * fallback of the error handlers, the console's error output and the queue's
 * log behaviour. Its message is ExceptionLogText::brief() of that exception,
 * its string form ExceptionLogText::of(). It is never thrown.
 */
final class RedactedException extends \RuntimeException
{
    public function __construct(private readonly string $logText, string $brief)
    {
        parent::__construct($brief);
    }

    public function __toString(): string
    {
        return $this->logText;
    }
}
