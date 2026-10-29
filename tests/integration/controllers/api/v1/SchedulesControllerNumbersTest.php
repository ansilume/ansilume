<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\SchedulesController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\tests\integration\controllers\SendsJsonNumbers;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The job_template_id of POST and PUT /api/v1/schedules. A value that is no
 * job template ID, such as a JSON number beyond the int range, a fraction, a
 * boolean or text, is left to the model's rules, as the web form leaves it:
 * 422 with the model's error, before the template's access is checked and
 * before anything is written; nothing is logged as a failure. A template ID,
 * also as digits, is checked and taken as before. The bodies are JSON texts,
 * decoded as the request's JSON parser decodes them (SendsJsonNumbers).
 *
 * Regression: a JSON number beyond the int range answered 500 on PHP 8.5:
 * the (int) cast of the controller warned "The float 1.0E+30 is not
 * representable as an int, cast occurred", and the error handler turned the
 * warning into an exception. Up to PHP 8.4 the cast wrapped it to an
 * arbitrary template ID ("The selected job template does not exist."), took
 * a fraction as the template of its whole part and true as template 1.
 */
class SchedulesControllerNumbersTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    /** The model's error for a job_template_id its integer rule refuses. */
    private const NOT_A_TEMPLATE_ID = 'Job Template Id must be an integer.';

    private const CREATED_NAME = 'numbers-created-schedule';

    private User $operator;

    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = $this->createUserWithRole('numbers-schedule', 'operator');
        $this->template = $this->templateOf((int)$this->operator->id);
        ['raw' => $raw] = ApiToken::generate((int)$this->operator->id, 'numbers-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testACreateWithATemplateIdBeyondTheIntRangeIsRefused(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $body = $this->sendJsonText($this->createBody($number));
        $this->assertIsFloat($body['job_template_id'], 'the parser gives a float');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('create'));

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::NOT_A_TEMPLATE_ID]], $result);
        $this->assertNull(Schedule::findOne(['name' => self::CREATED_NAME]), 'no schedule was created');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAnUpdateWithATemplateIdBeyondTheIntRangeIsRefused(string $number): void
    {
        $schedule = $this->scheduleOf((int)$this->template->id);
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"name": "numbers-renamed-schedule", "job_template_id": ' . $number . '}', 'PUT');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('update', ['id' => $schedule->id]));

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::NOT_A_TEMPLATE_ID]], $result);
        $stored = Schedule::findOne($schedule->id);
        $this->assertNotNull($stored);
        $this->assertSame((int)$this->template->id, $stored->job_template_id, 'the schedule keeps its template');
        $this->assertSame($schedule->name, $stored->name, 'nothing was saved');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Other values that are no template ID, and the model's error for each;
     * %d is the ID of a template the caller may schedule. Up to now a
     * fraction, a space or an exponent was taken as that template, true as
     * template 1, and text, false, null and "" were looked up as template 0
     * ("The selected job template does not exist.").
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noTemplateIdProvider(): array
    {
        return [
            'fraction' => ['%d.5', self::NOT_A_TEMPLATE_ID],
            'decimal text' => ['"%d.5"', self::NOT_A_TEMPLATE_ID],
            'digits after a space' => ['" %d"', self::NOT_A_TEMPLATE_ID],
            'exponent text' => ['"%de0"', self::NOT_A_TEMPLATE_ID],
            'text' => ['"abc"', self::NOT_A_TEMPLATE_ID],
            'true' => ['true', self::NOT_A_TEMPLATE_ID],
            'false' => ['false', self::NOT_A_TEMPLATE_ID],
            'null' => ['null', 'Job Template Id cannot be blank.'],
            'empty text' => ['""', 'Job Template Id cannot be blank.'],
        ];
    }

    /**
     * @dataProvider noTemplateIdProvider
     */
    public function testAnUpdateWithAValueThatIsNoTemplateIdIsRefused(string $value, string $error): void
    {
        $schedule = $this->scheduleOf((int)$this->template->id);
        $other = $this->templateOf((int)$this->operator->id);
        $this->sendJsonText('{"job_template_id": ' . sprintf($value, $other->id) . '}', 'PUT');

        [$result, $problems] = $this->logged(fn (): mixed => $this->api()->runAction('update', ['id' => $schedule->id]));

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $error]], $result);
        $this->assertSame((int)$this->template->id, Schedule::findOne($schedule->id)?->job_template_id, 'the schedule keeps its template');
        $this->assertSame([], $problems);
    }

    /**
     * What is taken as before; %d is the template's ID.
     *
     * @return array<string, array{0: string}>
     */
    public static function templateIdProvider(): array
    {
        return [
            'the ID' => ['%d'],
            'the ID as digits' => ['"%d"'],
            'the ID with a leading zero' => ['"0%d"'],
            'the ID with a plus' => ['"+%d"'],
            'the ID as a whole float' => ['%d.0'],
        ];
    }

    /**
     * @dataProvider templateIdProvider
     */
    public function testACreateTakesATemplateIdAsBefore(string $value): void
    {
        $this->sendJsonText($this->createBody(sprintf($value, $this->template->id)));

        $result = $this->api()->runAction('create');

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertIsArray($result);
        $this->assertSame((int)$this->template->id, $result['data']['job_template_id'] ?? null);
        $this->assertSame((int)$this->template->id, Schedule::findOne(['name' => self::CREATED_NAME])?->job_template_id);
    }

    /**
     * The template's access is checked as before: an unknown ID is reported
     * like a template the caller cannot see.
     */
    public function testAnUnknownTemplateIdIsReportedAsBefore(): void
    {
        $schedule = $this->scheduleOf((int)$this->template->id);
        $this->sendJsonText('{"job_template_id": 2147483647}', 'PUT');

        $result = $this->api()->runAction('update', ['id' => $schedule->id]);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'The selected job template does not exist.']], $result);
    }

    public function testAnUpdateMovesAScheduleToAnotherTemplateAsBefore(): void
    {
        $schedule = $this->scheduleOf((int)$this->template->id);
        $other = $this->templateOf((int)$this->operator->id);
        $this->sendJsonText('{"job_template_id": ' . $other->id . '}', 'PUT');

        $result = $this->api()->runAction('update', ['id' => $schedule->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertIsArray($result);
        $this->assertSame((int)$other->id, $result['data']['job_template_id'] ?? null);
        $this->assertSame((int)$other->id, Schedule::findOne($schedule->id)?->job_template_id);
    }

    // -- Helpers ------------------------------------------------------------------

    private function createBody(string $templateId): string
    {
        return '{"name": "' . self::CREATED_NAME . '", "job_template_id": ' . $templateId
            . ', "cron_expression": "0 3 * * *", "timezone": "UTC"}';
    }

    /**
     * A job template of a project no team restricts, which every operator
     * may schedule.
     */
    private function templateOf(int $userId): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    private function scheduleOf(int $templateId): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'numbers-schedule-' . uniqid('', true);
        $schedule->job_template_id = $templateId;
        $schedule->cron_expression = '0 * * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = (int)$this->operator->id;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $schedule->save(false);

        return $schedule;
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    private function api(): SchedulesController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new SchedulesController('api/v1/schedules', $app);
    }
}
