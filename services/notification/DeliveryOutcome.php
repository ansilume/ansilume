<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\ErrorText;
use app\components\OutboundHttpException;
use app\components\OutboundHttpResponse;

/**
 * How one attempt at a notification delivery ends
 * (NotificationDeliveryService::deliver()):
 * - DELIVERED: the channel took it (remoteId: the post it created, if any;
 *   subject: the subject it sent, for the notification.dispatched entry);
 * - TRANSIENT: the channel failed in a way a later attempt may get past: it
 *   is retried with the backoff, or fails once it ran out of attempts
 *   (retryAfter: the seconds the server asked to wait, if it did);
 * - PERMANENT: the channel refused it for good: it fails at once;
 * - SKIPPED: not sent: the user the template delivers as may no longer see
 *   the event (error: why);
 * - SUPERSEDED: not sent: a later status of the same run's thread replaces
 *   it;
 * - WAITING: not sent yet: an earlier delivery of its thread is not
 *   finished (wait: the seconds until it looks again).
 *
 * ofError() classifies the error of a channel by the first error of the
 * outbound HTTP client in its chain (OutboundHttpException), so that a
 * channel that wraps it is classified the same:
 * - permanent: no request was made (KIND_SCHEME, KIND_REQUEST: the template
 *   must be fixed), or the server answered with a status that says the
 *   request will not succeed: a redirect, which is never followed, or a 4xx
 *   other than TRANSIENT_STATUSES (400, 401, 403, 404, 413, ...); and any
 *   other kind, such as MattermostApiException::KIND_REFUSED (what the
 *   server answered refuses the delivery: a personal token, an archived
 *   channel);
 * - transient: no answer came (KIND_CONNECT, KIND_TIMEOUT), the TLS
 *   connection failed (KIND_TLS: an operator may fix the CA bundle,
 *   OUTBOUND_CA_BUNDLE, in time), or the status was one of
 *   TRANSIENT_STATUSES or 5xx, with the answer's Retry-After, as seconds
 *   or as an HTTP date;
 * - transient as well: any other error, such as the mailer's.
 *
 * The error is what ErrorText gives (a database error by its class,
 * SQLSTATE and driver error code only), at most ERROR_LENGTH characters:
 * notification_delivery.last_error holds no more.
 */
final class DeliveryOutcome
{
    public const DELIVERED = 'delivered';
    public const TRANSIENT = 'transient';
    public const PERMANENT = 'permanent';
    public const SKIPPED = 'skipped';
    public const SUPERSEDED = 'superseded';
    public const WAITING = 'waiting';

    /** The most characters of an error a delivery keeps: the length of notification_delivery.last_error. */
    public const ERROR_LENGTH = 500;

    /** The kinds of the HTTP client's errors that a later attempt may get past. */
    public const TRANSIENT_KINDS = [
        OutboundHttpException::KIND_CONNECT,
        OutboundHttpException::KIND_TIMEOUT,
        OutboundHttpException::KIND_TLS,
    ];

    /** The statuses besides 5xx that a later attempt may get past: Request Timeout, Too Early, Too Many Requests. */
    public const TRANSIENT_STATUSES = [408, 425, 429];

    /** An HTTP date as Retry-After gives it (IMF-fixdate), in the format of DateTimeImmutable::createFromFormat(). */
    private const HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    private function __construct(
        public readonly string $kind,
        public readonly string $error = '',
        public readonly ?int $retryAfter = null,
        public readonly ?string $remoteId = null,
        public readonly int $wait = 0,
        public readonly string $subject = ''
    ) {
    }

    public static function delivered(?string $remoteId = null, string $subject = ''): self
    {
        return new self(self::DELIVERED, '', null, $remoteId, 0, $subject);
    }

    public static function transient(string $error, ?int $retryAfter = null): self
    {
        return new self(self::TRANSIENT, self::cut($error), $retryAfter);
    }

    public static function permanent(string $error): self
    {
        return new self(self::PERMANENT, self::cut($error));
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, self::cut($reason));
    }

    public static function superseded(): self
    {
        return new self(self::SUPERSEDED);
    }

    public static function waiting(int $seconds): self
    {
        return new self(self::WAITING, '', null, null, $seconds);
    }

    /**
     * The outcome of an attempt whose channel threw $error (see the class);
     * $now is the time of the attempt, for a Retry-After that is a date.
     */
    public static function ofError(\Throwable $error, int $now): self
    {
        $text = ErrorText::of($error);
        $http = self::httpErrorIn($error);
        if ($http === null || in_array($http->kind, self::TRANSIENT_KINDS, true)) {
            return self::transient($text);
        }
        if ($http->kind === OutboundHttpException::KIND_STATUS && self::isTransientStatus($http->httpStatus)) {
            return self::transient($text, self::retryAfterOf($http->response, $now));
        }

        return self::permanent($text);
    }

    /**
     * Whether an answer of $status may be retried: TRANSIENT_STATUSES and
     * 5xx.
     */
    public static function isTransientStatus(int $status): bool
    {
        return in_array($status, self::TRANSIENT_STATUSES, true) || ($status >= 500 && $status <= 599);
    }

    /**
     * Whether the delivery is done with: anything but a transient error or
     * a wait.
     */
    public function isFinal(): bool
    {
        return $this->kind !== self::TRANSIENT && $this->kind !== self::WAITING;
    }

    /**
     * The first error of the outbound HTTP client in $error's chain, $error
     * first; null when there is none. A chain that loops (only reflection
     * can make one) ends before the first repeat.
     */
    private static function httpErrorIn(\Throwable $error): ?OutboundHttpException
    {
        $seen = [];
        for ($link = $error; $link !== null && !in_array($link, $seen, true); $link = $link->getPrevious()) {
            if ($link instanceof OutboundHttpException) {
                return $link;
            }
            $seen[] = $link;
        }

        return null;
    }

    /**
     * The seconds from $now that the answer's Retry-After asks to wait:
     * its seconds, or the time until its HTTP date, which passed dates make
     * 0; null for an answer without one, or with a value of another form.
     */
    private static function retryAfterOf(?OutboundHttpResponse $response, int $now): ?int
    {
        $value = trim((string)$response?->header('Retry-After'));
        if ($value !== '' && ctype_digit($value)) {
            return (int)$value;
        }
        $date = \DateTimeImmutable::createFromFormat(self::HTTP_DATE, $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format(self::HTTP_DATE) !== $value) {
            return null;
        }

        return max(0, $date->getTimestamp() - $now);
    }

    private static function cut(string $text): string
    {
        return mb_substr($text, 0, self::ERROR_LENGTH);
    }
}
