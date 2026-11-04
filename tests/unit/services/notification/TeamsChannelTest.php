<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\models\Job;
use app\models\NotificationTemplate;
use app\services\notification\TeamsChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

class TeamsChannelTest extends TestCase
{
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
            'name' => 'Teams Test',
            'channel' => NotificationTemplate::CHANNEL_TEAMS,
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
     * The colour of the card for each status a job ends or runs with, and
     * grey for any other text.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function jobStatusColours(): array
    {
        return [
            'succeeded' => [Job::STATUS_SUCCEEDED, '2DC72D'],
            'failed' => [Job::STATUS_FAILED, 'FF0000'],
            'timed out' => [Job::STATUS_TIMED_OUT, 'FF0000'],
            'running' => [Job::STATUS_RUNNING, '0078D7'],
            'canceled' => [Job::STATUS_CANCELED, '808080'],
            'no job status' => ['successful', '808080'],
        ];
    }

    /**
     * Regression: the card of a succeeded job was grey. The colour was
     * green only for "successful", a status no job has (Job::statuses()).
     */
    #[DataProvider('jobStatusColours')]
    public function testRegressionTheCardTakesTheColourOfTheJobStatus(string $status, string $colour): void
    {
        $nt = $this->makeTemplate('{"webhook_url": "https://outlook.office.com/test"}');
        $channel = new class extends TeamsChannel {
            public ?string $capturedPayload = null;

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedPayload = $payload;
                return '1';
            }
        };

        $channel->send($nt, 'Job', 'Body', ['job.status' => $status]);

        $decoded = json_decode((string)$channel->capturedPayload, true);
        $this->assertIsArray($decoded);
        $this->assertSame($colour, $decoded['themeColor']);
    }

    public function testEmptyWebhookUrlDoesNotSend(): void
    {
        $nt = $this->makeTemplate('{}');
        $channel = new TeamsChannel();

        $channel->send($nt, 'Subject', 'Body', []);
        $this->assertTrue(true);
    }

    public function testPayloadConstructionWithSuccessStatus(): void
    {
        $nt = $this->makeTemplate('{"webhook_url": "https://outlook.office.com/test"}');

        $channel = new class extends TeamsChannel {
            public ?string $capturedPayload = null;

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedPayload = $payload;
                return '1';
            }
        };

        $channel->send($nt, 'Job OK', 'All good', ['job.status' => Job::STATUS_SUCCEEDED, 'job.url' => 'http://test']);

        $decoded = json_decode((string)$channel->capturedPayload, true);
        $this->assertSame('MessageCard', $decoded['@type']);
        $this->assertSame('2DC72D', $decoded['themeColor']);
        $this->assertSame('Job OK', $decoded['summary']);
        $this->assertNotEmpty($decoded['potentialAction']);
    }

    public function testPayloadConstructionWithFailedStatus(): void
    {
        $nt = $this->makeTemplate('{"webhook_url": "https://outlook.office.com/test"}');

        $channel = new class extends TeamsChannel {
            public ?string $capturedPayload = null;

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedPayload = $payload;
                return '1';
            }
        };

        $channel->send($nt, 'Job Failed', 'Error', ['job.status' => 'failed']);

        $decoded = json_decode((string)$channel->capturedPayload, true);
        $this->assertSame('FF0000', $decoded['themeColor']);
    }

    public function testThemeColorForRunningStatus(): void
    {
        $nt = $this->makeTemplate('{"webhook_url": "https://outlook.office.com/test"}');

        $channel = new class extends TeamsChannel {
            public ?string $capturedPayload = null;

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedPayload = $payload;
                return '1';
            }
        };

        $channel->send($nt, 'Job Running', 'Started', ['job.status' => 'running']);
        $decoded = json_decode((string)$channel->capturedPayload, true);
        $this->assertSame('0078D7', $decoded['themeColor']);
    }

    public function testThemeColorForUnknownStatusDefaultsGray(): void
    {
        $nt = $this->makeTemplate('{"webhook_url": "https://outlook.office.com/test"}');

        $channel = new class extends TeamsChannel {
            public ?string $capturedPayload = null;

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedPayload = $payload;
                return '1';
            }
        };

        $channel->send($nt, 'Job Pending', 'Waiting', ['job.status' => 'pending']);
        $decoded = json_decode((string)$channel->capturedPayload, true);
        $this->assertSame('808080', $decoded['themeColor']);
    }

    public function testPayloadWithoutJobUrlOmitsPotentialAction(): void
    {
        $nt = $this->makeTemplate('{"webhook_url": "https://outlook.office.com/test"}');

        $channel = new class extends TeamsChannel {
            public ?string $capturedPayload = null;

            protected function post(string $url, string $payload, array $headers): string
            {
                $this->capturedPayload = $payload;
                return '1';
            }
        };

        $channel->send($nt, 'Job Done', 'OK', ['job.status' => Job::STATUS_SUCCEEDED]);
        $decoded = json_decode((string)$channel->capturedPayload, true);
        $this->assertArrayNotHasKey('potentialAction', $decoded);
    }

    /**
     * Regression: post() called curl_close(), which PHP 8.5 deprecates, after
     * the delivery; the error handler turned the deprecation into an
     * exception, so every delivered notification was recorded as failed.
     */
    public function testPostSucceedsAgainstLoopbackServer(): void
    {
        $server = new HttpLoopbackServer();
        $baseUrl = $server->start(200, 'teams-ok');
        try {
            $nt = $this->makeTemplate(json_encode(['webhook_url' => $baseUrl]) ?: '{}');
            $channel = new TeamsChannel();
            // No exception = success path covered (post() returns normally, info log written).
            $channel->send($nt, 'Hello', 'World', ['job.status' => Job::STATUS_SUCCEEDED]);
            $this->assertTrue(true);
        } finally {
            $server->stop();
        }
    }

    public function testPostRaisesOnNon2xxResponse(): void
    {
        $server = new HttpLoopbackServer();
        $baseUrl = $server->start(500, 'boom');
        try {
            $nt = $this->makeTemplate(json_encode(['webhook_url' => $baseUrl]) ?: '{}');
            $channel = new TeamsChannel();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('TeamsChannel: HTTP 500.');
            $channel->send($nt, 'Hello', 'World', ['job.status' => 'failed']);
        } finally {
            $server->stop();
        }
    }

    /**
     * curl does not take a URL longer than libcurl takes
     * (CURL_MAX_INPUT_LENGTH, 8000000 bytes): curl error 43.
     */
    public function testAWebhookUrlThatCurlDoesNotTakeIsAnError(): void
    {
        $url = 'http://127.0.0.1/' . str_repeat('a', 8000000);
        $nt = $this->makeTemplate((string)json_encode(['webhook_url' => $url]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TeamsChannel: The request could not be made (curl error 43).');
        (new TeamsChannel())->send($nt, 'Hello', 'World', ['job.status' => 'failed']);
    }
}
