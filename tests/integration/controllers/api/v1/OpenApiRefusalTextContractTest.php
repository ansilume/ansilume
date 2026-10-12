<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\SurveyDefinition;
use app\components\SurveySecrets;
use app\components\TemplateSurveyValidator;
use app\services\JobLaunchSecrets;
use app\services\ScheduleSecrets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What web/openapi.yaml says about refused launches, the survey rules and
 * schedule answers is what the code answers, word for word where it quotes
 * a message.
 *
 * Regressions from the review of the survey password release (findings in
 * brackets), each test fails on the spec as it was before the fix:
 * - [C3] a launch with a number JSON cannot hold is refused with 422 and a
 *   message naming the number, for the jobs API, triggers and schedules;
 * - [DOC-1] the info text said a placeholder answer "launches nothing
 *   (422)" for every caller, while /trigger/fire-workflow answers 201;
 * - [DOC-5] the path form /trigger/{token} reaches job templates only;
 * - [C1] password field names with whitespace around them or a control
 *   character cannot carry an answer;
 * - [C2] the Extra Vars rule runs on what it protects, not on every edit;
 * - [DOC-2] the env prefix applies to jobs with password answers only.
 * [PRE-1] is a guard: the trigger-token paths take only POST and DELETE,
 * and the undocumented default route stays out of the spec.
 *
 * From fix round 2:
 * - [V1-NI-1] JobLaunchInput.extra_vars said text that is not UTF-8 is
 *   refused in either form; a string that is not valid JSON is ignored.
 * - [V2-C1U] a password field name with a character outside ASCII cannot
 *   carry an answer either, and messages show such characters escaped.
 * - [V2-C2] the Extra Vars comparison tells a list from an object.
 * - [G open issue 1] job templates, schedules and workflow steps refuse a
 *   number too large for JSON when they are saved; a schedule shows the
 *   extra-vars message for its password answers too, as the model checks
 *   first.
 *
 * From fix round 3:
 * - [R2V1-DOC-CMP, R2V2-2] JobTemplateInput.extra_vars said a number
 *   written another way "with exactly the same value" is no change; beyond
 *   the 64-bit range only the same digits are, and a whole number there
 *   differs from the same digits as text.
 * - [R2V1-DOC-KEEP] SurveyField.name said a changed survey cannot keep any
 *   password field name that cannot carry an answer; it keeps an empty,
 *   numeric or bracketed one.
 * - [R2V2-1] Schedule.extra_vars said the values of a password field whose
 *   name cannot carry an answer appear as stored; they appear as
 *   $encrypted$.
 * - [C] a workflow step's extra_vars_template object with a number too
 *   large for JSON is refused too, not only JSON text with one.
 */
class OpenApiRefusalTextContractTest extends TestCase
{
    private const SCHEMAS = ['components', 'schemas'];

    /** @var array<mixed>|null */
    private ?array $spec = null;

    public function testTheLaunchRefusalsQuoteTheMessageForANumberJsonCannotHold(): void
    {
        $responses = ['components', 'responses'];
        $extraVars = SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN);
        $answers = SurveySecrets::unencodable(JobLaunchSecrets::PASSWORD_ANSWERS, JSON_ERROR_INF_OR_NAN);

        $api = $this->arrayAt([...$responses, 'JobLaunchRejected', 'content', 'application/json', 'examples']);
        $this->assertContains($extraVars, $this->exampleMessages($api, ['error', 'message']));
        $trigger = $this->arrayAt([...$responses, 'TriggerLaunchRejected', 'content', 'application/json', 'examples']);
        $this->assertContains($answers, $this->exampleMessages($trigger, ['error']));

