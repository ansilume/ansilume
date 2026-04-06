<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\NotificationTemplate;
use app\tests\integration\DbTestCase;

class NotificationTemplateTest extends DbTestCase
{
    // -- tableName / behaviors ---------------------------------------------------

    public function testTableName(): void
    {
        $this->assertSame('{{%notification_template}}', NotificationTemplate::tableName());
    }

    public function testTimestampBehaviorIsRegistered(): void
    {
        $nt = new NotificationTemplate();
        $behaviors = $nt->behaviors();
        $this->assertContains(\yii\behaviors\TimestampBehavior::class, $behaviors);
    }

    // -- validation: required fields --------------------------------------------

    public function testValidationRequiresNameChannelEvents(): void
    {
        $nt = new NotificationTemplate();
        $this->assertFalse($nt->validate());
        $this->assertArrayHasKey('name', $nt->getErrors());
        $this->assertArrayHasKey('channel', $nt->getErrors());
        $this->assertArrayHasKey('events', $nt->getErrors());
    }

    public function testValidationPassesWithRequiredFields(): void
    {
        $user = $this->createUser();
        $nt = new NotificationTemplate();
        $nt->name = 'Valid template';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = 'job.failed';
        $nt->created_by = $user->id;
        $this->assertTrue($nt->validate());
    }

    // -- validation: channel ----------------------------------------------------

    public function testValidationRejectsInvalidChannel(): void
    {
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = 'carrier_pigeon';
        $nt->events = 'job.failed';
        $this->assertFalse($nt->validate());
        $this->assertArrayHasKey('channel', $nt->getErrors());
    }

