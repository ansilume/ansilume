<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\User;
use app\models\Webhook;
use app\tests\integration\DbTestCase;

class WebhookTest extends DbTestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%webhook}}', Webhook::tableName());
    }

    public function testPersistAndRetrieve(): void
    {
        $user = $this->createUser();
        $webhook = $this->createWebhook($user->id);

        $this->assertNotNull($webhook->id);
        $reloaded = Webhook::findOne($webhook->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($webhook->name, $reloaded->name);
        $this->assertSame('https://example.com/webhook', $reloaded->url);
        $this->assertSame('job.success,job.failure', $reloaded->events);
        $this->assertSame($user->id, $reloaded->created_by);
    }

    public function testValidationRejectsInvalidUrl(): void
    {
        $user = $this->createUser();
        $w = new Webhook();
        $w->name = 'bad-url-webhook';
        $w->url = 'not-a-url';
        $w->events = 'job.success';
        $w->created_by = $user->id;
        $this->assertFalse($w->validate());
        $this->assertArrayHasKey('url', $w->errors);
    }

    public function testValidationRejectsUnknownEvent(): void
    {
        $user = $this->createUser();
        $w = new Webhook();
        $w->name = 'bad-event-webhook';
        $w->url = 'https://example.com/hook';
        $w->events = 'invalid.event';
        $w->created_by = $user->id;
        $this->assertFalse($w->validate());
        $this->assertArrayHasKey('events', $w->errors);
    }

    public function testBeforeValidateConvertsEventsArray(): void
    {
        $user = $this->createUser();
        $w = new Webhook();
        $w->name = 'array-events-webhook';
        $w->url = 'https://example.com/hook';
        $w->eventsArray = ['job.success', 'job.failure'];
        $w->created_by = $user->id;
        $w->validate();
        $this->assertSame('job.success,job.failure', $w->events);
    }

    public function testGetEventList(): void
    {
        $w = new Webhook();
        $w->events = 'job.success,job.failure,job.started';
        $this->assertSame(['job.success', 'job.failure', 'job.started'], $w->getEventList());
    }

    public function testListensToReturnsTrueForMatchingEvent(): void
    {
        $user = $this->createUser();
        $webhook = $this->createWebhook($user->id, 'job.success,job.failure', true);
        $this->assertTrue($webhook->listensTo('job.success'));
        $this->assertTrue($webhook->listensTo('job.failure'));
    }

    public function testListensToReturnsFalseWhenDisabled(): void
    {
        $user = $this->createUser();
        $webhook = $this->createWebhook($user->id, 'job.success', false);
        $this->assertFalse($webhook->listensTo('job.success'));
    }

    public function testListensToReturnsFalseForNonMatchingEvent(): void
    {
        $user = $this->createUser();
        $webhook = $this->createWebhook($user->id, 'job.success', true);
        $this->assertFalse($webhook->listensTo('job.failure'));
    }

    public function testAllEventsReturnsThreeEvents(): void
    {
        $events = Webhook::allEvents();
        $this->assertCount(3, $events);
        $this->assertArrayHasKey(Webhook::EVENT_JOB_STARTED, $events);
        $this->assertArrayHasKey(Webhook::EVENT_JOB_SUCCESS, $events);
        $this->assertArrayHasKey(Webhook::EVENT_JOB_FAILURE, $events);
    }

    public function testCreatorRelation(): void
    {
        $user = $this->createUser();
        $webhook = $this->createWebhook($user->id);
        $this->assertInstanceOf(User::class, $webhook->creator);
        $this->assertSame($user->id, $webhook->creator->id);
    }
}
