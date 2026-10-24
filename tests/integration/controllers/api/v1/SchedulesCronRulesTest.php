<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\CronExpressionRules;
use app\controllers\api\v1\SchedulesController;
use app\models\ApiToken;
use app\models\Schedule;
use app\models\User;
use app\tests\integration\controllers\ScheduleControllerCronRulesTest;
use app\tests\integration\controllers\SendsJsonBody;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Regression: POST and PUT /api/v1/schedules saved a cron expression that
 * the cron library accepts but never runs, or runs at other times than it
 * says, such as "5-1 * * * *" (CronExpressionRules). They answer 422 with
 * the reason and write nothing (ScheduleControllerCronRulesTest for the
 * form).
 */
class SchedulesCronRulesTest extends WebControllerTestCase
{
    use SendsJsonBody;
    use TeamScopeFixtures;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedProvider(): array
    {
        return ScheduleControllerCronRulesTest::refusedProvider();
    }

    /**
     * @dataProvider refusedProvider
     */
    public function testCreateIs422WithTheReason(string $cron, string $reason): void
    {
        $scope = $this->teamScope();
        $this->authenticate($scope['member']);
        $name = 'api-cron-' . uniqid('', true);
        $this->sendJson(['name' => $name, 'job_template_id' => (int)$scope['own']->id, 'cron_expression' => $cron]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $reason]], $result);
        $this->assertNull(Schedule::findOne(['name' => $name]));
    }

    /**
     * @dataProvider refusedProvider
     */
    public function testUpdateIs422WithTheReasonAndChangesNothing(string $cron, string $reason): void
    {
        $scope = $this->teamScope();
        $this->authenticate($scope['member']);
        $schedule = new Schedule();
        $schedule->name = 'api-cron-' . uniqid('', true);
        $schedule->job_template_id = (int)$scope['own']->id;
        $schedule->cron_expression = '0 3 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->next_run_at = time() + 3600;
        $schedule->created_by = (int)$scope['member']->id;
        $schedule->save(false);
        $stored = Schedule::findOne($schedule->id)?->getAttributes();
        $this->sendJson(['cron_expression' => $cron], 'PUT');

        $result = $this->controller()->actionUpdate((int)$schedule->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $reason]], $result);
        $this->assertSame($stored, Schedule::findOne($schedule->id)?->getAttributes());
    }

    private function controller(): SchedulesController
    {
        return new SchedulesController('api/v1/schedules', \Yii::$app);
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'cron-rules-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }
}
