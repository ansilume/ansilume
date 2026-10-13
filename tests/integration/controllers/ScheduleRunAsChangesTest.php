<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\SurveySecrets;
use app\controllers\api\v1\SchedulesController;
use app\controllers\ScheduleController;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\ScheduleRunAs;
use app\services\ScheduleSecrets;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * What makes a schedule run as the user who changes it, through the edit
 * form and PUT /api/v1/schedules/{id}: a change of its job template, cron
 * expression, timezone or extra vars, a password answer entered anew or
 * dropped, or enabling it. Renaming, disabling and saving the same values
 * keep the user it runs as. The schedule.updated audit entry records the
 * change as run_as {from, to}.
 */
class ScheduleRunAsChangesTest extends WebControllerTestCase
{
    use ScheduleAnswerFixtures;
    use TeamScopeFixtures;

    private const STORED_ANSWER = 'stored-answer-0815';

    private User $owner;
    private User $editor;
    private JobTemplate $template;
    private Schedule $schedule;
    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'schedule-run-as-test-key-0123456789';
        $this->owner = $this->createUserWithRole('run-as-owner', 'operator');
        $this->editor = $this->createUserWithRole('run-as-editor', 'operator');
        $this->template = $this->createPasswordSurveyTemplate((int)$this->owner->id);
        $this->schedule = $this->createScheduleWithAnswers(
            (int)$this->template->id,
            (int)$this->owner->id,
            [self::SURVEY_PASSWORD_FIELD => self::STORED_ANSWER],
            [self::SURVEY_TEXT_FIELD => 'prod']
        );
        $this->loginAs($this->editor);
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

    // -- The edit form ------------------------------------------------------------------

    /**
     * Form fields that differ from the form as shown, by case; ANOTHER_TEMPLATE
     * stands for the ID of another job template.
     *
     * @return array<string, array{0: array<string, string>}>
     */
    public static function armingFormProvider(): array
    {
        return [
            'another job template' => [['job_template_id' => 'ANOTHER_TEMPLATE', 'extra_vars' => '{"app_env": "prod"}']],
            'another cron expression' => [['cron_expression' => '30 2 * * *']],
            'another timezone' => [['timezone' => 'Europe/Berlin']],
            'other extra vars' => [['extra_vars' => '{"app_env": "stage", "db_password": "$encrypted$"}']],
            'a password answer entered anew' => [['extra_vars' => '{"app_env": "prod", "db_password": "new-answer-4711"}']],
            'the stored password answer entered anew' => [['extra_vars' => '{"app_env": "prod", "db_password": "' . self::STORED_ANSWER . '"}']],
            'a stored password answer dropped' => [['extra_vars' => '{"app_env": "prod"}']],
        ];
    }

    /**
     * Regression: editing another user's schedule let the editor approve its
     * job: the form kept the schedule's creator, whatever the editor changed.
     *
     * @dataProvider armingFormProvider
     * @param array<string, string> $fields
     */
    public function testAFormChangeOfWhatOrWhenItRunsMakesItRunAsTheEditor(array $fields): void
    {
        $this->submitForm($this->withAnotherTemplate($fields));

        $this->assertRunsAs($this->editor);
        $this->assertSame(['from' => (int)$this->owner->id, 'to' => (int)$this->editor->id], $this->lastUpdateContext()[ScheduleRunAs::AUDIT_KEY] ?? null);
    }

