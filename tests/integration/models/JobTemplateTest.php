<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\JobTemplate;
use app\tests\integration\DbTestCase;

/**
 * Additional JobTemplate coverage beyond trigger-token and soft-delete tests.
 * Covers: validation, validateJson, relations, survey fields, hasSurvey,
 * attributeLabels, find scopes.
 */
class JobTemplateTest extends DbTestCase
{
    // -- tableName / behaviors ---------------------------------------------------

    public function testTableName(): void
    {
        $this->assertSame('{{%job_template}}', JobTemplate::tableName());
    }

    public function testTimestampBehaviorIsRegistered(): void
    {
        $tpl = new JobTemplate();
        $behaviors = $tpl->behaviors();
        $this->assertContains(\yii\behaviors\TimestampBehavior::class, $behaviors);
    }

    // -- validation: required fields --------------------------------------------

    public function testValidationRequiresNameProjectInventoryPlaybookRunnerGroup(): void
    {
        $tpl = new JobTemplate();
        $this->assertFalse($tpl->validate());
        $this->assertArrayHasKey('name', $tpl->getErrors());
        $this->assertArrayHasKey('project_id', $tpl->getErrors());
        $this->assertArrayHasKey('inventory_id', $tpl->getErrors());
        $this->assertArrayHasKey('playbook', $tpl->getErrors());
        $this->assertArrayHasKey('runner_group_id', $tpl->getErrors());
    }

