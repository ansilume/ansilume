<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\SurveyField;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SurveyField — pure value object, no Yii or DB required.
 */
class SurveyFieldTest extends TestCase
{
    // ── Constructor & defaults ────────────────────────────────────────────────

    public function testConstructorSetsAllFields(): void
    {
        $f = new SurveyField('env', 'Environment', SurveyField::TYPE_SELECT, true, 'prod', ['prod', 'staging'], 'Choose env');
        $this->assertSame('env', $f->name);
        $this->assertSame('Environment', $f->label);
        $this->assertSame(SurveyField::TYPE_SELECT, $f->type);
        $this->assertTrue($f->required);
        $this->assertSame('prod', $f->default);
        $this->assertSame(['prod', 'staging'], $f->options);
        $this->assertSame('Choose env', $f->hint);
    }

    public function testConstructorUsesNameAsLabelWhenLabelOmitted(): void
    {
        $f = new SurveyField('my_var');
        $this->assertSame('my_var', $f->label);
    }

    public function testConstructorDefaults(): void
    {
        $f = new SurveyField('x');
        $this->assertSame(SurveyField::TYPE_TEXT, $f->type);
        $this->assertFalse($f->required);
        $this->assertSame('', $f->default);
        $this->assertSame([], $f->options);
        $this->assertSame('', $f->hint);
    }

    // ── fromArray ─────────────────────────────────────────────────────────────

    public function testFromArrayRoundTrip(): void
    {
        $data = [
            'name'     => 'version',
            'label'    => 'Target Version',
            'type'     => SurveyField::TYPE_TEXT,
            'required' => true,
            'default'  => 'latest',
            'options'  => [],
            'hint'     => 'Semver string',
        ];
        $f = SurveyField::fromArray($data);
        $this->assertSame($data, $f->toArray());
    }

    public function testFromArrayUsesDefaults(): void
    {
        $f = SurveyField::fromArray(['name' => 'x']);
        $this->assertSame('x', $f->name);
        $this->assertSame('x', $f->label);   // label defaults to name
        $this->assertSame(SurveyField::TYPE_TEXT, $f->type);
        $this->assertFalse($f->required);
        $this->assertSame('', $f->default);
        $this->assertSame([], $f->options);
    }

    public function testFromArrayCastsBooleanRequired(): void
    {
        $this->assertTrue(SurveyField::fromArray(['name' => 'x', 'required' => 1])->required);
        $this->assertFalse(SurveyField::fromArray(['name' => 'x', 'required' => 0])->required);
        $this->assertFalse(SurveyField::fromArray(['name' => 'x'])->required);
    }

    /**
     * Regression: the API stored any JSON as survey_fields, and a list or an
     * object as a value made fromArray() fail with "Array to string
     * conversion" (a yii\base\ErrorException), so the edit and launch pages
     * of such a template failed with a 500.
     */
    public function testFromArrayReadsListsAndObjectsAsMissingValues(): void
    {
        $f = SurveyField::fromArray([
            'name' => ['a' => 1],
            'label' => ['x'],
            'type' => ['password'],
            'default' => ['secret'],
            'hint' => (object)[],
            'options' => ['one', ['nested'], 2, true, null],
        ]);

        $this->assertSame('', $f->name);
        $this->assertSame('', $f->label);
        $this->assertSame(SurveyField::TYPE_TEXT, $f->type);
        $this->assertSame('', $f->default);
        $this->assertSame('', $f->hint);
        $this->assertSame(['one', '2', '1'], $f->options, 'single values as text, in order');
    }

    public function testFromArrayCastsSingleValuesAsBefore(): void
    {
        $f = SurveyField::fromArray(['name' => 42, 'label' => 1.5, 'type' => 'integer', 'default' => 7, 'hint' => false]);

        $this->assertSame('42', $f->name);
        $this->assertSame('1.5', $f->label);
        $this->assertSame('7', $f->default);
        $this->assertSame('', $f->hint);
    }

    public function testFromArrayListsOptionsGivenAsAMap(): void
    {
        $this->assertSame(['a', 'b'], SurveyField::fromArray(['name' => 'x', 'options' => ['k1' => 'a', 'k2' => 'b']])->options);
        $this->assertSame([], SurveyField::fromArray(['name' => 'x', 'options' => 'a,b'])->options);
    }

    public function testParseJsonOfMalformedFieldsDoesNotFail(): void
    {
        $fields = SurveyField::parseJson('[{"name":{"a":1},"type":"password","default":[1]}]');

        $this->assertCount(1, $fields);
        $this->assertSame('', $fields[0]->name);
        $this->assertSame(SurveyField::TYPE_PASSWORD, $fields[0]->type);
    }

    // ── toArray ───────────────────────────────────────────────────────────────

    public function testToArrayContainsAllKeys(): void
    {
        $f = new SurveyField('k');
        $arr = $f->toArray();
        foreach (['name', 'label', 'type', 'required', 'default', 'options', 'hint'] as $key) {
            $this->assertArrayHasKey($key, $arr);
        }
    }

    // ── parseJson ─────────────────────────────────────────────────────────────

    public function testParseJsonReturnsSurveyFields(): void
    {
        $json = json_encode([
            ['name' => 'env', 'type' => SurveyField::TYPE_SELECT, 'required' => true, 'options' => ['prod', 'dev']],
            ['name' => 'ver', 'type' => SurveyField::TYPE_TEXT],
        ]);
        $fields = SurveyField::parseJson($json);
        $this->assertCount(2, $fields);
        $this->assertInstanceOf(SurveyField::class, $fields[0]);
        $this->assertSame('env', $fields[0]->name);
        $this->assertSame('ver', $fields[1]->name);
        $this->assertSame(['prod', 'dev'], $fields[0]->options);
    }

    public function testParseJsonNullReturnsEmpty(): void
    {
        $this->assertSame([], SurveyField::parseJson(null));
    }

    public function testParseJsonEmptyStringReturnsEmpty(): void
    {
        $this->assertSame([], SurveyField::parseJson(''));
    }

    public function testParseJsonInvalidJsonReturnsEmpty(): void
    {
        $this->assertSame([], SurveyField::parseJson('{not json}'));
    }

    public function testParseJsonSkipsNonArrayEntries(): void
    {
        $json = json_encode([['name' => 'ok'], 'not-an-array', null]);
        $fields = SurveyField::parseJson($json);
        $this->assertCount(1, $fields);
        $this->assertSame('ok', $fields[0]->name);
    }

    public function testParseJsonReindexes(): void
    {
        $json   = json_encode([['name' => 'a'], 'skip', ['name' => 'b']]);
        $fields = SurveyField::parseJson($json);
        $this->assertArrayHasKey(0, $fields);
        $this->assertArrayHasKey(1, $fields);
    }

    // ── types ─────────────────────────────────────────────────────────────────

    public function testTypesReturnsAllSixTypes(): void
    {
        $types = SurveyField::types();
        $this->assertCount(6, $types);
        foreach (
            [
            SurveyField::TYPE_TEXT,
            SurveyField::TYPE_TEXTAREA,
            SurveyField::TYPE_INTEGER,
            SurveyField::TYPE_BOOLEAN,
            SurveyField::TYPE_SELECT,
            SurveyField::TYPE_PASSWORD,
            ] as $type
        ) {
            $this->assertArrayHasKey($type, $types);
            $this->assertIsString($types[$type]);
            $this->assertNotEmpty($types[$type]);
        }
    }
}
