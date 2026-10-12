<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\SurveyField;
use app\components\SurveySecrets;
use app\controllers\api\v1\SchedulesController;
use app\models\ApiToken;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;

/**
 * The extra vars of a schedule in the responses of /api/v1/schedules.
 *
 * Regression (DOC-3): no response carried a schedule's extra vars, so an API
 * client could neither read them nor learn which password answers were
 * stored, and the placeholder round trip the spec described worked only in
 * the web form. Every response now has `extra_vars` as the edit form shows
 * them: `$encrypted$` for every password answer, a plain answer (the value
 * of a key that became a password field after the schedule was saved)
 * included, and null when there are none. The stored column is never
 * returned as such.
 */
class SchedulesControllerExtraVarsTest extends WebControllerTestCase
{
    use ScheduleAnswerFixtures;
    use TeamScopeFixtures;

    private const ANSWER = 'schedule-api-read-marker-6021';
    private const PLAIN_ANSWER = 'schedule-api-plain-marker-3307';
    private const FOREIGN_MARKER = 'schedule-api-foreign-marker-1194';

    private SchedulesController $ctrl;
    private User $user;
    private JobTemplate $template;
    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'schedule-api-read-test-key-0123456789';
        $this->ctrl = new SchedulesController('api/v1/schedules', \Yii::$app);
        $this->user = $this->createUserWithRole('schedule-api-read', 'operator');
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

