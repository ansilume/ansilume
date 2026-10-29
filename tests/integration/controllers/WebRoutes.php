<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use yii\helpers\Inflector;
use yii\web\Request;
use yii\web\UrlManager;

/**
 * Resolves a request, its method and path, as the web application does:
 * with the URL rules and the controller map of config/web.php, and the
 * controllers of app\controllers for every other route. So a test that sends
 * a request by its path reaches the action a real request reaches, also
 * after a rule changes.
 */
final class WebRoutes
{
    /**
     * The controller class, the controller ID, the action ID and the
     * parameters (those of the path, as text) of the request $method $path;
     * null when no rule of the web application takes it.
     *
     * @return array{class: class-string<\yii\web\Controller>, controller: string, action: string, params: array<string, string>}|null
     */
    public static function resolve(string $method, string $path): ?array
    {
        $request = new Request(['pathInfo' => ltrim($path, '/')]);
        $request->getHeaders()->set('X-Http-Method-Override', $method);
        $parsed = self::urlManager()->parseRequest($request);
        if (!is_array($parsed)) {
            return null;
        }
        [$route, $params] = $parsed;
        $slash = strrpos((string)$route, '/');
        if ($slash === false) {
            return null;
        }
        $controller = substr((string)$route, 0, $slash);
        $class = self::controllerClass($controller);
        if ($class === null) {
            return null;
        }
        /** @var array<string, string> $params */
        return ['class' => $class, 'controller' => $controller, 'action' => substr((string)$route, $slash + 1), 'params' => $params];
    }

    /**
     * The class of the controller $id: from the controller map, or the
     * class of app\controllers its ID names (api/v1/projects is
     * app\controllers\api\v1\ProjectsController).
     *
     * @return class-string<\yii\web\Controller>|null
     */
    public static function controllerClass(string $id): ?string
    {
        $map = self::config()['controllerMap'] ?? [];
        $class = is_array($map) ? ($map[$id] ?? null) : null;
        if ($class === null) {
            $slash = strrpos($id, '/');
            $namespace = $slash === false ? '' : str_replace('/', '\\', substr($id, 0, $slash + 1));
            $class = 'app\\controllers\\' . $namespace . Inflector::id2camel($slash === false ? $id : substr($id, $slash + 1)) . 'Controller';
        }
        if (!is_string($class) || !is_subclass_of($class, \yii\web\Controller::class)) {
            return null;
        }

        return $class;
    }

    private static function urlManager(): UrlManager
    {
        static $manager = null;
        if ($manager === null) {
            $components = self::config()['components'] ?? [];
            $config = is_array($components) && is_array($components['urlManager'] ?? null) ? $components['urlManager'] : [];
            $manager = new UrlManager([
                'enablePrettyUrl' => true,
                'enableStrictParsing' => (bool)($config['enableStrictParsing'] ?? false),
                'showScriptName' => false,
                'rules' => is_array($config['rules'] ?? null) ? $config['rules'] : [],
                'cache' => false,
            ]);
        }

        return $manager;
    }

    /**
     * config/web.php, read once.
     *
     * @return array<array-key, mixed>
     */
    private static function config(): array
    {
        static $config = null;
        if ($config === null) {
            $read = require dirname(__DIR__, 3) . '/config/web.php';
            $config = is_array($read) ? $read : [];
        }

        return $config;
    }
}
