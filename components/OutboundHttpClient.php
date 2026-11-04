<?php

declare(strict_types=1);

namespace app\components;

use yii\base\Component;

/**
 * The HTTP client of every outbound request to an address that a setting
 * names: the notification channels and the outbound webhooks
 * (Yii::$app->get('outboundHttp')).
 *
 * - Only http and https, for the request and for any redirect: the URL is
 *   checked before curl sees it (schemeOf()), and curl may use no other
 *   protocol (CURLOPT_PROTOCOLS_STR, CURLOPT_REDIR_PROTOCOLS_STR). No
 *   redirect is followed: a 3xx answer is an error of its status.
 * - A connect timeout (the TLS handshake included) and a total timeout.
 * - TLS peer and host verification always on. caBundle
 *   (OUTBOUND_CA_BUNDLE) names a PEM file of CAs to trust on top of the
 *   system's, for servers whose certificate an internal CA issued: libcurl
 *   keeps its CA directory (/etc/ssl/certs in the images), so the system's
 *   CAs stay trusted. There is no setting that turns verification off.
 * - The answer's body is read up to maxBodyBytes; the rest is not read.
 * - Its errors (OutboundHttpException) are made of message constants only:
 *   they never carry the URL, a header, the request body or the answer's
 *   content. The answer of an error status is on the exception, for the
 *   caller to read.
 * - Every parameter that carries the URL, a header or the body is marked
 *   #[\SensitiveParameter]. It logs nothing; its callers say what they sent.
 */
class OutboundHttpClient extends Component
{
    /** The methods request() sends. */
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** request() was given a method it does not send, an error of the calling code; sprintf(): the method. */
    public const METHOD_REFUSED = 'The outbound HTTP client does not send %s requests.';

    /** The protocols curl may use, for the request and for a redirect. */
    private const PROTOCOLS = 'http,https';

    /**
     * curl's error codes of a TLS connection that failed: 35 SSL_CONNECT_ERROR,
     * 53 SSL_ENGINE_NOTFOUND, 54 SSL_ENGINE_SETFAILED, 58 SSL_CERTPROBLEM,
     * 59 SSL_CIPHER, 60 PEER_FAILED_VERIFICATION, 64 USE_SSL_FAILED,
     * 66 SSL_ENGINE_INITFAILED, 77 SSL_CACERT_BADFILE, 80 SSL_SHUTDOWN_FAILED,
     * 82 SSL_CRL_BADFILE, 83 SSL_ISSUER_ERROR, 90 SSL_PINNEDPUBKEYNOTMATCH,
     * 91 SSL_INVALIDCERTSTATUS, 98 SSL_CLIENTCERT. PHP defines constants
     * for only some of them.
     */
    private const TLS_ERRORS = [35, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98];

    /** A PEM file of CAs to trust on top of the system's (OUTBOUND_CA_BUNDLE); null or '' for none. */
    public ?string $caBundle = null;

    /** Seconds to connect, the TLS handshake included. */
    public int $connectTimeout = 5;

    /** Seconds for the whole request. */
    public int $timeout = 15;

    /** The most bytes of an answer's body (and of its headers) that are read. */
    public int $maxBodyBytes = 65536;

