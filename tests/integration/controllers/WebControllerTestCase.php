<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\User;
use app\tests\integration\DbTestCase;

/**
 * Base class for tests that exercise web controllers directly.
 *
 * The test bootstrap creates a console Application, which does not define
 * the web-specific components (`request`, `response`, `session`, `user`,
 * `urlManager`) that controller actions rely on. This class installs them
 * in setUp() and tears them down afterwards so each test starts with a
 * known, minimal web context.
 *
 * Subclasses typically:
 *  - call `loginAs($user)` to populate `Yii::$app->user->id`
 *  - call `setPost($data)` / `setQueryParams($data)` to simulate input
 *  - instantiate an anonymous subclass of the target controller that
 *    overrides `render()` and `redirect()` to capture output instead of
 *    rendering templates or writing HTTP responses
 */
abstract class WebControllerTestCase extends DbTestCase
{
    /**
     * Original component definitions captured in setUp and restored in tearDown.
     * Stored as [id => definition|instance|null] where null means "not present
     * at all" and any other value is passed back to Yii::$app->set() unchanged.
     *
     * @var array<string, mixed>
     */
    private array $originalComponents = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Reset request method — previous tests in the same process may have
        // left $_SERVER['REQUEST_METHOD'] = 'POST' behind via setPost().
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->installComponent('request', new \yii\web\Request([
            'enableCsrfValidation' => false,
            'cookieValidationKey' => 'test-key',
            'scriptUrl' => '/index.php',
            'baseUrl' => '',
        ]));
        $this->installComponent('response', new \yii\web\Response());
        $this->installComponent('session', [
            'class' => \yii\web\Session::class,
        ]);
        $this->installComponent('user', [
            'class' => \yii\web\User::class,
            'identityClass' => User::class,
            'enableSession' => false,
            'enableAutoLogin' => false,
        ]);
        $this->installComponent('urlManager', [
            'class' => \yii\web\UrlManager::class,
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'baseUrl' => '',
            'scriptUrl' => '/index.php',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalComponents as $id => $original) {
            // set(id, null) removes the component entirely; otherwise restore
            // the original definition/instance so later tests inherit the
            // same bootstrap state.
            \Yii::$app->set($id, $original);
        }
        $this->originalComponents = [];
        parent::tearDown();
    }

    /**
     * Log a user in for the duration of the current test by setting the
     * `user` component's identity. No session writes happen because
     * enableSession=false in setUp().
     */
    protected function loginAs(User $user): void
    {
        /** @var \yii\web\User $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->setIdentity($user);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function setQueryParams(array $params): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setQueryParams($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function setPost(array $params): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($params);
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    /**
     * @param mixed $definition
     */
    private function installComponent(string $id, $definition): void
    {
        // Snapshot the raw definition (not the instantiated object) so the
        // original stays lazy and identical to what the bootstrap set up.
        // Yii's ServiceLocator::getComponents(true) exposes both registered
        // instances and class-name definitions, so we pick the matching entry.
        $components = \Yii::$app->getComponents(true);
        $this->originalComponents[$id] = $components[$id] ?? null;
        \Yii::$app->set($id, $definition);
    }
}
