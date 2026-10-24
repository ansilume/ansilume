<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\CronExpressionRules;
use app\controllers\api\v1\SchedulesController;
use app\models\ApiToken;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Review follow-up: a schedule that an older version saved with a cron
 * expression the cron rules refuse runs as before, skipped and misplaced
 * runs included, and only schedule/check said so. Every schedule response
 * gives the reason as cron_expression_problem (null for one a save
 * accepts), and an update that keeps such an expression answers 422 with
 * it until the body sends one a save accepts.
 */
class SchedulesCronProblemTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** The list the library walks out of order: it ran at 18:00 only. */
    private const REFUSED = '0 18,6 * * *';

    private SchedulesController $ctrl;

    private User $member;

    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $scope = $this->teamScope();
        $this->member = $scope['member'];
        $this->template = $scope['own'];
        ['raw' => $raw] = ApiToken::generate((int)$this->member->id, 'cron-problem');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        $user->loginByAccessToken($raw);
        $this->ctrl = new SchedulesController('api/v1/schedules', \Yii::$app);
    }

    public function testViewAndIndexGiveTheReasonOfAStoredRefusedExpression(): void
    {
        $refused = $this->storedSchedule(self::REFUSED);
        $accepted = $this->storedSchedule('0 6,18 * * *');
        $reason = sprintf(CronExpressionRules::LIST_ORDER, '18,6', 'hour', '6,18');

        $this->assertSame($reason, $this->dataOf($this->ctrl->actionView((int)$refused->id))['cron_expression_problem']);
        $this->assertNull($this->dataOf($this->ctrl->actionView((int)$accepted->id))['cron_expression_problem']);
        $listed = [];
        $index = $this->ctrl->actionIndex();
        $this->assertIsArray($index['data']);
        foreach ($index['data'] as $item) {
            $this->assertIsArray($item);
            $listed[$item['id']] = $item['cron_expression_problem'];
        }
        $this->assertArrayHasKey($refused->id, $listed);
        $this->assertArrayHasKey($accepted->id, $listed);
        $this->assertSame($reason, $listed[$refused->id]);
        $this->assertNull($listed[$accepted->id]);
    }

    public function testAnUpdateThatKeepsTheExpressionIs422WithTheReason(): void
    {
        $refused = $this->storedSchedule(self::REFUSED);
        $stored = Schedule::findOne($refused->id)?->getAttributes();
        \Yii::$app->request->setBodyParams(['name' => 'renamed-' . uniqid('', true)]);

        $result = $this->ctrl->actionUpdate((int)$refused->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(CronExpressionRules::LIST_ORDER, '18,6', 'hour', '6,18')]], $result);
        $this->assertSame($stored, Schedule::findOne($refused->id)?->getAttributes());
    }

    public function testAnUpdateWithAnAcceptedExpressionClearsTheProblem(): void
    {
        $refused = $this->storedSchedule(self::REFUSED);
        \Yii::$app->request->setBodyParams(['cron_expression' => '0 6,18 * * *']);

        $data = $this->dataOf($this->ctrl->actionUpdate((int)$refused->id));

        $this->assertSame('0 6,18 * * *', $data['cron_expression']);
        $this->assertNull($data['cron_expression_problem']);
    }

    /**
     * Enabling and disabling saves no expression, so it works as before,
     * and the answer still gives the reason.
     */
    public function testToggleAnswersWithTheReason(): void
    {
        $refused = $this->storedSchedule(self::REFUSED);

        $data = $this->dataOf($this->ctrl->actionToggle((int)$refused->id));

        $this->assertFalse($data['enabled']);
        $this->assertSame(sprintf(CronExpressionRules::LIST_ORDER, '18,6', 'hour', '6,18'), $data['cron_expression_problem']);
    }

    /**
     * A schedule as an older version stored it, without the cron rules.
     */
    private function storedSchedule(string $cron): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'cron-problem-' . uniqid('', true);
        $schedule->job_template_id = (int)$this->template->id;
        $schedule->cron_expression = $cron;
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->next_run_at = time() + 3600;
        $schedule->created_by = (int)$this->member->id;
        $schedule->save(false);

        return $schedule;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function dataOf(array $result): array
    {
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        $this->assertIsArray($result['data']);

        return $result['data'];
    }
}
