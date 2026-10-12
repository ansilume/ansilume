<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveyField;
use app\components\SurveySecrets;
use app\controllers\ScheduleController;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\ScheduleService;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\AssetManager;
use yii\web\Response;
use yii\web\View;

/**
 * Password answers in the schedule form and on the schedule page.
 *
 * Regression: the answers to password survey fields were stored in
 * plaintext in schedule.extra_vars, shown on the schedule page and in the
 * edit form. Now they are stored sealed, both pages show the placeholder,
 * and submitting the form unchanged keeps them.
 */
class ScheduleControllerSecretsTest extends WebControllerTestCase
{
    use ScheduleAnswerFixtures;
    use TeamScopeFixtures;

    private const ANSWER = 'schedule-form-answer-7302';
    private const NEW_ANSWER = 'schedule-form-rotated-1123';

    private User $user;
    private JobTemplate $template;
    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'schedule-form-test-key-0123456789';
        $this->user = $this->createUserWithRole('schedule-form', 'operator');
        $this->loginAs($this->user);
        $this->template = $this->createPasswordSurveyTemplate((int)$this->user->id);
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

    // -- Create -------------------------------------------------------------------

    public function testAnAnswerIsStoredOnlySealed(): void
    {
        $this->setPost(['Schedule' => $this->formData('create', '{"app_env":"prod","db_password":"' . self::ANSWER . '"}')]);

        $ctrl = $this->makeController();
        $this->assertInstanceOf(Response::class, $ctrl->actionCreate());

        $schedule = $this->scheduleNamed('create');
        $this->assertSame(['view', 'id' => $schedule->id], $ctrl->capturedRedirect);
        $this->assertSame([self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], $this->storedExtraVarsOf($schedule));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
        $audit = $this->auditOf(AuditLog::ACTION_SCHEDULE_CREATED, $schedule);
        $this->assertSame((int)$this->template->id, $audit['job_template_id']);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $audit['secret_keys_added']);
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode($audit) . (string)json_encode(\Yii::$app->session->getAllFlashes()));
    }

    public function testThePlaceholderWithoutAStoredAnswerIsAFormError(): void
    {
        $this->setPost(['Schedule' => $this->formData('no-answer', '{"db_password":"$encrypted$"}')]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionCreate());

        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(Schedule::class, $model);
        $this->assertSame(['extra_vars' => ['"db_password" has no stored value; enter the real value.']], $model->getErrors());
        $this->assertNull(Schedule::findOne(['name' => $this->scheduleName('no-answer')]));
    }

    /**
     * The answers are checked only once the template is one the user may
     * use: an error naming a password field of another team's template
     * would reveal its survey.
     */
    public function testAnotherTeamsTemplateGetsOnlyTheTemplateError(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $foreign = $scope['foreign'];
        $foreign->survey_fields = (string)json_encode([['name' => 'their_secret', 'label' => 'S', 'type' => SurveyField::TYPE_PASSWORD]]);
        $foreign->save(false);
        $data = $this->formData('foreign', '{"their_secret":"$encrypted$"}');
        $data['job_template_id'] = (string)$foreign->id;
        $this->setPost(['Schedule' => $data]);

        $ctrl = $this->makeController();
        $ctrl->actionCreate();

        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(Schedule::class, $model);
        $this->assertSame(['job_template_id' => [ScheduleService::TEMPLATE_MISSING_MESSAGE]], $model->getErrors());
    }

    /**
     * A submission that fails validation shows what was submitted, the
     * typed answer included, and stores nothing: submitting the corrected
     * form stores the typed answer, not an older one.
     */
    public function testAFailedValidationStoresNothingAndShowsTheSubmission(): void
    {
        $submitted = '{"db_password":"' . self::ANSWER . '"}';
        $data = $this->formData('invalid', $submitted);
        $data['cron_expression'] = 'not a cron';
        $this->setPost(['Schedule' => $data]);

        $ctrl = $this->makeController();
        $ctrl->actionCreate();

        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(Schedule::class, $model);
        $this->assertArrayHasKey('cron_expression', $model->getErrors());
        $this->assertSame($submitted, $model->extra_vars);
        $this->assertNull($model->getSealedSecretExtraVars());
        $this->assertNull(Schedule::findOne(['name' => $this->scheduleName('invalid')]));
    }

    /**
     * Only server code sets the sealed answers: a form cannot.
     */
    public function testAPostedSealedValueIsIgnored(): void
    {
        $data = $this->formData('forged', '{"app_env":"prod"}');
        $data['secret_extra_vars'] = SurveySecrets::seal([self::SURVEY_PASSWORD_FIELD => 'forged']);
        $this->setPost(['Schedule' => $data]);

        $this->makeController()->actionCreate();

        $this->assertNull($this->scheduleNamed('forged')->getSealedSecretExtraVars());
    }

    // -- Edit -------------------------------------------------------------------

    public function testTheEditFormShowsThePlaceholder(): void
    {
        $schedule = $this->storedSchedule();

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$schedule->id));

        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(Schedule::class, $model);
        $this->assertSame([self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], json_decode((string)$model->extra_vars, true));
        $html = $this->renderPage('form', ['model' => $model, 'templates' => []]);
        $this->assertStringNotContainsString(self::ANSWER, $html);
        $this->assertStringContainsString('&quot;db_password&quot;:&quot;$encrypted$&quot;', $html);
        $this->assertStringContainsString('leave it to keep the stored answer', $html);
    }

    /**
     * A key that became a password field after the schedule was saved still
     * holds its value in plaintext: the form shows the placeholder anyway.
     */
    public function testTheEditFormMasksAPlainAnswer(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, (int)$this->user->id, '{"db_password":"' . self::ANSWER . '"}');

        $ctrl = $this->makeController();
        $ctrl->actionUpdate((int)$schedule->id);

        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(Schedule::class, $model);
        $this->assertStringNotContainsString(self::ANSWER, (string)$model->extra_vars);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], json_decode((string)$model->extra_vars, true));
    }

    /**
     * The round trip: the form submitted as shown keeps the stored answers.
     */
    public function testSubmittingTheEditFormUnchangedKeepsTheAnswers(): void
    {
        $schedule = $this->storedSchedule();
        $shown = $this->makeController();
        $shown->actionUpdate((int)$schedule->id);
        $model = $shown->capturedParams['model'];
        $this->assertInstanceOf(Schedule::class, $model);
        $this->setPost(['Schedule' => $this->formData('kept', (string)$model->extra_vars)]);

        $ctrl = $this->makeController();
        $this->assertInstanceOf(Response::class, $ctrl->actionUpdate((int)$schedule->id));

        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame($this->scheduleName('kept'), $this->reloadSchedule($schedule)->name);
        $audit = $this->auditOf(AuditLog::ACTION_SCHEDULE_UPDATED, $schedule);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $audit['secret_keys_kept']);
        $this->assertSame((int)$this->template->id, $audit['previous_job_template_id']);
    }

    public function testANewValueReplacesTheAnswer(): void
    {
        $schedule = $this->storedSchedule();
        $this->setPost(['Schedule' => $this->formData('replaced', '{"app_env":"prod","db_password":"' . self::NEW_ANSWER . '"}')]);

        $this->makeController()->actionUpdate((int)$schedule->id);

        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::NEW_ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $this->auditOf(AuditLog::ACTION_SCHEDULE_UPDATED, $schedule)['secret_keys_replaced']);
    }

    /**
     * Stored answers do not follow the schedule to another template.
     */
    public function testAfterATemplateChangeThePlaceholderIsAFormError(): void
    {
        $schedule = $this->storedSchedule();
        $other = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $data = $this->formData('moved', (string)$schedule->extra_vars);
        $data['job_template_id'] = (string)$other->id;
        $this->setPost(['Schedule' => $data]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$schedule->id));

        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(Schedule::class, $model);
        $this->assertSame(['extra_vars' => ['"db_password": the job template changed; enter the real value.']], $model->getErrors());
        $this->assertSame((int)$this->template->id, (int)$this->reloadSchedule($schedule)->job_template_id);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
    }

    /**
     * Answers sealed with an APP_SECRET_KEY that has been replaced: a form
     * error, not a 500.
     */
    public function testUndecryptableAnswersAreAFormError(): void
    {
        $schedule = $this->storedSchedule();
        $_ENV['APP_SECRET_KEY'] = 'a-rotated-key-that-cannot-open-it-0123';
        $this->setPost(['Schedule' => $this->formData('undecryptable', (string)$schedule->extra_vars)]);

        $ctrl = $this->makeController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$schedule->id));

        $model = $ctrl->capturedParams['model'];
        $this->assertInstanceOf(Schedule::class, $model);
        $this->assertSame(['extra_vars' => ['The stored password answers cannot be decrypted; enter the real values.']], $model->getErrors());
    }

    public function testAPostedSealedValueIsIgnoredOnUpdate(): void
    {
        $schedule = $this->storedSchedule();
        $sealed = $schedule->getSealedSecretExtraVars();
        $data = $this->formData('forged-update', (string)$schedule->extra_vars);
        $data['secret_extra_vars'] = 'forged';
        $this->setPost(['Schedule' => $data]);

        $this->makeController()->actionUpdate((int)$schedule->id);

        $this->assertNotSame('forged', $this->reloadSchedule($schedule)->getSealedSecretExtraVars());
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($sealed));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
    }

    // -- Page -----------------------------------------------------------------

    public function testThePageShowsThePlaceholder(): void
    {
        $schedule = $this->storedSchedule();

        $html = $this->renderPage('view', ['model' => $schedule]);

        $this->assertStringNotContainsString(self::ANSWER, $html);
        $this->assertMatchesRegularExpression('/data-testid="schedule-extra-vars"><code>\{\s+&quot;app_env&quot;: &quot;prod&quot;,\s+&quot;db_password&quot;: &quot;\$encrypted\$&quot;\s+\}/', $html);
        $this->assertStringContainsString('Password answers are stored encrypted and show as <code>$encrypted$</code>.', $html);
    }

    public function testThePageMasksAPlainAnswer(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, (int)$this->user->id, '{"db_password":"' . self::ANSWER . '"}');

        $html = $this->renderPage('view', ['model' => $schedule]);

        $this->assertStringNotContainsString(self::ANSWER, $html);
        $this->assertStringContainsString('&quot;db_password&quot;: &quot;$encrypted$&quot;', $html);
    }

    public function testThePageHasNoExtraVarsCardWithoutExtraVars(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, (int)$this->user->id, null);

        $this->assertStringNotContainsString('schedule-extra-vars', $this->renderPage('view', ['model' => $schedule]));
    }

    // -- Helpers ----------------------------------------------------------------

    private function storedSchedule(): Schedule
    {
        return $this->createScheduleWithAnswers((int)$this->template->id, (int)$this->user->id, [self::SURVEY_PASSWORD_FIELD => self::ANSWER]);
    }

    private function scheduleName(string $suffix): string
    {
        return 'schedule-form-' . $suffix . '-' . $this->user->id;
    }

    private function scheduleNamed(string $suffix): Schedule
    {
        $schedule = Schedule::findOne(['name' => $this->scheduleName($suffix)]);
        $this->assertNotNull($schedule, 'flashes: ' . json_encode(\Yii::$app->session->getAllFlashes()));

        return $schedule;
    }

    /**
     * @return array<string, string>
     */
    private function formData(string $suffix, string $extraVars): array
    {
        return [
            'name' => $this->scheduleName($suffix),
            'job_template_id' => (string)$this->template->id,
            'cron_expression' => '0 2 * * *',
            'timezone' => 'UTC',
            'extra_vars' => $extraVars,
            'enabled' => '1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditOf(string $action, Schedule $schedule): array
    {
        /** @var AuditLog|null $entry */
        $entry = AuditLog::find()->where(['action' => $action, 'object_type' => 'schedule', 'object_id' => $schedule->id])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($entry);
        $metadata = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($metadata);

        return $metadata;
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
