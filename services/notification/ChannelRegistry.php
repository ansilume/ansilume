<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use yii\base\InvalidConfigException;

/**
 * The channels of notification templates (spec section 6; docs/notifications.md):
 * the built-in ones (BUILT_IN) and those of the installation's own, which
 * config/notification-channels.php returns (CONFIG_FILE, the channelsFile
 * of the component notificationDispatcher in config/services.php), each
 * defined by the same settings (ChannelDefinition). The dispatcher reads
 * the file (configuredIn()) and builds the definitions when it starts
 * (build()), which the web and the console application do at their start,
 * and makes a handler of each; the template's rules, its secrets and its
 * identity read them from it (installed(), definitionOf()). There is no
 * channel in the database: a channel of one's own is code, which only the
 * installation's image or checkout holds.
 */
final class ChannelRegistry
{
    /** The file of the channels of the installation's own, from the application's root. */
    public const CONFIG_FILE = 'config/notification-channels.php';

    /** A channel of one's own with the name of a built-in one; sprintf(): the name. */
    public const NAME_TAKEN = 'Notification channel "%s" is built in: give your channel another name.';

    /** A CONFIG_FILE that returns no array; sprintf(): its path. */
    public const FILE_NOT_AN_ARRAY = '%s must return an array of notification channels, each name => its definition.';

    /** The built-in channels, in the order of the form, each as a ChannelDefinition takes it. */
    public const BUILT_IN = [
        NotificationTemplate::CHANNEL_EMAIL => [
            'label' => 'Email',
            'class' => EmailChannel::class,
            'requiredKeys' => ['emails'],
            'destinationKeys' => ['emails'],
        ],
        NotificationTemplate::CHANNEL_SLACK => [
            'label' => 'Slack',
            'class' => SlackChannel::class,
            'requiredKeys' => ['webhook_url'],
            'secretKeys' => ['webhook_url'],
            'urlSchemes' => ['webhook_url' => ['http', 'https']],
            'destinationKeys' => ['webhook_url'],
        ],
        NotificationTemplate::CHANNEL_TEAMS => [
            'label' => 'Microsoft Teams',
            'class' => TeamsChannel::class,
            'requiredKeys' => ['webhook_url'],
            'secretKeys' => ['webhook_url'],
            'urlSchemes' => ['webhook_url' => ['http', 'https']],
            'destinationKeys' => ['webhook_url'],
        ],
        // The headers carry the destination's authorization, and the signing
        // secret proves a request comes from Ansilume: both go along with the url.
        NotificationTemplate::CHANNEL_WEBHOOK => [
            'label' => 'Webhook',
            'class' => WebhookChannel::class,
            'requiredKeys' => ['url'],
            'secretKeys' => ['url', 'signing_secret'],
            'secretMaps' => ['headers'],
            'boundTo' => ['headers' => 'url', 'signing_secret' => 'url'],
            'urlSchemes' => ['url' => ['http', 'https']],
            'destinationKeys' => ['url', 'headers'],
            'configRules' => WebhookConfig::class,
        ],
        // Telegram's bot token goes to Telegram's fixed address: no key binds it.
        NotificationTemplate::CHANNEL_TELEGRAM => [
            'label' => 'Telegram',
            'class' => TelegramChannel::class,
            'requiredKeys' => ['bot_token', 'chat_id'],
            'secretKeys' => ['bot_token'],
            'destinationKeys' => ['bot_token', 'chat_id'],
        ],
        NotificationTemplate::CHANNEL_PAGERDUTY => [
            'label' => 'PagerDuty',
            'class' => PagerDutyChannel::class,
            'requiredKeys' => ['routing_key'],
            'secretKeys' => ['routing_key'],
            'destinationKeys' => ['routing_key'],
        ],
        // The bot token is a full session of its bot: it goes along with server_url, https only.
        NotificationTemplate::CHANNEL_MATTERMOST => [
            'label' => 'Mattermost',
            'class' => MattermostChannel::class,
            'requiredKeys' => MattermostConfig::REQUIRED_KEYS,
            'secretKeys' => ['bot_token'],
            'boundTo' => ['bot_token' => 'server_url'],
            'urlSchemes' => MattermostConfig::URL_KEYS,
            'destinationKeys' => MattermostConfig::DESTINATION_KEYS,
            'configRules' => MattermostConfig::class,
        ],
    ];

    /**
     * The definitions of the built-in channels, then of $configured (name =>
     * its settings), by name.
     *
     * @param array<int|string, mixed> $configured
     * @return array<string, ChannelDefinition>
     * @throws InvalidConfigException for a definition it cannot take, or a channel of one's own with a built-in name
     */
    public static function build(array $configured): array
    {
        $definitions = [];
        foreach (self::BUILT_IN as $name => $settings) {
            $definitions[$name] = ChannelDefinition::of($name, $settings);
        }
        foreach ($configured as $name => $settings) {
            if (array_key_exists($name, self::BUILT_IN)) {
                throw new InvalidConfigException(sprintf(self::NAME_TAKEN, $name));
            }
            $definition = ChannelDefinition::of($name, $settings);
            $definitions[$definition->name] = $definition;
        }

        return $definitions;
    }

    /**
     * The channels of one's own that $file returns, when it exists; none
     * without it.
     *
     * @return array<int|string, mixed>
     * @throws InvalidConfigException when it returns no array
     */
    public static function configuredIn(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $channels = require $file;
        if (!is_array($channels)) {
            throw new InvalidConfigException(sprintf(self::FILE_NOT_AN_ARRAY, $file));
        }

        return $channels;
    }

    /**
     * The channels of this installation, by name: those of the component
     * notificationDispatcher (NotificationDispatcher::definitions()).
     *
     * @return array<string, ChannelDefinition>
     */
    public static function installed(): array
    {
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = \Yii::$app->get('notificationDispatcher');

        return $dispatcher->definitions();
    }

    /**
     * The definition of channel $name of this installation; null for a
     * channel it does not have, and for no channel.
     */
    public static function definitionOf(?string $name): ?ChannelDefinition
    {
        return $name === null ? null : (self::installed()[$name] ?? null);
    }
}