        foreach (['JobLaunchRejected', 'TriggerLaunchRejected'] as $response) {
            $this->assertStringContainsString('too large for JSON', $this->textAt([...$responses, $response, 'description']), $response);
        }
    }

    /**
     * A number JSON cannot hold is refused in either form of extra_vars,
     * text that is not UTF-8 only in the object form: a string that is not
     * valid JSON is ignored (ExtraVarsBehaviourDocsContractTest).
     */
    public function testTheLaunchInputSaysWhichFormRefusesWhat(): void
    {
        $sentences = preg_split('/(?<=\.)\s+/', $this->textAt([...self::SCHEMAS, 'JobLaunchInput', 'properties', 'extra_vars', 'description'])) ?: [];
        $about = static fn (string $topic): array => array_values(array_filter($sentences, static fn (string $sentence): bool => str_contains($sentence, $topic)));

        $number = $about('`1e999`');
        $this->assertCount(1, $number);
        $this->assertStringContainsString('refused with 422 in either form', $number[0]);
        $utf8 = $about('not UTF-8');
        $this->assertNotEmpty($utf8);
        foreach ($utf8 as $sentence) {
            $this->assertStringNotContainsString('either form', $sentence, 'Text that is not UTF-8 is refused in the object form only.');
            $this->assertStringContainsString('in the object form', $sentence);
        }
        $this->assertStringContainsString('a string that is not valid JSON, such text included, is ignored', implode(' ', $utf8));
    }

    public function testScheduleInputListsTheMessagesOfAScheduleSave(): void
    {
        $description = $this->textAt([...self::SCHEMAS, 'ScheduleInput', 'properties', 'extra_vars', 'description']);
        $messages = [
            sprintf(ScheduleSecrets::NO_STORED_VALUE, '"<key>"'),
            sprintf(ScheduleSecrets::TEMPLATE_CHANGED, '"<key>"'),
            ScheduleSecrets::RESEND_AFTER_TEMPLATE_CHANGE,
            ScheduleSecrets::UNDECRYPTABLE,
            ScheduleSecrets::NOT_ENCODABLE,
            ScheduleSecrets::TOO_LONG,
            (new \ReflectionClassConstant(SurveySecrets::class, 'ENCRYPT_FAILED'))->getValue(),
        ];
        foreach ($messages as $message) {
            $this->assertIsString($message);
            $this->assertStringContainsString($message, $description);
        }
        // The model checks the extra vars before the answers are sealed, so
        // a password answer too large for JSON gets the extra-vars message.
        $this->assertSame(ScheduleSecrets::NOT_ENCODABLE, SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN));
        $this->assertStringContainsString(ScheduleSecrets::NOT_ENCODABLE . '` (a number such as `1e999`, in a password answer too', $description);
        $this->assertStringNotContainsString(SurveySecrets::unencodable(JobLaunchSecrets::PASSWORD_ANSWERS, JSON_ERROR_INF_OR_NAN), $description);
    }

    public function testTheInfoTextSaysWhatEachEndpointAnswersForThePlaceholder(): void
    {
        $info = $this->flatten($this->textAt(['info', 'description']));
        $start = strpos($info, '## Password survey answers');
        $this->assertNotFalse($start);
        $section = substr($info, $start, (int)strpos($info, '## Errors') - $start);

        $sentences = preg_split('/(?<=\.)\s+/', $section) ?: [];
        $workflow = array_values(array_filter($sentences, static fn (string $sentence): bool => str_contains($sentence, '`/trigger/fire-workflow`')));
        $this->assertNotEmpty($workflow, 'The info text says what a workflow trigger answers.');
        $this->assertStringContainsString('`201`', $workflow[0]);
        $this->assertStringContainsString('`POST /api/v1/jobs`', $workflow[0]);
        $this->assertStringContainsString('`422`', $workflow[0]);
    }

    public function testThePathFormIsDocumentedForJobTemplatesOnly(): void
    {
        $tags = $this->arrayAt(['tags']);
        $triggers = array_values(array_filter($tags, static fn (mixed $tag): bool => is_array($tag) && ($tag['name'] ?? null) === 'Triggers'));
        $this->assertCount(1, $triggers);
        $this->assertIsArray($triggers[0]);
        $description = $this->flatten((string)($triggers[0]['description'] ?? ''));
        $this->assertStringContainsString('the path form `/trigger/{token}` (job templates only)', $description);
        $this->assertStringContainsString('job', strtolower($this->textAt(['paths', '/trigger/{token}', 'post', 'summary'])));
    }

    public function testTheSurveyNameRulesNameEveryNameThatCannotCarryAnAnswer(): void
    {
        $name = $this->textAt([...self::SCHEMAS, 'SurveyField', 'properties', 'name', 'description']);
        $texts = [
            'SurveyField.name' => $name,
            'JobTemplateInput.survey_fields' => $this->textAt([...self::SCHEMAS, 'JobTemplateInput', 'properties', 'survey_fields', 'description']),
        ];
        foreach ($texts as $where => $text) {
            $this->assertStringContainsString('printable ASCII without a space at its start or end', $text, $where);
            $this->assertStringContainsString('exactly as stored', $text, $where);
        }
        foreach (['control character', 'non-breaking space', 'byte order mark', 'zero-width space'] as $kind) {
            $this->assertStringContainsString($kind, $name, 'SurveyField.name');
        }
        $this->assertStringContainsString('`' . SurveyDefinition::quote("db_password\u{00A0}") . '`', $name, 'Messages show such a name escaped.');
    }

    public function testTheExtraVarsRuleNamesWhatItChecks(): void
    {
        $description = $this->textAt([...self::SCHEMAS, 'JobTemplateInput', 'properties', 'extra_vars', 'description']);

        $this->assertStringContainsString(sprintf(TemplateSurveyValidator::PASSWORD_KEY_IN_EXTRA_VARS, '"<key>"'), $description);
        $this->assertStringContainsString('the names of the password fields', $description);
        $this->assertStringContainsString('and so do a list and an object', $description, 'A list never equals an object.');
        $this->assertStringContainsString('and a whole number beyond the 64-bit range and the same digits as text)', $description, 'The type counts beyond the 64-bit range too.');
        $this->assertStringContainsString(
            'a number written another way that PHP reads as the same number within the 64-bit range (`1.0` for `1`, `9007199254740994.0` for `9007199254740994`) are no change',
            $description,
            'The same number written differently does not count.'
        );
        $this->assertStringContainsString(
            'Beyond that range, a whole number written without a fraction or an exponent is the same only as the same digits (`1e20` for `100000000000000000000` is a change)',
            $description,
            'Beyond the 64-bit range only the same digits are the same number.'
        );
        $this->assertStringContainsString('a whole number that a client rounded is a change too', $description);
        $this->assertStringContainsString('A key written twice counts with its last value.', $description);
        $this->assertStringNotContainsString('with exactly the same value', $description);
        $this->assertStringContainsString(
            SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN) . '` on every save',
            $description,
            'A number too large for JSON is refused whenever the template is saved.'
        );
    }

    /**
     * Both forms of extra_vars_template refuse a number too large for JSON
     * (WorkflowTemplatesExtraVarsObjectTest, ExtraVarsBehaviourDocsContractTest);
     * an object also refuses text that is not UTF-8.
     */
    public function testAWorkflowStepNamesTheRefusalOfANumberTooLargeForJson(): void
    {
        $description = $this->textAt([...self::SCHEMAS, 'WorkflowStepInput', 'properties', 'extra_vars_template', 'description']);
        $sentences = preg_split('/(?<=\.)\s+(?=[A-Z])/', $description) ?: [];
        $number = array_values(array_filter($sentences, static fn (string $sentence): bool => str_contains($sentence, '`1e999`')));

        $this->assertCount(1, $number);
        $this->assertStringStartsWith('Either form with a number too large for JSON', $number[0], 'JSON text and an object are refused alike.');
        $this->assertStringContainsString('`steps[<n>]: ' . SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN) . '`', $description);
        $this->assertStringContainsString(
            'An object with text that is not valid UTF-8, which only a form-encoded body can carry, is rejected with 422 `steps[<n>]: '
            . SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_UTF8) . '`',
            $description
        );
    }

    /**
     * Which legacy password field names a changed survey may keep, as
     * SurveyDefinition::problems() decides: a name that is not printable
     * ASCII without a space at either end is refused, as for a field
     * retyped to password; an empty, numeric or bracketed one stays, though
     * its template cannot be launched.
     */
    public function testSurveyFieldNameSaysWhichLegacyPasswordNamesAChangedSurveyMayKeep(): void
    {
        $name = $this->textAt([...self::SCHEMAS, 'SurveyField', 'properties', 'name', 'description']);
        $this->assertStringContainsString(
            'A changed survey cannot keep a password field name that is not printable ASCII without a space at its start or end, nor give such a name to a field retyped to password; a name that is empty, numeric or has `[` or `]` may stay, but the template\'s launches stay refused.',
            $name
        );
        $this->assertStringNotContainsString('cannot keep such a password field name', $name, 'Not every name that cannot carry an answer is refused.');

        $kept = ['empty' => '', 'numeric' => '123', 'with [ or ]' => 'db[pw]'];
        $refused = ['a trailing space' => 'db_password ', 'a non-breaking space' => "db_password\u{00A0}", 'a letter outside ASCII' => "p\u{00E4}ssword", 'a tab' => "db\tpw"];
        foreach ([...$kept, ...$refused] as $kind => $legacy) {
            $this->assertFalse(SurveyDefinition::isUsablePasswordName($legacy), "A name with {$kind} cannot carry an answer.");
            $expected = isset($refused[$kind]) ? [self::invalidName($legacy)] : [];
            foreach (['kept as a password field' => 'password', 'retyped to password' => 'text'] as $change => $storedType) {
                $stored = (string)json_encode([['name' => $legacy, 'type' => $storedType]]);
                $changed = (string)json_encode([['name' => $legacy, 'type' => 'password'], ['name' => 'env', 'type' => 'text']]);
                $this->assertSame($expected, SurveyDefinition::problems($changed, $stored), "A name with {$kind}, {$change}.");
            }
        }
    }

    /**
     * Schedule.extra_vars masks the values under every password field name,
     * also one that cannot carry an answer: SurveyDefinition::secretNames()
     * gives the keys that are sealed and shown as the placeholder.
     */
    public function testScheduleExtraVarsShowTheAnswersOfEveryPasswordFieldAsThePlaceholder(): void
    {
        $description = $this->textAt([...self::SCHEMAS, 'Schedule', 'properties', 'extra_vars', 'description']);
        $this->assertStringContainsString(
            'Values under the exact name of a password field whose name cannot carry an answer appear as `$encrypted$` too; launches of such a template are refused until the field is renamed.',
            $description
        );
        $this->assertStringNotContainsString('appear as stored', $description);
        $this->assertStringContainsString(
            'values stored under its exact name are password answers all the same, shown as `$encrypted$`',
            $this->textAt([...self::SCHEMAS, 'SurveyField', 'properties', 'name', 'description'])
        );

        foreach (['db[pw]', '0', "contrase\u{00F1}a_db", 'db_password '] as $unusable) {
            $survey = (string)json_encode([['name' => 'db_password', 'type' => 'password'], ['name' => $unusable, 'type' => 'password']]);
            $this->assertSame(['db_password'], SurveyDefinition::passwordFields($survey)['usable']);
            $this->assertSame(['db_password', $unusable], SurveyDefinition::secretNames($survey), 'Every password field name is a secret key.');
        }
    }

    public function testTheExecutionCommandPrefixIsDescribedForJobsWithPasswordAnswers(): void
    {
        $description = $this->textAt([...self::SCHEMAS, 'Job', 'properties', 'execution_command', 'description']);
        $prefixed = array_values(array_filter(
            preg_split('/(?<=\.)\s+/', $description) ?: [],
            static fn (string $sentence): bool => str_contains($sentence, 'ANSIBLE_VERBOSITY=0')
        ));
        $this->assertCount(1, $prefixed);
        $this->assertStringContainsString('a job with password answers', $prefixed[0]);
    }

    /**
     * Guard (PRE-1): other methods on the documented paths get the 404 of
     * every unknown API path, and the default route of the controller is no
     * part of the contract.
     */
    public function testTheTriggerTokenPathsTakeOnlyPostAndDelete(): void
    {
        foreach (['/api/v1/job-templates/{id}/trigger-token', '/api/v1/workflow-templates/{id}/trigger-token'] as $path) {
            $operations = array_diff(array_keys($this->arrayAt(['paths', $path])), ['parameters']);
            $this->assertEqualsCanonicalizing(['post', 'delete'], array_values($operations), $path);
            foreach ($operations as $operation) {
                $this->assertArrayNotHasKey('405', $this->arrayAt(['paths', $path, $operation, 'responses']), "{$operation} {$path}");
            }
        }
        foreach (array_keys($this->arrayAt(['paths'])) as $path) {
            $this->assertStringNotContainsString('trigger-tokens', (string)$path);
        }
    }

    /**
     * The message of SurveyDefinition for a name that is not a variable
     * name, with $name quoted as the code quotes it.
     */
    private static function invalidName(string $name): string
    {
        return 'Survey field name ' . SurveyDefinition::quote($name) . ' is not a valid variable name: use letters, digits and underscores, '
            . 'and start with a letter or an underscore.';
    }

    /** The spec file under test. */
    protected function specPath(): string
    {
        return dirname(__DIR__, 5) . '/web/openapi.yaml';
    }

    /**
     * The message of each example under $examples, at $keys of its value.
     *
     * @param array<mixed> $examples
     * @param list<string> $keys
     * @return list<mixed>
     */
    private function exampleMessages(array $examples, array $keys): array
    {
        $messages = [];
        foreach ($examples as $example) {
            $value = is_array($example) ? ($example['value'] ?? null) : null;
            foreach ($keys as $key) {
                $value = is_array($value) ? ($value[$key] ?? null) : null;
            }
            $messages[] = $value;
        }

        return $messages;
    }

    /**
     * The text at $keys, on one line.
     *
     * @param list<string> $keys
     */
    private function textAt(array $keys): string
    {
        $value = $this->at($keys);
        $this->assertIsString($value, 'web/openapi.yaml: ' . implode('.', $keys) . ' is no text.');

        return $this->flatten($value);
    }

    /**
     * @param list<string> $keys
     * @return array<mixed>
     */
    private function arrayAt(array $keys): array
    {
        $value = $this->at($keys);
        $this->assertIsArray($value, 'web/openapi.yaml: ' . implode('.', $keys) . ' is no map or list.');

        return $value;
    }

    /**
     * @param list<string> $keys
     */
    private function at(array $keys): mixed
    {
        $node = $this->spec();
        foreach ($keys as $key) {
            $this->assertIsArray($node, 'web/openapi.yaml has no ' . implode('.', $keys) . '.');
            $this->assertArrayHasKey($key, $node, 'web/openapi.yaml has no ' . implode('.', $keys) . '.');
            $node = $node[$key];
        }

        return $node;
    }

    private function flatten(string $text): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $text));
    }

    /**
     * @return array<mixed>
     */
    private function spec(): array
    {
        if ($this->spec === null) {
            $spec = Yaml::parseFile($this->specPath());
            $this->assertIsArray($spec);
            $this->spec = $spec;
        }

        return $this->spec;
    }
}
