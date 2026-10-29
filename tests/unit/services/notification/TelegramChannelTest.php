<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\models\NotificationTemplate;
use app\services\notification\TelegramChannel;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;
use yii\log\Logger;

/**
 * TelegramChannel sends each notification as a MarkdownV2 message to a
 * chat through the sendMessage method of the Bot API.
 *
 * The loopback tests run the real post() against HttpLoopbackServer, which
 * takes the place of api.telegram.org.
 */
class TelegramChannelTest extends TestCase
{
    private const BOT_TOKEN = '123456:tg-test-bot-token-4711';

    private const CHAT_ID = '-1001234567890';

    private function makeTemplate(string $configJson): NotificationTemplate
    {
        $nt = $this->getMockBuilder(NotificationTemplate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $nt->method('attributes')->willReturn(
            ['id', 'name', 'description', 'channel', 'config', 'subject_template',
             'body_template', 'events', 'created_by', 'created_at', 'updated_at']
        );
        $nt->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setValue($nt, [
            'id' => 1,
            'name' => 'Telegram Test',
            'channel' => NotificationTemplate::CHANNEL_TELEGRAM,
            'config' => $configJson,
            'subject_template' => 'Test',
            'body_template' => 'Body',
            'events' => 'job.failed',
            'created_by' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        return $nt;
    }

    /**
     * A TelegramChannel that records each post() of send(); given a
     * receiver, it makes the post with the real post() to the receiver in
     * place of the Bot API, and records the answer.
     */
    private function channel(string $receiver = ''): TelegramChannel
    {
        return new class ($receiver) extends TelegramChannel {
            /** @var list<array{url: string, payload: string, headers: array<int, string>, answer?: string}> */
            public array $posts = [];

            public function __construct(private readonly string $receiver)
            {
            }

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->posts[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers];
                if ($this->receiver === '') {
                    return '';
                }
                $answer = parent::post($this->receiver, $payload, $headers);
                $this->posts[count($this->posts) - 1]['answer'] = $answer;

                return $answer;
            }
        };
    }

    /**
     * The message send() posted, decoded.
     *
     * @return array<string, mixed>
     */
    private static function message(TelegramChannel $channel): array
    {
        self::assertCount(1, $channel->posts);
        $message = json_decode($channel->posts[0]['payload'], true);
        self::assertIsArray($message);

        return $message;
    }

    private static function config(): string
    {
        return (string)json_encode(['bot_token' => self::BOT_TOKEN, 'chat_id' => self::CHAT_ID]);
    }

    /**
     * @dataProvider configsWithoutBotOrChat
     */
    public function testWithoutABotTokenAndAChatIdNothingIsSent(string $config): void
    {
        $channel = $this->channel();

        $channel->send($this->makeTemplate($config), 'Job failed', 'Body', []);

        $this->assertSame([], $channel->posts);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function configsWithoutBotOrChat(): array
    {
        return [
            'no config' => [''],
            'neither' => ['{}'],
            'no chat ID' => ['{"bot_token": "123456:abc"}'],
            'no bot token' => ['{"chat_id": "-100123"}'],
            'empty bot token' => ['{"bot_token": "", "chat_id": "-100123"}'],
            'empty chat ID' => ['{"bot_token": "123456:abc", "chat_id": ""}'],
        ];
    }

    public function testTheMessageGoesToTheChatThroughTheBotsSendMessageMethod(): void
    {
        $channel = $this->channel();

        $channel->send($this->makeTemplate(self::config()), 'Job 42 failed', 'Deploy failed on web1', [
            'job.id' => '42',
        ]);

        $this->assertSame('https://api.telegram.org/bot' . self::BOT_TOKEN . '/sendMessage', $channel->posts[0]['url']);
        $this->assertSame(['Content-Type: application/json'], $channel->posts[0]['headers']);
        $this->assertSame([
            'chat_id' => self::CHAT_ID,
            'text' => "*Job 42 failed*\nDeploy failed on web1",
            'parse_mode' => 'MarkdownV2',
            'disable_web_page_preview' => true,
        ], self::message($channel));
    }

    /**
     * MarkdownV2 reserves _ * [ ] ( ) ~ ` > # + - = | { } . ! everywhere: the
     * rendered subject and body have each escaped, so only the channel's
     * own * around the subject format.
     */
    public function testEveryCharacterThatMarkdownV2ReservesIsEscaped(): void
    {
        $channel = $this->channel();

        $channel->send(
            $this->makeTemplate(self::config()),
            '_*[]()~`>#+-=|{}.!',
            "Job #42 (deploy_web) failed: exit=2 on web-1.example.com!\n[retry] in ~5 min | {ok} `cmd` > log + 1.",
            []
        );

        $this->assertSame(
            '*\_\*\[\]\(\)\~\`\>\#\+\-\=\|\{\}\.\!*' . "\n"
            . 'Job \#42 \(deploy\_web\) failed: exit\=2 on web\-1\.example\.com\!' . "\n"
            . '\[retry\] in \~5 min \| \{ok\} \`cmd\` \> log \+ 1\.',
            self::message($channel)['text']
        );
    }

    /**
     * Regression: post() called curl_close(), which PHP 8.5 deprecates,
     * after curl_exec() had delivered the message. The error handler of the
     * application turned the deprecation into a yii\base\ErrorException, so
     * every Telegram notification was recorded as failed
     * (notification.failed: "Function curl_close() is deprecated since 8.5,
     * as it has no effect since PHP 8.0") and its Test action reported a
     * failure. No test reached post() before.
     */
    public function testRegressionAMessageTheBotApiTookWasRecordedAsFailedOnPhp85(): void
    {
        $answer = '{"ok":true,"result":{"message_id":17,"chat":{"id":-1001234567890}}}';
        $server = new HttpLoopbackServer();
        $channel = $this->channel($server->start(200, $answer) . '/bot-loopback/sendMessage');
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $channel->send($this->makeTemplate(self::config()), 'Runner offline', 'runner-1 missed its heartbeat', []);
            $requests = $server->requests();
        } finally {
            \Yii::setLogger($previous);
            $server->stop();
        }

        $this->assertSame($answer, $channel->posts[0]['answer'] ?? null);
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/bot-loopback/sendMessage', $requests[0]['path']);
        $this->assertSame('application/json', $requests[0]['headers']['Content-Type'] ?? null);
        $this->assertSame($channel->posts[0]['payload'], $requests[0]['body']);
        $this->assertSame([[Logger::LEVEL_INFO, 'TelegramChannel: sent — 200']], self::logged($logger));
    }

    /**
     * An answer outside 2xx is an error with its code and the first 200
     * bytes of its body, which notification.failed records.
     */
    public function testAnAnswerOutside2xxIsAnErrorWithItsCodeAndTheStartOfItsBody(): void
    {
        $answer = '{"ok":false,"error_code":400,"description":"Bad Request: can\'t parse entities: '
            . str_repeat('x', 300) . '"}';
        $server = new HttpLoopbackServer();
        $channel = $this->channel($server->start(400, $answer));
        try {
            $error = self::errorOf(fn () => $channel->send($this->makeTemplate(self::config()), 'Alert', 'Body', []));
            $requests = $server->requests();
        } finally {
            $server->stop();
        }

        $this->assertSame('TelegramChannel: HTTP 400 — ' . substr($answer, 0, 200), $error);
        $this->assertCount(1, $requests);
    }

    public function testABotApiThatCannotBeReachedIsAnErrorWithoutCode(): void
    {
        $channel = $this->channel('http://127.0.0.1:1/bot-loopback/sendMessage');

        $error = self::errorOf(fn () => $channel->send($this->makeTemplate(self::config()), 'Alert', 'Body', []));

        $this->assertSame('TelegramChannel: HTTP 0 — ', $error);
    }

    /**
     * curl_init() gives false for a URL longer than libcurl takes
     * (CURL_MAX_INPUT_LENGTH, 8000000 bytes); the bot token is part of the
     * URL, so the real channel builds one.
     */
    public function testAUrlThatCurlDoesNotTakeIsAnError(): void
    {
        $template = $this->makeTemplate((string)json_encode([
            'bot_token' => str_repeat('1', 8000000),
            'chat_id' => self::CHAT_ID,
        ]));

        $error = self::errorOf(fn () => (new TelegramChannel())->send($template, 'Alert', 'Body', []));

        $this->assertSame('TelegramChannel: failed to init curl', $error);
    }

    /**
     * The message of the RuntimeException that $send raises.
     */
    private static function errorOf(callable $send): string
    {
        try {
            $send();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        self::fail('post() raised no RuntimeException');
    }

    /**
     * What the channel logged: level, then message.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function logged(Logger $logger): array
    {
        $logged = [];
        foreach ($logger->messages as $message) {
            if ($message[2] === TelegramChannel::class) {
                $logged[] = [(int)$message[1], (string)$message[0]];
            }
        }

        return $logged;
    }
}
