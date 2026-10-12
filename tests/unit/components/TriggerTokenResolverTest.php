<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\TriggerTokenResolver;
use PHPUnit\Framework\TestCase;
use yii\web\Request;

/**
 * The trigger token of a request and how it was sent: the X-Trigger-Token
 * header, then the URL, then `Authorization: Bearer`. A value that is not a
 * non-empty string counts as absent.
 */
class TriggerTokenResolverTest extends TestCase
{
    private const HEADER_TOKEN = 'header-token-value';
    private const URL_TOKEN = 'url-token-value';
    private const BEARER_TOKEN = 'bearer-token-value';

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    private function request(array $query = [], array $headers = []): Request
    {
        $request = new Request(['enableCsrfValidation' => false, 'cookieValidationKey' => 'test-key']);
        $request->setQueryParams($query);
        foreach ($headers as $name => $value) {
            $request->getHeaders()->set($name, $value);
        }

        return $request;
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, string>, 2: array{token: string, transport: string}|null}>
     */
    public static function requestProvider(): array
    {
        $header = [TriggerTokenResolver::HEADER => self::HEADER_TOKEN];
        $bearer = ['Authorization' => 'Bearer ' . self::BEARER_TOKEN];
        $url = ['token' => self::URL_TOKEN];
        $fromHeader = ['token' => self::HEADER_TOKEN, 'transport' => TriggerTokenResolver::TRANSPORT_HEADER];
        $fromUrl = ['token' => self::URL_TOKEN, 'transport' => TriggerTokenResolver::TRANSPORT_URL];
        $fromBearer = ['token' => self::BEARER_TOKEN, 'transport' => TriggerTokenResolver::TRANSPORT_BEARER];

        return [
            'header' => [[], $header, $fromHeader],
            'URL' => [$url, [], $fromUrl],
            'bearer' => [[], $bearer, $fromBearer],
            'header before URL and bearer' => [$url, $header + $bearer, $fromHeader],
            'URL before bearer, so an unrelated Authorization header changes nothing' => [$url, $bearer, $fromUrl],
            'header name is case-insensitive' => [[], ['x-trigger-token' => self::HEADER_TOKEN], $fromHeader],
            'bearer scheme is case-insensitive' => [[], ['Authorization' => 'bearer ' . self::BEARER_TOKEN], $fromBearer],
            'bearer with extra blanks' => [[], ['Authorization' => "Bearer \t " . self::BEARER_TOKEN . '  '], $fromBearer],
            'empty header falls through to the URL' => [$url, [TriggerTokenResolver::HEADER => ''], $fromUrl],
            'token[]=x falls through to bearer' => [['token' => ['x']], $bearer, $fromBearer],
            'nothing' => [[], [], null],
            'empty header' => [[], [TriggerTokenResolver::HEADER => ''], null],
            'empty URL token' => [['token' => ''], [], null],
            'token[]=x' => [['token' => ['x']], [], null],
            'numeric URL token is no string' => [['token' => 42], [], null],
            'Basic credentials' => [[], ['Authorization' => 'Basic ' . base64_encode('proxy:secret')], null],
            'Bearer without credentials' => [[], ['Authorization' => 'Bearer '], null],
            'Bearer glued to the credentials' => [[], ['Authorization' => 'Bearer' . self::BEARER_TOKEN], null],
            'Bearer with two words' => [[], ['Authorization' => 'Bearer one two'], null],
        ];
    }

    /**
     * @dataProvider requestProvider
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @param array{token: string, transport: string}|null $expected
     */
    public function testResolve(array $query, array $headers, ?array $expected): void
    {
        $this->assertSame($expected, TriggerTokenResolver::resolve($this->request($query, $headers)));
    }

    public function testTheFirstOfRepeatedHeadersCounts(): void
    {
        $request = $this->request();
        $request->getHeaders()->add(TriggerTokenResolver::HEADER, self::HEADER_TOKEN);
        $request->getHeaders()->add(TriggerTokenResolver::HEADER, 'second-value');

        $this->assertSame(
            ['token' => self::HEADER_TOKEN, 'transport' => TriggerTokenResolver::TRANSPORT_HEADER],
            TriggerTokenResolver::resolve($request)
        );
    }
}
