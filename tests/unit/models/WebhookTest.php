<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Webhook model — event parsing, listensTo, validation.
 * No database required.
 */
class WebhookTest extends TestCase
{
    public function testGetEventListParsesCommaSeparatedString(): void
    {
        $webhook = new Webhook();
        $webhook->events = 'job.success,job.failure';
        $this->assertSame(['job.success', 'job.failure'], $webhook->getEventList());
    }

    public function testGetEventListTrimsWhitespace(): void
    {
        $webhook = new Webhook();
        $webhook->events = ' job.success , job.failure ';
        $this->assertSame(['job.success', 'job.failure'], $webhook->getEventList());
    }

    public function testGetEventListEmptyStringReturnsEmptyArray(): void
    {
        $webhook = new Webhook();
        $webhook->events = '';
        $this->assertSame([], $webhook->getEventList());
    }

    public function testListensToReturnsTrueForMatchingEvent(): void
    {
        $webhook          = new Webhook();
        $webhook->enabled = true;
        $webhook->events  = 'job.success,job.failure';
        $this->assertTrue($webhook->listensTo('job.success'));
        $this->assertTrue($webhook->listensTo('job.failure'));
    }

    public function testListensToReturnsFalseForNonMatchingEvent(): void
    {
        $webhook          = new Webhook();
        $webhook->enabled = true;
        $webhook->events  = 'job.success';
        $this->assertFalse($webhook->listensTo('job.failure'));
        $this->assertFalse($webhook->listensTo('job.started'));
    }

    public function testListensToReturnsFalseWhenDisabled(): void
    {
        $webhook          = new Webhook();
        $webhook->enabled = false;
        $webhook->events  = 'job.success,job.failure,job.started';
        $this->assertFalse($webhook->listensTo('job.success'));
    }

    public function testValidEventsPassValidation(): void
    {
        $webhook         = new Webhook();
        $webhook->events = 'job.success,job.failure';
        $webhook->validate(['events']);
        $this->assertFalse($webhook->hasErrors('events'));
    }

    public function testUnknownEventFailsValidation(): void
    {
        $webhook         = new Webhook();
        $webhook->events = 'job.success,job.exploded';
        $webhook->validate(['events']);
        $this->assertTrue($webhook->hasErrors('events'));
    }

    public function testAllEventsContainsThreeEntries(): void
    {
        $events = Webhook::allEvents();
        $this->assertCount(3, $events);
        $this->assertArrayHasKey(Webhook::EVENT_JOB_STARTED, $events);
        $this->assertArrayHasKey(Webhook::EVENT_JOB_SUCCESS, $events);
        $this->assertArrayHasKey(Webhook::EVENT_JOB_FAILURE, $events);
    }
}
