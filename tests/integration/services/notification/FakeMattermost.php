<?php

declare(strict_types=1);

namespace app\tests\integration\services\notification;

use app\components\OutboundHttpClient;
use app\components\OutboundHttpException;
use app\components\OutboundHttpResponse;

/**
 * A fake of Mattermost's REST API v4, for the tests of the Mattermost
 * channel: an outbound HTTP client (swap it in as the component
 * outboundHttp) that answers as the Mattermost servers it holds would,
 * without a network, and records every request it was given ($requests).
 *
 * Each server ($servers, by its address) has users by their token, channels
 * by their ID (with their team, name, whether they are archived, and their
 * members) and posts. It answers as Mattermost 12.0.1 does:
 * - GET /api/v4/users/me: the token's user; 401 for an unknown token;
 * - GET /api/v4/channels/{id} and /api/v4/teams/name/{team}/channels/name/{name}:
 *   the channel; 404 for none, 403 for a private channel the user is not in;
 * - GET /api/v4/channels/{id}/members/me: 200 for a member, else 404;
 * - POST /api/v4/posts: the post, 201; 403 for a non-member (unless the
 *   channel takes posts of non-members, as post:channels grants), 400
 *   can_not_post_to_deleted in an archived channel, 400 root_id for a reply
 *   whose root is deleted; a pending_post_id answers the post it made for
 *   PENDING_SECONDS of the fake's $clock after that create, as Mattermost
 *   keeps it, and is forgotten after them;
 * - GET /api/v4/posts/{id}: the post, 200; 404 app.post.get.app_error for a
 *   deleted post or none, 403 for a post of a private channel the user is
 *   not in;
 * - PUT /api/v4/posts/{id}/patch: 200; 403 api.context.permissions.app_error
 *   for a deleted post, another user's post, a non-member, and every post
 *   while $ownEditsAllowed is false (a permission scheme that takes Edit
 *   Posts from members): Mattermost answers them all alike.
 * Its errors are those of OutboundHttpClient: an answer outside 2xx is an
 * OutboundHttpException of KIND_STATUS with the answer, whose body is
 * Mattermost's error (id, message, request_id) with BODY_MARKER in its
 * message and whose head carries an X-Request-Id; a server it does not hold
 * cannot be reached (KIND_CONNECT). $script answers first: each entry the
 * first request of its method and path (without the query) once; status 0
 * is no answer at all, the client's error of the entry's kind (the
 * timeout, KIND_TIMEOUT, without one: KIND_CONNECT is a connection that
 * broke, KIND_TLS a failed handshake); with served, the server handles the
 * request first (a create makes its post) and the entry then replaces its
 * answer, as a connection lost or a proxy's 5xx after Mattermost made the
 * post.
 */
final class FakeMattermost extends OutboundHttpClient
{
    public const SERVER = 'https://chat.example.com';
    public const OTHER_SERVER = 'https://chat.other.example.com';

    public const BOT_TOKEN = 'botsecret7701bbbbbbbbbbbbb';
    public const OTHER_BOT_TOKEN = 'botsecret7703ccccccccccccc';
    public const PERSONAL_TOKEN = 'personalsecret7702ppppppp';

    public const BOT_ID = 'bot1aaaaaaaaaaaaaaaaaaaaaa';
    public const OTHER_BOT_ID = 'bot2aaaaaaaaaaaaaaaaaaaaaa';
    public const PERSON_ID = 'person1aaaaaaaaaaaaaaaaaaa';

    public const TEAM = 'ops';
    public const CHANNEL = 'deploys';
    public const CHANNEL_ID = 'chan1aaaaaaaaaaaaaaaaaaaaa';
    public const OTHER_CHANNEL_ID = 'chan2aaaaaaaaaaaaaaaaaaaaa';

    /** In the message of every error answer: no error of the channel may show it. */
    public const BODY_MARKER = 'answer-body-marker-7741';

    /** The error id of a reply whose root is deleted. */
    public const ROOT_DELETED = 'api.post.create_post.root_id.app_error';

    /** The error id of every permission that a user lacks, such as an edit of a deleted post. */
    public const PERMISSION_ERROR = 'api.context.permissions.app_error';

    /** The error id of a post that does not exist, or no longer. */
    public const POST_NOT_FOUND = 'app.post.get.app_error';

    /** How long a pending_post_id answers the post it made: Mattermost's pendingPostIDsCacheTTL. */
    public const PENDING_SECONDS = 30;

    /**
     * @var array<string, array{
     *     users: array<string, array{id: string, username: string, is_bot: bool}>,
     *     channels: array<string, array{id: string, team: string, name: string, delete_at: int, private: bool, open: bool, members: list<string>}>,
     *     posts: array<string, array{id: string, channel_id: string, root_id: string, user_id: string, message: string, props: array<mixed>, deleted: bool, edits: int}>
     * }>
     */
    public array $servers = [];

