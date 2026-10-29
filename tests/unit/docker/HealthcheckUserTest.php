<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The health checks that run PHP run it as www-data, with gosu.
 *
 * Regression: the app and queue-worker health checks of docker-compose.yml,
 * docker-compose.prebuilt.yml and the deploy role ran `php yii health/check`
 * as root, the user Docker runs a health check as in the app image. Every 15
 * seconds (every 5 while a container starts) the check wrote its log lines
 * to runtime/logs/app.log as root: on a fresh database it created the file
 * while the migrations ran, and it rotated a full one, leaving app.log.N
 * files that root owns. php-fpm's workers and the queue-worker run as
 * www-data and cannot append to a file that root created
 * (EntrypointLogOwnershipTest). Its dirs line answered for root too, who may
 * write anywhere; as www-data it answers for the web server's user.
 */
class HealthcheckUserTest extends TestCase
{
    use RendersDeployRole;

    /** The health check of the app and the queue-worker. */
    private const HEALTH_CHECK = ['CMD', 'gosu', 'www-data', 'php', '/var/www/yii', 'health/check'];

    /** The images of the services that run the health check, with gosu. */
    private const DOCKERFILES = ['docker/php/Dockerfile', 'docker/php/Dockerfile.prod'];

    /**
     * @return array<string, array{0: string}>
     */
    public static function stackProvider(): array
    {
        return [
            'docker-compose.yml' => ['dev'],
            'docker-compose.prebuilt.yml' => ['prebuilt'],
            'the deploy role, building the images' => ['deploy build'],
            'the deploy role, with prebuilt images' => ['deploy prebuilt'],
        ];
    }

    #[DataProvider('stackProvider')]
    public function testRegressionTheAppAndTheQueueWorkerCheckTheirHealthAsWwwData(string $stack): void
    {
        $services = $this->services($stack);

        foreach (['app', 'queue-worker'] as $name) {
            $this->assertArrayHasKey($name, $services);
            $this->assertSame(self::HEALTH_CHECK, $this->healthCheckOf($services[$name]), "the health check of {$name}");
        }
    }

    /**
     * A health check that starts PHP, whatever the service, runs it as
     * www-data: as root it would write the log as root.
     */
    #[DataProvider('stackProvider')]
    public function testNoHealthCheckRunsPhpAsRoot(string $stack): void
    {
        $checked = 0;
        foreach ($this->services($stack) as $name => $service) {
            $test = $this->healthCheckOf($service);
            if (preg_match('/\bphp\b/', implode(' ', $test)) !== 1) {
                continue;
            }
            $checked++;
            $this->assertSame(['CMD', 'gosu', 'www-data', 'php'], array_slice($test, 0, 4), "the health check of {$name} runs php as www-data");
        }
        $this->assertSame(2, $checked, 'the app and the queue-worker run php in their health check');
    }

    /**
     * gosu is in the images of the app, the queue-worker and the
     * schedule-runner, as their entrypoints use it too.
     */
    public function testTheImagesThatRunTheHealthCheckHaveGosu(): void
    {
        foreach (self::DOCKERFILES as $dockerfile) {
            $this->assertContains('gosu', $this->packagesOfTheFinalStage($dockerfile), "{$dockerfile} installs gosu");
        }
    }

    /**
     * The packages that the first apt-get install of the image's final stage
     * installs.
     *
     * @return list<string>
     */
    private function packagesOfTheFinalStage(string $dockerfile): array
    {
        $text = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $dockerfile);
        $finalStage = substr($text, (int)strrpos($text, "\nFROM "));
        $install = 'apt-get install -y';
        $start = strpos($finalStage, $install);
        $this->assertIsInt($start, "the final stage of {$dockerfile} installs packages");
        $end = strpos($finalStage, '&&', $start);
        $this->assertIsInt($end);
        $packages = preg_split('/[\s\\\\]+/', substr($finalStage, $start + strlen($install), $end - $start - strlen($install)), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertIsArray($packages);

        return $packages;
    }

    /**
     * The words of the health check of $service; none without one.
     *
     * @param array<string, mixed> $service
     * @return list<string>
     */
    private function healthCheckOf(array $service): array
    {
        $healthcheck = $service['healthcheck'] ?? [];
        $test = is_array($healthcheck) ? ($healthcheck['test'] ?? []) : [];
        $words = [];
        foreach ((array)$test as $word) {
            $this->assertIsString($word);
            $words[] = $word;
        }

        return $words;
    }

    /**
     * The services of $stack, by name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function services(string $stack): array
    {
        $compose = match ($stack) {
            'dev' => Yaml::parseFile(dirname(__DIR__, 3) . '/docker-compose.yml'),
            'prebuilt' => Yaml::parseFile(dirname(__DIR__, 3) . '/docker-compose.prebuilt.yml'),
            'deploy build' => $this->renderDeployTemplate(false),
            default => $this->renderDeployTemplate(true),
        };
        $this->assertIsArray($compose);
        $this->assertIsArray($compose['services'] ?? null);

        /** @var array<string, array<string, mixed>> $services */
        $services = $compose['services'];

        return $services;
    }
}
