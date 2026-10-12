<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\SurveyDefinition;
use app\components\SurveyField;
use app\components\SurveySecrets;
use app\components\TriggerTokenResolver;
use app\models\Runner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The names and values web/openapi.yaml promises are the ones the code uses:
 * runner capabilities, survey field types and names, the trigger token
 * header and its deprecated URL forms, and the placeholder of password
 * survey answers. A change on one side without the other fails here.
 *
 * Regression: the spec named the trigger token only as a required query
 * parameter, after triggers had learned to read it from a header.
 */
class OpenApiCodeConstantsContractTest extends TestCase
{
    /** The operations that take a token in a header, Bearer or the deprecated query parameter. */
    private const TRIGGER_OPERATIONS = ['/trigger/fire', '/trigger/fire-workflow'];

    private const RUNNER_CAPABILITIES = ['components', 'schemas', 'Runner', 'properties', 'capabilities'];

    private const SURVEY_FIELD = ['components', 'schemas', 'SurveyField', 'properties'];

    /** @var array<mixed>|null */
    private ?array $spec = null;

    public function testTheRunnerCapabilitiesAreTheOnesTheServerRecords(): void
    {
        $this->assertSame(
            Runner::CAPABILITIES,
            $this->at([...self::RUNNER_CAPABILITIES, 'items', 'enum']),
            'Runner.capabilities must list Runner::CAPABILITIES.'
        );
        $this->assertSame(Runner::CAPABILITIES, $this->at([...self::RUNNER_CAPABILITIES, 'example']));
        $description = $this->stringAt([...self::RUNNER_CAPABILITIES, 'description']);
        foreach (Runner::CAPABILITIES as $capability) {
            $this->assertStringContainsString("`{$capability}`", $description, "Runner.capabilities must explain {$capability}.");
        }
    }

    public function testTheSurveyFieldSchemaIsWhatTheSurveyRulesAccept(): void
    {
        $this->assertSame(array_keys(SurveyField::types()), $this->at([...self::SURVEY_FIELD, 'type', 'enum']));
        $this->assertSame(SurveyField::TYPE_TEXT, $this->at([...self::SURVEY_FIELD, 'type', 'default']), 'A field without a type is a text field.');
        [$body, $modifiers] = self::pcreParts(SurveyDefinition::NAME_PATTERN);
        $this->assertSame($body, $this->at([...self::SURVEY_FIELD, 'name', 'pattern']));
        // ECMA-262's $ matches only at the end; PCRE's does so only with D,
        // otherwise also before a final line break ("db_password\n").
        $this->assertSame('D', $modifiers, 'NAME_PATTERN must mean what the spec pattern means.');

        $survey = ['components', 'schemas', 'JobTemplateInput', 'properties', 'survey_fields'];
        $this->assertSame('#/components/schemas/SurveyField', $this->at([...$survey, 'oneOf', '0', 'items', '$ref']));
        $description = (string)preg_replace('/\s+/', ' ', $this->stringAt([...$survey, 'description']));
        $this->assertStringContainsString(SurveyDefinition::PASSWORD_DEFAULT, $description);
    }

    public function testTheTriggerTokenTravelsInTheHeaderTheResolverReads(): void
    {
        $schemes = ['components', 'securitySchemes'];
        $this->assertSame('apiKey', $this->at([...$schemes, 'triggerTokenHeader', 'type']));
        $this->assertSame('header', $this->at([...$schemes, 'triggerTokenHeader', 'in']));
        $this->assertSame(TriggerTokenResolver::HEADER, $this->at([...$schemes, 'triggerTokenHeader', 'name']));
        $this->assertSame('http', $this->at([...$schemes, 'triggerTokenBearer', 'type']));
        $this->assertSame('bearer', $this->at([...$schemes, 'triggerTokenBearer', 'scheme']));

        foreach (self::TRIGGER_OPERATIONS as $path) {
            $this->assertSame(
                [['triggerTokenHeader' => []], ['triggerTokenBearer' => []], []],
                $this->at(['paths', $path, 'post', 'security']),
                "{$path}: the header, Bearer, or nothing but the deprecated query parameter."
            );
            $query = $this->parameter($path, 'query', 'token');
            $this->assertFalse($query['required'] ?? false, "{$path}: the query token is optional.");
            $this->assertTrue($query['deprecated'] ?? false, "{$path}: the query token is deprecated.");
            $this->assertArrayHasKey('400', $this->arrayAt(['paths', $path, 'post', 'responses']), "{$path}: a call without a token answers 400.");
        }
    }

