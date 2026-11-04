<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\models\Job;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\NotificationDispatcher;
use app\services\notification\JobPayloadBuilder;
use app\services\notification\WebhookChannel;
use app\tests\integration\DbTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use app\tests\unit\services\notification\HttpLoopbackServer;

/**
 * The signed webhook (WebhookChannel, docs/notifications.md): a template
 * with a signing_secret signs each request in X-Ansilume-Signature
 * (t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">), and every request
 * names its delivery in X-Ansilume-Delivery, the same on every retry, and
 * its event in X-Ansilume-Event. payload event sends the documented event
 * payload (NotificationEventPayload); a template without the setting sends
 * the template's subject, body and variables as before. The recipes of
 * docs/notifications.md verify what the channel sends, and refuse it once
 * the body, the secret or the time does not match.
 */
class WebhookChannelSigningTest extends DbTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;
    use DeliveryFixtures;

    private const SECRET = 'whsec-signing-test-5521';

    private const DOC = 'docs/notifications.md';

    private const RECIPE_HEADING = '### Verifying the signature';

    /** What each recipe of the doc is run with: its interpreter and the code that calls it on the request of stdin. */
    private const RECIPES = [
        'php' => "\n\$in = json_decode((string)stream_get_contents(STDIN), true);\n"
            . "echo verifyAnsilumeSignature(\$in['secret'], \$in['header'], \$in['body']) ? 'valid' : 'invalid';\n",
        'python' => "\nimport json, sys\n_in = json.load(sys.stdin)\n"
            . "print('valid' if verify_ansilume_signature(_in['secret'], _in['header'], _in['body'].encode('utf-8')) else 'invalid')\n",
    ];

    private ?HttpLoopbackServer $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTestDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        $this->server?->stop();
        parent::tearDown();
    }

    /**
     * A job's event through the outbox and the worker to a real server: the
     * request names its delivery and its event, carries the event payload,
     * and its signature verifies with each recipe of the doc, which refuses
     * a changed body, another secret, an old time and a header without one.
     */
    public function testTheSignatureVerifiesWithTheDocumentedRecipes(): void
    {
        $base = $this->startServer();
        $admin = $this->createUserWithRole('signing-admin', 'admin');
        $template = $this->webhookTemplate($admin, ['url' => $base . '/hook', 'signing_secret' => self::SECRET, 'payload' => 'event']);
        $job = $this->failedJobOf($admin);

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_JOB_FAILED, JobPayloadBuilder::build($job));
        $this->queue->run();

        $delivery = $this->onlyDeliveryOf($template);
        $this->assertSame(NotificationDelivery::STATUS_DELIVERED, $delivery->status, (string)$delivery->last_error);
        [$request] = $this->requests(1);
        $headers = self::headersOf($request);
        $this->assertSame((string)$delivery->id, $headers['x-ansilume-delivery'] ?? null);
        $this->assertSame(NotificationTemplate::EVENT_JOB_FAILED, $headers['x-ansilume-event'] ?? null);
        $signature = (string)($headers['x-ansilume-signature'] ?? '');
        $this->assertMatchesRegularExpression('/^t=(\d+),v1=[0-9a-f]{64}$/', $signature);
        $time = (int)substr($signature, 2, (int)strpos($signature, ',') - 2);
        $this->assertEqualsWithDelta(time(), $time, 5, 't is the time of the request');

        $payload = json_decode($request['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame(['event', 'event_id', 'occurred_at', 'severity', 'job', 'project'], array_keys($payload));
        $this->assertSame([NotificationTemplate::EVENT_JOB_FAILED, 'critical', (int)$job->id], [$payload['event'], $payload['severity'], $payload['job']['id'] ?? null]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string)$payload['event_id']);
        $this->assertIsInt($payload['occurred_at']);
        $this->assertEqualsWithDelta((int)$delivery->created_at, $payload['occurred_at'], 2, 'occurred_at is the time of the event');

        $old = time() - 600;
        foreach (array_keys(self::RECIPES) as $language) {
            $this->assertSame('valid', $this->runRecipe($language, self::SECRET, $signature, $request['body']), "{$language}: the request verifies");
            $this->assertSame('invalid', $this->runRecipe($language, self::SECRET, $signature, $request['body'] . ' '), "{$language}: a changed body");
            $this->assertSame('invalid', $this->runRecipe($language, 'another-secret', $signature, $request['body']), "{$language}: another secret");
            $this->assertSame(
                'invalid',
                $this->runRecipe($language, self::SECRET, sprintf('t=%d,v1=%s', $old, hash_hmac('sha256', $old . '.' . $request['body'], self::SECRET)), $request['body']),
                "{$language}: a request signed ten minutes ago"
            );
            $this->assertSame('invalid', $this->runRecipe($language, self::SECRET, substr($signature, (int)strpos($signature, ',') + 1), $request['body']), "{$language}: no time");
        }
    }

    /**
     * Every template that an event reaches gets the same event ID, and
     * every event another one.
     */
    public function testEveryTemplateOfAnEventGetsItsEventIdAndAnotherEventAnother(): void
    {
        $admin = $this->createUserWithRole('signing-event-id', 'admin');
        $first = $this->webhookTemplate($admin, ['url' => 'https://hooks.example.com/a']);
        $second = $this->webhookTemplate($admin, ['url' => 'https://hooks.example.com/b']);
        $job = $this->failedJobOf($admin);

        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_JOB_FAILED, JobPayloadBuilder::build($job));
        $this->dispatcher()->dispatch(NotificationTemplate::EVENT_JOB_FAILED, JobPayloadBuilder::build($job));

        $ids = [];
        foreach ([$first, $second] as $template) {
            $ids[] = array_map(
                static fn (NotificationDelivery $delivery): string => (string)($delivery->getVariableMap()['event_id'] ?? ''),
                NotificationDelivery::find()->where(['notification_template_id' => $template->id])->orderBy(['id' => SORT_ASC])->all()
            );
        }
        $this->assertCount(2, $ids[0]);
        $this->assertSame($ids[0], $ids[1], 'both templates got each event with the same ID');
        $this->assertNotSame($ids[0][0], $ids[0][1], 'each event has an ID of its own');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $ids[0][0]);
    }

    /**
     * A retry of a delivery names the same delivery and sends the same
     * body; each attempt is signed at its own time.
     */
    public function testTheDeliveryIdStaysTheSameAcrossARetry(): void
    {
        $base = $this->startServer(['/busy' => ['status' => 503]]);
        $admin = $this->createUserWithRole('signing-retry', 'admin');
        $template = $this->webhookTemplate($admin, ['url' => $base . '/busy', 'signing_secret' => self::SECRET, 'payload' => 'event']);
        $delivery = $this->delivery(['variables' => (string)json_encode([
            'event' => NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN,
            'severity' => 'error',
            'event_id' => '00112233445566778899aabbccddeeff',
            'occurred_at' => '1762250000',
            'app.url' => 'https://ansilume.example.com',
            'trigger.token_prefix' => 'abc123',
        ])], $template);

        $this->service()->deliver((int)$delivery->id);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $this->reload($delivery)->status, 'a 503 is retried');
        NotificationDelivery::updateAll(['next_attempt_at' => time()], ['id' => $delivery->id]);
        $this->service()->deliver((int)$delivery->id);

        $requests = $this->requests(2);
        $this->assertSame(2, (int)$this->reload($delivery)->attempts);
        $delivered = array_map(static fn (array $request): ?string => self::headersOf($request)['x-ansilume-delivery'] ?? null, $requests);
        $this->assertSame([(string)$delivery->id, (string)$delivery->id], $delivered);
        $this->assertSame($requests[0]['body'], $requests[1]['body'], 'a retry sends the same payload');
        $this->assertSame(['trigger' => ['kind' => null, 'token_prefix' => 'abc123', 'ip' => null, 'user_agent' => null, 'note' => null]], array_intersect_key((array)json_decode($requests[1]['body'], true), ['trigger' => true]));
        foreach ($requests as $request) {
            $this->assertSame('valid', $this->runRecipe('php', self::SECRET, (string)(self::headersOf($request)['x-ansilume-signature'] ?? ''), $request['body']));
        }
    }

    /**
     * A template stored before the setting existed, and one set to
     * template, send the template's subject, body and variables exactly as
     * before, unsigned without a secret, with the delivery and the event
     * named.
     */
    public function testWithoutThePayloadSettingTheTemplatesPayloadIsSentAsBefore(): void
    {
        $base = $this->startServer(['/old' => ['status' => 204], '/template' => ['status' => 204]]);
        $admin = $this->createUserWithRole('signing-default', 'admin');
        $deliveries = [];
        foreach (['/old' => ['url' => $base . '/old'], '/template' => ['url' => $base . '/template', 'payload' => 'template']] as $path => $config) {
            $deliveries[$path] = $this->delivery([], $this->webhookTemplate($admin, $config));
            $this->service()->deliver((int)$deliveries[$path]->id);
        }

        $expected = (string)json_encode([
            'subject' => 'Job #7 failed',
            'body' => 'Template: deploy',
            'variables' => ['job.id' => '7', 'job.status' => 'failed', 'template.name' => 'deploy'],
        ]);
        foreach ($this->requests(2) as $request) {
            $delivery = $deliveries[$request['path']];
            $headers = self::headersOf($request);
            $this->assertSame($expected, $request['body'], $request['path']);
            $this->assertArrayNotHasKey('x-ansilume-signature', $headers, 'no secret, no signature');
            $this->assertSame((string)$delivery->id, $headers['x-ansilume-delivery'] ?? null);
            $this->assertSame(NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, $headers['x-ansilume-event'] ?? null);
            $this->assertSame('application/json', $headers['content-type'] ?? null);
        }
    }

    /**
     * The template's payload can be signed too: the signature covers the
     * body as sent.
     */
    public function testTheTemplatesPayloadIsSignedWithASecret(): void
    {
        $base = $this->startServer(['/signed' => ['status' => 204]]);
        $admin = $this->createUserWithRole('signing-template', 'admin');
        $delivery = $this->delivery([], $this->webhookTemplate($admin, ['url' => $base . '/signed', 'signing_secret' => self::SECRET]));

        $this->service()->deliver((int)$delivery->id);

        [$request] = $this->requests(1);
        $this->assertSame('Job #7 failed', json_decode($request['body'], true)['subject'] ?? null);
        $this->assertSame('valid', $this->runRecipe('php', self::SECRET, (string)(self::headersOf($request)['x-ansilume-signature'] ?? ''), $request['body']));
    }

    /**
     * Send Test signs as a delivery does, for the template's first event,
     * with the sample variables; it has no delivery, so it names one of
     * test-<16 hex digits>, another for every test.
     */
    public function testSendTestIsSignedAndNamesATestDelivery(): void
    {
        $base = $this->startServer(['/test' => ['status' => 204]]);
        $admin = $this->createUserWithRole('signing-send-test', 'admin');
        $template = $this->webhookTemplate($admin, ['url' => $base . '/test', 'signing_secret' => self::SECRET, 'payload' => 'event'], 'workflow.waiting,job.failed');
        $dispatcher = new NotificationDispatcher();

        $this->assertNull($dispatcher->sendSingle($template, \app\components\NotificationTestVariables::of('workflow.waiting', 'alice'), 'workflow.waiting'));
        $this->assertNull($dispatcher->sendSingle($template, \app\components\NotificationTestVariables::of('workflow.waiting', 'alice'), 'workflow.waiting'));

        $requests = $this->requests(2);
        $deliveryIds = [];
        foreach ($requests as $request) {
            $headers = self::headersOf($request);
            $deliveryIds[] = (string)($headers['x-ansilume-delivery'] ?? '');
            $this->assertSame('workflow.waiting', $headers['x-ansilume-event'] ?? null);
            $this->assertSame('valid', $this->runRecipe('php', self::SECRET, (string)($headers['x-ansilume-signature'] ?? ''), $request['body']));
            $payload = json_decode($request['body'], true);
            $this->assertIsArray($payload);
            $this->assertSame(['event', 'event_id', 'occurred_at', 'severity', 'workflow', 'step', 'approval'], array_keys($payload));
            $this->assertSame(['workflow.waiting', 'warning', 'Deploy'], [$payload['event'], $payload['severity'], $payload['step']['step_name'] ?? null]);
        }
        $this->assertMatchesRegularExpression('/^test-[0-9a-f]{16}$/', $deliveryIds[0]);
        $this->assertMatchesRegularExpression('/^test-[0-9a-f]{16}$/', $deliveryIds[1]);
        $this->assertNotSame($deliveryIds[0], $deliveryIds[1], 'every test names a delivery of its own');
        $this->assertNotSame(
            json_decode($requests[0]['body'], true)['event_id'] ?? null,
            json_decode($requests[1]['body'], true)['event_id'] ?? null,
            'every test is an event of its own'
        );
    }

    /**
     * regression guard: a header of the config named as one that the
     * channel sets (stored before such names were refused) is not sent, so
     * a receiver reads only Ansilume's; the other headers are.
     */
    public function testAStoredHeaderNamedAsTheChannelsOwnIsNotSent(): void
    {
        $base = $this->startServer(['/headers' => ['status' => 204]]);
        $admin = $this->createUserWithRole('signing-reserved', 'admin');
        $template = $this->webhookTemplate($admin, ['url' => $base . '/headers', 'headers' => [
            'x-ansilume-delivery' => 'forged-delivery',
            ' X-Ansilume-Signature ' => 't=1,v1=forged',
            'X-Ansilume-Event' => 'forged.event',
            'X-Team' => 'ops',
        ]]);
        $delivery = $this->delivery([], $template);

        $this->service()->deliver((int)$delivery->id);

        [$request] = $this->requests(1);
        $headers = self::headersOf($request);
        $this->assertSame((string)$delivery->id, $headers['x-ansilume-delivery'] ?? null);
        $this->assertSame(NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, $headers['x-ansilume-event'] ?? null);
        $this->assertArrayNotHasKey('x-ansilume-signature', $headers);
        $this->assertSame('ops', $headers['x-team'] ?? null);
        $this->assertStringNotContainsString('forged', (string)json_encode($request['headers']));
    }

    /**
     * The doc names the headers, the signature's form and the tolerance
     * the recipes use, as the channel's constants word them.
     */
    public function testTheDocNamesTheHeadersAndTheSignaturesForm(): void
    {
        $doc = $this->doc();

        foreach ([WebhookChannel::SIGNATURE_HEADER, WebhookChannel::DELIVERY_HEADER, WebhookChannel::EVENT_HEADER] as $header) {
            $this->assertStringContainsString('`' . $header, $doc, self::DOC . " names {$header}.");
        }
        $this->assertStringContainsString('`X-Ansilume-Signature: t=<unix time>,v1=<signature>`', $doc);
        $this->assertSame('t=%d,v1=%s', WebhookChannel::SIGNATURE);
        $this->assertStringContainsString('the HMAC-SHA256 of `<t>.<body>`', $doc);
        $this->assertStringContainsString('`test-` and 16 hexadecimal digits', $doc);
        $this->assertSame('test-%s', WebhookChannel::TEST_DELIVERY);
        $this->assertStringContainsString('more than 300 seconds', $doc, 'the recipes refuse a signature older than five minutes');
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * Starts the local server with $answers (by path), every other path 204.
     *
     * @param array<string, array{status?: int, body?: string, headers?: array<string, string>}> $answers
     */
    private function startServer(array $answers = []): string
    {
        $this->server = new HttpLoopbackServer();

        return $this->server->startWith($answers + ['*' => ['status' => 204]]);
    }

    /**
     * The requests the server answered: exactly $count.
     *
     * @return list<array{method: string, path: string, headers: array<string, string>, body: string}>
     */
    private function requests(int $count): array
    {
        $this->assertNotNull($this->server);
        $requests = $this->server->requests();
        $this->assertCount($count, $requests);

        return $requests;
    }

    /**
     * The headers of $request by their lower-case names.
     *
     * @param array{headers: array<string, string>} $request
     * @return array<string, string>
     */
    private static function headersOf(array $request): array
    {
        return array_change_key_case($request['headers'], CASE_LOWER);
    }

    /**
     * A webhook template of $identity that listens to $events.
     *
     * @param array<string, mixed> $config
     */
    private function webhookTemplate(User $identity, array $config, string $events = 'job.failed'): NotificationTemplate
    {
        return $this->createNotificationTemplate((int)$identity->id, NotificationTemplate::CHANNEL_WEBHOOK, $events, (string)json_encode($config, JSON_UNESCAPED_SLASHES));
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
        $job = $this->createJob((int)$template->id, $ownerId, Job::STATUS_FAILED);
        $job->exit_code = 2;
        $job->save(false);

        return $job;
    }

    private function onlyDeliveryOf(NotificationTemplate $template): NotificationDelivery
    {
        $deliveries = NotificationDelivery::find()->where(['notification_template_id' => $template->id])->all();
        $this->assertCount(1, $deliveries);
        $this->assertInstanceOf(NotificationDelivery::class, $deliveries[0]);

        return $deliveries[0];
    }

    private function dispatcher(): NotificationDispatcher
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');

        return $dispatcher;
    }

    private function doc(): string
    {
        $doc = file_get_contents(dirname(__DIR__, 4) . '/' . self::DOC);
        $this->assertIsString($doc, self::DOC . ' exists.');

        return $doc;
    }

    /**
     * The code of the $language recipe under RECIPE_HEADING of the doc.
     */
    private function recipe(string $language): string
    {
        $doc = $this->doc();
        $start = strpos($doc, "\n" . self::RECIPE_HEADING . "\n");
        $this->assertNotFalse($start, self::DOC . ' has the section ' . self::RECIPE_HEADING . '.');
        $section = substr($doc, $start + 1);
        $next = preg_match('/\n#{2,3} /', $section, $match, PREG_OFFSET_CAPTURE, 1) === 1 ? $match[0][1] : strlen($section);
        $section = substr($section, 0, $next);
        $this->assertSame(1, preg_match('/^```' . $language . '\n(.*?)^```$/ms', $section, $code), "The section has a {$language} recipe.");

        return $code[1];
    }

    /**
     * What the $language recipe of the doc answers for a request with
     * $header and $body, checked with $secret: valid or invalid.
     */
    private function runRecipe(string $language, string $secret, string $header, string $body): string
    {
        $file = (string)tempnam(sys_get_temp_dir(), 'ansilume-recipe');
        file_put_contents($file, $this->recipe($language) . self::RECIPES[$language]);
        $command = $language === 'php' ? [PHP_BINARY, '-d', 'display_errors=stderr', $file] : ['python3', '-I', $file];
        try {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process, "{$language} runs");
            fwrite($pipes[0], (string)json_encode(['secret' => $secret, 'header' => $header, 'body' => $body]));
            fclose($pipes[0]);
            $output = (string)stream_get_contents($pipes[1]);
            $errors = (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), "the {$language} recipe ran without an error: {$errors}");
        } finally {
            unlink($file);
        }

        return trim($output);
    }
}
