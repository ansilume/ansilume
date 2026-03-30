<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\RunnerHttpClient;
use PHPUnit\Framework\TestCase;

class RunnerHttpClientTest extends TestCase
{
    public function testGetLastHttpStatusDefaultsToZero(): void
    {
        $client = new RunnerHttpClient('http://test', '');
        $this->assertSame(0, $client->getLastHttpStatus());
    }

    public function testSetTokenDoesNotThrow(): void
    {
        $client = new RunnerHttpClient('http://test', 'initial');
        $client->setToken('updated');

        // Token is private — verify via getLastHttpStatus that object is in valid state.
        $this->assertSame(0, $client->getLastHttpStatus());
    }

    public function testPostReturnsNullForInvalidUrl(): void
    {
        // Use an unresolvable hostname to get a fast failure.
        $client = new RunnerHttpClient('http://invalid.test.invalid', '');
        $result = $client->post('/api/test', ['foo' => 'bar']);

        $this->assertNull($result);
    }

    public function testPostUnauthenticatedReturnsNullForInvalidUrl(): void
    {
        $client = new RunnerHttpClient('http://invalid.test.invalid', '');
        $result = $client->postUnauthenticated('/api/test', ['foo' => 'bar']);

        $this->assertNull($result);
    }
}
