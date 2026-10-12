<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eSurveyEditorSeeder;
use app\components\SurveySecrets;
use app\models\JobTemplate;
use app\tests\integration\DbTestCase;

/**
 * The fixtures of tests/e2e/tests/job-templates/survey-editor.spec.ts
 * (E2eSurveyEditorSeeder): stored survey data the editor and the template
 * page must handle. The rollback removes them.
 */
class E2eSurveyEditorSeederTest extends DbTestCase
{
    /** Every template the seeder restores, in the order it restores them. */
    private const TEMPLATES = [
        E2eSurveyEditorSeeder::XSS_TEMPLATE,
        E2eSurveyEditorSeeder::LEGACY_TEMPLATE,
        E2eSurveyEditorSeeder::LEGACY_EDIT_TEMPLATE,
        E2eSurveyEditorSeeder::EXTRA_VARS_TEMPLATE,
        E2eSurveyEditorSeeder::REWRITTEN_VARS_TEMPLATE,
    ];

    private int $adminId;

    /** @var array{0: int, 1: int, 2: int} */
    private array $parents;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int)$this->createUser('admin')->id;
        $this->parents = [
            (int)$this->createProject($this->adminId)->id,
            (int)$this->createInventory($this->adminId)->id,
            (int)$this->createRunnerGroup($this->adminId)->id,
        ];
        $this->log = [];
    }

    public function testTheXssTemplateStoresScriptTextAsSurveyText(): void
    {
        $this->seed();

        $fields = $this->template(E2eSurveyEditorSeeder::XSS_TEMPLATE)->getSurveyFields();
        $this->assertCount(1, $fields);
        $this->assertSame('deploy_target', $fields[0]->name);
        $this->assertSame(E2eSurveyEditorSeeder::XSS_LABEL, $fields[0]->label);
        $this->assertSame(E2eSurveyEditorSeeder::XSS_DEFAULT, $fields[0]->default);
        $this->assertSame(E2eSurveyEditorSeeder::XSS_HINT, $fields[0]->hint);
    }

    /**
     * Two templates with the same legacy survey: the spec that saves an
     * edit uses its own, so the other keeps its stored default all run long
     * (a test that checks the default never reaches the page then always
     * has one to check).
     */
    public function testTheLegacyTemplatesHaveAFieldNameTheRulesRefuseAndAPasswordDefault(): void
    {
        $this->seed();

        $legacy = $this->template(E2eSurveyEditorSeeder::LEGACY_TEMPLATE);
        $edited = $this->template(E2eSurveyEditorSeeder::LEGACY_EDIT_TEMPLATE);
        $this->assertNotSame((int)$legacy->id, (int)$edited->id);
        foreach ([$legacy, $edited] as $template) {
            $survey = json_decode((string)$template->survey_fields, true);
            $this->assertIsArray($survey);
            $this->assertSame(
                [[E2eSurveyEditorSeeder::LEGACY_NAME, 'text', 'db.example.test'], ['db_password', 'password', E2eSurveyEditorSeeder::LEGACY_DEFAULT]],
                array_map(static fn (array $field): array => [$field['name'], $field['type'], $field['default']], $survey),
                (string)$template->name
            );
            $this->assertSame(['db_password'], $template->passwordFieldNames());
        }
    }

    public function testTheExtraVarsTemplateHoldsAValueForItsPasswordFieldThatThePageMasks(): void
    {
        $this->seed();

        $template = $this->template(E2eSurveyEditorSeeder::EXTRA_VARS_TEMPLATE);
        $this->assertSame(
            ['region' => 'eu-central', 'db_password' => E2eSurveyEditorSeeder::LEGACY_EXTRA_VAR],
            json_decode((string)$template->extra_vars, true)
        );
        $this->assertSame(
            ['region' => 'eu-central', 'db_password' => SurveySecrets::PLACEHOLDER],
            json_decode((string)$template->getMaskedExtraVars(), true)
        );
    }

    /**
     * The seed stores the Extra Vars as text, not re-encoded, with a float
     * written 1.0 next to a value for the password field, which the page
     * masks. The form's Extra Vars editor posts that text unchanged
     * (survey-editor.spec.ts). A client that decodes and re-encodes it posts
     * 1.0 as 1, the same value: that edit saves too, as the rule against
     * password keys in Extra Vars runs only on a real change.
     */
    public function testTheRewrittenVarsTemplateStoresItsTextAsWrittenAndSavesItsValuesWrittenAnotherWay(): void
    {
        $this->seed();

        $template = $this->template(E2eSurveyEditorSeeder::REWRITTEN_VARS_TEMPLATE);
        $this->assertSame(E2eSurveyEditorSeeder::REWRITTEN_EXTRA_VARS, $template->extra_vars, 'stored as text, not re-encoded');
        $stored = json_decode((string)$template->extra_vars, true);
        $this->assertIsArray($stored);
        $this->assertIsFloat($stored['cpu_ratio'] ?? null);
        $this->assertSame(
            ['cpu_ratio' => 1, 'db_password' => SurveySecrets::PLACEHOLDER],
            json_decode((string)$template->getMaskedExtraVars(), true)
        );

        $template->description = 'edited';
        $template->extra_vars = '{"cpu_ratio":1,"db_password":"' . E2eSurveyEditorSeeder::REWRITTEN_EXTRA_VAR . '"}';
        $this->assertTrue($template->validate(['survey_fields', 'extra_vars']), (string)json_encode($template->getErrors()));
    }

    public function testEveryTemplateUsesTheGivenParents(): void
    {
        $this->seed();

        $restored = [];
        foreach (self::TEMPLATES as $name) {
            $template = $this->template($name);
            $this->assertSame($this->parents, [(int)$template->project_id, (int)$template->inventory_id, (int)$template->runner_group_id], $name);
            $restored[] = "'{$name}' (ID {$template->id})";
        }
        $last = array_pop($restored);
        $this->assertSame(['  Restored survey editor fixtures ' . implode(', ', $restored) . " and {$last}.\n"], $this->log);
    }

    public function testSeedingAgainRestoresWhatASpecChanged(): void
    {
        $this->seed();
        $legacy = $this->template(E2eSurveyEditorSeeder::LEGACY_TEMPLATE);
        $legacy->generateTriggerToken($this->adminId);
        // survey-editor.spec.ts saves the legacy edit template, which drops the password default.
        $edited = $this->template(E2eSurveyEditorSeeder::LEGACY_EDIT_TEMPLATE);
        $edited->updateAttributes(['survey_fields' => '[{"name":"db-host","type":"text"}]', 'description' => 'edited']);
        $xss = $this->template(E2eSurveyEditorSeeder::XSS_TEMPLATE);
        $xss->softDelete();
        $this->template(E2eSurveyEditorSeeder::EXTRA_VARS_TEMPLATE)->updateAttributes(['extra_vars' => null]);
        // ...and a save of the rewritten vars template that changed its description and the way its Extra Vars are written.
        $this->template(E2eSurveyEditorSeeder::REWRITTEN_VARS_TEMPLATE)->updateAttributes([
            'extra_vars' => '{"cpu_ratio":1,"db_password":"' . E2eSurveyEditorSeeder::REWRITTEN_EXTRA_VAR . '"}',
            'description' => 'edited',
        ]);

        $this->seed();

        $restored = $this->template(E2eSurveyEditorSeeder::LEGACY_TEMPLATE);
        $this->assertSame((int)$legacy->id, (int)$restored->id);
        $this->assertFalse($restored->hasTriggerToken());
        $restoredEdit = $this->template(E2eSurveyEditorSeeder::LEGACY_EDIT_TEMPLATE);
        $this->assertSame((int)$edited->id, (int)$restoredEdit->id);
        $this->assertStringContainsString(E2eSurveyEditorSeeder::LEGACY_DEFAULT, (string)$restoredEdit->survey_fields);
        $this->assertSame((int)$xss->id, (int)$this->template(E2eSurveyEditorSeeder::XSS_TEMPLATE)->id);
        $this->assertStringContainsString(
            E2eSurveyEditorSeeder::LEGACY_EXTRA_VAR,
            (string)$this->template(E2eSurveyEditorSeeder::EXTRA_VARS_TEMPLATE)->extra_vars
        );
        $this->assertSame(
            E2eSurveyEditorSeeder::REWRITTEN_EXTRA_VARS,
            $this->template(E2eSurveyEditorSeeder::REWRITTEN_VARS_TEMPLATE)->extra_vars
        );
        foreach (self::TEMPLATES as $name) {
            $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => $name])->count(), $name);
            $this->assertSame('E2E fixture: a survey with a password field', $this->template($name)->description, $name);
        }
    }

    private function seed(): void
    {
        (new E2eSurveyEditorSeeder(function (string $msg): void {
            $this->log[] = $msg;
        }))->seed($this->adminId, $this->parents);
    }

    private function template(string $name): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(JobTemplate::class, $template, $name);

        return $template;
    }
}
