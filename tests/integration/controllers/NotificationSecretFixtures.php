<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\NotificationTemplate;

/**
 * Notification templates with channel secrets (ChannelSecretFixtures), and
 * the assertion that a page or an answer carries none of them. Use in
 * DbTestCase subclasses.
 */
trait NotificationSecretFixtures
{
    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: list<string>, 3: array<string, mixed>}>
     */
    public static function channelProvider(): array
    {
        return ChannelSecretFixtures::channels();
    }

    /**
     * A stored notification template of $channel with $config, as the form
     * and the API store it (JSON without escaped slashes).
     *
     * @param array<string, mixed> $config
     */
    protected function secretTemplate(string $channel, array $config): NotificationTemplate
    {
        $creator = $this->createUser('nt-secret-owner');

        return $this->createNotificationTemplate(
            (int)$creator->id,
            $channel,
            'job.failed',
            (string)json_encode($config, JSON_UNESCAPED_SLASHES)
        );
    }

    protected function reload(NotificationTemplate $template): NotificationTemplate
    {
        $stored = NotificationTemplate::findOne($template->id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);

        return $stored;
    }

    /**
     * $haystack holds none of $secrets, also not as JSON escapes a slash.
     * The message counts, never names, what was found.
     *
     * @param list<string> $secrets
     */
    protected function assertNoSecretIn(string $haystack, array $secrets): void
    {
        $found = 0;
        foreach ($secrets as $secret) {
            foreach ([$secret, str_replace('/', '\/', $secret), htmlspecialchars($secret, ENT_QUOTES)] as $form) {
                $found += substr_count($haystack, $form);
            }
        }
        $this->assertSame(0, $found, 'stored secrets found in the page or the answer');
    }
}
