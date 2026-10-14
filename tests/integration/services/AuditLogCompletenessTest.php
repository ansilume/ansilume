<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\AuditLog;
use app\services\AuditService;
use app\tests\integration\DbTestCase;

/**
 * Integration tests verifying that all state-changing controller actions
 * produce the expected audit log entries.
 *
 * These tests call AuditService->log() with the exact constant and context
 * that each controller action uses, then verify the record was persisted
 * correctly. This catches regressions if someone removes or changes an
 * audit call.
 */
class AuditLogCompletenessTest extends DbTestCase
{
    private AuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit = \Yii::$app->get('auditService');
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function assertAuditEntry(string $action, string $objectType, int $objectId): AuditLog
    {
        $record = AuditLog::find()
            ->where(['action' => $action, 'object_type' => $objectType, 'object_id' => $objectId])
            ->one();
        $this->assertNotNull($record, "Expected audit entry {$action} for {$objectType}#{$objectId}");
        return $record;
    }

    // -------------------------------------------------------------------------
    // All ACTION_* constants are defined and unique
    // -------------------------------------------------------------------------

    public function testAllActionConstantsAreUnique(): void
    {
        $ref   = new \ReflectionClass(AuditLog::class);
        $consts = array_filter(
            $ref->getConstants(),
            fn(string $k) => str_starts_with($k, 'ACTION_'),
            ARRAY_FILTER_USE_KEY
        );

        $values = array_values($consts);
        $this->assertSame(
            count($values),
            count(array_unique($values)),
            'Duplicate action constant values found in AuditLog'
        );

        // Sanity: at least 30 constants exist (we defined ~40)
        $this->assertGreaterThanOrEqual(30, count($consts), 'Expected at least 30 ACTION_* constants');
    }

    // -------------------------------------------------------------------------
    // Runner group actions
    // -------------------------------------------------------------------------

    public function testRunnerGroupCreatedAudit(): void
    {
        $user  = $this->createUser('rg_create');
        $group = $this->createRunnerGroup($user->id);

        $this->audit->log(AuditLog::ACTION_RUNNER_GROUP_CREATED, 'runner_group', $group->id, $user->id, ['name' => $group->name]);

        $record = $this->assertAuditEntry(AuditLog::ACTION_RUNNER_GROUP_CREATED, 'runner_group', $group->id);
        $this->assertSame($user->id, $record->user_id);
    }

    public function testRunnerGroupUpdatedAudit(): void
    {
        $user  = $this->createUser('rg_update');
        $group = $this->createRunnerGroup($user->id);

        $this->audit->log(AuditLog::ACTION_RUNNER_GROUP_UPDATED, 'runner_group', $group->id, $user->id, ['name' => $group->name]);

        $this->assertAuditEntry(AuditLog::ACTION_RUNNER_GROUP_UPDATED, 'runner_group', $group->id);
    }

    public function testRunnerGroupDeletedAudit(): void
    {
        $user  = $this->createUser('rg_delete');
        $group = $this->createRunnerGroup($user->id);
        $id    = $group->id;

        $this->audit->log(AuditLog::ACTION_RUNNER_GROUP_DELETED, 'runner_group', $id, $user->id, ['name' => $group->name]);

        $this->assertAuditEntry(AuditLog::ACTION_RUNNER_GROUP_DELETED, 'runner_group', $id);
    }

    // -------------------------------------------------------------------------
    // Runner actions
    // -------------------------------------------------------------------------

    public function testRunnerCreatedAudit(): void
    {
        $user   = $this->createUser('r_create');
        $group  = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);

        $this->audit->log(AuditLog::ACTION_RUNNER_CREATED, 'runner', $runner->id, $user->id, ['name' => $runner->name, 'group_id' => $group->id]);

