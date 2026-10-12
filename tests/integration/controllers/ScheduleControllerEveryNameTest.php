<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveySecrets;
use app\controllers\ScheduleController;
use app\models\AuditLog;
use app\models\Schedule;
use app\models\User;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\AssetManager;
use yii\web\Response;
use yii\web\View;

/**
 * The schedule page and edit form with an answer stored under a password
 * field name that cannot carry an answer, as 2.8.1 stored it: every
 * password field name is a secret key. Both pages show the placeholder, the
 * form submitted unchanged keeps the answer, now sealed, and a new value
 * replaces it.
 *
 * Regression (R2V2-1): once every name outside printable ASCII was
 * unusable, ordinary letters included ("contraseña_db"), the page and the
 * form showed such an answer in plaintext, and submitting the form stored
 * it in plaintext again.
 */
class ScheduleControllerEveryNameTest extends WebControllerTestCase
{
    use ScheduleAnswerFixtures;
    use TeamScopeFixtures;

    private const ANSWER = 'schedule-form-every-name-marker-4410';
    private const NEW_ANSWER = 'schedule-form-every-name-rotated-9025';

    private User $user;
    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'schedule-form-every-name-key-0123456789';
        $this->user = $this->createUserWithRole('schedule-form-every-name', 'operator');
        $this->loginAs($this->user);
    }

    protected function tearDown(): void
    {
        if ($this->originalKey === null) {
            unset($_ENV['APP_SECRET_KEY']);
        } else {
            $_ENV['APP_SECRET_KEY'] = $this->originalKey;
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableNameProvider(): array
    {
        return [
            'a letter outside ASCII' => ['contraseña_db'],
            'a non-breaking space' => ["db_password\u{00A0}"],
            'a number' => ['123'],
            'brackets' => ['db[pw]'],
        ];
    }

    /**
     * @dataProvider unusableNameProvider
     */
    public function testThePageShowsSuchAnAnswerOnlyAsThePlaceholder(string $name): void
    {
        $schedule = $this->legacySchedule($name);

        $html = $this->renderPage('view', ['model' => $schedule]);

        $this->assertStringNotContainsString(self::ANSWER, $html);
        $this->assertStringContainsString('&quot;' . $name . '&quot;: &quot;$encrypted$&quot;', $html);
    }

    /**
     * The round trip of the edit form: it shows the placeholder, and
     * submitted unchanged it keeps the answer, sealed now.
     *
     * @dataProvider unusableNameProvider
     */
    public function testTheEditFormShowsThePlaceholderAndKeepsTheAnswerWhenSubmittedUnchanged(string $name): void
    {
        $schedule = $this->legacySchedule($name);
        $shown = $this->makeController();
        $this->assertSame('rendered:form', $shown->actionUpdate((int)$schedule->id));
        $model = $shown->capturedParams['model'];
        $this->assertInstanceOf(Schedule::class, $model);
        $this->assertSame(['app_env' => 'prod', $name => SurveySecrets::PLACEHOLDER], json_decode((string)$model->extra_vars, true));
        $this->assertStringNotContainsString(self::ANSWER, $this->renderPage('form', ['model' => $model, 'templates' => []]));
        $this->setPost(['Schedule' => $this->formData($schedule, (string)$model->extra_vars)]);

        $ctrl = $this->makeController();
        $this->assertInstanceOf(Response::class, $ctrl->actionUpdate((int)$schedule->id));

        $this->assertSame(['view', 'id' => $schedule->id], $ctrl->capturedRedirect);
        $this->assertSame([$name => self::ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame(['app_env' => 'prod', $name => SurveySecrets::PLACEHOLDER], $this->storedExtraVarsOf($schedule));
        $this->assertSame([$name], $this->auditOf($schedule)['secret_keys_kept']);
    }

    /**
     * @dataProvider unusableNameProvider
     */
    public function testANewValueReplacesTheAnswer(string $name): void
    {
        $schedule = $this->legacySchedule($name);
        $this->setPost(['Schedule' => $this->formData($schedule, self::json(['app_env' => 'prod', $name => self::NEW_ANSWER]))]);

        $this->assertInstanceOf(Response::class, $this->makeController()->actionUpdate((int)$schedule->id));

        $this->assertSame([$name => self::NEW_ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame(['app_env' => 'prod', $name => SurveySecrets::PLACEHOLDER], $this->storedExtraVarsOf($schedule));
        $this->assertSame([$name], $this->auditOf($schedule)['secret_keys_replaced']);
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * A schedule of a template saved before the survey rules, with a text
     * field and a password field named $name, whose answer is stored in
     * plaintext under that name, as 2.8.1 stored it.
     */
    private function legacySchedule(string $name): Schedule
    {
        $template = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $template->survey_fields = self::json([['name' => 'app_env', 'type' => 'text'], ['name' => $name, 'type' => 'password']]);
        $this->assertTrue($template->save(false));

        return $this->createPlainSchedule((int)$template->id, (int)$this->user->id, self::json(['app_env' => 'prod', $name => self::ANSWER]));
    }

    /**
     * @return array<string, string>
     */
    private function formData(Schedule $schedule, string $extraVars): array
    {
        return [
            'name' => (string)$schedule->name,
            'job_template_id' => (string)$schedule->job_template_id,
            'cron_expression' => '0 2 * * *',
            'timezone' => 'UTC',
            'extra_vars' => $extraVars,
            'enabled' => '0',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditOf(Schedule $schedule): array
    {
        /** @var AuditLog|null $entry */
        $entry = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_SCHEDULE_UPDATED, 'object_type' => 'schedule', 'object_id' => $schedule->id])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($entry);
        $metadata = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($metadata);
        $this->assertStringNotContainsString(self::ANSWER, (string)$entry->metadata);

        return $metadata;
    }

    private static function json(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Render a real schedule view. The console application of the tests has
     * no web root, so asset bundles are dummies; view, asset manager and
     * controller are restored afterwards.
     *
     * @param array<string, mixed> $params
     */
    private function renderPage(string $view, array $params): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $ctrl = new ScheduleController('schedule', \Yii::$app);
        \Yii::$app->controller = $ctrl;
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        // ActiveForm posts back to the current URL.
        $request->setUrl('/schedule/' . $view);
        try {
            return $ctrl->renderPartial($view, $params);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    private function makeController(): ScheduleController
    {
        return new class ('schedule', \Yii::$app) extends ScheduleController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = $url;
                $r = new Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
