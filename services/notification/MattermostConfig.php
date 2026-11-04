<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\NotificationConfigUrls;
use app\models\NotificationTemplate;

/**
 * The config of a template of the Mattermost channel, as the template's
 * rules check it when it is saved (refusals(), with the required keys and
 * the scheme of server_url that NotificationTemplate checks for every
 * channel) and as the channel reads it when it delivers (of()):
 *
 * - server_url: the address of the Mattermost server, https only
 *   (URL_KEYS), without a user, a query or a fragment, at most 255
 *   characters; a path is kept, for a server below one;
 * - bot_token: the access token of a bot account, a channel secret
 *   (ChannelRegistry::BUILT_IN) that keeps its stored value only while
 *   server_url keeps its own (NotificationConfigSecrets);
 * - the channel: channel_id, or team and channel, the names in the
 *   channel's address; a channel ID survives a rename, names do not;
 * - mention, optional: the one user or group mention that the replies
 *   which need attention start with; never @all, @channel or @here, which
 *   notify everyone in the channel.
 *
 * Saving checks the form of these only and never asks Mattermost, so that
 * an outage of Mattermost never blocks an edit: the channel checks the token
 * and the channel on its first delivery after a change, and Send Test every
 * time (MattermostDestination).
 */
final class MattermostConfig implements ChannelConfigRules
{
    /** A required key that is missing or empty; sprintf(): the key. */
    public const REQUIRED = '%s is required for Mattermost.';

    /** server_url with a user, a query or a fragment, or too long. */
    public const SERVER_URL_FORM = 'server_url must be the address of the Mattermost server, without a user, a query or a '
        . 'fragment, at most 255 characters.';

    /** bot_token that no header can carry. */
    public const BOT_TOKEN_FORM = 'bot_token must be the access token of the bot, without spaces, at most 255 characters.';

    /** Neither channel_id nor team and channel. */
    public const CHANNEL_MISSING = 'Mattermost needs channel_id, or team and channel.';

    /** Both channel_id and a name. */
    public const CHANNEL_TWICE = 'Give channel_id, or team and channel, not both.';

    /** team without channel, or channel without team. */
    public const TEAM_AND_CHANNEL = 'team and channel go together: give both, or channel_id instead.';

    /** A channel_id of another form. */
    public const CHANNEL_ID_FORM = 'channel_id must be the ID of a Mattermost channel: 26 lower-case letters and digits.';

    /** A team or channel name of another form; sprintf(): the key. */
    public const NAME_FORM = '%s must be the name in the address of Mattermost, such as town-square: lower-case letters, '
        . 'digits, - and _, at most 64.';

    /** A mention of another form. */
    public const MENTION_FORM = 'mention must be one user or group mention, such as @ops-team.';

    /** A mention of everyone in the channel. */
    public const MENTION_EVERYONE = 'mention may not be @all, @channel or @here, which notify everyone in the channel.';

    /** The keys of the config that decide where the channel delivers: what fingerprint() covers. */
    public const DESTINATION_KEYS = ['server_url', 'bot_token', 'channel_id', 'team', 'channel'];

    /** The keys a config must hold a value at. */
    public const REQUIRED_KEYS = ['server_url', 'bot_token'];

    /** The URL of the config, with the scheme it may use: the bot token is a full session, so https only. */
    public const URL_KEYS = ['server_url' => ['https']];

    /** The longest server_url. */
    private const SERVER_URL_LENGTH = 255;

    private const TOKEN = '/^[\x21-\x7e]{1,255}$/';

    private const CHANNEL_ID = '/^[a-z0-9]{26}$/';

    private const NAME = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    private const MENTION = '/^@[a-z0-9][a-z0-9._-]{0,63}$/i';

    /** The mentions of everyone in a channel, in lower case. */
    private const EVERYONE = ['@all', '@channel', '@here'];

    private function __construct(
        public readonly string $serverUrl,
        #[\SensitiveParameter] public readonly string $token,
        public readonly ?string $channelId,
        public readonly ?string $team,
        public readonly ?string $channel,
        public readonly ?string $mention
    ) {
    }

