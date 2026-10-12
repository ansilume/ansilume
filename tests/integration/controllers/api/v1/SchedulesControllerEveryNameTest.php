<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\SurveySecrets;
use app\controllers\api\v1\SchedulesController;
use app\models\ApiToken;
use app\models\Schedule;
use app\models\User;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\ScheduleAnswerFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * /api/v1/schedules with an answer stored under a password field name that
 * cannot carry an answer, as 2.8.1 stored it: every password field name is
 * a secret key. GET shows the placeholder, a PUT of the extra vars a GET
 * returned keeps the answer, now sealed, and a new value replaces it.
 *
 * Regression (R2V2-1): once every name outside printable ASCII was
 * unusable, ordinary letters included ("contraseña_db"), GET returned such
 * an answer in plaintext (extra_vars is new in this release), and a PUT
 * stored it in plaintext again.
 */
class SchedulesControllerEveryNameTest extends WebControllerTestCase
{
    use ScheduleAnswerFixtures;
    use TeamScopeFixtures;

    private const ANSWER = 'schedule-api-every-name-marker-7716';
    private const NEW_ANSWER = 'schedule-api-every-name-rotated-2208';

    private SchedulesController $ctrl;
    private User $user;
    private mixed $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['APP_SECRET_KEY'] ?? null;
        $_ENV['APP_SECRET_KEY'] = 'schedule-api-every-name-key-0123456789';
        $this->ctrl = new SchedulesController('api/v1/schedules', \Yii::$app);
        $this->user = $this->createUserWithRole('schedule-api-every-name', 'operator');
        ['raw' => $raw] = ApiToken::generate((int)$this->user->id, 'schedule-every-name');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
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
     * @return array<string, array{0: string}>
     */
    public static function unusableNameProvider(): array
    {
        return [
            'a letter outside ASCII' => ['contraseña_db'],
            'a trailing space' => ['db_password '],
            'zero' => ['0'],
            'brackets' => ['db[pw]'],
        ];
    }

    /**
     * @dataProvider unusableNameProvider
     */
    public function testGetShowsSuchAnAnswerOnlyAsThePlaceholder(string $name): void
    {
        $schedule = $this->legacySchedule($name);

        $view = $this->ctrl->actionView((int)$schedule->id);
        $list = $this->ctrl->actionIndex();

        $expected = ['app_env' => 'prod', $name => SurveySecrets::PLACEHOLDER];
        $this->assertSame($expected, json_decode($this->extraVarsOf($view), true));
        $this->assertSame($expected, json_decode($this->extraVarsOf(['data' => $this->itemOf($list, (int)$schedule->id)]), true));
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode([$view, $list]));
    }

    /**
     * @dataProvider unusableNameProvider
     */
    public function testAPutOfTheExtraVarsAGetReturnedKeepsTheAnswer(string $name): void
    {
        $schedule = $this->legacySchedule($name);
        $this->setBody(['extra_vars' => $this->extraVarsOf($this->ctrl->actionView((int)$schedule->id))]);

        $updated = $this->ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($updated));
        $this->assertSame([$name => self::ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame(['app_env' => 'prod', $name => SurveySecrets::PLACEHOLDER], $this->storedExtraVarsOf($schedule));
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode($updated));
    }

    /**
     * @dataProvider unusableNameProvider
     */
    public function testAPutWithANewValueReplacesTheAnswer(string $name): void
    {
        $schedule = $this->legacySchedule($name);
        $this->setBody(['extra_vars' => self::json(['app_env' => 'prod', $name => self::NEW_ANSWER])]);

        $updated = $this->ctrl->actionUpdate((int)$schedule->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($updated));
        $this->assertSame([$name => self::NEW_ANSWER], $this->sealedAnswersOf($schedule));
        $this->assertSame(['app_env' => 'prod', $name => SurveySecrets::PLACEHOLDER], json_decode($this->extraVarsOf($updated), true));
        $this->assertStringNotContainsString(self::NEW_ANSWER, (string)json_encode($updated));
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * A schedule of a template saved before the survey rules, with a text
     * field and a password field named $name, whose answer is stored in
     * plaintext under that name, as 2.8.1 stored it.
     */
    private function legacySchedule(string $name): Schedule
    {
        $template = $this->createPasswordSurveyTemplate((int)$this->user->id);
        $template->survey_fields = self::json([['name' => 'app_env', 'type' => 'text'], ['name' => $name, 'type' => 'password']]);
        $this->assertTrue($template->save(false));

        return $this->createPlainSchedule((int)$template->id, (int)$this->user->id, self::json(['app_env' => 'prod', $name => self::ANSWER]));
    }

    /**
     * The extra vars of a response, as text.
     *
     * @param array<string, mixed> $response
     */
    private function extraVarsOf(array $response): string
    {
        $this->assertIsArray($response['data'] ?? null, (string)json_encode($response));
        $extraVars = $response['data']['extra_vars'] ?? null;
        $this->assertIsString($extraVars, (string)json_encode($response));

        return $extraVars;
    }

    /**
     * @param array<string, mixed> $list
     * @return array<mixed>
     */
    private function itemOf(array $list, int $id): array
    {
        $this->assertIsArray($list['data'] ?? null);
        foreach ($list['data'] as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $id) {
                return $item;
            }
        }
        $this->fail("Schedule #{$id} is missing from the list.");
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

    private static function json(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
