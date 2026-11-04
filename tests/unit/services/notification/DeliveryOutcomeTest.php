<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\components\OutboundHttpException;
use app\components\OutboundHttpResponse;
use app\services\notification\DeliveryOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How one attempt at a notification delivery ends (DeliveryOutcome), and
 * how the error of a channel is classified: by the first error of the
 * outbound HTTP client in its chain, by its kind and its status; any other
 * error is one a later attempt may get past.
 */
final class DeliveryOutcomeTest extends TestCase
{
    private const NOW = 1762171200;

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function httpErrors(): array
    {
        $cases = [
            'a scheme other than http or https: no request was made' => [OutboundHttpException::KIND_SCHEME, 0, DeliveryOutcome::PERMANENT],
            'a request that could not be made as given' => [OutboundHttpException::KIND_REQUEST, 0, DeliveryOutcome::PERMANENT],
            'no connection' => [OutboundHttpException::KIND_CONNECT, 0, DeliveryOutcome::TRANSIENT],
            'a timeout' => [OutboundHttpException::KIND_TIMEOUT, 0, DeliveryOutcome::TRANSIENT],
            'a TLS error: an operator may fix the CA bundle in time' => [OutboundHttpException::KIND_TLS, 0, DeliveryOutcome::TRANSIENT],
        ];
        foreach ([408, 425, 429, 500, 501, 502, 503, 504, 507, 599] as $status) {
            $cases["HTTP {$status}"] = [OutboundHttpException::KIND_STATUS, $status, DeliveryOutcome::TRANSIENT];
        }
        foreach ([100, 300, 301, 302, 303, 304, 307, 308, 400, 401, 403, 404, 405, 409, 410, 413, 415, 422, 600] as $status) {
            $cases["HTTP {$status}"] = [OutboundHttpException::KIND_STATUS, $status, DeliveryOutcome::PERMANENT];
        }

        return $cases;
    }

    #[DataProvider('httpErrors')]
    public function testAnErrorOfTheHttpClientIsClassifiedByItsKindAndStatus(string $kind, int $status, string $expected): void
    {
        $error = new OutboundHttpException($kind, 'SlackChannel: HTTP ' . $status . '.', $status, $status > 0 ? new OutboundHttpResponse($status, [], 'secret-body') : null);

        $outcome = DeliveryOutcome::ofError($error, self::NOW);

        $this->assertSame($expected, $outcome->kind);
        $this->assertSame('SlackChannel: HTTP ' . $status . '.', $outcome->error);
        $this->assertNull($outcome->retryAfter, 'no Retry-After came');
        $this->assertSame($expected === DeliveryOutcome::PERMANENT, $outcome->isFinal());
    }

    /**
     * A channel error that is no error of the HTTP client, such as the
     * mailer's, may not happen again: it is retried. A database error is
     * named by its class, SQLSTATE and driver error code only.
     */
    public function testAnErrorThatIsNoHttpErrorIsTransient(): void
    {
        $mailer = DeliveryOutcome::ofError(new \RuntimeException('Mailer::send() returned false'), self::NOW);
        $database = DeliveryOutcome::ofError(new \yii\db\Exception(
            "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\nThe SQL being executed was: SELECT 'marker-4711'",
            ['HY000', 2006, 'MySQL server has gone away']
        ), self::NOW);
        $bug = DeliveryOutcome::ofError(new \TypeError('a bug'), self::NOW);

        $this->assertSame([DeliveryOutcome::TRANSIENT, 'Mailer::send() returned false'], [$mailer->kind, $mailer->error]);
        $this->assertSame([DeliveryOutcome::TRANSIENT, 'yii\db\Exception (SQLSTATE HY000, driver error 2006)'], [$database->kind, $database->error]);
        $this->assertSame(DeliveryOutcome::TRANSIENT, $bug->kind);
        $this->assertFalse($mailer->isFinal());
    }

    /**
     * A channel that wraps the HTTP client's error in an error of its own is
     * classified by the client's error; its own message is kept.
     */
    public function testAWrappedHttpErrorDecides(): void
    {
        $notFound = new OutboundHttpException(OutboundHttpException::KIND_STATUS, 'HTTP 404.', 404, new OutboundHttpResponse(404, [], ''));
        $unavailable = new OutboundHttpException(OutboundHttpException::KIND_STATUS, 'HTTP 503.', 503, new OutboundHttpResponse(503, ['retry-after' => '90'], ''));

        $permanent = DeliveryOutcome::ofError(new \RuntimeException('CustomChannel: the hook is gone.', 0, new \LogicException('x', 0, $notFound)), self::NOW);
        $transient = DeliveryOutcome::ofError(new \RuntimeException('CustomChannel: try later.', 0, $unavailable), self::NOW);

        $this->assertSame([DeliveryOutcome::PERMANENT, 'CustomChannel: the hook is gone.'], [$permanent->kind, $permanent->error]);
        $this->assertSame([DeliveryOutcome::TRANSIENT, 90], [$transient->kind, $transient->retryAfter]);
    }

