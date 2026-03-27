<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\JobArtifact;
use PHPUnit\Framework\TestCase;

class JobArtifactTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertSame('{{%job_artifact}}', JobArtifact::tableName());
    }

    public function testRulesReturnArray(): void
    {
        $model = new class () extends JobArtifact {
            public function init(): void
            {
            }
        };

        $rules = $model->rules();
        $this->assertIsArray($rules);
        $this->assertNotEmpty($rules);

        // Verify required fields are defined
        $requiredFields = [];
        foreach ($rules as $rule) {
            if (isset($rule[1]) && $rule[1] === 'required') {
                $requiredFields = array_merge($requiredFields, (array)$rule[0]);
            }
        }
        $this->assertContains('job_id', $requiredFields);
        $this->assertContains('filename', $requiredFields);
        $this->assertContains('display_name', $requiredFields);
        $this->assertContains('storage_path', $requiredFields);
    }
}
