<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The nginx of the prebuilt image (docker/nginx/Dockerfile.prod), of the
 * development stack (docker-compose.yml) and of the deploy role's build
 * mode is one image, from an nginx line that still gets fixes.
 *
 * Regression: all three ran nginx:1.25-alpine, which no longer changed
 * after 2024: nginx 1.25.5 on Alpine 3.19, which reached its end of life,
 * with 28 nginx advisories and none of their fixes. Raise OLDEST_LINE when
 * the stable line of nginx moves on.
 */
class NginxBaseImageTest extends TestCase
{
    private const OLDEST_LINE = '1.30';

    public function testRegressionEveryNginxIsTheSameMaintainedImage(): void
    {
        $root = dirname(__DIR__, 3);
        preg_match_all('/^FROM\s+(\S+)/m', (string)file_get_contents($root . '/docker/nginx/Dockerfile.prod'), $from);
        $compose = Yaml::parseFile($root . '/docker-compose.yml');
        $defaults = Yaml::parseFile($root . '/deploy/roles/ansilume/defaults/main.yaml');
        $this->assertIsArray($compose);
        $this->assertIsArray($defaults);

        $this->assertCount(1, $from[1], 'The prebuilt nginx image has one stage.');
        $images = [
            'docker/nginx/Dockerfile.prod' => $from[1][0],
            'docker-compose.yml' => (string)($compose['services']['nginx']['image'] ?? ''),
            'ansilume_nginx_image' => (string)($defaults['ansilume_nginx_image'] ?? ''),
        ];
        $this->assertCount(1, array_unique($images), (string)json_encode($images));
        $this->assertMatchesRegularExpression('/^nginx:(\d+\.\d+)-alpine(?:-slim)?$/', $images['ansilume_nginx_image']);
        preg_match('/^nginx:(\d+\.\d+)-/', $images['ansilume_nginx_image'], $line);
        $this->assertTrue(version_compare($line[1], self::OLDEST_LINE, '>='), "nginx {$line[1]} is older than the oldest line still fixed, " . self::OLDEST_LINE);
    }
}
