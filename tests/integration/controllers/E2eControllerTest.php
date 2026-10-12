<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\E2eController;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The web E2E hooks answer 404 unless YII_DEBUG is on.
 *
 * The hooks need no login and no CSRF token, so the gate is all that keeps
 * them off a real installation. Each request goes through runAction(), as a
 * real request does. The open-gate cases use a schedule name without the
 * e2e- prefix, which the action refuses with 400 before touching anything.
 */
class E2eControllerTest extends WebControllerTestCase
{
    private bool $hadDebugEnv = false;
    private mixed $savedDebugEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadDebugEnv = array_key_exists('YII_DEBUG', $_ENV);
        $this->savedDebugEnv = $_ENV['YII_DEBUG'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadDebugEnv) {
            $_ENV['YII_DEBUG'] = $this->savedDebugEnv;
        } else {
            unset($_ENV['YII_DEBUG']);
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function offProvider(): array
    {
        return [
            'false' => ['false'],
            'FALSE' => ['FALSE'],
            '0' => ['0'],
            'off' => ['off'],
            'no' => ['no'],
            'empty' => [''],
            'unrecognized' => ['maybe'],
        ];
    }

    /**
     * Regression: the gate read YII_DEBUG with a (bool) cast, and
     * (bool)'false' is true, so YII_DEBUG=false in .env opened the hooks to
     * anyone who could reach the web server.
     *
     * @dataProvider offProvider
     */
    public function testTheHooksAnswer404WhenYiiDebugIsOff(string $value): void
    {
        $_ENV['YII_DEBUG'] = $value;
        $this->setPost([]);

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->runAction('fire-schedule', ['name' => 'not-an-e2e-schedule']);
    }

    /**
     * Without YII_DEBUG in the environment the gate follows the YII_DEBUG
     * constant, which is false in the tests.
     */
    public function testTheHooksAnswer404WhenYiiDebugIsNotSet(): void
    {
        unset($_ENV['YII_DEBUG']);
        $this->setPost([]);

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->runAction('fire-schedule', ['name' => 'not-an-e2e-schedule']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function onProvider(): array
    {
        return [
            '1' => ['1'],
            'true' => ['true'],
            'on' => ['on'],
        ];
    }

    /**
     * @dataProvider onProvider
     */
    public function testTheHooksRunWhenYiiDebugIsOn(string $value): void
    {
        $_ENV['YII_DEBUG'] = $value;
        $this->setPost([]);

        $response = $this->controller()->runAction('fire-schedule', ['name' => 'not-an-e2e-schedule']);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(400, $response->statusCode);
        $this->assertSame(['error' => 'non-e2e schedule name'], $response->data);
    }

    private function controller(): E2eController
    {
        return new E2eController('e2e', \Yii::$app);
    }
}
