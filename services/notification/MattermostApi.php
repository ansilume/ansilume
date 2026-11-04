<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\OutboundHttpClient;
use app\components\OutboundHttpException;
use app\components\OutboundHttpResponse;

/**
 * The calls of Mattermost's REST API v4 that the Mattermost channel makes,
 * as one bot of one server: through the outbound HTTP client (https, no
 * redirect, its timeouts, TLS verified), with the bot token as a bearer
 * token. One instance serves one delivery, or one Send Test.
 *
 * - me(): GET /users/me, the bot's user;
 * - channel(), channelByName(): the channel by its ID, or by its team's and
 *   its own name;
 * - membership(): GET /channels/{id}/members/me, whether the bot is a
 *   member: the bot must be one to edit its posts;
 * - createPost(): POST /posts?set_online=false, with a pending post ID,
 *   which Mattermost keeps for 30 seconds after it made the post, so that
 *   the same create sent again within them answers the post it made;
 *   never with from_bot, which Mattermost sets for a bot;
 * - getPost(): GET /posts/{id}, who made a post and in which channel, or
 *   that Mattermost no longer has it;
 * - patchPost(): PUT /posts/{id}/patch, which replaces the message and the
 *   props; never PUT /posts/{id}, which blanks the fields it is not sent.
 *
 * A create of a delivery (with a pending post ID) whose answer may have been
 * lost after Mattermost made the post, no answer at all (a connection lost,
 * the client's timeout) or a 5xx (a proxy's error after Mattermost
 * answered, or Mattermost's own while it still saves the first create), is
 * sent once more as it was, after createRetryPauseMs: Mattermost answers
 * the post the first one made, where the delivery's retry after its backoff
 * (30 seconds at least) would come after it forgot the ID and post again.
 * Once per delivery, and only while a request is left. Never a create
 * without a pending post ID (Send Test), which could post twice, nor one
 * that Mattermost refused (4xx: it made nothing; 429 asks to wait).
 *
 * A call that failed throws a MattermostApiException that says only what
 * failed, the status, Mattermost's error id and the request ID: any 401 as
 * TOKEN_REFUSED, and each call's refusals in the words that name their
 * causes (CHANNEL_NOT_FOUND, NOT_A_MEMBER, POST_REFUSED, CHANNEL_ARCHIVED).
 * An edit refused, and a reply to a deleted root, keep their status and
 * error id: the channel decides on them (MattermostChannel). A 2xx answer
 * that is not what the API gives is UNEXPECTED_ANSWER.
 *
 * At most MAX_REQUESTS requests: the one after them is refused before it is
 * sent (TOO_MANY_REQUESTS), so that a delivery ends within the notification
 * queue's TTR (120 seconds) at the client's timeout (15 seconds) per
 * request, with the pauses before an edit or a create is sent again.
 */
final class MattermostApi
{
    /**
     * The most requests one delivery makes: the check of the token and the
     * channel after a change (3), then an edit, its one retry, the lookup
     * of the post and a new post; or a reply, a new root and the reply
     * again, one of them sent twice after a lost answer.
     */
    public const MAX_REQUESTS = 7;

    /** How long to wait before a create whose answer may have been lost is sent again, in milliseconds. */
    public const CREATE_RETRY_PAUSE_MS = 2000;

    /** What a call does, in its errors (MattermostApiException::FAILED). */
    public const OP_ME = 'checking the bot token';
    public const OP_CHANNEL = 'looking up the channel';
    public const OP_MEMBERSHIP = 'checking that the bot is a member of the channel';
    public const OP_POST = 'posting';
    public const OP_EDIT = 'editing the status post';
    public const OP_LOOKUP = 'looking up the status post';

    /** The paths of the calls, below the server's address. */
    public const PATH_ME = '/api/v4/users/me';
    public const PATH_CHANNEL = '/api/v4/channels/%s';
    public const PATH_CHANNEL_BY_NAME = '/api/v4/teams/name/%s/channels/name/%s';
    public const PATH_MEMBERSHIP = '/api/v4/channels/%s/members/me';
    public const PATH_POSTS = '/api/v4/posts?set_online=false';
    public const PATH_POST = '/api/v4/posts/%s';
    public const PATH_PATCH = '/api/v4/posts/%s/patch';

    /** The form of Mattermost's IDs: of users, channels and posts. */
    private const ID = '/^[a-z0-9]{26}$/';

    /** The ID of a post at the start of an answer that the client's limit cut. */
    private const LEADING_ID = '/^\{"id":"([a-z0-9]{26})"/';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    private readonly string $serverUrl;

    private int $requests = 0;

    /** Whether this delivery sent a create once more (see the class). */
    private bool $createSentAgain = false;

