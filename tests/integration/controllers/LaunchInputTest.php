<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\TriggerTokenResolver;
use app\controllers\api\v1\JobsController;
use app\controllers\JobTemplateController;
use app\controllers\TriggerController;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;
use yii\web\JsonParser;
use yii\web\Response;

/**
 * Launch input the job record would refuse is refused before the launch,
 * with the record's own messages (JobLaunchInput): a verbosity outside 0-5
 * or that is no whole number, a limit of more than 255 characters, a list
 * where a value belongs. POST /api/v1/jobs answers 422 with the messages, by
 * field under "fields"; the web launch form shows them in its danger flash;
 * a job trigger answers 422 with them. Nothing is written, and nothing is
 * logged as a failed launch. A re-launch takes the limit and verbosity of a
 * job record, which took them already.
 *
 * Regression: such input failed inside the launch, as the job record
 * refused it: POST /api/v1/jobs and the trigger answered 500
 * "Launch failed.", the web form flashed
 * "Launch failed: Job launch failed: Failed to save job record: {...}", and a
 * list as the limit failed the request with "Array to string conversion".
 *
 * Regression: a JSON number beyond the int range (1e30,
 * 9223372036854775808, 1e999), which the request's JSON parser gives as a
 * float, answered 500 on PHP 8.5 as the verbosity or the job template ID of
 * POST /api/v1/jobs, as the verbosity of a job trigger and of the web launch
 * form: an (int) cast of such a float warns there ("The float 1.0E+30 is not
 * representable as an int, cast occurred"), and the error handler turns the
 * warning into an exception. Up to PHP 8.4 the cast wrapped it, and 1e999
 * (infinity) became 0: a trigger with "verbosity": 1e999 launched a job at
 * verbosity 0. A verbosity that is no whole number (2.5, "abc", true, false)
 * was cast too, and the job ran at the verbosity the cast made of it.
 *
 * Regression: an empty list or object ([] or {}) as the limit or the
 * verbosity of POST /api/v1/jobs or of a job trigger launched the job (201),
 * where the spec said a list or an object is refused: the record's rules
 * skip an empty value, and the launch took it for none, so the job ran with
 * the template's limit and verbosity (2.13.1 ran it at verbosity 0). The web
 * launch form dropped a list as either (a forged overrides[verbosity][]=)
 * and launched the job with the template's. A list or an object is refused
 * now on every launch path, also an empty one; an empty string is still
 * none.
 */
