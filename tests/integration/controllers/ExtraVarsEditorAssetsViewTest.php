<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\VersionedAssetUrl;
use app\controllers\JobTemplateController;
use app\controllers\ScheduleController;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\web\AssetManager;
use yii\web\Controller;
use yii\web\View;

/**
 * The pages with the Extra Vars editor load its script and stylesheet with
 * a version of their content (VersionedAssetUrl).
 *
 * Regression: they loaded /js/extra-vars-editor.js without one. nginx sends
 * static files without Cache-Control, so a browser could run the cached
 * older editor for days after an update, which rewrote the stored Extra
 * Vars on every save.
 */
class ExtraVarsEditorAssetsViewTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('editor-assets', 'admin');
        $this->loginAs($this->admin);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pageProvider(): array
    {
        return [
            'job template form' => ['templateForm'],
            'launch page' => ['launchPage'],
            'schedule form' => ['scheduleForm'],
        ];
    }

    /**
     * @dataProvider pageProvider
     */
    public function testThePageLoadsTheEditorWithTheVersionOfItsContent(string $page): void
    {
        $html = match ($page) {
            'templateForm' => $this->templateForm(),
            'launchPage' => $this->launchPage(),
            default => $this->scheduleForm(),
        };

        $script = VersionedAssetUrl::of('/js/extra-vars-editor.js');
        $stylesheet = VersionedAssetUrl::of('/css/extra-vars-editor.css');
        $this->assertMatchesRegularExpression('#^/js/extra-vars-editor\.js\?v=[0-9a-f]{12}$#', $script);
        $this->assertStringContainsString('<script src="' . $script . '"></script>', $html);
        $this->assertStringContainsString('<link href="' . $stylesheet . '" rel="stylesheet">', $html);
        $this->assertStringNotContainsString('"/js/extra-vars-editor.js"', $html, 'never without its version');
        $this->assertStringNotContainsString('"/css/extra-vars-editor.css"', $html, 'never without its version');
        $this->assertStringContainsString('data-extra-vars-editor="1"', $html);
    }

    /**
     * The directory of the views a controller renders; null for the
     * application's views/<controller id>.
     */
    protected function viewDirectory(string $controllerId): ?string
    {
        return null;
    }

    private function templateForm(): string
    {
        $ctrl = $this->templateController();

        return $this->withViewComponents($ctrl, static fn (): mixed => $ctrl->actionCreate());
    }

    private function launchPage(): string
    {
        $userId = (int)$this->admin->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        $this->setQueryParams(['id' => (string)$template->id]);
        $ctrl = $this->templateController();

        return $this->withViewComponents($ctrl, static fn (): mixed => $ctrl->actionLaunch());
    }

    private function scheduleForm(): string
    {
        $ctrl = new class ('schedule', $this->app()) extends ScheduleController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };

        return $this->withViewComponents($ctrl, static fn (): mixed => $ctrl->actionCreate());
    }

    private function app(): Module
    {
        $this->assertNotNull(\Yii::$app);

        return \Yii::$app;
    }

    private function templateController(): JobTemplateController
    {
        return new class ('job-template', $this->app()) extends JobTemplateController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
    }

    /**
     * Run $render with a real view; the controllers render without the
     * layout. The console application of the tests has no web root, so asset
     * bundles are dummies; view, asset manager and controller are restored.
     *
     * @param callable(): mixed $render
     */
    private function withViewComponents(Controller $ctrl, callable $render): string
    {
        $directory = $this->viewDirectory($ctrl->id);
        if ($directory !== null) {
            $ctrl->setViewPath($directory);
        }
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        $assets = new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']);
        \Yii::$app->set('assetManager', $assets);
        \Yii::$app->set('view', new View());
        \Yii::$app->controller = $ctrl;
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        // ActiveForm posts back to the current URL.
        $request->setUrl('/' . $ctrl->id . '/create');
        try {
            $html = $render();
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
        $this->assertIsString($html);

        return $html;
    }
}
