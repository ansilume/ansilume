<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\NotificationConfigSecrets;
use app\components\OutboundHttpClient;
use app\components\OutboundHttpResponse;
use app\models\NotificationTemplate;
use app\services\NotificationDispatcher;
use app\services\notification\ChannelDefinition;
use app\services\notification\ChannelRegistry;
use app\services\notification\PagerDutyChannel;
use app\services\notification\SelectiveChannelInterface;
use app\tests\integration\RestoresComponents;
use PHPUnit\Framework\TestCase;

/**
 * "Channels of your own" of docs/notifications.md says what the code does:
 * its example class and definition work as written (a definition the
 * registry takes, a template the rules check, a message sent through the
 * outbound HTTP client), its table names every setting of a definition,
 * and the messages it quotes are the code's.
 */
class ChannelsOfYourOwnDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;
    use RestoresComponents;

    private const DOC = 'docs/notifications.md';

    private const SECTION = '## Channels of your own';

    /** The class of the doc's example. */
    private const EXAMPLE_CLASS = 'app\custom\AcmePagerChannel';

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * The example class, with the example definition: the dispatcher takes
     * it beside the built-in channels, a template of it with an https
     * endpoint and an API key is valid and keeps its key secret, and its
     * message goes to the endpoint through the outbound HTTP client, with
     * the key as the bearer token.
     */
    public function testTheExampleWorksAsWritten(): void
    {
        $this->loadExampleClass();
        $channels = $this->exampleDefinition();
        $http = new class () extends OutboundHttpClient {
            /** @var list<array{method: string, url: string, headers: list<string>, body: string|null}> */
            public array $requests = [];

            public function request(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] array $headers = [], #[\SensitiveParameter] ?string $body = null): OutboundHttpResponse
            {
                $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => array_values($headers), 'body' => $body];

                return new OutboundHttpResponse(202, [], '');
            }
        };
        $this->swapComponent('outboundHttp', $http);
        $this->swapComponent('notificationDispatcher', ['class' => NotificationDispatcher::class, 'channels' => $channels]);

        $this->assertSame(['acme'], array_keys($channels));
        $this->assertSame('Acme Pager', NotificationTemplate::channelLabel('acme'));
        $template = new NotificationTemplate();
        $template->name = 'docs-acme';
        $template->channel = 'acme';
        $template->events = 'job.failed';
        $template->config = (string)json_encode(['endpoint' => 'https://pager.example.com/alerts', 'api_key' => 'docs-api-key-1']);
        $this->assertTrue($template->validate(), (string)json_encode($template->getErrors()));
        $this->assertSame('$encrypted$', $template->getRedactedConfig()['api_key'] ?? null);

        $dispatcher = ChannelRegistry::installed();
        $this->assertSame(self::EXAMPLE_CLASS, $dispatcher['acme']->class);
        /** @var NotificationDispatcher $component */
        $component = \Yii::$app->get('notificationDispatcher');
        $channel = $component->channel('acme');
        $this->assertNotNull($channel);
        $channel->send($template, 'Job #7 failed', 'Template: deploy', []);

        $this->assertSame([[
            'method' => 'POST',
            'url' => 'https://pager.example.com/alerts',
            'headers' => ['Content-Type: application/json', 'Authorization: Bearer docs-api-key-1'],
            'body' => '{"title":"Job #7 failed","text":"Template: deploy"}',
        ]], $http->requests);
    }

    /**
     * Review finding: the section told an installation the quickstart made
     * from source to put the class into its checkout, which the containers
     * of docker-compose.prebuilt.yml do not see.
     */
    public function testTheSectionSaysWhichInstallationsRunTheCheckout(): void
    {
        $section = $this->flat('docs/notifications.md', '### Adding it to your installation');
        $this->assertMentions('In a source installation started from `docker-compose.yml`, or one the deploy role builds from source (without `ansilume_use_prebuilt_images`), the containers run the code of the checkout', $section, 'Adding it to your installation');
        $this->assertMentions('an installation the quickstart made from source included (it runs the images of `docker-compose.prebuilt.yml`, which do not see the checkout)', $section, 'Adding it to your installation');
    }

    /**
     * The table names every setting of a definition, in the order of
     * ChannelDefinition::SETTINGS.
     */
    public function testTheTableNamesEverySetting(): void
    {
        $settings = [];
        foreach ($this->units(self::DOC, self::SECTION) as $unit) {
            if (preg_match('/^\| `([A-Za-z]+)` \|/', $unit, $match) === 1) {
                $settings[] = $match[1];
            }
        }

        $this->assertSame(ChannelDefinition::SETTINGS, $settings);
    }

    /**
     * The messages the section quotes are the code's.
     */
    public function testTheQuotedMessagesAreTheCodes(): void
    {
        $section = $this->flat(self::DOC, self::SECTION);

        foreach ([
            sprintf(ChannelDefinition::REQUIRED, 'api_key', 'Acme Pager'),
            sprintf(NotificationConfigSecrets::URL_CHANGED, 'api_key', 'endpoint'),
            sprintf(NotificationTemplate::HTTPS_REQUIRED, 'endpoint'),
            sprintf(ChannelRegistry::NAME_TAKEN, 'webhook'),
            sprintf(ChannelDefinition::UNKNOWN_CLASS, 'acme', self::EXAMPLE_CLASS),
            ChannelRegistry::CONFIG_FILE,
        ] as $message) {
            $this->assertMentions($message, $section, self::SECTION . ' of ' . self::DOC);
        }
    }

    /**
     * The section names the interface of a channel that sends nothing for
     * some events, and its method, as the code declares them: the
     * dispatcher asks it (EventSubscription), and PagerDuty implements it.
     */
    public function testTheSectionNamesTheInterfaceOfAChannelThatSendsNothingForSomeEvents(): void
    {
        $section = $this->flat(self::DOC, self::SECTION);
        $interface = new \ReflectionClass(SelectiveChannelInterface::class);

        $this->assertMentions('`' . $interface->getShortName() . '`', $section, self::SECTION . ' of ' . self::DOC);
        $this->assertTrue($interface->hasMethod('sendsEvent'));
        $this->assertMentions('`sendsEvent()`', $section, self::SECTION . ' of ' . self::DOC);
        $this->assertTrue((new \ReflectionClass(PagerDutyChannel::class))->implementsInterface(SelectiveChannelInterface::class));
        foreach (PagerDutyChannel::NEVER_TRIGGERED as $event) {
            $this->assertMentions('`' . $event . '`', $section, self::SECTION . ' of ' . self::DOC);
        }
    }

    /**
     * The troubleshooting entry on secrets without a stored value quotes the
     * refusal of every bound secret of the built-in channels, as the code
     * words it.
     */
    public function testTroubleshootingQuotesTheRefusalOfEveryBoundSecret(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', '## Saving a notification template or a webhook: a secret has no stored value');

        foreach (ChannelRegistry::BUILT_IN as $definition) {
            foreach ($definition['boundTo'] ?? [] as $secret => $key) {
                $place = $secret === 'headers' ? 'headers.Authorization' : $secret;
                $this->assertMentions('`' . sprintf(NotificationConfigSecrets::URL_CHANGED, $place, $key) . '`', $entry, 'troubleshooting.md, on secrets that have no stored value');
            }
        }
    }

    /**
     * Declares the example class of the doc, once per process.
     */
    private function loadExampleClass(): void
    {
        if (class_exists(self::EXAMPLE_CLASS, false)) {
            return;
        }
        $code = $this->codeUnder('### The class');
        $this->assertStringContainsString('final class AcmePagerChannel implements ChannelInterface', $code);
        $this->requireCode($code);
        $this->assertTrue(class_exists(self::EXAMPLE_CLASS, false), 'the example declares ' . self::EXAMPLE_CLASS);
    }

    /**
     * What the example config/notification-channels.php returns.
     *
     * @return array<int|string, mixed>
     */
    private function exampleDefinition(): array
    {
        $file = $this->tempFile($this->codeUnder('### The definition'));
        try {
            return ChannelRegistry::configuredIn($file);
        } finally {
            unlink($file);
        }
    }

    /**
     * The first PHP block under $heading in the section.
     */
    private function codeUnder(string $heading): string
    {
        $section = $this->section(self::DOC, self::SECTION);
        $start = strpos($section, "\n" . $heading . "\n");
        $this->assertNotFalse($start, self::DOC . " has {$heading}.");
        $this->assertSame(1, preg_match('/^```php\n(.*?)^```$/ms', substr($section, $start), $code), "{$heading} has a PHP example.");

        return $code[1];
    }

    private function requireCode(string $code): void
    {
        $file = $this->tempFile($code);
        try {
            require $file;
        } finally {
            unlink($file);
        }
    }

    private function tempFile(string $code): string
    {
        $file = (string)tempnam(sys_get_temp_dir(), 'ansilume-doc');
        file_put_contents($file, $code);

        return $file;
    }
}
