<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\OutboundHttpClient;
use app\components\OutboundHttpException;
use app\tests\unit\services\notification\HttpLoopbackServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\log\Logger;

/**
 * OutboundHttpClient, the one HTTP client of the notification channels and
 * the outbound webhooks: only http and https, no redirect followed, a
 * connect and a total timeout, the answer's body read up to a limit, TLS
 * always verified, with a CA bundle of the operator's on top of the
 * system's (OUTBOUND_CA_BUNDLE), and errors that never carry the URL, a
 * header, the request body or the answer's content.
 *
 * Regression: a file: URL is refused without being read, and no error
 * carries what an answer held; before, an operator's Send Test showed the
 * start of a local file.
 */
class OutboundHttpClientTest extends TestCase
{
    /**
     * Schemes other than http and https; %d is the port of a socket that
     * listens, to tell whether a connection was made.
     *
     * @return array<string, array{0: string}>
     */
    public static function otherSchemes(): array
    {
        return [
            'file' => ['file:///etc/hostname'],
            'file in upper case' => ['FILE:///etc/hostname'],
            'gopher' => ['gopher://127.0.0.1:%d/'],
            'dict' => ['dict://127.0.0.1:%d/'],
            'ftp' => ['ftp://127.0.0.1:%d/'],
        ];
    }

    /**
     * A file: URL is refused without being read, and a URL of another
     * network scheme without a connection: the scheme is checked before curl
     * sees the URL.
     */
    #[DataProvider('otherSchemes')]
    public function testOtherSchemesAreRefusedWithoutBeingRead(string $url): void
    {
        $listener = $this->listener();
        try {
            $error = $this->errorOf(fn () => (new OutboundHttpClient())->request('GET', sprintf($url, $this->portOf($listener))));
            $connected = $this->hasPendingConnection($listener);
        } finally {
            fclose($listener);
        }

        $this->assertSame(OutboundHttpException::KIND_SCHEME, $error->kind);
        $this->assertSame('The URL must use http or https.', $error->getMessage());
        $this->assertSame(OutboundHttpException::SCHEME_REFUSED, $error->getMessage());
        $this->assertSame(0, $error->httpStatus);
        $this->assertNull($error->response);
        $this->assertNull($error->getPrevious());
        $this->assertFalse($connected, 'no connection was made');
    }

    /**
     * What the client and the channel config checks take as http or https
     * URL: an absolute URL with a host, and nothing that curl could read
     * another way.
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function urls(): array
    {
        return [
            'https' => ['https://hooks.example.com/services/T0', 'https'],
            'http with a port' => ['http://127.0.0.1:8065/hooks/x', 'http'],
            'the scheme in upper case' => ['HTTPS://Example.com/', 'https'],
            'another scheme' => ['gopher://127.0.0.1/', 'gopher'],
            'no host' => ['https:///hooks', null],
            'no slashes' => ['http:example.com', null],
            'a file' => ['file:///etc/hostname', null],
            'no scheme' => ['//example.com/hooks', null],
            'a space before it' => [' https://example.com/', null],
            'a space in it' => ['https://exa mple.com/', null],
            'a line break in it' => ["https://example.com/\nx", null],
            'a NUL byte in it' => ["https://example.com/\0", null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('urls')]
    public function testTheSchemeOfAUrl(string $url, ?string $scheme): void
    {
        $this->assertSame($scheme, OutboundHttpClient::schemeOf($url));
    }

    /**
     * A URL that is no http or https URL is refused as such, whatever its
     * flaw: no host, a space, a line break or a NUL byte.
     *
     * @return array<string, array{0: string}>
     */
    public static function malformedUrls(): array
    {
        return [
            'no host' => ['https:///hooks'],
            'a space' => ['https://exa mple.com/'],
            'a line break' => ["http://127.0.0.1:1/\r\nX-Injected: 1"],
            'a NUL byte' => ["http://127.0.0.1:1/\0"],
        ];
    }