    /**
     * The errors of the Mattermost keys of $config (see the class), other
     * than a missing server_url or bot_token and the scheme of server_url,
     * which the template's rules check for every channel; [] for a config
     * without them.
     *
     * @param array<int|string, mixed> $config the channel config, its secrets included
     * @return list<string>
     */
    public static function refusals(#[\SensitiveParameter] array $config): array
    {
        $errors = [];
        $server = $config['server_url'] ?? null;
        if (is_string($server) && $server !== '' && !self::isServerUrl($server)) {
            $errors[] = self::SERVER_URL_FORM;
        }
        $token = $config['bot_token'] ?? null;
        if (self::isSet($token) && (!is_string($token) || preg_match(self::TOKEN, $token) !== 1)) {
            $errors[] = self::BOT_TOKEN_FORM;
        }

        return array_merge($errors, self::channelRefusals($config), self::mentionRefusals($config['mention'] ?? null));
    }

    /**
     * The config $template's channel delivers with: refused, before any
     * request, with what is wrong with it (CONFIG_UNUSABLE, KIND_REQUEST),
     * when its rules would refuse it.
     *
     * @throws MattermostApiException
     */
    public static function of(NotificationTemplate $template): self
    {
        $config = $template->getParsedConfig();
        $errors = [];
        foreach (self::REQUIRED_KEYS as $key) {
            if (!self::isSet($config[$key] ?? null)) {
                $errors[] = sprintf(self::REQUIRED, $key);
            }
        }
        $errors = array_merge($errors, NotificationConfigUrls::refusals($config, self::URL_KEYS), self::refusals($config));
        if ($errors !== []) {
            throw MattermostApiException::unusable(sprintf(MattermostApiException::CONFIG_UNUSABLE, $errors[0]));
        }

        return new self(
            rtrim((string)self::textOf($config['server_url']), '/'),
            (string)self::textOf($config['bot_token']),
            self::textOf($config['channel_id'] ?? null),
            self::textOf($config['team'] ?? null),
            self::textOf($config['channel'] ?? null),
            self::textOf($config['mention'] ?? null)
        );
    }

    /**
     * A hash of what decides where the channel delivers: the server, the
     * token and the channel. It changes with any of them, and with none of
     * the others; the token cannot be read from it.
     */
    public function fingerprint(): string
    {
        return hash('sha256', (string)json_encode([$this->serverUrl, $this->token, $this->channelId, $this->team, $this->channel]));
    }

    /**
     * How a message names the channel: its ID, or team/channel.
     */
    public function channelLabel(): string
    {
        return $this->channelId ?? $this->team . '/' . $this->channel;
    }

    /**
     * Whether $url, whose scheme the rules check, has no user, query or
     * fragment and is not too long.
     */
    private static function isServerUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strlen($url) <= self::SERVER_URL_LENGTH
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }

    /**
     * @param array<int|string, mixed> $config
     * @return list<string>
     */
    private static function channelRefusals(#[\SensitiveParameter] array $config): array
    {
        $id = $config['channel_id'] ?? null;
        $team = $config['team'] ?? null;
        $channel = $config['channel'] ?? null;

        return self::isSet($id)
            ? self::idRefusals($id, self::isSet($team) || self::isSet($channel))
            : self::namesRefusals($team, $channel);
    }

    /**
     * The errors of a channel given by its ID $id, and also by names when
     * $withNames.
     *
     * @return list<string>
     */
    private static function idRefusals(mixed $id, bool $withNames): array
    {
        if ($withNames) {
            return [self::CHANNEL_TWICE];
        }

        return is_string($id) && preg_match(self::CHANNEL_ID, $id) === 1 ? [] : [self::CHANNEL_ID_FORM];
    }

    /**
     * The errors of a channel given by $team and $channel, its team's and
     * its own name.
     *
     * @return list<string>
     */
    private static function namesRefusals(mixed $team, mixed $channel): array
    {
        if (!self::isSet($team) && !self::isSet($channel)) {
            return [self::CHANNEL_MISSING];
        }
        if (!self::isSet($team) || !self::isSet($channel)) {
            return [self::TEAM_AND_CHANNEL];
        }

        return array_merge(self::nameRefusals('team', $team), self::nameRefusals('channel', $channel));
    }

    /**
     * @return list<string>
     */
    private static function nameRefusals(string $key, mixed $name): array
    {
        return is_string($name) && preg_match(self::NAME, $name) === 1 ? [] : [sprintf(self::NAME_FORM, $key)];
    }

    /**
     * @return list<string>
     */
    private static function mentionRefusals(mixed $mention): array
    {
        if (!self::isSet($mention)) {
            return [];
        }
        if (!is_string($mention) || preg_match(self::MENTION, $mention) !== 1) {
            return [self::MENTION_FORM];
        }

        return in_array(strtolower($mention), self::EVERYONE, true) ? [self::MENTION_EVERYONE] : [];
    }

    /** Whether a config holds $value: anything but null and ''. */
    private static function isSet(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /** A value the rules took: the text, null for none. */
    private static function textOf(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
