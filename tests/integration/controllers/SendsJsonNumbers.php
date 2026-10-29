<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use yii\log\Logger;
use yii\web\Request;

/**
 * Sends a request body given as a JSON text, decoded as the request's JSON
 * parser decodes it (config/web.php: JsonParser, a plain json_decode()): a
 * number beyond the int range, such as 1e30 or 9223372036854775808, arrives
 * as a float, and 1e999 as infinity, which json_encode() cannot even write,
 * so SendsJsonBody cannot send it. The parser serves every request, so a
 * signed-in user's web post sent as JSON (with the CSRF header) brings such
 * floats as well as an API request does. Use in WebControllerTestCase
 * subclasses.
 */
trait SendsJsonNumbers
{
    /**
     * JSON numbers beyond the int range. Since PHP 8.5 an (int) cast of
     * each warns ("The float 1.0E+30 is not representable as an int, cast
     * occurred"), and the error handler turns the warning into an
     * exception; up to PHP 8.4 the cast wrapped it to an arbitrary int, and
     * made infinity 0.
     *
     * @return array<string, array{0: string}>
     */
    public static function beyondTheIntRangeProvider(): array
    {
        return [
            '1e30' => ['1e30'],
            'one more than the largest int' => ['9223372036854775808'],
            '1e999, infinity' => ['1e999'],
        ];
    }

    /**
     * The JSON text $json as the body of the next request, a POST unless
     * $method says otherwise; returns the body as the action reads it. A
     * number of beyondTheIntRangeProvider() in it is the float the parser
     * gives.
     *
     * @return array<array-key, mixed>
     */
    protected function sendJsonText(string $json, string $method = 'POST'): array
    {
        $body = json_decode($json, true);
        $this->assertIsArray($body, 'the body is a JSON object');
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams($body);
        $_SERVER['REQUEST_METHOD'] = $method;

        return $body;
    }

    /**
     * Runs $request, one call of an action, with a logger of its own: what
     * the call returned, and the errors and warnings it logged.
     *
     * @template T
     * @param callable(): T $request
     * @return array{0: T, 1: list<string>}
     */
    protected function logged(callable $request): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $result = $request();
        } finally {
            \Yii::setLogger($previous);
        }
        $problems = [];
        foreach ($logger->messages as $message) {
            if (in_array($message[1], [Logger::LEVEL_ERROR, Logger::LEVEL_WARNING], true)) {
                $problems[] = is_string($message[0]) ? $message[0] : get_debug_type($message[0]);
            }
        }

        return [$result, $problems];
    }
}
