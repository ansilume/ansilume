<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\AppLinks;
use app\models\NotificationTemplate;
use app\models\NotificationThread;

/**
 * Where a Mattermost template delivers, checked: its bot, its channel's ID,
 * and the API of its server (MattermostApi) that one delivery or one Send
 * Test sends through.
 *
 * The check (of()) asks Mattermost three things:
 * 1. GET /users/me: the token must be a bot's; a personal token carries a
 *    human's rights and is refused (PERSONAL_TOKEN);
 * 2. the channel, by its ID or by its team's and its own name, resolved to
 *    its ID: it must exist for the bot (CHANNEL_NOT_FOUND) and not be
 *    archived (ARCHIVED);
 * 3. GET /channels/{id}/members/me: the bot must be a member, or it could
 *    not edit its status posts (NOT_A_MEMBER).
 * A check that passed is kept with the template's server state
 * (channel_state, which no form or request writes; never in its config):
 * the fingerprint of the config it checked (MattermostConfig::fingerprint(),
 * which cannot give the token back), the channel's ID, the bot's ID and
 * username, and when. A delivery checks only when the config it delivers
 * with is not the one checked: the first delivery after the token or the
 * channel changed (spec answer 4). Send Test checks every time. A check
 * that failed is not kept, so the next delivery checks again.
 */
final class MattermostDestination
{
    /** The keys of the server state that a passed check keeps. */
    public const STATE_KEYS = ['fingerprint', 'channel_id', 'bot_user_id', 'bot_username', 'checked_at'];

    /** A pending post ID: the bot, the installation, the delivery, what is created. sprintf(): in that order. */
    public const PENDING_POST_ID = '%s:%s-%d-%s';

    private const ID = '/^[a-z0-9]{26}$/';

    private function __construct(
        public readonly MattermostApi $api,
        public readonly MattermostConfig $config,
        public readonly string $channelId,
        public readonly string $botUserId,
        private bool $membershipChecked
    ) {
    }

    /**
     * The destination of $template's $config, with $api: checked now when
     * $check, or when the check kept for the template is not of this
     * config; else as the kept check says, without a request.
     *
     * @throws MattermostApiException when the check fails (see the class)
     */
    public static function of(NotificationTemplate $template, MattermostApi $api, MattermostConfig $config, bool $check): self
    {
        $kept = self::keptCheckOf($template);
        if (!$check && $kept !== null && hash_equals($kept['fingerprint'], $config->fingerprint())) {
            return new self($api, $config, $kept['channel_id'], $kept['bot_user_id'], false);
        }
        $bot = $api->me();
        if (!$bot['is_bot']) {
            throw MattermostApiException::refused(MattermostApiException::PERSONAL_TOKEN);
        }
        $channel = $config->channelId !== null
            ? $api->channel($config->channelId)
            : $api->channelByName((string)$config->team, (string)$config->channel);
        if ($channel['delete_at'] > 0) {
            throw MattermostApiException::refused(sprintf(MattermostApiException::ARCHIVED, $config->channelLabel()));
        }
        $api->membership($channel['id']);
        self::keep($template, [
            'fingerprint' => $config->fingerprint(),
            'channel_id' => $channel['id'],
            'bot_user_id' => $bot['id'],
            'bot_username' => $bot['username'],
            'checked_at' => time(),
        ]);

        return new self($api, $config, $channel['id'], $bot['id'], true);
    }

    /**
     * Whether $thread's post lives where this destination posts: the same
     * server and channel. A post elsewhere is never edited: its server
     * would get this template's token.
     */
    public function holds(NotificationThread $thread): bool
    {
        return $thread->server === $this->config->serverUrl && $thread->channel_id === $this->channelId;
    }

    /**
     * Refuses (NOT_A_MEMBER) unless the bot is a member of the channel;
     * asks Mattermost only when this delivery has not checked it yet.
     */
    public function requireMembership(): void
    {
        if (!$this->membershipChecked) {
            $this->api->membership($this->channelId);
            $this->membershipChecked = true;
        }
    }

    /**
     * Whether Mattermost still has post $postId in the channel as this
     * bot's (MattermostApi::getPost()): false for a post it no longer has
     * (deleted, or moved to another channel) and for another user's, such
     * as the post of the bot of a token before. An error of the lookup is
     * thrown.
     */
    public function isOwnPost(string $postId): bool
    {
        $post = $this->api->getPost($postId);

        return $post !== null && $post['user_id'] === $this->botUserId && $post['channel_id'] === $this->channelId;
    }

    /**
     * Creates a post in the channel (a reply in the thread of $rootId), as
     * $role of delivery $deliveryId (0: Send Test, without a pending post
     * ID), and returns its ID.
     *
     * @param array<string, mixed> $props
     */
    public function post(string $message, array $props, ?string $rootId, int $deliveryId, string $role): string
    {
        return $this->api->createPost($this->channelId, $message, $props, $rootId, $this->pendingPostId($deliveryId, $role));
    }

    /**
     * The pending post ID of what delivery $deliveryId creates as $role
     * (message, status, root, reply): the same on every attempt of the
     * delivery, so that a create sent again within the 30 seconds that
     * Mattermost keeps it after it made the post gets that post (the API
     * sends a create whose answer was lost once more at once; a retry after
     * the backoff comes too late for it); of this bot and this installation
     * (a tag of its address), so that no other one's create is taken for
     * it; '' for Send Test.
     */
    private function pendingPostId(int $deliveryId, string $role): string
    {
        return $deliveryId > 0 ? sprintf(self::PENDING_POST_ID, $this->botUserId, hash('crc32b', AppLinks::base()), $deliveryId, $role) : '';
    }

    /**
     * The check kept for $template: its fingerprint, the channel's ID and
     * the bot's ID; null for none, or one that is not of that form.
     *
     * @return array{fingerprint: string, channel_id: string, bot_user_id: string}|null
     */
    private static function keptCheckOf(NotificationTemplate $template): ?array
    {
        $state = json_decode((string)$template->channel_state, true);
        $fingerprint = is_array($state) ? ($state['fingerprint'] ?? null) : null;
        $channelId = is_array($state) ? ($state['channel_id'] ?? null) : null;
        $botUserId = is_array($state) ? ($state['bot_user_id'] ?? null) : null;
        if (!is_string($fingerprint) || !is_string($channelId) || !is_string($botUserId)) {
            return null;
        }
        if (preg_match(self::ID, $channelId) !== 1 || preg_match(self::ID, $botUserId) !== 1) {
            return null;
        }

        return ['fingerprint' => $fingerprint, 'channel_id' => $channelId, 'bot_user_id' => $botUserId];
    }

    /**
     * Keeps $state as $template's server state: written to its row alone,
     * so that no save of the template's other attributes is touched, and
     * set on $template as stored.
     *
     * @param array<string, int|string> $state
     */
    private static function keep(NotificationTemplate $template, array $state): void
    {
        $json = (string)json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        NotificationTemplate::updateAll(['channel_state' => $json], ['id' => (int)$template->id]);
        $template->setAttribute('channel_state', $json);
        $template->setOldAttribute('channel_state', $json);
    }
}