    /**
     * @dataProvider validChannelProvider
     */
    public function testValidationAcceptsValidChannel(string $channel): void
    {
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = $channel;
        $nt->events = 'job.failed';
        $this->assertTrue($nt->validate(['channel']));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validChannelProvider(): array
    {
        return [
            'email' => [NotificationTemplate::CHANNEL_EMAIL],
            'slack' => [NotificationTemplate::CHANNEL_SLACK],
            'teams' => [NotificationTemplate::CHANNEL_TEAMS],
            'webhook' => [NotificationTemplate::CHANNEL_WEBHOOK],
            'telegram' => [NotificationTemplate::CHANNEL_TELEGRAM],
            'pagerduty' => [NotificationTemplate::CHANNEL_PAGERDUTY],
        ];
    }

    // -- validateJson -----------------------------------------------------------

    public function testValidateJsonPassesWithValidJson(): void
    {
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = 'job.failed';
        $nt->config = '{"emails": ["a@b.com"]}';
        $this->assertTrue($nt->validate(['config']));
    }

    public function testValidateJsonFailsWithInvalidJson(): void
    {
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = 'job.failed';
        $nt->config = '{bad json';
        $this->assertFalse($nt->validate(['config']));
        $this->assertArrayHasKey('config', $nt->getErrors());
        $this->assertStringContainsString('valid JSON', $nt->getErrors()['config'][0]);
    }

    public function testValidateJsonPassesWhenEmpty(): void
    {
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = 'job.failed';
        $nt->config = '';
        $this->assertTrue($nt->validate(['config']));
    }

    public function testValidateJsonPassesWhenNull(): void
    {
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = 'job.failed';
        $nt->config = null;
        $this->assertTrue($nt->validate(['config']));
    }

    // -- validateEvents ---------------------------------------------------------

    public function testValidateEventsPassesWithKnownEvents(): void
    {
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = 'job.failed,job.succeeded';
        $this->assertTrue($nt->validate(['events']));
    }

    public function testValidateEventsFailsWithUnknownEvent(): void
    {
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = 'job.failed,unicorn.appeared';
        $this->assertFalse($nt->validate(['events']));
        $this->assertStringContainsString('Unknown event', $nt->getErrors()['events'][0]);
    }

    public function testValidateEventsFailsWithEmptyString(): void
    {
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = '';
        $this->assertFalse($nt->validate());
        $this->assertArrayHasKey('events', $nt->getErrors());
    }

    public function testValidateEventsFailsWithOnlyCommas(): void
    {
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = ',,,';
        $this->assertFalse($nt->validate(['events']));
        $this->assertStringContainsString('at least one event', $nt->getErrors()['events'][0]);
    }

    // -- channelLabels / channelLabel -------------------------------------------

    public function testChannelLabelsReturnsAllChannels(): void
    {
        $labels = NotificationTemplate::channelLabels();
        $this->assertArrayHasKey('email', $labels);
        $this->assertArrayHasKey('slack', $labels);
        $this->assertArrayHasKey('teams', $labels);
        $this->assertArrayHasKey('webhook', $labels);
        $this->assertArrayHasKey('telegram', $labels);
        $this->assertArrayHasKey('pagerduty', $labels);
        $this->assertCount(6, $labels);
    }

    public function testChannelLabelReturnsHumanLabelForKnownChannel(): void
    {
        $this->assertSame('Email', NotificationTemplate::channelLabel('email'));
        $this->assertSame('Slack', NotificationTemplate::channelLabel('slack'));
        $this->assertSame('Microsoft Teams', NotificationTemplate::channelLabel('teams'));
    }

    public function testChannelLabelReturnsFallbackForUnknownChannel(): void
    {
        $this->assertSame('carrier_pigeon', NotificationTemplate::channelLabel('carrier_pigeon'));
    }

    // -- eventLabels / eventGroups / defaultFailureEvents / allFailureEvents -----

    public function testEventLabelsReturnsAllKnownEvents(): void
    {
        $labels = NotificationTemplate::eventLabels();
        $this->assertArrayHasKey('job.failed', $labels);
        $this->assertArrayHasKey('workflow.launched', $labels);
        $this->assertArrayHasKey('approval.requested', $labels);
        $this->assertArrayHasKey('schedule.failed_to_launch', $labels);
        $this->assertArrayHasKey('runner.offline', $labels);
        $this->assertArrayHasKey('project.sync_failed', $labels);
        $this->assertArrayHasKey('webhook.invalid_token', $labels);
    }

    public function testEventGroupsReturnsAllDomainGroups(): void
    {
        $groups = NotificationTemplate::eventGroups();
        $this->assertArrayHasKey('jobs', $groups);
        $this->assertArrayHasKey('workflows', $groups);
        $this->assertArrayHasKey('approvals', $groups);
        $this->assertArrayHasKey('schedules', $groups);
        $this->assertArrayHasKey('runners', $groups);
        $this->assertArrayHasKey('projects', $groups);
        $this->assertArrayHasKey('webhooks', $groups);

        foreach ($groups as $group) {
            $this->assertArrayHasKey('label', $group);
            $this->assertArrayHasKey('events', $group);
            $this->assertNotEmpty($group['events']);
        }
    }

    public function testDefaultFailureEventsAreSubsetOfAllEvents(): void
    {
        $all = array_keys(NotificationTemplate::eventLabels());
        foreach (NotificationTemplate::defaultFailureEvents() as $event) {
            $this->assertContains($event, $all);
        }
    }

    public function testAllFailureEventsAreSubsetOfAllEvents(): void
    {
        $all = array_keys(NotificationTemplate::eventLabels());
        foreach (NotificationTemplate::allFailureEvents() as $event) {
            $this->assertContains($event, $all);
        }
    }

    public function testAllFailureEventsContainsDefaultFailureEvents(): void
    {
        $allFailure = NotificationTemplate::allFailureEvents();
        foreach (NotificationTemplate::defaultFailureEvents() as $event) {
            $this->assertContains($event, $allFailure);
        }
    }

    // -- eventSeverity ----------------------------------------------------------

    public function testEventSeverityCriticalForFailures(): void
    {
        $this->assertSame('critical', NotificationTemplate::eventSeverity('job.failed'));
        $this->assertSame('critical', NotificationTemplate::eventSeverity('workflow.failed'));
        $this->assertSame('critical', NotificationTemplate::eventSeverity('schedule.failed_to_launch'));
        $this->assertSame('critical', NotificationTemplate::eventSeverity('runner.offline'));
        $this->assertSame('critical', NotificationTemplate::eventSeverity('project.sync_failed'));
    }

    public function testEventSeverityErrorForCancellationsAndRejections(): void
    {
        $this->assertSame('error', NotificationTemplate::eventSeverity('job.canceled'));
        $this->assertSame('error', NotificationTemplate::eventSeverity('workflow.canceled'));
        $this->assertSame('error', NotificationTemplate::eventSeverity('workflow.step_failed'));
        $this->assertSame('error', NotificationTemplate::eventSeverity('approval.rejected'));
        $this->assertSame('error', NotificationTemplate::eventSeverity('webhook.invalid_token'));
    }

    public function testEventSeverityWarningForApprovalRequested(): void
    {
        $this->assertSame('warning', NotificationTemplate::eventSeverity('approval.requested'));
    }

    public function testEventSeverityInfoForSuccessAndDefault(): void
    {
        $this->assertSame('info', NotificationTemplate::eventSeverity('job.succeeded'));
        $this->assertSame('info', NotificationTemplate::eventSeverity('job.launched'));
        $this->assertSame('info', NotificationTemplate::eventSeverity('unknown.event'));
    }

    // -- listensTo / getEventList -----------------------------------------------

    public function testListensToReturnsTrueForSubscribedEvent(): void
    {
        $user = $this->createUser();
        $nt = $this->createNotificationTemplate($user->id, NotificationTemplate::CHANNEL_EMAIL, 'job.failed,job.succeeded');
        $this->assertTrue($nt->listensTo('job.failed'));
        $this->assertTrue($nt->listensTo('job.succeeded'));
    }

    public function testListensToReturnsFalseForUnsubscribedEvent(): void
    {
        $user = $this->createUser();
        $nt = $this->createNotificationTemplate($user->id, NotificationTemplate::CHANNEL_EMAIL, 'job.failed');
        $this->assertFalse($nt->listensTo('job.succeeded'));
    }

    public function testGetEventListReturnsEmptyArrayForEmptyEvents(): void
    {
        $nt = new NotificationTemplate();
        $nt->events = '';
        $this->assertSame([], $nt->getEventList());
    }

    public function testGetEventListReturnsEmptyArrayForNullEvents(): void
    {
        $nt = new NotificationTemplate();
        /** @phpstan-ignore assign.propertyType */
        $nt->events = null;
        $this->assertSame([], $nt->getEventList());
    }

    public function testGetEventListTrimsWhitespace(): void
    {
        $nt = new NotificationTemplate();
        $nt->events = ' job.failed , job.succeeded ';
        $this->assertSame(['job.failed', 'job.succeeded'], $nt->getEventList());
    }

    public function testGetEventListFiltersEmptyEntries(): void
    {
        $nt = new NotificationTemplate();
        $nt->events = 'job.failed,,job.succeeded';
        $this->assertSame(['job.failed', 'job.succeeded'], $nt->getEventList());
    }

    // -- getParsedConfig --------------------------------------------------------

    public function testGetParsedConfigReturnsArrayForValidJson(): void
    {
        $user = $this->createUser();
        $nt = $this->createNotificationTemplate($user->id, NotificationTemplate::CHANNEL_SLACK, 'job.failed', '{"webhook_url": "https://hooks.slack.com/xxx"}');
        $this->assertSame(['webhook_url' => 'https://hooks.slack.com/xxx'], $nt->getParsedConfig());
    }

    public function testGetParsedConfigReturnsEmptyArrayForEmptyConfig(): void
    {
        $nt = new NotificationTemplate();
        $nt->config = '';
        $this->assertSame([], $nt->getParsedConfig());
    }

    public function testGetParsedConfigReturnsEmptyArrayForNullConfig(): void
    {
        $nt = new NotificationTemplate();
        $nt->config = null;
        $this->assertSame([], $nt->getParsedConfig());
    }

    public function testGetParsedConfigReturnsEmptyArrayForNonArrayJson(): void
    {
        $nt = new NotificationTemplate();
        $nt->config = '"just a string"';
        $this->assertSame([], $nt->getParsedConfig());
    }

    // -- creator relation -------------------------------------------------------

    public function testCreatorRelationReturnsUser(): void
    {
        $user = $this->createUser();
        $nt = $this->createNotificationTemplate($user->id);
        $creator = $nt->creator;
        $this->assertNotNull($creator);
        $this->assertSame($user->id, $creator->id);
    }

    // -- rules() coverage -------------------------------------------------------

    public function testSubjectTemplateMaxLength512(): void
    {
        $user = $this->createUser();
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = 'job.failed';
        $nt->created_by = $user->id;
        $nt->subject_template = str_repeat('a', 513);
        $this->assertFalse($nt->validate(['subject_template']));
        $this->assertArrayHasKey('subject_template', $nt->getErrors());
    }

    public function testEventsMaxLength1024(): void
    {
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = str_repeat('job.failed,', 200);
        $this->assertFalse($nt->validate(['events']));
    }

    public function testNameMaxLength128(): void
    {
        $nt = new NotificationTemplate();
        $nt->name = str_repeat('x', 129);
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = 'job.failed';
        $this->assertFalse($nt->validate(['name']));
        $this->assertArrayHasKey('name', $nt->getErrors());
    }

    public function testDescriptionIsOptionalString(): void
    {
        $user = $this->createUser();
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = 'job.failed';
        $nt->created_by = $user->id;
        $nt->description = 'Some long description here.';
        $this->assertTrue($nt->validate());
    }

    public function testBodyTemplateIsOptionalString(): void
    {
        $user = $this->createUser();
        $nt = new NotificationTemplate();
        $nt->name = 'test';
        $nt->channel = NotificationTemplate::CHANNEL_EMAIL;
        $nt->events = 'job.failed';
        $nt->created_by = $user->id;
        $nt->body_template = 'Job {{ job.id }} failed on {{ timestamp }}';
        $this->assertTrue($nt->validate());
    }

    // -- defaultFailureEvents / allFailureEvents content checks -----------------

    public function testDefaultFailureEventsContainsExpectedEvents(): void
    {
        $events = NotificationTemplate::defaultFailureEvents();
        $this->assertContains(NotificationTemplate::EVENT_JOB_FAILED, $events);
        $this->assertContains(NotificationTemplate::EVENT_WORKFLOW_FAILED, $events);
        $this->assertContains(NotificationTemplate::EVENT_SCHEDULE_FAILED_TO_LAUNCH, $events);
        $this->assertContains(NotificationTemplate::EVENT_PROJECT_SYNC_FAILED, $events);
        $this->assertContains(NotificationTemplate::EVENT_RUNNER_OFFLINE, $events);
        $this->assertCount(5, $events);
    }

    public function testAllFailureEventsContainsExpectedEvents(): void
    {
        $events = NotificationTemplate::allFailureEvents();
        $this->assertContains(NotificationTemplate::EVENT_JOB_CANCELED, $events);
        $this->assertContains(NotificationTemplate::EVENT_WORKFLOW_CANCELED, $events);
        $this->assertContains(NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED, $events);
        $this->assertContains(NotificationTemplate::EVENT_APPROVAL_REJECTED, $events);
        $this->assertContains(NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, $events);
        $this->assertCount(10, $events);
    }

    // -- eventSeverity edge cases -----------------------------------------------

    public function testEventSeverityInfoForSuccessEvents(): void
    {
        $this->assertSame('info', NotificationTemplate::eventSeverity(NotificationTemplate::EVENT_WORKFLOW_LAUNCHED));
        $this->assertSame('info', NotificationTemplate::eventSeverity(NotificationTemplate::EVENT_WORKFLOW_SUCCEEDED));
        $this->assertSame('info', NotificationTemplate::eventSeverity(NotificationTemplate::EVENT_APPROVAL_APPROVED));
        $this->assertSame('info', NotificationTemplate::eventSeverity(NotificationTemplate::EVENT_RUNNER_RECOVERED));
        $this->assertSame('info', NotificationTemplate::eventSeverity(NotificationTemplate::EVENT_PROJECT_SYNC_SUCCEEDED));
    }

    // -- listensTo with whitespace in events ------------------------------------

    public function testListensToReturnsFalseForEmptyEvents(): void
    {
        $nt = new NotificationTemplate();
        $nt->events = '';
        $this->assertFalse($nt->listensTo('job.failed'));
    }

    // -- persistence round-trip ------------------------------------------------

    public function testSaveAndReloadPreservesAllFields(): void
    {
        $user = $this->createUser();
        $nt = $this->createNotificationTemplate(
            $user->id,
            NotificationTemplate::CHANNEL_TELEGRAM,
            'runner.offline,runner.recovered',
            '{"chat_id": "123"}'
        );

        $nt->refresh();
        $this->assertSame(NotificationTemplate::CHANNEL_TELEGRAM, $nt->channel);
        $this->assertSame('runner.offline,runner.recovered', $nt->events);
        $this->assertTrue($nt->listensTo('runner.offline'));
        $this->assertTrue($nt->listensTo('runner.recovered'));
        $this->assertFalse($nt->listensTo('job.failed'));
    }
}