    /** @var list<array{method: string, server: string, path: string, query: string, token: string, headers: list<string>, body: array<mixed>|null}> */
    public array $requests = [];

    /** @var list<array{method: string, path: string, status: int, body?: string, headers?: array<string, string>, served?: bool, kind?: string}> */
    public array $script = [];

    /** Whether members may edit their own posts: false takes Edit Posts from them, as a permission scheme can. */
    public bool $ownEditsAllowed = true;

    /** The fake's clock, in seconds: a test moves it on to let time pass for the pending post IDs. */
    public int $clock = 0;

    /** @var array<string, array{post: string, until: int}> the post of each pending_post_id, and until when it answers it */
    private array $pending = [];

    private int $ids = 0;

    public function __construct()
    {
        parent::__construct();
        $this->servers[self::SERVER] = [
            'users' => [
                self::BOT_TOKEN => ['id' => self::BOT_ID, 'username' => 'ansilume-bot', 'is_bot' => true],
                self::PERSONAL_TOKEN => ['id' => self::PERSON_ID, 'username' => 'alice', 'is_bot' => false],
            ],
            'channels' => [
                self::CHANNEL_ID => [
                    'id' => self::CHANNEL_ID, 'team' => self::TEAM, 'name' => self::CHANNEL, 'delete_at' => 0,
                    'private' => false, 'open' => false, 'members' => [self::BOT_ID, self::PERSON_ID],
                ],
            ],
            'posts' => [],
        ];
        $this->servers[self::OTHER_SERVER] = [
            'users' => [self::OTHER_BOT_TOKEN => ['id' => self::OTHER_BOT_ID, 'username' => 'other-bot', 'is_bot' => true]],
            'channels' => [
                self::OTHER_CHANNEL_ID => [
                    'id' => self::OTHER_CHANNEL_ID, 'team' => self::TEAM, 'name' => self::CHANNEL, 'delete_at' => 0,
                    'private' => false, 'open' => false, 'members' => [self::OTHER_BOT_ID],
                ],
            ],
            'posts' => [],
        ];
    }

