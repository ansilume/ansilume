<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\components\NotificationConfigSecrets;
use app\components\SurveySecrets;
use app\models\Job;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\NotificationDispatcher;
use app\services\notification\ChannelDefinition;
use app\services\notification\ChannelRegistry;
use app\services\notification\EmailChannel;
use app\services\notification\JobPayloadBuilder;
use app\services\notification\MattermostChannel;
use app\services\notification\MattermostConfig;
use app\services\notification\NotificationDeliveryService;
use app\services\notification\NotificationIdentity;
use app\services\notification\WebhookChannel;
use app\services\notification\WebhookConfig;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\base\InvalidConfigException;

/**
 * The channels of notification templates are defined, not hard-coded
 * (spec section 6): each channel by a ChannelDefinition of its label, its
 * class, its required keys, its secrets and what they go along with, its
 * URLs and their schemes, the keys that say where it delivers and its
 * config rules; the built-in channels in ChannelRegistry::BUILT_IN, a
 * channel of an installation's own in the configuration of the component
 * notificationDispatcher (the file config/notification-channels.php, its
 * channelsFile; channels in the tests), defined the same way. The model,
 * the secrets, the identity and the delivery read the definitions, so a
 * configured channel is saved, validated, redacted, bound and delivered as
 * a built-in one; a definition that is wrong stops the application at its
 * start, with a message that says what is wrong.
 */
class ChannelRegistryTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;

    private const SHOWN = SurveySecrets::PLACEHOLDER;

    private const API_KEY = 'acme-api-key-secret-4471';

    /** A channel of one's own, as an installation configures it. */
    private const ACME = [
        'label' => 'Acme Pager',
        'class' => ConfiguredTestChannel::class,
        'requiredKeys' => ['endpoint', 'api_key'],
        'secretKeys' => ['api_key'],
        'boundTo' => ['api_key' => 'endpoint'],
        'urlSchemes' => ['endpoint' => ['https']],
        'destinationKeys' => ['endpoint', 'api_key'],
        'configRules' => ConfiguredTestChannelRules::class,
    ];

    private const CONFIG = ['endpoint' => 'https://pager.example.com/alerts', 'api_key' => self::API_KEY, 'priority' => 'high'];

    private RecordingNotificationQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new RecordingNotificationQueue();
        $this->swapComponent('notificationQueue', $this->queue);
        $this->swapComponent('notificationDispatcher', ['class' => NotificationDispatcher::class, 'channels' => ['acme' => self::ACME]]);
        $this->swapComponent('notificationDeliveryService', ['class' => NotificationDeliveryService::class]);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    // -- A configured channel ---------------------------------------------------------

    /**
     * The form and the API offer the configured channel by its label; its
     * required keys, the scheme of its URL and its own rules are checked
     * when a template is saved, as a built-in channel's are.
     */
    public function testAConfiguredChannelIsSavedAndValidated(): void
    {
        $this->assertSame('Acme Pager', NotificationTemplate::channelLabels()['acme'] ?? null);
        $this->assertSame('Acme Pager', NotificationTemplate::channelLabel('acme'));

        $missing = $this->newTemplate(['endpoint' => 'https://pager.example.com/alerts']);
        $this->assertFalse($missing->validate());
        $this->assertSame(['config' => ['api_key is required for Acme Pager.']], $missing->getErrors());

        $http = $this->newTemplate(['endpoint' => 'http://pager.example.com/alerts', 'api_key' => self::API_KEY, 'priority' => 'urgent']);
        $this->assertFalse($http->validate());
        $this->assertSame(
            ['config' => [sprintf(NotificationTemplate::HTTPS_REQUIRED, 'endpoint'), ConfiguredTestChannelRules::PRIORITY_REFUSED]],
            $http->getErrors()
        );

        $valid = $this->newTemplate(self::CONFIG);
        $valid->created_by = $valid->delivers_as = (int)$this->createUser('registry-saver')->id;
        $this->assertTrue($valid->save(), (string)json_encode($valid->getErrors()));
        $this->assertSame(self::CONFIG, $this->reload($valid)->getParsedConfig());
    }

    /**
     * Its secret is shown as the placeholder, in its config and in any
     * other, and keeps its stored value only while the key it is bound to
     * keeps its own.
     */
    public function testAConfiguredChannelsSecretIsRedactedAndBound(): void
    {
        $stored = $this->storedTemplate('acme', self::CONFIG);
        $this->assertSame(['endpoint' => 'https://pager.example.com/alerts', 'api_key' => self::SHOWN, 'priority' => 'high'], $stored->getRedactedConfig());
        $leftover = $this->storedTemplate(NotificationTemplate::CHANNEL_EMAIL, ['emails' => ['ops@example.com'], 'api_key' => self::API_KEY]);
        $this->assertSame(['emails' => ['ops@example.com'], 'api_key' => self::SHOWN], $leftover->getRedactedConfig(), 'a secret key of one channel is redacted in every config');

        $kept = $this->storedTemplate('acme', self::CONFIG);
        $kept->config = (string)json_encode(['endpoint' => 'https://pager.example.com/alerts', 'api_key' => self::SHOWN, 'priority' => 'low']);
        $this->assertNull((new NotificationConfigSecrets($kept))->restore());
        $this->assertSame(self::API_KEY, $kept->getParsedConfig()['api_key'] ?? null);

        $moved = $this->storedTemplate('acme', self::CONFIG);
        $moved->config = (string)json_encode(['endpoint' => 'https://collector.example.net/steal', 'api_key' => self::SHOWN]);
        $this->assertFalse($moved->validate());
        $this->assertSame(['config' => ['api_key: endpoint changed; enter the real value.']], $moved->getErrors());
    }

    /**
     * Its destination keys decide whom it delivers as: a change of one makes
     * the editor its identity, a change of another key does not.
     */
    public function testItsDestinationKeysDecideWhomItDeliversAs(): void
    {
        $destination = $this->storedTemplate('acme', self::CONFIG);
        $identity = new NotificationIdentity($destination);
        $destination->config = (string)json_encode(['endpoint' => 'https://pager.example.com/other'] + self::CONFIG);
        $this->assertTrue($identity->changesIdentity());

        $priority = $this->storedTemplate('acme', self::CONFIG);
        $same = new NotificationIdentity($priority);
        $priority->config = (string)json_encode(['priority' => 'low'] + self::CONFIG);
        $this->assertFalse($same->changesIdentity());
    }

    /**
     * Its class receives the deliveries of its templates through the
     * notification worker, and Send Test.
     */
    public function testAConfiguredChannelReceivesADelivery(): void
    {
        $admin = $this->createUserWithRole('registry-admin', 'admin');
        $template = $this->storedTemplate('acme', self::CONFIG, $admin);
        $job = $this->failedJobOf($admin);

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_JOB_FAILED, JobPayloadBuilder::build($job));
        $this->queue->run();
        $this->assertNull($this->dispatcher()->sendSingle($template, ['job.id' => '1', 'job.status' => 'failed']));

        $channel = $this->dispatcher()->channel('acme');
        $this->assertInstanceOf(ConfiguredTestChannel::class, $channel);
        $this->assertCount(2, $channel->calls);
        [$delivered, $tested] = $channel->calls;
        $this->assertSame([(int)$template->id, 'Job #' . $job->id . ' failed'], [$delivered['template'], $delivered['subject']]);
        $this->assertSame((string)$job->id, $delivered['variables']['job.id'] ?? null);
        $this->assertSame('Job #1 failed', $tested['subject']);
        $delivery = NotificationDelivery::findOne(['notification_template_id' => $template->id]);
        $this->assertInstanceOf(NotificationDelivery::class, $delivery);
        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $delivery->status);
    }

    /**
     * A template of a channel that the installation no longer has (its
     * definition was taken out of the configuration) shows no value of its
     * config: what was a secret of that channel is no longer known as one.
     */
    public function testTheConfigOfAChannelNoLongerDefinedShowsNoValue(): void
    {
        $template = $this->storedTemplate('acme', self::CONFIG);
        $this->swapComponent('notificationDispatcher', ['class' => NotificationDispatcher::class]);

        $this->assertSame(['endpoint' => self::SHOWN, 'api_key' => self::SHOWN, 'priority' => self::SHOWN], $this->reload($template)->getRedactedConfig());
        $this->assertNull($this->dispatcher()->channel('acme'));
        $this->assertArrayNotHasKey('acme', NotificationTemplate::channelLabels());
        $stale = $this->reload($template);
        $this->assertFalse($stale->validate());
        $this->assertSame(['channel' => ['Channel is invalid.']], $stale->getErrors(), 'the API answers it as 422, as the spec says');
    }

    // -- The built-in channels -------------------------------------------------------------

    /**
     * The built-in channels are defined as a configured one is, in the
     * order of the form, each with its class.
     */
    public function testTheBuiltInChannelsAreDefinedTheSameWay(): void
    {
        $builtIn = ChannelRegistry::build([]);
        $this->assertSame(
            ['email', 'slack', 'teams', 'webhook', 'telegram', 'pagerduty', 'mattermost'],
            array_keys($builtIn)
        );
        foreach (ChannelRegistry::BUILT_IN as $name => $definition) {
            $this->assertEquals(ChannelDefinition::of($name, $definition), $builtIn[$name], "{$name} is a definition as a configured channel's");
        }

        $webhook = $builtIn['webhook'];
        $this->assertSame(
            ['Webhook', WebhookChannel::class, ['url'], ['url', 'signing_secret'], ['headers'], ['headers' => 'url', 'signing_secret' => 'url'], ['url' => ['http', 'https']], ['url', 'headers'], WebhookConfig::class],
            [$webhook->label, $webhook->class, $webhook->requiredKeys, $webhook->secretKeys, $webhook->secretMaps, $webhook->boundTo, $webhook->urlSchemes, $webhook->destinationKeys, $webhook->configRules]
        );
        $mattermost = $builtIn['mattermost'];
        $this->assertSame(
            [MattermostChannel::class, ['server_url', 'bot_token'], ['bot_token'], ['bot_token' => 'server_url'], ['server_url' => ['https']], MattermostConfig::DESTINATION_KEYS, MattermostConfig::class],
            [$mattermost->class, $mattermost->requiredKeys, $mattermost->secretKeys, $mattermost->boundTo, $mattermost->urlSchemes, $mattermost->destinationKeys, $mattermost->configRules]
        );
        $email = $builtIn['email'];
        $this->assertSame([EmailChannel::class, ['emails'], [], [], [], [], ['emails'], null], [$email->class, $email->requiredKeys, $email->secretKeys, $email->secretMaps, $email->boundTo, $email->urlSchemes, $email->destinationKeys, $email->configRules]);
    }

    /**
     * The configured channels follow the built-in ones; every definition has
     * its handler, which a test can replace.
     */
    public function testTheConfiguredChannelsFollowTheBuiltInOnes(): void
    {
        $definitions = $this->dispatcher()->definitions();

        $this->assertSame([...array_keys(ChannelRegistry::BUILT_IN), 'acme'], array_keys($definitions));
        $this->assertSame(array_keys($definitions), array_keys(NotificationTemplate::channelLabels()));
        foreach ($definitions as $name => $definition) {
            $this->assertInstanceOf($definition->class, $this->dispatcher()->channel($name), $name);
            $this->assertSame($definition, ChannelRegistry::definitionOf($name));
        }
        $this->assertNull(ChannelRegistry::definitionOf('carrier-pigeon'));
        $this->assertNull(ChannelRegistry::definitionOf(null));
    }

    // -- Definitions that are refused ------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusedDefinitions(): array
    {
        $acme = self::ACME;

        return [
            'an unknown class' => [['acme' => ['class' => 'app\custom\MissingChannel'] + $acme], sprintf(ChannelDefinition::UNKNOWN_CLASS, 'acme', 'app\custom\MissingChannel')],
            'a class that is no channel' => [['acme' => ['class' => \stdClass::class] + $acme], sprintf(ChannelDefinition::NOT_A_CHANNEL, 'acme', \stdClass::class)],
            'no class' => [['acme' => array_diff_key($acme, ['class' => true])], sprintf(ChannelDefinition::CLASS_MISSING, 'acme')],
            'a built-in name' => [['webhook' => $acme], sprintf(ChannelRegistry::NAME_TAKEN, 'webhook')],
            'a name of another form' => [['Acme Pager' => $acme], sprintf(ChannelDefinition::NAME_FORM, 'Acme Pager')],
            'a name too long' => [[str_repeat('a', 33) => $acme], sprintf(ChannelDefinition::NAME_FORM, str_repeat('a', 33))],
            'a list of channels' => [[$acme], sprintf(ChannelDefinition::NAME_FORM, '0')],
            'a definition that is no array' => [['acme' => 'ConfiguredTestChannel'], sprintf(ChannelDefinition::NOT_A_DEFINITION, 'acme')],
            'no label' => [['acme' => ['label' => ''] + $acme], sprintf(ChannelDefinition::LABEL_MISSING, 'acme')],
            'an unknown setting' => [['acme' => $acme + ['secretKey' => ['api_key']]], sprintf(ChannelDefinition::UNKNOWN_SETTING, 'acme', 'secretKey')],
            'keys that are no list' => [['acme' => ['secretKeys' => 'api_key'] + $acme], sprintf(ChannelDefinition::KEYS_FORM, 'acme', 'secretKeys')],
            'an empty key' => [['acme' => ['requiredKeys' => ['endpoint', '']] + $acme], sprintf(ChannelDefinition::KEYS_FORM, 'acme', 'requiredKeys')],
            'destination keys that are no list' => [['acme' => ['destinationKeys' => ['a' => 'endpoint']] + $acme], sprintf(ChannelDefinition::KEYS_FORM, 'acme', 'destinationKeys')],
            'a binding of a key that is no secret' => [['acme' => ['boundTo' => ['endpoint' => 'api_key']] + $acme], sprintf(ChannelDefinition::BOUND_TO_FORM, 'acme')],
            'a binding to no key' => [['acme' => ['boundTo' => ['api_key' => '']] + $acme], sprintf(ChannelDefinition::BOUND_TO_FORM, 'acme')],
            'a binding to itself' => [['acme' => ['boundTo' => ['api_key' => 'api_key']] + $acme], sprintf(ChannelDefinition::BOUND_TO_FORM, 'acme')],
            'a binding that is no map' => [['acme' => ['boundTo' => 'endpoint'] + $acme], sprintf(ChannelDefinition::BOUND_TO_FORM, 'acme')],
            'schemes that are no array' => [['acme' => ['urlSchemes' => 'https'] + $acme], sprintf(ChannelDefinition::URL_SCHEMES_FORM, 'acme')],
            'a scheme that is no text' => [['acme' => ['urlSchemes' => ['endpoint' => [['https']]]] + $acme], sprintf(ChannelDefinition::URL_SCHEMES_FORM, 'acme')],
            'another scheme' => [['acme' => ['urlSchemes' => ['endpoint' => ['https', 'ftp']]] + $acme], sprintf(ChannelDefinition::URL_SCHEMES_FORM, 'acme')],
            'no scheme' => [['acme' => ['urlSchemes' => ['endpoint' => []]] + $acme], sprintf(ChannelDefinition::URL_SCHEMES_FORM, 'acme')],
            'schemes that are no map' => [['acme' => ['urlSchemes' => ['https']] + $acme], sprintf(ChannelDefinition::URL_SCHEMES_FORM, 'acme')],
            'rules that are no rules' => [['acme' => ['configRules' => ConfiguredTestChannel::class] + $acme], sprintf(ChannelDefinition::RULES_FORM, 'acme')],
            'rules of no class' => [['acme' => ['configRules' => 'app\custom\MissingRules'] + $acme], sprintf(ChannelDefinition::RULES_FORM, 'acme')],
        ];
    }

    /**
     * A definition that is wrong is refused when the dispatcher starts,
     * which the web and the console application do at their start
     * (ChannelRegistryAtStartTest), with a message that names the channel
     * and what is wrong: an unknown class, a class that does not implement
     * ChannelInterface, a built-in name, a URL with another scheme than http
     * or https.
     *
     * @param array<string, mixed> $channels
     */
    #[DataProvider('refusedDefinitions')]
    public function testAWrongDefinitionIsRefusedAtInit(array $channels, string $message): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($message);

        new NotificationDispatcher(['channels' => $channels]);
    }

    /**
     * The handler is made by Yii's container, so its definitions can set
     * the handler's properties; a container definition that makes no
     * channel of the class is refused at init as well.
     */
    public function testTheContainerMakesTheHandler(): void
    {
        \Yii::$container->set(MattermostChannel::class, ['editRetryPauseMs' => 5]);
        try {
            $handler = (new NotificationDispatcher())->channel(NotificationTemplate::CHANNEL_MATTERMOST);
            $this->assertInstanceOf(MattermostChannel::class, $handler);
            $this->assertSame(5, $handler->editRetryPauseMs);

            \Yii::$container->set(ConfiguredTestChannel::class, \stdClass::class);
            $this->expectException(InvalidConfigException::class);
            $this->expectExceptionMessage(sprintf(ChannelDefinition::NOT_A_CHANNEL, 'acme', ConfiguredTestChannel::class));
            new NotificationDispatcher(['channels' => ['acme' => self::ACME]]);
        } finally {
            \Yii::$container->clear(MattermostChannel::class);
            \Yii::$container->clear(ConfiguredTestChannel::class);
        }
    }

    /**
     * The messages, as the docs quote them.
     */
    public function testTheMessages(): void
    {
        $this->assertSame(
            'Notification channel "acme": its class app\custom\MissingChannel does not exist.',
            sprintf(ChannelDefinition::UNKNOWN_CLASS, 'acme', 'app\custom\MissingChannel')
        );
        $this->assertSame(
            'Notification channel "acme": its class stdClass does not implement app\services\notification\ChannelInterface.',
            sprintf(ChannelDefinition::NOT_A_CHANNEL, 'acme', 'stdClass')
        );
        $this->assertSame('Notification channel "webhook" is built in: give your channel another name.', sprintf(ChannelRegistry::NAME_TAKEN, 'webhook'));
        $this->assertSame('Notification channel "acme": urlSchemes must map config keys to http, https or both.', sprintf(ChannelDefinition::URL_SCHEMES_FORM, 'acme'));
    }

    // -- The configuration --------------------------------------------------------------------

    /**
     * The channels of an installation come from config/notification-channels.php,
     * when it exists: an array of name => definition. Without it there are
     * none; a file that returns no array stops the application.
     */
    public function testTheConfiguredChannelsComeFromTheirFile(): void
    {
        $file = (string)tempnam(sys_get_temp_dir(), 'ansilume-channels');
        try {
            file_put_contents($file, "<?php\nreturn ['acme' => ['label' => 'Acme Pager', 'class' => 'app\\\\custom\\\\AcmeChannel']];\n");
            $this->assertSame(['acme' => ['label' => 'Acme Pager', 'class' => 'app\custom\AcmeChannel']], ChannelRegistry::configuredIn($file));

            file_put_contents($file, "<?php\nreturn 'acme';\n");
            try {
                ChannelRegistry::configuredIn($file);
                $this->fail('A file that returns no array is refused.');
            } catch (InvalidConfigException $e) {
                $this->assertSame(sprintf(ChannelRegistry::FILE_NOT_AN_ARRAY, $file), $e->getMessage());
            }
        } finally {
            unlink($file);
        }
        $this->assertSame([], ChannelRegistry::configuredIn($file), 'no file, no channels');
    }

    /**
     * The dispatcher reads the channels of the installation's own from
     * $channelsFile when it starts, in place of $channels; without the file
     * there are none, and a file it cannot take is refused when it starts.
     */
    public function testTheDispatcherReadsItsFileWhenItStarts(): void
    {
        $file = (string)tempnam(sys_get_temp_dir(), 'ansilume-channels');
        try {
            file_put_contents($file, "<?php\n\nreturn " . var_export(['acme' => self::ACME], true) . ";\n");
            $dispatcher = new NotificationDispatcher(['channelsFile' => $file]);
            $this->assertSame([...array_keys(ChannelRegistry::BUILT_IN), 'acme'], array_keys($dispatcher->definitions()));
            $this->assertInstanceOf(ConfiguredTestChannel::class, $dispatcher->channel('acme'));
        } finally {
            unlink($file);
        }

        $none = new NotificationDispatcher(['channels' => ['acme' => self::ACME], 'channelsFile' => $file]);
        $this->assertSame(array_keys(ChannelRegistry::BUILT_IN), array_keys($none->definitions()), 'no file, no channel of one\'s own');

        $wrong = (string)tempnam(sys_get_temp_dir(), 'ansilume-channels');
        try {
            file_put_contents($wrong, "<?php\n\nreturn 'acme';\n");
            $this->expectException(InvalidConfigException::class);
            $this->expectExceptionMessage(sprintf(ChannelRegistry::FILE_NOT_AN_ARRAY, $wrong));
            new NotificationDispatcher(['channelsFile' => $wrong]);
        } finally {
            unlink($wrong);
        }
    }

    /**
     * The web and the console application build the dispatcher when they
     * start (their bootstrap), which reads the installation's
     * config/notification-channels.php (config/services.php): a wrong
     * definition stops them there (ChannelRegistryAtStartTest). The tests
     * never read the file.
     */
    public function testTheApplicationsReadTheFileWhenTheyStartAndTheTestsDoNot(): void
    {
        $root = dirname(__DIR__, 4);
        $services = require $root . '/config/services.php';
        $test = require $root . '/config/test.php';

        $this->assertSame(
            ['class' => NotificationDispatcher::class, 'channelsFile' => $root . '/' . ChannelRegistry::CONFIG_FILE],
            $services['notificationDispatcher'] ?? null
        );
        foreach (['web', 'console'] as $name) {
            $config = require $root . '/config/' . $name . '.php';
            $this->assertContains('notificationDispatcher', $config['bootstrap'] ?? [], "config/{$name}.php builds the dispatcher when the application starts");
        }
        $this->assertSame(
            ['class' => NotificationDispatcher::class, 'channels' => []],
            $test['components']['notificationDispatcher'] ?? null,
            'the tests have the built-in channels only'
        );
        $this->assertSame('config/notification-channels.php', ChannelRegistry::CONFIG_FILE);
        $ignored = (string)file_get_contents($root . '/.gitignore');
        $this->assertStringContainsString("\n/config/notification-channels.php\n", $ignored, 'git leaves the installation\'s file alone');
    }

    // -- Helpers ------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     */
    private function newTemplate(array $config): NotificationTemplate
    {
        $template = new NotificationTemplate();
        $template->name = 'registry-' . uniqid('', true);
        $template->channel = 'acme';
        $template->events = 'job.failed';
        $template->subject_template = 'Job #{{ job.id }} {{ job.status }}';
        $template->config = (string)json_encode($config, JSON_UNESCAPED_SLASHES);

        return $template;
    }

    /**
     * A template of $channel with $config, as loaded from the database.
     *
     * @param array<string, mixed> $config
     */
    private function storedTemplate(string $channel, array $config, ?User $identity = null): NotificationTemplate
    {
        $identity ??= $this->createUser('registry-identity');
        $template = $this->createNotificationTemplate((int)$identity->id, $channel, 'job.failed', (string)json_encode($config, JSON_UNESCAPED_SLASHES));

        return $this->reload($template);
    }

    private function reload(NotificationTemplate $template): NotificationTemplate
    {
        $stored = NotificationTemplate::findOne($template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);

        return $stored;
    }

    private function failedJobOf(User $owner): Job
    {
        $ownerId = (int)$owner->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );

        return $this->createJob((int)$template->id, $ownerId, Job::STATUS_FAILED);
    }

    private function dispatcher(): NotificationDispatcher
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');

        return $dispatcher;
    }
}
