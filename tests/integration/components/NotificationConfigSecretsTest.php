<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\NotificationConfigSecrets;
use app\components\SurveySecrets;
use app\models\NotificationTemplate;
use app\tests\integration\controllers\ChannelSecretFixtures;
use app\tests\integration\DbTestCase;

/**
 * What the template page, the edit form and the API show of a notification
 * template's channel config, and what a submitted placeholder keeps.
 */
class NotificationConfigSecretsTest extends DbTestCase
{
    private const SHOWN = SurveySecrets::PLACEHOLDER;

    // -- redacted() -------------------------------------------------------------

    /**
     * @dataProvider channelProvider
     * @param array<string, mixed> $config
     * @param list<string> $secrets
     * @param array<string, mixed> $shown
     */
    public function testEverySecretOfEveryChannelIsRedacted(string $channel, array $config, array $secrets, array $shown): void
    {
        $this->assertSame($shown, $this->stored($channel, $config)->getRedactedConfig());
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: list<string>, 3: array<string, mixed>}>
     */
    public static function channelProvider(): array
    {
        return ChannelSecretFixtures::channels();
    }

    /**
     * The secret keys of every channel apply to every config: a key left over
     * from another channel and the config of a channel no handler sends show
     * no secret either.
     */
    public function testASecretKeyOfAnotherChannelIsRedactedToo(): void
    {
        $email = $this->stored(NotificationTemplate::CHANNEL_EMAIL, ['emails' => ['ops@example.com'], 'webhook_url' => ChannelSecretFixtures::SLACK_URL]);
        $unknown = $this->stored('carrier-pigeon', ['bot_token' => ChannelSecretFixtures::BOT_TOKEN, 'headers' => ['X-Key' => 'k']]);

        $this->assertSame(['emails' => ['ops@example.com'], 'webhook_url' => self::SHOWN], $email->getRedactedConfig());
        $this->assertSame(['bot_token' => self::SHOWN, 'headers' => ['X-Key' => self::SHOWN]], $unknown->getRedactedConfig());
    }

    /**
     * Nothing set, nothing to hide: null and '' are shown as they are, at a
     * key and among the headers.
     */
    public function testAnEmptyValueIsShownAsItIs(): void
    {
        $template = $this->stored(NotificationTemplate::CHANNEL_WEBHOOK, ['url' => '', 'headers' => ['A' => null, 'B' => '', 'C' => 'c'], 'routing_key' => null]);

        $this->assertSame(['url' => '', 'headers' => ['A' => null, 'B' => '', 'C' => self::SHOWN], 'routing_key' => null], $template->getRedactedConfig());
    }

    /**
     * Headers that are no JSON object (stored through the API) are hidden as
     * a whole; empty ones are shown.
     */
    public function testHeadersThatAreNoObjectAreRedactedAsAWhole(): void
    {
        $string = $this->stored(NotificationTemplate::CHANNEL_WEBHOOK, ['url' => 'u', 'headers' => 'Authorization: Bearer x']);
        $list = $this->stored(NotificationTemplate::CHANNEL_WEBHOOK, ['url' => 'u', 'headers' => ['Authorization: Bearer x']]);
        $empty = $this->stored(NotificationTemplate::CHANNEL_WEBHOOK, ['url' => 'u', 'headers' => []]);

        $this->assertSame(['url' => self::SHOWN, 'headers' => self::SHOWN], $string->getRedactedConfig());
        $this->assertSame(['url' => self::SHOWN, 'headers' => [self::SHOWN]], $list->getRedactedConfig());
        $this->assertSame(['url' => self::SHOWN, 'headers' => []], $empty->getRedactedConfig());
    }

    /**
     * A secret that is no string is hidden too: the placeholder stands for
     * whatever is stored there.
     */
    public function testASecretThatIsNoStringIsRedacted(): void
    {
        $template = $this->stored(NotificationTemplate::CHANNEL_PAGERDUTY, ['routing_key' => ['nested' => 'key'], 'bot_token' => 12345]);

        $this->assertSame(['routing_key' => self::SHOWN, 'bot_token' => self::SHOWN], $template->getRedactedConfig());
    }

    public function testAConfigThatIsNoObjectShowsNothing(): void
    {
        foreach (['"a string"', 'not json', '', null] as $config) {
            $template = new NotificationTemplate();
            $template->channel = NotificationTemplate::CHANNEL_SLACK;
            $template->config = $config;

            $this->assertSame([], $template->getRedactedConfig());
            $this->assertSame('', $template->getRedactedConfigJson());
        }
    }

    public function testTheJsonIsWrittenAsTheFormWritesIt(): void
    {
        $template = $this->stored(NotificationTemplate::CHANNEL_EMAIL, ['emails' => ['ops@example.com'], 'note' => 'é/ü']);

        $this->assertSame('{"emails":["ops@example.com"],"note":"é/ü"}', $template->getRedactedConfigJson());
    }

