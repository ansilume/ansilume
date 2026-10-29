<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use yii\web\Response;

/**
 * For an anonymous subclass of a web controller under test: render() renders
 * the real view without the layout, as RendersRealViews sets it up, and
 * keeps the view and its parameters; redirect() keeps where it would go
 * instead of sending a response.
 */
trait CapturesRenderedForm
{
    /** The view render() rendered last; null when nothing was rendered. */
    public ?string $renderedView = null;

    /** @var array<string, mixed> the parameters of that view */
    public array $renderedParams = [];

    /** @var mixed the route passed to redirect(); null when there was none */
    public mixed $redirectedTo = null;

    public function render($view, $params = []): string
    {
        /** @var array<string, mixed> $params */
        $this->renderedView = $view;
        $this->renderedParams = $params;

        return $this->renderPartial($view, $params);
    }

    public function redirect($url, $statusCode = 302): Response
    {
        $this->redirectedTo = $url;

        return new Response();
    }
}