    #[DataProvider('malformedUrls')]
    public function testAMalformedUrlIsRefusedBeforeAnyRequest(string $url): void
    {
        $error = $this->errorOf(fn () => (new OutboundHttpClient())->request('GET', $url));

        $this->assertSame(OutboundHttpException::KIND_SCHEME, $error->kind);
        $this->assertSame(OutboundHttpException::SCHEME_REFUSED, $error->getMessage());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function redirectTargets(): array
    {
        return [
            'to a local file' => ['file:///etc/hostname'],
            'to another path of the same server' => ['/target'],
        ];
    }

    /**
     * A redirect is an error of its status: the client follows none, to
     * another scheme or anywhere else.
     */
    #[DataProvider('redirectTargets')]
    public function testARedirectIsNotFollowed(string $location): void
    {
        $server = new HttpLoopbackServer();
        $base = $server->startWith([
            '/moved' => ['status' => 302, 'headers' => ['Location' => $location]],
            '*' => ['status' => 200, 'body' => 'the redirect target'],
        ]);
        try {
            $error = $this->errorOf(fn () => (new OutboundHttpClient())->request('POST', $base . '/moved', ['Content-Type: application/json'], '{}'));
            $requests = $server->requests();
        } finally {
            $server->stop();
        }

        $this->assertSame(OutboundHttpException::KIND_STATUS, $error->kind);
        $this->assertSame(302, $error->httpStatus);
        $this->assertSame('HTTP 302.', $error->getMessage());
        $this->assertSame($location, $error->response?->header('Location'));
        $this->assertSame(['/moved'], array_column($requests, 'path'), 'only the first request was made');
    }

    /**
     * An error answer and a request that got no answer: neither the message
     * nor the string form of the exception, its stack trace with the
     * arguments included (phpunit.xml), nor a log line holds the URL, its
     * credentials, a request header, the request body, an answer header or
     * the answer's body. The caller still reads the answer from the error.
     */
    public function testErrorsNeverCarryTheUrlTheBodyOrAHeader(): void
    {
        $server = new HttpLoopbackServer();
        $base = $server->startWith(['*' => ['status' => 500, 'body' => 'secret-body-4471', 'headers' => ['X-Echo' => 'secret-answer-header-4472']]]);
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $status = $this->errorOf(fn () => (new OutboundHttpClient())->request(
                'POST',
                $base . '/hooks/secret-path-4473?token=secret-query-4474',
                ['Authorization: Bearer secret-header-4475'],
                'secret-payload-4476'
            ));
            $unanswered = $this->errorOf(fn () => (new OutboundHttpClient())->request(
                'POST',
                'http://secret-user-4477:secret-password-4478@127.0.0.1:1/hooks/secret-path-4473',
                ['Authorization: Bearer secret-header-4475'],
                'secret-payload-4476'
            ));
        } finally {
            \Yii::setLogger($previous);
            $server->stop();
        }

        $this->assertSame(OutboundHttpException::KIND_STATUS, $status->kind);
        $this->assertSame('HTTP 500.', $status->getMessage());
        $this->assertSame(500, $status->httpStatus);
        $this->assertSame('secret-body-4471', $status->response?->body, 'the caller reads the answer from the error');
        $this->assertSame(OutboundHttpException::KIND_CONNECT, $unanswered->kind);
        $this->assertSame('Could not connect (curl error 7).', $unanswered->getMessage());
        $this->assertSame(0, $unanswered->httpStatus);
        $this->assertNull($unanswered->response);
        $said = (string)$status . "\n" . (string)$unanswered . "\n" . json_encode($logger->messages);
        $this->assertStringContainsString('OutboundHttpClient->request(', $said, 'the trace was read');
        foreach (['secret-body', 'secret-answer-header', 'secret-path', 'secret-query', 'secret-header', 'secret-payload', 'secret-user', 'secret-password', '127.0.0.1'] as $secret) {
            $this->assertStringNotContainsString($secret, $said);
        }
    }

