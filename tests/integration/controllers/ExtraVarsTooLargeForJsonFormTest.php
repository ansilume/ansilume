<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobTemplateController;
use app\controllers\ScheduleController;
use app\controllers\WorkflowTemplateController;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\models\WorkflowStep;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Model;
use yii\base\Module;
use yii\web\Response;

/**
 * The web forms refuse extra vars with a number too large for JSON: the
 * error shows on the Extra Vars field and nothing is stored.
 *
 * Regression: the job template and schedule forms (and a step posted to a
 * workflow) stored extra vars such as {"timeout_s": 1e999}, which PHP reads
 * as infinity, and then every launch was refused with "The extra vars
 * cannot be stored: they contain a number too large for JSON."
 */
class ExtraVarsTooLargeForJsonFormTest extends WebControllerTestCase
{
    use ScheduleAnswerFixtures;
    use TeamScopeFixtures;

    private const MESSAGE = 'The extra vars cannot be stored: they contain a number too large for JSON.';
    private const TOO_LARGE = '{"app_env": "prod", "timeout_s": 1e999}';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('inf-form', 'admin');
        $this->loginAs($this->admin);
    }

    // -- Job template form --------------------------------------------------------

    public function testTheTemplateFormShowsTheErrorOnExtraVarsAndCreatesNothing(): void
    {
        $name = 'inf-form-template-' . uniqid('', true);
        $userId = (int)$this->admin->id;
        $this->setPost(['JobTemplate' => [
            'name' => $name,
            'project_id' => (string)$this->createProject($userId)->id,
            'inventory_id' => (string)$this->createInventory($userId)->id,
            'runner_group_id' => (string)$this->createRunnerGroup($userId)->id,
            'playbook' => 'site.yml',
            'extra_vars' => self::TOO_LARGE,
        ]]);

        $ctrl = $this->templateController();
        $this->assertSame('rendered:form', $ctrl->actionCreate());

        $this->assertExtraVarsError($ctrl->capturedParams['model'] ?? null, 'extra_vars');
        $this->assertFalse(JobTemplate::findWithDeleted()->where(['name' => $name])->exists());
    }

    public function testTheTemplateFormKeepsTheStoredExtraVarsOnAnEditThatAddsSuchANumber(): void
    {
        $template = $this->template();
        $this->setPost(['JobTemplate' => ['description' => 'changed in the form', 'extra_vars' => self::TOO_LARGE]]);

        $ctrl = $this->templateController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$template->id));

        $this->assertExtraVarsError($ctrl->capturedParams['model'] ?? null, 'extra_vars');
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);
        $this->assertSame('{"app_env": "prod"}', $stored->extra_vars);
        $this->assertNotSame('changed in the form', $stored->description);
    }

    // -- Schedule form ------------------------------------------------------------

    public function testTheScheduleFormShowsTheErrorOnExtraVarsAndCreatesNothing(): void
    {
        $name = 'inf-form-schedule-' . uniqid('', true);
        $this->setPost(['Schedule' => [
            'name' => $name,
            'job_template_id' => (string)$this->template()->id,
            'cron_expression' => '0 2 * * *',
            'timezone' => 'UTC',
            'enabled' => '1',
            'extra_vars' => self::TOO_LARGE,
        ]]);

        $ctrl = $this->scheduleController();
        $this->assertSame('rendered:form', $ctrl->actionCreate());

        $this->assertExtraVarsError($ctrl->capturedParams['model'] ?? null, 'extra_vars');
        $this->assertFalse(Schedule::find()->where(['name' => $name])->exists());
    }

    public function testTheScheduleFormKeepsTheStoredExtraVarsOnAnEditThatAddsSuchANumber(): void
    {
        $templateId = (int)$this->template()->id;
        $schedule = $this->createPlainSchedule($templateId, (int)$this->admin->id, '{"app_env": "prod"}');
        $this->setPost(['Schedule' => ['extra_vars' => '{"app_env": "prod", "retries": -1e999}']]);

        $ctrl = $this->scheduleController();
        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$schedule->id));

        $this->assertExtraVarsError($ctrl->capturedParams['model'] ?? null, 'extra_vars');
        $this->assertSame('{"app_env": "prod"}', Schedule::findOne($schedule->id)?->extra_vars);
    }

    // -- Workflow step ------------------------------------------------------------

    /**
     * The add-step form has no field for the mapping, but a posted one is
     * loaded like every safe attribute.
     */
    public function testAStepPostedWithSuchAnExtraVarsTemplateIsNotAdded(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->setPost(['WorkflowStep' => [
            'name' => 'inf-form-pause',
            'step_type' => WorkflowStep::TYPE_PAUSE,
            'extra_vars_template' => '{"target_env": 1e999}',
        ]]);

        $ctrl = new class ('workflow-template', $this->app()) extends WorkflowTemplateController {
            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
        $ctrl->actionAddStep((int)$workflow->id);

        $this->assertSame('Step not added: ' . self::MESSAGE, \Yii::$app->session->getFlash('danger'));
        $this->assertFalse(WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->exists());
    }

    // -- Helpers ------------------------------------------------------------------

    private function app(): Module
    {
        $this->assertNotNull(\Yii::$app);

        return \Yii::$app;
    }

    private function assertExtraVarsError(mixed $model, string $attribute): void
    {
        $this->assertInstanceOf(Model::class, $model);
        $this->assertSame([self::MESSAGE], $model->getErrors($attribute), (string)json_encode($model->getErrors()));
    }

    private function template(): JobTemplate
    {
        $userId = (int)$this->admin->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        $template->extra_vars = '{"app_env": "prod"}';
        $template->save(false);

        return $template;
    }

    /**
     * @return JobTemplateController&object{capturedParams: array<string, mixed>}
     */
    private function templateController(): JobTemplateController
    {
        return new class ('job-template', $this->app()) extends JobTemplateController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }

    /**
     * @return ScheduleController&object{capturedParams: array<string, mixed>}
     */
    private function scheduleController(): ScheduleController
    {
        return new class ('schedule', $this->app()) extends ScheduleController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }
}