    public function testAnErrorIsKeptUpTo500Characters(): void
    {
        $outcome = DeliveryOutcome::ofError(new \RuntimeException(str_repeat('ä', 600)), self::NOW);

        $this->assertSame(str_repeat('ä', 500), $outcome->error);
        $this->assertSame(500, DeliveryOutcome::ERROR_LENGTH);
        $this->assertSame(str_repeat('x', 500), DeliveryOutcome::permanent(str_repeat('x', 501))->error);
    }

    /**
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function retryAfterValues(): array
    {
        return [
            'seconds' => ['120', 120],
            'no seconds' => ['0', 0],
            'seconds with spaces around' => [' 45 ', 45],
            'a date 90 seconds ahead' => [gmdate('D, d M Y H:i:s', self::NOW + 90) . ' GMT', 90],
            'a date that passed' => [gmdate('D, d M Y H:i:s', self::NOW - 600) . ' GMT', 0],
            'a date with the wrong weekday' => ['Sun, 21 Oct 2015 07:28:00 GMT', null],
            'a date that does not exist' => ['Wed, 32 Oct 2015 07:28:00 GMT', null],
            'a date in another format' => ['2026-11-03T12:00:00Z', null],
            'a negative number' => ['-5', null],
            'a fraction' => ['1.5', null],
            'text' => ['soon', null],
            'empty' => ['', null],
            'a header that came twice' => ['120, 120', null],
        ];
    }

    /**
     * Retry-After of an answer whose status may be retried: seconds, or an
     * HTTP date (IMF-fixdate, as it formats back), as seconds from now; any
     * other value is no Retry-After.
     */
    #[DataProvider('retryAfterValues')]
    public function testRetryAfterIsReadAsSecondsOrAnHttpDate(string $value, ?int $expected): void
    {
        $outcome = DeliveryOutcome::ofError($this->statusError(429, ['retry-after' => $value]), self::NOW);

        $this->assertSame(DeliveryOutcome::TRANSIENT, $outcome->kind);
        $this->assertSame($expected, $outcome->retryAfter);
    }

    public function testAHugeRetryAfterIsTakenAsItIsForTheServiceToCap(): void
    {
        $outcome = DeliveryOutcome::ofError($this->statusError(503, ['retry-after' => '99999999999999999999']), self::NOW);

        $this->assertSame(PHP_INT_MAX, $outcome->retryAfter);
    }

    /**
     * An answer that is not retried has no Retry-After to honour; one
     * without the header neither.
     */
    public function testRetryAfterIsReadOnlyFromAnAnswerThatIsRetried(): void
    {
        $this->assertNull(DeliveryOutcome::ofError($this->statusError(404, ['retry-after' => '30']), self::NOW)->retryAfter);
        $this->assertNull(DeliveryOutcome::ofError($this->statusError(503, []), self::NOW)->retryAfter);
        $this->assertNull(DeliveryOutcome::ofError(
            new OutboundHttpException(OutboundHttpException::KIND_STATUS, 'HTTP 503.', 503),
            self::NOW
        )->retryAfter, 'an error without its answer');
    }

    public function testTheOtherOutcomes(): void
    {
        $delivered = DeliveryOutcome::delivered('post-1', 'Job #7 failed');
        $skipped = DeliveryOutcome::skipped('The user may not see it.');
        $superseded = DeliveryOutcome::superseded();
        $waiting = DeliveryOutcome::waiting(35);

        $this->assertSame([DeliveryOutcome::DELIVERED, 'post-1', '', 'Job #7 failed'], [$delivered->kind, $delivered->remoteId, $delivered->error, $delivered->subject]);
        $this->assertSame([null, ''], [DeliveryOutcome::delivered()->remoteId, DeliveryOutcome::delivered()->subject]);
        $this->assertSame('', $skipped->subject);
        $this->assertSame([DeliveryOutcome::SKIPPED, 'The user may not see it.'], [$skipped->kind, $skipped->error]);
        $this->assertSame(DeliveryOutcome::SUPERSEDED, $superseded->kind);
        $this->assertSame([DeliveryOutcome::WAITING, 35], [$waiting->kind, $waiting->wait]);
        $this->assertSame([true, true, true, false], [$delivered->isFinal(), $skipped->isFinal(), $superseded->isFinal(), $waiting->isFinal()]);
        $this->assertSame([DeliveryOutcome::TRANSIENT, 'x', 30], [DeliveryOutcome::transient('x', 30)->kind, DeliveryOutcome::transient('x', 30)->error, DeliveryOutcome::transient('x', 30)->retryAfter]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function statusError(int $status, array $headers): OutboundHttpException
    {
        return (new OutboundHttpException(
            OutboundHttpException::KIND_STATUS,
            sprintf(OutboundHttpException::HTTP_STATUS, $status),
            $status,
            new OutboundHttpResponse($status, $headers, '')
        ))->from('WebhookChannel');
    }
}
