<?php

declare(strict_types=1);

namespace app\services\notification;

/**
 * The rules of a channel's config beyond what its definition says
 * (ChannelDefinition: its required keys and the schemes of its URLs), such
 * as Mattermost's channel and mention (MattermostConfig) or the webhook's
 * payload and signing secret (WebhookConfig): a channel definition names
 * the class (configRules), and a template's rules ask it when the template
 * is saved.
 */
interface ChannelConfigRules
{
    /**
     * What is wrong with $config, the channel config of a template, its
     * secrets included; [] for nothing. Each message names the key.
     *
     * @param array<int|string, mixed> $config
     * @return list<string>
     */
    public static function refusals(#[\SensitiveParameter] array $config): array;
}
