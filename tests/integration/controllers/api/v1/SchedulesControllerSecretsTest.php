<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\SurveyField;
use app\components\SurveySecrets;
use app\controllers\api\v1\SchedulesController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\ScheduleService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * Password answers in schedule extra vars through POST and PUT
 * /api/v1/schedules.
 *
 * Regression: the API stored the answers to password survey fields in
 * plaintext in schedule.extra_vars. Now they are stored sealed; sending the
 * placeholder keeps a stored answer, and a body without extra_vars keeps
 * them all unless it moves the schedule to another template.
 */
class SchedulesControllerSecretsTest extends WebControllerTestCase
{
    use ScheduleAnswerFixtures;
    use TeamScopeFixtures;

    private const ANSWER = 'schedule-api-answer-4420';
    private const NEW_ANSWER = 'schedule-api-rotated-9907';

    private SchedulesController $ctrl;
    private User $user;
    private JobTemplate $template;
    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'schedule-api-test-key-0123456789';
        $this->ctrl = new SchedulesController('api/v1/schedules', \Yii::$app);
        $this->user = $this->createUserWithRole('schedule-api', 'operator');
        $this->authenticate($this->user);
        $this->template = $this->createPasswordSurveyTemplate((int)$this->user->id);
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

    public function testCreateStoresTheAnswerOnlySealed(): void
    {
        $this->setBody($this->body('create') + ['extra_vars' => '{"app_env":"prod","db_password":"' . self::ANSWER . '"}']);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode($result));
        $schedule = $this->scheduleNamed('create');
        $this->assertSame([self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], $this->storedExtraVarsOf($schedule));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
        $audit = $this->auditOf(AuditLog::ACTION_SCHEDULE_CREATED, $schedule);
        $this->assertSame('api', $audit['source']);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $audit['secret_keys_added']);
    }

    public function testThePlaceholderWithoutAStoredAnswerIs422(): void
    {
        $this->setBody($this->body('no-answer') + ['extra_vars' => '{"db_password":"$encrypted$"}']);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => '"db_password" has no stored value; enter the real value.']], $result);
        $this->assertNull(Schedule::findOne(['name' => $this->scheduleName('no-answer')]));
    }

    /**
     * The round trip: the extra vars as the schedule page shows them, sent
     * back, keep the stored answer.
     */
    public function testSendingThePlaceholderKeepsTheStoredAnswer(): void
    {
        $schedule = $this->storedSchedule();
        $this->setBody(['extra_vars' => (string)$schedule->extra_vars, 'cron_expression' => '0 5 * * *']);

        $this->ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame('0 5 * * *', $this->reloadSchedule($schedule)->cron_expression);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $this->auditOf(AuditLog::ACTION_SCHEDULE_UPDATED, $schedule)['secret_keys_kept']);
    }

    public function testANewValueReplacesTheStoredAnswer(): void
    {
        $schedule = $this->storedSchedule();
        $this->setBody(['extra_vars' => '{"db_password":"' . self::NEW_ANSWER . '"}']);

        $this->ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::NEW_ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], $this->storedExtraVarsOf($schedule));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function partialUpdateProvider(): array
    {
        return [
            'disable' => [['enabled' => false]],
            'rename' => [['name' => 'renamed-schedule']],
            'new cron expression' => [['cron_expression' => '15 4 * * *']],
            'the same template' => [['job_template_id' => 0]],
        ];
    }

    /**
     * A body without extra_vars keeps the extra vars and the answers.
     *
     * @dataProvider partialUpdateProvider
     * @param array<string, mixed> $body
     */
    public function testAnUpdateWithoutExtraVarsKeepsTheAnswers(array $body): void
    {
        $schedule = $this->storedSchedule();
        if (array_key_exists('job_template_id', $body)) {
            $body['job_template_id'] = (int)$this->template->id;
        }
        $this->setBody($body);

        $this->ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame($schedule->extra_vars, $this->reloadSchedule($schedule)->extra_vars);
    }

    public function testExtraVarsNullClearsTheAnswers(): void
    {
        $schedule = $this->storedSchedule();
        $this->setBody(['extra_vars' => null]);

        $this->ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $stored = $this->reloadSchedule($schedule);
        $this->assertNull($stored->extra_vars);
        $this->assertNull($stored->getSealedSecretExtraVars());
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $this->auditOf(AuditLog::ACTION_SCHEDULE_UPDATED, $schedule)['secret_keys_dropped']);
    }

    /**
     * Stored answers do not follow the schedule to another template.
     */
    public function testATemplateChangeWithoutExtraVarsIs422WhenAnswersAreStored(): void
    {
        $schedule = $this->storedSchedule();
        $other = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $this->setBody(['job_template_id' => (int)$other->id]);

        $result = $this->ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'The job template changed: send extra_vars with the password answers again.']], $result);
        $this->assertSame((int)$this->template->id, (int)$this->reloadSchedule($schedule)->job_template_id);
    }

    public function testATemplateChangeWithThePlaceholderIs422(): void
    {
        $schedule = $this->storedSchedule();
        $other = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $this->setBody(['job_template_id' => (int)$other->id, 'extra_vars' => (string)$schedule->extra_vars]);

        $result = $this->ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => '"db_password": the job template changed; enter the real value.']], $result);
    }

    public function testATemplateChangeWithTheAnswersEnteredAgainMovesThem(): void
    {
        $schedule = $this->storedSchedule();
        $other = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $this->setBody(['job_template_id' => (int)$other->id, 'extra_vars' => '{"db_password":"' . self::NEW_ANSWER . '"}']);

        $this->ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::NEW_ANSWER], $this->sealedAnswersOf($schedule));
        $audit = $this->auditOf(AuditLog::ACTION_SCHEDULE_UPDATED, $schedule);
        $this->assertSame((int)$other->id, $audit['job_template_id']);
        $this->assertSame((int)$this->template->id, $audit['previous_job_template_id']);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD], $audit['secret_keys_replaced']);
    }

    /**
     * A template change without extra_vars and without stored answers seals
     * the values of the new template's password fields.
     */
    public function testATemplateChangeWithoutExtraVarsSealsTheNewTemplatesAnswers(): void
    {
        $plain = $this->createJobTemplate(
            (int)$this->createProject((int)$this->user->id)->id,
            (int)$this->createInventory((int)$this->user->id)->id,
            (int)$this->createRunnerGroup((int)$this->user->id)->id,
            (int)$this->user->id
        );
        $schedule = $this->createPlainSchedule((int)$plain->id, (int)$this->user->id, '{"db_password":"' . self::ANSWER . '"}');
        $this->setBody(['job_template_id' => (int)$this->template->id]);

        $this->ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertStringNotContainsString(self::ANSWER, (string)$this->reloadSchedule($schedule)->extra_vars);
    }

    /**
     * The answers are checked only once the template is one the caller may
     * use: an error naming a password field of another team's template
     * would reveal its survey.
     */
    public function testAnotherTeamsTemplateGetsOnlyTheTemplateError(): void
    {
        $scope = $this->teamScope();
        $this->authenticate($scope['member']);
        $foreign = $scope['foreign'];
        $foreign->survey_fields = (string)json_encode([['name' => 'their_secret', 'label' => 'S', 'type' => SurveyField::TYPE_PASSWORD]]);
        $foreign->save(false);
        $this->setBody(['name' => 'api-foreign', 'job_template_id' => (int)$foreign->id, 'cron_expression' => '0 2 * * *', 'extra_vars' => '{"their_secret":"$encrypted$"}']);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => ScheduleService::TEMPLATE_MISSING_MESSAGE]], $result);
    }

    /**
     * Only server code sets the sealed answers: a request body cannot.
     */
    public function testASentSealedValueIsIgnored(): void
    {
        $this->setBody($this->body('forged') + [
            'extra_vars' => '{"app_env":"prod"}',
            'secret_extra_vars' => SurveySecrets::seal([self::SURVEY_PASSWORD_FIELD => 'forged']),
        ]);

        $this->ctrl->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertNull($this->scheduleNamed('forged')->getSealedSecretExtraVars());
    }

    // -- Helpers ----------------------------------------------------------------

    private function storedSchedule(): Schedule
    {
        return $this->createScheduleWithAnswers((int)$this->template->id, (int)$this->user->id, [self::SURVEY_PASSWORD_FIELD => self::ANSWER]);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $suffix): array
    {
        return [
            'name' => $this->scheduleName($suffix),
            'job_template_id' => (int)$this->template->id,
            'cron_expression' => '0 3 * * *',
            'timezone' => 'UTC',
            'enabled' => true,
        ];
    }

    private function scheduleName(string $suffix): string
    {
        return 'schedule-api-' . $suffix . '-' . $this->user->id;
    }

    private function scheduleNamed(string $suffix): Schedule
    {
        $schedule = Schedule::findOne(['name' => $this->scheduleName($suffix)]);
        $this->assertNotNull($schedule);

        return $schedule;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditOf(string $action, Schedule $schedule): array
    {
        /** @var AuditLog|null $entry */
        $entry = AuditLog::find()->where(['action' => $action, 'object_type' => 'schedule', 'object_id' => $schedule->id])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($entry);
        $metadata = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($metadata);

        return $metadata;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'schedule-secrets');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }
}
