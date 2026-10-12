<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobTemplatesController;
use app\models\ApiToken;
use app\models\JobTemplate;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\unit\components\UnusablePasswordNames;

/**
 * POST/PUT /api/v1/job-templates: the survey rules answer 422 with their
 * message, never 500, and only for surveys and extra vars that change.
 */
class JobTemplatesControllerSurveyRulesTest extends WebControllerTestCase
{
    use UnusablePasswordNames;

    private const NAME_RULE = 'Survey field name %s is not a valid variable name: use letters, digits and underscores, '
        . 'and start with a letter or an underscore.';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->authenticateAsAdmin();
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function rejectedSurveyProvider(): array
    {
        $name = 'Survey field name %s is not a valid variable name: use letters, digits and underscores, and start with a letter or an underscore.';

        return [
            'invalid name' => [[['name' => 'db-host', 'type' => 'text']], sprintf($name, '"db-host"')],
            'duplicate name' => [[['name' => 'env'], ['name' => 'env']], 'Survey field name "env" is used more than once.'],
            'unknown type' => [[['name' => 'env', 'type' => 'secret']], 'Survey field "env" has an unknown type "secret"; use one of: text, textarea, integer, boolean, select, password.'],
            'password default' => [[['name' => 'pw', 'type' => 'password', 'default' => 'hunter2']], 'A password field cannot have a default value.'],
            'name as an object' => [[['name' => ['a' => 1], 'type' => 'text']], sprintf($name, '{"a":1}')],
            'type as a list' => [[['name' => 'env', 'type' => ['text']]], 'Survey field "env" has an unknown type ["text"]; use one of: text, textarea, integer, boolean, select, password.'],
            'default as a list' => [[['name' => 'env', 'default' => ['a']]], 'The default of survey field "env" must be a single value, not a list or an object.'],
            'entry that is no object' => [['env'], 'Survey field 1 must be a JSON object with a name and a type.'],
            'not a list' => ['"env"', 'Survey fields must be a JSON list of field definitions.'],
        ];
    }

