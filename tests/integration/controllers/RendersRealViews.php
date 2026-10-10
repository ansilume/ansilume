<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use yii\base\Controller;
use yii\web\AssetManager;
use yii\web\Request;
use yii\web\View;

/**
 * Renders a controller's real views, as a page request does. Use in
 * WebControllerTestCase subclasses, whose web request it points at the page.
 */
trait RendersRealViews
{
    /**
     * Run $render while $ctrl is the current controller of a request for
     * $url (an ActiveForm posts back to it). The console application of the
     * tests has no web root, so asset bundles are dummies. View, asset
     * manager and controller are restored afterwards.
     *
     * @template T
     * @param callable(): T $render
     * @return T
     */
    protected function withRealViews(Controller $ctrl, string $url, callable $render): mixed
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        \Yii::$app->controller = $ctrl;
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setUrl($url);
        try {
            return $render();
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }
}
