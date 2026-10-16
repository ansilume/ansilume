<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\RunnerGroup;
use PHPUnit\Framework\TestCase;

class RunnerGroupTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%runner_group}}', RunnerGroup::tableName());
    }

    public function testStaleAfterConstantIs120(): void
    {
        $this->assertSame(120, RunnerGroup::STALE_AFTER);
    }

    public function testRulesRequireName(): void
    {
        $group = new RunnerGroup();
        $group->validate();
        $this->assertArrayHasKey('name', $group->errors);
    }

    public function testNameMaxLength128(): void
    {
        $group = new RunnerGroup();
        $group->name = str_repeat('x', 129);
        $group->validate(['name']);
        $this->assertArrayHasKey('name', $group->errors);
    }

    /**
     * Regression (review of the runner step, finding 4): created_by was a
     * safe attribute, so the web forms took the creator from the request. It
     * is set in code only now, and still validated.
     */
    public function testCreatedByIsSetInCodeOnly(): void
    {
        $group = new RunnerGroup();
        $this->assertSame(['name', 'description'], $group->safeAttributes());

        $group->setAttributes(['name' => 'eu-west', 'created_by' => 7]);
        $this->assertSame('eu-west', $group->name);
        $this->assertNull($group->created_by, 'a form does not set the creator');

        $group->setAttributes(['created_by' => 'not a user ID'], false);
        $this->assertFalse($group->validate(['created_by']));
        $this->assertArrayHasKey('created_by', $group->errors, 'what code sets is validated');
    }
}
