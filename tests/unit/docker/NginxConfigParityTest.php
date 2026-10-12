<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;

/**
 * Regression: the Ansible deploy role's nginx template drifted from
 * docker/nginx/default.conf. It lacked `server_tokens off` and the public
 * /openapi.yaml location, so deploy installations exposed the nginx version
 * and returned the app's 404 page for the API spec.
 *
 * Both also write the access log without tokens from URLs; what the masking
 * does is tested in NginxLogMaskingTest.
 */
class NginxConfigParityTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function directiveProvider(): array
    {
        return [
            'version hidden' => ['server_tokens off;'],
            'OpenAPI spec served' => ['location = /openapi.yaml {'],
            'OpenAPI spec readable from Swagger UI' => ['add_header Access-Control-Allow-Origin  "*" always;'],
            'httpoxy guard' => ['fastcgi_param HTTP_PROXY "";'],
            'tokens masked in the request URI' => ['map $request_uri $ansilume_uri_path_masked {'],
            'tokens masked in the referrer' => ['map $http_referer $ansilume_referer_path_masked {'],
            'masked log format' => ['log_format ansilume_masked '],
            'access log in the masked format' => ['access_log /var/log/nginx/access.log ansilume_masked;'],
        ];
    }

    /**
     * @dataProvider directiveProvider
     */
    public function testDeployTemplateMatchesTheImageConfig(string $directive): void
    {
        $root = dirname(__DIR__, 3);

        $this->assertStringContainsString($directive, (string)file_get_contents($root . '/docker/nginx/default.conf'));
        $this->assertStringContainsString(
            $directive,
            (string)file_get_contents($root . '/deploy/roles/ansilume/templates/nginx.conf')
        );
    }
}