    /**
     * A password answer appears only as the placeholder, in the response to
     * every action.
     */
    public function testEveryResponseShowsAPasswordAnswerOnlyAsThePlaceholder(): void
    {
        $this->setBody([
            'name' => 'api-read-' . $this->user->id,
            'job_template_id' => (int)$this->template->id,
            'cron_expression' => '0 3 * * *',
            'extra_vars' => '{"app_env":"prod","db_password":"' . self::ANSWER . '"}',
        ]);
        $created = $this->ctrl->actionCreate();
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $id = (int)$this->dataOf($created)['id'];
        $this->setBody(['cron_expression' => '0 4 * * *']);

        $responses = [
            'create' => $created,
            'view' => $this->ctrl->actionView($id),
            'list' => ['data' => $this->listed($id)],
            'update' => $this->ctrl->actionUpdate($id),
            'toggle' => $this->ctrl->actionToggle($id),
        ];

        foreach ($responses as $action => $response) {
            $this->assertSame(
                '{"app_env":"prod","db_password":"$encrypted$"}',
                $this->extraVarsOf($response),
                "{$action}: the extra vars as stored, with the placeholder"
            );
        }
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode($responses));
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($this->scheduleWithId($id)));
    }

    /**
     * A plain answer, which the stored column still holds in plain text, is
     * masked too.
     */
    public function testAPlainAnswerIsMasked(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, (int)$this->user->id, '{"app_env":"prod","db_password":"' . self::PLAIN_ANSWER . '"}');
        $expected = [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER];

        $responses = [
            'view' => $this->ctrl->actionView((int)$schedule->id),
            'list' => ['data' => $this->listed((int)$schedule->id)],
            'toggle' => $this->ctrl->actionToggle((int)$schedule->id),
        ];

        foreach ($responses as $action => $response) {
            $this->assertSame($expected, $this->decodedExtraVars($response), $action);
        }
        $this->assertStringNotContainsString(self::PLAIN_ANSWER, (string)json_encode($responses));
        $this->assertStringContainsString(self::PLAIN_ANSWER, (string)$this->reloadSchedule($schedule)->extra_vars, 'reading seals nothing');
    }

    /**
     * The round trip the spec describes: the extra vars a GET returned, sent
     * back unchanged, keep every answer, sealed and plain.
     */
    public function testAGetThenAPutOfTheReturnedExtraVarsKeepsEveryAnswer(): void
    {
        $template = $this->templateWithPasswordFields((int)$this->user->id, [self::SURVEY_PASSWORD_FIELD, 'api_token']);
        $schedule = $this->createScheduleWithAnswers(
            (int)$template->id,
            (int)$this->user->id,
            [self::SURVEY_PASSWORD_FIELD => self::ANSWER],
            ['app_env' => 'prod', 'api_token' => self::PLAIN_ANSWER]
        );
        $read = $this->extraVarsOf($this->ctrl->actionView((int)$schedule->id));
        $this->assertIsString($read);
        $this->setBody(['extra_vars' => $read]);

        $updated = $this->ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($updated));
        $answers = $this->sealedAnswersOf($schedule);
        ksort($answers);
        $this->assertSame(['api_token' => self::PLAIN_ANSWER, self::SURVEY_PASSWORD_FIELD => self::ANSWER], $answers);
        $placeholders = ['app_env' => 'prod', 'api_token' => SurveySecrets::PLACEHOLDER, self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER];
        $this->assertSame($placeholders, $this->storedExtraVarsOf($schedule));
        $this->assertSame($placeholders, $this->decodedExtraVars($updated));
        $this->assertStringNotContainsString(self::PLAIN_ANSWER, (string)json_encode($updated));
    }

    /**
     * A caller whose team may only view the template's project gets the
     * masked extra vars, and still may not change the schedule.
     */
    public function testACallerWhoMayOnlyViewTheScheduleGetsTheMaskedExtraVars(): void
    {
        $scope = $this->teamScope();
        $viewed = $this->withPasswordField($scope['viewed']);
        $schedule = $this->createScheduleWithAnswers((int)$viewed->id, (int)$scope['admin']->id, [self::SURVEY_PASSWORD_FIELD => self::ANSWER]);
        $this->authenticate($scope['member']);

        $view = $this->ctrl->actionView((int)$schedule->id);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $listed = $this->listed((int)$schedule->id);
        $this->setBody(['extra_vars' => '{"app_env":"prod"}']);
        $update = $this->ctrl->actionUpdate((int)$schedule->id);

        $expected = ['app_env' => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER];
        $this->assertSame($expected, $this->decodedExtraVars($view));
        $this->assertSame($expected, $this->decodedExtraVars(['data' => $listed]));
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode([$view, $listed]));
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $update);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], $this->sealedAnswersOf($schedule));
    }

    /**
     * Team scoping is unchanged: another team's schedule, and with it its
     * extra vars, is neither shown nor listed.
     */
    public function testAnotherTeamsScheduleAndItsExtraVarsStayHidden(): void
    {
        $scope = $this->teamScope();
        $foreign = $this->withPasswordField($scope['foreign']);
        $schedule = $this->createScheduleWithAnswers(
            (int)$foreign->id,
            (int)$scope['outsider']->id,
            [self::SURVEY_PASSWORD_FIELD => self::ANSWER],
            ['marker' => self::FOREIGN_MARKER]
        );
        $own = $this->createPlainSchedule((int)$scope['own']->id, (int)$scope['member']->id, '{"app_env":"own"}');
        $this->authenticate($scope['member']);

        $view = $this->ctrl->actionView((int)$schedule->id);
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $list = $this->ctrl->actionIndex();

        $this->assertSame(['error' => ['message' => 'Forbidden.']], $view);
        $ids = array_map('intval', array_column($list['data'], 'id'));
        $this->assertNotContains((int)$schedule->id, $ids);
        $this->assertContains((int)$own->id, $ids);
        $this->assertStringNotContainsString(self::FOREIGN_MARKER, (string)json_encode($list));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function noExtraVarsProvider(): array
    {
        return ['none' => [null], 'empty' => ['']];
    }

    /**
     * @dataProvider noExtraVarsProvider
     */
    public function testExtraVarsAreNullWhenThereAreNone(?string $stored): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, (int)$this->user->id, $stored);

        $this->assertNull($this->extraVarsOf($this->ctrl->actionView((int)$schedule->id)));
        $this->assertNull($this->extraVarsOf(['data' => $this->listed((int)$schedule->id)]));
    }

    /**
     * Extra vars without answers come back as stored, formatting included:
     * the text the edit form shows.
     */
    public function testExtraVarsWithoutAnswersComeBackAsStored(): void
    {
        $stored = "{\n  \"app_env\": \"prod\",\n  \"note\": \"\$encrypted\$ is just text here\"\n}";
        $schedule = $this->createPlainSchedule((int)$this->template->id, (int)$this->user->id, $stored);

        $this->assertSame($stored, $this->extraVarsOf($this->ctrl->actionView((int)$schedule->id)));
        $this->assertSame($stored, $this->extraVarsOf(['data' => $this->listed((int)$schedule->id)]));
    }

    /**
     * Masking follows the template, a soft-deleted one included, in a single
     * response and in the list (which loads the templates with the
     * schedules, deleted ones excluded).
     */
    public function testThePlainAnswerOfADeletedTemplatesFieldIsMasked(): void
    {
        $schedule = $this->createPlainSchedule((int)$this->template->id, (int)$this->user->id, '{"db_password":"' . self::PLAIN_ANSWER . '"}');
        $this->template->softDelete();

        $view = $this->ctrl->actionView((int)$schedule->id);
        $listed = $this->listed((int)$schedule->id);

        $expected = [self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER];
        $this->assertSame($expected, $this->decodedExtraVars($view));
        $this->assertSame($expected, $this->decodedExtraVars(['data' => $listed]));
    }

    /**
     * The list loads the job templates of a page with one query, not one
     * per schedule.
     */
    public function testTheListLoadsTheJobTemplatesOfAPageAtOnce(): void
    {
        $first = $this->createPlainSchedule((int)$this->template->id, (int)$this->user->id, '{"db_password":"' . self::PLAIN_ANSWER . '"}');
        $this->ctrl->actionIndex();
        $withOne = $this->queriesOf(fn () => $this->ctrl->actionIndex());
        $more = [];
        for ($i = 0; $i < 3; $i++) {
            $template = $this->createPasswordSurveyTemplate((int)$this->user->id);
            $more[] = $this->createPlainSchedule((int)$template->id, (int)$this->user->id, '{"db_password":"' . self::PLAIN_ANSWER . '"}');
        }

        $withFour = $this->queriesOf(fn () => $this->ctrl->actionIndex());
        $list = $this->ctrl->actionIndex();

        $this->assertSame($withOne, $withFour, 'three more schedules of three more templates, no more queries');
        foreach (array_merge([$first], $more) as $schedule) {
            $this->assertSame([self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER], $this->decodedExtraVars(['data' => $this->itemOf($list, (int)$schedule->id)]));
        }
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * @param array<string, mixed> $response
     * @return array<mixed>
     */
    private function dataOf(array $response): array
    {
        $this->assertArrayHasKey('data', $response, (string)json_encode($response));
        $this->assertIsArray($response['data']);

        return $response['data'];
    }

    /**
     * The extra vars of a response, which every response has.
     *
     * @param array<string, mixed> $response
     */
    private function extraVarsOf(array $response): mixed
    {
        $data = $this->dataOf($response);
        $this->assertArrayHasKey('extra_vars', $data, (string)json_encode($response));

        return $data['extra_vars'];
    }

    /**
     * The extra vars of a response, decoded.
     *
     * @param array<string, mixed> $response
     */
    private function decodedExtraVars(array $response): mixed
    {
        $extraVars = $this->extraVarsOf($response);
        $this->assertIsString($extraVars, (string)json_encode($response));

        return json_decode($extraVars, true);
    }

    /**
     * The list item of schedule $id, from a fresh GET /api/v1/schedules.
     *
     * @return array<mixed>
     */
    private function listed(int $id): array
    {
        return $this->itemOf($this->ctrl->actionIndex(), $id);
    }

    /**
     * @param array<string, mixed> $list
     * @return array<mixed>
     */
    private function itemOf(array $list, int $id): array
    {
        foreach ($this->dataOf($list) as $item) {
            $this->assertIsArray($item);
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }
        $this->fail("Schedule #{$id} is missing from the list.");
    }

    /**
     * The number of queries $call sends.
     */
    private function queriesOf(\Closure $call): int
    {
        $logger = new Logger();
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $call();
        } finally {
            \Yii::setLogger($previous);
        }

        // One info entry per query; with profiling on, each also has a begin and an end entry.
        return count(array_filter(
            $logger->messages,
            static fn (array $m): bool => $m[2] === 'yii\db\Command::query' && $m[1] === Logger::LEVEL_INFO
        ));
    }

    private function scheduleWithId(int $id): Schedule
    {
        $schedule = Schedule::findOne($id);
        $this->assertNotNull($schedule);

        return $schedule;
    }

    /**
     * $template with the password field of createPasswordSurveyTemplate().
     */
    private function withPasswordField(JobTemplate $template): JobTemplate
    {
        $template->survey_fields = (string)json_encode([
            ['name' => self::SURVEY_PASSWORD_FIELD, 'label' => 'Password', 'type' => SurveyField::TYPE_PASSWORD, 'required' => false, 'default' => '', 'options' => [], 'hint' => ''],
        ]);
        $template->save(false);

        return $template;
    }

    /**
     * @param list<string> $names
     */
    private function templateWithPasswordFields(int $createdBy, array $names): JobTemplate
    {
        $template = $this->createPasswordSurveyTemplate($createdBy);
        $fields = [];
        foreach ($names as $name) {
            $fields[] = ['name' => $name, 'label' => $name, 'type' => SurveyField::TYPE_PASSWORD, 'required' => false, 'default' => '', 'options' => [], 'hint' => ''];
        }
        $template->survey_fields = (string)json_encode($fields);
        $template->save(false);

        return $template;
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
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'schedule-extra-vars');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }
}
