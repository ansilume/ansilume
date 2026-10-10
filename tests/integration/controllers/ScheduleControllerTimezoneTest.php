<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ScheduleController;
use app\models\AuditLog;
use app\models\Schedule;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * Regression: the schedule form computes the next run before it validates,
 * and CronExpression takes the timezone as ?string. A timezone that is not
 * text, a list from a crafted form (Schedule[timezone][]=x) or a number or a
 * boolean from a JSON body (the web application parses JSON bodies), failed
 * with a TypeError (500) instead of showing the form with the error. A
 * timezone with a NUL byte failed the same way with a ValueError, a very
 * long cron expression was parsed for many seconds before the length rule
 * refused it, and a negative cron step made the parser run out of memory or
 * throw a ValueError.
 */
class ScheduleControllerTimezoneTest extends WebControllerTestCase
{
    use RendersRealViews;
    use SendsJsonBody;
    use TeamScopeFixtures;

    private const CRON_TOO_LONG = ['cron_expression' => ['Cron Expression should contain at most 64 characters.']];

    private const CRON_INVALID = [
        'cron_expression' => ['Invalid cron expression. Use standard 5-field format: min hour dom mon dow'],
    ];

    /**
     * @return array<string, array{0: bool, 1: mixed}> whether the body is JSON, and the timezone
     */
    public static function timezoneThatIsNotTextProvider(): array
    {
        return [
            'a list from a form' => [false, ['x']],
            'a number from JSON' => [true, 5],
            'a boolean from JSON' => [true, true],
        ];
    }

    /**
     * @dataProvider timezoneThatIsNotTextProvider
     */
    public function testCreateShowsTheFormWithTheTimezoneError(bool $json, mixed $timezone): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $name = 'test-schedule-tz-' . uniqid('', true);
        $this->submit($json, [
            'name' => $name,
            'job_template_id' => $json ? (int)$scope['own']->id : (string)$scope['own']->id,
            'cron_expression' => '0 2 * * *',
            'timezone' => $timezone,
            'enabled' => $json ? true : '1',
        ]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $model = $this->renderedModel($ctrl);
        $this->assertSame(['timezone' => ['Timezone must be a string.']], $model->getErrors());
        $this->assertNull($model->next_run_at);
        $this->assertNull(Schedule::findOne(['name' => $name]));
    }

    /**
     * @dataProvider timezoneThatIsNotTextProvider
     */
    public function testUpdateShowsTheFormWithTheTimezoneErrorAndChangesNothing(bool $json, mixed $timezone): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $stored = $this->storedRow($schedule);
        $this->submit($json, ['timezone' => $timezone]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame('rendered:form', $result);
        $this->assertSame(['timezone' => ['Timezone must be a string.']], $this->renderedModel($ctrl)->getErrors());
        $this->assertSame($stored, $this->storedRow($schedule));
        $this->assertFalse(AuditLog::find()->where(['action' => AuditLog::ACTION_SCHEDULE_UPDATED, 'object_id' => $schedule->id])->exists());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function jsonTimezoneThatIsNotTextProvider(): array
    {
        return ['a number' => [5], 'a boolean' => [true]];
    }

    /**
     * The form view itself renders the model the action passes back, with
     * the timezone error.
     *
     * @dataProvider jsonTimezoneThatIsNotTextProvider
     */
    public function testTheFormPageShowsTheTimezoneErrorOfAJsonBody(mixed $timezone): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $this->sendJson(['Schedule' => [
            'name' => 'test-schedule-tz-page-' . uniqid('', true),
            'job_template_id' => (int)$scope['own']->id,
            'cron_expression' => '0 2 * * *',
            'timezone' => $timezone,
        ]]);
        $ctrl = $this->makeController();
        $ctrl->actionCreate();

        $html = $this->renderForm($this->renderedModel($ctrl));

        $this->assertMatchesRegularExpression('/field-schedule-timezone[^"]*has-error/', $html);
        $this->assertStringContainsString('Timezone must be a string.', $html);
    }

