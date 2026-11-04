<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowTemplate;
use app\services\notification\ChannelDefinition;
use app\services\notification\ChannelRegistry;
use app\tests\integration\DbTestCase;

/**
 * The options of a notification template from migration m000091: its
 * level, its workflow scope and filter, the user it delivers as
 * (delivers_as) and the state the server keeps for its channel
 * (channel_state), with their defaults, their validation, which of them a
 * form or a request body may set, and the relations.
 */
class NotificationTemplateOptionsTest extends DbTestCase
{
    public function testTheConstants(): void
    {
        $this->assertSame(['all', 'essential'], [NotificationTemplate::LEVEL_ALL, NotificationTemplate::LEVEL_ESSENTIAL]);
        $this->assertSame(['all', 'essential'], NotificationTemplate::LEVELS);
        $this->assertSame(['all', 'selected'], [NotificationTemplate::WORKFLOW_SCOPE_ALL, NotificationTemplate::WORKFLOW_SCOPE_SELECTED]);
        $this->assertSame(['all', 'selected'], NotificationTemplate::WORKFLOW_SCOPES);
        $this->assertSame('{{%notification_template_workflow_template}}', NotificationTemplate::WORKFLOW_FILTER_TABLE);
        $this->assertSame(
            ['workflow.step_failed', 'workflow.waiting', 'workflow.succeeded', 'workflow.failed', 'workflow.canceled'],
            NotificationTemplate::ESSENTIAL_WORKFLOW_EVENTS
        );
    }

    /**
     * Every channel names the config keys that say where it delivers, and
     * only keys its channel reads.
     */
    public function testEveryChannelNamesItsDestination(): void
    {
        $destinations = array_map(static fn (ChannelDefinition $definition): ?array => $definition->destinationKeys, ChannelRegistry::installed());
        $this->assertSame(array_keys(NotificationTemplate::channelLabels()), array_keys($destinations));
        $this->assertSame([
            'email' => ['emails'],
            'slack' => ['webhook_url'],
            'teams' => ['webhook_url'],
            'webhook' => ['url', 'headers'],
            'telegram' => ['bot_token', 'chat_id'],
            'pagerduty' => ['routing_key'],
            'mattermost' => ['server_url', 'bot_token', 'channel_id', 'team', 'channel'],
        ], $destinations);
    }

    /**
     * A template the form creates is for every workflow at level all, as
     * every template stored before m000091.
     */
    public function testANewTemplateIsForEveryWorkflowAtLevelAll(): void
    {
        $user = $this->createUser('nt-options');
        $template = $this->newTemplate();
        $template->created_by = (int)$user->id;
        $template->delivers_as = (int)$user->id;

        $this->assertTrue($template->save(), (string)json_encode($template->errors));

        $this->assertSame(NotificationTemplate::LEVEL_ALL, $template->level);
        $this->assertSame(NotificationTemplate::WORKFLOW_SCOPE_ALL, $template->workflow_scope);
        $stored = $this->reload($template);
        $this->assertSame(NotificationTemplate::LEVEL_ALL, $stored->level);
        $this->assertSame(NotificationTemplate::WORKFLOW_SCOPE_ALL, $stored->workflow_scope);
        $this->assertNull($stored->channel_state);
        $this->assertSame((int)$user->id, (int)$stored->delivers_as);
        $this->assertSame([], $stored->getWorkflowTemplateIds());
    }

    public function testATemplateStoredWithoutTheOptionsGetsTheDefaults(): void
    {
        $template = $this->createNotificationTemplate((int)$this->createUser('nt-defaults')->id);

        $stored = $this->reload($template);
        $this->assertSame(NotificationTemplate::LEVEL_ALL, $stored->level);
        $this->assertSame(NotificationTemplate::WORKFLOW_SCOPE_ALL, $stored->workflow_scope);
    }