    // -- restore() --------------------------------------------------------------

    public function testAConfigWithoutAPlaceholderIsLeftAsSubmitted(): void
    {
        $template = $this->stored(NotificationTemplate::CHANNEL_SLACK, ChannelSecretFixtures::SLACK);
        $template->config = '{ "webhook_url" : "https://hooks.slack.com/services/new" }';

        $this->assertNull((new NotificationConfigSecrets($template))->restore());
        $this->assertSame('{ "webhook_url" : "https://hooks.slack.com/services/new" }', $template->config);
    }

    /**
     * A config that is no JSON object is left to validateJson.
     */
    public function testAConfigThatIsNoObjectIsLeftToTheOtherRules(): void
    {
        $template = $this->stored(NotificationTemplate::CHANNEL_SLACK, ChannelSecretFixtures::SLACK);
        $template->config = '{"webhook_url": "$encrypted$"';

        $this->assertNull((new NotificationConfigSecrets($template))->restore());
        $this->assertSame('{"webhook_url": "$encrypted$"', $template->config);
        $this->assertFalse($template->validate(['config']));
        $this->assertSame('Config must be valid JSON.', $template->getFirstError('config'));
    }

    public function testEveryPlaceholderGetsItsStoredValue(): void
    {
        $template = $this->stored(NotificationTemplate::CHANNEL_WEBHOOK, ChannelSecretFixtures::WEBHOOK);
        $template->config = (string)json_encode(['url' => self::SHOWN, 'headers' => ['Authorization' => self::SHOWN, 'X-Env' => 'typed']]);

        $this->assertNull((new NotificationConfigSecrets($template))->restore());
        $this->assertSame(
            ['url' => ChannelSecretFixtures::WEBHOOK_URL, 'headers' => ['Authorization' => ChannelSecretFixtures::HEADER_TOKEN, 'X-Env' => 'typed']],
            $template->getParsedConfig()
        );
        $this->assertStringContainsString('"url":"https://hooks.example.com/', (string)$template->config, 'slashes are not escaped');
    }

    /**
     * The headers sent back whole as the placeholder (as the page shows
     * headers that are no object) keep the stored headers.
     */
    public function testHeadersSentBackWholeKeepTheStoredHeaders(): void
    {
        $template = $this->stored(NotificationTemplate::CHANNEL_WEBHOOK, ['url' => 'https://a.example', 'headers' => 'Authorization: Bearer x']);
        $template->config = '{"url":"$encrypted$","headers":"$encrypted$"}';

        $this->assertNull((new NotificationConfigSecrets($template))->restore());
        $this->assertSame(['url' => 'https://a.example', 'headers' => 'Authorization: Bearer x'], $template->getParsedConfig());
    }

    /**
     * The URL typed again as it is stored is no change: the headers keep
     * their stored values.
     */
    public function testTheStoredURLTypedAgainKeepsTheHeaders(): void
    {
        $template = $this->stored(NotificationTemplate::CHANNEL_WEBHOOK, ChannelSecretFixtures::WEBHOOK);
        $template->config = (string)json_encode(['url' => ChannelSecretFixtures::WEBHOOK_URL, 'headers' => ['Authorization' => self::SHOWN]]);

        $this->assertNull((new NotificationConfigSecrets($template))->restore());
        $this->assertSame(['url' => ChannelSecretFixtures::WEBHOOK_URL, 'headers' => ['Authorization' => ChannelSecretFixtures::HEADER_TOKEN]], $template->getParsedConfig());
    }

