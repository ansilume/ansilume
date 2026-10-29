<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\Webhook;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for Webhook model — event parsing, listensTo, validation.
 * No database required.
 */
class WebhookTest extends TestCase
{
    public function testGetEventListParsesCommaSeparatedString(): void
    {
        $webhook = $this->makeWebhook(['events' => 'job.success,job.failure']);
        $this->assertSame(['job.success', 'job.failure'], $webhook->getEventList());
    }

    public function testGetEventListTrimsWhitespace(): void
    {
        $webhook = $this->makeWebhook(['events' => ' job.success , job.failure ']);
        $this->assertSame(['job.success', 'job.failure'], $webhook->getEventList());
    }

    public function testGetEventListEmptyStringReturnsEmptyArray(): void
    {
        $webhook = $this->makeWebhook(['events' => '']);
        $this->assertSame([], $webhook->getEventList());
    }

    public function testListensToReturnsTrueForMatchingEvent(): void
    {
        $webhook = $this->makeWebhook(['enabled' => true, 'events' => 'job.success,job.failure']);
        $this->assertTrue($webhook->listensTo('job.success'));
        $this->assertTrue($webhook->listensTo('job.failure'));
    }

    public function testListensToReturnsFalseForNonMatchingEvent(): void
    {
        $webhook = $this->makeWebhook(['enabled' => true, 'events' => 'job.success']);
        $this->assertFalse($webhook->listensTo('job.failure'));
        $this->assertFalse($webhook->listensTo('job.started'));
    }

    public function testListensToReturnsFalseWhenDisabled(): void
    {
        $webhook = $this->makeWebhook(['enabled' => false, 'events' => 'job.success,job.failure,job.started']);
        $this->assertFalse($webhook->listensTo('job.success'));
    }

    public function testValidEventsPassValidation(): void
    {
        $webhook = $this->makeWebhook(['events' => 'job.success,job.failure']);
        $webhook->validate(['events']);
        $this->assertFalse($webhook->hasErrors('events'));
    }

    public function testUnknownEventFailsValidation(): void
    {
        $webhook = $this->makeWebhook(['events' => 'job.success,job.exploded']);
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

    /**
     * Create a Webhook stub with pre-populated attributes.
     * Mocks attributes() to avoid DB schema lookup.
     */
    private function makeWebhook(array $attrs = []): Webhook
    {
        $w = $this->getMockBuilder(Webhook::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $w->method('attributes')->willReturn(
            ['id', 'name', 'url', 'events', 'secret', 'enabled', 'created_by', 'created_at', 'updated_at']
        );
        $w->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setValue($w, array_merge(
            ['id' => null, 'name' => 'test', 'url' => 'http://example.com', 'events' => '', 'secret' => null, 'enabled' => true],
            $attrs
        ));
        return $w;
    }
}
