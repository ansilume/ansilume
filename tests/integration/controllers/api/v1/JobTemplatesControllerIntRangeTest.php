<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobTemplatesController;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedJobTemplateLock;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;
use yii\web\JsonParser;
use yii\web\Request;

/**
 * Regression: an integer field of POST and PUT /api/v1/job-templates sent as
 * a JSON number beyond the int range (1e30, 9223372036854775808, -1e30, or
 * 1e999, which a JSON parser reads as infinity) reached an (int) cast before
 * validation. On PHP 8.5 the cast warns ("The float 1.0E+30 is not
 * representable as an int, cast occurred"), the error handler turns the
 * warning into an exception, and the request answered 500 instead of 422.
 * Up to PHP 8.4 the cast wrapped the number to an arbitrary int and made
 * 1e999 0, so "verbosity": 1e999 saved verbosity 0.
 *
 * Now the field's integer rule refuses such a number: 422 with the field's
 * message, nothing written, no audit entry, no error logged. The lock of a
 * gated template refuses it first for users who may not change what the
 * template runs (403 GatedJobTemplateLock::CHANGE_REFUSED), as it refuses
 * every other change of the field. Whole numbers keep their answers.
 *
 * Each body is a literal JSON text, read by the request's JSON parser as
 * config/web.php sets it up, so the values are the floats a request gives.
 */
class JobTemplatesControllerIntRangeTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** JSON numbers beyond the int range, as a client writes them. */
    private const NUMBERS = ['1e30', '9223372036854775808', '-1e30', '1e999'];

    /** The integer fields of the body, with the message of each one's integer rule. */
    private const MESSAGES = [
        'project_id' => 'Project Id must be an integer.',
        'inventory_id' => 'Inventory Id must be an integer.',
        'credential_id' => 'Credential Id must be an integer.',
        'runner_group_id' => 'Runner must be an integer.',
        'verbosity' => 'Verbosity must be an integer.',
        'forks' => 'Forks must be an integer.',
        'timeout_minutes' => 'Timeout Minutes must be an integer.',
    ];

    private JobTemplatesController $ctrl;
    private User $admin;
    private Project $project;
    private Inventory $inventory;
    private RunnerGroup $group;
    private Credential $credential;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotNull(\Yii::$app);
        $this->ctrl = new JobTemplatesController('api/v1/job-templates', \Yii::$app);
        $this->admin = $this->createUserWithRole('api-ir-admin', 'admin');
        $ownerId = (int)$this->admin->id;
        $this->project = $this->createProject($ownerId);
        $this->inventory = $this->createInventory($ownerId);
        $this->group = $this->createRunnerGroup($ownerId);
        $this->credential = $this->createCredential($ownerId);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function beyondTheIntRangeProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::MESSAGES) as $field) {
            foreach (self::NUMBERS as $number) {
                $cases["{$field}: {$number}"] = [$field, $number];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testACreateWithANumberBeyondTheIntRangeIs422AndWritesNothing(string $field, string $number): void
    {
        $this->loginAs($this->admin);
        $name = 'api-ir-create-' . uniqid('', true);
        $audits = $this->templateAuditCount();
        $this->sendJsonText($this->json($this->createFields($name), $field, $number), 'POST', $field);

        [$result, $errors] = $this->withLoggedErrors(fn (): array => $this->ctrl->actionCreate());

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::MESSAGES[$field]]], $result);
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists(), 'no template was created');
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing was audited');
        $this->assertSame([], $errors, 'nothing was logged as an error');
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAnUpdateWithANumberBeyondTheIntRangeIs422AndWritesNothing(string $field, string $number): void
    {
        $template = $this->template(null);
        $before = $this->fresh($template)->getAttributes();
        $audits = $this->templateAuditCount();
        $this->loginAs($this->admin);
        $this->sendJsonText($this->json(['name' => 'api-ir-renamed-' . uniqid('', true)], $field, $number), 'PUT', $field);

        [$result, $errors] = $this->withLoggedErrors(fn (): array => $this->ctrl->actionUpdate((int)$template->id));

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => self::MESSAGES[$field]]], $result);
        $this->assertSame($before, $this->fresh($template)->getAttributes(), 'not even the name was written');
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing was audited');
        $this->assertSame([], $errors, 'nothing was logged as an error');
    }

    /**
     * The fields the lock reads first: all but the project, which is refused
     * before the access checks (testTheProjectIsRefusedBeforeTheLock()).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lockedFieldProvider(): array
    {
        return array_filter(
            self::beyondTheIntRangeProvider(),
            static fn (array $case): bool => $case[0] !== 'project_id'
        );
    }

    /**
     * For a user who may not change what a gated template runs, such a
     * number is a change of the field like any other value but the stored
     * one: the lock refuses it before validation. (Up to PHP 8.4 the cast
     * wrapped it to another int, a change too, but made 1e999 0, which a
     * field stored as 0 took for no change.)
     *
     * @dataProvider lockedFieldProvider
     */
    public function testTheLockOfAGatedTemplateRefusesSuchANumberFirst(string $field, string $number): void
    {
        $operator = $this->createUserWithRole('api-ir-operator', 'operator');
        $template = $this->template((int)$this->createApprovalRule((int)$this->admin->id)->id);
        $before = $this->fresh($template)->getAttributes();
        $audits = $this->templateAuditCount();
        $this->loginAs($operator);
        $this->sendJsonText($this->json(['name' => 'api-ir-forged-' . uniqid('', true)], $field, $number), 'PUT', $field);

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => GatedJobTemplateLock::CHANGE_REFUSED]], $result);
        $this->assertSame($before, $this->fresh($template)->getAttributes());
        $this->assertSame($audits, $this->templateAuditCount());
    }

    /**
     * The lock compares a field's value after the column's type cast, PHP's
     * (int): a value that the cast reads as the stored one is no change to
     * it. A body that changes nothing else the lock guards then gets the
     * field's 422 from validation, also from a user the lock applies to;
     * any other change of a guarded field, such as the tags, brings the
     * lock's 403 back. The template stores forks 5 and verbosity 0 unless
     * the case stores another value.
     *
     * @return array<string, array{0: string, 1: array<string, int>, 2: int, 3: string}>
     */
    public static function valueTheCastReadsAsTheStoredOneProvider(): array
    {
        $forks = self::MESSAGES['forks'];
        $verbosity = self::MESSAGES['verbosity'];
        $refused = GatedJobTemplateLock::CHANGE_REFUSED;

        return [
            'forks 5.5 where 5 is stored' => ['{"forks": 5.5}', [], 422, $forks],
            'forks "5abc" where 5 is stored' => ['{"forks": "5abc"}', [], 422, $forks],
            'forks " 5" where 5 is stored' => ['{"forks": " 5"}', [], 422, $forks],
            'forks true where 1 is stored' => ['{"forks": true}', ['forks' => 1], 422, $forks],
            'verbosity "abc" where 0 is stored' => ['{"verbosity": "abc"}', [], 422, $verbosity],
            'verbosity false where 0 is stored' => ['{"verbosity": false}', [], 422, $verbosity],
            'verbosity 0.5 where 0 is stored' => ['{"verbosity": 0.5}', [], 422, $verbosity],
            'forks "abc" where 5 is stored' => ['{"forks": "abc"}', [], 403, $refused],
            'forks true where 5 is stored' => ['{"forks": true}', [], 403, $refused],
            'forks 5.5 with a change of the tags' => ['{"forks": 5.5, "tags": "x"}', [], 403, $refused],
        ];
    }

    /**
     * Pins what docs/updating.md and the spec's JobTemplateRejected say of
     * the order (Php85UpdateDocsContractTest::testTheDocsSayWhichChecksComeBeforeTheProjectsError):
     * a review found that they said the lock's 403 always came before these
     * 422s. Nothing is written and nothing is audited either way.
     *
     * @dataProvider valueTheCastReadsAsTheStoredOneProvider
     * @param array<string, int> $stored
     */
    public function testTheLockTakesAValueThatTheCastReadsAsTheStoredOneForNoChange(string $json, array $stored, int $status, string $message): void
    {
        $operator = $this->createUserWithRole('api-ir-cast-operator', 'operator');
        $template = $this->template((int)$this->createApprovalRule((int)$this->admin->id)->id);
        if ($stored !== []) {
            JobTemplate::updateAll($stored, ['id' => $template->id]);
        }
        $before = $this->fresh($template)->getAttributes();
        $audits = $this->templateAuditCount();
        $this->loginAs($operator);
        $this->sendJsonText($json, 'PUT');

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame($status, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame(['error' => ['message' => $message]], $result);
        $this->assertSame($before, $this->fresh($template)->getAttributes(), 'nothing was written');
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing was audited');
    }

    /**
     * A project beyond the int range names no project the access checks
     * could clear: its integer rule answers 422 before them and before the
     * lock of a gated template, also for a user the lock applies to; nothing
     * is written.
     */
    public function testTheProjectIsRefusedBeforeTheLock(): void
    {
        $operator = $this->createUserWithRole('api-ir-project-operator', 'operator');
        $template = $this->template((int)$this->createApprovalRule((int)$this->admin->id)->id);
        $before = $this->fresh($template)->getAttributes();
        $this->loginAs($operator);
        foreach (self::NUMBERS as $number) {
            $this->sendJsonText($this->json(['name' => 'api-ir-forged-' . uniqid('', true)], 'project_id', $number), 'PUT', 'project_id');

            $this->assertSame(['error' => ['message' => self::MESSAGES['project_id']]], $this->ctrl->actionUpdate((int)$template->id), $number);
            $this->assertSame(422, \Yii::$app->response->statusCode);
        }
        $this->assertSame($before, $this->fresh($template)->getAttributes());
    }

    /**
     * The approval rule, which only JobTemplateRuleAssignment sets: an
     * admin's number beyond the int range is refused by its integer rule,
     * anyone else's as a rule change.
     */
    public function testAnApprovalRuleBeyondTheIntRangeIsRefused(): void
    {
        $template = $this->template(null);
        $before = $this->fresh($template)->getAttributes();
        foreach (self::NUMBERS as $number) {
            $this->loginAs($this->admin);
            $this->sendJsonText($this->json(['name' => 'api-ir-rule'], 'approval_rule_id', $number), 'PUT', 'approval_rule_id');
            $this->assertSame(['error' => ['message' => 'Approval rule must be an integer.']], $this->ctrl->actionUpdate((int)$template->id), $number);
            $this->assertSame(422, \Yii::$app->response->statusCode);

            $this->loginAs($this->createUserWithRole('api-ir-rule-operator', 'operator'));
            $this->sendJsonText($this->json(['name' => 'api-ir-rule'], 'approval_rule_id', $number), 'PUT', 'approval_rule_id');
            $this->assertSame(
                ['error' => ['message' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED]],
                $this->ctrl->actionUpdate((int)$template->id),
                $number
            );
            $this->assertSame(403, \Yii::$app->response->statusCode);
        }
        $this->assertSame($before, $this->fresh($template)->getAttributes());
    }

    /**
     * The access check reads the project as the save stores it
     * (SubmittedInteger): a project the caller may not operate in is still
     * 403, written as an int, as digits or as a whole float. A value that is
     * no ID, which the (int) cast had made the ID it starts with, is refused
     * by the integer rule before the check, which never takes it for no
     * project: nothing is saved there either, and the answer says nothing
     * about the project.
     */
    public function testTheProjectIsCheckedAsTheSaveStoresIt(): void
    {
        ['member' => $member, 'foreign' => $foreign] = $this->teamScope();
        $foreignProject = (int)$foreign->project_id;
        $this->loginAs($member);
        $name = 'api-ir-foreign-' . uniqid('', true);
        foreach (["{$foreignProject}", "\"{$foreignProject}\"", "{$foreignProject}.0"] as $value) {
            $this->sendJsonText($this->json($this->createFields($name), 'project_id', $value), 'POST');
            $this->assertSame(['error' => ['message' => 'Forbidden.']], $this->ctrl->actionCreate(), $value);
            $this->assertSame(403, \Yii::$app->response->statusCode);
        }
        foreach (["{$foreignProject}.5", "\"{$foreignProject}.5\"", "\" {$foreignProject}\"", "1e30"] as $value) {
            $this->sendJsonText($this->json($this->createFields($name), 'project_id', $value), 'POST');
            $this->assertSame(['error' => ['message' => 'Project Id must be an integer.']], $this->ctrl->actionCreate(), $value);
            $this->assertSame(422, \Yii::$app->response->statusCode);
        }
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists(), 'nothing was created');
    }

    /**
     * A whole number keeps its answer: as an int, as its digits, or as a
     * float without a fraction (5.0).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function wholeNumberProvider(): array
    {
        return [
            'verbosity as an int' => ['verbosity', '3'],
            'verbosity as digits' => ['verbosity', '"3"'],
            'verbosity as a whole float' => ['verbosity', '3.0'],
            'forks as an int' => ['forks', '3'],
            'forks as digits with a plus sign' => ['forks', '"+3"'],
            'timeout as an int' => ['timeout_minutes', '3'],
            'timeout as digits with a leading zero' => ['timeout_minutes', '"03"'],
        ];
    }

    /**
     * @dataProvider wholeNumberProvider
     */
    public function testAWholeNumberKeepsItsAnswer(string $field, string $number): void
    {
        $this->loginAs($this->admin);
        $this->sendJsonText($this->json($this->createFields('api-ir-whole-' . uniqid('', true)), $field, $number), 'POST');

        $result = $this->ctrl->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        // The answer carries verbosity and forks, not the timeout: the stored row tells.
        $this->assertSame(3, $this->fresh($this->templateOf($this->data($result)))->$field);
    }

    /**
     * The IDs keep their answers too: an existing one is stored, as an int
     * or as its digits, and null clears the primary credential.
     */
    public function testTheIdsOfTheBodyKeepTheirAnswers(): void
    {
        $this->loginAs($this->admin);
        $fields = $this->createFields('api-ir-ids-' . uniqid('', true));
        $fields['credential_id'] = (string)$this->credential->id;
        $fields['project_id'] = (string)$this->project->id;
        $this->sendJsonText((string)json_encode($fields), 'POST');

        $data = $this->data($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $stored = $this->fresh($this->templateOf($data));
        $this->assertSame(
            [(int)$this->project->id, (int)$this->inventory->id, (int)$this->credential->id, (int)$this->group->id],
            [$stored->project_id, $stored->inventory_id, $stored->credential_id, $stored->runner_group_id]
        );

        $this->sendJsonText('{"credential_id": null}', 'PUT');
        $data = $this->data($this->ctrl->actionUpdate((int)$stored->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertNull($data['credential_id']);
        $this->assertNull($this->fresh($stored)->credential_id);
    }

    /**
     * Values that are no whole number and no empty value are left to the
     * integer rule now, which refuses them; the (int) cast had made a
     * fraction, a text or a boolean a number (5.5 was 5, "abc" 0, true 1).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noWholeNumberProvider(): array
    {
        return [
            'a fraction' => ['5.5', 'Forks must be an integer.'],
            'a fraction as text' => ['"5.5"', 'Forks must be an integer.'],
            'an exponent as text' => ['"1e1"', 'Forks must be an integer.'],
            'digits with a space' => ['" 5"', 'Forks must be an integer.'],
            'a text' => ['"abc"', 'Forks must be an integer.'],
            'true' => ['true', 'Forks must be an integer.'],
            'false' => ['false', 'Forks must be an integer.'],
            'a list' => ['[5]', 'Forks must be an integer.'],
        ];
    }

    /**
     * @dataProvider noWholeNumberProvider
     */
    public function testAValueThatIsNoWholeNumberIs422(string $value, string $message): void
    {
        $template = $this->template(null);
        $before = $this->fresh($template)->getAttributes();
        $this->loginAs($this->admin);
        $this->sendJsonText($this->json(['name' => 'api-ir-other'], 'forks', $value), 'PUT');

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $message]], $result);
        $this->assertSame($before, $this->fresh($template)->getAttributes());
    }

    /**
     * An empty value (null or "") keeps the answer of the former cast,
     * which made it 0: the integer rule skips empty values, and verbosity,
     * forks and timeout_minutes are NOT NULL columns, so passing it on would
     * fail the save. null clears only credential_id and runner_group_id. An
     * empty list, which Yii takes for empty too, is no whole number
     * (ListsForWholeNumbersTest): the cast made it 0 as well, and verbosity
     * 0 was saved.
     *
     * @return array<string, array{0: string, 1: string, 2: int, 3: string|null}>
     */
    public static function emptyValueProvider(): array
    {
        return [
            'verbosity null is 0' => ['verbosity', 'null', 200, null],
            'verbosity "" is 0' => ['verbosity', '""', 200, null],
            'verbosity [] is no whole number' => ['verbosity', '[]', 422, 'Verbosity must be an integer.'],
            'forks null is 0, too few' => ['forks', 'null', 422, 'Forks must be no less than 1.'],
            'timeout "" is 0, too few' => ['timeout_minutes', '""', 422, 'Timeout Minutes must be no less than 1.'],
            'project null is 0, no project' => ['project_id', 'null', 422, 'The selected project does not exist.'],
            'inventory "" is 0, no inventory' => ['inventory_id', '""', 422, 'The selected inventory does not exist.'],
            'credential "" is 0, no credential' => ['credential_id', '""', 422, 'The selected credential does not exist.'],
            'runner group null is none, which it needs' => ['runner_group_id', 'null', 422, 'Runner cannot be blank.'],
        ];
    }

    /**
     * @dataProvider emptyValueProvider
     */
    public function testAnEmptyValueKeepsItsAnswer(string $field, string $value, int $status, ?string $message): void
    {
        $template = $this->template(null);
        JobTemplate::updateAll(['verbosity' => 2], ['id' => $template->id]);
        $before = $this->fresh($template)->getAttributes();
        $this->loginAs($this->admin);
        $this->sendJsonText($this->json(['name' => 'api-ir-empty-' . uniqid('', true)], $field, $value), 'PUT');

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame($status, \Yii::$app->response->statusCode, (string)json_encode($result));
        if ($message !== null) {
            $this->assertSame(['error' => ['message' => $message]], $result);
            $this->assertSame($before, $this->fresh($template)->getAttributes());

            return;
        }
        $this->data($result);
        $this->assertSame(0, $this->fresh($template)->$field);
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * The fields of a valid create body.
     *
     * @return array<string, mixed>
     */
    private function createFields(string $name): array
    {
        return [
            'name' => $name,
            'project_id' => (int)$this->project->id,
            'inventory_id' => (int)$this->inventory->id,
            'playbook' => 'site.yml',
            'runner_group_id' => (int)$this->group->id,
        ];
    }

    /**
     * The JSON text of $fields with $field set to $value as written: a JSON
     * number (or any JSON value) that PHP could not encode itself.
     *
     * @param array<string, mixed> $fields
     */
    private function json(array $fields, string $field, string $value): string
    {
        $fields[$field] = '@value@';

        return str_replace('"@value@"', $value, (string)json_encode($fields));
    }

    /**
     * Sends $json as the request body, read by the JSON parser when the
     * action reads it; $floatField names a field the parser must give as a
     * float (the premise of the test).
     */
    private function sendJsonText(string $json, string $method, ?string $floatField = null): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->parsers = ['application/json' => JsonParser::class];
        $request->getHeaders()->set('Content-Type', 'application/json');
        $request->setRawBody($json);
        $request->setBodyParams(null);
        $_SERVER['REQUEST_METHOD'] = $method;
        \Yii::$app->response->statusCode = 200;
        if ($floatField !== null) {
            $body = $request->getBodyParams();
            $this->assertIsArray($body);
            $this->assertIsFloat($body[$floatField], 'the parser gives a float');
        }
    }

    /**
     * What $action returned, and the messages logged as errors meanwhile.
     *
     * @param callable(): array<string, mixed> $action
     * @return array{0: array<string, mixed>, 1: list<mixed>}
     */
    private function withLoggedErrors(callable $action): array
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
        $errors = array_values(array_filter($logger->messages, static fn (array $m): bool => $m[1] === Logger::LEVEL_ERROR));

        return [$result, array_column($errors, 0)];
    }

    /**
     * A template in the fixtures' project, inventory and runner group,
     * gated by $ruleId unless it is null.
     */
    private function template(?int $ruleId): JobTemplate
    {
        $ownerId = (int)$this->admin->id;
        $template = $this->createJobTemplate((int)$this->project->id, (int)$this->inventory->id, (int)$this->group->id, $ownerId);
        JobTemplate::updateAll(['approval_rule_id' => $ruleId, 'credential_id' => $this->credential->id], ['id' => $template->id]);

        return $this->fresh($template);
    }

    private function fresh(JobTemplate $template): JobTemplate
    {
        $fresh = JobTemplate::findOne($template->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function templateOf(array $data): JobTemplate
    {
        $template = JobTemplate::findOne($data['id']);
        $this->assertNotNull($template);

        return $template;
    }

    private function templateAuditCount(): int
    {
        return (int)AuditLog::find()
            ->where(['action' => [AuditLog::ACTION_TEMPLATE_CREATED, AuditLog::ACTION_TEMPLATE_UPDATED]])
            ->count();
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function data(array $result): array
    {
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        $this->assertIsArray($result['data']);

        return $result['data'];
    }
}
