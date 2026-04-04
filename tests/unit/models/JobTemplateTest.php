<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\JobTemplate;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for JobTemplate pure-logic helpers — no DB required.
 */
class JobTemplateTest extends TestCase
{
    // ── getSurveyFields / hasSurvey ───────────────────────────────────────────

    public function testGetSurveyFieldsReturnsEmptyWhenNull(): void
    {
        $tpl = $this->makeTemplate(['survey_fields' => null]);
        $this->assertSame([], $tpl->getSurveyFields());
    }

    public function testGetSurveyFieldsReturnsEmptyWhenEmpty(): void
    {
        $tpl = $this->makeTemplate(['survey_fields' => '']);
        $this->assertSame([], $tpl->getSurveyFields());
    }

    public function testGetSurveyFieldsReturnsSurveyFieldObjects(): void
    {
        $json = json_encode([[
            'name'     => 'env',
            'label'    => 'Environment',
            'type'     => 'text',
            'required' => true,
            'default'  => 'prod',
            'options'  => [],
        ]]);
        $tpl    = $this->makeTemplate(['survey_fields' => $json]);
        $fields = $tpl->getSurveyFields();
        $this->assertCount(1, $fields);
        $this->assertSame('env', $fields[0]->name);
    }

    public function testHasSurveyFalseWhenNoFields(): void
    {
        $tpl = $this->makeTemplate(['survey_fields' => null]);
        $this->assertFalse($tpl->hasSurvey());
    }

    public function testHasSurveyTrueWhenFieldsPresent(): void
    {
        $json = json_encode([[
            'name'     => 'env',
            'label'    => 'Env',
            'type'     => 'text',
            'required' => false,
            'default'  => '',
            'options'  => [],
        ]]);
        $tpl = $this->makeTemplate(['survey_fields' => $json]);
        $this->assertTrue($tpl->hasSurvey());
    }

    // ── validateJson ──────────────────────────────────────────────────────────

    public function testValidateJsonPassesForValidJson(): void
    {
        $tpl = $this->makeTemplate(['extra_vars' => '{"key":"value"}']);
        $tpl->validate(['extra_vars']);
        $this->assertFalse($tpl->hasErrors('extra_vars'));
    }

    public function testValidateJsonFailsForInvalidJson(): void
    {
        $tpl = $this->makeTemplate(['extra_vars' => '{broken']);
        $tpl->validate(['extra_vars']);
        $this->assertTrue($tpl->hasErrors('extra_vars'));
    }

    public function testValidateJsonPassesForEmptyValue(): void
    {
        $tpl = $this->makeTemplate(['extra_vars' => '']);
        $tpl->validate(['extra_vars']);
        $this->assertFalse($tpl->hasErrors('extra_vars'));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeTemplate(array $attrs = []): JobTemplate
    {
        $tpl = $this->getMockBuilder(JobTemplate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $tpl->method('attributes')->willReturn([
            'id', 'name', 'description', 'project_id', 'inventory_id', 'credential_id',
            'playbook', 'extra_vars', 'verbosity', 'forks', 'become', 'become_method',
            'become_user', 'limit', 'tags', 'skip_tags', 'runner_group_id',
            'survey_fields', 'trigger_token',
            'created_by', 'created_at', 'updated_at',
        ]);
        $tpl->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($tpl, array_merge([
            'id' => 1,
            'name' => 'Test Template',
            'description' => null,
            'project_id' => 1,
            'inventory_id' => 1,
            'credential_id' => null,
            'playbook' => 'site.yml',
            'extra_vars' => null,
            'verbosity' => 0,
            'forks' => 5,
            'become' => false,
            'become_method' => 'sudo',
            'become_user' => 'root',
            'limit' => null,
            'tags' => null,
            'skip_tags' => null,
            'runner_group_id' => null,
            'survey_fields' => null,
            'trigger_token' => null,
            'created_by' => 1,
            'created_at' => null,
            'updated_at' => null,
        ], $attrs));
        return $tpl;
    }
}
