<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every image the development stack (docker-compose.yml, which source
 * installations run too) and the deploy role build pulls its base image on
 * every build (build.pull), so a base rebuilt with security fixes reaches
 * the next `docker compose up -d --build`.
 *
 * Regression: after the PHP security release (2.12.1, PHP 8.2.35), source
 * installations kept PHP 8.2.34. `docker compose up -d --build` and the
 * deploy role (pull: always, build: always) built on the php:8.2-fpm that
 * Docker had kept; `--pull always` pulls the images of services, not the
 * base images of builds, and `build --pull` held only until the next
 * `up --build`.
 */
class BaseImagePullTest extends TestCase
{
    use RendersDeployRole;

    public function testRegressionTheDevelopmentStackPullsTheBaseOfEveryBuild(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 3) . '/docker-compose.yml');
        $this->assertIsArray($compose);

        $this->assertEveryBuildPulls($compose['services']);
    }

    public function testRegressionTheDeployRolePullsTheBaseOfEveryBuild(): void
    {
        $this->assertEveryBuildPulls($this->renderDeployTemplate(false)['services']);
    }

    /**
     * @param array<string, array<string, mixed>> $services
     */
    private function assertEveryBuildPulls(array $services): void
    {
        $built = array_filter($services, static fn (array $service): bool => isset($service['build']));

        $this->assertArrayHasKey('app', $built);
        $this->assertArrayHasKey('runner-1', $built);
        foreach ($built as $name => $service) {
            $this->assertTrue($service['build']['pull'] ?? null, "{$name} pulls its base image when it is built");
        }
    }
}
