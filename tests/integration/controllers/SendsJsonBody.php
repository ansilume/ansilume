<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use yii\web\JsonParser;
use yii\web\Request;

/**
 * Sends a JSON request body the way the web application receives one:
 * config/web.php parses application/json bodies with JsonParser, so a
 * client can post numbers, booleans and lists where a form posts only
 * text. Use in WebControllerTestCase subclasses.
 */
trait SendsJsonBody
{
    /**
     * @param array<string, mixed> $body
     */
    protected function sendJson(array $body, string $method = 'POST'): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->parsers = ['application/json' => JsonParser::class];
        $request->getHeaders()->set('Content-Type', 'application/json');
        $request->setRawBody((string)json_encode($body));
        // Parsed when the action first reads it, as in a real request.
        $request->setBodyParams(null);
        $_SERVER['REQUEST_METHOD'] = $method;

        $this->assertSame($body, $request->getBodyParams(), 'the parser keeps the types of the body');
    }
}
