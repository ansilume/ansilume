<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveyDefinition;
use app\components\SurveySecrets;
use app\controllers\JobTemplateController;
use app\models\JobTemplate;
use yii\web\AssetManager;
use yii\web\View;

/**
 * The survey editor and the template page, rendered for real.
 */
class JobTemplateSurveyViewsTest extends WebControllerTestCase
{
    private const LEGACY_DEFAULT = 'legacy-password-default-7712';

    /**
     * Regression: the editor put the survey into an inline script with
     * Json::encode, which leaves "</script>" as it is, so any survey text
     * (name, label, default, hint, options), written through the form or the
     * API, could run script in the browser of the next editor.
     */
    public function testSurveyTextCannotEndTheEditorsScript(): void
    {
        $model = $this->template((string)json_encode([[
            'name' => 'env',
            'label' => '</script><script>alert("label")</script>',
            'type' => 'select',
            'default' => '<!--<script>',
            'options' => ['</script><img src=x onerror=alert(1)>'],
            'hint' => "it's \"quoted\" & <b>bold</b>",
        ]]));

        $html = $this->renderEditor($model);

        $script = $this->inlineScript($html);
        $this->assertStringNotContainsString('</script', strtolower($script));
        $this->assertStringNotContainsString('<!--', $script);
        $this->assertStringNotContainsString('<script', strtolower($script));
        $this->assertStringContainsString('\\u003C\\/script\\u003E\\u003Cscript\\u003Ealert(\\u0022label\\u0022)', $script);
        $this->assertStringNotContainsString('<script>alert("label")', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    /**
     * Regression: a password field's stored default was sent to every editor,
     * in a text input, the hidden input and the inline script.
     */
    public function testAStoredPasswordDefaultNeverReachesThePage(): void
    {
        $model = $this->template((string)json_encode([
            ['name' => 'env', 'type' => 'text', 'default' => 'staging'],
            ['name' => 'db_password', 'type' => 'password', 'default' => self::LEGACY_DEFAULT],
        ]));

        $html = $this->renderEditor($model);

        $this->assertStringNotContainsString(self::LEGACY_DEFAULT, $html);
        $this->assertStringContainsString('staging', $html, 'other defaults are kept');
        $this->assertStringContainsString('sf-default-none', $html, 'the editor explains that there is no default');
    }

    public function testTheHiddenInputStartsAsTheFieldsTheEditorShows(): void
    {
        $model = $this->template('[{"name":"db_password","type":"password","default":"' . self::LEGACY_DEFAULT . '"}]');

        $html = $this->renderEditor($model);

        $this->assertSame(1, preg_match('/<input type="hidden" id="survey-fields-json" name="JobTemplate\[survey_fields\]" value="([^"]*)"/', $html, $m));
        $this->assertSame(
            [['name' => 'db_password', 'label' => 'db_password', 'type' => 'password', 'required' => false, 'default' => '', 'options' => [], 'hint' => '']],
            json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true)
        );
    }

    /**
     * Regression: errors on survey_fields were invisible, the field being a
     * hidden input; a rejected save showed nothing.
     */
    public function testSurveyErrorsAreShown(): void
    {
        $model = $this->template('[{"name":"env"}]');
        $model->survey_fields = '[{"name":"<b>bad</b>","type":"password","default":"x"}]';
        $this->assertFalse($model->validate(['survey_fields']));

        $html = $this->renderEditor($model);

        $this->assertSame(2, substr_count($html, 'class="invalid-feedback d-block" data-testid="survey-fields-error"'));
        $this->assertStringContainsString(htmlspecialchars(SurveyDefinition::PASSWORD_DEFAULT), $html);
        $this->assertStringContainsString('&lt;b&gt;bad&lt;/b&gt;', $html, 'escaped');
        $this->assertStringNotContainsString('<b>bad</b>', $html);
    }

    public function testAnEditorWithoutErrorsShowsNone(): void
    {
        $this->assertStringNotContainsString('survey-fields-error', $this->renderEditor($this->template('[{"name":"env"}]')));
    }

    /**
     * Regression: the template page showed a password field's value from
     * the template's extra vars in plaintext to every template viewer.
     */
    public function testTheTemplatePageMasksPasswordKeysInTheDefaultExtraVars(): void
    {
        $model = $this->template('[{"name":"db_password","type":"password"}]');
        $model->extra_vars = '{"env":"prod","db_password":"' . self::LEGACY_DEFAULT . '"}';
        $model->save(false);

        $html = $this->viewPage($model);

        $this->assertStringNotContainsString(self::LEGACY_DEFAULT, $html);
        $this->assertSame(1, preg_match('/data-testid="template-extra-vars">([^<]*)</', $html, $m));
        $this->assertSame(['env' => 'prod', 'db_password' => SurveySecrets::PLACEHOLDER], json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true));
    }

    // -- Helpers ----------------------------------------------------------------

    private function template(string $survey): JobTemplate
    {
        $user = $this->createUser('survey-views');
        $user->is_superadmin = true;
        $user->save(false);
        $this->loginAs($user);
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$this->createRunnerGroup((int)$user->id)->id,
            (int)$user->id
        );
        $template->survey_fields = $survey;
        $template->save(false);

        return $template;
    }

    private function renderEditor(JobTemplate $model): string
    {
        return $this->withViewComponents(static fn (JobTemplateController $ctrl): string => $ctrl->renderPartial('_survey_editor', ['model' => $model]));
    }

    private function viewPage(JobTemplate $model): string
    {
        return $this->withViewComponents(static fn (JobTemplateController $ctrl): string => $ctrl->actionView((int)$model->id));
    }

    /**
     * Run $render with a real view and a controller whose render() skips the
     * layout. The console application of the tests has no web root, so asset
     * bundles are dummies; view, asset manager and controller are restored.
     *
     * @param callable(JobTemplateController): string $render
     */
    private function withViewComponents(callable $render): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $ctrl = new class ('job-template', \Yii::$app) extends JobTemplateController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
        \Yii::$app->controller = $ctrl;
        try {
            return $render($ctrl);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    /**
     * The editor's inline script, between its <script> and the first </script>.
     */
    private function inlineScript(string $html): string
    {
        $start = strpos($html, "<script>\n(function () {");
        $this->assertNotFalse($start);
        $end = strpos($html, '</script>', $start + 8);
        $this->assertNotFalse($end);

        return substr($html, $start + 8, $end - $start - 8);
    }
}
