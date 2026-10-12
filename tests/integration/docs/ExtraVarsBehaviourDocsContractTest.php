<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\components\SurveySecrets;
use app\components\TemplateSurveyValidator;
use app\controllers\api\v1\JobsController;
use app\controllers\api\v1\JobTemplatesController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\controllers\JobTemplateController;
use app\models\ApiToken;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\JobClaimService;
use app\services\JobLaunchSecrets;
use app\services\JobLaunchService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use app\tests\unit\docs\ReadsOperatorDocs;
use yii\base\Module;
use yii\web\Response;

/**
 * The behaviour the operator docs describe for Extra Vars, run against the
 * code. ExtraVarsDocsContractTest, OperatorDocsContractTest and
 * OpenApiRefusalTextContractTest pin the text; this test pins what the
 * text says, so a change of the behaviour fails here and needs the docs
 * changed with it:
 * - docs/updating.md and web/openapi.yaml (JobLaunchInput.extra_vars):
 *   POST /api/v1/jobs ignores extra_vars sent as JSON text that is not
 *   valid JSON, text that is not UTF-8 included. (V1-NI-1: the docs said
 *   such text is refused in either form; only the object form refuses it,
 *   see JobsControllerSurveySecretsTest.)
 * - docs/updating.md, docs/troubleshooting.md and web/openapi.yaml
 *   (JobTemplateInput.extra_vars): a job template that holds a number too
 *   large for JSON is refused on every update, also one that leaves its
 *   extra vars alone, and cannot be cloned.
 * - the same docs: the Extra Vars rule compares the examples they give as
 *   they say, and docs/updating.md gives every example compared here.
 * - docs/updating.md: a launch passes a whole number written with a
 *   fraction or an exponent as PHP writes the floating-point number, which
 *   from 2^54 on can be another whole number and from 10^17 on has an
 *   exponent, although the rule counts it as unchanged.
 * - docs/updating.md, docs/troubleshooting.md and web/openapi.yaml
 *   (WorkflowStepInput.extra_vars_template): a workflow step whose
 *   extra_vars_template is an object with a number too large for JSON is
 *   refused with 422, as JSON text with one is.
 *
 * Regressions from fix round 3 of the survey password release (findings in
 * brackets), each fails on the code or the docs from before the fix:
 * - [R2V1-DOC-CMP, R2V2-2] the docs said that a number written another way
 *   with exactly the same value is no change; beyond 2^53 the code called it
 *   a change, and it took a whole number beyond the 64-bit range for the
 *   same digits as text. The comparison now follows the docs' examples
 *   ('a whole number beyond 2^53 written another way', 'the smallest
 *   64-bit number written another way', 'digits beyond the 64-bit range and
 *   the same digits as text differ' and its reverse), and the docs give the
 *   exact rule.
 * - [C, Q open issue 3] such an object was stored without its extra vars
 *   with 201; the docs named the 422 for JSON text only.
 *
 * Regression from fix round 4, failing on the docs from before the fix:
 * - [R3V2-2] updating.md said the Extra Vars change when their JSON value
 *   does "as a launch reads it". The rule compares JSON values, so
 *   {"n": 1e18} over {"n": 1000000000000000000} is no change, while a
 *   launch passes 1.0e+18 for the first and the integer for the second, and
 *   18014398509481990 for 18014398509481992.0. The docs now say so.
 */
class ExtraVarsBehaviourDocsContractTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use ReadsOperatorDocs;

    private const TOO_LARGE = '{"app_env": "prod", "timeout_s": 1e999}';

    private const UPDATE_SECTION = '## After updating from 2.8.1 or older';

    /**
     * The examples of the docs, as the Extra Vars of a template saved
     * before the rule, with a password field's key in them, and the words
     * docs/updating.md gives the example (or the boundary it shows) in.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string}> the stored Extra Vars, the saved ones, whether that is a change, and the words of the docs
     */
    public static function documentedExampleProvider(): array
    {
        $vars = static fn (string $value): string => '{"db_password": "legacy-default-7741", "value": ' . $value . '}';
        $digits = '12345678901234567890';

        return [
            '"1" and 1 differ' => [$vars('"1"'), $vars('1'), true, '`"1"` and `1` differ'],
            'a list and an object differ' => [$vars('["a", "b"]'), $vars('{"0": "a", "1": "b"}'), true, 'and so do a list and an object'],
            'digits beyond the 64-bit range and the same digits as text differ' => [
                $vars($digits),
                $vars('"' . $digits . '"'),
                true,
                '`' . $digits . '` and `"' . $digits . '"`',
            ],
            'the same digits as text and digits beyond the 64-bit range differ' => [
                $vars('"' . $digits . '"'),
                $vars($digits),
                true,
                'a whole number beyond the 64-bit range and the same digits as text',
            ],
            'spacing, line breaks and key order' => [
                $vars('1'),
                "{\n  \"value\": 1,\n  \"db_password\":\"legacy-default-7741\"\n}",
                false,
                'Spacing, line breaks and the order of the keys do not count',
            ],
            'a key written twice counts with its last value' => [
                $vars('1'),
                '{"db_password": "legacy-default-7741", "value": 2, "value": 1}',
                false,
                'A key written twice counts with its last value',
            ],
            '1.0 for 1' => [$vars('1'), $vars('1.0'), false, '`1.0` for `1`'],
            '1e2 for 100' => [$vars('100'), $vars('1e2'), false, '`1e2` for `100`'],
            'a whole number beyond 2^53 written another way' => [
                $vars('9007199254740994'),
                $vars('9007199254740994.0'),
                false,
                '`9007199254740994.0` for `9007199254740994`',
            ],
            'the smallest 64-bit number written another way' => [
                $vars((string)PHP_INT_MIN),
                $vars(PHP_INT_MIN . '.0'),
                false,
                '`' . PHP_INT_MIN . '` to `' . PHP_INT_MAX . '`',
            ],
            'two fractions that read as the same floating-point number' => [$vars('0.5'), $vars('0.50'), false, '`0.50` for `0.5`'],
            'beyond the 64-bit range, an exponent is not the same digits' => [
                $vars('100000000000000000000'),
                $vars('1e20'),
                true,
                '`1e20` for `100000000000000000000` is a change',
            ],
            'beyond the 64-bit range, a fraction is not the same digits' => [
                $vars('100000000000000000000'),
                $vars('100000000000000000000.0'),
                true,
                'a whole number written without a fraction or an exponent is the same only as the same digits',
            ],
            'a whole number a client rounded' => [
                $vars('9007199254740993'),
                $vars('9007199254740992'),
                true,
                '`9007199254740993` sent back as `9007199254740992`',
            ],
            'a fraction that reads as another whole number' => [
                $vars('9007199254740993'),
                $vars('9007199254740993.0'),
                true,
                '`9007199254740993.0` for `9007199254740993` is a change',
            ],
            'the largest 64-bit number with a fraction reads as 2^63' => [
                $vars((string)PHP_INT_MAX),
                $vars(PHP_INT_MAX . '.0'),
                true,
                'which above 2^53 (`' . 2 ** 53 . '`) can be another whole number',
            ],
            // R3V2-2: no change, although a launch passes the two forms otherwise (launchedNumberProvider).
            'from 2^54 on, a fraction that a launch writes as another whole number' => [
                $vars('18014398509481992'),
                $vars('18014398509481992.0'),
                false,
                '`18014398509481990` for `18014398509481992.0`',
            ],
            'from 10^17 on, an exponent that a launch passes as a floating-point number' => [
                $vars('1e18'),
                $vars('1000000000000000000'),
                false,
                '`1.0e+18` for `1e18`',
            ],
        ];
    }

    /**
     * Whole numbers within the 64-bit range written with a fraction or an
     * exponent, as a job template's Extra Vars hold them, and as a launch
     * passes them on: as PHP writes the floating-point number, in the
     * fewest digits that read back as it. Rows marked as examples are the
     * ones docs/updating.md gives.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}> the number as written, as launched, and whether the docs give it as an example
     */
    public static function launchedNumberProvider(): array
    {
        $twoTo54 = 2 ** 54;

        return [
            'between 2^53 and 2^54, the same digits' => ['9007199254740994.0', '9007199254740994', false],
            '2^54, the same digits' => [$twoTo54 . '.0', (string)$twoTo54, false],
            'from 2^54 on, another whole number' => ['18014398509481992.0', '18014398509481990', true],
            'from 2^54 on without the sign' => ['-18014398509481992.0', '-18014398509481990', false],
            'below 10^17, digits' => ['1e16', '10000000000000000', false],
            'the largest floating-point number below 10^17, digits' => ['99999999999999984.0', '99999999999999980', false],
            'from 10^17 on, an exponent' => ['1e17', '1.0e+17', false],
            '1e18 with an exponent' => ['1e18', '1.0e+18', true],
            'from 10^17 on without the sign' => ['-1e18', '-1.0e+18', false],
            'the same number as digits stays an integer' => ['1000000000000000000', '1000000000000000000', false],
        ];
    }

    /**
     * The docs give the example, and the code compares it as they say.
     *
     * @dataProvider documentedExampleProvider
     */
    public function testTheExtraVarsRuleComparesTheDocumentedExamplesAsDocumented(string $stored, string $saved, bool $changed, string $documented): void
    {
        $this->assertMentions($documented, $this->flat('docs/updating.md', self::UPDATE_SECTION), 'updating.md, on the Extra Vars rule,');
        $user = $this->createUser('docs-compare');
        $template = $this->createPasswordSurveyTemplate((int)$user->id);
        $template->extra_vars = $stored;
        $this->assertTrue($template->save(false));
        $legacy = JobTemplate::findOne($template->id);
        $this->assertNotNull($legacy);

        $legacy->extra_vars = $saved;
        $legacy->validate(['extra_vars']);

        $refusal = sprintf(TemplateSurveyValidator::PASSWORD_KEY_IN_EXTRA_VARS, '"' . self::SURVEY_PASSWORD_FIELD . '"');
        $this->assertSame($changed ? [$refusal] : [], $legacy->getErrors('extra_vars'));
    }

    /**
     * The docs say that a number written another way counts as unchanged
     * (documentedExampleProvider), although a launch passes the form with a
     * fraction or an exponent as PHP writes the floating-point number: from
     * 2^54 on that can be another whole number, and from 10^17 on it has an
     * exponent, which the playbook reads as a floating-point number. The job
     * stores it so, and the runner gets it so in the command.
     *
     * Regression (R3V2-2): updating.md said the Extra Vars change when their
     * JSON value does "as a launch reads it", and did not say that the save
     * counts {"n": 1e18} over {"n": 1000000000000000000} as no change while
     * a launch passes 1.0e+18 for the first and the integer for the second.
     *
     * @dataProvider launchedNumberProvider
     */
    public function testALaunchPassesSuchANumberAsPhpWritesTheFloatingPointNumber(string $written, string $launched, bool $example): void
    {
        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $statements = [
            'Such a whole number counts as unchanged, but a launch passes the form with a fraction or an exponent as PHP writes the floating-point number, in the fewest digits that read back as it.',
            'Without the sign, from 2^54 (`' . 2 ** 54 . '`) on, that can be another whole number',
            'from 10^17 on it has an exponent and reaches the playbook as a floating-point number',
        ];
        if ($example) {
            $statements[] = 'such as `' . $launched . '` for `' . $written . '`';
        }
        foreach ($statements as $statement) {
            $this->assertMentions($statement, $updating, 'updating.md, on the Extra Vars rule,');
        }
        $userId = (int)$this->createUser('docs-launch')->id;
        $template = $this->templateWithExtraVars($userId, '{"n": ' . $written . '}');

        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');
        $job = $launcher->launch($template, $userId);
        /** @var JobClaimService $claims */
        $claims = \Yii::$app->get('jobClaimService');
        $payload = $claims->buildExecutionPayload($job);

        $passed = '{"n":' . $launched . '}';
        $this->assertSame($passed, $job->extra_vars, 'the job stores it so');
        $this->assertSame($passed, $payload['extra_vars'], 'the runner gets it so');
        $at = array_search('--extra-vars', $payload['command'], true);
        $this->assertIsInt($at, 'the command passes the extra vars');
        $this->assertSame($passed, $payload['command'][$at + 1] ?? null, 'the command passes them so');
    }

    /**
     * Below 2^54 every whole floating-point number is written with its own
     * digits, as the docs imply by naming 2^54: from 2^53 up to 2^54 the
     * floating-point numbers are 2 apart, so no number with fewer digits
     * reads back as the same one. Checked with the encoder of the launch,
     * at both ends of that range and on both sides of zero.
     */
    public function testBelow2To54ALaunchWritesAWholeNumberWithItsOwnDigits(): void
    {
        $checked = 0;
        $otherDigits = [];
        for ($step = 0; $step < 20000; $step++) {
            foreach ([2 ** 53 + 2 * $step, 2 ** 54 - 2 - 2 * $step] as $whole) {
                foreach ([$whole, -$whole] as $number) {
                    $checked++;
                    if (JobLaunchSecrets::encode(['n' => (float)$number], JobLaunchSecrets::EXTRA_VARS) !== '{"n":' . $number . '}') {
                        $otherDigits[] = $number;
                    }
                }
            }
        }
        $this->assertSame(80000, $checked);
        $this->assertSame([], $otherDigits, 'A launch writes these whole numbers with other digits.');
        $this->assertSame('{"n":18014398509481990}', JobLaunchSecrets::encode(['n' => 18014398509481992.0], JobLaunchSecrets::EXTRA_VARS), 'from 2^54 on, it can be another whole number');
    }

    /**
     * What the docs say since V1-NI-1: the job runs with the template's
     * extra vars, and the password answer in the ignored text is stored
     * nowhere. Answering 422 instead needs the docs changed.
     */
    public function testExtraVarsTextThatIsNotValidJsonIsIgnored(): void
    {
        $admin = $this->authenticateApi();
        $template = $this->createPasswordSurveyTemplate((int)$admin->id);
        $template->extra_vars = '{"app_env": "staging"}';
        $this->assertTrue($template->save(false));
        $this->setBody([
            'job_template_id' => $template->id,
            'extra_vars' => '{"app_env": "prod", "db_password": "docs-ignored-marker-3318' . "\xFF" . '"}',
        ]);

        $result = (new JobsController('api/v1/jobs', $this->app()))->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertIsArray($result['data'] ?? null);
        $job = Job::findOne($result['data']['id'] ?? 0);
        $this->assertNotNull($job);
        $this->assertSame(['app_env' => 'staging'], json_decode((string)$job->extra_vars, true), 'the template\'s extra vars');
        $this->assertNull($job->getSealedSecretExtraVars(), 'no answer is stored');
        $this->assertStringNotContainsString('docs-ignored-marker-3318', (string)$job->runner_payload);
    }

    public function testAJobTemplateThatHoldsANumberTooLargeForJsonIsRefusedOnEveryUpdate(): void
    {
        $admin = $this->authenticateApi();
        $template = $this->legacyTemplate((int)$admin->id);
        $this->setBody(['description' => 'docs-changed']);

        $result = (new JobTemplatesController('api/v1/job-templates', $this->app()))->actionUpdate((int)$template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::tooLargeMessage()]], $result);
        $this->assertNotSame('docs-changed', JobTemplate::findOne($template->id)?->description);
    }

    public function testCloningAJobTemplateThatHoldsANumberTooLargeForJsonFails(): void
    {
        $admin = $this->createUserWithRole('docs-clone', 'admin');
        $this->loginAs($admin);
        $template = $this->legacyTemplate((int)$admin->id);
        $templates = JobTemplate::findWithDeleted()->count();
        $controller = new class ('job-template', $this->app()) extends JobTemplateController {
            /**
             * @param array<int|string, mixed>|string $url
             * @param int $statusCode
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };

        $controller->actionClone((int)$template->id);

        $this->assertSame('Clone failed: ' . self::tooLargeMessage() . ' Fix the source template first.', \Yii::$app->session->getFlash('danger'));
        $this->assertSame($templates, JobTemplate::findWithDeleted()->count(), 'no clone is created');
    }

    /**
     * A step's extra_vars_template sent as an object with a number too large
     * for JSON (1e999 in a JSON body, which Yii's JSON parser reads as
     * infinity) answers 422 with the message of JSON text, and nothing is
     * written, as the docs say. 2.8.1 stored the step without its extra
     * vars and answered 201.
     */
    public function testAWorkflowStepObjectWithANumberTooLargeForJsonIsRefusedAsTheDocsSay(): void
    {
        $this->authenticateApi();
        $this->setBody([
            'name' => 'docs-step-object',
            'steps' => [['name' => 'Wait', 'step_type' => WorkflowStep::TYPE_PAUSE, 'extra_vars_template' => ['timeout_s' => INF]]],
        ]);
        $workflows = WorkflowTemplate::findWithDeleted()->count();

        $result = (new WorkflowTemplatesController('api/v1/workflow-templates', $this->app()))->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'steps[0]: ' . self::tooLargeMessage()]], $result);
        $this->assertSame($workflows, WorkflowTemplate::findWithDeleted()->count(), 'nothing is written');

        $form = 'given as JSON text or as an object';
        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $this->assertMentions('`extra_vars_template`, ' . $form . ', as `steps[<n>]: ...`', $updating, 'updating.md, among the fixes,');
        $this->assertMentions('`extra_vars_template` with such a number, ' . $form, $updating, 'updating.md, among the API contract changes,');
        $troubleshooting = $this->flat('docs/troubleshooting.md', '## Launch or save refused: a number too large for JSON');
        $this->assertMentions('`extra_vars_template`, ' . $form . ' (`steps[<n>]: ...`)', $troubleshooting, 'troubleshooting.md');
    }

    /**
     * A job template stored with such extra vars before the rule.
     */
    private function legacyTemplate(int $userId): JobTemplate
    {
        return $this->templateWithExtraVars($userId, self::TOO_LARGE);
    }

    /**
     * A job template without a survey whose Extra Vars are $extraVars as
     * written, stored without validation.
     */
    private function templateWithExtraVars(int $userId, string $extraVars): JobTemplate
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        $template->extra_vars = $extraVars;
        $this->assertTrue($template->save(false));

        return $template;
    }

    private static function tooLargeMessage(): string
    {
        return SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN);
    }

    /**
     * An admin with an API token, signed in with it.
     */
    private function authenticateApi(): User
    {
        $admin = $this->createUserWithRole('docs-api', 'admin');
        ['raw' => $raw] = ApiToken::generate((int)$admin->id, 'docs-api');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $user */
        $user = \Yii::$app->user;
        $user->loginByAccessToken($raw);

        return $admin;
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

    private function app(): Module
    {
        $this->assertNotNull(\Yii::$app);

        return \Yii::$app;
    }
}