    /**
     * @dataProvider validOptions
     */
    public function testEveryLevelAndScopeIsValid(string $level, string $scope): void
    {
        $template = $this->newTemplate();
        $template->level = $level;
        $template->workflow_scope = $scope;

        $this->assertTrue($template->validate(['level', 'workflow_scope']), (string)json_encode($template->errors));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validOptions(): array
    {
        return [
            'all, all' => ['all', 'all'],
            'essential, selected' => ['essential', 'selected'],
        ];
    }

    /**
     * Only the listed words: in another case, as another type (a JSON
     * true is no level, as a loose comparison with 'all' would take it),
     * or as a list.
     *
     * @dataProvider invalidOptions
     */
    public function testValidationRefusesAnotherLevelOrScope(string $attribute, mixed $value): void
    {
        $template = $this->newTemplate();
        $template->$attribute = $value;

        $this->assertFalse($template->validate([$attribute]));
        $this->assertTrue($template->hasErrors($attribute));
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidOptions(): array
    {
        return [
            'level: an unknown word' => ['level', 'failures'],
            'level: in capitals' => ['level', 'ALL'],
            'level: true' => ['level', true],
            'level: a list' => ['level', ['all']],
            'scope: an unknown word' => ['workflow_scope', 'some'],
            'scope: true' => ['workflow_scope', true],
            'scope: a list' => ['workflow_scope', ['selected']],
        ];
    }

    /**
     * The server sets delivers_as and channel_state, and the create actions
     * created_by: none of them comes from a form. The level and the
     * workflow scope do.
     */
    public function testChannelStateAndTheUsersAreNotMassAssignable(): void
    {
        $owner = $this->createUser('nt-owner');
        $other = $this->createUser('nt-other');
        $template = $this->reload($this->createNotificationTemplate((int)$owner->id));

        $this->assertTrue($template->load(['NotificationTemplate' => [
            'channel_state' => '{"channel_id":"stolen"}',
            'delivers_as' => (string)$other->id,
            'created_by' => (string)$other->id,
            'level' => NotificationTemplate::LEVEL_ESSENTIAL,
            'workflow_scope' => NotificationTemplate::WORKFLOW_SCOPE_SELECTED,
        ]]));

        $this->assertNull($template->channel_state);
        $this->assertSame((int)$owner->id, (int)$template->delivers_as);
        $this->assertSame((int)$owner->id, (int)$template->created_by);
        $this->assertSame(NotificationTemplate::LEVEL_ESSENTIAL, $template->level);
        $this->assertSame(NotificationTemplate::WORKFLOW_SCOPE_SELECTED, $template->workflow_scope);
        $this->assertNotContains('channel_state', $template->safeAttributes());
        $this->assertNotContains('delivers_as', $template->safeAttributes());
        $this->assertNotContains('created_by', $template->safeAttributes());
    }

    public function testTheUserItDeliversAsIsARelation(): void
    {
        $owner = $this->createUser('nt-rel');
        $template = $this->reload($this->createNotificationTemplate((int)$owner->id));

        $this->assertInstanceOf(User::class, $template->deliversAsUser);
        $this->assertSame((int)$owner->id, (int)$template->deliversAsUser->id);
    }

    /**
     * The relation lists the live workflow templates of the filter; a
     * deleted one stays in the filter's IDs.
     */
    public function testTheWorkflowTemplatesOfTheFilterAreARelation(): void
    {
        $owner = $this->createUser('nt-wt');
        $template = $this->createNotificationTemplate((int)$owner->id);
        $live = $this->createWorkflowTemplate((int)$owner->id);
        $deleted = $this->createWorkflowTemplate((int)$owner->id);
        $this->createWorkflowTemplate((int)$owner->id);
        foreach ([$live, $deleted] as $workflow) {
            \Yii::$app->db->createCommand()->insert(NotificationTemplate::WORKFLOW_FILTER_TABLE, [
                'notification_template_id' => $template->id,
                'workflow_template_id' => $workflow->id,
            ])->execute();
        }
        $deleted->softDelete();

        $stored = $this->reload($template);
        $this->assertSame([(int)$live->id], array_map(static fn (WorkflowTemplate $w): int => (int)$w->id, $stored->workflowTemplates));
        $expected = [(int)$live->id, (int)$deleted->id];
        sort($expected);
        $this->assertSame($expected, $stored->getWorkflowTemplateIds());
    }

    public function testTheDeliveriesAreARelation(): void
    {
        $owner = $this->createUser('nt-deliveries');
        $template = $this->createNotificationTemplate((int)$owner->id);
        $other = $this->createNotificationTemplate((int)$owner->id);
        $mine = $this->insertDelivery((int)$template->id);
        $this->insertDelivery((int)$other->id);

        $this->assertSame([$mine], array_map(static fn (NotificationDelivery $d): int => (int)$d->id, $this->reload($template)->deliveries));
    }

    /**
     * A template delivers nothing while the user it delivers as is no
     * active account: deactivated, or never saved.
     */
    public function testATemplateDeliversAsAnInactiveUserWhileTheAccountIsInactive(): void
    {
        $owner = $this->createUser('nt-inactive');
        $template = $this->reload($this->createNotificationTemplate((int)$owner->id));
        $this->assertFalse($template->isDeliveringAsInactive());

        $owner->status = User::STATUS_INACTIVE;
        $owner->save(false);
        $this->assertTrue($template->isDeliveringAsInactive());

        $owner->status = User::STATUS_ACTIVE;
        $owner->save(false);
        $this->assertFalse($template->isDeliveringAsInactive());

        $this->assertTrue($this->newTemplate()->isDeliveringAsInactive(), 'nobody yet');
    }

    /**
     * The level holds back only workflow events: at level essential, a
     * failed step and the run's end; every event at level all.
     */
    public function testTheLevelHoldsBackTheWorkflowEventsThatAreNotEssential(): void
    {
        $all = $this->newTemplate();
        $all->level = NotificationTemplate::LEVEL_ALL;
        $essential = $this->newTemplate();
        $essential->level = NotificationTemplate::LEVEL_ESSENTIAL;
        $unknown = $this->newTemplate();
        $unknown->level = 'something else';

        foreach (array_keys(NotificationTemplate::eventLabels()) as $event) {
            $workflow = str_starts_with($event, 'workflow.');
            $essentialEvent = in_array($event, NotificationTemplate::ESSENTIAL_WORKFLOW_EVENTS, true);
            $this->assertSame($workflow, NotificationTemplate::isWorkflowEvent($event), $event);
            $this->assertTrue($all->isWithinLevel($event), "all: {$event}");
            $this->assertSame(!$workflow || $essentialEvent, $essential->isWithinLevel($event), "essential: {$event}");
            $this->assertSame(!$workflow || $essentialEvent, $unknown->isWithinLevel($event), "another level counts as essential: {$event}");
        }
        $this->assertFalse($essential->isWithinLevel(NotificationTemplate::EVENT_WORKFLOW_LAUNCHED));
        $this->assertTrue($essential->isWithinLevel(NotificationTemplate::EVENT_WORKFLOW_STEP_FAILED));
        $this->assertTrue($essential->isWithinLevel(NotificationTemplate::EVENT_JOB_LAUNCHED));
        // Spec section 4: essential is a failed step, a run that waits for a person, and the run's end.
        $this->assertTrue($essential->isWithinLevel(NotificationTemplate::EVENT_WORKFLOW_WAITING));
        $this->assertFalse($essential->isWithinLevel(NotificationTemplate::EVENT_WORKFLOW_STEP_STARTED));
        $this->assertFalse($essential->isWithinLevel(NotificationTemplate::EVENT_WORKFLOW_STEP_SUCCEEDED));
    }

    /**
     * A template whose level and workflow scope were never set, such as one
     * built in memory, has the columns' defaults: every event, every
     * workflow.
     */
    public function testUnsetOptionsAreTheDefaults(): void
    {
        $template = $this->newTemplate();
        $this->assertNull($template->level);
        $this->assertNull($template->workflow_scope);

        $this->assertTrue($template->isWithinLevel(NotificationTemplate::EVENT_WORKFLOW_LAUNCHED));
        $this->assertTrue($template->isForWorkflowTemplate(987654321));
    }

    private function newTemplate(): NotificationTemplate
    {
        $template = new NotificationTemplate();
        $template->name = 'nt-options-' . uniqid('', true);
        $template->channel = NotificationTemplate::CHANNEL_EMAIL;
        $template->config = '{"emails":["ops@example.com"]}';
        $template->events = 'job.failed,workflow.failed';

        return $template;
    }

    private function reload(NotificationTemplate $template): NotificationTemplate
    {
        $stored = NotificationTemplate::findOne($template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);

        return $stored;
    }

    private function insertDelivery(int $templateId): int
    {
        \Yii::$app->db->createCommand()->insert(NotificationDelivery::tableName(), [
            'notification_template_id' => $templateId,
            'event' => NotificationTemplate::EVENT_JOB_FAILED,
            'variables' => '{}',
            'next_attempt_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();

        return (int)\Yii::$app->db->getLastInsertID();
    }
}