    public function request(
        string $method,
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] array $headers = [],
        #[\SensitiveParameter] ?string $body = null
    ): OutboundHttpResponse {
        $server = $this->serverOf($url);
        $path = (string)parse_url($url, PHP_URL_PATH);
        $path = substr($path, strlen((string)parse_url($server, PHP_URL_PATH)));
        $decoded = $body === null ? null : json_decode($body, true);
        $this->requests[] = [
            'method' => $method,
            'server' => $server,
            'path' => $path,
            'query' => (string)parse_url($url, PHP_URL_QUERY),
            'token' => self::tokenOf($headers),
            'headers' => $headers,
            'body' => is_array($decoded) ? $decoded : null,
        ];
        if (!isset($this->servers[$server])) {
            throw new OutboundHttpException(OutboundHttpException::KIND_CONNECT, sprintf(OutboundHttpException::CONNECT_FAILED, 6));
        }
        foreach ($this->script as $index => $answer) {
            if ($answer['method'] === $method && $answer['path'] === $path) {
                unset($this->script[$index]);
                $this->script = array_values($this->script);
                if ($answer['served'] ?? false) {
                    $this->served($server, $method, $path, self::tokenOf($headers), is_array($decoded) ? $decoded : []);
                }

                return $this->scripted($answer);
            }
        }

        return $this->route($server, $method, $path, self::tokenOf($headers), is_array($decoded) ? $decoded : []);
    }

    /**
     * The posts of $server, in the order they were created.
     *
     * @return list<array{id: string, channel_id: string, root_id: string, user_id: string, message: string, props: array<mixed>, deleted: bool, edits: int}>
     */
    public function posts(string $server = self::SERVER): array
    {
        return array_values($this->servers[$server]['posts']);
    }

    /**
     * The requests of $method to a path that starts with $path.
     *
     * @return list<array{method: string, server: string, path: string, query: string, token: string, headers: list<string>, body: array<mixed>|null}>
     */
    public function requestsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => $request['method'] === $method && str_starts_with($request['path'], $path)
        ));
    }

    public function deletePost(string $postId, string $server = self::SERVER): void
    {
        $this->servers[$server]['posts'][$postId]['deleted'] = true;
    }

    public function removeBot(string $server = self::SERVER, string $channelId = self::CHANNEL_ID, string $botId = self::BOT_ID): void
    {
        $channel = $this->servers[$server]['channels'][$channelId];
        $channel['members'] = array_values(array_diff($channel['members'], [$botId]));
        $this->servers[$server]['channels'][$channelId] = $channel;
    }

    /**
     * Handles a request as the server would, whose answer a script entry
     * replaces: what it answered is lost.
     *
     * @param array<string, mixed> $body
     */
    private function served(string $server, string $method, string $path, string $token, array $body): void
    {
        try {
            $this->route($server, $method, $path, $token, $body);
        } catch (OutboundHttpException) {
            // The server refused it: nothing was made, and its answer is lost too.
        }
    }

    /**
     * The answer of a script entry: none at all for status 0, the client's
     * error of the entry's kind (its timeout without one).
     *
     * @param array{method: string, path: string, status: int, body?: string, headers?: array<string, string>, served?: bool, kind?: string} $answer
     */
    private function scripted(array $answer): OutboundHttpResponse
    {
        if ($answer['status'] === 0) {
            $kind = $answer['kind'] ?? OutboundHttpException::KIND_TIMEOUT;
            throw new OutboundHttpException($kind, match ($kind) {
                OutboundHttpException::KIND_TIMEOUT => sprintf(OutboundHttpException::TIMED_OUT, 15),
                OutboundHttpException::KIND_TLS => sprintf(OutboundHttpException::TLS_FAILED, 35),
                default => sprintf(OutboundHttpException::CONNECT_FAILED, 56),
            });
        }

        return $this->answer($answer['status'], $answer['body'] ?? '', $answer['headers'] ?? []);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function route(string $server, string $method, string $path, string $token, array $body): OutboundHttpResponse
    {
        $user = $this->servers[$server]['users'][$token] ?? null;
        if ($user === null) {
            return $this->error(401, 'api.context.session_expired.app_error');
        }
        $route = $method . ' ' . $path;

        return match (true) {
            $route === 'GET /api/v4/users/me' => $this->answer(200, (string)json_encode($user + ['email' => 'bot@example.com'])),
            preg_match('#^GET /api/v4/channels/([^/]+)$#', $route, $m) === 1 => $this->channelAnswer($server, $this->servers[$server]['channels'][$m[1]] ?? null, $user['id']),
            preg_match('#^GET /api/v4/teams/name/([^/]+)/channels/name/([^/]+)$#', $route, $m) === 1 => $this->channelAnswer($server, $this->channelNamed($server, $m[1], $m[2]), $user['id']),
            preg_match('#^GET /api/v4/channels/([^/]+)/members/me$#', $route, $m) === 1 => $this->memberAnswer($server, $m[1], $user['id']),
            $route === 'POST /api/v4/posts' => $this->createPost($server, $user['id'], $body),
            preg_match('#^GET /api/v4/posts/([^/]+)$#', $route, $m) === 1 => $this->postAnswer($server, $m[1], $user['id']),
            preg_match('#^PUT /api/v4/posts/([^/]+)/patch$#', $route, $m) === 1 => $this->patchPost($server, $m[1], $user['id'], $body),
            default => $this->error(404, 'api.context.404.app_error'),
        };
    }

    /**
     * @param array{id: string, team: string, name: string, delete_at: int, private: bool, open: bool, members: list<string>}|null $channel
     */
    private function channelAnswer(string $server, ?array $channel, string $userId): OutboundHttpResponse
    {
        if ($channel === null) {
            return $this->error(404, 'app.channel.get.existing.app_error');
        }
        if ($channel['private'] && !in_array($userId, $channel['members'], true)) {
            return $this->error(403, 'api.context.permissions.app_error');
        }

        return $this->answer(200, (string)json_encode([
            'id' => $channel['id'],
            'name' => $channel['name'],
            'display_name' => 'Display ' . $channel['name'],
            'delete_at' => $channel['delete_at'],
            'type' => $channel['private'] ? 'P' : 'O',
        ]));
    }

    /**
     * @return array{id: string, team: string, name: string, delete_at: int, private: bool, open: bool, members: list<string>}|null
     */
    private function channelNamed(string $server, string $team, string $name): ?array
    {
        foreach ($this->servers[$server]['channels'] as $channel) {
            if ($channel['team'] === rawurldecode($team) && $channel['name'] === rawurldecode($name) && $channel['delete_at'] === 0) {
                return $channel;
            }
        }

        return null;
    }

    private function memberAnswer(string $server, string $channelId, string $userId): OutboundHttpResponse
    {
        $channel = $this->servers[$server]['channels'][$channelId] ?? null;
        if ($channel === null || !in_array($userId, $channel['members'], true)) {
            return $this->error(404, 'app.channel.get_member.missing.app_error');
        }

        return $this->answer(200, (string)json_encode(['channel_id' => $channelId, 'user_id' => $userId]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createPost(string $server, string $userId, array $body): OutboundHttpResponse
    {
        $channel = $this->servers[$server]['channels'][(string)($body['channel_id'] ?? '')] ?? null;
        if ($channel === null || (!$channel['open'] && !in_array($userId, $channel['members'], true))) {
            return $this->error(403, 'api.context.permissions.app_error');
        }
        if ($channel['delete_at'] > 0) {
            return $this->error(400, 'api.post.create_post.can_not_post_to_deleted.error');
        }
        $rootId = (string)($body['root_id'] ?? '');
        $root = $rootId === '' ? null : ($this->servers[$server]['posts'][$rootId] ?? null);
        if ($rootId !== '' && ($root === null || $root['deleted'])) {
            return $this->error(400, self::ROOT_DELETED);
        }
        $pending = (string)($body['pending_post_id'] ?? '');
        if ($pending !== '' && isset($this->pending[$pending]) && $this->clock <= $this->pending[$pending]['until']) {
            return $this->answer(201, (string)json_encode($this->servers[$server]['posts'][$this->pending[$pending]['post']]));
        }
        $id = sprintf('post%022d', ++$this->ids);
        $props = $body['props'] ?? [];
        $this->servers[$server]['posts'][$id] = [
            'id' => $id,
            'channel_id' => $channel['id'],
            'root_id' => $rootId,
            'user_id' => $userId,
            'message' => (string)($body['message'] ?? ''),
            'props' => is_array($props) ? $props : [],
            'deleted' => false,
            'edits' => 0,
        ];
        if ($pending !== '') {
            $this->pending[$pending] = ['post' => $id, 'until' => $this->clock + self::PENDING_SECONDS];
        }

        return $this->answer(201, (string)json_encode($this->servers[$server]['posts'][$id]));
    }

    /**
     * Post $postId as GET /api/v4/posts/{id} answers it.
     */
    private function postAnswer(string $server, string $postId, string $userId): OutboundHttpResponse
    {
        $post = $this->servers[$server]['posts'][$postId] ?? null;
        if ($post === null || $post['deleted']) {
            return $this->error(404, self::POST_NOT_FOUND);
        }
        $channel = $this->servers[$server]['channels'][$post['channel_id']] ?? null;
        if ($channel !== null && $channel['private'] && !in_array($userId, $channel['members'], true)) {
            return $this->error(403, self::PERMISSION_ERROR);
        }
        $answer = $post;
        unset($answer['deleted'], $answer['edits']);

        return $this->answer(200, (string)json_encode($answer + ['delete_at' => 0, 'type' => '']));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patchPost(string $server, string $postId, string $userId, array $body): OutboundHttpResponse
    {
        $post = $this->servers[$server]['posts'][$postId] ?? null;
        $channel = $post === null ? null : ($this->servers[$server]['channels'][$post['channel_id']] ?? null);
        if ($post === null || $post['deleted'] || $post['user_id'] !== $userId || !in_array($userId, (array)($channel['members'] ?? []), true) || !$this->ownEditsAllowed) {
            return $this->error(403, self::PERMISSION_ERROR);
        }
        $post['message'] = (string)($body['message'] ?? $post['message']);
        $props = $body['props'] ?? $post['props'];
        $post['props'] = is_array($props) ? $props : [];
        $post['edits']++;
        $this->servers[$server]['posts'][$postId] = $post;

        return $this->answer(200, (string)json_encode($post));
    }

    private function error(int $status, string $errorId): OutboundHttpResponse
    {
        return $this->answer($status, (string)json_encode([
            'id' => $errorId,
            'message' => 'Mattermost said: ' . self::BODY_MARKER,
            'detailed_error' => '',
            'request_id' => $this->requestId(),
            'status_code' => $status,
        ]));
    }

    /**
     * The answer of $status: returned for 2xx, thrown as the outbound HTTP
     * client throws it otherwise.
     *
     * @param array<string, string> $headers
     */
    private function answer(int $status, string $body, array $headers = []): OutboundHttpResponse
    {
        $response = new OutboundHttpResponse($status, $headers + ['content-type' => 'application/json', 'x-request-id' => $this->requestId()], $body);
        if ($status < 200 || $status > 299) {
            throw new OutboundHttpException(OutboundHttpException::KIND_STATUS, sprintf(OutboundHttpException::HTTP_STATUS, $status), $status, $response);
        }

        return $response;
    }

    private function requestId(): string
    {
        return sprintf('req%023d', count($this->requests));
    }

    /**
     * The server $url goes to: the address of a server it holds that $url
     * starts with, else $url's scheme and host.
     */
    private function serverOf(string $url): string
    {
        foreach (array_keys($this->servers) as $server) {
            if (str_starts_with($url, $server . '/')) {
                return $server;
            }
        }

        return parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
    }

    /**
     * The bearer token of $headers; '' without one.
     *
     * @param list<string> $headers
     */
    private static function tokenOf(array $headers): string
    {
        foreach ($headers as $header) {
            if (str_starts_with($header, 'Authorization: Bearer ')) {
                return substr($header, strlen('Authorization: Bearer '));
            }
        }

        return '';
    }
}
