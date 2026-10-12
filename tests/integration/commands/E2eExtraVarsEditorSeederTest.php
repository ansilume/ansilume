<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eExtraVarsEditorSeeder;
use app\components\SurveySecrets;
use app\components\TemplateSurveyValidator;
use app\models\JobTemplate;
use app\tests\integration\DbTestCase;

/**
 * The fixtures of tests/e2e/tests/job-templates/extra-vars-editor.spec.ts
 * (E2eExtraVarsEditorSeeder): Extra Vars stored as text that a rewrite in
 * the browser would change. The rollback removes them.
 */
class E2eExtraVarsEditorSeederTest extends DbTestCase
{
    /** Every template the seeder restores, in the order it restores them. */
    private const TEMPLATES = [
        E2eExtraVarsEditorSeeder::BIGINT_TEMPLATE,
        E2eExtraVarsEditorSeeder::BIGINT_SECRET_TEMPLATE,
        E2eExtraVarsEditorSeeder::REFUSAL_TEMPLATE,
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

    /**
     * The integer is stored as written: 9007199254740993, which PHP keeps
     * as an int and JavaScript reads as 9007199254740992.
     */
    public function testTheBigIntegerTemplateStoresItsExtraVarsAsText(): void
    {
        $this->seed();

        $template = $this->template(E2eExtraVarsEditorSeeder::BIGINT_TEMPLATE);
        $this->assertSame('{"build_id": 9007199254740993}', $template->extra_vars);
        $this->assertSame(['build_id' => 9007199254740993], json_decode((string)$template->extra_vars, true));
        $this->assertNull($template->survey_fields);
        $this->assertFalse($template->hasSurvey(), 'its launch page fills Advanced Overrides with the Extra Vars');
        $this->assertTrue($template->validate(), (string)json_encode($template->getErrors()));
    }

    /**
     * A legacy template: its Extra Vars hold the password field's key, and
     * an edit that posts them unchanged saves.
     */
    public function testTheBigIntegerSecretTemplateHoldsAPasswordKeyThatOnlyAnUnchangedEditKeeps(): void
    {
        $this->seed();

        $template = $this->template(E2eExtraVarsEditorSeeder::BIGINT_SECRET_TEMPLATE);
        $this->assertSame(E2eExtraVarsEditorSeeder::BIGINT_SECRET_EXTRA_VARS, $template->extra_vars);
        $this->assertSame(['db_password'], $template->passwordFieldNames());
        $this->assertSame(
            ['build_id' => 9007199254740993, 'db_password' => SurveySecrets::PLACEHOLDER],
            json_decode((string)$template->getMaskedExtraVars(), true)
        );

        $template->description = 'edited';
        $this->assertTrue($template->validate(), 'the Extra Vars as stored');
        $value = E2eExtraVarsEditorSeeder::BIGINT_SECRET_VALUE;
        $template->extra_vars = '{"build_id":9007199254740992,"db_password":"' . $value . '"}';
        $this->assertFalse($template->validate(), 'the rounded integer is a change');
        $this->assertSame(
            [sprintf(TemplateSurveyValidator::PASSWORD_KEY_IN_EXTRA_VARS, '"db_password"')],
            $template->getErrors('extra_vars')
        );
    }

    public function testTheRefusalTemplateHasPlainExtraVars(): void
    {
        $this->seed();

        $template = $this->template(E2eExtraVarsEditorSeeder::REFUSAL_TEMPLATE);
        $this->assertSame(E2eExtraVarsEditorSeeder::REFUSAL_EXTRA_VARS, $template->extra_vars);
        $this->assertTrue($template->validate(), (string)json_encode($template->getErrors()));
    }

    public function testEveryTemplateUsesTheGivenParentsAndIsLogged(): void
    {
        $this->seed();

        $restored = [];
        foreach (self::TEMPLATES as $name) {
            $template = $this->template($name);
            $parents = [(int)$template->project_id, (int)$template->inventory_id, (int)$template->runner_group_id];
            $this->assertSame($this->parents, $parents, $name);
            $this->assertSame(E2eExtraVarsEditorSeeder::DESCRIPTION, $template->description, $name);
            $restored[] = "'{$name}' (ID {$template->id})";
        }
        $last = array_pop($restored);
        $line = '  Restored Extra Vars editor fixtures ' . implode(', ', $restored) . " and {$last}.\n";
        $this->assertSame([$line], $this->log);
    }

    /**
     * A spec saves descriptions; a run against an older editor saves rewritten
     * Extra Vars; another test may soft-delete a template. A seed restores
     * each one in place.
     */
    public function testSeedingAgainRestoresWhatASpecChanged(): void
    {
        $this->seed();
        $bigint = $this->template(E2eExtraVarsEditorSeeder::BIGINT_TEMPLATE);
        $bigint->updateAttributes(['extra_vars' => '{"build_id":9007199254740992}', 'description' => 'edited']);
        $secret = $this->template(E2eExtraVarsEditorSeeder::BIGINT_SECRET_TEMPLATE);
        $secret->updateAttributes(['survey_fields' => null, 'extra_vars' => null]);
        $refusal = $this->template(E2eExtraVarsEditorSeeder::REFUSAL_TEMPLATE);
        $refusal->softDelete();

        $this->seed();

        $this->assertSame((int)$bigint->id, (int)$this->template(E2eExtraVarsEditorSeeder::BIGINT_TEMPLATE)->id);
        $restoredBigint = $this->template(E2eExtraVarsEditorSeeder::BIGINT_TEMPLATE);
        $this->assertSame(E2eExtraVarsEditorSeeder::BIGINT_EXTRA_VARS, $restoredBigint->extra_vars);
        $restoredSecret = $this->template(E2eExtraVarsEditorSeeder::BIGINT_SECRET_TEMPLATE);
        $this->assertSame(['db_password'], $restoredSecret->passwordFieldNames());
        $this->assertSame(E2eExtraVarsEditorSeeder::BIGINT_SECRET_EXTRA_VARS, $restoredSecret->extra_vars);
        $this->assertSame((int)$refusal->id, (int)$this->template(E2eExtraVarsEditorSeeder::REFUSAL_TEMPLATE)->id);
        foreach (self::TEMPLATES as $name) {
            $this->assertSame(1, (int)JobTemplate::findWithDeleted()->where(['name' => $name])->count(), $name);
            $this->assertSame(E2eExtraVarsEditorSeeder::DESCRIPTION, $this->template($name)->description, $name);
        }
    }

    private function seed(): void
    {
        (new E2eExtraVarsEditorSeeder(function (string $msg): void {
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
