<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * OUTBOUND_CA_BUNDLE names a file inside the containers that send the
 * notifications and the outbound webhooks: every service that runs the
 * server's code with its .env. The texts that tell an operator where the
 * file must be (the .env examples, the deploy role's defaults, the
 * troubleshooting entry of curl error 60) name each of them, the
 * notification-worker included: without the file there, every delivery to
 * an endpoint that an internal CA certified fails with curl error 77.
 */
final class OutboundCaBundleDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const TLS_ENTRY = '## A notification fails with `The TLS connection failed (curl error 60).`';

    public function testEveryTextNamesEveryServiceThatNeedsTheFile(): void
    {
        $services = $this->servicesWithTheServersEnv();
        $this->assertSame(['app', 'notification-worker', 'queue-worker', 'schedule-runner'], $services);

        $texts = [
            '.env.example' => $this->between($this->read('.env.example'), '# Outbound HTTP', 'OUTBOUND_CA_BUNDLE='),
            '.env.prod.example' => $this->between($this->read('.env.prod.example'), '# Outbound HTTP', 'OUTBOUND_CA_BUNDLE='),
            'deploy/roles/ansilume/defaults/main.yaml' => $this->between(
                $this->read('deploy/roles/ansilume/defaults/main.yaml'),
                '# -- Outbound HTTP',
                'ansilume_outbound_ca_bundle:'
            ),
            'docs/troubleshooting.md' => $this->section('docs/troubleshooting.md', self::TLS_ENTRY),
        ];
        foreach ($texts as $file => $text) {
            foreach ($services as $service) {
                $this->assertMatchesRegularExpression('/(?<![\w-])' . preg_quote($service, '/') . '(?![\w-])/', $text, "{$file} names {$service}");
            }
        }
    }

    /**
     * The troubleshooting entry gives the command of every way of
     * installing: the prebuilt services name .env as their env_file, so
     * `docker compose up -d` recreates them for a changed .env, which a
     * source installation's services of docker-compose.yml, without one, do
     * not, and an installation the quickstart made from source names both
     * compose files. Review finding: the entry's plain `docker compose up -d`
     * started the development stack there, and left the workers of a
     * source installation on their old .env, so their deliveries kept
     * failing with curl error 60 while Send Test worked.
     */
    public function testTheTlsEntryGivesTheCommandOfEveryWayOfInstalling(): void
    {
        $entry = $this->flat('docs/troubleshooting.md', self::TLS_ENTRY);
        foreach (
            [
                '`docker compose -f docker-compose.prebuilt.yml -f docker-compose.override.yml up -d`',
                '`docker compose restart app queue-worker notification-worker schedule-runner`',
                'with its prebuilt images, also put that override into the install directory, where the role uses it',
                '[notifications.md](notifications.md#outbound-http)',
            ] as $needle
        ) {
            $this->assertMentions($needle, $entry, self::TLS_ENTRY);
        }
        $development = Yaml::parseFile($this->root() . '/docker-compose.yml');
        $this->assertIsArray($development);
        foreach ($this->servicesWithTheServersEnv() as $service) {
            $this->assertArrayNotHasKey('env_file', (array)($development['services'][$service] ?? []), "docker-compose.yml's {$service} names no env_file, so it must be restarted for a changed .env");
        }
    }

    /**
     * The services of docker-compose.prebuilt.yml that get the server's .env.
     *
     * @return list<string>
     */
    private function servicesWithTheServersEnv(): array
    {
        $compose = Yaml::parseFile($this->root() . '/docker-compose.prebuilt.yml');
        $this->assertIsArray($compose);
        $services = [];
        foreach ((array)($compose['services'] ?? []) as $name => $service) {
            if (is_array($service) && in_array('.env', (array)($service['env_file'] ?? []), true)) {
                $services[] = (string)$name;
            }
        }
        sort($services);

        return $services;
    }
}