    public function testThePathFormIsDeprecatedAndTakesTheTokensTheRouteAccepts(): void
    {
        $this->assertTrue($this->at(['paths', '/trigger/{token}', 'post', 'deprecated']));
        $pattern = $this->dig($this->parameter('/trigger/{token}', 'path', 'token'), ['schema', 'pattern']);
        $this->assertIsString($pattern);

        $this->assertMatchesRegularExpression('~' . $pattern . '~', str_repeat('a1', 32), 'lowercase hex');
        $this->assertMatchesRegularExpression('~' . $pattern . '~', str_repeat('F0', 32), 'uppercase hex: the route accepts it');
        $this->assertDoesNotMatchRegularExpression('~' . $pattern . '~', str_repeat('a', 63), '63 characters');
        $this->assertDoesNotMatchRegularExpression('~' . $pattern . '~', 'fire', 'the endpoints are no tokens');
    }

    public function testTheRefusedLaunchesAnswer422WhereTheSpecSays(): void
    {
        $expected = ['/trigger/fire' => 'TriggerLaunchRejected', '/trigger/{token}' => 'TriggerLaunchRejected', '/api/v1/jobs' => 'JobLaunchRejected'];
        foreach ($expected as $path => $response) {
            $this->assertSame(
                '#/components/responses/' . $response,
                $this->at(['paths', $path, 'post', 'responses', '422', '$ref']),
                "{$path} answers a refused launch with 422 and the reason."
            );
        }
    }

    /**
     * Wherever the API shows or takes password answers, the spec names the
     * placeholder that stands for them.
     *
     * @return array<string, array{0: list<string>}>
     */
    public static function placeholderProvider(): array
    {
        return [
            'Job.extra_vars' => [['components', 'schemas', 'Job', 'properties', 'extra_vars', 'description']],
            'Job.execution_command' => [['components', 'schemas', 'Job', 'properties', 'execution_command', 'description']],
            'JobLaunchInput.extra_vars' => [['components', 'schemas', 'JobLaunchInput', 'properties', 'extra_vars', 'description']],
            'ScheduleInput.extra_vars' => [['components', 'schemas', 'ScheduleInput', 'properties', 'extra_vars', 'description']],
            'ScheduleInput.extra_vars example' => [['components', 'schemas', 'ScheduleInput', 'properties', 'extra_vars', 'example']],
            'Job.extra_vars example' => [['components', 'schemas', 'Job', 'properties', 'extra_vars', 'example', 'db_password']],
        ];
    }

    /**
     * @dataProvider placeholderProvider
     * @param list<string> $keys
     */
    public function testThePlaceholderIsDocumented(array $keys): void
    {
        $this->assertStringContainsString(SurveySecrets::PLACEHOLDER, $this->stringAt($keys));
    }

    /**
     * CLAUDE.md: examples hold placeholders, never secrets. Trigger, API and
     * runner tokens are long hexadecimal strings; none may be in the spec,
     * not even a made-up one that looks real.
     */
    public function testTheSpecHoldsNoTokenShapedValue(): void
    {
        $text = (string)file_get_contents($this->specPath());

        $this->assertSame(0, preg_match('/[0-9a-fA-F]{32,}/', $text, $match), 'Token-shaped value in web/openapi.yaml: ' . ($match[0] ?? ''));
        $this->assertSame('<trigger-token>', $this->at(['components', 'schemas', 'TriggerTokenCreated', 'properties', 'token', 'example']));
    }

    /**
     * The body of a PCRE pattern, without its delimiters, and its modifiers.
     *
     * @return array{0: string, 1: string}
     */
    private static function pcreParts(string $pattern): array
    {
        $end = (int)strrpos($pattern, $pattern[0]);

        return [substr($pattern, 1, $end - 1), substr($pattern, $end + 1)];
    }

    /**
     * The spec file under test.
     */
    protected function specPath(): string
    {
        return dirname(__DIR__, 5) . '/web/openapi.yaml';
    }

    /**
     * The value at $keys in the spec.
     *
     * @param list<string> $keys
     */
    private function at(array $keys): mixed
    {
        return $this->dig($this->spec(), $keys);
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
    private function stringAt(array $keys): string
    {
        $value = $this->at($keys);
        $this->assertIsString($value, 'web/openapi.yaml: ' . implode('.', $keys) . ' is no text.');

        return $value;
    }

    /**
     * The value at $keys under $node.
     *
     * @param list<string> $keys
     */
    private function dig(mixed $node, array $keys): mixed
    {
        foreach ($keys as $key) {
            $this->assertIsArray($node, 'web/openapi.yaml has no ' . implode('.', $keys) . '.');
            $this->assertArrayHasKey($key, $node, 'web/openapi.yaml has no ' . implode('.', $keys) . '.');
            $node = $node[$key];
        }

        return $node;
    }

    /**
     * The parameter $name in $in of the POST operation of $path.
     *
     * @return array<mixed>
     */
    private function parameter(string $path, string $in, string $name): array
    {
        foreach ($this->arrayAt(['paths', $path, 'post', 'parameters']) as $parameter) {
            if (is_array($parameter) && ($parameter['in'] ?? null) === $in && ($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }
        $this->fail("POST {$path} has no {$in} parameter {$name}.");
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
