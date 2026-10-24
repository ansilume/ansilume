<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\CronExpressionRules;
use app\controllers\ScheduleController;
use app\models\Schedule;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * Regression: the schedule form saved a cron expression that the cron
 * library accepts but never runs, or runs at other times than it says, such
 * as "5-1 * * * *" (CronExpressionRules). The form shows the reason and
 * stores nothing (SchedulesCronRulesTest for the API).
 */
class ScheduleControllerCronRulesTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'a range that runs backwards' => ['5-1 * * * *', sprintf(CronExpressionRules::RANGE_BACKWARDS, '5-1', 'minute', '0-1,5-59')],
            'a weekday range ending with Sunday as 0' => ['0 0 * * 5-0', sprintf(CronExpressionRules::SUNDAY_ZERO_LAST, '5-0', '5-7')],
            'a long list out of order' => ['*/2,5 * * * *', sprintf(CronExpressionRules::LIST_ORDER, '*/2,5', 'minute', '0,2,4,5,6-58/2')],
            'a backwards range with a step' => ['50-10/5 * * * *', sprintf(CronExpressionRules::RANGE_BACKWARDS, '50-10/5', 'minute', '0-10/5,50-59/5')],
            'an asterisk in a minute list' => ['*,5 * * * *', sprintf(CronExpressionRules::ASTERISK_IN_LIST, '*,5')],
            'a step that wraps around' => ['*/90 * * * *', sprintf(CronExpressionRules::STEP_TOO_LARGE, '/90', 'minute', 59)],
            'a date that never comes' => ['0 0 31 2 *', CronExpressionRules::NEVER_RUNS],
        ];
    }

    /**
     * @dataProvider refusedProvider
     */
    public function testCreateShowsTheReasonAndStoresNothing(string $cron, string $reason): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $name = 'test-schedule-cron-' . uniqid('', true);
        $this->setPost(['Schedule' => [
            'name' => $name,
            'job_template_id' => (string)$scope['own']->id,
            'cron_expression' => $cron,
            'timezone' => 'UTC',
            'enabled' => '1',
        ]]);

        $ctrl = $this->makeController();

        $this->assertSame('rendered:form', $ctrl->actionCreate());
        $this->assertSame(['cron_expression' => [$reason]], $this->renderedModel($ctrl)->getErrors());
        $this->assertNull(Schedule::findOne(['name' => $name]));
    }

    /**
     * @dataProvider refusedProvider
     */
    public function testUpdateShowsTheReasonAndChangesNothing(string $cron, string $reason): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $stored = Schedule::findOne($schedule->id)?->getAttributes();
        $this->setPost(['Schedule' => ['cron_expression' => $cron]]);

        $ctrl = $this->makeController();

        $this->assertSame('rendered:form', $ctrl->actionUpdate((int)$schedule->id));
        $this->assertSame(['cron_expression' => [$reason]], $this->renderedModel($ctrl)->getErrors());
        $this->assertSame($stored, Schedule::findOne($schedule->id)?->getAttributes());
    }

    public function testAnExpressionThatRunsAsItSaysIsSavedWithItsNextRun(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $name = 'test-schedule-cron-' . uniqid('', true);
        $this->setPost(['Schedule' => [
            'name' => $name,
            'job_template_id' => (string)$scope['own']->id,
            'cron_expression' => '0 0 29 2 *',
            'timezone' => 'UTC',
            'enabled' => '1',
        ]]);

        $this->assertSame('redirected', $this->makeController()->actionCreate()->content);

        $saved = Schedule::findOne(['name' => $name]);
        $this->assertNotNull($saved);
        $this->assertSame('02-29', gmdate('m-d', (int)$saved->next_run_at));
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

    private function renderedModel(ScheduleController $ctrl): Schedule
    {
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(Schedule::class, $model);
        return $model;
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
