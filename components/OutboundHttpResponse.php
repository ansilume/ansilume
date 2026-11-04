<?php

declare(strict_types=1);

namespace app\components;

/**
 * The answer to an outbound HTTP request (OutboundHttpClient): its status,
 * its headers by lower-case name, and its body, read up to the client's
 * limit. The answer of an error status is on the OutboundHttpException, for
 * the caller to read; no error message carries it.
 */
final class OutboundHttpResponse
{
    /**
     * @param array<string, string> $headers by lower-case name; a header that came twice holds both values, joined by ", "
     */
    public function __construct(
        public readonly int $status,
        #[\SensitiveParameter] public readonly array $headers,
        #[\SensitiveParameter] public readonly string $body
    ) {
    }

    /**
     * The value of the header $name, in any case; null when the answer has
     * no such header.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
