<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\SurveySecrets;
use app\services\JobLaunchSecrets;
use PHPUnit\Framework\TestCase;

/**
 * What docs/updating.md and docs/troubleshooting.md say about Extra Vars is
 * what the code does: the messages of the Extra Vars editor as its script
 * words them, the refusal of numbers too large for JSON when a job template,
 * a schedule or a workflow step is saved, and how a save tells changed Extra
 * Vars from the same ones written another way.
 * ExtraVarsBehaviourDocsContractTest runs the behaviour the docs describe.
 *
 * Regressions from fix round 2 of the survey password release (findings in
 * brackets), each test fails on the docs as they were before the fix:
 * - [V1-NI-2] the editor no longer rewrites the Extra Vars when a form
 *   opens, refuses numbers it cannot convert exactly, and blocks a submit
 *   of text that does not parse; the docs named none of it and still said
 *   the editor rewrites the Extra Vars.
 * - [G open issue 1] such numbers are refused when a template, a schedule
 *   or a step is saved, not only at launch.
 * - [V2-C2] the comparison of the Extra Vars tells a list from an object
 *   and a rounded whole number from the original.
 *
 * From fix round 3:
 * - [R2V1-DOC-CMP, R2V2-2] the docs said a number written another way
 *   "with exactly the same value" is no change, which the code did not do
 *   beyond 2^53 and does not do beyond the 64-bit range; they now give the
 *   exact rule (ExtraVarsBehaviourDocsContractTest runs its examples).
 * - [R2V2-4] the editor's YAML mode reads YAML 1.2, and Ansible YAML 1.1:
 *   the docs did not say that 0644, yes/no/on/off and 1:30 are sent with
 *   another meaning than Ansible gives them.
 *
 * From fix round 4:
 * - [R3V2-2] updating.md said the Extra Vars change when their JSON value
 *   does "as a launch reads it"; the rule compares JSON values, and a
 *   number it counts as unchanged can reach the playbook otherwise
 *   (ExtraVarsBehaviourDocsContractTest runs the launch).
 */
class ExtraVarsDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATE_SECTION = '## After updating from 2.8.1 or older';
    private const EDITOR_SCRIPT = 'web/js/extra-vars-editor.js';
    private const YAML_LIBRARY = 'web/js/vendor/js-yaml/js-yaml.min.js';
    private const EDITOR_SECTION = '## Extra Vars editor: the form is not submitted, or YAML is not converted';
    private const NUMBER_SECTION = '## Launch or save refused: a number too large for JSON';

    public function testTheEditorsMessagesAreQuotedAsTheEditorShowsThem(): void
    {
        $script = $this->editorScript();
        $constants = self::stringConstants($script);
        // The line under the editor shows each problem after this mark.
        $this->assertStringContainsString("setStatus('\u{2717} ' + problem", $script);
        $this->assertStringContainsString("label(fmt) + ': '", $script, 'A parse error is "JSON: <message>" or "YAML: <message>".');

        $section = $this->flat('docs/troubleshooting.md', self::EDITOR_SECTION);
        foreach (['NOT_CONVERTED_TO_YAML', 'INEXACT_YAML'] as $name) {
            $this->assertArrayHasKey($name, $constants, self::EDITOR_SCRIPT . " defines {$name}.");
            $this->assertMentions("`\u{2717} {$constants[$name]}`", $section, 'troubleshooting.md, on the Extra Vars editor,');
        }
        $this->assertMentions("`\u{2717} JSON: ", $section, 'troubleshooting.md, on the Extra Vars editor,');
        $this->assertMentions("`\u{2717} YAML: ", $section, 'troubleshooting.md, on the Extra Vars editor,');

        $editor = array_values(array_filter(
            $this->units('docs/updating.md', self::UPDATE_SECTION),
            static fn (string $unit): bool => str_starts_with($unit, '**The Extra Vars editor keeps the text as typed.**')
        ));
        $this->assertCount(1, $editor, 'updating.md says what changed in the Extra Vars editor.');
        foreach (['`9007199254740993` became `9007199254740992`', 'as stored until they are edited', 'is not submitted'] as $statement) {
            $this->assertMentions($statement, $editor[0], 'updating.md, on the Extra Vars editor,');
        }
        // The editor no longer rewrites what it loads.
        $this->assertStringNotContainsString('how the template form\'s editor rewrites', $this->flat('docs/updating.md'));
    }

    public function testTheSaveTimeRefusalOfNumbersTooLargeForJsonIsDocumented(): void
    {
        $message = SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN);

        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $fixes = $this->between($updating, '**Fixes that change behaviour:**', '**API contract change:**');
        $saving = array_values(array_filter(
            $this->units('docs/updating.md', self::UPDATE_SECTION),
            static fn (string $unit): bool => str_starts_with($unit, '- Saving a job template or a schedule') && str_contains($fixes, $unit)
        ));
        $this->assertCount(1, $saving, 'updating.md lists the save-time refusal among the fixes.');
        foreach ([$message, '/api/v1/workflow-templates', 'cloning such a template fails'] as $statement) {
            $this->assertMentions($statement, $saving[0], 'updating.md, on saving such numbers,');
        }
        $api = $this->between($updating, '**API contract change:**', '**Database and deployment.**');
        $this->assertMentions('`/api/v1/schedules` answer `422` `' . $message . '`', $api, 'The API contract changes of updating.md');

        $troubleshooting = $this->flat('docs/troubleshooting.md', self::NUMBER_SECTION);
        foreach (['saving a job template or a schedule', '`steps[<n>]: ...`', 'cloning such a template fails'] as $statement) {
            $this->assertMentions($statement, $troubleshooting, 'troubleshooting.md, on numbers too large for JSON,');
        }
    }

    /**
     * Text that is not UTF-8 is refused only where a form field carries it
     * (POST /api/v1/jobs: extra_vars[<key>]); extra vars sent as JSON text
     * that is not valid JSON, such text included, are ignored
     * (ExtraVarsBehaviourDocsContractTest).
     *
     * Regression (V1-NI-1): updating.md said such text is refused with
     * extra_vars as an object or as JSON text.
     */
    public function testTheDocsSayWhereTextThatIsNotUtf8IsRefused(): void
    {
        $units = $this->units('docs/updating.md', self::UPDATE_SECTION);
        $unit = static fn (string $start): array => array_values(array_filter($units, static fn (string $text): bool => str_starts_with($text, $start)));

        $api = $unit('- `POST /api/v1/jobs` answers `422`');
        $this->assertCount(1, $api, 'updating.md has one API note on POST /api/v1/jobs.');
        $this->assertMentions('is refused in `extra_vars[<key>]` fields', $api[0], 'updating.md, on POST /api/v1/jobs,');
        $this->assertMentions('`extra_vars` as JSON text that is not valid JSON, such text included, is ignored', $api[0], 'updating.md, on POST /api/v1/jobs,');
        $refusals = $unit('- extra vars or answers that JSON cannot hold');
        $this->assertCount(1, $refusals, 'updating.md lists the values JSON cannot hold once.');
        $this->assertMentions('text that is not UTF-8 in a form field', $refusals[0], 'updating.md, on refused launches,');
        $this->assertMentions('JSON text that is not valid JSON, text that is not UTF-8 included, are still ignored', $refusals[0], 'updating.md, on refused launches,');

        $troubleshooting = $this->flat('docs/troubleshooting.md', self::NUMBER_SECTION);
        $this->assertMentions('Text that is not valid UTF-8 in a form field', $troubleshooting, 'troubleshooting.md');
        $this->assertMentions('vars sent as JSON text that is not valid JSON are ignored', $troubleshooting, 'troubleshooting.md');
    }

    /**
     * The examples the docs give are compared as ExtraVarsBehaviourDocsContractTest
     * shows the code does: by JSON value, a list never equal to an object,
     * a rounded whole number a change.
     */
    public function testTheExtraVarsComparisonIsDescribedByValue(): void
    {
        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $troubleshooting = $this->flat('docs/troubleshooting.md', '## Saving a job template fails: survey rules');
        $statements = [
            '`"1"` and `1` differ, and so do a list and an object',
            'and a whole number beyond the 64-bit range and the same digits as text',
            'number written another way that PHP reads as the same number within the 64-bit range',
            '`1.0` for `1`',
            '`9007199254740994.0` for `9007199254740994`',
            'a whole number written without a fraction or an exponent is the same only as the same digits',
            '`1e20` for `100000000000000000000` is a change',
            'A key written twice counts with its last value',
        ];
        foreach ($statements as $statement) {
            $this->assertMentions($statement, $updating, 'updating.md, on the Extra Vars rule,');
            $this->assertMentions($statement, $troubleshooting, 'troubleshooting.md, on the Extra Vars rule,');
        }
        $this->assertMentions('`9007199254740993` sent back as `9007199254740992`', $updating, 'updating.md, on the Extra Vars rule,');
        // The 64-bit range is the one of PHP's int, which decides the comparison.
        $this->assertMentions('(`' . PHP_INT_MIN . '` to `' . PHP_INT_MAX . '`)', $updating, 'updating.md, on the Extra Vars rule,');
        foreach ([$updating, $troubleshooting] as $text) {
            $this->assertStringNotContainsString('with exactly the same value', $text, 'Beyond the 64-bit range, the same value written another way is a change.');
            // R3V2-2: the rule compares JSON values, not what a launch passes on.
            $this->assertMentions('The Extra Vars change when their JSON value does:', $text, 'The Extra Vars rule');
            $this->assertStringNotContainsString('as a launch reads it', $text, 'A number that counts as unchanged can reach the playbook otherwise.');
        }
    }

    /**
     * The YAML mode of the editor parses with js-yaml 4 and its default
     * schema, which reads YAML 1.2: an unquoted 0644 is the number 644,
     * yes/no/on/off are text, 1:30 is text, and a date becomes a timestamp.
     * Ansible reads its own files as YAML 1.1, so the docs warn about these
     * values. A YAML 1.1 schema or another library changes what they must
     * say.
     */
    public function testTheEditorsYamlModeIsDescribedAsYaml12(): void
    {
        $script = $this->editorScript();
        $this->assertStringContainsString('jsyaml.load(text)', $script, 'The YAML mode parses with js-yaml\'s default schema.');
        $library = file_get_contents(dirname(__DIR__, 3) . '/' . self::YAML_LIBRARY);
        $this->assertIsString($library);
        $this->assertMatchesRegularExpression('~^/\*! js-yaml 4\.~', $library, 'js-yaml 4 reads YAML 1.2.');

        $editor = array_values(array_filter(
            $this->units('docs/updating.md', self::UPDATE_SECTION),
            static fn (string $unit): bool => str_starts_with($unit, '**The Extra Vars editor keeps the text as typed.**')
        ));
        $this->assertCount(1, $editor);
        $docs = [
            'updating.md, on the Extra Vars editor,' => $editor[0],
            'troubleshooting.md, on the Extra Vars editor,' => $this->flat('docs/troubleshooting.md', self::EDITOR_SECTION),
        ];
        $statements = [
            'YAML mode reads YAML 1.2, while Ansible reads its own YAML files as YAML 1.1',
            '`0644` as the number `644`',
            '`yes`, `no`, `on` and `off` as text',
            '`1:30` as text',
            'A date such as `2026-10-11` is sent as the text `2026-10-11T00:00:00.000Z`',
            'Quote such values',
            'or use JSON mode',
        ];
        foreach ($docs as $where => $text) {
            foreach ($statements as $statement) {
                $this->assertMentions($statement, $text, $where);
            }
        }
    }

    /**
     * The editor's script, from the repository: the code the docs describe,
     * also when root() points at another copy of the docs.
     */
    private function editorScript(): string
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/' . self::EDITOR_SCRIPT);
        $this->assertIsString($script);

        return $script;
    }

    /**
     * The text constants of the script, "var NAME = 'text' + OTHER + 'text';",
     * with the constants they use filled in.
     *
     * @return array<string, string>
     */
    private static function stringConstants(string $script): array
    {
        $constants = [];
        preg_match_all("/\\bvar ([A-Z][A-Z_]*) = ((?:'[^'\\\\]*'|[A-Z][A-Z_]*|[\\s+])+);/", $script, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            preg_match_all("/'([^'\\\\]*)'|([A-Z][A-Z_]*)/", $match[2], $parts, PREG_SET_ORDER);
            $value = '';
            foreach ($parts as $part) {
                $name = $part[2] ?? '';
                $value .= $name !== '' ? ($constants[$name] ?? '') : ($part[1] ?? '');
            }
            $constants[$match[1]] = $value;
        }

        return $constants;
    }
}
