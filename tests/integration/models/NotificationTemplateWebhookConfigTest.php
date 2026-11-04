<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\NotificationTemplate;
use app\services\notification\WebhookConfig;
use app\tests\integration\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The config of a webhook template as its rules check it when it is saved
 * (WebhookConfig::refusals()): an optional signing_secret, which is text;
 * an optional payload, template (the default) or event; and headers, an
 * object of header names and values of one line, that the channel does not
 * set itself.
 */
class NotificationTemplateWebhookConfigTest extends DbTestCase
{
    private const URL = 'https://hooks.example.com/ansilume';

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function validProvider(): array
    {
        return [
            'a url only, as every template before the setting' => [['url' => self::URL]],
            'a signing secret and the event payload' => [['url' => self::URL, 'signing_secret' => 'whsec-1234567890', 'payload' => 'event']],
            'the template payload' => [['url' => self::URL, 'payload' => 'template', 'headers' => ['Authorization' => 'Bearer x']]],
            'empty optional keys' => [['url' => self::URL, 'signing_secret' => '', 'payload' => null]],
            'a header named like the channel’s, but another' => [['url' => self::URL, 'headers' => ['X-Ansilume-Team' => 'ops']]],
            'no headers, an empty object' => [['url' => self::URL, 'headers' => []]],
            'headers of a number, true and null, as the channel sends them' => [['url' => self::URL, 'headers' => ['X-Retry' => 3, 'X-Ratio' => 1.5, 'X-Flag' => true, 'X-Empty' => null]]],
            'a header name of every character a name may have' => [['url' => self::URL, 'headers' => ["X!#$%&'*+.^_`|~09-az-AZ" => 'value']]],
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
        $this->assertSame([], WebhookConfig::refusals($config));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function refusedProvider(): array
    {
        return [
            'another payload' => [['url' => self::URL, 'payload' => 'json'], [WebhookConfig::PAYLOAD_REFUSED]],
            'a payload that is no text' => [['url' => self::URL, 'payload' => ['event']], [WebhookConfig::PAYLOAD_REFUSED]],
            'a signing secret that is no text' => [['url' => self::URL, 'signing_secret' => 12345], [WebhookConfig::SIGNING_SECRET_FORM]],
            'a signing secret too long' => [['url' => self::URL, 'signing_secret' => str_repeat('s', 257)], [WebhookConfig::SIGNING_SECRET_FORM]],
            'the channel’s headers' => [
                ['url' => self::URL, 'headers' => ['x-ansilume-signature' => 'a', ' X-Ansilume-Delivery ' => 'b', 'X-Ansilume-Event' => 'c']],
                [
                    sprintf(WebhookConfig::HEADER_RESERVED, 'x-ansilume-signature'),
                    sprintf(WebhookConfig::HEADER_RESERVED, ' X-Ansilume-Delivery '),
                    sprintf(WebhookConfig::HEADER_RESERVED, 'X-Ansilume-Event'),
                ],
            ],
            // Regression: the web form refused headers that are no object, the
            // API stored them, and the channel then sent none of them, or a
            // header named "0" for each line of a list.
            'headers as text' => [['url' => self::URL, 'headers' => 'X-Custom: value'], [WebhookConfig::HEADERS_FORM]],
            'headers as a list of lines' => [['url' => self::URL, 'headers' => ['X-Custom: value']], [WebhookConfig::HEADERS_FORM]],
            'headers as a number' => [['url' => self::URL, 'headers' => 5], [WebhookConfig::HEADERS_FORM]],
            'a header value that is a list' => [['url' => self::URL, 'headers' => ['X-A' => ['b']]], [sprintf(WebhookConfig::HEADER_VALUE_REFUSED, 'X-A')]],
            'a header value that is an object' => [['url' => self::URL, 'headers' => ['X-A' => ['b' => 'c']]], [sprintf(WebhookConfig::HEADER_VALUE_REFUSED, 'X-A')]],
            'a header value of two lines' => [['url' => self::URL, 'headers' => ['X-A' => "b\r\nX-Injected: c"]], [sprintf(WebhookConfig::HEADER_VALUE_REFUSED, 'X-A')]],
            'a header value with a NUL byte' => [['url' => self::URL, 'headers' => ['X-A' => "b\0c"]], [sprintf(WebhookConfig::HEADER_VALUE_REFUSED, 'X-A')]],
            'a header name with a space' => [['url' => self::URL, 'headers' => ['X A' => 'b']], [sprintf(WebhookConfig::HEADER_NAME_REFUSED, 'X A')]],
            'a header name with a colon' => [['url' => self::URL, 'headers' => ['X-A: b' => 'c']], [sprintf(WebhookConfig::HEADER_NAME_REFUSED, 'X-A: b')]],
            'an empty header name' => [['url' => self::URL, 'headers' => ['' => 'c']], [sprintf(WebhookConfig::HEADER_NAME_REFUSED, '')]],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $errors
     */
    #[DataProvider('refusedProvider')]
    public function testAnInvalidConfigIsRefused(array $config, array $errors): void
    {
        $template = $this->template($config);

        $this->assertFalse($template->validate());
        $this->assertSame(['config' => $errors], $template->getErrors());
        $this->assertSame($errors, WebhookConfig::refusals($config));
    }

    /**
     * The messages, as the docs and the API quote them.
     */
    public function testTheMessages(): void
    {
        $this->assertSame('payload must be template or event.', WebhookConfig::PAYLOAD_REFUSED);
        $this->assertSame('signing_secret must be text of at most 256 characters.', WebhookConfig::SIGNING_SECRET_FORM);
        $this->assertSame('headers.X-Ansilume-Event is set by Ansilume; leave it out.', sprintf(WebhookConfig::HEADER_RESERVED, 'X-Ansilume-Event'));
        $this->assertSame('headers must be an object of header names and values.', WebhookConfig::HEADERS_FORM);
        $this->assertSame('headers.X A is no header name: leave out spaces, quotes, colons and the other separators.', sprintf(WebhookConfig::HEADER_NAME_REFUSED, 'X A'));
        $this->assertSame('headers.X-A must be one line of text.', sprintf(WebhookConfig::HEADER_VALUE_REFUSED, 'X-A'));
    }

    /**
     * Another channel is not asked: a key of the same name means nothing
     * there.
     */
    public function testAnotherChannelsConfigIsNotAsked(): void
    {
        $template = $this->template(['webhook_url' => 'https://hooks.slack.com/services/T0/B0/x', 'payload' => 'json'], NotificationTemplate::CHANNEL_SLACK);

        $this->assertTrue($template->validate(), (string)json_encode($template->getErrors()));
    }

    /**
     * Which payload a config sends: event only when it says so.
     */
    public function testThePayloadOfAConfig(): void
    {
        $this->assertSame(WebhookConfig::PAYLOAD_TEMPLATE, WebhookConfig::payloadOf([]));
        $this->assertSame(WebhookConfig::PAYLOAD_TEMPLATE, WebhookConfig::payloadOf(['payload' => 'template']));
        $this->assertSame(WebhookConfig::PAYLOAD_EVENT, WebhookConfig::payloadOf(['payload' => 'event']));
        $this->assertSame(WebhookConfig::PAYLOAD_TEMPLATE, WebhookConfig::payloadOf(['payload' => ['event']]));
        $this->assertSame(['template', 'event'], WebhookConfig::PAYLOADS);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function template(array $config, string $channel = NotificationTemplate::CHANNEL_WEBHOOK): NotificationTemplate
    {
        $template = new NotificationTemplate();
        $template->name = 'nt-webhook-config';
        $template->channel = $channel;
        $template->events = 'job.failed';
        $template->config = (string)json_encode($config);

        return $template;
    }
}