    /**
     * @param string $serverUrl the address of the server (MattermostConfig::$serverUrl)
     * @param int $createRetryPauseMs the wait before a create whose answer may have been lost is sent again
     */
    public function __construct(
        private readonly OutboundHttpClient $http,
        string $serverUrl,
        #[\SensitiveParameter] private readonly string $token,
        private readonly int $createRetryPauseMs = self::CREATE_RETRY_PAUSE_MS
    ) {
        $this->serverUrl = rtrim($serverUrl, '/');
    }

    /**
     * The bot's user (GET /users/me): its ID, its username ('' without one)
     * and whether it is a bot (only when the answer says true).
     *
     * @return array{id: string, username: string, is_bot: bool}
     */
    public function me(): array
    {
        $user = $this->json('GET', self::PATH_ME, null, self::OP_ME);
        $username = $user['username'] ?? null;

        return [
            'id' => $this->idIn($user, self::OP_ME),
            'username' => is_string($username) ? $username : '',
            'is_bot' => ($user['is_bot'] ?? null) === true,
        ];
    }

    /**
     * The channel $id: its ID and when it was archived (0: it is not).
     *
     * @return array{id: string, delete_at: int}
     */
    public function channel(string $id): array
    {
        return $this->channelAt(sprintf(self::PATH_CHANNEL, rawurlencode($id)), $id);
    }

    /**
     * The channel named $name of the team named $team.
     *
     * @return array{id: string, delete_at: int}
     */
    public function channelByName(string $team, string $name): array
    {
        return $this->channelAt(sprintf(self::PATH_CHANNEL_BY_NAME, rawurlencode($team), rawurlencode($name)), $team . '/' . $name);
    }

    /**
     * Refuses (NOT_A_MEMBER) unless the bot is a member of channel $channelId.
     */
    public function membership(string $channelId): void
    {
        try {
            $this->send('GET', sprintf(self::PATH_MEMBERSHIP, rawurlencode($channelId)), null, self::OP_MEMBERSHIP);
        } catch (MattermostApiException $e) {
            throw in_array($e->httpStatus, [403, 404], true) ? $e->because(MattermostApiException::NOT_A_MEMBER, $channelId) : $e;
        }
    }

    /**
     * Creates a post in channel $channelId, a reply in the thread of
     * $rootId when given, and returns its ID. $pendingPostId ('' for none)
     * lets the same create sent again within 30 seconds answer the post
     * this one made: a create whose answer may have been lost is sent once
     * more (see the class).
     *
     * @param array<string, mixed> $props
     */
    public function createPost(string $channelId, string $message, array $props, ?string $rootId, string $pendingPostId): string
    {
        $post = ['channel_id' => $channelId, 'message' => $message, 'props' => (object)$props];
        if ($rootId !== null) {
            $post['root_id'] = $rootId;
        }
        if ($pendingPostId !== '') {
            $post['pending_post_id'] = $pendingPostId;
        }
        try {
            $response = $this->sendCreate($post, $pendingPostId !== '');
        } catch (MattermostApiException $e) {
            throw self::postRefusal($e, $channelId);
        }

        return self::postIdOf($response) ?? throw MattermostApiException::refused(
            sprintf(MattermostApiException::UNEXPECTED_ANSWER, self::OP_POST, $response->status)
        );
    }

    /**
     * Post $postId (GET /posts/{id}): who made it and in which channel;
     * null when Mattermost no longer has it (404), as after it was deleted,
     * or moved to another channel, which deletes it where it was. Any other
     * refusal is thrown as it is, such as a 403 for a post the bot may not
     * read.
     *
     * @return array{user_id: string, channel_id: string}|null
     */
    public function getPost(string $postId): ?array
    {
        try {
            $post = $this->json('GET', sprintf(self::PATH_POST, rawurlencode($postId)), null, self::OP_LOOKUP);
        } catch (MattermostApiException $e) {
            if ($e->httpStatus === 404) {
                return null;
            }
            throw $e;
        }

        return ['user_id' => $this->idIn($post, self::OP_LOOKUP, 'user_id'), 'channel_id' => $this->idIn($post, self::OP_LOOKUP, 'channel_id')];
    }

    /**
     * Replaces the message and the props of post $postId.
     *
     * @param array<string, mixed> $props
     */
    public function patchPost(string $postId, string $message, array $props): void
    {
        $this->send('PUT', sprintf(self::PATH_PATCH, rawurlencode($postId)), ['message' => $message, 'props' => (object)$props], self::OP_EDIT);
    }

    /**
     * @return array{id: string, delete_at: int}
     */
    private function channelAt(string $path, string $label): array
    {
        try {
            $channel = $this->json('GET', $path, null, self::OP_CHANNEL);
        } catch (MattermostApiException $e) {
            throw in_array($e->httpStatus, [403, 404], true) ? $e->because(MattermostApiException::CHANNEL_NOT_FOUND, $label) : $e;
        }
        $deleteAt = $channel['delete_at'] ?? null;
        if (!is_int($deleteAt)) {
            throw MattermostApiException::refused(sprintf(MattermostApiException::UNEXPECTED_ANSWER, self::OP_CHANNEL, 200));
        }

        return ['id' => $this->idIn($channel, self::OP_CHANNEL), 'delete_at' => $deleteAt];
    }

