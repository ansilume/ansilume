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

    /**
     * Regression: the nginx image of 2.15.2 carried the packages of
     * nginx:1.30-alpine-slim as of 2026-11-18, OpenSSL 3.5.9-r0 and zlib
     * 1.3.2-r1, while its Alpine branch had 3.5.9-r1 and 1.4-r0 with fixes of
     * high and critical severity (CVE-2026-95692, CVE-2026-4738 and more):
     * the release scan (grype --only-fixed --fail-on high) stopped the
     * release. The prebuilt image takes the fixes of its Alpine branch when
     * it is built, right after FROM, before anything else runs in it.
     */
    public function testRegressionThePrebuiltImageTakesTheFixesOfItsAlpineBranch(): void
    {
        $dockerfile = (string)file_get_contents(dirname(__DIR__, 3) . '/docker/nginx/Dockerfile.prod');
        preg_match_all('/^(FROM|RUN|COPY|ADD|EXPOSE|CMD|ENTRYPOINT|USER)\s+(.*)$/m', $dockerfile, $instructions, PREG_SET_ORDER);

        $this->assertGreaterThanOrEqual(2, count($instructions));
        $this->assertSame('FROM', $instructions[0][1]);
        $this->assertSame(['RUN', 'apk upgrade --no-cache'], [$instructions[1][1], trim($instructions[1][2])], 'The instruction right after FROM upgrades the Alpine packages.');
    }
}
