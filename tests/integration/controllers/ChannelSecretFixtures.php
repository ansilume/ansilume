<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\NotificationTemplate;

/**
 * Channel configs of notification templates that hold secrets
 * (NotificationTemplate::SECRET_CONFIG_KEYS and SECRET_CONFIG_MAPS), for the
 * tests of their redaction. Test fixtures, not secrets: each value is
 * distinct, so a test can tell which one reached a page or an answer.
 */
final class ChannelSecretFixtures
{
    public const BOT_TOKEN = '123456789:AAE-w5b-secrets-bot-token-7731';
    public const NEW_BOT_TOKEN = '987654321:AAE-w5b-rotated-bot-token-0042';
    public const CHAT_ID = '-100200300';
    public const SLACK_URL = 'https://hooks.slack.com/services/T000/B000/w5bSlackSecret4410';
    public const TEAMS_URL = 'https://example.webhook.office.com/webhookb2/w5b-teams-secret-9921';
    public const WEBHOOK_URL = 'https://hooks.example.com/notify?token=w5b-webhook-url-secret-5562';
    public const HEADER_TOKEN = 'Bearer w5b-header-secret-3317';
    public const HEADER_ENV = 'prod-w5b-header-2208';
    public const ROUTING_KEY = 'R0UT1NG-w5b-secret-8890';

    public const TELEGRAM = ['bot_token' => self::BOT_TOKEN, 'chat_id' => self::CHAT_ID];
    public const SLACK = ['webhook_url' => self::SLACK_URL];
    public const TEAMS = ['webhook_url' => self::TEAMS_URL];
    public const WEBHOOK = ['url' => self::WEBHOOK_URL, 'headers' => ['Authorization' => self::HEADER_TOKEN, 'X-Env' => self::HEADER_ENV]];
    public const PAGERDUTY = ['routing_key' => self::ROUTING_KEY];

    /** SurveySecrets::PLACEHOLDER, which the page, the form and the API show for a secret. */
    public const SHOWN = '$encrypted$';

    /**
     * Every channel with a secret: its config, the secrets in it, and the
     * config as the page, the form and the API show it.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: list<string>, 3: array<string, mixed>}>
     */
    public static function channels(): array
    {
        return [
            'telegram' => [NotificationTemplate::CHANNEL_TELEGRAM, self::TELEGRAM, [self::BOT_TOKEN], ['bot_token' => self::SHOWN, 'chat_id' => self::CHAT_ID]],
            'slack' => [NotificationTemplate::CHANNEL_SLACK, self::SLACK, [self::SLACK_URL], ['webhook_url' => self::SHOWN]],
            'teams' => [NotificationTemplate::CHANNEL_TEAMS, self::TEAMS, [self::TEAMS_URL], ['webhook_url' => self::SHOWN]],
            'webhook' => [
                NotificationTemplate::CHANNEL_WEBHOOK,
                self::WEBHOOK,
                [self::WEBHOOK_URL, self::HEADER_TOKEN, self::HEADER_ENV],
                ['url' => self::SHOWN, 'headers' => ['Authorization' => self::SHOWN, 'X-Env' => self::SHOWN]],
            ],
            'pagerduty' => [NotificationTemplate::CHANNEL_PAGERDUTY, self::PAGERDUTY, [self::ROUTING_KEY], ['routing_key' => self::SHOWN]],
        ];
    }

    /**
     * Every secret of channels(), each also as JSON writes it (a slash may
     * be escaped), for searching a page or an answer.
     *
     * @return list<string>
     */
    public static function allSecrets(): array
    {
        $secrets = [];
        foreach (self::channels() as [, , $values, ]) {
            foreach ($values as $value) {
                $secrets[] = $value;
                $secrets[] = str_replace('/', '\/', $value);
            }
        }

        return array_values(array_unique($secrets));
    }
}