class LaunchInputTest extends WebControllerTestCase
{
    use PresentsTriggerTokens;
    use TeamScopeFixtures;

    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    private User $user;

    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swap('notificationDispatcher', new RecordingNotificationDispatcher());
        $this->user = $this->createUserWithRole('launch-input', 'operator');
        $this->loginAs($this->user);
        $userId = (int)$this->user->id;
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->swapped) as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    /**
     * Input, the message, the errors by field.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: array<string, string>}>
     */
    public static function invalidInputProvider(): array
    {
        $tooLong = str_repeat('w', 256);
        $notAnInteger = 'Verbosity must be an integer.';

        return [
            'a verbosity above 5' => [['verbosity' => 9], 'Verbosity must be no greater than 5.', ['verbosity' => 'Verbosity must be no greater than 5.']],
            'a verbosity below 0' => [['verbosity' => -1], 'Verbosity must be no less than 0.', ['verbosity' => 'Verbosity must be no less than 0.']],
            'a verbosity as text above 5' => [['verbosity' => '6'], 'Verbosity must be no greater than 5.', ['verbosity' => 'Verbosity must be no greater than 5.']],
            'a list as the verbosity' => [['verbosity' => [2]], 'Verbosity must be an integer.', ['verbosity' => 'Verbosity must be an integer.']],
            // Regression: each of these was cast, and the job ran at 2, 2, 0, 1, 0 and 2.
            'a fraction as the verbosity' => [['verbosity' => 2.5], $notAnInteger, ['verbosity' => $notAnInteger]],
            'a fraction as text' => [['verbosity' => '2.5'], $notAnInteger, ['verbosity' => $notAnInteger]],
            'text that is no number' => [['verbosity' => 'abc'], $notAnInteger, ['verbosity' => $notAnInteger]],
            'true as the verbosity' => [['verbosity' => true], $notAnInteger, ['verbosity' => $notAnInteger]],
            'false as the verbosity' => [['verbosity' => false], $notAnInteger, ['verbosity' => $notAnInteger]],
            'a number with a space before it' => [['verbosity' => ' 2'], $notAnInteger, ['verbosity' => $notAnInteger]],
            'a limit of 256 characters' => [['limit' => $tooLong], 'Limit should contain at most 255 characters.', ['limit' => 'Limit should contain at most 255 characters.']],
            'a list as the limit' => [['limit' => ['web']], 'Limit must be a string.', ['limit' => 'Limit must be a string.']],
            'both, the limit first' => [
                ['verbosity' => 7, 'limit' => $tooLong],
                'Limit should contain at most 255 characters. Verbosity must be no greater than 5.',
                ['limit' => 'Limit should contain at most 255 characters.', 'verbosity' => 'Verbosity must be no greater than 5.'],
            ],
        ];
    }

    /**
     * Regression: POST /api/v1/jobs with a verbosity outside 0-5 answered
     * 500 "Launch failed.": the job record refused it inside the launch.
     */
    public function testRegressionTheApiAnswered500ForAVerbosityOutsideZeroToFive(): void
    {
        [$result, $logged] = $this->apiLaunch(['verbosity' => 9]);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => 'Verbosity must be no greater than 5.', 'fields' => ['verbosity' => 'Verbosity must be no greater than 5.']]],
            $result
        );
        $this->assertSame([], $logged, 'no failed launch');
        $this->assertNoJob();
    }

    /**
     * @dataProvider invalidInputProvider
     * @param array<string, mixed> $input
     * @param array<string, string> $fields
     */
    public function testTheApiAnswers422WithTheErrorsByField(array $input, string $message, array $fields): void
    {
        [$result, $logged] = $this->apiLaunch($input);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $message, 'fields' => $fields]], $result);
        $this->assertSame([], $logged);
        $this->assertNoJob();
    }

    /**
     * The limits themselves are taken, and a number sent as text, as before.
     */
    public function testTheApiLaunchesWithInputAtTheLimits(): void
    {
        [$result] = $this->apiLaunch(['verbosity' => '5', 'limit' => str_repeat('w', 255)]);

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertIsArray($result);
        $job = $this->onlyJob();
        $this->assertSame(5, (int)$job->verbosity);
        $this->assertSame(str_repeat('w', 255), $job->limit);

        [$result] = $this->apiLaunch(['verbosity' => 0]);
        $this->assertSame(201, \Yii::$app->response->statusCode);
    }

    /**
     * Regression: the web launch form flashed
     * "Launch failed: Job launch failed: Failed to save job record: {...}"
     * for a verbosity outside 0-5 (a forged post; the form offers 0-5).
     */
    public function testRegressionTheWebLaunchFlashedTheJobRecordsSaveErrorForAVerbosityOutsideZeroToFive(): void
    {
        $result = $this->webLaunch(['verbosity' => '9']);

        $this->assertSame('rendered:launch', $result, 'the form again');
        $this->assertSame(['danger' => 'Launch failed: Verbosity must be no greater than 5.'], \Yii::$app->session->getAllFlashes());
        $this->assertSame(['danger' => sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, 'Verbosity must be no greater than 5.')], \Yii::$app->session->getAllFlashes());
        $this->assertNoJob();
    }

    public function testTheWebLaunchShowsATooLongLimitInItsFlash(): void
    {
        $result = $this->webLaunch(['limit' => str_repeat('w', 256), 'verbosity' => '2']);

        $this->assertSame('rendered:launch', $result);
        $this->assertSame(['danger' => 'Launch failed: Limit should contain at most 255 characters.'], \Yii::$app->session->getAllFlashes());
        $this->assertNoJob();
    }

    public function testTheWebLaunchLaunchesWithInputAtTheLimits(): void
    {
        $result = $this->webLaunch(['limit' => str_repeat('w', 255), 'verbosity' => '5', 'check_mode' => '0']);

        $this->assertInstanceOf(Response::class, $result);
        $job = $this->onlyJob();
        $this->assertSame(5, (int)$job->verbosity);
        $this->assertSame(str_repeat('w', 255), $job->limit);
    }

    /**
     * Regression: a job trigger with a verbosity outside 0-5 answered 500
     * "Launch failed.".
     */
    public function testRegressionTheJobTriggerAnswered500ForAVerbosityOutsideZeroToFive(): void
    {
        [$result, $warnings] = $this->trigger('{"verbosity": 9}');

        $this->assertSame(422, $result->statusCode);
        $this->assertSame(['error' => 'Verbosity must be no greater than 5.'], $result->data);
        $this->assertSame(["Trigger for template #{$this->template->id} rejected: Verbosity must be no greater than 5."], $warnings);
        $this->assertNoJob();
        $this->assertSame(0, (int)AuditLog::find()->where(['action' => AuditLog::ACTION_TRIGGER_FIRED, 'object_id' => $this->template->id])->count());
    }

    /**
     * Regression: a list as the limit of a job trigger failed the request
     * with "Array to string conversion".
     */
    public function testRegressionAListAsTheLimitOfAJobTriggerFailedTheRequest(): void
    {
        [$result] = $this->trigger('{"limit": ["web", "db"]}');

        $this->assertSame(422, $result->statusCode);
        $this->assertSame(['error' => 'Limit must be a string.'], $result->data);
        $this->assertNoJob();
    }

    public function testTheJobTriggerLaunchesWithANumberSentAsText(): void
    {
        [$result] = $this->trigger('{"verbosity": "4", "limit": "web"}');

        $this->assertSame(201, $result->statusCode);
        $job = $this->onlyJob();
        $this->assertSame(4, (int)$job->verbosity);
        $this->assertSame('web', $job->limit);
    }

    // -- JSON numbers beyond the int range ----------------------------------------

    /**
     * JSON numbers beyond the int range, as JSON text: the request's JSON
     * parser gives each as a float (1e999 as infinity).
     *
     * @return array<string, array{0: string}>
     */
    public static function numberBeyondTheIntRangeProvider(): array
    {
        return [
            '1e30' => ['1e30'],
            '9223372036854775808, one above the int range' => ['9223372036854775808'],
            '1e999, infinity' => ['1e999'],
            '-1e30' => ['-1e30'],
            '-1e999, minus infinity' => ['-1e999'],
        ];
    }

    /**
     * Regression: POST /api/v1/jobs with a verbosity beyond the int range as
     * a JSON number answered 500 on PHP 8.5: JobLaunchInput cast the float
     * with (int), which warns there ("The float 1.0E+30 is not representable
     * as an int, cast occurred"). Up to PHP 8.4 the cast wrapped it, and
     * 1e999 became verbosity 0.
     *
     * @dataProvider numberBeyondTheIntRangeProvider
     */
    public function testRegressionTheApiAnswered500ForAVerbosityBeyondTheIntRange(string $number): void
    {
        $input = self::parsedJson(sprintf('{"verbosity": %s}', $number));
        $this->assertIsFloat($input['verbosity'], 'the parser gives the number as a float');

        [$result, $logged] = $this->apiLaunch($input);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $notAnInteger = 'Verbosity must be an integer.';
        $this->assertSame(
            ['error' => ['message' => $notAnInteger, 'fields' => ['verbosity' => $notAnInteger]]],
            $result
        );
        $this->assertSame([], $logged, 'no failed launch');
        $this->assertNoJob();
        $this->assertNothingAudited();
    }

    /**
     * Regression: a job trigger (signed out, with the template's trigger
     * token) with a verbosity beyond the int range as a JSON number answered
     * 500 on PHP 8.5; up to PHP 8.4 "verbosity": 1e999 launched a job at
     * verbosity 0. It is refused as the other launch input is: 422 with the
     * job record's message, logged as a rejected trigger, nothing launched.
     *
     * @dataProvider numberBeyondTheIntRangeProvider
     */
    public function testRegressionTheJobTriggerAnswered500ForAVerbosityBeyondTheIntRange(string $number): void
    {
        [$result, $logged] = $this->trigger(
            sprintf('{"verbosity": %s, "limit": "web"}', $number),
            TriggerTokenResolver::TRANSPORT_HEADER,
            Logger::LEVEL_WARNING | Logger::LEVEL_ERROR
        );

        $this->assertSame(422, $result->statusCode);
        $this->assertSame(['error' => 'Verbosity must be an integer.'], $result->data);
        $this->assertSame(
            ["Trigger for template #{$this->template->id} rejected: Verbosity must be an integer."],
            $logged,
            'the rejection, no failure'
        );
        $this->assertNoJob();
        $this->assertNothingAudited();
    }

    /**
     * The same for the deprecated path form /trigger/<token> and the other
     * ways to send the token: they reach the same action.
     *
     * @dataProvider transportProvider
     */
    public function testATriggerRefusesAVerbosityBeyondTheIntRangeHoweverItsTokenIsSent(string $transport): void
    {
        [$result] = $this->trigger('{"verbosity": 1e30}', $transport);

        $this->assertSame(422, $result->statusCode);
        $this->assertSame(['error' => 'Verbosity must be an integer.'], $result->data);
        $this->assertNoJob();
    }

    /**
     * Regression: the web launch form posted as JSON (the session's CSRF
     * token in its header) with a verbosity beyond the int range failed with
     * an exception on PHP 8.5 instead of showing the form again.
     *
     * @dataProvider numberBeyondTheIntRangeProvider
     */
    public function testRegressionTheWebLaunchFailedForAVerbosityBeyondTheIntRangeSentAsJson(string $number): void
    {
        $result = $this->webLaunch(self::parsedJson(sprintf('{"verbosity": %s}', $number)));

        $this->assertSame('rendered:launch', $result, 'the form again');
        $this->assertSame(
            ['danger' => sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, 'Verbosity must be an integer.')],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertNoJob();
        $this->assertNothingAudited();
    }

    /**
     * job_template_id and its older name template_id, each with a JSON
     * number beyond the int range.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function templateIdBeyondTheIntRangeProvider(): array
    {
        $cases = [];
        foreach (['job_template_id', 'template_id'] as $field) {
            foreach (self::numberBeyondTheIntRangeProvider() as $name => [$number]) {
                $cases["{$field} {$name}"] = [$field, $number];
            }
        }

        return $cases;
    }

    /**
     * Regression: POST /api/v1/jobs with a job template ID beyond the int
     * range as a JSON number answered 500 on PHP 8.5: is_numeric() is true
     * for the float, and the (int) cast warned. Up to PHP 8.4 the cast
     * wrapped it, to an ID no template has or to 0. It is no job template
     * ID: the answer is the one for a missing or invalid ID.
     *
     * @dataProvider templateIdBeyondTheIntRangeProvider
     */
    public function testRegressionTheApiAnswered500ForATemplateIdBeyondTheIntRange(string $field, string $number): void
    {
        $body = self::parsedJson(sprintf('{"%s": %s, "verbosity": 2}', $field, $number));
        $this->assertIsFloat($body[$field], 'the parser gives the number as a float');

        [$result, $logged] = $this->apiLaunchBody($body);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'job_template_id is required.']], $result);
        $this->assertSame([], $logged);
        $this->assertNoJob();
        $this->assertNothingAudited();
    }

    /**
     * A job template ID that is no whole number, which the cast made one of.
     *
     * @return array<string, array{0: string}>
     */
    public static function templateIdNoWholeNumberProvider(): array
    {
        return [
            'a fraction' => ['%d.5'],
            'a fraction as text' => ['"%d.5"'],
            'an exponent as text' => ['"%de0"'],
            'a space before it' => ['" %d"'],
        ];
    }

    /**
     * Regression: a job template ID that is no whole number, such as 12.5 or
     * "12.5", launched job template 12, as the (int) cast cut it off.
     *
     * @dataProvider templateIdNoWholeNumberProvider
     */
    public function testRegressionATemplateIdThatIsNoWholeNumberLaunchedTheTemplateItWasCutTo(string $pattern): void
    {
        $body = self::parsedJson(sprintf('{"job_template_id": ' . $pattern . '}', (int)$this->template->id));

        [$result, $logged] = $this->apiLaunchBody($body);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'job_template_id is required.']], $result);
        $this->assertSame([], $logged);
        $this->assertNoJob();
    }

    /**
     * Whole numbers keep their answers, also as JSON floats such as 5.0 and
     * as text: the job template ID as 12.0 or "12", the verbosity as 3, 5.0
     * or "4".
     */
    public function testWholeNumbersAreTakenAsBeforeAlsoAsJsonFloats(): void
    {
        $id = (int)$this->template->id;
        [$result] = $this->apiLaunchBody(self::parsedJson(sprintf('{"job_template_id": %d.0, "verbosity": 5.0}', $id)));
        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame(5, $this->onlyJob()->verbosity);

        [$result] = $this->apiLaunchBody(self::parsedJson(sprintf('{"template_id": "%d", "verbosity": 3}', $id)));
        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame(3, $this->onlyJob()->verbosity);

        [$result] = $this->trigger('{"verbosity": 1.0}');
        $this->assertSame(201, $result->statusCode);
        $this->assertSame(1, $this->onlyJob()->verbosity);

        $this->assertSame(3, (int)Job::find()->where(['job_template_id' => $id])->count());
        $this->assertSame(3, $this->audited(AuditLog::ACTION_JOB_LAUNCHED));
        $this->assertSame(1, $this->audited(AuditLog::ACTION_TRIGGER_FIRED));
    }

    /**
     * A verbosity that is empty (""), which the job record's rule skips, is
     * no verbosity: the job runs at the template's, as when it is left out
     * (as an empty limit is the template's limit). The cast made it 0. So is
     * null, on POST /api/v1/jobs, a job trigger and the web launch form.
     */
    public function testAnEmptyVerbosityIsNoneAndTheJobRunsAtTheTemplatesVerbosity(): void
    {
        $this->template->updateAttributes(['verbosity' => 2, 'limit' => 'db']);

        [$result] = $this->apiLaunch(['verbosity' => '', 'limit' => '']);
        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertRunsWithTheTemplatesLimitAndVerbosity($this->onlyJob(), 'the API, empty text');

        [$result] = $this->apiLaunch(self::parsedJson('{"verbosity": null, "limit": null}'));
        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertRunsWithTheTemplatesLimitAndVerbosity($this->onlyJob(), 'the API, null');

        [$result] = $this->trigger('{"verbosity": "", "limit": ""}');
        $this->assertSame(201, $result->statusCode, (string)json_encode($result->data));
        $this->assertRunsWithTheTemplatesLimitAndVerbosity($this->onlyJob(), 'a job trigger, empty text');

        [$result] = $this->trigger('{"verbosity": null, "limit": null}');
        $this->assertSame(201, $result->statusCode, (string)json_encode($result->data));
        $this->assertRunsWithTheTemplatesLimitAndVerbosity($this->onlyJob(), 'a job trigger, null');

        $this->loginAs($this->user);
        $this->assertInstanceOf(Response::class, $this->webLaunch(['verbosity' => '', 'limit' => '']), 'the web launch form launches');
        $this->assertRunsWithTheTemplatesLimitAndVerbosity($this->onlyJob(), 'the web launch form, empty text');

        $this->assertSame(5, (int)Job::find()->where(['job_template_id' => $this->template->id])->count());
    }

    // -- Lists and objects, also empty ones ---------------------------------------

    /**
     * An empty list or object as the limit, the verbosity or both, as JSON
     * text (the request's JSON parser and the trigger's json_decode() give
     * {} as an empty list too), and the job record's messages for a list by
     * field, the limit first.
     *
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function emptyListOrObjectProvider(): array
    {
        $notAnInteger = ['verbosity' => 'Verbosity must be an integer.'];
        $notAString = ['limit' => 'Limit must be a string.'];

        return [
            'an empty list as the verbosity' => ['{"verbosity": []}', $notAnInteger],
            'an empty object as the verbosity' => ['{"verbosity": {}}', $notAnInteger],
            'an empty list as the limit' => ['{"limit": []}', $notAString],
            'an empty object as the limit' => ['{"limit": {}}', $notAString],
            'both, the limit first' => ['{"verbosity": [], "limit": {}}', $notAString + $notAnInteger],
        ];
    }

    /**
     * Regression: POST /api/v1/jobs with an empty list or object as the
     * limit or the verbosity answered 201 and launched the job, with the
     * template's limit and verbosity (2.13.1 ran it at verbosity 0), where
     * the spec says a list or an object is refused with 422: the job
     * record's rules skip an empty value, and the launch took it for none.
     *
     * @dataProvider emptyListOrObjectProvider
     * @param array<string, string> $fields
     */
    public function testRegressionTheApiLaunchedWithAnEmptyListOrObjectAsTheLimitOrTheVerbosity(string $json, array $fields): void
    {
        [$result, $logged] = $this->apiLaunch(self::parsedJson($json));

        $this->assertSame(422, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame(['error' => ['message' => implode(' ', $fields), 'fields' => $fields]], $result);
        $this->assertSame([], $logged, 'no failed launch');
        $this->assertNoJob();
        $this->assertNothingAudited();
    }

    /**
     * Regression: a job trigger with an empty list or object as the limit or
     * the verbosity answered 201 and launched the job the same way.
     *
     * @dataProvider emptyListOrObjectProvider
     * @param array<string, string> $fields
     */
    public function testRegressionTheJobTriggerLaunchedWithAnEmptyListOrObjectAsTheLimitOrTheVerbosity(string $json, array $fields): void
    {
        [$result, $logged] = $this->trigger($json, TriggerTokenResolver::TRANSPORT_HEADER, Logger::LEVEL_WARNING | Logger::LEVEL_ERROR);

        $this->assertSame(422, $result->statusCode, (string)json_encode($result->data));
        $this->assertSame(['error' => implode(' ', $fields)], $result->data);
        $this->assertSame(
            ["Trigger for template #{$this->template->id} rejected: " . implode(' ', $fields)],
            $logged,
            'the rejection, no failure'
        );
        $this->assertNoJob();
        $this->assertNothingAudited();
    }

    /**
     * Regression: the web launch form posted as JSON (the session's CSRF
     * token in its header) with an empty list or object as the limit or the
     * verbosity launched the job with the template's: the form dropped a
     * list as either.
     *
     * @dataProvider emptyListOrObjectProvider
     * @param array<string, string> $fields
     */
    public function testRegressionTheWebLaunchLaunchedWithAnEmptyListOrObjectSentAsJson(string $json, array $fields): void
    {
        $result = $this->webLaunch(self::parsedJson($json));

        $this->assertSame('rendered:launch', $result, 'the form again');
        $this->assertSame(
            ['danger' => sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, implode(' ', $fields))],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertNoJob();
        $this->assertNothingAudited();
    }

    /**
     * A list as the limit or the verbosity as a form body posts it, also a
     * list of one empty value, and the job record's message for a list.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function postedListProvider(): array
    {
        return [
            'overrides[verbosity][]=' => ['overrides[verbosity][]=', 'Verbosity must be an integer.'],
            'overrides[verbosity][]=3' => ['overrides[verbosity][]=3', 'Verbosity must be an integer.'],
            'overrides[limit][]=' => ['overrides[limit][]=', 'Limit must be a string.'],
            'overrides[limit][]=web' => ['overrides[limit][]=web', 'Limit must be a string.'],
            'overrides[limit][host]=web' => ['overrides[limit][host]=web', 'Limit must be a string.'],
        ];
    }

    /**
     * Regression: the web launch form dropped a list as the limit or the
     * verbosity, a forged post such as overrides[verbosity][]=, and launched
     * the job with the template's limit and verbosity. It shows the job
     * record's message now and launches nothing, as POST /api/v1/jobs and
     * the job trigger refuse a list.
     *
     * @dataProvider postedListProvider
     */
    public function testRegressionTheWebLaunchDroppedAListAsTheLimitOrTheVerbosityAndLaunched(string $body, string $message): void
    {
        // As PHP reads the form body.
        parse_str($body, $posted);
        $overrides = $posted['overrides'] ?? null;
        $this->assertIsArray($overrides);

        $result = $this->webLaunch($overrides);

        $this->assertSame('rendered:launch', $result, 'the form again');
        $this->assertSame(['danger' => sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, $message)], \Yii::$app->session->getAllFlashes());
        $this->assertNoJob();
        $this->assertNothingAudited();
    }

    /**
     * Regression: a job trigger took a verbosity that is no whole number for
     * the number the cast made of it, "abc" for 0.
     */
    public function testRegressionTheJobTriggerTookTextThatIsNoNumberForVerbosityZero(): void
    {
        [$result] = $this->trigger('{"verbosity": "abc"}');

        $this->assertSame(422, $result->statusCode);
        $this->assertSame(['error' => 'Verbosity must be an integer.'], $result->data);
        $this->assertNoJob();
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * POST /api/v1/jobs for the template with $input; the answer and the
     * errors JobsController logged.
     *
     * @param array<string, mixed> $input
     * @return array{0: mixed, 1: list<string>}
     */
    private function apiLaunch(array $input): array
    {
        return $this->apiLaunchBody(['job_template_id' => (int)$this->template->id] + $input);
    }

    /**
     * POST /api/v1/jobs with the body $body; the answer and the errors
     * JobsController logged.
     *
     * @param array<string, mixed> $body
     * @return array{0: mixed, 1: list<string>}
     */
    private function apiLaunchBody(array $body): array
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);

        return $this->logged(JobsController::class, Logger::LEVEL_ERROR, fn (): mixed => (new JobsController('api/v1/jobs', $this->app()))->actionCreate());
    }

    /**
     * The JSON text $json as the request's JSON parser reads a request body
     * (config/web.php): a number beyond the int range is a float, 1e999 is
     * infinity.
     *
     * @return array<string, mixed>
     */
    private static function parsedJson(string $json): array
    {
        $parsed = (new JsonParser())->parse($json, 'application/json');
        self::assertIsArray($parsed);

        return $parsed;
    }

    /**
     * The web launch form posted with $overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function webLaunch(array $overrides): Response|string
    {
        $this->setQueryParams(['id' => (string)$this->template->id]);
        $this->setPost(['overrides' => $overrides]);
        $controller = new class ('job-template', $this->app()) extends JobTemplateController {
            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };

        return $controller->actionLaunch();
    }

    /**
     * POST /trigger/fire for the template with the JSON $body, signed out
     * and with the template's trigger token sent by $transport (the URL
     * also carries the path form /trigger/<token>); the answer and the
     * messages of $levels (by default the warnings) TriggerController
     * logged.
     *
     * @return array{0: Response, 1: list<string>}
     */
    private function trigger(
        string $body,
        string $transport = TriggerTokenResolver::TRANSPORT_HEADER,
        int $levels = Logger::LEVEL_WARNING
    ): array {
        $this->presentTriggerToken($this->template->generateTriggerToken((int)$this->user->id), $transport);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->setIdentity(null);
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setRawBody($body);

        [$result, $messages] = $this->logged(
            TriggerController::class,
            $levels,
            fn (): mixed => (new TriggerController('trigger', $this->app()))->actionFire()
        );
        $this->assertInstanceOf(Response::class, $result);

        return [$result, $messages];
    }

    /**
     * What $action returned, and the messages of $levels (Logger::LEVEL_*,
     * combined with |) logged meanwhile in $category.
     *
     * @return array{0: mixed, 1: list<string>}
     */
    private function logged(string $category, int $levels, callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $result = $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $messages = [];
        foreach ($logger->messages as $message) {
            if (($message[1] & $levels) !== 0 && $message[2] === $category && is_string($message[0])) {
                $messages[] = $message[0];
            }
        }

        return [$result, $messages];
    }

    private function assertNoJob(): void
    {
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $this->template->id])->count(), 'nothing was launched');
    }

    /**
     * $job, launched by $path, has no limit and no verbosity of its own: it
     * runs with the template's, limit db and verbosity 2, as its runner
     * payload says.
     */
    private function assertRunsWithTheTemplatesLimitAndVerbosity(Job $job, string $path): void
    {
        $this->assertNull($job->limit, "{$path}: no limit of the job's own");
        $this->assertNull($job->verbosity, "{$path}: no verbosity of the job's own");
        $payload = $job->decodedRunnerPayload();
        $this->assertSame('db', $payload['limit'] ?? null, "{$path}: the template's limit");
        $this->assertSame(2, $payload['verbosity'] ?? null, "{$path}: the template's verbosity");
    }

    /**
     * No launch and no trigger was audited for the user the launch and the
     * trigger run as.
     */
    private function assertNothingAudited(): void
    {
        $audited = $this->audited(
            AuditLog::ACTION_JOB_LAUNCHED,
            AuditLog::ACTION_TRIGGER_FIRED,
            AuditLog::ACTION_TRIGGER_DENIED
        );
        $this->assertSame(0, $audited, 'nothing was audited');
    }

    /**
     * The audit entries of $actions for the user the launch and the trigger
     * run as.
     */
    private function audited(string ...$actions): int
    {
        return (int)AuditLog::find()->where(['user_id' => $this->user->id, 'action' => $actions])->count();
    }

    private function onlyJob(): Job
    {
        /** @var Job|null $job */
        $job = Job::find()->where(['job_template_id' => $this->template->id])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($job);

        return $job;
    }

    private function swap(string $id, object $component): void
    {
        $this->swapped[] = [$id, \Yii::$app->getComponents(true)[$id] ?? null];
        \Yii::$app->set($id, $component);
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return $app;
    }
}
