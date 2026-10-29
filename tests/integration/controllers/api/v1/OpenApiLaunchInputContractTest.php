<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobsController;
use app\controllers\TriggerController;
use app\models\Job;
use app\tests\integration\controllers\PresentsTriggerTokens;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\RecordingNotificationDispatcher;
use yii\web\JsonParser;
use yii\web\Response;

/**
 * What web/openapi.yaml says about launch input that the job record does
 * not take (a limit longer than 255 characters, a verbosity below 0 or
 * above 5, a list or an object as either) is what POST /api/v1/jobs and the
 * job trigger answer: 422 with the record's messages, before anything is
 * written. The tests run the endpoints and compare their answers with the
 * spec's examples and schemas, and the messages with the job record's own.
 *
 * Regression from wave 10 of step 3, fails on the spec as it was before it:
 * both answered 500 for such input (or took a list or an object as the
 * verbosity for a number), and the spec documented neither the refusal nor
 * its error.fields. An empty list or object passed until the move to PHP
 * 8.5 was reviewed (testRegressionAnEmptyListOrObjectIsRefusedAsTheSpecSays).
 */
class OpenApiLaunchInputContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use PresentsTriggerTokens;

    private const RESPONSES = ['components', 'responses'];

    /** @var array<string, mixed> component definitions to restore */
    private array $swapped = [];

    protected function setUp(): void
    {
        parent::setUp();
        $components = $this->app()->getComponents(true);
        $this->swapped['notificationDispatcher'] = $components['notificationDispatcher'] ?? null;
        $this->app()->set('notificationDispatcher', new RecordingNotificationDispatcher());
    }

    protected function tearDown(): void
    {
        foreach ($this->swapped as $id => $original) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    /**
     * Regression (wave 10 of step 3): POST /api/v1/jobs refuses a verbosity
     * the job record does not take with the spec's invalidInput example,
     * and a limit that is too long together with a list as the verbosity
     * with both fields; the answers have the shape of JobLaunchError and
     * nothing is launched.
     */
    public function testRegressionTheApiRefusesInvalidLaunchInputAsTheSpecSays(): void
    {
        $operator = $this->userWithRole('launch-input-contract', 'operator');
        $this->loginAs($operator);
        $template = $this->jobTemplateOwnedBy($operator);
        $ctrl = new JobsController('api/v1/jobs', $this->app());

        $this->setPost(['job_template_id' => (int)$template->id, 'verbosity' => 9]);
        $answer = $ctrl->actionCreate();
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame($this->example('JobLaunchRejected', 'invalidInput'), $answer, 'The spec\'s example is the answer.');
        $this->assertSame(['verbosity' => self::recordError('verbosity', 9)], $answer['error']['fields'] ?? null, 'The job record\'s own message.');
        $this->assertHasTheShapeOfJobLaunchError($answer);

        $limit = str_repeat('w', 256);
        $this->setPost(['job_template_id' => (int)$template->id, 'limit' => $limit, 'verbosity' => [3]]);
        $answer = $ctrl->actionCreate();
        $fields = ['limit' => self::recordError('limit', $limit), 'verbosity' => self::recordError('verbosity', [3])];
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => implode(' ', $fields), 'fields' => $fields]], $answer);
        $this->assertHasTheShapeOfJobLaunchError($answer);
        $this->assertFalse(Job::find()->where(['job_template_id' => $template->id])->exists(), 'Nothing was launched.');

        $this->assertStringContainsString(
            'A `limit` longer than 255 characters, a `verbosity` below 0 or above 5, or a list or an object as either is refused with 422 before anything is written',
            $this->text(['paths', '/api/v1/jobs', 'post', 'description'])
        );
        $this->assertSame(255, $this->dig($this->schema('JobLaunchInput'), ['properties', 'limit', 'maxLength'], 'JobLaunchInput documents the limit\'s length.'));
        $this->assertSame('#/components/schemas/JobLaunchError', $this->dig($this->spec(), [...self::RESPONSES, 'JobLaunchRejected', 'content', 'application/json', 'schema', '$ref'], 'JobLaunchRejected documents its schema.'));
    }

    /**
     * Regression (wave 10 of step 3): the job trigger refuses a verbosity
     * the job record does not take with 422 and the spec's invalidInput
     * example of TriggerLaunchRejected, before anything is written.
     */
    public function testRegressionTheJobTriggerRefusesInvalidLaunchInputAsTheSpecSays(): void
    {
        $operator = $this->userWithRole('trigger-input-contract', 'operator');
        $template = $this->jobTemplateOwnedBy($operator);
        $this->presentTriggerToken($template->generateTriggerToken((int)$operator->id));
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setRawBody('{"verbosity": 9}');

        $result = (new TriggerController('trigger', $this->app()))->actionFire();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(422, $result->statusCode);
        $this->assertSame($this->example('TriggerLaunchRejected', 'invalidInput'), $result->data, 'The spec\'s example is the answer.');
        $this->assertSame(['error' => self::recordError('verbosity', 9)], $result->data, 'The job record\'s own message.');
        $this->assertFalse(Job::find()->where(['job_template_id' => $template->id])->exists(), 'Nothing was launched.');
        $this->assertSame(255, $this->dig($this->schema('TriggerJobInput'), ['properties', 'limit', 'maxLength'], 'TriggerJobInput documents the limit\'s length.'));
        $this->assertStringContainsString(
            'A `limit` or a `verbosity` the job record does not take (longer than 255 characters; below 0 or above 5; a list or an object) is refused with the record\'s messages; releases up to 2.9.0 answered 500 for it, or took a list or an object as the verbosity for a number.',
            $this->text([...self::RESPONSES, 'TriggerLaunchRejected', 'description'])
        );
    }

    /**
     * Regression: the spec said a list or an object as the limit or the
     * verbosity is refused with 422, but an empty one ([] or {}) launched
     * the job: POST /api/v1/jobs and the job trigger answered 201, and the
     * job ran with the template's limit and verbosity (2.13.1 ran it at
     * verbosity 0).
     * Both refuse it now, with the job record's message for a list, and the
     * spec says that this holds for an empty one too, and that an empty
     * string or null is none, the job template's, as the answers show.
     */
    public function testRegressionAnEmptyListOrObjectIsRefusedAsTheSpecSays(): void
    {
        $operator = $this->userWithRole('launch-lists-contract', 'operator');
        $this->loginAs($operator);
        $template = $this->jobTemplateOwnedBy($operator);
        $ctrl = new JobsController('api/v1/jobs', $this->app());

        // As the request's JSON parser gives the body: {} is an empty list.
        $this->setPost(self::parsedJson(sprintf('{"job_template_id": %d, "limit": {}, "verbosity": []}', (int)$template->id)));
        $answer = $ctrl->actionCreate();
        $fields = ['limit' => self::recordError('limit', ['web']), 'verbosity' => self::recordError('verbosity', [3])];
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => implode(' ', $fields), 'fields' => $fields]], $answer);
        $this->assertHasTheShapeOfJobLaunchError($answer);

        $this->presentTriggerToken($template->generateTriggerToken((int)$operator->id));
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setRawBody('{"verbosity": {}}');
        $result = (new TriggerController('trigger', $this->app()))->actionFire();
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(422, $result->statusCode);
        $this->assertSame(['error' => $fields['verbosity']], $result->data);
        $this->assertFalse(Job::find()->where(['job_template_id' => $template->id])->exists(), 'Nothing was launched.');

        $this->setPost(['job_template_id' => (int)$template->id, 'limit' => '', 'verbosity' => '']);
        $ctrl->actionCreate();
        $this->assertSame(201, \Yii::$app->response->statusCode, 'An empty string is none.');
        $job = Job::find()->where(['job_template_id' => $template->id])->one();
        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame([null, null], [$job->limit, $job->verbosity], 'The job template\'s limit and verbosity.');

        $this->assertStringContainsString(
            'A list or an object is refused also when it is empty (`[]` or `{}`), which releases up to 2.13.1 launched with the job template\'s limit and at verbosity 0. An empty `limit` (`""`) is none: the job runs with the job template\'s limit.',
            $this->text(['paths', '/api/v1/jobs', 'post', 'description'])
        );
        foreach (['JobLaunchInput', 'TriggerJobInput'] as $schema) {
            $limit = $this->text(['components', 'schemas', $schema, 'properties', 'limit', 'description']);
            $verbosity = $this->text(['components', 'schemas', $schema, 'properties', 'verbosity', 'description']);
            $this->assertStringContainsString('a list or an object, also an empty one (`[]` or `{}`)', $limit, "{$schema}.limit");
            $this->assertStringContainsString('a list or an object, also an empty one (`[]` or `{}`)', $verbosity, "{$schema}.verbosity");
            $this->assertStringContainsString(
                'An empty string or `null` is none: the job runs with the job template\'s limit, as when the field is left out.',
                $limit,
                "{$schema}.limit"
            );
            $this->assertStringContainsString(
                'An empty string or `null` is none: the job runs at the job template\'s verbosity, as when the field is left out.',
                $verbosity,
                "{$schema}.verbosity"
            );
        }
    }

    /**
     * The JSON text $json as the request's JSON parser reads a request body
     * (config/web.php).
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
     * The first message the job record gives $attribute for $value.
     */
    private static function recordError(string $attribute, mixed $value): string
    {
        $job = new Job();
        $job->setAttribute($attribute, $value);
        $job->validate([$attribute]);

        return (string)$job->getFirstError($attribute);
    }

    /**
     * $answer has exactly the properties of JobLaunchError, and its error
     * exactly those of the schema's error object.
     *
     * @param array<mixed> $answer
     */
    private function assertHasTheShapeOfJobLaunchError(array $answer): void
    {
        $this->assertMatchesSchema('JobLaunchError', $answer);
        $error = $this->properties('JobLaunchError')['error'] ?? [];
        $properties = $error['properties'] ?? null;
        $this->assertIsArray($properties);
        $this->assertIsArray($answer['error']);
        $this->assertMatchesProperties($properties, $answer['error'], 'Schema JobLaunchError.error');
    }

    /**
     * The value of the example $name of the response $response.
     *
     * @return array<mixed>
     */
    private function example(string $response, string $name): array
    {
        $value = $this->dig(
            $this->spec(),
            [...self::RESPONSES, $response, 'content', 'application/json', 'examples', $name, 'value'],
            "{$response} has the example {$name}."
        );
        $this->assertIsArray($value);

        return $value;
    }

    /**
     * The text at $keys of the spec, on one line.
     *
     * @param list<string> $keys
     */
    private function text(array $keys): string
    {
        $text = $this->dig($this->spec(), $keys, 'web/openapi.yaml has ' . implode('.', $keys) . '.');
        $this->assertIsString($text);

        return trim((string)preg_replace('/\s+/', ' ', $text));
    }
}
