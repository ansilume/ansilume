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