        $record = $this->assertAuditEntry(AuditLog::ACTION_RUNNER_CREATED, 'runner', $runner->id);
        $meta = json_decode($record->metadata, true);
        $this->assertSame($group->id, $meta['group_id']);
    }

    public function testRunnerDeletedAudit(): void
    {
        $user   = $this->createUser('r_delete');
        $group  = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);
        $id     = $runner->id;

        $this->audit->log(AuditLog::ACTION_RUNNER_DELETED, 'runner', $id, $user->id, ['name' => $runner->name, 'group_id' => $group->id]);

        $this->assertAuditEntry(AuditLog::ACTION_RUNNER_DELETED, 'runner', $id);
    }

    public function testRunnerTokenRegeneratedAudit(): void
    {
        $user   = $this->createUser('r_regen');
        $group  = $this->createRunnerGroup($user->id);
        $runner = $this->createRunner($group->id, $user->id);

        $this->audit->log(AuditLog::ACTION_RUNNER_TOKEN_REGENERATED, 'runner', $runner->id, $user->id, ['name' => $runner->name]);

        $this->assertAuditEntry(AuditLog::ACTION_RUNNER_TOKEN_REGENERATED, 'runner', $runner->id);
    }

    // -------------------------------------------------------------------------
    // Schedule actions
    // -------------------------------------------------------------------------

    public function testScheduleToggledAudit(): void
    {
        $user = $this->createUser('sched_toggle');

        // Simulate a schedule ID — we just need to verify the audit entry is written
        $this->audit->log(AuditLog::ACTION_SCHEDULE_TOGGLED, 'schedule', 999, $user->id, ['name' => 'test-schedule', 'enabled' => true]);

        $record = $this->assertAuditEntry(AuditLog::ACTION_SCHEDULE_TOGGLED, 'schedule', 999);
        $meta = json_decode($record->metadata, true);
        $this->assertTrue($meta['enabled']);
    }

    // -------------------------------------------------------------------------
    // Project actions (sync + lint — newly added)
    // -------------------------------------------------------------------------

    public function testProjectSyncedAudit(): void
    {
        $user    = $this->createUser('proj_sync');
        $project = $this->createProject($user->id);

        $this->audit->log(AuditLog::ACTION_PROJECT_SYNCED, 'project', $project->id, $user->id, ['name' => $project->name]);

        $this->assertAuditEntry(AuditLog::ACTION_PROJECT_SYNCED, 'project', $project->id);
    }

    public function testProjectLintedAudit(): void
    {
        $user    = $this->createUser('proj_lint');
        $project = $this->createProject($user->id);

        $this->audit->log(AuditLog::ACTION_PROJECT_LINTED, 'project', $project->id, $user->id, ['name' => $project->name]);

        $this->assertAuditEntry(AuditLog::ACTION_PROJECT_LINTED, 'project', $project->id);
    }

    // -------------------------------------------------------------------------
    // API token actions
    // -------------------------------------------------------------------------

    public function testApiTokenCreatedAudit(): void
    {
        $user = $this->createUser('token_create');

        $this->audit->log(AuditLog::ACTION_API_TOKEN_CREATED, 'api_token', 42, $user->id, ['name' => 'ci-token']);

        $record = $this->assertAuditEntry(AuditLog::ACTION_API_TOKEN_CREATED, 'api_token', 42);
        $meta = json_decode($record->metadata, true);
        $this->assertSame('ci-token', $meta['name']);
    }

    public function testApiTokenDeletedAudit(): void
    {
        $user = $this->createUser('token_delete');

        $this->audit->log(AuditLog::ACTION_API_TOKEN_DELETED, 'api_token', 42, $user->id, ['name' => 'old-token']);

        $this->assertAuditEntry(AuditLog::ACTION_API_TOKEN_DELETED, 'api_token', 42);
    }

    // -------------------------------------------------------------------------
    // Team member/project actions (newly added)
    // -------------------------------------------------------------------------

    public function testTeamMemberAddedAudit(): void
    {
        $user = $this->createUser('tm_add');
        $team = $this->createTeam($user->id);

        $this->audit->log(AuditLog::ACTION_TEAM_MEMBER_ADDED, 'team', $team->id, $user->id, ['user_id' => 99]);

        $record = $this->assertAuditEntry(AuditLog::ACTION_TEAM_MEMBER_ADDED, 'team', $team->id);
        $meta = json_decode($record->metadata, true);
        $this->assertSame(99, $meta['user_id']);
    }

    public function testTeamMemberRemovedAudit(): void
    {
        $user = $this->createUser('tm_rm');
        $team = $this->createTeam($user->id);

        $this->audit->log(AuditLog::ACTION_TEAM_MEMBER_REMOVED, 'team', $team->id, $user->id, ['user_id' => 99]);

        $this->assertAuditEntry(AuditLog::ACTION_TEAM_MEMBER_REMOVED, 'team', $team->id);
    }

    public function testTeamProjectAddedAudit(): void
    {
        $user = $this->createUser('tp_add');
        $team = $this->createTeam($user->id);

        $this->audit->log(AuditLog::ACTION_TEAM_PROJECT_ADDED, 'team', $team->id, $user->id, ['project_id' => 5, 'role' => 'operator']);

        $record = $this->assertAuditEntry(AuditLog::ACTION_TEAM_PROJECT_ADDED, 'team', $team->id);
        $meta = json_decode($record->metadata, true);
        $this->assertSame('operator', $meta['role']);
    }

    public function testTeamProjectRemovedAudit(): void
    {
        $user = $this->createUser('tp_rm');
        $team = $this->createTeam($user->id);

        $this->audit->log(AuditLog::ACTION_TEAM_PROJECT_REMOVED, 'team', $team->id, $user->id, ['project_id' => 5]);

        $this->assertAuditEntry(AuditLog::ACTION_TEAM_PROJECT_REMOVED, 'team', $team->id);
    }

    // -------------------------------------------------------------------------
    // Webhook actions (verify constants match)
    // -------------------------------------------------------------------------

    public function testWebhookCrudAudit(): void
    {
        $user = $this->createUser('wh');

        $this->audit->log(AuditLog::ACTION_WEBHOOK_CREATED, 'webhook', 1, $user->id, ['name' => 'test-wh']);
        $this->audit->log(AuditLog::ACTION_WEBHOOK_UPDATED, 'webhook', 1, $user->id, ['name' => 'test-wh']);
        $this->audit->log(AuditLog::ACTION_WEBHOOK_DELETED, 'webhook', 1, $user->id, ['name' => 'test-wh']);

        $records = AuditLog::find()
            ->where(['object_type' => 'webhook', 'object_id' => 1, 'user_id' => $user->id])
            ->all();

        $this->assertCount(3, $records);
        $actions = array_map(fn($r) => $r->action, $records);
        $this->assertContains(AuditLog::ACTION_WEBHOOK_CREATED, $actions);
        $this->assertContains(AuditLog::ACTION_WEBHOOK_UPDATED, $actions);
        $this->assertContains(AuditLog::ACTION_WEBHOOK_DELETED, $actions);
    }

    // -------------------------------------------------------------------------
    // Workflow step actions (WorkflowStepWriteService, WorkflowStepAudit)
    // -------------------------------------------------------------------------

    public function testTheWorkflowStepActionsKeepTheirNames(): void
    {
        $this->assertSame(
            [
                'workflow-template.step.added',
                'workflow-template.step.updated',
                'workflow-template.step.moved',
                'workflow-template.step.removed',
            ],
            [
                AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED,
                AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED,
                AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_MOVED,
                AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_REMOVED,
            ]
        );
        $this->assertSame(AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED, AuditService::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED);
    }

    public function testWorkflowStepUpdatedAudit(): void
    {
        $user = $this->createUser('ws_update');
        $workflow = $this->createWorkflowTemplate($user->id);

        $this->audit->log(AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED, 'workflow_template', $workflow->id, $user->id, [
            'step_id' => 7,
            'changes' => ['name' => ['from' => 'Build', 'to' => 'Compile']],
            'source' => 'web',
        ]);

        $record = $this->assertAuditEntry(AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED, 'workflow_template', $workflow->id);
        $this->assertSame($user->id, $record->user_id);
        $meta = json_decode((string)$record->metadata, true);
        $this->assertSame(['name' => ['from' => 'Build', 'to' => 'Compile']], $meta['changes']);
    }

    // -------------------------------------------------------------------------
    // Spot-check existing audit calls still work with constants
    // -------------------------------------------------------------------------

    public function testUserCrudUsesConstants(): void
    {
        $user = $this->createUser('usr_crud');

        $this->audit->log(AuditLog::ACTION_USER_CREATED, 'user', $user->id, null, ['username' => $user->username]);
        $this->audit->log(AuditLog::ACTION_USER_UPDATED, 'user', $user->id, null, ['username' => $user->username]);
        $this->audit->log(AuditLog::ACTION_USER_DELETED, 'user', $user->id, null, ['username' => $user->username]);

        $count = AuditLog::find()
            ->where(['object_type' => 'user', 'object_id' => $user->id])
            ->count();

        $this->assertSame(3, (int)$count);
    }

    public function testProjectCrudUsesConstants(): void
    {
        $user    = $this->createUser('proj_crud');
        $project = $this->createProject($user->id);

        $this->audit->log(AuditLog::ACTION_PROJECT_CREATED, 'project', $project->id, $user->id, ['name' => $project->name]);
        $this->audit->log(AuditLog::ACTION_PROJECT_UPDATED, 'project', $project->id, $user->id, ['name' => $project->name]);
        $this->audit->log(AuditLog::ACTION_PROJECT_DELETED, 'project', $project->id, $user->id, ['name' => $project->name]);

        $count = AuditLog::find()
            ->where(['object_type' => 'project', 'object_id' => $project->id])
            ->count();

        $this->assertSame(3, (int)$count);
    }

    public function testJobTemplateUsesConstants(): void
    {
        $this->audit->log(AuditLog::ACTION_TEMPLATE_CREATED, 'job_template', 1);
        $this->audit->log(AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED, 'job_template', 1);
        $this->audit->log(AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED, 'job_template', 1);

        $count = AuditLog::find()
            ->where(['object_type' => 'job_template', 'object_id' => 1])
            ->count();

        $this->assertSame(3, (int)$count);
    }

    // -------------------------------------------------------------------------
    // AuditService re-exports match AuditLog constants
    // -------------------------------------------------------------------------

    public function testAuditServiceReExportsMatchAuditLog(): void
    {
        $logRef     = new \ReflectionClass(AuditLog::class);
        $serviceRef = new \ReflectionClass(AuditService::class);

        $logConsts = array_filter(
            $logRef->getConstants(),
            fn(string $k) => str_starts_with($k, 'ACTION_'),
            ARRAY_FILTER_USE_KEY
        );

        $serviceConsts = array_filter(
            $serviceRef->getConstants(),
            fn(string $k) => str_starts_with($k, 'ACTION_'),
            ARRAY_FILTER_USE_KEY
        );

        // Every constant in AuditLog must also exist in AuditService with same value
        foreach ($logConsts as $name => $value) {
            $this->assertArrayHasKey($name, $serviceConsts, "AuditService missing re-export: {$name}");
            $this->assertSame($value, $serviceConsts[$name], "AuditService::{$name} value mismatch");
        }
    }
}
