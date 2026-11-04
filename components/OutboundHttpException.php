<?php

declare(strict_types=1);

namespace app\components;

/**
 * An outbound HTTP request that OutboundHttpClient did not complete with a
 * 2xx answer.
 *
 * Its message is one of the message constants below and says only what
 * went wrong: never the URL, a header, the request body or the answer's
 * content. kind says what happened, for a caller that tells a lasting error
 * from one that a later attempt may not meet:
 * - KIND_SCHEME: the URL is no http or https URL; no request was made;
 * - KIND_REQUEST: the request could not be made as given (a header holds a
 *   line break or a NUL byte, or curl did not take it); no request was made;
 * - KIND_CONNECT: no answer came: the host could not be resolved or reached,
 *   or the connection broke;
 * - KIND_TIMEOUT: the connect or the total timeout passed;
 * - KIND_TLS: the TLS connection failed, its certificate check included;
 * - KIND_STATUS: the server answered with a status outside 2xx, a redirect
 *   included (httpStatus; the answer is response, its body read up to the
 *   client's limit).
 */
class OutboundHttpException extends \RuntimeException
{
    public const KIND_SCHEME = 'scheme';
    public const KIND_REQUEST = 'request';
    public const KIND_CONNECT = 'connect';
    public const KIND_TIMEOUT = 'timeout';
    public const KIND_TLS = 'tls';
    public const KIND_STATUS = 'status';

    /** KIND_SCHEME: the URL is no absolute http or https URL with a host. */
    public const SCHEME_REFUSED = 'The URL must use http or https.';

    /** KIND_REQUEST: a request header holds a line break or a NUL byte, which would add headers to the request. */
    public const HEADER_REFUSED = 'A request header holds a line break or a NUL byte.';

    /** KIND_REQUEST: curl did not take the request as given; sprintf(): curl's error code. */
    public const REQUEST_REFUSED = 'The request could not be made (curl error %d).';

    /** KIND_CONNECT; sprintf(): curl's error code. */
    public const CONNECT_FAILED = 'Could not connect (curl error %d).';

    /** KIND_TIMEOUT; sprintf(): the seconds of the timeout that passed, the connect or the total one. */
    public const TIMED_OUT = 'The request timed out after %d seconds.';

    /** KIND_TLS; sprintf(): curl's error code. */
    public const TLS_FAILED = 'The TLS connection failed (curl error %d).';

    /** KIND_STATUS; sprintf(): the status of the answer. */
    public const HTTP_STATUS = 'HTTP %d.';

    /** from(): the error as its sender reports it; sprintf(): the sender, such as SlackChannel, then the error. */
    public const FROM = '%s: %s';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?OutboundHttpResponse $response = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * This error as $sender reports it (FROM), of the same kind, status and
     * answer, with this error as the previous one.
     */
    public function from(string $sender): self
    {
        return new self($this->kind, sprintf(self::FROM, $sender, $this->getMessage()), $this->httpStatus, $this->response, $this);
    }
}
