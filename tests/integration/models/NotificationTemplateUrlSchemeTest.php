<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\components\NotificationConfigUrls;
use app\models\NotificationTemplate;
use app\tests\integration\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The URL of a notification template's channel config is an http or https
 * URL, checked when the template is saved: the slack and teams
 * webhook_url, the webhook url. The channels send only to such a URL
 * (OutboundHttpClient), so a template they would refuse is not stored.
 *
 * Regression: a template stored a URL of any scheme.
 */
class NotificationTemplateUrlSchemeTest extends DbTestCase
{
    /** NotificationTemplate::URL_SCHEME_REFUSED, as the form and the API show it; sprintf(): the key. */
    private const REFUSED = '%s must be an http or https URL.';

    /**
     * Each channel with a URL in its config, and its key.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function urlKeys(): array
    {
        return [
            'slack' => [NotificationTemplate::CHANNEL_SLACK, 'webhook_url'],
            'teams' => [NotificationTemplate::CHANNEL_TEAMS, 'webhook_url'],
            'webhook' => [NotificationTemplate::CHANNEL_WEBHOOK, 'url'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: mixed}>
     */
    public static function refusedUrls(): array
    {
        $urls = [
            'file' => 'file:///etc/hostname',
            'gopher' => 'gopher://127.0.0.1:70/_x',
            'ftp' => 'ftp://files.example.com/hook',
            'no scheme' => 'hooks.example.com/services/x',
            'no host' => 'https:///services/x',
            'a list' => ['https://hooks.example.com/services/x'],
            'a number' => 42,
        ];
        $cases = [];
        foreach (self::urlKeys() as $channel => [$name, $key]) {
            foreach ($urls as $label => $url) {
                $cases["{$channel}, {$label}"] = [$name, $key, $url];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function acceptedUrls(): array
    {
        $urls = [
            'https' => 'https://hooks.example.com/services/T0/B0/x',
            'http with a port' => 'http://mattermost.internal:8065/hooks/x',
            'the scheme in upper case' => 'HTTPS://hooks.example.com/x',
        ];
        $cases = [];
        foreach (self::urlKeys() as $channel => [$name, $key]) {
            foreach ($urls as $label => $url) {
                $cases["{$channel}, {$label}"] = [$name, $key, $url];
            }
        }

        return $cases;
    }

    #[DataProvider('refusedUrls')]
    public function testRegressionAUrlOfAnotherSchemeIsRefusedOnSave(string $channel, string $key, mixed $url): void
    {
        $template = $this->newTemplate($channel, [$key => $url]);

        $this->assertFalse($template->save());

        $this->assertSame([sprintf(self::REFUSED, $key)], $template->getErrors('config'));
        $this->assertSame(sprintf(self::REFUSED, $key), sprintf(NotificationTemplate::URL_SCHEME_REFUSED, $key));
        $this->assertNull(NotificationTemplate::findOne(['name' => $template->name]), 'nothing was stored');
    }

    #[DataProvider('acceptedUrls')]
    public function testHttpAndHttpsUrlsAreAccepted(string $channel, string $key, string $url): void
    {
        $template = $this->newTemplate($channel, [$key => $url]);

        $this->assertTrue($template->save(), (string)json_encode($template->getErrors()));

        $this->assertSame([$key => $url], NotificationTemplate::findOne($template->id)?->getParsedConfig());
    }

    /**
     * A template stored before the check, with another scheme, saves again
     * only with an http or https URL: kept as $encrypted$, its stored URL is
     * checked as well.
     */
    public function testAStoredUrlOfAnotherSchemeMustBeFixedBeforeTheNextSave(): void
    {
        $user = $this->createUser('nt-scheme-stored');
        $stored = $this->createNotificationTemplate((int)$user->id, NotificationTemplate::CHANNEL_SLACK, 'job.failed', '{"webhook_url":"file:///etc/hostname"}');
        $template = NotificationTemplate::findOne($stored->id);
        $this->assertInstanceOf(NotificationTemplate::class, $template);
        $template->name = $stored->name . '-renamed';
        $template->config = '{"webhook_url":"$encrypted$"}';

        $this->assertFalse($template->save());
        $this->assertSame([sprintf(self::REFUSED, 'webhook_url')], $template->getErrors('config'));

        $template->config = '{"webhook_url":"https://hooks.example.com/services/fixed"}';
        $this->assertTrue($template->save(), (string)json_encode($template->getErrors()));
    }

    /**
     * The rule of every URL key (NotificationConfigUrls): a key that allows
     * https only (Mattermost's server_url) refuses http with its own
     * message; a key the config does not hold, or holds empty, is left to
     * the required check.
     */
    public function testAKeyThatAllowsHttpsOnlyRefusesHttp(): void
    {
        $httpsOnly = ['server_url' => ['https']];

        $this->assertSame(
            ['server_url must be an https URL.'],
            NotificationConfigUrls::refusals(['server_url' => 'http://mattermost.example.com'], $httpsOnly)
        );
        $this->assertSame('%s must be an https URL.', NotificationTemplate::HTTPS_REQUIRED);
        $this->assertSame([], NotificationConfigUrls::refusals(['server_url' => 'https://mattermost.example.com'], $httpsOnly));
        $this->assertSame([], NotificationConfigUrls::refusals([], $httpsOnly));
        $this->assertSame([], NotificationConfigUrls::refusals(['server_url' => ''], $httpsOnly));
        $this->assertSame([], NotificationConfigUrls::refusals(['server_url' => null], $httpsOnly));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function newTemplate(string $channel, array $config): NotificationTemplate
    {
        $user = $this->createUser('nt-scheme');
        $template = new NotificationTemplate();
        $template->name = 'nt-scheme-' . uniqid('', true);
        $template->channel = $channel;
        $template->config = (string)json_encode($config, JSON_UNESCAPED_SLASHES);
        $template->events = 'job.failed';
        $template->subject_template = 'Subject';
        $template->body_template = 'Body';
        $template->created_by = (int)$user->id;
        $template->delivers_as = (int)$user->id;

        return $template;
    }
}