    /**
     * A JSON body with a text timezone is saved with the next run in that
     * timezone, as a form is.
     */
    public function testAJsonBodyWithATimezoneIsSaved(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $this->sendJson(['Schedule' => ['cron_expression' => '0 2 * * *', 'timezone' => 'Asia/Tokyo']]);

        $this->makeController()->actionUpdate((int)$schedule->id);

        $schedule->refresh();
        $this->assertSame('Asia/Tokyo', $schedule->timezone);
        $next = new \DateTimeImmutable('@' . (int)$schedule->next_run_at);
        $this->assertSame('02:00', $next->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('H:i'));
        $this->assertGreaterThan(time(), (int)$schedule->next_run_at);
    }

    /**
     * Regression: DateTimeZone throws a ValueError for a timezone with a NUL
     * byte, which got past both catch blocks (500).
     */
    public function testCreateWithANulByteInTheTimezoneShowsTheTimezoneError(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $name = 'test-schedule-tz-nul-' . uniqid('', true);
        $this->setPost(['Schedule' => [
            'name' => $name,
            'job_template_id' => (string)$scope['own']->id,
            'cron_expression' => '0 2 * * *',
            'timezone' => "UTC\0x",
            'enabled' => '1',
        ]]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $model = $this->renderedModel($ctrl);
        $this->assertSame(['timezone' => ['Invalid timezone identifier.']], $model->getErrors());
        $this->assertNull($model->next_run_at);
        $this->assertNull(Schedule::findOne(['name' => $name]));
    }

    public function testUpdateWithANulByteInTheTimezoneShowsTheTimezoneErrorAndChangesNothing(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $stored = $this->storedRow($schedule);
        $this->setPost(['Schedule' => ['timezone' => "UTC\0x"]]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame('rendered:form', $result);
        $this->assertSame(['timezone' => ['Invalid timezone identifier.']], $this->renderedModel($ctrl)->getErrors());
        $this->assertSame($stored, $this->storedRow($schedule));
    }

    /**
     * Regression: the next run was computed before the 64-character rule
     * ran, and the cron parser's cost grows quadratically with the length (a
     * 300 KB expression took about 35 s). Any user who may create schedules
     * could hold PHP workers that way. The form now answers at once.
     */
    public function testCreateWithAVeryLongCronExpressionAnswersWithoutParsingIt(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $name = 'test-schedule-long-cron-' . uniqid('', true);
        $this->setPost(['Schedule' => [
            'name' => $name,
            'job_template_id' => (string)$scope['own']->id,
            'cron_expression' => self::veryLongCronExpression(),
            'timezone' => 'UTC',
            'enabled' => '1',
        ]]);

        $ctrl = $this->makeController();
        $started = microtime(true);
        $result = $ctrl->actionCreate();
        $elapsed = microtime(true) - $started;

        $this->assertSame('rendered:form', $result);
        $model = $this->renderedModel($ctrl);
        $this->assertSame(self::CRON_TOO_LONG, $model->getErrors());
        $this->assertNull($model->next_run_at);
        $this->assertNull(Schedule::findOne(['name' => $name]));
        $this->assertLessThan(5.0, $elapsed, 'The long expression must not reach the cron parser.');
    }

    public function testUpdateWithAVeryLongCronExpressionAnswersWithoutParsingIt(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $stored = $this->storedRow($schedule);
        $this->setPost(['Schedule' => ['cron_expression' => self::veryLongCronExpression()]]);

        $ctrl = $this->makeController();
        $started = microtime(true);
        $result = $ctrl->actionUpdate((int)$schedule->id);
        $elapsed = microtime(true) - $started;

        $this->assertSame('rendered:form', $result);
        $this->assertSame(self::CRON_TOO_LONG, $this->renderedModel($ctrl)->getErrors());
        $this->assertSame($stored, $this->storedRow($schedule));
        $this->assertLessThan(5.0, $elapsed, 'The long expression must not reach the cron parser.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function negativeStepProvider(): array
    {
        return [
            'an hour step past its range' => ['* */-30 * * *'],
            'a minute step of -1' => ['*/-1 * * * *'],
        ];
    }

    /**
     * Regression: the cron library accepts a negative step, then throws a
     * ValueError or loops until PHP runs out of memory when it computes the
     * next run, which the form did before validating. That was a 500, or,
     * once the ValueError was caught, a stored schedule that stopped
     * schedule/run. The form now shows the cron error and stores nothing.
     *
     * @dataProvider negativeStepProvider
     */
    public function testCreateWithANegativeCronStepShowsTheCronError(string $cron): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $name = 'test-schedule-negative-step-' . uniqid('', true);
        $this->setPost(['Schedule' => [
            'name' => $name,
            'job_template_id' => (string)$scope['own']->id,
            'cron_expression' => $cron,
            'timezone' => 'UTC',
            'enabled' => '1',
        ]]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $model = $this->renderedModel($ctrl);
        $this->assertSame(self::CRON_INVALID, $model->getErrors());
        $this->assertNull($model->next_run_at);
        $this->assertNull(Schedule::findOne(['name' => $name]));
    }

    /**
     * @dataProvider negativeStepProvider
     */
    public function testUpdateWithANegativeCronStepShowsTheCronErrorAndChangesNothing(string $cron): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $stored = $this->storedRow($schedule);
        $this->setPost(['Schedule' => ['cron_expression' => $cron]]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame('rendered:form', $result);
        $this->assertSame(self::CRON_INVALID, $this->renderedModel($ctrl)->getErrors());
        $this->assertSame($stored, $this->storedRow($schedule));
    }

    /**
     * A valid but 300 KB cron expression: 100,000 minutes of 59, then the
     * other four fields. Parsing it takes about 35 s.
     */
    public static function veryLongCronExpression(): string
    {
        return implode(',', array_fill(0, 100000, '59')) . ' 23 31 12 0';
    }

    /**
     * Post $fields as Schedule[...]: as a form, or as a JSON body.
     *
     * @param array<string, mixed> $fields
     */
    private function submit(bool $json, array $fields): void
    {
        if ($json) {
            $this->sendJson(['Schedule' => $fields]);
            return;
        }
        $this->setPost(['Schedule' => $fields]);
    }

    private function createSchedule(int $templateId, int $createdBy): Schedule
    {
        $s = new Schedule();
        $s->name = 'test-schedule-' . uniqid('', true);
        $s->job_template_id = $templateId;
        $s->cron_expression = '0 2 * * *';
        $s->timezone = 'UTC';
        $s->enabled = true;
        $s->next_run_at = time() + 3600;
        $s->created_by = $createdBy;
        $s->save(false);
        return $s;
    }

    /**
     * @return array<string, mixed> the schedule's row as stored
     */
    private function storedRow(Schedule $schedule): array
    {
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);
        return $stored->getAttributes();
    }

    /**
     * The model the action passed to the form.
     */
    private function renderedModel(ScheduleController $ctrl): Schedule
    {
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(Schedule::class, $model);
        return $model;
    }

    /**
     * Render the real form view.
     */
    private function renderForm(Schedule $model): string
    {
        $ctrl = new ScheduleController('schedule', \Yii::$app);

        return $this->withRealViews(
            $ctrl,
            '/schedule/create',
            fn (): string => $ctrl->renderPartial('form', ['model' => $model, 'templates' => []])
        );
    }

    private function makeController(): ScheduleController
    {
        return new class ('schedule', \Yii::$app) extends ScheduleController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $response = new Response();
                $response->content = 'redirected';
                return $response;
            }
        };
    }
}
