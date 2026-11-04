<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\components\OutboundHttpException;
use app\models\NotificationTemplate;
use app\services\notification\ChannelInterface;
use app\services\notification\PagerDutyChannel;
use app\services\notification\SlackChannel;
use app\services\notification\TeamsChannel;
use app\services\notification\TelegramChannel;
use app\services\notification\WebhookChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Every HTTP notification channel sends through the outbound HTTP client
 * (OutboundHttpClient): a URL that is no http or https URL is refused
 * without being read, and an error never holds the answer's content.
 *
 * Slack, Teams and the webhook channel send to the URL of their config.
 * Telegram and PagerDuty send to a fixed address, so their real post() is
 * given the URL of the case instead, as the channel tests do.
 */
class ChannelSchemeRegressionTest extends TestCase
{
    /**
     * Each channel, its config (with %s for the URL where the config holds
     * one) and its name in its errors.
     *
     * @return array<string, array{0: string, 1: array<string, string>, 2: string}>
     */
    public static function channels(): array
    {
        return [
            'slack' => [NotificationTemplate::CHANNEL_SLACK, ['webhook_url' => '%s'], 'SlackChannel'],
            'teams' => [NotificationTemplate::CHANNEL_TEAMS, ['webhook_url' => '%s'], 'TeamsChannel'],
            'webhook' => [NotificationTemplate::CHANNEL_WEBHOOK, ['url' => '%s', 'headers' => ['Authorization' => 'Bearer header-secret-6612']], 'WebhookChannel'],
            'telegram' => [NotificationTemplate::CHANNEL_TELEGRAM, ['bot_token' => '123:bot-token-6613', 'chat_id' => '-100200300'], 'TelegramChannel'],
            'pagerduty' => [NotificationTemplate::CHANNEL_PAGERDUTY, ['routing_key' => 'routing-key-6614'], 'PagerDutyChannel'],
        ];
    }

    /**
     * Regression: an operator's Send Test showed the start of a local file.
     * A file: URL is refused without being read, and the error says only
     * that the URL must use http or https.
     *
     * @param array<string, string> $config
     */
    #[DataProvider('channels')]
    public function testRegressionAChannelRefusesAFileUrlWithoutReadingIt(string $channel, array $config, string $name): void
    {
        $hostname = trim((string)file_get_contents('/etc/hostname'));
        $this->assertNotSame('', $hostname, 'the file whose content must not show');

        $error = $this->errorOf($channel, $config, 'file:///etc/hostname');

        $this->assertStringNotContainsString($hostname, (string)$error);
        $this->assertInstanceOf(OutboundHttpException::class, $error);
        $this->assertSame(OutboundHttpException::KIND_SCHEME, $error->kind);
        $this->assertSame($name . ': The URL must use http or https.', $error->getMessage());
    }

    /**
     * Regression: an error answer's body was part of the channel's error,
     * which the test flash, the API's 502 and the notification.failed audit
     * entry show; a hook's error can echo its secret ID. The error names the
     * status only.
     *
     * @param array<string, string> $config
     */
    #[DataProvider('channels')]
    public function testRegressionChannelErrorsCarryNoResponseContent(string $channel, array $config, string $name): void
    {
        $server = new HttpLoopbackServer();
        $base = $server->start(500, 'secret-hook-id-123');
        try {
            $error = $this->errorOf($channel, $config, $base . '/hooks/path-secret-6615');
            $requests = $server->requests();
        } finally {
            $server->stop();
        }

        $this->assertCount(1, $requests, 'the channel sent its message');
        foreach (['secret-hook-id-123', 'path-secret-6615', '127.0.0.1', 'header-secret-6612', 'bot-token-6613', 'routing-key-6614'] as $secret) {
            $this->assertStringNotContainsString($secret, (string)$error);
        }
        $this->assertInstanceOf(OutboundHttpException::class, $error);
        $this->assertSame(OutboundHttpException::KIND_STATUS, $error->kind);
        $this->assertSame(500, $error->httpStatus);
        $this->assertSame($name . ': HTTP 500.', $error->getMessage());
    }

    /**
     * What sending a message of $channel with $config throws when it goes
     * to $url: in the config where it holds the URL, else to the channel's
     * real post() in place of its fixed address.
     *
     * @param array<string, string> $config
     */
    private function errorOf(string $channel, array $config, string $url): \Throwable
    {
        $filled = array_map(static fn (mixed $value): mixed => $value === '%s' ? $url : $value, $config);
        $template = $this->template($channel, (string)json_encode($filled, JSON_UNESCAPED_SLASHES));
        try {
            $this->sender($channel, $url)->send($template, 'Subject', 'Body', ['event' => 'job.failed']);
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('the channel threw nothing');
    }

    /**
     * The channel; Telegram and PagerDuty post to $receiver in place of
     * their fixed address.
     */
    private function sender(string $channel, string $receiver): ChannelInterface
    {
        return match ($channel) {
            NotificationTemplate::CHANNEL_SLACK => new SlackChannel(),
            NotificationTemplate::CHANNEL_TEAMS => new TeamsChannel(),
            NotificationTemplate::CHANNEL_WEBHOOK => new WebhookChannel(),
            NotificationTemplate::CHANNEL_TELEGRAM => new class ($receiver) extends TelegramChannel {
                public function __construct(private readonly string $receiver)
                {
                }

                protected function post(string $url, string $payload, array $headers): string
                {
                    return parent::post($this->receiver, $payload, $headers);
                }
            },
            default => new class ($receiver) extends PagerDutyChannel {
                public function __construct(private readonly string $receiver)
                {
                }

                protected function post(string $url, string $payload, array $headers): string
                {
                    return parent::post($this->receiver, $payload, $headers);
                }
            },
        };
    }

    private function template(string $channel, string $config): NotificationTemplate
    {
        $template = $this->getMockBuilder(NotificationTemplate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $template->method('attributes')->willReturn(
            ['id', 'name', 'description', 'channel', 'config', 'subject_template', 'body_template', 'events', 'created_by', 'created_at', 'updated_at']
        );
        $template->method('save')->willReturn(true);
        (new \ReflectionProperty(BaseActiveRecord::class, '_attributes'))->setValue($template, [
            'id' => 7,
            'name' => 'Scheme regression',
            'channel' => $channel,
            'config' => $config,
            'subject_template' => 'Subject',
            'body_template' => 'Body',
            'events' => 'job.failed',
            'created_by' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $template;
    }
}
