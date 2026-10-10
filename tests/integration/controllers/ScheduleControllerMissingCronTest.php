<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ScheduleController;
use app\models\Schedule;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * Regression: the schedule form computes the next run before it validates,
 * and CronExpression only takes a string. A create request that does not
 * post Schedule[cron_expression] at all failed with a TypeError (500)
 * instead of showing the form with the required error.
 */
class ScheduleControllerMissingCronTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    public function testCreateWithoutTheCronFieldShowsTheFormWithTheRequiredError(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $name = 'test-schedule-no-cron-' . uniqid('', true);
        $this->setPost(['Schedule' => [
            'name' => $name,
            'job_template_id' => (string)$scope['own']->id,
            'timezone' => 'UTC',
            'enabled' => '1',
        ]]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(Schedule::class, $model);
        $label = $model->getAttributeLabel('cron_expression');
        $this->assertSame(['cron_expression' => [$label . ' cannot be blank.']], $model->getErrors());
        $this->assertNull($model->next_run_at);
        $this->assertNull(Schedule::findOne(['name' => $name]));
    }

    public function testCreateWithACronFieldThatIsNotTextShowsTheFormWithAnError(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $name = 'test-schedule-cron-array-' . uniqid('', true);
        $this->setPost(['Schedule' => [
            'name' => $name,
            'job_template_id' => (string)$scope['own']->id,
            'cron_expression' => ['0 2 * * *'],
            'timezone' => 'UTC',
        ]]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionCreate();

        $this->assertSame('rendered:form', $result);
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(Schedule::class, $model);
        $label = $model->getAttributeLabel('cron_expression');
        $this->assertSame(['cron_expression' => [$label . ' must be a string.']], $model->getErrors());
        $this->assertNull($model->next_run_at);
        $this->assertNull(Schedule::findOne(['name' => $name]));
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