    public function testValidationPassesWithAllRequiredFields(): void
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);

        $tpl = new JobTemplate();
        $tpl->name = 'Deploy';
        $tpl->project_id = $project->id;
        $tpl->inventory_id = $inv->id;
        $tpl->runner_group_id = $group->id;
        $tpl->playbook = 'site.yml';
        $tpl->verbosity = 0;
        $tpl->forks = 5;
        $tpl->become = false;
        $tpl->become_method = 'sudo';
        $tpl->become_user = 'root';
        $tpl->timeout_minutes = 120;
        $tpl->created_by = $user->id;
        $this->assertTrue($tpl->validate());
    }

    // -- validation: verbosity --------------------------------------------------

    public function testVerbosityRejectsNegative(): void
    {
        $tpl = new JobTemplate();
        $tpl->verbosity = -1;
        $this->assertFalse($tpl->validate(['verbosity']));
    }

    public function testVerbosityRejectsAbove5(): void
    {
        $tpl = new JobTemplate();
        $tpl->verbosity = 6;
        $this->assertFalse($tpl->validate(['verbosity']));
    }

    public function testVerbosityAccepts0Through5(): void
    {
        for ($v = 0; $v <= 5; $v++) {
            $tpl = new JobTemplate();
            $tpl->verbosity = $v;
            $this->assertTrue($tpl->validate(['verbosity']), "Verbosity {$v} should be valid");
        }
    }

    // -- validation: forks ------------------------------------------------------

    public function testForksRejectsZero(): void
    {
        $tpl = new JobTemplate();
        $tpl->forks = 0;
        $this->assertFalse($tpl->validate(['forks']));
    }

    public function testForksRejectsAbove200(): void
    {
        $tpl = new JobTemplate();
        $tpl->forks = 201;
        $this->assertFalse($tpl->validate(['forks']));
    }

    public function testForksAccepts1And200(): void
    {
        $tpl = new JobTemplate();
        $tpl->forks = 1;
        $this->assertTrue($tpl->validate(['forks']));
        $tpl->forks = 200;
        $this->assertTrue($tpl->validate(['forks']));
    }

    // -- validation: timeout_minutes --------------------------------------------

    public function testTimeoutRejectsZero(): void
    {
        $tpl = new JobTemplate();
        $tpl->timeout_minutes = 0;
        $this->assertFalse($tpl->validate(['timeout_minutes']));
    }

    public function testTimeoutRejectsAbove1440(): void
    {
        $tpl = new JobTemplate();
        $tpl->timeout_minutes = 1441;
        $this->assertFalse($tpl->validate(['timeout_minutes']));
    }

    public function testTimeoutAcceptsBounds(): void
    {
        $tpl = new JobTemplate();
        $tpl->timeout_minutes = 1;
        $this->assertTrue($tpl->validate(['timeout_minutes']));
        $tpl->timeout_minutes = 1440;
        $this->assertTrue($tpl->validate(['timeout_minutes']));
    }

    // -- validation: become_method ----------------------------------------------

    public function testBecomeMethodAcceptsValidValues(): void
    {
        foreach (['sudo', 'su', 'pbrun', 'pfexec', 'doas'] as $method) {
            $tpl = new JobTemplate();
            $tpl->become_method = $method;
            $this->assertTrue($tpl->validate(['become_method']), "become_method '{$method}' should be valid");
        }
    }

    public function testBecomeMethodRejectsInvalidValue(): void
    {
        $tpl = new JobTemplate();
        $tpl->become_method = 'runas';
        $this->assertFalse($tpl->validate(['become_method']));
    }

    // -- validateJson -----------------------------------------------------------

    public function testValidateJsonPassesForValidExtraVars(): void
    {
        $tpl = new JobTemplate();
        $tpl->extra_vars = '{"key": "value"}';
        $this->assertTrue($tpl->validate(['extra_vars']));
    }

    public function testValidateJsonFailsForInvalidExtraVars(): void
    {
        $tpl = new JobTemplate();
        $tpl->extra_vars = '{not json}';
        $this->assertFalse($tpl->validate(['extra_vars']));
        $this->assertStringContainsString('valid JSON', $tpl->getErrors()['extra_vars'][0]);
    }

    public function testValidateJsonPassesForEmptyExtraVars(): void
    {
        $tpl = new JobTemplate();
        $tpl->extra_vars = '';
        $this->assertTrue($tpl->validate(['extra_vars']));
    }

    public function testValidateJsonPassesForNullExtraVars(): void
    {
        $tpl = new JobTemplate();
        $tpl->extra_vars = null;
        $this->assertTrue($tpl->validate(['extra_vars']));
    }

    public function testValidateJsonFailsForInvalidSurveyFields(): void
    {
        $tpl = new JobTemplate();
        $tpl->survey_fields = 'not json';
        $this->assertFalse($tpl->validate(['survey_fields']));
        $this->assertStringContainsString('valid JSON', $tpl->getErrors()['survey_fields'][0]);
    }

    public function testValidateJsonPassesForValidSurveyFields(): void
    {
        $tpl = new JobTemplate();
        $tpl->survey_fields = '[{"name":"version","type":"text"}]';
        $this->assertTrue($tpl->validate(['survey_fields']));
    }

    // -- getSurveyFields / hasSurvey -------------------------------------------

    public function testGetSurveyFieldsReturnsParsedFields(): void
    {
        $tpl = $this->makeTemplate();
        $tpl->survey_fields = '[{"name":"version","label":"Version","type":"text","required":true,"default":"latest"}]';
        $tpl->save(false);

        $fields = $tpl->getSurveyFields();
        $this->assertCount(1, $fields);
        $this->assertSame('version', $fields[0]->name);
        $this->assertSame('Version', $fields[0]->label);
        $this->assertTrue($fields[0]->required);
    }

    public function testGetSurveyFieldsReturnsEmptyArrayWhenNull(): void
    {
        $tpl = $this->makeTemplate();
        $tpl->survey_fields = null;
        $this->assertSame([], $tpl->getSurveyFields());
    }

    public function testHasSurveyReturnsTrueWhenFieldsExist(): void
    {
        $tpl = $this->makeTemplate();
        $tpl->survey_fields = '[{"name":"env","type":"select"}]';
        $this->assertTrue($tpl->hasSurvey());
    }

    public function testHasSurveyReturnsFalseWhenNull(): void
    {
        $tpl = $this->makeTemplate();
        $tpl->survey_fields = null;
        $this->assertFalse($tpl->hasSurvey());
    }

    public function testHasSurveyReturnsFalseWhenEmpty(): void
    {
        $tpl = $this->makeTemplate();
        $tpl->survey_fields = '';
        $this->assertFalse($tpl->hasSurvey());
    }

    public function testHasSurveyReturnsFalseWhenEmptyArray(): void
    {
        $tpl = $this->makeTemplate();
        $tpl->survey_fields = '[]';
        $this->assertFalse($tpl->hasSurvey());
    }

    // -- attributeLabels -------------------------------------------------------

    public function testAttributeLabelsContainsRunnerGroupId(): void
    {
        $tpl = new JobTemplate();
        $labels = $tpl->attributeLabels();
        $this->assertArrayHasKey('runner_group_id', $labels);
        $this->assertSame('Runner', $labels['runner_group_id']);
    }

    // -- relations --------------------------------------------------------------

    public function testProjectRelation(): void
    {
        $tpl = $this->makeTemplate();
        $this->assertNotNull($tpl->project);
    }

    public function testInventoryRelation(): void
    {
        $tpl = $this->makeTemplate();
        $this->assertNotNull($tpl->inventory);
    }

    public function testCredentialRelationReturnsNullWhenNotSet(): void
    {
        $tpl = $this->makeTemplate();
        $this->assertNull($tpl->credential);
    }

    public function testCreatorRelation(): void
    {
        $tpl = $this->makeTemplate();
        $this->assertNotNull($tpl->creator);
    }

    public function testRunnerGroupRelation(): void
    {
        $tpl = $this->makeTemplate();
        $this->assertNotNull($tpl->runnerGroup);
    }

    public function testApprovalRuleRelationReturnsNullWhenNotSet(): void
    {
        $tpl = $this->makeTemplate();
        $this->assertNull($tpl->approvalRule);
    }

    public function testJobsRelationReturnsArray(): void
    {
        $tpl = $this->makeTemplate();
        $this->assertIsArray($tpl->jobs);
        $this->assertEmpty($tpl->jobs);
    }

    public function testJobsRelationReturnsJobsWhenTheyExist(): void
    {
        $user = $this->createUser();
        $tpl = $this->makeTemplate($user);
        $this->createJob($tpl->id, $user->id);

        $tpl->refresh();
        $this->assertCount(1, $tpl->jobs);
    }

    // -- find scopes -----------------------------------------------------------

    public function testFindExcludesSoftDeletedByDefault(): void
    {
        $tpl = $this->makeTemplate();
        $id = $tpl->id;
        $tpl->softDelete();

        $this->assertNull(JobTemplate::findOne($id));
    }

    public function testFindWithDeletedIncludesDeleted(): void
    {
        $tpl = $this->makeTemplate();
        $id = $tpl->id;
        $tpl->softDelete();

        $found = JobTemplate::findWithDeleted()->where(['id' => $id])->one();
        $this->assertNotNull($found);
    }

    // -------------------------------------------------------------------------

    /**
     * @param \app\models\User|null $user
     */
    private function makeTemplate($user = null): JobTemplate
    {
        $user = $user ?? $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $project = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        return $this->createJobTemplate($project->id, $inv->id, $group->id, $user->id);
    }
}
