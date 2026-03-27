<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Inventory;
use app\models\Project;
use app\services\JobClaimService;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for JobClaimService::buildExecutionPayload() — pure payload assembly logic.
 * claim() is DB-dependent and covered by integration tests.
 *
 * All tests use an anonymous subclass that stubs out the two DB lookups
 * (resolveProjectPath, resolveInventory) so no database is required.
 */
class JobClaimServiceTest extends TestCase
{
    /** Default no-DB service: returns a fixed project path and static localhost inventory */
    private function makeService(
        string $projectPath = '/var/projects/default',
        ?array $inventory = null
    ): JobClaimService {
        $inv = $inventory ?? ['type' => 'static', 'content' => "localhost\n", 'path' => null];

        return new class ($projectPath, $inv) extends JobClaimService {
            public function __construct(
                private readonly string $projectPath,
                private readonly array $inv,
            ) {
            }

            protected function resolveProjectPath(array $payload): string
            {
                return $this->projectPath;
            }

            protected function resolveInventory(array $payload): array
            {
                return $this->inv;
            }
        };
    }

    public function testPayloadContainsJobId(): void
    {
        $job     = $this->makeJob(1, []);
        $payload = $this->makeService()->buildExecutionPayload($job);
        $this->assertSame(1, $payload['job_id']);
    }

    public function testPayloadDefaultsWhenRunnerPayloadIsEmpty(): void
    {
        $job     = $this->makeJob(5, []);
        $payload = $this->makeService()->buildExecutionPayload($job);
        $this->assertSame(0, $payload['verbosity']);
        $this->assertSame(5, $payload['forks']);
        $this->assertFalse($payload['become']);
        $this->assertSame('sudo', $payload['become_method']);
        $this->assertSame('root', $payload['become_user']);
        $this->assertNull($payload['limit']);
        $this->assertNull($payload['tags']);
        $this->assertNull($payload['skip_tags']);
        $this->assertNull($payload['extra_vars']);
    }

    public function testPayloadMapsRunnerPayloadFields(): void
    {
        $raw = [
            'playbook'     => 'deploy.yml',
            'verbosity'    => 2,
            'forks'        => 10,
            'become'       => true,
            'become_method' => 'su',
            'become_user'  => 'deploy',
            'limit'        => 'webservers',
            'tags'         => 'app',
            'skip_tags'    => 'slow',
            'extra_vars'   => '{"env":"prod"}',
        ];
        $job     = $this->makeJob(3, $raw);
        $payload = $this->makeService()->buildExecutionPayload($job);

        $this->assertSame(2, $payload['verbosity']);
        $this->assertSame(10, $payload['forks']);
        $this->assertTrue($payload['become']);
        $this->assertSame('su', $payload['become_method']);
        $this->assertSame('deploy', $payload['become_user']);
        $this->assertSame('webservers', $payload['limit']);
        $this->assertSame('app', $payload['tags']);
        $this->assertSame('slow', $payload['skip_tags']);
        $this->assertSame('{"env":"prod"}', $payload['extra_vars']);
    }

    public function testPlaybookPathCombinesProjectPathAndPlaybook(): void
    {
        $raw     = ['project_id' => 42, 'playbook' => 'site.yml'];
        $job     = $this->makeJob(1, $raw);
        $service = $this->makeService('/var/projects/42');

        $payload = $service->buildExecutionPayload($job);
        $this->assertSame('/var/projects/42/site.yml', $payload['playbook_path']);
    }

    public function testStaticInventoryTypeAndContent(): void
    {
        $raw     = ['inventory_id' => 1, 'playbook' => 'site.yml'];
        $job     = $this->makeJob(1, $raw);
        $service = $this->makeService(
            inventory: ['type' => 'static', 'content' => "web1\nweb2\n", 'path' => null]
        );

        $payload = $service->buildExecutionPayload($job);
        $this->assertSame('static', $payload['inventory_type']);
        $this->assertSame("web1\nweb2\n", $payload['inventory_content']);
        $this->assertNull($payload['inventory_path']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeJob(int $id, array $rawPayload): \app\models\Job
    {
        $job = $this->getMockBuilder(\app\models\Job::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $job->method('attributes')->willReturn(['id', 'runner_payload', 'status']);
        $job->method('save')->willReturn(true);
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($job, [
            'id'             => $id,
            'runner_payload' => empty($rawPayload) ? null : json_encode($rawPayload),
            'status'         => 'running',
        ]);
        return $job;
    }
}