    /**
     * The JSON object of the 2xx answer to the request; UNEXPECTED_ANSWER
     * for an answer that is none.
     *
     * @param array<string, mixed>|null $body
     * @return array<mixed>
     */
    private function json(string $method, string $path, ?array $body, string $operation): array
    {
        $response = $this->send($method, $path, $body, $operation);
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw MattermostApiException::refused(sprintf(MattermostApiException::UNEXPECTED_ANSWER, $operation, $response->status));
        }

        return $decoded;
    }

    /**
     * Sends one request, the bot token as a bearer token, $body as JSON:
     * the 2xx answer, else the error (any 401 as TOKEN_REFUSED).
     *
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body, string $operation): OutboundHttpResponse
    {
        if (++$this->requests > self::MAX_REQUESTS) {
            throw MattermostApiException::unusable(sprintf(MattermostApiException::TOO_MANY_REQUESTS, self::MAX_REQUESTS));
        }
        $headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        try {
            return $this->http->request($method, $this->serverUrl . $path, $headers, $body === null ? null : (string)json_encode($body, self::JSON_FLAGS));
        } catch (OutboundHttpException $e) {
            $error = MattermostApiException::failed($operation, $e);
            throw $error->httpStatus === 401 ? $error->because(MattermostApiException::TOKEN_REFUSED) : $error;
        }
    }

    /**
     * Sends the create $post: once more after createRetryPauseMs when its
     * answer may have been lost and it may be sent again ($retryable: it
     * carries a pending post ID; see the class); the answer, else the error
     * of the last one sent.
     *
     * @param array<string, mixed> $post
     */
    private function sendCreate(array $post, bool $retryable): OutboundHttpResponse
    {
        try {
            return $this->send('POST', self::PATH_POSTS, $post, self::OP_POST);
        } catch (MattermostApiException $e) {
            if (!$retryable || !$this->maySendCreateAgain($e)) {
                throw $e;
            }
        }
        $this->createSentAgain = true;
        if ($this->createRetryPauseMs > 0) {
            usleep($this->createRetryPauseMs * 1000);
        }

        return $this->send('POST', self::PATH_POSTS, $post, self::OP_POST);
    }

    /**
     * Whether a create that ended with $error is sent once more: its answer
     * may have been lost after Mattermost made the post (no answer, or a
     * 5xx), this delivery sent none again yet, and a request is left.
     */
    private function maySendCreateAgain(MattermostApiException $error): bool
    {
        $lost = in_array($error->kind, [OutboundHttpException::KIND_CONNECT, OutboundHttpException::KIND_TIMEOUT], true)
            || ($error->kind === OutboundHttpException::KIND_STATUS && $error->httpStatus >= 500);

        return $lost && !$this->createSentAgain && $this->requests < self::MAX_REQUESTS;
    }

    /**
     * The ID under $key of $object (its id: the object's own), as the
     * answer to $operation gives it; UNEXPECTED_ANSWER when it is none.
     *
     * @param array<mixed> $object
     */
    private function idIn(array $object, string $operation, string $key = 'id'): string
    {
        $id = $object[$key] ?? null;
        if (!is_string($id) || preg_match(self::ID, $id) !== 1) {
            throw MattermostApiException::refused(sprintf(MattermostApiException::UNEXPECTED_ANSWER, $operation, 200));
        }

        return $id;
    }

    /**
     * The refusal of a create in channel $channelId in the words of its
     * causes: 403 (no such channel, or no member), 400 can_not_post_to_deleted
     * (archived); any other as it is.
     */
    private static function postRefusal(MattermostApiException $e, string $channelId): MattermostApiException
    {
        if ($e->httpStatus === 403) {
            return $e->because(MattermostApiException::POST_REFUSED, $channelId);
        }
        if ($e->httpStatus === 400 && str_contains($e->errorId, 'can_not_post_to_deleted')) {
            return $e->because(MattermostApiException::CHANNEL_ARCHIVED, $channelId);
        }

        return $e;
    }

    /**
     * The ID of the post that answered a create: the answer's id; the ID
     * the answer starts with when the client's limit cut it; null for none.
     */
    private static function postIdOf(OutboundHttpResponse $response): ?string
    {
        $decoded = json_decode($response->body, true);
        $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;
        if (is_string($id)) {
            return preg_match(self::ID, $id) === 1 ? $id : null;
        }

        return preg_match(self::LEADING_ID, $response->body, $match) === 1 ? $match[1] : null;
    }
}
