<?php

declare(strict_types=1);

namespace app\components;

/**
 * What curl hands OutboundHttpClient of one answer while a request runs:
 * the headers of the final answer, by lower-case name, and the body up to a
 * limit of bytes. Once the body reaches the limit, the next part stops the
 * transfer, so the rest of a large answer is never read; capped says so.
 * Headers count against the same limit, so a flood of them is left out.
 */
final class OutboundHttpTransfer
{
    /** Whether the body went past the limit, so the transfer was stopped. */
    public bool $capped = false;

    /** @var array<string, string> */
    private array $headers = [];

    private int $headerBytes = 0;

    private string $body = '';

    public function __construct(private readonly int $maxBytes)
    {
    }

    /**
     * curl's header callback, for each line of the answer's head: a status
     * line starts a new answer (after 100 Continue only the final answer's
     * headers count), a line without a colon is no header.
     *
     * @return int the length of the line: curl goes on
     */
    public function onHeader(\CurlHandle $handle, #[\SensitiveParameter] string $line): int
    {
        $length = strlen($line);
        if (str_starts_with($line, 'HTTP/')) {
            $this->headers = [];
            $this->headerBytes = 0;

            return $length;
        }
        $colon = strpos($line, ':');
        if ($colon === false || $this->headerBytes + $length > $this->maxBytes) {
            return $length;
        }
        $this->headerBytes += $length;
        $name = strtolower(trim(substr($line, 0, $colon)));
        $value = trim(substr($line, $colon + 1));
        $this->headers[$name] = isset($this->headers[$name]) ? $this->headers[$name] . ', ' . $value : $value;

        return $length;
    }

    /**
     * curl's write callback, for each part of the body: kept up to the
     * limit. A part that goes past it is cut and stops the transfer.
     *
     * @return int the length of $data to go on; less (0) to stop the transfer
     */
    public function onBody(\CurlHandle $handle, #[\SensitiveParameter] string $data): int
    {
        $room = max(0, $this->maxBytes - strlen($this->body));
        $this->body .= substr($data, 0, $room);
        if (strlen($data) > $room) {
            $this->capped = true;

            return 0;
        }

        return strlen($data);
    }

    /**
     * The answer as received, with $status, its status code.
     */
    public function response(int $status): OutboundHttpResponse
    {
        return new OutboundHttpResponse($status, $this->headers, $this->body);
    }
}