    /**
     * Regression: the API stored any survey JSON; malformed entries later
     * failed the edit and launch pages with a 500.
     *
     * @dataProvider rejectedSurveyProvider
     */
    public function testACreateWithABadSurveyIs422(mixed $survey, string $message): void
    {
        $this->setBody($this->templateBody() + ['survey_fields' => $survey]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $message]], $result);
    }

    /**
     * Padding and line breaks an API client can send in a name, for example
     * from a YAML block scalar.
     *
     * Regression: POST and PUT stored a password field named
     * "db_password\n", and a PUT could add padding to a stored name. No
     * caller sends that exact name, so its answers were stored in plaintext.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function paddedNameProvider(): array
    {
        return [
            'line feed' => ["db_password\n", '"db_password\n"'],
            'CRLF' => ["db_password\r\n", '"db_password\r\n"'],
            'leading space' => [' db_password', '" db_password"'],
            'trailing space' => ['db_password ', '"db_password "'],
            'tab' => ["db_password\t", '"db_password\t"'],
        ];
    }

    /**
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testACreateWithAPasswordNameNoCallerSendsExactlyIs422(string $name, string $quoted): void
    {
        $this->setBody($this->templateBody() + ['survey_fields' => [['name' => $name, 'type' => 'password']]]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(self::NAME_RULE, $quoted)]], $result);
    }

    /**
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testAnUpdateThatGivesAStoredPasswordNameSuchACharacterIs422(string $name, string $quoted): void
    {
        $template = $this->legacyTemplate('[{"name":"db_password","type":"password"}]');
        $this->setBody(['survey_fields' => [['name' => $name, 'type' => 'password']]]);

        $result = $this->controller()->actionUpdate((int)$template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(self::NAME_RULE, $quoted)]], $result);
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);
        $this->assertSame(['db_password'], $stored->passwordFieldNames(), 'the stored survey is unchanged');
    }

    /**
     * A legacy password name that no caller sends exactly: an update that
     * leaves the survey alone saves, one that changes the survey and keeps
     * the name is 422, and so is retyping a field with such a name to
     * password.
     *
     * @dataProvider paddedNameProvider
     * @dataProvider unicodeNameProvider
     */
    public function testAChangedSurveyCannotKeepOrRetypeSuchAPasswordName(string $name, string $quoted): void
    {
        $password = $this->legacyTemplate((string)json_encode([['name' => $name, 'type' => 'password']]));
        $this->setBody(['playbook' => 'other.yml']);
        $result = $this->controller()->actionUpdate((int)$password->id);
        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));

        $this->setBody(['survey_fields' => [['name' => $name, 'type' => 'password'], ['name' => 'env', 'type' => 'text']]]);
        $result = $this->controller()->actionUpdate((int)$password->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(self::NAME_RULE, $quoted)]], $result);

        $text = $this->legacyTemplate((string)json_encode([['name' => $name, 'type' => 'text']]));
        $this->setBody(['survey_fields' => [['name' => $name, 'type' => 'password']]]);
        $result = $this->controller()->actionUpdate((int)$text->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(self::NAME_RULE, $quoted)]], $result);
        $stored = JobTemplate::findOne($text->id);
        $this->assertNotNull($stored);
        $this->assertSame((string)json_encode([['name' => $name, 'type' => 'text']]), $stored->survey_fields, 'nothing is stored');
    }

    /**
     * Regression (V2-C2): a PUT that changed the value of a legacy password
     * key in a way the comparison took for a reformat (a list sent as an
     * object, a number that decodes to the same float) saved.
     *
     * Regression (R2V1-DOC-CMP, R2V2-2): so did a PUT that sent an integer
     * beyond the int range as text with the same digits, or the other way
     * round, although the launch reads one as a float and the other as text.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function realChangeProvider(): array
    {
        return [
            'a list that became an object' => ['{"db_password": ["a", "b"]}', '{"db_password":{"0":"a","1":"b"}}'],
            'an integer above 2^53 that became the nearest float' => ['{"db_password": 9007199254740993}', '{"db_password":9007199254740992.0}'],
            'an integer beyond the int range that changed' => ['{"db_password": 12345678901234567890}', '{"db_password":12345678901234567999}'],
            'an integer beyond the int range that became text' => ['{"db_password": 12345678901234567890}', '{"db_password":"12345678901234567890"}'],
            'text that became an integer beyond the int range' => ['{"db_password": "12345678901234567890"}', '{"db_password":12345678901234567890}'],
            'an integer beyond the int range sent with an exponent' => ['{"db_password": 100000000000000000000}', '{"db_password":1e20}'],
        ];
    }

    /**
     * @dataProvider realChangeProvider
     */
    public function testAChangeOfALegacyPasswordKeysValueIs422(string $stored, string $changed): void
    {
        $template = $this->legacyTemplate('[{"name":"db_password","type":"password"}]');
        $template->extra_vars = $stored;
        $this->assertTrue($template->save(false));
        $this->setBody(['extra_vars' => $changed]);

        $result = $this->controller()->actionUpdate((int)$template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => 'Remove "db_password" from Extra Vars: a password survey field cannot have a default; enter it at launch.']],
            $result
        );
        $reloaded = JobTemplate::findOne($template->id);
        $this->assertNotNull($reloaded);
        $this->assertSame($stored, $reloaded->extra_vars, 'nothing is stored');
    }

    /**
     * What an API client posts back when its JSON library decodes and
     * re-encodes the stored Extra Vars: the same numbers written another
     * way.
     *
     * Regression (R2V1-DOC-CMP, R2V2-2): a whole number beyond 2^53 written
     * another way with the same value was a change, so a PUT that changed
     * only the description of a legacy template with a password key was
     * refused with the misleading "Remove "db_password" from Extra Vars".
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sameNumberWrittenAnotherWayProvider(): array
    {
        return [
            'a whole float beyond 2^53 sent as an integer' => [
                '{"db_password": "legacy", "max_id": 9007199254740994.0}',
                '{"db_password":"legacy","max_id":9007199254740994}',
            ],
            'the whole float 2^60 sent as an integer' => [
                '{"db_password": "legacy", "max_id": 1152921504606846976.0}',
                '{"db_password":"legacy","max_id":1152921504606846976}',
            ],
            '1e18 sent with all its digits' => [
                '{"db_password": "legacy", "quota": 1e18}',
                '{"db_password":"legacy","quota":1000000000000000000}',
            ],
            'the password key itself, 1e18 sent with all its digits' => [
                '{"db_password": 1e18}',
                '{"db_password":1000000000000000000}',
            ],
        ];
    }

    /**
     * @dataProvider sameNumberWrittenAnotherWayProvider
     */
    public function testAPutThatSendsTheSameNumbersWrittenAnotherWayKeepsTheLegacyKey(string $stored, string $sent): void
    {
        $template = $this->legacyTemplate('[{"name":"db_password","type":"password"}]');
        $template->extra_vars = $stored;
        $this->assertTrue($template->save(false));
        $this->setBody(['description' => 'only the description changed', 'extra_vars' => $sent]);

        $result = $this->controller()->actionUpdate((int)$template->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $reloaded = JobTemplate::findOne($template->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('only the description changed', $reloaded->description);
        $this->assertSame($sent, $reloaded->extra_vars);
    }

    public function testExtraVarsWithAPasswordFieldsKeyAre422(): void
    {
        $this->setBody($this->templateBody() + [
            'survey_fields' => [['name' => 'db_password', 'type' => 'password']],
            'extra_vars' => '{"db_password":"in-plaintext"}',
        ]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => 'Remove "db_password" from Extra Vars: a password survey field cannot have a default; enter it at launch.']],
            $result
        );
    }

    public function testAValidSurveyIsCreated(): void
    {
        $this->setBody($this->templateBody() + ['survey_fields' => [['name' => 'env', 'type' => 'text'], ['name' => 'db_password', 'type' => 'password']]]);

        $result = $this->controller()->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertTrue($result['data']['has_survey']);
    }

    public function testAnUpdateOfALegacyTemplateThatLeavesTheSurveyAloneSucceeds(): void
    {
        $template = $this->legacyTemplate();
        $this->setBody(['playbook' => 'other.yml']);

        $result = $this->controller()->actionUpdate((int)$template->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame('other.yml', $result['data']['playbook']);
    }

    public function testAnUpdateThatAddsABadFieldToALegacySurveyIs422(): void
    {
        $template = $this->legacyTemplate();
        $this->setBody(['survey_fields' => [['name' => 'db-host', 'type' => 'text'], ['name' => 'new-field', 'type' => 'text']]]);

        $result = $this->controller()->actionUpdate((int)$template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(
            ['error' => ['message' => 'Survey field name "new-field" is not a valid variable name: use letters, digits and underscores, and start with a letter or an underscore.']],
            $result
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function templateBody(): array
    {
        return [
            'name' => 'api-survey-rules-' . uniqid('', true),
            'project_id' => (int)$this->createProject($this->userId)->id,
            'inventory_id' => (int)$this->createInventory($this->userId)->id,
            'runner_group_id' => (int)$this->createRunnerGroup($this->userId)->id,
            'playbook' => 'site.yml',
        ];
    }

    private function legacyTemplate(string $survey = '[{"name":"db-host","type":"text"}]'): JobTemplate
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
        $template->survey_fields = $survey;
        $template->save(false);

        return $template;
    }

    private function controller(): JobTemplatesController
    {
        return new JobTemplatesController('api/v1/job-templates', \Yii::$app);
    }

    private function authenticateAsAdmin(): int
    {
        $user = $this->createUser('templates-api-survey');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole('admin');
        $this->assertNotNull($role);
        $auth->assign($role, (string)$user->id);
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'templates-api-survey');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $component */
        $component = \Yii::$app->user;
        $component->loginByAccessToken($raw);

        return (int)$user->id;
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
}
