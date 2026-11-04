<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\NotificationTemplate;
use app\services\notification\MattermostApiException;
use app\services\notification\MattermostConfig;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\notification\FakeMattermost;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The config of a Mattermost template as its rules check it when it is
 * saved (MattermostConfig::refusals(), MattermostConfig::URL_KEYS)
 * and as its channel reads it when it delivers (MattermostConfig::of()):
 * an https server_url, a bot_token, and channel_id or team and channel;
 * optionally one mention, never one that notifies the whole channel.
 * Saving checks only the form: it never asks Mattermost.
 */
class NotificationTemplateMattermostConfigTest extends DbTestCase
{
    use RestoresComponents;

    private const SERVER = 'https://chat.example.com';
    private const TOKEN = 'mattermost-bot-token-8812';
    private const CHANNEL_ID = 'abcdefghijklmnopqrstuvwxyz';

    private FakeMattermost $mattermost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mattermost = new FakeMattermost();
        $this->swapComponent('outboundHttp', $this->mattermost);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * A Mattermost template with the channel's ID, or with team and
     * channel names, and with a mention, is valid; a server below a path
     * too.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function validProvider(): array
    {
        return [
            'a channel ID' => [['server_url' => self::SERVER, 'bot_token' => self::TOKEN, 'channel_id' => self::CHANNEL_ID]],
            'team and channel names' => [['server_url' => self::SERVER, 'bot_token' => self::TOKEN, 'team' => 'ops', 'channel' => 'deploy-alerts_2']],
            'a mention' => [['server_url' => self::SERVER . '/', 'bot_token' => self::TOKEN, 'channel_id' => self::CHANNEL_ID, 'mention' => '@ops-team']],
            'a server below a path' => [['server_url' => self::SERVER . '/mattermost', 'bot_token' => self::TOKEN, 'channel_id' => self::CHANNEL_ID]],
            'empty optional keys' => [['server_url' => self::SERVER, 'bot_token' => self::TOKEN, 'channel_id' => self::CHANNEL_ID, 'team' => '', 'channel' => null, 'mention' => '']],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('validProvider')]
    public function testAValidConfigIsTaken(array $config): void
    {
        $template = $this->template($config);

        $this->assertTrue($template->validate(), (string)json_encode($template->getErrors()));
        $this->assertSame([], MattermostConfig::refusals($config));
    }

    /**
     * Regression (spec section 1): the bot token is a full session of its
     * bot, so server_url is https only: an http URL and any other scheme
     * are refused with HTTPS_REQUIRED, before anything is sent.
     *
     * @return array<string, array{0: string}>
     */
    public static function insecureServerProvider(): array
    {
        return [
            'http' => ['http://chat.example.com'],
            'a file' => ['file:///etc/hostname'],
            'gopher' => ['gopher://127.0.0.1:70/_x'],
            'no scheme' => ['chat.example.com'],
        ];
    }

    #[DataProvider('insecureServerProvider')]
    public function testHttpServerUrlIsRefused(string $serverUrl): void
    {
        $template = $this->template(['server_url' => $serverUrl, 'bot_token' => self::TOKEN, 'channel_id' => self::CHANNEL_ID]);

        $this->assertFalse($template->validate());
        $this->assertSame([sprintf(NotificationTemplate::HTTPS_REQUIRED, 'server_url')], $template->getErrors('config'));
    }

    /**
     * What a config is refused for: each with its message.
     *
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function refusalProvider(): array
    {
        $base = ['server_url' => self::SERVER, 'bot_token' => self::TOKEN];

        return [
            'no server and no token' => [['channel_id' => self::CHANNEL_ID], ['server_url is required for Mattermost.', 'bot_token is required for Mattermost.']],
            'no channel' => [$base, [MattermostConfig::CHANNEL_MISSING]],
            'a channel ID and names' => [$base + ['channel_id' => self::CHANNEL_ID, 'team' => 'ops', 'channel' => 'deploys'], [MattermostConfig::CHANNEL_TWICE]],
            'a team without a channel' => [$base + ['team' => 'ops'], [MattermostConfig::TEAM_AND_CHANNEL]],
            'a channel without a team' => [$base + ['channel' => 'deploys'], [MattermostConfig::TEAM_AND_CHANNEL]],
            'a channel ID of another form' => [$base + ['channel_id' => 'Town Square'], [MattermostConfig::CHANNEL_ID_FORM]],
            'a display name for the channel' => [$base + ['team' => 'ops', 'channel' => 'Town Square'], [sprintf(MattermostConfig::NAME_FORM, 'channel')]],
            'a team name with a dot' => [$base + ['team' => 'ops.eu', 'channel' => 'deploys'], [sprintf(MattermostConfig::NAME_FORM, 'team')]],
            'a server with a user' => [['server_url' => 'https://bot:pw@chat.example.com'] + $base + ['channel_id' => self::CHANNEL_ID], [MattermostConfig::SERVER_URL_FORM]],
            'a server with a query' => [['server_url' => self::SERVER . '/?next=x'] + $base + ['channel_id' => self::CHANNEL_ID], [MattermostConfig::SERVER_URL_FORM]],
            'a server with a fragment' => [['server_url' => self::SERVER . '/#x'] + $base + ['channel_id' => self::CHANNEL_ID], [MattermostConfig::SERVER_URL_FORM]],
            'a server address of 256 characters' => [['server_url' => self::SERVER . '/' . str_repeat('p', 231)] + $base + ['channel_id' => self::CHANNEL_ID], [MattermostConfig::SERVER_URL_FORM]],
            'a token with a space' => [['bot_token' => 'abc def'] + $base + ['channel_id' => self::CHANNEL_ID], [MattermostConfig::BOT_TOKEN_FORM]],
            'a token of 256 characters' => [['bot_token' => str_repeat('t', 256)] + $base + ['channel_id' => self::CHANNEL_ID], [MattermostConfig::BOT_TOKEN_FORM]],
            'a token that is no text' => [['bot_token' => 12345] + $base + ['channel_id' => self::CHANNEL_ID], [MattermostConfig::BOT_TOKEN_FORM]],
            'a channel ID that is no text' => [$base + ['channel_id' => ['x']], [MattermostConfig::CHANNEL_ID_FORM]],
            'a mention without an @' => [$base + ['channel_id' => self::CHANNEL_ID, 'mention' => 'ops-team'], [MattermostConfig::MENTION_FORM]],
            'two mentions' => [$base + ['channel_id' => self::CHANNEL_ID, 'mention' => '@alice @bob'], [MattermostConfig::MENTION_FORM]],
            'a mention that is no text' => [$base + ['channel_id' => self::CHANNEL_ID, 'mention' => true], [MattermostConfig::MENTION_FORM]],
            'everyone in the channel' => [$base + ['channel_id' => self::CHANNEL_ID, 'mention' => '@all'], [MattermostConfig::MENTION_EVERYONE]],
            'the channel' => [$base + ['channel_id' => self::CHANNEL_ID, 'mention' => '@Channel'], [MattermostConfig::MENTION_EVERYONE]],
            'who is online' => [$base + ['channel_id' => self::CHANNEL_ID, 'mention' => '@here'], [MattermostConfig::MENTION_EVERYONE]],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $errors
     */
    #[DataProvider('refusalProvider')]
    public function testAConfigOfAnotherFormIsRefused(array $config, array $errors): void
    {
        $template = $this->template($config);

        $this->assertFalse($template->validate());
        $this->assertSame($errors, $template->getErrors('config'));
    }

    /**
     * Saving checks the form only: the template is saved, and saved again
     * with another channel, without a request to Mattermost, so an outage
     * of Mattermost never blocks an edit (spec answer 4).
     */
    public function testSavingNeedsNoServer(): void
    {
        $user = $this->createUser('mm-config-save');
        $template = $this->template(['server_url' => self::SERVER, 'bot_token' => self::TOKEN, 'team' => 'ops', 'channel' => 'deploys']);
        $template->created_by = (int)$user->id;
        $template->delivers_as = (int)$user->id;

        $this->assertTrue($template->save(), (string)json_encode($template->getErrors()));
        $template->config = (string)json_encode(['server_url' => self::SERVER, 'bot_token' => '$encrypted$', 'channel_id' => self::CHANNEL_ID]);
        $this->assertTrue($template->save(), (string)json_encode($template->getErrors()));

        $this->assertSame([], $this->mattermost->requests);
        $stored = NotificationTemplate::findOne($template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);
        $this->assertSame(['server_url' => self::SERVER, 'bot_token' => self::TOKEN, 'channel_id' => self::CHANNEL_ID], $stored->getParsedConfig());
        $this->assertNull($stored->channel_state, 'nothing was checked');
    }

    /**
     * The channel reads the config it delivers with through of(): a
     * stored config that the rules would refuse (here written around them)
     * is refused there too, before any request, with what is wrong
     * (KIND_REQUEST, which fails the delivery at once).
     */
    public function testTheChannelRefusesAConfigTheRulesRefuse(): void
    {
        $template = $this->template(['server_url' => 'http://chat.example.com', 'bot_token' => self::TOKEN, 'channel_id' => self::CHANNEL_ID]);
        $missing = $this->template(['server_url' => self::SERVER, 'channel_id' => self::CHANNEL_ID]);
        $noChannel = $this->template(['server_url' => self::SERVER, 'bot_token' => self::TOKEN, 'mention' => '@all']);

        $this->assertSame(
            sprintf(MattermostApiException::CONFIG_UNUSABLE, sprintf(NotificationTemplate::HTTPS_REQUIRED, 'server_url')),
            $this->refusalOf($template)->getMessage()
        );
        $this->assertSame(sprintf(MattermostApiException::CONFIG_UNUSABLE, sprintf(MattermostConfig::REQUIRED, 'bot_token')), $this->refusalOf($missing)->getMessage());
        $refusal = $this->refusalOf($noChannel);
        $this->assertSame(sprintf(MattermostApiException::CONFIG_UNUSABLE, MattermostConfig::CHANNEL_MISSING), $refusal->getMessage());
        $this->assertSame(MattermostApiException::KIND_REQUEST, $refusal->kind);
        $this->assertStringNotContainsString(self::TOKEN, $refusal->getMessage());
    }

    /**
     * What of() reads: the server without its trailing slash, the token,
     * the channel's ID or names, the mention; and a fingerprint of what
     * decides the destination, which changes with the server, the token
     * or the channel, but not with the mention, and never shows the token.
     */
    public function testTheChannelReadsTheConfigAndItsFingerprint(): void
    {
        $config = MattermostConfig::of($this->template(['server_url' => self::SERVER . '/mm/', 'bot_token' => self::TOKEN, 'team' => 'ops', 'channel' => 'deploys', 'mention' => '@ops-team']));

        $this->assertSame(
            [self::SERVER . '/mm', self::TOKEN, null, 'ops', 'deploys', '@ops-team', 'ops/deploys'],
            [$config->serverUrl, $config->token, $config->channelId, $config->team, $config->channel, $config->mention, $config->channelLabel()]
        );
        $byId = MattermostConfig::of($this->template(['server_url' => self::SERVER, 'bot_token' => self::TOKEN, 'channel_id' => self::CHANNEL_ID, 'mention' => '']));
        $this->assertSame([null, null, null, self::CHANNEL_ID], [$byId->team, $byId->channel, $byId->mention, $byId->channelLabel()]);

        $fingerprint = $byId->fingerprint();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        $this->assertStringNotContainsString(self::TOKEN, $fingerprint);
        $same = ['server_url' => self::SERVER . '/', 'bot_token' => self::TOKEN, 'channel_id' => self::CHANNEL_ID, 'mention' => '@x'];
        $this->assertSame($fingerprint, MattermostConfig::of($this->template($same))->fingerprint(), 'a trailing slash or a mention is no other destination');
        foreach ([['server_url' => 'https://other.example.com'], ['bot_token' => 'another-token'], ['channel_id' => str_repeat('b', 26)]] as $change) {
            $this->assertNotSame($fingerprint, MattermostConfig::of($this->template($change + $same))->fingerprint(), (string)json_encode(array_keys($change)));
        }
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * A new Mattermost template with $config, listening to a workflow event.
     *
     * @param array<string, mixed> $config
     */
    private function template(array $config): NotificationTemplate
    {
        $template = new NotificationTemplate();
        $template->name = 'mm-config-' . uniqid('', true);
        $template->channel = NotificationTemplate::CHANNEL_MATTERMOST;
        $template->events = NotificationTemplate::EVENT_WORKFLOW_FAILED;
        $template->config = (string)json_encode($config, JSON_UNESCAPED_SLASHES);

        return $template;
    }

    private function refusalOf(NotificationTemplate $template): MattermostApiException
    {
        try {
            MattermostConfig::of($template);
        } catch (MattermostApiException $e) {
            return $e;
        }
        $this->fail('the config was taken');
    }
}
