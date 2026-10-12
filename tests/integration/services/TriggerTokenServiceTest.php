<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\WorkflowTemplate;
use app\services\TriggerTokenService;
use app\tests\integration\DbTestCase;

/**
 * Generating and revoking trigger tokens, shared by the web UI and the REST
 * API: the trigger runs as the user who generated the token, only the hash
 * is stored, and both changes are audited without the token.
 */
class TriggerTokenServiceTest extends DbTestCase
{
    private TriggerTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TriggerTokenService();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function templateKindProvider(): array
    {
        return [
            'job template' => [
                'job', 'job_template',
                AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED, AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED,
            ],
            'workflow template' => [
                'workflow', 'workflow_template',
                AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_GENERATED, AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_REVOKED,
            ],
        ];
    }

    /**
     * @dataProvider templateKindProvider
     */
    public function testGenerateStoresTheHashRunsAsTheUserAndAudits(string $kind, string $objectType, string $generated, string $revoked): void
    {
        $owner = $this->createUser('owner');
        $generator = $this->createUser('generator');
        $template = $this->template($kind, (int)$owner->id);

        $raw = $this->service->generate($template, (int)$generator->id, ['source' => 'api']);

        $template->refresh();
        $this->assertSame(hash('sha256', $raw), $template->trigger_token);
        $this->assertSame((int)$generator->id, $template->getTriggerUserId());
        $entry = $this->onlyEntry($generated, $objectType, (int)$template->id);
        $this->assertSame((int)$generator->id, (int)$entry->user_id);
        $this->assertSame(['name' => $template->name, 'source' => 'api'], json_decode((string)$entry->metadata, true));
        $this->assertStringNotContainsString($raw, (string)json_encode($entry->attributes));
        $this->assertFalse(AuditLog::find()->where(['action' => $revoked, 'object_id' => $template->id])->exists());
    }

    /**
     * @dataProvider templateKindProvider
     */
    public function testRevokeClearsTheTokenOnceAndAuditsOnlyAChange(string $kind, string $objectType, string $generated, string $revoked): void
    {
        $owner = $this->createUser('owner');
        $template = $this->template($kind, (int)$owner->id);
        $this->service->generate($template, (int)$owner->id);

        $this->assertTrue($this->service->revoke($template, (int)$owner->id));
        $template->refresh();
        $this->assertNull($template->trigger_token);
        $this->assertNull($template->trigger_token_created_by);
        $entry = $this->onlyEntry($revoked, $objectType, (int)$template->id);
        $this->assertSame(['name' => $template->name], json_decode((string)$entry->metadata, true), 'the web UI adds no source');

        $this->assertFalse($this->service->revoke($template, (int)$owner->id), 'nothing left to revoke');
        $this->onlyEntry($revoked, $objectType, (int)$template->id);
        $this->onlyEntry($generated, $objectType, (int)$template->id);
    }

    private function template(string $kind, int $userId): JobTemplate|WorkflowTemplate
    {
        if ($kind === 'workflow') {
            return $this->createWorkflowTemplate($userId);
        }

        return $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    private function onlyEntry(string $action, string $objectType, int $objectId): AuditLog
    {
        /** @var AuditLog[] $entries */
        $entries = AuditLog::find()->where(['action' => $action, 'object_type' => $objectType, 'object_id' => $objectId])->all();
        $this->assertCount(1, $entries, $action);

        return $entries[0];
    }
}
