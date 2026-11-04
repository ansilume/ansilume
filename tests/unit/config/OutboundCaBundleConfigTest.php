<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use app\components\OutboundHttpClient;
use app\tests\unit\docker\RendersDeployRole;
use app\tests\unit\TemporaryEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OUTBOUND_CA_BUNDLE reaches the outbound HTTP client of the web and console
 * applications (config/services.php) in every deployment: a git checkout
 * reads .env itself (phpdotenv), the prebuilt images get it as env_file, and
 * the deploy role writes it into its .env from ansilume_outbound_ca_bundle.
 */
class OutboundCaBundleConfigTest extends TestCase
{
    use RendersDeployRole;

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function settings(): array
    {
        return [
            'a file' => ['/etc/ssl/ansilume/internal-ca.pem', '/etc/ssl/ansilume/internal-ca.pem'],
            'empty, as the env files leave it' => ['', null],
        ];
    }

    #[DataProvider('settings')]
    public function testTheClientGetsTheCaBundleOfTheEnvironment(string $setting, ?string $caBundle): void
    {
        $environment = new TemporaryEnvironment(['OUTBOUND_CA_BUNDLE' => $setting]);
        try {
            $client = $this->clientDefinition();
        } finally {
            $environment->restore();
        }

        $this->assertSame(OutboundHttpClient::class, $client['class'] ?? null);
        $this->assertArrayHasKey('caBundle', $client);
        $this->assertSame($caBundle, $client['caBundle']);
    }

    public function testWithoutTheSettingTheClientHasNoCaBundle(): void
    {
        $previous = getenv('OUTBOUND_CA_BUNDLE');
        putenv('OUTBOUND_CA_BUNDLE');
        try {
            $client = $this->clientDefinition();
        } finally {
            if ($previous !== false) {
                putenv('OUTBOUND_CA_BUNDLE=' . $previous);
            }
        }

        $this->assertArrayHasKey('caBundle', $client);
        $this->assertNull($client['caBundle']);
    }

    /**
     * The role's .env carries the setting: empty by default, the file the
     * role variable names otherwise.
     */
    public function testTheDeployRoleWritesTheSettingIntoItsEnvFile(): void
    {
        $this->assertSame(['OUTBOUND_CA_BUNDLE='], $this->settingLines($this->renderDeployFile('env')));
        $this->assertSame(
            ['OUTBOUND_CA_BUNDLE=/etc/ssl/ansilume/internal-ca.pem'],
            $this->settingLines($this->renderDeployFile('env', ['ansilume_outbound_ca_bundle' => '/etc/ssl/ansilume/internal-ca.pem']))
        );
    }

    /**
     * The definition of the outboundHttp component in config/services.php,
     * read with the environment as it is.
     *
     * @return array<string, mixed>
     */
    private function clientDefinition(): array
    {
        /** @var array<string, array<string, mixed>> $services */
        $services = require dirname(__DIR__, 3) . '/config/services.php';
        $this->assertArrayHasKey('outboundHttp', $services);

        return $services['outboundHttp'];
    }

    /**
     * The lines of $env that set OUTBOUND_CA_BUNDLE.
     *
     * @return list<string>
     */
    private function settingLines(string $env): array
    {
        return array_values(array_filter(
            explode("\n", $env),
            static fn (string $line): bool => str_starts_with($line, 'OUTBOUND_CA_BUNDLE=')
        ));
    }
}