    public function testEnablingTheScheduleInTheFormMakesItRunAsTheEditor(): void
    {
        Schedule::updateAll(['enabled' => 0, 'next_run_at' => null], ['id' => $this->schedule->id]);

        $this->submitForm(['enabled' => '1']);

        $this->assertRunsAs($this->editor);
        $this->assertTrue((bool)$this->reloadSchedule($this->schedule)->enabled);
        $this->assertArrayHasKey(ScheduleRunAs::AUDIT_KEY, $this->lastUpdateContext());
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function keepingFormProvider(): array
    {
        return [
            'nothing' => [[]],
            'the name' => [['name' => 'renamed-by-the-editor']],
            'disabling it' => [['enabled' => '0']],
            'the extra vars written otherwise' => [['extra_vars' => "{\r\n  \"app_env\" : \"prod\",\r\n  \"db_password\" : \"\$encrypted\$\"\r\n}"]],
            'an empty timezone, which is UTC' => [['timezone' => '']],
        ];
    }

    /**
     * @dataProvider keepingFormProvider
     * @param array<string, string> $fields
     */
    public function testAFormSaveThatChangesNothingThatRunsKeepsTheOwner(array $fields): void
    {
        $this->submitForm($fields);

        $this->assertRunsAs($this->owner);
        $this->assertArrayNotHasKey(ScheduleRunAs::AUDIT_KEY, $this->lastUpdateContext());
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::STORED_ANSWER], $this->sealedAnswersOf($this->schedule), 'the answer is kept');
    }

    /**
     * A change the form refuses is not saved: the owner keeps the schedule.
     */
    public function testARefusedFormChangeKeepsTheOwner(): void
    {
        $this->setPost(['Schedule' => ['cron_expression' => 'not a cron'] + $this->formAsShown()]);
        $this->assertSame('rendered:form', $this->webController()->actionUpdate((int)$this->schedule->id));

        $this->assertRunsAs($this->owner);
        $this->assertSame([], $this->updateContexts());
    }

    // -- PUT /api/v1/schedules/{id} -------------------------------------------------------

    /**
     * Body fields by case; ANOTHER_TEMPLATE stands for the ID of another job
     * template.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function armingBodyProvider(): array
    {
        return [
            'another job template' => [['job_template_id' => 'ANOTHER_TEMPLATE', 'extra_vars' => '{"app_env": "prod"}']],
            'another cron expression' => [['cron_expression' => '30 2 * * *']],
            'another timezone' => [['timezone' => 'Europe/Berlin']],
            'other extra vars' => [['extra_vars' => '{"app_env": "stage", "db_password": "$encrypted$"}']],
            'a password answer sent anew' => [['extra_vars' => '{"app_env": "prod", "db_password": "new-answer-4711"}']],
            'extra vars cleared' => [['extra_vars' => null]],
        ];
    }

    /**
     * Regression: editing another user's schedule let the editor approve its
     * job: PUT kept the schedule's creator, whatever the caller changed.
     *
     * @dataProvider armingBodyProvider
     * @param array<string, mixed> $body
     */
    public function testAnApiChangeOfWhatOrWhenItRunsMakesItRunAsTheCaller(array $body): void
    {
        $data = $this->put($this->withAnotherTemplate($body));

        $this->assertSame((int)$this->editor->id, $data['created_by']);
        $this->assertRunsAs($this->editor);
        $this->assertSame(['from' => (int)$this->owner->id, 'to' => (int)$this->editor->id], $this->lastUpdateContext()[ScheduleRunAs::AUDIT_KEY] ?? null);
    }

    public function testEnablingTheScheduleThroughTheApiMakesItRunAsTheCaller(): void
    {
        Schedule::updateAll(['enabled' => 0, 'next_run_at' => null], ['id' => $this->schedule->id]);

        $data = $this->put(['enabled' => true]);

        $this->assertTrue($data['enabled']);
        $this->assertSame((int)$this->editor->id, $data['created_by']);
        $this->assertRunsAs($this->editor);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function keepingBodyProvider(): array
    {
        return [
            'nothing' => [[]],
            'the name' => [['name' => 'renamed-through-the-api']],
            'disabling it' => [['enabled' => false]],
            'the same values' => [['cron_expression' => '0 2 * * *', 'timezone' => 'UTC', 'enabled' => true]],
            'the extra vars written otherwise' => [['extra_vars' => '{ "app_env" : "prod", "db_password" : "$encrypted$" }']],
            'the same job template without extra vars' => [['job_template_id' => 'SAME_TEMPLATE']],
        ];
    }

    /**
     * @dataProvider keepingBodyProvider
     * @param array<string, mixed> $body
     */
    public function testAnApiSaveThatChangesNothingThatRunsKeepsTheOwner(array $body): void
    {
        if (($body['job_template_id'] ?? null) === 'SAME_TEMPLATE') {
            $body['job_template_id'] = (int)$this->template->id;
        }

        $data = $this->put($body);

        $this->assertSame((int)$this->owner->id, $data['created_by']);
        $this->assertRunsAs($this->owner);
        $this->assertArrayNotHasKey(ScheduleRunAs::AUDIT_KEY, $this->lastUpdateContext());
    }

    /**
     * The owner who changes their own schedule keeps it, and nothing about
     * whom it runs as is audited.
     */
    public function testTheOwnersOwnChangeAuditsNoRunAs(): void
    {
        $this->loginAs($this->owner);

        $this->put(['cron_expression' => '30 2 * * *']);
        $this->submitForm(['timezone' => 'Europe/Berlin']);

        $this->assertRunsAs($this->owner);
        foreach ($this->updateContexts() as $context) {
            $this->assertArrayNotHasKey(ScheduleRunAs::AUDIT_KEY, $context);
        }
        $this->assertCount(2, $this->updateContexts());
    }

    // -- Helpers ----------------------------------------------------------------------------

    /**
     * Submit the edit form as shown, with $fields changed; it must save.
     *
     * @param array<string, mixed> $fields
     */
    private function submitForm(array $fields): void
    {
        $posted = array_map(static fn (mixed $value): string => is_scalar($value) ? (string)$value : '', $fields);
        $this->setPost(['Schedule' => $posted + $this->formAsShown()]);

        $result = $this->webController()->actionUpdate((int)$this->schedule->id);

        $this->assertInstanceOf(Response::class, $result, 'the form saved');
    }

    /**
     * PUT $body; it must answer 200. The response's data.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function put(array $body): array
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
        \Yii::$app->response->statusCode = 200;

        $result = (new SchedulesController('api/v1/schedules', $this->app()))->actionUpdate((int)$this->schedule->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $data = $result['data'] ?? null;
        $this->assertIsArray($data);

        return $data;
    }

    /**
     * $fields with ANOTHER_TEMPLATE replaced by the ID of a new job template
     * the editor may operate (its project has no team).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function withAnotherTemplate(array $fields): array
    {
        if (($fields['job_template_id'] ?? null) === 'ANOTHER_TEMPLATE') {
            $fields['job_template_id'] = (int)$this->createPasswordSurveyTemplate((int)$this->owner->id)->id;
        }

        return $fields;
    }

    /**
     * The edit form's fields as it shows them for the stored schedule: the
     * extra vars with the placeholder for the stored answer.
     *
     * @return array<string, string>
     */
    private function formAsShown(): array
    {
        $stored = $this->reloadSchedule($this->schedule);

        return [
            'name' => $stored->name,
            'job_template_id' => (string)$stored->job_template_id,
            'cron_expression' => $stored->cron_expression,
            'timezone' => $stored->timezone,
            'extra_vars' => (string)ScheduleSecrets::maskedExtraVars($stored),
            'enabled' => (bool)$stored->enabled ? '1' : '0',
        ];
    }

    private function assertRunsAs(User $user): void
    {
        $this->assertSame((int)$user->id, (int)$this->reloadSchedule($this->schedule)->created_by);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastUpdateContext(): array
    {
        $contexts = $this->updateContexts();
        $this->assertNotSame([], $contexts, 'schedule.updated is audited');

        return $contexts[count($contexts) - 1];
    }

    /**
     * The contexts of the schedule's schedule.updated audit entries, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function updateContexts(): array
    {
        $contexts = [];
        $entries = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_SCHEDULE_UPDATED, 'object_id' => $this->schedule->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        foreach ($entries as $entry) {
            $context = json_decode((string)$entry->metadata, true);
            $this->assertIsArray($context);
            $this->assertStringNotContainsString(self::STORED_ANSWER, (string)$entry->metadata);
            $contexts[] = $context;
        }

        return $contexts;
    }

    private function webController(): ScheduleController
    {
        return new class ('schedule', $this->app()) extends ScheduleController {
            public function render($view, $params = []): string
            {
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

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return $app;
    }
}
