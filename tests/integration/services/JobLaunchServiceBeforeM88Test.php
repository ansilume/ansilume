<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\ApprovalEffects;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Code that runs before m000088 (the schedule runner and the maintenance run
 * start while the app container migrates), or with a schema cached from
 * before it: the models then have no inventory.approval_rule_id and no
 * approval_request.required_by.
 *
 * - Launches behave as in 2.11.1: an inventory model without the column has
 *   no rule (JobTemplateGate reads it with getAttribute()), and the raw read
 *   of the launch lock finds no rule on an inventory without one.
 * - A stale schema cache fails closed: the model shows no rule on an
 *   inventory that has one, and the lock's raw read refuses the launch
 *   (INVENTORY_CHANGED) instead of queueing an unapproved job.
 * - The approval.requested entry and notification of a request without the
 *   column say nothing about what required it.
 *
 * ApprovalRequest without required_by is ApprovalRequestRequiredByTest's
 * (unit), and the raw reader on a table without the column is
 * JobTemplateGateSqlTest's.
 */
class JobLaunchServiceBeforeM88Test extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;
    use LaunchGateFixtures;

    private RecordingNotificationDispatcher $notifications;

    private User $launcher;

    private ApprovalRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->launcher = $this->gateApprover('m88-launcher');
        $this->rule = $this->listRule((int)$this->launcher->id, [$this->gateApprover('m88-approver')]);
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    public function testAnUngatedLaunchIsQueuedAsIn2111(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        $inventory = $this->inventoryWithoutRuleColumn((int)$template->inventory_id);
        $template->populateRelation('inventory', $inventory);

        $job = $this->launcherService()->launch($template, (int)$this->launcher->id);

        $stored = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_QUEUED, $stored->status);
        $this->assertNotNull($stored->queued_at);
        $this->assertNull(ApprovalRequest::findOne(['job_id' => $job->id]));
        $this->assertSame((int)$inventory->id, $stored->decodedRunnerPayload()['inventory_id'] ?? null);
        $this->assertSame($inventory->name, $stored->decodedRunnerPayload()[Job::PAYLOAD_INVENTORY_NAME] ?? null);
    }

    public function testATemplateWithItsOwnRuleWaitsAsIn2111(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        $template->approval_rule_id = (int)$this->rule->id;
        $template->save(false);
        $template->populateRelation('inventory', $this->inventoryWithoutRuleColumn((int)$template->inventory_id));

        $job = $this->launcherService()->launch($template, (int)$this->launcher->id);

        $this->assertWaitsFor($job, $this->rule, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE);
    }

    /**
     * A schema cached from before m000088 shows an inventory that has a rule
     * as one without: the launch's raw read of the inventory row sees the
     * rule and refuses the launch, so nothing queues unapproved.
     */
    public function testAStaleSchemaCacheFailsClosed(): void
    {
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $this->rule);
        $template->populateRelation('inventory', $this->inventoryWithoutRuleColumn((int)$template->inventory_id));

        $this->assertLaunchRefused(
            fn () => $this->launcherService()->launch($template, (int)$this->launcher->id),
            fn (): string => sprintf(JobLaunchService::INVENTORY_CHANGED, (int)$template->id, (int)$template->inventory_id),
            $this->notifications
        );
    }

    /**
     * A request without the column (before m000088) records nothing: the
     * approval.requested entry says required_by null, the notification ''.
     */
    public function testTheAnnouncementOfARequestWithoutTheColumnSaysNothingAboutWhatRequiredIt(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        $job = $this->launcherService()->launch($template, (int)$this->launcher->id);
        $request = $this->requestWithoutRequiredBy();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$this->rule->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $request->recordRequiredBy(ApprovalRequest::REQUIRED_BY_INVENTORY);
        $this->assertTrue($request->save());
        $this->assertNull($request->requiredBy());

        (new ApprovalEffects())->requested($request, $this->reloadJob($job), $this->rule);

        $entry = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_REQUESTED, 'approval_request', (int)$request->id);
        $context = $this->contextOf($entry);
        $this->assertIsArray($context);
        $this->assertArrayHasKey('required_by', $context);
        $this->assertNull($context['required_by']);
        $this->assertSame((int)$template->inventory_id, $context['inventory_id']);
        $payloads = $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_REQUESTED);
        $this->assertCount(1, $payloads);
        $this->assertSame('', $payloads[0]['approval']['required_by'] ?? null);
    }

    private function requestWithoutRequiredBy(): ApprovalRequest
    {
        return new class () extends ApprovalRequest {
            public function attributes(): array
            {
                return array_values(array_diff(parent::attributes(), ['required_by']));
            }
        };
    }
}
