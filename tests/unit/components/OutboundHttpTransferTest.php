<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\OutboundHttpTransfer;
use PHPUnit\Framework\TestCase;

/**
 * OutboundHttpTransfer keeps what curl hands OutboundHttpClient of one
 * answer: the headers of the final answer by lower-case name, and the body
 * up to a limit, after which it stops the transfer.
 */
class OutboundHttpTransferTest extends TestCase
{
    /**
     * An interim answer (100 Continue) is followed by the final one: only
     * the final answer's headers count. A header that comes twice is joined
     * with a comma, a line without a colon is no header.
     */
    public function testTheHeadersAreThoseOfTheFinalAnswer(): void
    {
        $handle = curl_init();
        $this->assertInstanceOf(\CurlHandle::class, $handle);
        $transfer = new OutboundHttpTransfer(65536);
        $lines = [
            "HTTP/1.1 100 Continue\r\n",
            "X-Interim: 1\r\n",
            "\r\n",
            "HTTP/1.1 201 Created\r\n",
            "X-Request-Id:  req-7 \r\n",
            "Link: <a>\r\n",
            "link: <b>\r\n",
            "not a header\r\n",
            "\r\n",
        ];

        foreach ($lines as $line) {
            $this->assertSame(strlen($line), $transfer->onHeader($handle, $line), 'every header line is taken');
        }

        $this->assertSame(['x-request-id' => 'req-7', 'link' => '<a>, <b>'], $transfer->response(201)->headers);
    }

    /**
     * Headers count against the limit too: once they would pass it, the
     * rest is left out; the transfer goes on.
     */
    public function testHeadersPastTheLimitAreLeftOut(): void
    {
        $handle = curl_init();
        $this->assertInstanceOf(\CurlHandle::class, $handle);
        $transfer = new OutboundHttpTransfer(20);

        $this->assertSame(15, $transfer->onHeader($handle, "A: 1234567890\r\n"));
        $this->assertSame(15, $transfer->onHeader($handle, "B: 1234567890\r\n"));

        $this->assertSame(['a' => '1234567890'], $transfer->response(200)->headers);
    }

    /**
     * The body is kept up to the limit. The part that reaches it is taken;
     * the first byte past it stops the transfer (a callback that takes less
     * than it was given), and the transfer counts as capped.
     */
    public function testTheBodyStopsAtTheLimit(): void
    {
        $handle = curl_init();
        $this->assertInstanceOf(\CurlHandle::class, $handle);
        $transfer = new OutboundHttpTransfer(10);

        $this->assertSame(6, $transfer->onBody($handle, 'abcdef'));
        $this->assertFalse($transfer->capped);
        $this->assertSame(4, $transfer->onBody($handle, 'ghij'));
        $this->assertFalse($transfer->capped, 'a body of exactly the limit is whole');
        $this->assertSame(0, $transfer->onBody($handle, 'k'));
        $this->assertTrue($transfer->capped);

        $this->assertSame('abcdefghij', $transfer->response(200)->body);
    }

    /**
     * A part that goes past the limit is kept up to it and stops the
     * transfer.
     */
    public function testAPartPastTheLimitIsCut(): void
    {
        $handle = curl_init();
        $this->assertInstanceOf(\CurlHandle::class, $handle);
        $transfer = new OutboundHttpTransfer(4);

        $this->assertSame(0, $transfer->onBody($handle, 'abcdef'));

        $this->assertTrue($transfer->capped);
        $this->assertSame('abcd', $transfer->response(502)->body);
        $this->assertSame(502, $transfer->response(502)->status);
    }
}
