<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobTemplatesController;
use app\controllers\api\v1\SchedulesController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApiToken;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;

/**
 * The job-templates, schedules and workflow-templates APIs answer 422 for
 * extra vars with a number too large for JSON, and store nothing.
 *
 * Regression: such extra vars (1e999, which PHP reads as infinity) were
 * stored with 201 or 200, and then every launch of the template or the
 * schedule was refused with "The extra vars cannot be stored: they contain
 * a number too large for JSON." The save now answers 422 with that message.
 */
class ExtraVarsTooLargeForJsonApiTest extends WebControllerTestCase
{
    use ScheduleAnswerFixtures;
    use TeamScopeFixtures;

    private const MESSAGE = 'The extra vars cannot be stored: they contain a number too large for JSON.';
    private const TOO_LARGE = '{"app_env": "prod", "timeout_s": 1e999}';

    private User $admin;
    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'extra-vars-api-test-key-0123456789';
        $this->admin = $this->createUserWithRole('inf-api', 'admin');
        ['raw' => $raw] = ApiToken::generate((int)$this->admin->id, 'inf-api');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        $user->loginByAccessToken($raw);
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

    // -- Job templates ------------------------------------------------------------

    public function testCreatingAJobTemplateWithSuchExtraVarsIs422(): void
    {
        $name = 'inf-api-template-' . uniqid('', true);
        $this->setBody($this->templateBody($name) + ['extra_vars' => self::TOO_LARGE]);

        $result = (new JobTemplatesController('api/v1/job-templates', $this->app()))->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::MESSAGE]], $result);
        $this->assertFalse(JobTemplate::findWithDeleted()->where(['name' => $name])->exists());
    }

    public function testUpdatingAJobTemplateWithSuchExtraVarsIs422AndKeepsTheStoredOnes(): void
    {
        $template = $this->template();
        $template->extra_vars = '{"app_env": "prod"}';
        $template->save(false);
        $this->setBody(['description' => 'changed', 'extra_vars' => self::TOO_LARGE]);

        $result = (new JobTemplatesController('api/v1/job-templates', $this->app()))->actionUpdate((int)$template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::MESSAGE]], $result);
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);
        $this->assertSame('{"app_env": "prod"}', $stored->extra_vars);
        $this->assertNotSame('changed', $stored->description);
    }

    // -- Schedules ----------------------------------------------------------------

    public function testCreatingAScheduleWithSuchExtraVarsIs422(): void
    {
        $name = 'inf-api-schedule-' . uniqid('', true);
        $this->setBody($this->scheduleBody($name, (int)$this->template()->id, self::TOO_LARGE));

        $result = (new SchedulesController('api/v1/schedules', $this->app()))->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::MESSAGE]], $result);
        $this->assertFalse(Schedule::find()->where(['name' => $name])->exists());
    }

    public function testUpdatingAScheduleWithSuchExtraVarsIs422AndKeepsTheStoredOnes(): void
    {
        $templateId = (int)$this->template()->id;
        $schedule = $this->createPlainSchedule($templateId, (int)$this->admin->id, '{"app_env": "prod"}');
        $this->setBody(['extra_vars' => '{"app_env": "prod", "retries": -1e999}']);

        $result = (new SchedulesController('api/v1/schedules', $this->app()))->actionUpdate((int)$schedule->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::MESSAGE]], $result);
        $this->assertSame('{"app_env": "prod"}', Schedule::findOne($schedule->id)?->extra_vars);
    }

    /**
     * The model's rule runs before the password answers are sealed: an
     * answer too large for JSON gets the same 422, and nothing is sealed.
     */
    public function testAPasswordAnswerTooLargeForJsonIs422WithTheSameMessage(): void
    {
        $name = 'inf-api-answer-' . uniqid('', true);
        $template = $this->createPasswordSurveyTemplate((int)$this->admin->id);
        $this->setBody($this->scheduleBody($name, (int)$template->id, '{"app_env": "prod", "db_password": 1e999}'));

        $result = (new SchedulesController('api/v1/schedules', $this->app()))->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::MESSAGE]], $result);
        $this->assertFalse(Schedule::find()->where(['name' => $name])->exists());
    }

    /**
     * A schedule stored with such extra vars before the rule: a PUT without
     * extra_vars validates the stored ones too, as its runs fail anyway.
     */
    public function testAPartialUpdateOfALegacyScheduleIs422(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template()->id, (int)$this->admin->id, self::TOO_LARGE);
        $this->setBody(['name' => 'renamed-' . $schedule->id]);

        $result = (new SchedulesController('api/v1/schedules', $this->app()))->actionUpdate((int)$schedule->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::MESSAGE]], $result);
        $this->assertNotSame('renamed-' . $schedule->id, Schedule::findOne($schedule->id)?->name);
    }

    // -- Workflow steps -----------------------------------------------------------

    public function testAWorkflowStepWithSuchAnExtraVarsTemplateIs422(): void
    {
        $name = 'inf-api-workflow-' . uniqid('', true);
        $this->setBody(['name' => $name, 'steps' => [
            ['name' => 'inf-api-pause', 'step_type' => 'pause', 'extra_vars_template' => '{"target_env": 1e999}'],
        ]]);

        $result = (new WorkflowTemplatesController('api/v1/workflow-templates', $this->app()))->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'steps[0]: ' . self::MESSAGE]], $result);
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => $name])->exists());
        $this->assertFalse(WorkflowStep::find()->where(['name' => 'inf-api-pause'])->exists());
    }

    // -- Helpers ------------------------------------------------------------------

    private function app(): Module
    {
        $this->assertNotNull(\Yii::$app);

        return \Yii::$app;
    }

    private function template(): JobTemplate
    {
        $userId = (int)$this->admin->id;

        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function templateBody(string $name): array
    {
        $userId = (int)$this->admin->id;

        return [
            'name' => $name,
            'project_id' => (int)$this->createProject($userId)->id,
            'inventory_id' => (int)$this->createInventory($userId)->id,
            'runner_group_id' => (int)$this->createRunnerGroup($userId)->id,
            'playbook' => 'site.yml',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleBody(string $name, int $templateId, string $extraVars): array
    {
        return [
            'name' => $name,
            'job_template_id' => $templateId,
            'cron_expression' => '0 3 * * *',
            'extra_vars' => $extraVars,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }
}
