<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ScheduleController;
use app\models\AuditLog;
use app\models\Schedule;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\web\Response;

/**
 * Regression: a rejected schedule form is shown again with what was posted,
 * and its fields render their values as text. The rules refused a list that
 * a crafted form posted for a field (Schedule[timezone][]=x), but rendering
 * the list failed with "Array to string conversion" (500). The earlier tests
 * of a list cron expression and timezone replaced the view, so they never saw
 * it; these run each action through the real form view.
 */
class ScheduleControllerListValueTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> the field, the ID of its input, and its error
     */
    public static function fieldProvider(): array
    {
        return [
            'name' => ['name', 'schedule-name', 'Name must be a string.'],
            'job template' => ['job_template_id', 'schedule-job_template_id', 'Job Template Id must be an integer.'],
            'cron expression' => ['cron_expression', 'cron-input', 'Cron Expression must be a string.'],
            'timezone' => ['timezone', 'schedule-timezone', 'Timezone must be a string.'],
            'extra vars' => ['extra_vars', 'schedule-extra_vars', 'Extra Vars must be a string.'],
            'enabled' => ['enabled', 'schedule-enabled', 'Enabled must be either "1" or "0".'],
        ];
    }

    /**
     * @dataProvider fieldProvider
     */
    public function testCreateShowsTheFormWithTheErrorOfAList(string $attribute, string $inputId, string $error): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $schedules = Schedule::find()->count();
        $fields = [
            'name' => 'test-schedule-list-' . uniqid('', true),
            'job_template_id' => (string)$scope['own']->id,
            'cron_expression' => '0 2 * * *',
            'timezone' => 'Europe/Berlin',
            'extra_vars' => '{"env": "staging"}',
            'enabled' => '1',
        ];
        $fields[$attribute] = ['x'];
        $this->setPost(['Schedule' => $fields]);

        $ctrl = $this->makeController();
        $html = $this->withRealViews($ctrl, '/schedule/create', fn (): Response|string => $ctrl->actionCreate());

        $this->assertShowsOnlyTheError($html, $inputId, $error);
        $this->assertSame($schedules, Schedule::find()->count());
        $this->assertFalse(AuditLog::find()->where(['action' => AuditLog::ACTION_SCHEDULE_CREATED, 'user_id' => $scope['member']->id])->exists());
    }

    /**
     * @dataProvider fieldProvider
     */
    public function testUpdateShowsTheFormWithTheErrorOfAListAndChangesNothing(string $attribute, string $inputId, string $error): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $schedule = $this->createSchedule((int)$scope['own']->id, (int)$scope['member']->id);
        $stored = $this->storedRow((int)$schedule->id);
        $this->setPost(['Schedule' => [$attribute => ['x']]]);

        $ctrl = $this->makeController();
        $url = '/schedule/update?id=' . $schedule->id;
        $html = $this->withRealViews($ctrl, $url, fn (): Response|string => $ctrl->actionUpdate((int)$schedule->id));

        $this->assertShowsOnlyTheError($html, $inputId, $error);
        $this->assertSame($stored, $this->storedRow((int)$schedule->id));
        $this->assertFalse(AuditLog::find()->where(['action' => AuditLog::ACTION_SCHEDULE_UPDATED, 'object_id' => $schedule->id])->exists());
    }

    /**
     * The form is shown again: the field of $inputId is marked as failed and
     * shows $error, and no other field is.
     */
    private function assertShowsOnlyTheError(Response|string $html, string $inputId, string $error): void
    {
        $this->assertIsString($html, 'the form is shown again');
        $failed = '/<div class="form-group field-([\w-]+)[^"]*has-error">/';
        $this->assertSame(1, preg_match_all($failed, $html, $fields), 'one field is marked as failed');
        $this->assertSame([$inputId], $fields[1]);
        $this->assertStringContainsString('<div class="help-block">' . Html::encode($error) . '</div>', $html);
    }

    private function createSchedule(int $templateId, int $createdBy): Schedule
    {
        $s = new Schedule();
        $s->name = 'test-schedule-' . uniqid('', true);
        $s->job_template_id = $templateId;
        $s->cron_expression = '0 2 * * *';
        $s->timezone = 'Europe/Berlin';
        $s->extra_vars = '{"env": "staging"}';
        $s->enabled = true;
        $s->next_run_at = time() + 3600;
        $s->created_by = $createdBy;
        $s->save(false);
        return $s;
    }

    /**
     * @return array<string, mixed> the schedule's row as stored
     */
    private function storedRow(int $id): array
    {
        $stored = Schedule::findOne($id);
        $this->assertNotNull($stored);
        return $stored->getAttributes();
    }

    /**
     * The controller with the real form view, without the layout.
     */
    private function makeController(): ScheduleController
    {
        return new class ('schedule', \Yii::$app) extends ScheduleController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
    }
}