    /**
     * @dataProvider refusalProvider
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $submitted
     */
    public function testAPlaceholderThatCannotKeepAValueIsRefused(string $channel, array $stored, string $newChannel, array $submitted, string $error): void
    {
        $template = $this->stored($channel, $stored);
        $template->channel = $newChannel;
        $config = (string)json_encode($submitted);
        $template->config = $config;

        $this->assertSame($error, (new NotificationConfigSecrets($template))->restore());
        $this->assertSame($config, $template->config, 'nothing is assigned');
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: array<string, mixed>, 4: string}>
     */
    public static function refusalProvider(): array
    {
        $webhook = NotificationTemplate::CHANNEL_WEBHOOK;
        $headers = ['url' => 'https://a.example', 'headers' => ['Authorization' => 'Bearer x']];

        return [
            'no stored value at the key' => [$webhook, ['url' => 'https://a.example'], $webhook, ['url' => self::SHOWN, 'headers' => ['A' => self::SHOWN]], 'headers.A has no stored value; enter the real value.'],
            'an empty stored value' => [NotificationTemplate::CHANNEL_SLACK, ['webhook_url' => ''], NotificationTemplate::CHANNEL_SLACK, ['webhook_url' => self::SHOWN], 'webhook_url has no stored value; enter the real value.'],
            'stored headers that are no object' => [$webhook, ['url' => 'u', 'headers' => 'A: b'], $webhook, ['url' => self::SHOWN, 'headers' => ['A' => self::SHOWN]], 'headers.A has no stored value; enter the real value.'],
            'another channel' => [NotificationTemplate::CHANNEL_TELEGRAM, ChannelSecretFixtures::TELEGRAM, 'carrier-pigeon', ['bot_token' => self::SHOWN], 'bot_token: the channel changed; enter the real value.'],
            'a new url' => [$webhook, $headers, $webhook, ['url' => 'https://b.example', 'headers' => ['Authorization' => self::SHOWN]], 'headers.Authorization: url changed; enter the real value.'],
            'no url' => [$webhook, $headers, $webhook, ['headers' => ['Authorization' => self::SHOWN]], 'headers.Authorization: url changed; enter the real value.'],
            'the whole headers to a new url' => [$webhook, $headers, $webhook, ['url' => 'https://b.example', 'headers' => self::SHOWN], 'headers: url changed; enter the real value.'],
            'a header named by a number' => [$webhook, ['url' => 'u', 'headers' => ['x']], $webhook, ['url' => 'v', 'headers' => [self::SHOWN]], 'headers.0: url changed; enter the real value.'],
        ];
    }

    public function testANewTemplateHasNoStoredValue(): void
    {
        $template = new NotificationTemplate();
        $template->channel = NotificationTemplate::CHANNEL_TELEGRAM;
        $template->config = '{"bot_token":"$encrypted$","chat_id":"1"}';

        $this->assertSame(sprintf(NotificationConfigSecrets::NOT_STORED, 'bot_token'), (new NotificationConfigSecrets($template))->restore());
    }

    /**
     * json_decode() reads 1e400 as INF, which JSON cannot hold: the config
     * with the stored values put back cannot be written.
     */
    public function testAConfigThatCannotBeWrittenAgainIsRefused(): void
    {
        $template = $this->stored(NotificationTemplate::CHANNEL_PAGERDUTY, ChannelSecretFixtures::PAGERDUTY);
        $template->config = '{"routing_key":"$encrypted$","threshold":1e400}';

        $this->assertSame(NotificationConfigSecrets::NOT_ENCODABLE, (new NotificationConfigSecrets($template))->restore());
        $this->assertSame('{"routing_key":"$encrypted$","threshold":1e400}', $template->config);
    }

    // -- The model's rules --------------------------------------------------------

    /**
     * The length limit applies to the config with the stored values put
     * back: the placeholder is shorter than most secrets.
     */
    public function testTheLengthLimitAppliesToTheConfigWithTheStoredValues(): void
    {
        $template = $this->stored(NotificationTemplate::CHANNEL_TELEGRAM, ['bot_token' => str_repeat('t', 4000), 'chat_id' => '1']);
        $template->config = (string)json_encode(['bot_token' => self::SHOWN, 'chat_id' => str_repeat('9', 90)]);

        $this->assertFalse($template->validate(['config']));
        $this->assertSame('Config should contain at most 4,096 characters.', $template->getFirstError('config'));
    }

    /**
     * The channel's required fields are checked on the config with the
     * stored values: the placeholder of a required secret passes.
     */
    public function testTheRequiredFieldsAreCheckedWithTheStoredValues(): void
    {
        $template = $this->stored(NotificationTemplate::CHANNEL_PAGERDUTY, ChannelSecretFixtures::PAGERDUTY);
        $template->config = '{"routing_key":"$encrypted$"}';

        $this->assertTrue($template->validate(['config']));
        $this->assertSame(ChannelSecretFixtures::PAGERDUTY, $template->getParsedConfig());
        $this->assertTrue($template->validate(['config']), 'validating again changes nothing');
        $this->assertSame(ChannelSecretFixtures::PAGERDUTY, $template->getParsedConfig());
    }

    public function testAnErrorOfTheSecretsIsTheConfigsError(): void
    {
        $template = $this->stored(NotificationTemplate::CHANNEL_PAGERDUTY, ['routing_key' => '']);
        $template->config = '{"routing_key":"$encrypted$"}';

        $this->assertFalse($template->validate(['config']));
        $this->assertSame(['config' => ['routing_key has no stored value; enter the real value.']], $template->getErrors());
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * A notification template of $channel with $config, as loaded from the
     * database: what it holds there is its stored config and channel.
     *
     * @param array<string, mixed> $config
     */
    private function stored(string $channel, array $config): NotificationTemplate
    {
        $user = $this->createUser('nt-config-secrets');
        $created = $this->createNotificationTemplate((int)$user->id, $channel, 'job.failed', (string)json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $template = NotificationTemplate::findOne($created->id);
        $this->assertInstanceOf(NotificationTemplate::class, $template);

        return $template;
    }
}
