<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\components\OutboundHttpClient;
use app\components\OutboundHttpException;
use app\services\notification\MattermostApi;
use app\services\notification\MattermostApiException;
use app\tests\integration\services\notification\FakeMattermost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The calls of Mattermost's REST API v4 that the Mattermost channel makes
 * (MattermostApi), through the outbound HTTP client: with the bot token as
 * a bearer token, posts created with set_online=false and edited through
 * the patch endpoint, never with from_bot. A failed call is a
 * MattermostApiException that says only the status, Mattermost's error id
 * and the request ID, in words that name the causes where Mattermost's
 * answer names them; a delivery makes at most MAX_REQUESTS calls.
 */
class MattermostApiTest extends TestCase
{
    private const TOKEN = 'loopback-bot-token-5501';
    private const BOT_ID = 'bot1aaaaaaaaaaaaaaaaaaaaaa';
    private const CHANNEL_ID = 'chan1aaaaaaaaaaaaaaaaaaaaa';
    private const POST_ID = 'post1aaaaaaaaaaaaaaaaaaaaa';

    private ?HttpLoopbackServer $server = null;

    /** The address of the loopback server of loopbackApi(). */
    private ?string $base = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        parent::tearDown();
    }

    /**
     * Every call over the real outbound HTTP client: its method, its path
     * below the server's address, the bearer token, the JSON it sends and
     * what it answers. A create sends set_online=false, a pending post ID,
     * and a root only for a reply; an edit is a patch of the message and
     * the props; no request sends from_bot. Team and channel names go into
     * the path encoded.
     */
    public function testItSpeaksMattermostsApiThroughTheOutboundClient(): void
    {
        $api = $this->loopbackApi([
            '/mm/api/v4/users/me' => ['status' => 200, 'body' => (string)json_encode(['id' => self::BOT_ID, 'username' => 'ansilume-bot', 'is_bot' => true])],
            '/mm/api/v4/channels/' . self::CHANNEL_ID => ['status' => 200, 'body' => (string)json_encode(['id' => self::CHANNEL_ID, 'delete_at' => 0])],
            '/mm/api/v4/teams/name/ops%20eu/channels/name/de%2Fploys' => ['status' => 200, 'body' => (string)json_encode(['id' => self::CHANNEL_ID, 'delete_at' => 1700000000000])],
            '/mm/api/v4/channels/' . self::CHANNEL_ID . '/members/me' => ['status' => 200, 'body' => '{"user_id":"x"}'],
            '/mm/api/v4/posts' => ['status' => 201, 'body' => (string)json_encode(['id' => self::POST_ID, 'message' => ''])],
            '/mm/api/v4/posts/' . self::POST_ID . '/patch' => ['status' => 200, 'body' => '{}'],
            '/mm/api/v4/posts/' . self::POST_ID => ['status' => 200, 'body' => (string)json_encode(['id' => self::POST_ID, 'user_id' => self::BOT_ID, 'channel_id' => self::CHANNEL_ID, 'delete_at' => 0])],
        ], '/mm');
        $props = ['attachments' => [['color' => '#1C58D9', 'title' => 'T']]];

        $this->assertSame(['id' => self::BOT_ID, 'username' => 'ansilume-bot', 'is_bot' => true], $api->me());
        $this->assertSame(['id' => self::CHANNEL_ID, 'delete_at' => 0], $api->channel(self::CHANNEL_ID));
        $this->assertSame(['id' => self::CHANNEL_ID, 'delete_at' => 1700000000000], $api->channelByName('ops eu', 'de/ploys'));
        $api->membership(self::CHANNEL_ID);
        $this->assertSame(self::POST_ID, $api->createPost(self::CHANNEL_ID, 'Hello', $props, null, 'bot:pending-1'));
        $this->assertSame(self::POST_ID, $api->createPost(self::CHANNEL_ID, 'A reply', [], self::POST_ID, ''));
        $edits = new MattermostApi(new OutboundHttpClient(), (string)$this->base . '/mm', self::TOKEN);
        $edits->patchPost(self::POST_ID, '', $props);
        $this->assertSame(['user_id' => self::BOT_ID, 'channel_id' => self::CHANNEL_ID], $edits->getPost(self::POST_ID));

        $requests = $this->server?->requests() ?? [];
        $this->assertSame(
            [
                'GET /mm/api/v4/users/me',
                'GET /mm/api/v4/channels/' . self::CHANNEL_ID,
                'GET /mm/api/v4/teams/name/ops%20eu/channels/name/de%2Fploys',
                'GET /mm/api/v4/channels/' . self::CHANNEL_ID . '/members/me',
                'POST /mm/api/v4/posts?set_online=false',
                'POST /mm/api/v4/posts?set_online=false',
                'PUT /mm/api/v4/posts/' . self::POST_ID . '/patch',
                'GET /mm/api/v4/posts/' . self::POST_ID,
            ],
            array_map(static fn (array $r): string => $r['method'] . ' ' . $r['path'], $requests)
        );
        foreach ($requests as $request) {
            $this->assertSame('Bearer ' . self::TOKEN, $request['headers']['Authorization'] ?? null);
            $this->assertSame('application/json', $request['headers']['Accept'] ?? null);
            $this->assertStringNotContainsString('from_bot', $request['body']);
        }
        $this->assertSame(
            ['channel_id' => self::CHANNEL_ID, 'message' => 'Hello', 'props' => $props, 'pending_post_id' => 'bot:pending-1'],
            json_decode($requests[4]['body'], true)
        );
        $this->assertSame('application/json', $requests[4]['headers']['Content-Type'] ?? null);
        $this->assertSame(['channel_id' => self::CHANNEL_ID, 'message' => 'A reply', 'props' => [], 'root_id' => self::POST_ID], json_decode($requests[5]['body'], true));
        $this->assertSame(['message' => '', 'props' => $props], json_decode($requests[6]['body'], true));
        $this->assertSame('', $requests[0]['body'], 'a GET sends no body');
    }

    /**
     * Regression (spec section 1): an error says what failed, the status,
     * Mattermost's error id and the request ID, and nothing else: not the
     * server's address, the token, the path with its IDs or the text of the
     * answer.
     */
    public function testAnErrorSaysOnlyTheStatusTheErrorIdAndTheRequestId(): void
    {
        $api = $this->loopbackApi(['*' => [
            'status' => 500,
            'body' => (string)json_encode(['id' => 'app.post.save.app_error', 'message' => 'secret-answer-text-5502', 'request_id' => 'bodyrequestid']),
            'headers' => ['X-Request-Id' => 'h7k2m9q4w1e8r5t6y3u0i2o9pz'],
        ]]);

        $error = $this->errorOf(static fn () => $api->createPost(self::CHANNEL_ID, 'secret-message-5503', [], null, ''));

        $this->assertSame(
            'Mattermost: posting failed (HTTP 500, error app.post.save.app_error, request ID h7k2m9q4w1e8r5t6y3u0i2o9pz).',
            $error->getMessage()
        );
        $this->assertSame([OutboundHttpException::KIND_STATUS, 500, 'app.post.save.app_error', 'h7k2m9q4w1e8r5t6y3u0i2o9pz'], [$error->kind, $error->httpStatus, $error->errorId, $error->requestId]);
        $this->assertNotNull($error->response, 'the answer stays on the error, for its Retry-After');
        foreach (['secret-answer-text-5502', 'secret-message-5503', self::TOKEN, '127.0.0.1', self::CHANNEL_ID] as $secret) {
            $this->assertStringNotContainsString($secret, (string)$error);
        }
    }

    /**
     * An error id or a request ID that is not of the form Mattermost gives
     * (the answer of another server) is left out, as is the error id of an
     * answer that is no JSON.
     *
     * @return array<string, array{0: string, 1: array<string, string>, 2: string}>
     */
    public static function oddAnswerProvider(): array
    {
        return [
            'an error id with spaces' => ['{"id":"api error id"}', ['x-request-id' => 'abc123'], 'HTTP 502, request ID abc123'],
            'an error id in upper case' => ['{"id":"API.ERROR"}', [], 'HTTP 502'],
            'an error id of 129 characters' => ['{"id":"' . str_repeat('a', 129) . '"}', [], 'HTTP 502'],
            'an error id that is no text' => ['{"id":["x"]}', [], 'HTTP 502'],
            'a request ID with odd characters' => ['{"id":"api.x"}', ['x-request-id' => '<script>'], 'HTTP 502, error api.x'],
            'no JSON' => ['<html>Bad gateway: secret-gateway-text</html>', [], 'HTTP 502'],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('oddAnswerProvider')]
    public function testAnErrorIdOrARequestIdOfAnotherFormIsLeftOut(string $body, array $headers, string $detail): void
    {
        $mattermost = new FakeMattermost();
        $mattermost->script = [['method' => 'GET', 'path' => '/api/v4/users/me', 'status' => 502, 'body' => $body, 'headers' => $headers + ['x-request-id' => '']]];
        $api = new MattermostApi($mattermost, FakeMattermost::SERVER, FakeMattermost::BOT_TOKEN);

        $error = $this->errorOf(static fn () => $api->me());

        $this->assertSame(sprintf(MattermostApiException::FAILED, MattermostApi::OP_ME, $detail), $error->getMessage());
        $this->assertSame($detail, $error->detail());
    }

    /**
     * Any 401 means the token: the error names the causes, whatever call
     * it answered (spec section 5), and fails the delivery for good.
     */
    public function testUnauthorizedNamesTheCausesWhateverTheCall(): void
    {
        $mattermost = new FakeMattermost();
        $api = new MattermostApi($mattermost, FakeMattermost::SERVER, 'revoked-token-5504');

        foreach ([static fn () => $api->me(), static fn () => $api->createPost(FakeMattermost::CHANNEL_ID, 'x', [], null, ''), static fn () => $api->patchPost('p', 'x', [])] as $call) {
            $error = $this->errorOf($call);

            $this->assertSame(401, $error->httpStatus);
            $this->assertSame('api.context.session_expired.app_error', $error->errorId);
            $this->assertStringStartsWith(
                'Mattermost refused the bot token (HTTP 401, error api.context.session_expired.app_error, request ID req',
                $error->getMessage()
            );
            $this->assertStringEndsWith('): it was revoked or has expired, or the bot was disabled when its owner was deactivated.', $error->getMessage());
            $this->assertStringNotContainsString('revoked-token-5504', $error->getMessage());
            $this->assertStringNotContainsString(FakeMattermost::BODY_MARKER, (string)$error);
        }
    }

    /**
     * What each call's refusal means, in the words of MattermostApiException;
     * a refusal that the channel decides on (a reply to a deleted root, an
     * edit that Mattermost refused) keeps its status and error id.
     */
    public function testEachRefusalNamesItsCauses(): void
    {
        $mattermost = new FakeMattermost();
        // A fresh API for each call: the calls of one delivery are bounded.
        $api = new class ($mattermost) {
            public function __construct(private readonly FakeMattermost $mattermost)
            {
            }

            /**
             * @param list<mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                return (new MattermostApi($this->mattermost, FakeMattermost::SERVER, FakeMattermost::BOT_TOKEN))->$name(...$arguments);
            }
        };
        $missing = 'nochannelaaaaaaaaaaaaaaaaa';

        $this->assertStringStartsWith('Mattermost has no channel ' . $missing . ' that the bot may see (HTTP 404, error app.channel.get.existing.app_error', $this->errorOf(static fn () => $api->channel($missing))->getMessage());
        $this->assertStringStartsWith('Mattermost has no channel ops/nothing that the bot may see (HTTP 404', $this->errorOf(static fn () => $api->channelByName('ops', 'nothing'))->getMessage());
        $this->assertStringStartsWith('The bot is not a member of the Mattermost channel ' . $missing . ' (HTTP 404', $this->errorOf(static fn () => $api->membership($missing))->getMessage());
        $this->assertStringStartsWith('Mattermost refused the post in channel ' . $missing . ' (HTTP 403', $this->errorOf(static fn () => $api->createPost($missing, 'x', [], null, ''))->getMessage());

        $mattermost->servers[FakeMattermost::SERVER]['channels'][FakeMattermost::CHANNEL_ID]['delete_at'] = 1700000000000;
        $archived = $this->errorOf(static fn () => $api->createPost(FakeMattermost::CHANNEL_ID, 'x', [], null, ''));
        $this->assertStringStartsWith('The Mattermost channel ' . FakeMattermost::CHANNEL_ID . ' is archived (HTTP 400, error api.post.create_post.can_not_post_to_deleted.error', $archived->getMessage());

        $mattermost->servers[FakeMattermost::SERVER]['channels'][FakeMattermost::CHANNEL_ID]['delete_at'] = 0;
        $reply = $this->errorOf(static fn () => $api->createPost(FakeMattermost::CHANNEL_ID, 'x', [], 'gonepostaaaaaaaaaaaaaaaaaa', ''));
        $this->assertSame([400, FakeMattermost::ROOT_DELETED], [$reply->httpStatus, $reply->errorId]);
        $this->assertStringStartsWith('Mattermost: posting failed (HTTP 400, error ' . FakeMattermost::ROOT_DELETED, $reply->getMessage());
        $edit = $this->errorOf(static fn () => $api->patchPost('gonepostaaaaaaaaaaaaaaaaaa', '', []));
        $this->assertSame([403, FakeMattermost::PERMISSION_ERROR], [$edit->httpStatus, $edit->errorId]);
        $this->assertStringStartsWith('Mattermost: editing the status post failed (HTTP 403', $edit->getMessage());
    }

    /**
     * The lookup of a post (GET /posts/{id}), with which the channel tells a
     * deleted status post from an edit that Mattermost does not allow, as
     * Mattermost answers both edits alike (403): who made the post and in
     * which channel; null for a post that Mattermost no longer has (404), a
     * deleted one too; any other refusal as it is, such as a 403 for a post
     * the bot may not read; an answer that is no post refuses the delivery
     * for good.
     */
    public function testTheLookupOfAPostSaysWhoMadeItAndWhere(): void
    {
        $mattermost = new FakeMattermost();
        $api = new MattermostApi($mattermost, FakeMattermost::SERVER, FakeMattermost::BOT_TOKEN);
        $postId = $api->createPost(FakeMattermost::CHANNEL_ID, 'x', [], null, '');

        $this->assertSame(['user_id' => FakeMattermost::BOT_ID, 'channel_id' => FakeMattermost::CHANNEL_ID], $api->getPost($postId));
        $this->assertSame(['GET', '/api/v4/posts/' . $postId, FakeMattermost::BOT_TOKEN, null], [$mattermost->requests[1]['method'], $mattermost->requests[1]['path'], $mattermost->requests[1]['token'], $mattermost->requests[1]['body']]);

        $channel = $mattermost->servers[FakeMattermost::SERVER]['channels'][FakeMattermost::CHANNEL_ID];
        $mattermost->servers[FakeMattermost::SERVER]['channels'][FakeMattermost::CHANNEL_ID]['private'] = true;
        $mattermost->removeBot();
        $refused = $this->errorOf(static fn () => $api->getPost($postId));
        $this->assertSame([OutboundHttpException::KIND_STATUS, 403], [$refused->kind, $refused->httpStatus]);
        $this->assertStringStartsWith('Mattermost: looking up the status post failed (HTTP 403, error ' . FakeMattermost::PERMISSION_ERROR . ', request ID req', $refused->getMessage());
        $mattermost->servers[FakeMattermost::SERVER]['channels'][FakeMattermost::CHANNEL_ID] = $channel;

        $mattermost->deletePost($postId);
        $this->assertNull($api->getPost($postId), 'a deleted post');
        $this->assertNull($api->getPost('nopostaaaaaaaaaaaaaaaaaaaa'), 'no such post');

        $mattermost->script = [['method' => 'GET', 'path' => '/api/v4/posts/' . $postId, 'status' => 200, 'body' => '{"id":"' . $postId . '","user_id":"../x"}']];
        $odd = $this->errorOf(static fn () => $api->getPost($postId));
        $this->assertSame(MattermostApiException::KIND_REFUSED, $odd->kind);
        $this->assertSame(sprintf(MattermostApiException::UNEXPECTED_ANSWER, MattermostApi::OP_LOOKUP, 200), $odd->getMessage());
    }

    /**
     * Regression (review: a lost answer posted twice): Mattermost keeps a
     * create's pending post ID for 30 seconds after it made the post, and a
     * delivery's retry comes 30 seconds or more after the failed attempt, so
     * a create whose answer was lost after Mattermost made the post was
     * posted again. A create of a delivery whose answer may have been lost
     * so, no answer at all or a 5xx, is now sent once more after the pause,
     * as it was, and Mattermost answers the post the first one made.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function lostAnswerProvider(): array
    {
        return [
            'no answer: the client timed out' => [0, OutboundHttpException::KIND_TIMEOUT],
            'no answer: the connection broke' => [0, OutboundHttpException::KIND_CONNECT],
            'a proxy\'s 502 after Mattermost posted' => [502, OutboundHttpException::KIND_STATUS],
            'a 504 after Mattermost posted' => [504, OutboundHttpException::KIND_STATUS],
        ];
    }

    #[DataProvider('lostAnswerProvider')]
    public function testRegressionACreateWhoseAnswerWasLostIsSentOnceMore(int $status, string $kind): void
    {
        $mattermost = new FakeMattermost();
        $mattermost->script = [['method' => 'POST', 'path' => '/api/v4/posts', 'status' => $status, 'served' => true, 'kind' => $kind]];
        $api = new MattermostApi($mattermost, FakeMattermost::SERVER, FakeMattermost::BOT_TOKEN, 20);
        $started = microtime(true);

        $postId = $api->createPost(FakeMattermost::CHANNEL_ID, 'Hello', [], null, 'bot:pending-7');

        $this->assertGreaterThanOrEqual(0.02, microtime(true) - $started, 'the retry waits its pause');
        $this->assertSame([$postId], array_column($mattermost->posts(), 'id'), 'one post: the one the first create made');
        $creates = $mattermost->requestsTo('POST', '/api/v4/posts');
        $this->assertCount(2, $creates);
        $this->assertSame($creates[0]['body'], $creates[1]['body'], 'the same create, with the same pending post ID');
        $this->assertSame(2000, MattermostApi::CREATE_RETRY_PAUSE_MS, 'a few seconds: well within the 30 seconds that Mattermost keeps the ID');
    }

    /**
     * A create is sent again only where that cannot post twice and may
     * help: never one without a pending post ID (Send Test), nor one that
     * Mattermost refused (4xx: it made nothing; a 429 asks to wait), nor one
     * whose TLS handshake failed (nothing was sent), nor a second one of the
     * same delivery, nor one when the delivery has no request left; the
     * error of the retry is the error of the create.
     */
    public function testACreateIsSentAgainOncePerDeliveryAndOnlyWhereThatHelps(): void
    {
        $lost = ['method' => 'POST', 'path' => '/api/v4/posts', 'status' => 0, 'served' => true];

        $test = $this->apiAnswering([$lost]);
        $this->assertSame(OutboundHttpException::KIND_TIMEOUT, $this->errorOf(static fn () => $test['api']->createPost(FakeMattermost::CHANNEL_ID, 'x', [], null, ''))->kind);
        $this->assertCount(1, $test['mattermost']->requests, 'Send Test: no pending post ID, no retry');

        $tls = $this->apiAnswering([['method' => 'POST', 'path' => '/api/v4/posts', 'status' => 0, 'kind' => OutboundHttpException::KIND_TLS]]);
        $this->assertSame(OutboundHttpException::KIND_TLS, $this->errorOf(static fn () => $tls['api']->createPost(FakeMattermost::CHANNEL_ID, 'x', [], null, 'bot:pending-1'))->kind);
        $this->assertCount(1, $tls['mattermost']->requests, 'a failed TLS handshake sent nothing');

        foreach ([400, 403, 429] as $status) {
            $refused = $this->apiAnswering([['method' => 'POST', 'path' => '/api/v4/posts', 'status' => $status, 'body' => '{}']]);
            $this->assertSame($status, $this->errorOf(static fn () => $refused['api']->createPost(FakeMattermost::CHANNEL_ID, 'x', [], null, 'bot:pending-1'))->httpStatus);
            $this->assertCount(1, $refused['mattermost']->requests, "a {$status} is not sent again");
        }

        $twice = $this->apiAnswering([$lost]);
        $twice['api']->createPost(FakeMattermost::CHANNEL_ID, 'x', [], null, 'bot:pending-1');
        $twice['mattermost']->script = [$lost];
        $this->assertSame(OutboundHttpException::KIND_TIMEOUT, $this->errorOf(static fn () => $twice['api']->createPost(FakeMattermost::CHANNEL_ID, 'y', [], null, 'bot:pending-2'))->kind);
        $this->assertCount(3, $twice['mattermost']->requests, 'once per delivery');

        $failing = $this->apiAnswering([$lost, ['method' => 'POST', 'path' => '/api/v4/posts', 'status' => 503, 'body' => '']]);
        $this->assertSame(503, $this->errorOf(static fn () => $failing['api']->createPost(FakeMattermost::CHANNEL_ID, 'x', [], null, 'bot:pending-1'))->httpStatus);
        $this->assertCount(2, $failing['mattermost']->requests, 'the error of the retry');

        $spent = $this->apiAnswering([$lost]);
        for ($i = 1; $i < MattermostApi::MAX_REQUESTS; $i++) {
            $spent['api']->me();
        }
        $this->assertSame(OutboundHttpException::KIND_TIMEOUT, $this->errorOf(static fn () => $spent['api']->createPost(FakeMattermost::CHANNEL_ID, 'x', [], null, 'bot:pending-1'))->kind);
        $this->assertCount(MattermostApi::MAX_REQUESTS, $spent['mattermost']->requests, 'no request left');
    }

    /**
     * A 2xx answer that is not what Mattermost's API gives (an answer of
     * another server, a user or channel without a valid ID, a post without
     * one) refuses the delivery for good (KIND_REFUSED).
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function unexpectedProvider(): array
    {
        return [
            'no JSON' => ['/api/v4/users/me', '<html>Welcome</html>', 'me'],
            'a user without an ID' => ['/api/v4/users/me', '{"username":"x","is_bot":true}', 'me'],
            'a channel with an odd ID' => ['/api/v4/channels/' . FakeMattermost::CHANNEL_ID, '{"id":"../x","delete_at":0}', 'channel'],
            'a channel without delete_at' => ['/api/v4/channels/' . FakeMattermost::CHANNEL_ID, '{"id":"' . FakeMattermost::CHANNEL_ID . '"}', 'channel'],
            'a post without an ID' => ['/api/v4/posts', '{"message":"x"}', 'post'],
        ];
    }

    #[DataProvider('unexpectedProvider')]
    public function testAnAnswerTheApiDoesNotGiveRefusesTheDelivery(string $path, string $body, string $call): void
    {
        $mattermost = new FakeMattermost();
        $mattermost->script = [['method' => $call === 'post' ? 'POST' : 'GET', 'path' => $path, 'status' => $call === 'post' ? 201 : 200, 'body' => $body]];
        $api = new MattermostApi($mattermost, FakeMattermost::SERVER, FakeMattermost::BOT_TOKEN);

        $error = $this->errorOf(match ($call) {
            'me' => static fn () => $api->me(),
            'channel' => static fn () => $api->channel(FakeMattermost::CHANNEL_ID),
            default => static fn () => $api->createPost(FakeMattermost::CHANNEL_ID, 'x', [], null, ''),
        });

        $this->assertSame(MattermostApiException::KIND_REFUSED, $error->kind);
        $operation = ['me' => MattermostApi::OP_ME, 'channel' => MattermostApi::OP_CHANNEL, 'post' => MattermostApi::OP_POST][$call];
        $this->assertSame(sprintf(MattermostApiException::UNEXPECTED_ANSWER, $operation, $call === 'post' ? 201 : 200), $error->getMessage());
    }

    /**
     * A user whose answer leaves is_bot out is no bot, and one without a
     * username has none.
     */
    public function testAUserIsABotOnlyWhenTheAnswerSaysSo(): void
    {
        $mattermost = new FakeMattermost();
        $mattermost->script = [['method' => 'GET', 'path' => '/api/v4/users/me', 'status' => 200, 'body' => '{"id":"' . self::BOT_ID . '","is_bot":"true"}']];
        $api = new MattermostApi($mattermost, FakeMattermost::SERVER, FakeMattermost::BOT_TOKEN);

        $this->assertSame(['id' => self::BOT_ID, 'username' => '', 'is_bot' => false], $api->me());
    }

    /**
     * Mattermost's answer to a create repeats the post. Cut at the
     * client's limit, it still names the post first, and the post is
     * known.
     */
    public function testACutAnswerStillNamesThePost(): void
    {
        $this->server = new HttpLoopbackServer();
        $base = $this->server->startWith(['*' => ['status' => 201, 'body' => '{"id":"' . self::POST_ID . '","message":"' . str_repeat('x', 5000) . '"}']]);
        $client = new OutboundHttpClient(['maxBodyBytes' => 200]);
        $api = new MattermostApi($client, $base, self::TOKEN);

        $this->assertSame(self::POST_ID, $api->createPost(self::CHANNEL_ID, 'x', [], null, ''));
    }

    /**
     * A server that cannot be reached: the client's error, of its kind, so
     * that the delivery tries again.
     */
    public function testAServerThatCannotBeReachedIsAnErrorOfTheClient(): void
    {
        $api = new MattermostApi(new OutboundHttpClient(['connectTimeout' => 2]), 'http://127.0.0.1:1', self::TOKEN);

        $error = $this->errorOf(static fn () => $api->me());

        $this->assertSame(OutboundHttpException::KIND_CONNECT, $error->kind);
        $this->assertSame('Mattermost: checking the bot token failed (Could not connect (curl error 7)).', $error->getMessage());
        $this->assertSame('Could not connect (curl error 7)', $error->detail());
        $this->assertSame([0, '', ''], [$error->httpStatus, $error->errorId, $error->requestId]);
    }

    /**
     * Bounded: a delivery makes at most MAX_REQUESTS requests, so that it
     * ends within the notification queue's TTR; the one after them is
     * refused before it is sent, for good (KIND_REQUEST).
     */
    public function testTheRequestsOfADeliveryAreBounded(): void
    {
        $mattermost = new FakeMattermost();
        $api = new MattermostApi($mattermost, FakeMattermost::SERVER, FakeMattermost::BOT_TOKEN);
        for ($i = 0; $i < MattermostApi::MAX_REQUESTS; $i++) {
            $api->me();
        }

        $error = $this->errorOf(static fn () => $api->me());

        $this->assertSame(MattermostApiException::KIND_REQUEST, $error->kind);
        $this->assertSame(sprintf(MattermostApiException::TOO_MANY_REQUESTS, MattermostApi::MAX_REQUESTS), $error->getMessage());
        $this->assertSame(rtrim(sprintf(MattermostApiException::TOO_MANY_REQUESTS, MattermostApi::MAX_REQUESTS), '.'), $error->detail());
        $this->assertCount(MattermostApi::MAX_REQUESTS, $mattermost->requests);
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * An API of a loopback server below $prefix that answers as $answers
     * say (HttpLoopbackServer::startWith()).
     *
     * @param array<string, array{status?: int, body?: string, headers?: array<string, string>}> $answers
     */
    private function loopbackApi(array $answers, string $prefix = ''): MattermostApi
    {
        $this->server = new HttpLoopbackServer();
        $this->base = $this->server->startWith($answers);

        return new MattermostApi(new OutboundHttpClient(), $this->base . $prefix . '/', self::TOKEN);
    }

    /**
     * An API of a FakeMattermost whose script is $script, without the pause
     * before a create is sent again.
     *
     * @param list<array{method: string, path: string, status: int, body?: string, served?: bool, kind?: string}> $script
     * @return array{api: MattermostApi, mattermost: FakeMattermost}
     */
    private function apiAnswering(array $script): array
    {
        $mattermost = new FakeMattermost();
        $mattermost->script = $script;

        return ['api' => new MattermostApi($mattermost, FakeMattermost::SERVER, FakeMattermost::BOT_TOKEN, 0), 'mattermost' => $mattermost];
    }

    private function errorOf(\Closure $call): MattermostApiException
    {
        try {
            $call();
        } catch (MattermostApiException $e) {
            return $e;
        }
        $this->fail('the call threw nothing');
    }
}
