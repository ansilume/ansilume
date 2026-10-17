<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\services\JobLaunchService;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Tests for JobLaunchService::buildRunnerPayload() — the JSON snapshot
 * written to job.runner_payload at launch time.
 *
 * Uses an anonymous subclass that exposes the private method, with both
 * template and job mocked so no database is required.
 */
class JobLaunchServicePayloadTest extends TestCase
{
    private JobLaunchService $service;

    protected function setUp(): void
    {
        $this->service = new class extends JobLaunchService {
            public function exposePayload(JobTemplate $template, Job $job): array
            {
                $json = $this->buildRunnerPayload($template, $job);
                return json_decode($json, true);
            }
        };
    }

    /**
     * A template with $attributes whose inventory relation holds an
     * inventory of its inventory_id named $inventoryName, as the launch's
     * gate loaded it; with $inventoryName null the relation holds null (the
     * inventory's row is gone).
     */
    private function makeTemplate(array $attributes, ?string $inventoryName = 'web-prod'): JobTemplate
    {
        $t = $this->getMockBuilder(JobTemplate::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($t, $attributes);
        $t->populateRelation('inventory', $inventoryName === null ? null : $this->makeInventory([
            'id' => $attributes['inventory_id'] ?? null,
            'name' => $inventoryName,
        ]));
        return $t;
    }

    private function makeInventory(array $attributes): Inventory
    {
        $i = $this->getMockBuilder(Inventory::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($i, $attributes);
        return $i;
    }

    private function makeJob(array $attributes): Job
    {
        $j = $this->getMockBuilder(Job::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $ref = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($j, $attributes);
        return $j;
    }

    public function testPayloadContainsTemplateIdentifiers(): void
    {
        $template = $this->makeTemplate([
            'id' => 7, 'name' => 'Deploy', 'project_id' => 3, 'inventory_id' => 2,
            'credential_id' => 1, 'playbook' => 'deploy.yml',
            'extra_vars' => null, 'limit' => null, 'verbosity' => 0,
            'forks' => 5, 'become' => false, 'become_method' => 'sudo',
            'become_user' => 'root', 'tags' => null, 'skip_tags' => null,
            'timeout_minutes' => 120,
        ]);
        $job = $this->makeJob(['extra_vars' => null, 'limit' => null, 'verbosity' => null]);

        $payload = $this->service->exposePayload($template, $job);

        $this->assertSame(7, $payload['template_id']);
        $this->assertSame('Deploy', $payload['template_name']);
        $this->assertSame(3, $payload['project_id']);
        $this->assertSame('deploy.yml', $payload['playbook']);
        $this->assertSame(2, $payload['inventory_id']);
        $this->assertSame('web-prod', $payload[Job::PAYLOAD_INVENTORY_NAME], 'the inventory as launched');
    }

    /**
     * The inventory's name follows its ID, so the job page names the
     * inventory as it was launched (JobInventory).
     */
    public function testTheInventoryNameFollowsTheInventoryId(): void
    {
        $template = $this->makeTemplate([
            'id' => 7, 'name' => 'Deploy', 'project_id' => 3, 'inventory_id' => 2,
            'credential_id' => null, 'playbook' => 'deploy.yml',
            'extra_vars' => null, 'limit' => null, 'verbosity' => 0,
            'forks' => 5, 'become' => false, 'become_method' => 'sudo',
            'become_user' => 'root', 'tags' => null, 'skip_tags' => null,
            'timeout_minutes' => 120,
        ], 'Prod "hosts" <b>');
        $job = $this->makeJob(['extra_vars' => null, 'limit' => null, 'verbosity' => null]);

        $keys = array_keys($this->service->exposePayload($template, $job));

        $this->assertSame(Job::PAYLOAD_INVENTORY_NAME, $keys[array_search('inventory_id', $keys, true) + 1]);
        $this->assertSame('Prod "hosts" <b>', $this->service->exposePayload($template, $job)[Job::PAYLOAD_INVENTORY_NAME], 'stored as named');
    }

    /**
     * A template loaded after its inventory's row was deleted: the payload
     * records the ID and no name (the launch then refuses it, INVENTORY_MISSING).
     */
    public function testTheInventoryNameIsNullWithoutTheInventory(): void
    {
        $template = $this->makeTemplate([
            'id' => 7, 'name' => 'Deploy', 'project_id' => 3, 'inventory_id' => 2,
            'credential_id' => null, 'playbook' => 'deploy.yml',
            'extra_vars' => null, 'limit' => null, 'verbosity' => 0,
            'forks' => 5, 'become' => false, 'become_method' => 'sudo',
            'become_user' => 'root', 'tags' => null, 'skip_tags' => null,
            'timeout_minutes' => 120,
        ], null);
        $job = $this->makeJob(['extra_vars' => null, 'limit' => null, 'verbosity' => null]);

        $payload = $this->service->exposePayload($template, $job);

        $this->assertSame(2, $payload['inventory_id']);
        $this->assertArrayHasKey(Job::PAYLOAD_INVENTORY_NAME, $payload);
        $this->assertNull($payload[Job::PAYLOAD_INVENTORY_NAME]);
    }

    public function testPayloadUsesJobExtraVarsOverTemplateWhenSet(): void
    {
        $template = $this->makeTemplate([
            'id' => 1, 'name' => 'T', 'project_id' => 1, 'inventory_id' => 1,
            'credential_id' => null, 'playbook' => 'site.yml',
            'extra_vars' => '{"env":"template"}', 'limit' => null, 'verbosity' => 0,
            'forks' => 5, 'become' => false, 'become_method' => 'sudo',
            'become_user' => 'root', 'tags' => null, 'skip_tags' => null,
            'timeout_minutes' => 60,
        ]);
        $job = $this->makeJob(['extra_vars' => '{"env":"job_override"}', 'limit' => null, 'verbosity' => null]);

        $payload = $this->service->exposePayload($template, $job);

        $this->assertSame('{"env":"job_override"}', $payload['extra_vars']);
    }

    public function testPayloadFallsBackToTemplateExtraVarsWhenJobHasNone(): void
    {
        $template = $this->makeTemplate([
            'id' => 1, 'name' => 'T', 'project_id' => 1, 'inventory_id' => 1,
            'credential_id' => null, 'playbook' => 'site.yml',
            'extra_vars' => '{"env":"template"}', 'limit' => null, 'verbosity' => 0,
            'forks' => 5, 'become' => false, 'become_method' => 'sudo',
            'become_user' => 'root', 'tags' => null, 'skip_tags' => null,
            'timeout_minutes' => 60,
        ]);
        $job = $this->makeJob(['extra_vars' => null, 'limit' => null, 'verbosity' => null]);

        $payload = $this->service->exposePayload($template, $job);

        $this->assertSame('{"env":"template"}', $payload['extra_vars']);
    }

    public function testPayloadContainsTimeoutMinutes(): void
    {
        $template = $this->makeTemplate([
            'id' => 1, 'name' => 'T', 'project_id' => 1, 'inventory_id' => 1,
            'credential_id' => null, 'playbook' => 'site.yml',
            'extra_vars' => null, 'limit' => null, 'verbosity' => 0,
            'forks' => 5, 'become' => false, 'become_method' => 'sudo',
            'become_user' => 'root', 'tags' => null, 'skip_tags' => null,
            'timeout_minutes' => 180,
        ]);
        $job = $this->makeJob(['extra_vars' => null, 'limit' => null, 'verbosity' => null]);

        $payload = $this->service->exposePayload($template, $job);

        $this->assertSame(180, $payload['timeout_minutes']);
    }

    public function testPayloadUsesJobLimitOverTemplateLimitWhenSet(): void
    {
        $template = $this->makeTemplate([
            'id' => 1, 'name' => 'T', 'project_id' => 1, 'inventory_id' => 1,
            'credential_id' => null, 'playbook' => 'site.yml',
            'extra_vars' => null, 'limit' => 'all', 'verbosity' => 0,
            'forks' => 5, 'become' => false, 'become_method' => 'sudo',
            'become_user' => 'root', 'tags' => null, 'skip_tags' => null,
            'timeout_minutes' => 120,
        ]);
        $job = $this->makeJob(['extra_vars' => null, 'limit' => 'webservers', 'verbosity' => null]);

        $payload = $this->service->exposePayload($template, $job);

        $this->assertSame('webservers', $payload['limit']);
    }

    public function testPayloadForksAndBecomeFromTemplate(): void
    {
        $template = $this->makeTemplate([
            'id' => 1, 'name' => 'T', 'project_id' => 1, 'inventory_id' => 1,
            'credential_id' => null, 'playbook' => 'site.yml',
            'extra_vars' => null, 'limit' => null, 'verbosity' => 0,
            'forks' => 10, 'become' => true, 'become_method' => 'doas',
            'become_user' => 'deploy', 'tags' => 'tag1', 'skip_tags' => 'slow',
            'timeout_minutes' => 120,
        ]);
        $job = $this->makeJob(['extra_vars' => null, 'limit' => null, 'verbosity' => null]);

        $payload = $this->service->exposePayload($template, $job);

        $this->assertSame(10, $payload['forks']);
        $this->assertTrue($payload['become']);
        $this->assertSame('doas', $payload['become_method']);
        $this->assertSame('deploy', $payload['become_user']);
        $this->assertSame('tag1', $payload['tags']);
        $this->assertSame('slow', $payload['skip_tags']);
    }
}