    /**
     * The scheme of $url in lower case, when $url is an absolute URL with a
     * host and without whitespace or control characters; null otherwise.
     * request() sends only to a URL whose scheme is http or https, and the
     * notification templates take only such URLs (NotificationTemplate).
     */
    public static function schemeOf(#[\SensitiveParameter] string $url): ?string
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) !== 0) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }

        return strtolower($parts['scheme']);
    }

    /**
     * Sends one request and answers the 2xx answer to it.
     *
     * @param string $method one of METHODS
     * @param list<string> $headers request headers, each a "Name: value" line
     * @param string|null $body the request body; null for none
     * @throws OutboundHttpException when no 2xx answer came (see its kinds)
     * @throws \InvalidArgumentException for a method that is not one of METHODS
     */
    public function request(
        string $method,
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] array $headers = [],
        #[\SensitiveParameter] ?string $body = null
    ): OutboundHttpResponse {
        $this->checkRequest($method, $url, $headers);
        $transfer = new OutboundHttpTransfer($this->maxBodyBytes);
        // curl_init() without a URL fails only when libcurl has no memory for a handle.
        $handle = curl_init() ?: throw $this->refused(CURLE_FAILED_INIT);
        if (!curl_setopt_array($handle, $this->options($method, $url, $headers, $body, $transfer))) {
            throw $this->refused(curl_errno($handle));
        }
        curl_exec($handle);
        $errno = curl_errno($handle);
        // A body cut at maxBodyBytes stops the transfer as a write error: the answer is there.
        if ($errno !== 0 && !($errno === CURLE_WRITE_ERROR && $transfer->capped)) {
            throw $this->failure($handle, $errno);
        }
        $response = $transfer->response((int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
        if ($response->status < 200 || $response->status > 299) {
            throw new OutboundHttpException(
                OutboundHttpException::KIND_STATUS,
                sprintf(OutboundHttpException::HTTP_STATUS, $response->status),
                $response->status,
                $response
            );
        }

        return $response;
    }

    /**
     * Refuses a method other than METHODS, a URL that is no http or https
     * URL, and a header with a line break or a NUL byte, which curl would
     * send as it is: as headers of the caller's choosing.
     *
     * @param list<string> $headers
     */
    private function checkRequest(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers): void
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException(sprintf(self::METHOD_REFUSED, $method));
        }
        if (!in_array(self::schemeOf($url), ['http', 'https'], true)) {
            throw new OutboundHttpException(OutboundHttpException::KIND_SCHEME, OutboundHttpException::SCHEME_REFUSED);
        }
        foreach ($headers as $header) {
            if (strpbrk($header, "\r\n\0") !== false) {
                throw new OutboundHttpException(OutboundHttpException::KIND_REQUEST, OutboundHttpException::HEADER_REFUSED);
            }
        }
    }

    /**
     * The curl options of the request; the URL first, so that a URL curl
     * does not take stops curl_setopt_array() before anything else.
     *
     * @param list<string> $headers
     * @return array<int, mixed>
     */
    private function options(
        string $method,
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] array $headers,
        #[\SensitiveParameter] ?string $body,
        OutboundHttpTransfer $transfer
    ): array {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_PROTOCOLS_STR => self::PROTOCOLS,
            CURLOPT_REDIR_PROTOCOLS_STR => self::PROTOCOLS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => [$transfer, 'onHeader'],
            CURLOPT_WRITEFUNCTION => [$transfer, 'onBody'],
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($this->caBundle !== null && $this->caBundle !== '') {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        return $options;
    }

    /**
     * The error of a request that curl did not take as given; $code is
     * curl's error code.
     */
    private function refused(int $code): OutboundHttpException
    {
        return new OutboundHttpException(OutboundHttpException::KIND_REQUEST, sprintf(OutboundHttpException::REQUEST_REFUSED, $code));
    }

    /**
     * The error of a request that got no answer: a timeout names the
     * timeout that passed (the connect timeout while the connection, its TLS
     * handshake included, was not up yet), a TLS error and any other error
     * curl's error code.
     */
    private function failure(\CurlHandle $handle, int $errno): OutboundHttpException
    {
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            $connected = (float)curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME) > 0.0;

            return new OutboundHttpException(
                OutboundHttpException::KIND_TIMEOUT,
                sprintf(OutboundHttpException::TIMED_OUT, $connected ? $this->timeout : $this->connectTimeout)
            );
        }
        if (in_array($errno, self::TLS_ERRORS, true)) {
            return new OutboundHttpException(OutboundHttpException::KIND_TLS, sprintf(OutboundHttpException::TLS_FAILED, $errno));
        }

        return new OutboundHttpException(OutboundHttpException::KIND_CONNECT, sprintf(OutboundHttpException::CONNECT_FAILED, $errno));
    }
}
