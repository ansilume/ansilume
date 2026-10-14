<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use yii\web\Response;

/**
 * Keeps the parameters an action renders its view with, and renders
 * nothing; a redirect goes nowhere.
 */
trait CapturesParams
{
    /** @var array<string, mixed> */
    public array $capturedParams = [];

    public function render($view, $params = []): string
    {
        /** @var array<string, mixed> $params */
        $this->capturedParams = $params;

        return 'rendered:' . $view;
    }

    public function redirect($url, $statusCode = 302): Response
    {
        return new Response();
    }
}
