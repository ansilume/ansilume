<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\components\SurveySecrets;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowTemplate;
use app\services\notification\NotificationIdentity;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Whom a notification template delivers as (NotificationIdentity): the user
 * who last changed what it delivers or where to, never its creator merely
 * because they created it.
 *
 * Regression (Review focus 5 of the workflow notifications plan): templates
 * were global, and the creator was the only user named on a template, so an
 * operator who edited an admin's template chose where it delivered while it
 * delivered what the admin may see: the events of every team. Now the
 * operator who changes what or where it delivers becomes the user it
 * delivers as, and it delivers only what the operator may see.
 */
class NotificationIdentityTest extends DbTestCase
{
    use TeamScopeFixtures;

    private const SHOWN = SurveySecrets::PLACEHOLDER;

    /** A bot token of the form Mattermost gives one: 26 lower-case letters and digits. */
    private const MATTERMOST_TOKEN = 'abcdefghijklmnopqrstuvwxyz';

    /** A channel ID of the form Mattermost gives one. */
    private const MATTERMOST_CHANNEL_ID = 'zyxwvutsrqponmlkjihgfedcba';

    private User $admin;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('identity-admin', 'admin');
        $this->operator = $this->createUserWithRole('identity-operator', 'operator');
    }

    /**
     * Changing a key that says where the template delivers, of any channel,
     * makes the editor the user it delivers as, recorded as {from, to}.
     *
     * @dataProvider destinationChanges
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $submitted
     */
    public function testEditingTheDestinationMakesTheEditorTheIdentity(string $channel, array $stored, array $submitted): void
    {
        $template = $this->adminTemplate($channel, $stored);
        $identity = new NotificationIdentity($template);
        $template->config = (string)json_encode($submitted, JSON_UNESCAPED_SLASHES);
        $this->assertTrue($template->validate(), (string)json_encode($template->errors));

        $this->assertTrue($identity->changesIdentity());
        $identity->apply((int)$this->operator->id);
        $this->assertTrue($template->save(false));

        $this->assertSame((int)$this->operator->id, (int)$this->reload($template)->delivers_as);
        $this->assertSame(
            ['delivers_as' => ['from' => (int)$this->admin->id, 'to' => (int)$this->operator->id]],
            $identity->auditContext()
        );
        $this->assertSame('delivers_as', NotificationIdentity::AUDIT_KEY);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    public static function destinationChanges(): array
    {
        $headers = ['url' => 'https://hooks.example.com/a', 'headers' => ['Authorization' => 'Bearer a']];

        return [
            'email recipients' => ['email', ['emails' => ['ops@example.com']], ['emails' => ['me@example.com']]],
            'slack url' => ['slack', ['webhook_url' => 'https://hooks.slack.com/services/A'], ['webhook_url' => 'https://hooks.slack.com/services/B']],
            'teams url' => ['teams', ['webhook_url' => 'https://example.webhook.office.com/a'], ['webhook_url' => 'https://example.webhook.office.com/b']],
            'webhook url' => ['webhook', ['url' => 'https://hooks.example.com/a'], ['url' => 'https://hooks.example.com/b']],
            'webhook header' => ['webhook', $headers, ['url' => 'https://hooks.example.com/a', 'headers' => ['Authorization' => 'Bearer b']]],
            'webhook header added' => ['webhook', ['url' => 'https://hooks.example.com/a'], $headers],
            'webhook header removed' => ['webhook', $headers, ['url' => self::SHOWN]],
            'webhook header emptied' => ['webhook', $headers, ['url' => self::SHOWN, 'headers' => ['Authorization' => '']]],
            'webhook header renamed' => ['webhook', $headers, ['url' => self::SHOWN, 'headers' => ['X-Authorization' => 'Bearer a']]],
            'webhook header added beside one' => ['webhook', $headers, ['url' => self::SHOWN, 'headers' => ['Authorization' => self::SHOWN, 'X-Team' => 'b']]],
            'webhook header added to an empty map' => ['webhook', ['url' => 'https://hooks.example.com/a', 'headers' => []], $headers],
            'telegram bot' => ['telegram', ['bot_token' => '1:a', 'chat_id' => '-100'], ['bot_token' => '2:b', 'chat_id' => '-100']],
            'telegram chat' => ['telegram', ['bot_token' => '1:a', 'chat_id' => '-100'], ['bot_token' => self::SHOWN, 'chat_id' => '-200']],
            'telegram chat, another number' => ['telegram', ['bot_token' => '1:a', 'chat_id' => -100], ['bot_token' => self::SHOWN, 'chat_id' => -200]],
            'telegram chat, from a number to other text' => ['telegram', ['bot_token' => '1:a', 'chat_id' => -100], ['bot_token' => self::SHOWN, 'chat_id' => '-1000']],
            'email recipient added' => ['email', ['emails' => ['ops@example.com']], ['emails' => ['ops@example.com', 'me@example.com']]],
            'email recipients reordered' => ['email', ['emails' => ['ops@example.com', 'me@example.com']], ['emails' => ['me@example.com', 'ops@example.com']]],
            'mattermost channel by its ID instead of its names' => [
                'mattermost',
                ['server_url' => 'https://chat.example.com', 'bot_token' => self::MATTERMOST_TOKEN, 'team' => 'ops', 'channel' => 'alerts'],
                ['server_url' => 'https://chat.example.com', 'bot_token' => self::SHOWN, 'channel_id' => self::MATTERMOST_CHANNEL_ID],
            ],
            'pagerduty routing key' => ['pagerduty', ['routing_key' => 'key-a'], ['routing_key' => 'key-b']],
        ];
    }

    /**
     * Regression (review of the workflow notifications, access finding 1):
     * the destination was compared by the JSON values of its keys, so a
     * save that changed nothing about where the template delivers made its
     * saver the user it delivers as: the edit form of 2.14 stored a webhook
     * template's headers as {} (read as []), and the form of now posts no
     * headers key for none; a Telegram chat stored as a number comes back
     * from the form as its text. An admin who renamed an operator's webhook
     * template so made it deliver the events of every team to the
     * operator's webhook; an operator who renamed an admin's, only those of
     * the operator's teams. Now a missing key, null, '' and an empty list or
     * map are the same, a scalar is compared by its text, as the channels
     * read it, and a map whatever the order of its keys.
     *
     * @dataProvider sameDestinations
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $submitted
     */
    public function testRegressionTheSameDestinationInAnotherShapeKeepsTheIdentity(string $channel, array $stored, array $submitted): void
    {
        $template = $this->adminTemplate($channel, $stored);
        $identity = new NotificationIdentity($template);
        $template->name = 'renamed';
        $template->config = (string)json_encode($submitted, JSON_UNESCAPED_SLASHES);
        $this->assertTrue($template->validate(), (string)json_encode($template->errors));

        $this->assertFalse($identity->changesIdentity());
        $this->assertSame((int)$this->admin->id, (int)$template->delivers_as);
        $this->assertSame([], $identity->auditContext());
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    public static function sameDestinations(): array
    {
        $url = 'https://hooks.example.com/a';
        $headers = ['Authorization' => 'Bearer a', 'X-Team' => 'a'];

        return [
            // What the edit form of 2.14 stored, and what the form of now posts back for it.
            'webhook headers stored as {} (read as []), posted without the key' => ['webhook', ['url' => $url, 'headers' => new \stdClass()], ['url' => self::SHOWN]],
            'webhook headers stored as [], posted without the key' => ['webhook', ['url' => $url, 'headers' => []], ['url' => self::SHOWN]],
            'webhook headers stored as null, posted as {}' => ['webhook', ['url' => $url, 'headers' => null], ['url' => self::SHOWN, 'headers' => new \stdClass()]],
            'webhook without headers, posted with an empty map' => ['webhook', ['url' => $url], ['url' => self::SHOWN, 'headers' => []]],
            'webhook headers in another order' => [
                'webhook',
                ['url' => $url, 'headers' => $headers],
                ['url' => self::SHOWN, 'headers' => ['X-Team' => self::SHOWN, 'Authorization' => self::SHOWN]],
            ],
            // The form shows a number in a text field and posts its text.
            'telegram chat stored as a number, posted as its text' => ['telegram', ['bot_token' => '1:a', 'chat_id' => -1001234567890], ['bot_token' => self::SHOWN, 'chat_id' => '-1001234567890']],
            'telegram chat stored as text, sent as its number' => ['telegram', ['bot_token' => '1:a', 'chat_id' => '-100'], ['bot_token' => self::SHOWN, 'chat_id' => -100]],
            'mattermost channel ID stored as "", posted without the key' => [
                'mattermost',
                ['server_url' => 'https://chat.example.com', 'bot_token' => self::MATTERMOST_TOKEN, 'channel_id' => '', 'team' => 'ops', 'channel' => 'alerts'],
                ['server_url' => 'https://chat.example.com', 'bot_token' => self::SHOWN, 'team' => 'ops', 'channel' => 'alerts'],
            ],
        ];
    }

    /**
     * For a channel without destination keys every key says where it
     * delivers, each compared as a destination key is.
     */
    public function testEveryKeyOfAChannelWithoutDestinationKeysIsComparedByWhatItMeans(): void
    {
        $template = $this->adminTemplate('email', ['emails' => ['ops@example.com']]);
        NotificationTemplate::updateAll(['channel' => 'carrier_pigeon', 'config' => '{"loft":"a","ring":7,"route":{"b":"2","a":"1"},"note":null}'], ['id' => $template->id]);

        $same = $this->reload($template);
        $identity = new NotificationIdentity($same);
        $same->config = '{"route":{"a":"1","b":"2"},"loft":"a","ring":"7"}';
        $this->assertFalse($identity->changesIdentity(), 'the same values, as text, in another order, and no note');

        $other = $this->reload($template);
        $changed = new NotificationIdentity($other);
        $other->config = '{"loft":"a","ring":7,"route":{"b":"2","a":"1"},"note":"x"}';
        $this->assertTrue($changed->changesIdentity(), 'a note now');
    }

    /**
     * Renaming, the description, the subject and the body, another key of
     * the config, the secrets sent back as the placeholder, a reordering of
     * the events and a save that changes nothing keep the user it delivers
     * as.
     *
     * @dataProvider keepingChanges
     *
     * @param array<string, mixed> $attributes
     */
    public function testRenamingKeepsTheIdentity(array $attributes): void
    {
        $template = $this->adminTemplate('webhook', ['url' => 'https://hooks.example.com/a', 'headers' => ['Authorization' => 'Bearer a']]);
        $identity = new NotificationIdentity($template);
        $template->setAttributes($attributes, false);
        $this->assertTrue($template->validate(), (string)json_encode($template->errors));

        $this->assertFalse($identity->changesIdentity());
        $this->assertSame((int)$this->admin->id, (int)$template->delivers_as);
        $this->assertSame([], $identity->auditContext());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function keepingChanges(): array
    {
        return [
            'nothing' => [[]],
            'the name' => [['name' => 'renamed']],
            'the description, subject and body' => [['description' => 'd', 'subject_template' => 's {{ event }}', 'body_template' => 'b']],
            'the secrets sent back' => [['config' => '{"url":"' . self::SHOWN . '","headers":{"Authorization":"' . self::SHOWN . '"}}']],
            'another key of the config' => [['config' => '{"url":"https://hooks.example.com/a","headers":{"Authorization":"Bearer a"},"note":"x"}']],
            // How a request is signed and shaped says nothing about where it goes or what it carries.
            'a signing secret and the event payload' => [['config' => '{"url":"https://hooks.example.com/a","headers":{"Authorization":"Bearer a"},"signing_secret":"whsec-new-1","payload":"event"}']],
            'the events reordered' => [['events' => 'workflow.failed, job.failed,job.failed']],
        ];
    }

    /**
     * What the template delivers: its channel, its events, its level, its
     * workflow scope and its workflow filter.
     *
     * @dataProvider whatChanges
     *
     * @param array<string, mixed> $attributes
     */
    public function testChangingWhatItDeliversMakesTheEditorTheIdentity(array $attributes): void
    {
        $template = $this->adminTemplate('email', ['emails' => ['ops@example.com']]);
        $identity = new NotificationIdentity($template);
        $template->setAttributes($attributes, false);
        $this->assertTrue($template->validate(), (string)json_encode($template->errors));

        $this->assertTrue($identity->changesIdentity());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function whatChanges(): array
    {
        return [
            'the channel' => [['channel' => 'pagerduty', 'config' => '{"emails":["ops@example.com"],"routing_key":"k"}']],
            'an event added' => [['events' => 'job.failed,workflow.failed,job.succeeded']],
            'an event removed' => [['events' => 'job.failed']],
            'the level' => [['level' => NotificationTemplate::LEVEL_ESSENTIAL]],
            'the workflow scope' => [['workflow_scope' => NotificationTemplate::WORKFLOW_SCOPE_SELECTED]],
        ];
    }

    public function testChangingTheWorkflowFilterMakesTheEditorTheIdentity(): void
    {
        $template = $this->adminTemplate('email', ['emails' => ['ops@example.com']]);
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $identity = new NotificationIdentity($template);

        $this->assertSame([], $template->setWorkflowTemplateIds([(int)$workflow->id], (int)$this->operator->id));

        $this->assertTrue($identity->changesIdentity());
        $unchanged = new NotificationIdentity($template);
        $template->setWorkflowTemplateIds([(int)$workflow->id], (int)$this->operator->id);
        $this->assertFalse($unchanged->changesIdentity(), 'the same filter again');
    }

    /**
     * Regression: an operator who edited an admin's template, its
     * destination here, inherited the admin's view of every team. Now the
     * operator becomes the user it delivers as.
     */
    public function testRegressionAnEditorCannotInheritTheCreatorsIdentity(): void
    {
        $template = $this->adminTemplate('slack', ['webhook_url' => 'https://hooks.slack.com/services/ADMIN']);
        $this->assertSame((int)$this->admin->id, (int)$template->delivers_as);
        $identity = new NotificationIdentity($template);
        $template->load(['NotificationTemplate' => [
            'config' => '{"webhook_url":"https://hooks.slack.com/services/OPERATOR"}',
            'delivers_as' => (string)$this->admin->id,
        ]]);
        $this->assertTrue($template->validate());

        $this->assertTrue($identity->changesIdentity());
        $identity->apply((int)$this->operator->id);
        $template->save(false);

        $stored = $this->reload($template);
        $this->assertSame((int)$this->operator->id, (int)$stored->delivers_as);
        $this->assertSame((int)$this->admin->id, (int)$stored->created_by, 'the creator stays');
    }

    /**
     * The editor's selection replaces only what the editor may see of the
     * filter: the workflow templates of other teams stay selected, and the
     * template delivers as the editor, who cannot see them, so their runs
     * reach it no more.
     */
    public function testFilterKeepsWorkflowsTheEditorCannotSee(): void
    {
        $scope = $this->teamScope();
        $adminId = (int)$scope['admin']->id;
        $memberId = (int)$scope['member']->id;
        $own = $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id);
        $open = $this->createWorkflowWithJobSteps($adminId, (int)$scope['open']->id);
        $foreign = $this->createWorkflowWithJobSteps($adminId, (int)$scope['foreign']->id);
        $template = $this->createNotificationTemplate($adminId);
        $template->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $template->save(false);
        $template->setWorkflowTemplateIds([(int)$own->id, (int)$foreign->id], $adminId);
        $template = $this->reload($template);
        $identity = new NotificationIdentity($template);

        $refused = $template->setWorkflowTemplateIds([(int)$open->id], $memberId);
        $this->assertTrue($template->validate());
        $this->assertTrue($identity->changesIdentity());
        $identity->apply($memberId);
        $template->save(false);

        $this->assertSame([], $refused);
        $expected = [(int)$open->id, (int)$foreign->id];
        sort($expected);
        $stored = $this->reload($template);
        $this->assertSame($expected, $stored->getWorkflowTemplateIds(), 'the foreign workflow stays selected');
        $this->assertSame($memberId, (int)$stored->delivers_as);
        $this->assertTrue($stored->isForWorkflowTemplate((int)$foreign->id), 'the filter lets it through');
        $this->assertFalse($stored->isForWorkflowTemplate((int)$own->id), 'the editor removed it');
    }

    /**
     * A selected filter whose workflow templates were all deleted matches
     * nothing: it never turns into "every workflow". Deleted from the
     * database, they leave the filter; deleted in Ansilume (a soft delete),
     * they stay in it, so the runs canceled by the delete still reach the
     * template, and no other workflow does.
     */
    public function testASelectedFilterWhoseWorkflowsWereDeletedMatchesNothing(): void
    {
        $adminId = (int)$this->admin->id;
        $purged = $this->createWorkflowTemplate($adminId);
        $deleted = $this->createWorkflowTemplate($adminId);
        $other = $this->createWorkflowTemplate($adminId);
        $template = $this->createNotificationTemplate($adminId);
        $template->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $template->save(false);
        $template->setWorkflowTemplateIds([(int)$purged->id, (int)$deleted->id], $adminId);

        WorkflowTemplate::deleteAll(['id' => $purged->id]);
        $deleted->softDelete();

        $stored = $this->reload($template);
        $this->assertSame([(int)$deleted->id], $stored->getWorkflowTemplateIds());
        $this->assertFalse($stored->isForWorkflowTemplate((int)$other->id));
        $this->assertFalse($stored->isForWorkflowTemplate((int)$purged->id));
        $this->assertTrue($stored->isForWorkflowTemplate((int)$deleted->id), 'the runs its delete canceled');

        WorkflowTemplate::deleteAll(['id' => $deleted->id]);
        $empty = $this->reload($template);
        $this->assertSame([], $empty->getWorkflowTemplateIds());
        $this->assertSame(NotificationTemplate::WORKFLOW_SCOPE_SELECTED, $empty->workflow_scope);
        foreach ([$purged, $deleted, $other] as $workflow) {
            $this->assertFalse($empty->isForWorkflowTemplate((int)$workflow->id), "workflow template #{$workflow->id}");
        }
    }

    /**
     * Whoever saves a new template is the user it delivers as; there was
     * nobody before, so nothing is recorded.
     */
    public function testANewTemplateDeliversAsWhoeverSavesIt(): void
    {
        $template = new NotificationTemplate();
        $identity = new NotificationIdentity($template);

        $this->assertTrue($identity->changesIdentity());
        $identity->apply((int)$this->operator->id);

        $this->assertSame((int)$this->operator->id, $template->delivers_as);
        $this->assertSame([], $identity->auditContext());
    }

    /**
     * A template that delivers as an inactive user delivers nothing; saving
     * it, even unchanged, makes the saver the user it delivers as.
     */
    public function testSavingATemplateThatDeliversAsAnInactiveUserMakesTheSaverItsIdentity(): void
    {
        $template = $this->adminTemplate('email', ['emails' => ['ops@example.com']]);
        $this->admin->status = User::STATUS_INACTIVE;
        $this->admin->save(false);
        $identity = new NotificationIdentity($template);
        $template->name = 'saved again';
        $this->assertTrue($template->validate());

        $this->assertTrue($identity->changesIdentity());
        $identity->apply((int)$this->operator->id);

        $this->assertSame(['delivers_as' => ['from' => (int)$this->admin->id, 'to' => (int)$this->operator->id]], $identity->auditContext());
    }

    public function testApplyingTheUserItDeliversAsAlreadyRecordsNothing(): void
    {
        $template = $this->adminTemplate('email', ['emails' => ['ops@example.com']]);
        $identity = new NotificationIdentity($template);
        $template->events = 'job.failed';
        $this->assertTrue($identity->changesIdentity());

        $identity->apply((int)$this->admin->id);

        $this->assertSame((int)$this->admin->id, (int)$template->delivers_as);
        $this->assertSame([], $identity->auditContext());
        $this->assertSame([], $template->getDirtyAttributes(['delivers_as']));
    }

    /**
     * A config that is no JSON object, stored or submitted (a stored one
     * from before the validation, or one the validation will refuse),
     * counts as changed unless its text stays the same.
     */
    public function testAConfigThatIsNoJsonObjectCountsAsChangedUnlessItStaysTheSame(): void
    {
        $template = $this->adminTemplate('email', ['emails' => ['ops@example.com']]);
        NotificationTemplate::updateAll(['config' => 'not json'], ['id' => $template->id]);
        $stored = $this->reload($template);

        $this->assertFalse((new NotificationIdentity($stored))->changesIdentity(), 'the same text');

        $identity = new NotificationIdentity($stored);
        $stored->config = '{"emails":["ops@example.com"]}';
        $this->assertTrue($identity->changesIdentity());

        $fixed = $this->adminTemplate('email', ['emails' => ['ops@example.com']]);
        $identity = new NotificationIdentity($fixed);
        $fixed->config = null;
        $this->assertTrue($identity->changesIdentity());
    }

    /**
     * For a channel that names no destination keys, every key of the
     * config says where the template delivers.
     */
    public function testEveryKeyIsTheDestinationOfAChannelWithoutDestinationKeys(): void
    {
        $template = $this->adminTemplate('email', ['emails' => ['ops@example.com']]);
        NotificationTemplate::updateAll(['channel' => 'carrier_pigeon', 'config' => '{"loft":"a","note":"x"}'], ['id' => $template->id]);
        $stored = $this->reload($template);

        $identity = new NotificationIdentity($stored);
        $stored->config = '{"loft":"a","note":"y"}';
        $this->assertTrue($identity->changesIdentity());

        $again = $this->reload($template);
        $same = new NotificationIdentity($again);
        $again->config = '{"loft":"a","note":"x"}';
        $this->assertFalse($same->changesIdentity());
    }

    /**
     * A template of $this->admin on $channel with $config, as stored.
     *
     * @param array<string, mixed> $config
     */
    private function adminTemplate(string $channel, array $config): NotificationTemplate
    {
        $template = $this->createNotificationTemplate(
            (int)$this->admin->id,
            $channel,
            'job.failed,workflow.failed',
            (string)json_encode($config, JSON_UNESCAPED_SLASHES)
        );

        return $this->reload($template);
    }

    private function reload(NotificationTemplate $template): NotificationTemplate
    {
        $stored = NotificationTemplate::findOne($template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);

        return $stored;
    }
}
