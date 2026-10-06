<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\CredentialUsage;
use PHPUnit\Framework\TestCase;

class CredentialUsageTest extends TestCase
{
    public function testSummaryCountsEverythingButNamesNothingHidden(): void
    {
        $usage = new CredentialUsage(
            'prod-vault',
            [['id' => 7, 'name' => 'deploy', 'project_id' => 3, 'project_name' => 'web', 'role' => 'primary']],
            3,
            [],
            1,
            2
        );

        $this->assertTrue($usage->isInUse());
        $this->assertSame(2, $usage->hiddenJobTemplateCount());
        $this->assertSame(1, $usage->hiddenProjectCount());
        $this->assertSame('Credential "prod-vault" is in use by 3 job template(s), 1 project(s) and 2 pending job(s).', $usage->summary());
        $this->assertSame([
            'in_use' => true,
            'job_templates' => [['id' => 7, 'name' => 'deploy', 'project_id' => 3, 'project_name' => 'web', 'role' => 'primary']],
            'projects' => [],
            'hidden_job_template_count' => 2,
            'hidden_project_count' => 1,
            'pending_job_count' => 2,
        ], $usage->toArray());
    }

    public function testUnusedAndSinglePartSummaries(): void
    {
        $unused = new CredentialUsage('spare', [], 0, [], 0, 0);
        $onlyJobs = new CredentialUsage('busy', [], 0, [], 0, 4);

        $this->assertFalse($unused->isInUse());
        $this->assertSame('Credential "spare" is not in use.', $unused->summary());
        $this->assertTrue($onlyJobs->isInUse());
        $this->assertSame('Credential "busy" is in use by 4 pending job(s).', $onlyJobs->summary());
    }
}
