<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\SurveySecrets;
use app\controllers\api\v1\JobsController;
use app\models\ApiToken;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\JobLaunchService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\unit\components\UnusablePasswordNames;
use yii\web\JsonParser;

/**
 * POST /api/v1/jobs with password answers: they are sealed, the responses
 * show the placeholder, and refused launches answer 422 with the reason.
 */
class JobsControllerSurveySecretsTest extends WebControllerTestCase
{
    use UnusablePasswordNames;

    private const ANSWER = 'api-launch-answer-marker-2208';

    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->swapped) as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    /**
     * Regression: the launch response and GET /api/v1/jobs/{id} returned the
     * password answer in extra_vars to every caller with job.view.
     */
    public function testAPasswordAnswerIsSealedAndTheResponsesShowThePlaceholder(): void
    {
        $user = $this->authenticateAsAdmin();
        $template = $this->createPasswordSurveyTemplate((int)$user->id);
        $this->setBody(['job_template_id' => $template->id, 'extra_vars' => [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => self::ANSWER]]);

        $created = $this->controller()->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $expected = [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER];
        $this->assertSame($expected, $created['data']['extra_vars']);
        $this->assertStringNotContainsString(self::ANSWER, (string)json_encode($created));

        $view = $this->controller()->actionView((int)$created['data']['id']);
        $this->assertSame($expected, $view['data']['extra_vars']);
        $job = Job::findOne($created['data']['id']);
        $this->assertNotNull($job);
        $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
    }

    public function testThePlaceholderAsAnAnswerIs422(): void
    {
        $user = $this->authenticateAsAdmin();
        $template = $this->createPasswordSurveyTemplate((int)$user->id);
        $this->setBody(['job_template_id' => $template->id, 'extra_vars' => [self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER]]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'The value of "db_password" is the placeholder $encrypted$; send the real value.']], $result);
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
    }

    public function testAnUnusablePasswordFieldNameIs422(): void
    {
        $user = $this->authenticateAsAdmin();
        $template = $this->createPasswordSurveyTemplate((int)$user->id);
        $template->survey_fields = '[{"name":"db[pw]","type":"password"}]';
        $template->save(false);
        $this->setBody(['job_template_id' => $template->id]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => 'Survey field "db[pw]" has a name that cannot be used for a password answer; rename it in the survey before launching.']],
            $result
        );
    }

    /**
     * Regression: a password field named "db_password\n" (stored through
     * the API) counted as usable, so the answer an API caller sends as
     * "db_password" was stored in plaintext and returned by the API.
     */
    public function testALegacyPasswordFieldNameWithALineBreakIs422(): void
    {
        $user = $this->authenticateAsAdmin();
        $template = $this->createPasswordSurveyTemplate((int)$user->id);
        $template->survey_fields = '[{"name":"app_env","type":"text"},{"name":"db_password\n","type":"password"}]';
        $template->save(false);
        $this->setBody(['job_template_id' => $template->id, 'extra_vars' => [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => self::ANSWER]]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => 'Survey field "db_password\n" has a name that cannot be used for a password answer; rename it in the survey before launching.']],
            $result
        );
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
    }

    /**
     * Regression (V2-C1U): a password field name with a character outside
     * ASCII around or in it (a non-breaking space, a byte order mark, a
     * letter such as an umlaut) counted as usable, so the answer an API
     * caller sends under the visible name "db_password" was stored in
     * plaintext and returned by the API. Refused now, in either form of
     * extra_vars, and the message shows the name escaped.
     *
     * @dataProvider unicodeNameProvider
     */
    public function testALegacyPasswordFieldNameOutsideAsciiIs422(string $name, string $quoted): void
    {
        $user = $this->authenticateAsAdmin();
        $template = $this->createPasswordSurveyTemplate((int)$user->id);
        $template->survey_fields = (string)json_encode([['name' => self::SURVEY_TEXT_FIELD, 'type' => 'text'], ['name' => $name, 'type' => 'password']]);
        $this->assertTrue($template->save(false));
        $refusal = "Survey field {$quoted} has a name that cannot be used for a password answer; rename it in the survey before launching.";
        $answers = [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => self::ANSWER];

        foreach (['an object' => $answers, 'JSON text' => (string)json_encode($answers)] as $form => $extraVars) {
            \Yii::$app->response->statusCode = 200;
            $this->setBody(['job_template_id' => $template->id, 'extra_vars' => $extraVars]);

            $result = $this->controller()->actionCreate();

            $this->assertSame(422, \Yii::$app->response->statusCode, $form);
            $this->assertSame(['error' => ['message' => $refusal]], $result, $form);
        }
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
    }

    /**
     * JSON allows 1e999; PHP reads it as INF and cannot write it back.
     *
     * Regression: such a number was refused as "not valid UTF-8", and as a
     * password answer the launch answered 500 "Launch failed.".
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function numberTooLargeProvider(): array
    {
        return [
            'another key' => ['{"timeout_s": 1e999}', 'The extra vars cannot be stored: they contain a number too large for JSON.'],
            'a password answer' => ['{"db_password": 1e999}', 'The password answers cannot be stored: they contain a number too large for JSON.'],
        ];
    }

    /**
     * @dataProvider numberTooLargeProvider
     */
    public function testANumberTooLargeForJsonIs422(string $extraVars, string $message): void
    {
        $user = $this->authenticateAsAdmin();
        $template = $this->createPasswordSurveyTemplate((int)$user->id);
        $this->setBody(['job_template_id' => $template->id, 'extra_vars' => $extraVars]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $message]], $result);
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
    }

    /**
     * The same numbers in extra_vars sent as a JSON object, the documented
     * form, as Yii's JSON parser hands the body to the controller.
     *
     * Regression: the controller re-encoded the object for the launch, which
     * failed for such a number, and dropped every extra var of the request:
     * the call answered 201 and launched with the template's extra vars
     * only, without the password answer.
     *
     * @dataProvider numberTooLargeProvider
     */
    public function testANumberTooLargeForJsonInAnExtraVarsObjectIs422(string $extraVars, string $message): void
    {
        $user = $this->authenticateAsAdmin();
        $template = $this->createPasswordSurveyTemplate((int)$user->id);
        $body = (new JsonParser())->parse('{"job_template_id": ' . $template->id . ', "extra_vars": ' . $extraVars . '}', 'application/json');
        $this->assertIsArray($body);
        $this->assertIsArray($body['extra_vars'], 'sent as an object');
        $this->setBody($body);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $message]], $result);
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
    }

    /**
     * Text that is not UTF-8, which a form-encoded body can carry
     * (extra_vars[db_password]=%FF).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function notUtf8Provider(): array
    {
        return [
            'another key' => [self::SURVEY_TEXT_FIELD, 'The extra vars cannot be stored: they contain characters that are not valid UTF-8.'],
            'a password answer' => [self::SURVEY_PASSWORD_FIELD, 'The password answers cannot be stored: they contain characters that are not valid UTF-8.'],
        ];
    }

    /**
     * Regression: like a number too large for JSON, such text in the
     * extra_vars object dropped every extra var of the request (201)
     * instead of answering 422 with the reason.
     *
     * @dataProvider notUtf8Provider
     */
    public function testTextThatIsNotUtf8InAnExtraVarsObjectIs422(string $key, string $message): void
    {
        $user = $this->authenticateAsAdmin();
        $template = $this->createPasswordSurveyTemplate((int)$user->id);
        $this->setBody(['job_template_id' => (string)$template->id, 'extra_vars' => [$key => "not-utf8-\xFF"]]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $message]], $result);
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
    }

    public function testOtherLaunchFailuresStay500(): void
    {
        $user = $this->authenticateAsAdmin();
        $template = $this->createPasswordSurveyTemplate((int)$user->id);
        $this->swap('jobLaunchService', new class () extends JobLaunchService {
            public function launch(JobTemplate $template, int $launchedBy, array $overrides = []): Job
            {
                throw new \RuntimeException('Job launch failed: the job could not be saved.');
            }
        });
        $this->setBody(['job_template_id' => $template->id]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(500, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Launch failed.']], $result);
    }

    private function controller(): JobsController
    {
        return new JobsController('api/v1/jobs', \Yii::$app);
    }

    private function authenticateAsAdmin(): User
    {
        $user = $this->createUser('jobs-api-secrets');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole('admin');
        $this->assertNotNull($role);
        $auth->assign($role, (string)$user->id);
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'jobs-api-secrets');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $component */
        $component = \Yii::$app->user;
        $component->loginByAccessToken($raw);

        return $user;
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

    private function swap(string $id, object $replacement): void
    {
        $components = \Yii::$app->getComponents(true);
        $this->swapped[] = [$id, $components[$id] ?? null];
        \Yii::$app->set($id, $replacement);
    }
}