    /**
     * The body is read up to maxBodyBytes (65536 by default) and the rest
     * not at all, also of an error answer; a body of exactly the limit is
     * read whole.
     */
    public function testTheBodyIsCappedAtMaxBodyBytes(): void
    {
        $body = str_repeat('a', 65536) . str_repeat('b', 34464);
        $server = new HttpLoopbackServer();
        $base = $server->startWith([
            '/big' => ['status' => 200, 'body' => $body],
            '/big-error' => ['status' => 503, 'body' => $body],
            '/exact' => ['status' => 200, 'body' => 'abc'],
        ]);
        try {
            $big = (new OutboundHttpClient())->request('GET', $base . '/big');
            $small = (new OutboundHttpClient(['maxBodyBytes' => 10]))->request('GET', $base . '/big');
            $exact = (new OutboundHttpClient(['maxBodyBytes' => 3]))->request('GET', $base . '/exact');
            $error = $this->errorOf(fn () => (new OutboundHttpClient())->request('GET', $base . '/big-error'));
        } finally {
            $server->stop();
        }

        $this->assertSame(65536, (new OutboundHttpClient())->maxBodyBytes);
        $this->assertSame(200, $big->status);
        $this->assertSame(str_repeat('a', 65536), $big->body);
        $this->assertSame('aaaaaaaaaa', $small->body);
        $this->assertSame('abc', $exact->body);
        $this->assertSame(OutboundHttpException::KIND_STATUS, $error->kind);
        $this->assertSame(503, $error->httpStatus);
        $this->assertSame(str_repeat('a', 65536), $error->response?->body);
    }

    /**
     * A server that takes the request and answers too late: the request
     * ends after the total timeout, and the error names it.
     */
    public function testATimeoutIsReportedAsTimeout(): void
    {
        $server = new HttpLoopbackServer();
        $base = $server->startWith(['*' => ['status' => 200, 'body' => 'late', 'sleep' => 5]]);
        $started = microtime(true);
        try {
            $error = $this->errorOf(fn () => (new OutboundHttpClient(['timeout' => 2]))->request('GET', $base . '/slow'));
            $elapsed = microtime(true) - $started;
        } finally {
            $server->stop();
        }

        $this->assertSame(OutboundHttpException::KIND_TIMEOUT, $error->kind);
        $this->assertSame('The request timed out after 2 seconds.', $error->getMessage());
        $this->assertLessThan(4.0, $elapsed);
    }

    /**
     * A TLS handshake that never ends belongs to the connection phase: the
     * request ends after the connect timeout, and the error names that one.
     */
    public function testAHandshakeThatNeverEndsTimesOutAfterTheConnectTimeout(): void
    {
        $listener = $this->listener();
        $started = microtime(true);
        try {
            $error = $this->errorOf(fn () => (new OutboundHttpClient(['connectTimeout' => 2, 'timeout' => 10]))
                ->request('GET', 'https://127.0.0.1:' . $this->portOf($listener) . '/'));
            $elapsed = microtime(true) - $started;
        } finally {
            fclose($listener);
        }

        $this->assertSame(OutboundHttpException::KIND_TIMEOUT, $error->kind);
        $this->assertSame('The request timed out after 2 seconds.', $error->getMessage());
        $this->assertLessThan(4.0, $elapsed);
    }

    /**
     * The defaults: 5 seconds to connect, 15 for the whole request.
     */
    public function testTheTimeoutsDefaultToFiveAndFifteenSeconds(): void
    {
        $client = new OutboundHttpClient();

        $this->assertSame(5, $client->connectTimeout);
        $this->assertSame(15, $client->timeout);
        $this->assertNull($client->caBundle);
    }

    /**
     * A server whose certificate an internal CA issued: refused without the
     * CA bundle, taken with it; a bundle that is not there, or an empty
     * setting, gives no trust either.
     */
    public function testTheCaBundleIsUsedForTls(): void
    {
        $server = new TlsLoopbackServer();
        $base = $server->start();
        try {
            $refused = $this->errorOf(fn () => (new OutboundHttpClient())->request('GET', $base . '/'));
            $unset = $this->errorOf(fn () => (new OutboundHttpClient(['caBundle' => '']))->request('GET', $base . '/'));
            $taken = (new OutboundHttpClient(['caBundle' => $server->caFile()]))->request('GET', $base . '/');
            $missing = $this->errorOf(fn () => (new OutboundHttpClient(['caBundle' => $server->caFile() . '.missing']))->request('GET', $base . '/'));
        } finally {
            $server->stop();
        }

        $this->assertSame(OutboundHttpException::KIND_TLS, $refused->kind);
        $this->assertSame('The TLS connection failed (curl error 60).', $refused->getMessage());
        $this->assertSame('The TLS connection failed (curl error 60).', $unset->getMessage());
        $this->assertSame(200, $taken->status);
        $this->assertSame(TlsLoopbackServer::ANSWER, $taken->body);
        $this->assertSame(OutboundHttpException::KIND_TLS, $missing->kind);
        $this->assertSame('The TLS connection failed (curl error 77).', $missing->getMessage());
    }

