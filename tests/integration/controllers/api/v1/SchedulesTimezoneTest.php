<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\SchedulesController;
use app\models\ApiToken;
use app\models\Schedule;
use app\models\User;
use app\tests\integration\controllers\ScheduleControllerTimezoneTest;
use app\tests\integration\controllers\SendsJsonBody;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * POST and PUT /api/v1/schedules with a timezone that is not text. The web
 * form passed such a value to CronExpression, which takes ?string, and
 * answered 500 (ScheduleControllerTimezoneTest). The API reads the timezone
 * as a string, so a JSON number or boolean is checked as one: 422, and
 * nothing is written. Pinned here, as both compute the next run before they
 * validate. A timezone with a NUL byte (ValueError), a very long cron
 * expression (parsed for many seconds) and a negative cron step (out of
 * memory, or a ValueError) were regressions of the API too.
 */
class SchedulesTimezoneTest extends WebControllerTestCase
{
    use SendsJsonBody;
    use TeamScopeFixtures;

    private const CRON_INVALID = 'Invalid cron expression. Use standard 5-field format: min hour dom mon dow';

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function timezoneThatIsNotTextProvider(): array
    {
        return ['a number' => [5], 'a boolean' => [true]];
    }

    /**
     * @dataProvider timezoneThatIsNotTextProvider
     */
    public function testCreateWithATimezoneThatIsNotTextIs422(mixed $timezone): void
    {
        $scope = $this->teamScope();
        $this->authenticate($scope['member']);
        $name = 'api-tz-' . uniqid('', true);
        $this->sendJson([
            'name' => $name,
            'job_template_id' => (int)$scope['own']->id,
            'cron_expression' => '0 3 * * *',
            'timezone' => $timezone,
            'enabled' => true,
        ]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Invalid timezone identifier.']], $result);
        $this->assertNull(Schedule::findOne(['name' => $name]));
    }

    /**
     * @dataProvider timezoneThatIsNotTextProvider
     */
    public function testUpdateWithATimezoneThatIsNotTextIs422AndChangesNothing(mixed $timezone): void
    {
        $scope = $this->teamScope();
        $this->authenticate($scope['member']);
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $stored = $this->storedRow($schedule);
        $this->sendJson(['timezone' => $timezone], 'PUT');

        $result = $this->controller()->actionUpdate((int)$schedule->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Invalid timezone identifier.']], $result);
        $this->assertSame($stored, $this->storedRow($schedule));
    }

    /**
     * Regression: DateTimeZone throws a ValueError for a timezone with a NUL
     * byte, which got past both catch blocks (500).
     */
    public function testCreateWithANulByteInTheTimezoneIs422(): void
    {
        $scope = $this->teamScope();
        $this->authenticate($scope['member']);
        $name = 'api-tz-nul-' . uniqid('', true);
        $this->sendJson([
            'name' => $name,
            'job_template_id' => (int)$scope['own']->id,
            'cron_expression' => '0 3 * * *',
            'timezone' => "UTC\0x",
        ]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Invalid timezone identifier.']], $result);
        $this->assertNull(Schedule::findOne(['name' => $name]));
    }

    public function testUpdateWithANulByteInTheTimezoneIs422AndChangesNothing(): void
    {
        $scope = $this->teamScope();
        $this->authenticate($scope['member']);
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $stored = $this->storedRow($schedule);
        $this->sendJson(['timezone' => "UTC\0x"], 'PUT');

        $result = $this->controller()->actionUpdate((int)$schedule->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Invalid timezone identifier.']], $result);
        $this->assertSame($stored, $this->storedRow($schedule));
    }

    /**
     * Regression: the next run was computed before the 64-character rule
     * ran, and the cron parser's cost grows quadratically with the length:
     * this 300 KB expression took about 35 s before the 422.
     */
    public function testCreateWithAVeryLongCronExpressionIs422WithoutParsingIt(): void
    {
        $scope = $this->teamScope();
        $this->authenticate($scope['member']);
        $name = 'api-long-cron-' . uniqid('', true);
        $this->sendJson([
            'name' => $name,
            'job_template_id' => (int)$scope['own']->id,
            'cron_expression' => ScheduleControllerTimezoneTest::veryLongCronExpression(),
        ]);

        $started = microtime(true);
        $result = $this->controller()->actionCreate();
        $elapsed = microtime(true) - $started;

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Cron Expression should contain at most 64 characters.']], $result);
        $this->assertNull(Schedule::findOne(['name' => $name]));
        $this->assertLessThan(5.0, $elapsed, 'The long expression must not reach the cron parser.');
    }

    public function testUpdateWithAVeryLongCronExpressionIs422WithoutParsingIt(): void
    {
        $scope = $this->teamScope();
        $this->authenticate($scope['member']);
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $stored = $this->storedRow($schedule);
        $this->sendJson(['cron_expression' => ScheduleControllerTimezoneTest::veryLongCronExpression()], 'PUT');

        $started = microtime(true);
        $result = $this->controller()->actionUpdate((int)$schedule->id);
        $elapsed = microtime(true) - $started;

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Cron Expression should contain at most 64 characters.']], $result);
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
     * next run, which the API did before validating.
     *
     * @dataProvider negativeStepProvider
     */
    public function testCreateWithANegativeCronStepIs422(string $cron): void
    {
        $scope = $this->teamScope();
        $this->authenticate($scope['member']);
        $name = 'api-negative-step-' . uniqid('', true);
        $this->sendJson([
            'name' => $name,
            'job_template_id' => (int)$scope['own']->id,
            'cron_expression' => $cron,
        ]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::CRON_INVALID]], $result);
        $this->assertNull(Schedule::findOne(['name' => $name]));
    }

    /**
     * @dataProvider negativeStepProvider
     */
    public function testUpdateWithANegativeCronStepIs422AndChangesNothing(string $cron): void
    {
        $scope = $this->teamScope();
        $this->authenticate($scope['member']);
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $stored = $this->storedRow($schedule);
        $this->sendJson(['cron_expression' => $cron], 'PUT');

        $result = $this->controller()->actionUpdate((int)$schedule->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::CRON_INVALID]], $result);
        $this->assertSame($stored, $this->storedRow($schedule));
    }

    private function createSchedule(int $templateId, int $createdBy): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'api-tz-' . uniqid('', true);
        $schedule->job_template_id = $templateId;
        $schedule->cron_expression = '0 3 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->next_run_at = time() + 3600;
        $schedule->created_by = $createdBy;
        $schedule->save(false);
        return $schedule;
    }

    private function controller(): SchedulesController
    {
        return new SchedulesController('api/v1/schedules', \Yii::$app);
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'timezone-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
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
}