    /**
     * The host is verified too: a certificate that a trusted CA issued for
     * another name is refused.
     */
    public function testACertificateForAnotherHostIsRefused(): void
    {
        $server = new TlsLoopbackServer();
        $base = $server->start('DNS:mattermost.example.com');
        try {
            $error = $this->errorOf(fn () => (new OutboundHttpClient(['caBundle' => $server->caFile()]))->request('GET', $base . '/'));
        } finally {
            $server->stop();
        }

        $this->assertSame(OutboundHttpException::KIND_TLS, $error->kind);
        $this->assertSame('The TLS connection failed (curl error 60).', $error->getMessage());
    }

    public function testA2xxAnswerReturnsStatusHeadersAndBody(): void
    {
        $server = new HttpLoopbackServer();
        $base = $server->startWith(['/api/v4/posts' => [
            'status' => 201,
            'body' => '{"id":"post-1"}',
            'headers' => ['X-Request-Id' => 'req-42', 'Retry-After' => '30'],
        ]]);
        try {
            $response = (new OutboundHttpClient())->request(
                'POST',
                $base . '/api/v4/posts?set_online=false',
                ['Content-Type: application/json', 'Authorization: Bearer token-1'],
                '{"message":"hi"}'
            );
            $requests = $server->requests();
        } finally {
            $server->stop();
        }

        $this->assertSame(201, $response->status);
        $this->assertSame('{"id":"post-1"}', $response->body);
        $this->assertSame('req-42', $response->header('X-Request-ID'));
        $this->assertSame('req-42', $response->headers['x-request-id'] ?? null, 'header names are lower-case');
        $this->assertSame('30', $response->header('retry-after'));
        $this->assertNull($response->header('X-Missing'));
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/api/v4/posts?set_online=false', $requests[0]['path']);
        $this->assertSame('{"message":"hi"}', $requests[0]['body']);
        $this->assertSame('application/json', $requests[0]['headers']['Content-Type'] ?? null);
        $this->assertSame('Bearer token-1', $requests[0]['headers']['Authorization'] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function methods(): array
    {
        return [
            'GET' => ['GET', null],
            'POST' => ['POST', '{"a":1}'],
            'PUT' => ['PUT', '{"message":"edited"}'],
            'PATCH' => ['PATCH', '{"b":2}'],
            'DELETE' => ['DELETE', null],
        ];
    }

    /**
     * Each method is sent as given, with its body when it has one.
     */
    #[DataProvider('methods')]
    public function testEachMethodIsSentAsGiven(string $method, ?string $body): void
    {
        $server = new HttpLoopbackServer();
        $base = $server->start(200, 'ok');
        try {
            $response = (new OutboundHttpClient())->request($method, $base . '/x', ['Content-Type: application/json'], $body);
            $requests = $server->requests();
        } finally {
            $server->stop();
        }

        $this->assertSame('ok', $response->body);
        $this->assertSame([$method], array_column($requests, 'method'));
        $this->assertSame((string)$body, $requests[0]['body']);
    }

    public function testAnotherMethodIsAnErrorOfTheCaller(): void
    {
        $server = new HttpLoopbackServer();
        $base = $server->start(200, 'ok');
        try {
            $error = null;
            try {
                (new OutboundHttpClient())->request('TRACE', $base . '/x');
            } catch (\InvalidArgumentException $e) {
                $error = $e;
            }
            $requests = $server->requests();
        } finally {
            $server->stop();
        }

        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertSame(sprintf(OutboundHttpClient::METHOD_REFUSED, 'TRACE'), $error->getMessage());
        $this->assertSame([], $requests);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function headersWithLineBreaks(): array
    {
        return [
            'CR LF' => ["X-Token: a\r\nX-Injected: 1"],
            'LF' => ["X-Token: a\nX-Injected: 1"],
            'CR' => ["X-Token: a\rX-Injected: 1"],
            'NUL' => ["X-Token: a\0b"],
        ];
    }

    /**
     * curl sends a header line as it is given, a line break included, which
     * would add headers of the caller's choosing: such a header is refused
     * and no request is made. Its value is not named.
     */
    #[DataProvider('headersWithLineBreaks')]
    public function testAHeaderWithALineBreakIsRefusedBeforeAnyRequest(string $header): void
    {
        $server = new HttpLoopbackServer();
        $base = $server->start(200, 'ok');
        try {
            $error = $this->errorOf(fn () => (new OutboundHttpClient())->request('POST', $base . '/x', ['Content-Type: application/json', $header], '{}'));
            $requests = $server->requests();
        } finally {
            $server->stop();
        }

        $this->assertSame(OutboundHttpException::KIND_REQUEST, $error->kind);
        $this->assertSame('A request header holds a line break or a NUL byte.', $error->getMessage());
        $this->assertSame([], $requests);
    }

    /**
     * A URL longer than libcurl takes (CURL_MAX_INPUT_LENGTH, 8000000
     * bytes) is refused by curl before any request.
     */
    public function testARequestCurlDoesNotTakeIsAnError(): void
    {
        $error = $this->errorOf(fn () => (new OutboundHttpClient())->request('GET', 'http://127.0.0.1/' . str_repeat('a', 8000000)));

        $this->assertSame(OutboundHttpException::KIND_REQUEST, $error->kind);
        $this->assertSame('The request could not be made (curl error 43).', $error->getMessage());
    }

    /**
     * How a sender, such as a notification channel, reports the error: its
     * name first, of the same kind, status and answer, with the client's
     * error as the previous one.
     */
    public function testFromNamesTheSenderAndKeepsKindStatusAndAnswer(): void
    {
        $server = new HttpLoopbackServer();
        $base = $server->start(404, 'no_service');
        try {
            $error = $this->errorOf(fn () => (new OutboundHttpClient())->request('POST', $base . '/hooks/x', [], '{}'));
        } finally {
            $server->stop();
        }

        $reported = $error->from('SlackChannel');

        $this->assertSame('SlackChannel: HTTP 404.', $reported->getMessage());
        $this->assertSame(OutboundHttpException::KIND_STATUS, $reported->kind);
        $this->assertSame(404, $reported->httpStatus);
        $this->assertSame($error->response, $reported->response);
        $this->assertSame('no_service', $reported->response?->body);
        $this->assertSame($error, $reported->getPrevious());
    }

    /**
     * The client is an application component; config/services.php gives it
     * the CA bundle of OUTBOUND_CA_BUNDLE (OutboundCaBundleConfigTest).
     */
    public function testTheApplicationHasTheClient(): void
    {
        $this->assertInstanceOf(OutboundHttpClient::class, \Yii::$app->get('outboundHttp'));
    }

    /**
     * The OutboundHttpException that $call throws.
     */
    private function errorOf(callable $call): OutboundHttpException
    {
        try {
            $call();
        } catch (OutboundHttpException $e) {
            return $e;
        }
        $this->fail('no OutboundHttpException');
    }

    /**
     * A socket that listens on a free loopback port: the kernel takes a
     * connection, nothing ever answers it.
     *
     * @return resource
     */
    private function listener()
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($listener, $error);

        return $listener;
    }

    /**
     * @param resource $listener
     */
    private function portOf($listener): int
    {
        $name = (string)stream_socket_get_name($listener, false);

        return (int)substr($name, (int)strrpos($name, ':') + 1);
    }

    /**
     * Whether a connection to $listener waits to be accepted.
     *
     * @param resource $listener
     */
    private function hasPendingConnection($listener): bool
    {
        $read = [$listener];
        $write = null;
        $except = null;

        return stream_select($read, $write, $except, 0, 200000) > 0;
    }
}
