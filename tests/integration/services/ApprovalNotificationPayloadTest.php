<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The approval.requested, approval.approved and approval.rejected
 * notifications (ApprovalEffects) say what required the request
 * (approval.required_by) and name the inventory the job was launched with
 * (job.inventory_id, job.inventory_name: JobInventory, the name as launched,
 * else the current one), every value a string, '' where there is none. The
 * approval.requested audit entry says the same with its own types:
 * required_by, and the inventory's ID or null.
 */
class ApprovalNotificationPayloadTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;
    use LaunchGateFixtures;

    private RecordingNotificationDispatcher $notifications;

    private User $launcher;

    private User $approver;

    private ApprovalRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->launcher = $this->gateApprover('payload-launcher');
        $this->approver = $this->gateApprover('payload-approver');
        $this->rule = $this->listRule((int)$this->launcher->id, [$this->approver]);
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    public function testTheRequestOfAnInventoryGatedLaunchNamesTheInventory(): void
    {
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $this->rule);
        $inventory = $this->inventoryOf($template);

        $job = $this->launcherService()->launch($template, (int)$this->launcher->id);

        $request = $this->assertWaitsFor($job, $this->rule, ApprovalRequest::REQUIRED_BY_INVENTORY);
        $this->assertSame(
            [
                'approval' => [
                    'id' => (string)$request->id,
                    'status' => ApprovalRequest::STATUS_PENDING,
                    'rule_id' => (string)$this->rule->id,
                    'rule_name' => (string)$this->rule->name,
                    'required_by' => ApprovalRequest::REQUIRED_BY_INVENTORY,
                ],
                'job' => [
                    'id' => (string)$job->id,
                    'status' => Job::STATUS_PENDING_APPROVAL,
                    'template_id' => (string)$template->id,
                    'inventory_id' => (string)$inventory->id,
                    'inventory_name' => (string)$inventory->name,
                ],
            ],
            $this->onlyPayloadOf(NotificationTemplate::EVENT_APPROVAL_REQUESTED)
        );
        $this->assertSame(
            [
                'job_id' => (int)$job->id,
                'rule_id' => (int)$this->rule->id,
                'rule_name' => (string)$this->rule->name,
                'required_by' => ApprovalRequest::REQUIRED_BY_INVENTORY,
                'inventory_id' => (int)$inventory->id,
            ],
            $this->requestedContextOf($request),
            'the audit entry, in this order'
        );
    }

    /**
     * A vote's notification names the inventory as the job was launched
     * with it, also once the inventory was renamed.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function decisionProvider(): array
    {
        return [
            'approved' => [ApprovalDecision::DECISION_APPROVED, NotificationTemplate::EVENT_APPROVAL_APPROVED, Job::STATUS_QUEUED],
            'rejected' => [ApprovalDecision::DECISION_REJECTED, NotificationTemplate::EVENT_APPROVAL_REJECTED, Job::STATUS_REJECTED],
        ];
    }

    /**
     * @dataProvider decisionProvider
     */
    public function testADecisionNamesTheInventoryAsLaunched(string $decision, string $event, string $jobStatus): void
    {
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $this->rule);
        $inventory = $this->inventoryOf($template);
        $job = $this->launcherService()->launch($template, (int)$this->launcher->id);
        $request = $this->assertWaitsFor($job, $this->rule, ApprovalRequest::REQUIRED_BY_INVENTORY);
        Inventory::updateAll(['name' => 'renamed-after-launch-' . uniqid('', true)], ['id' => $inventory->id]);

        $this->approvals()->recordDecision($request, (int)$this->approver->id, $decision);

        $this->assertSame(
            [
                'approval' => [
                    'id' => (string)$request->id,
                    'status' => $decision === ApprovalDecision::DECISION_APPROVED ? ApprovalRequest::STATUS_APPROVED : ApprovalRequest::STATUS_REJECTED,
                    'rule_id' => (string)$this->rule->id,
                    'rule_name' => (string)$this->rule->name,
                    'required_by' => ApprovalRequest::REQUIRED_BY_INVENTORY,
                ],
                'job' => [
                    'id' => (string)$job->id,
                    'status' => $jobStatus,
                    'template_id' => (string)$template->id,
                    'inventory_id' => (string)$inventory->id,
                    'inventory_name' => (string)$inventory->name,
                ],
            ],
            $this->onlyPayloadOf($event)
        );
    }

    /**
     * The placeholder job of a workflow approval step has no job template
     * and no inventory: '' in the notification, null in the audit entry.
     */
    public function testThePlaceholderOfAnApprovalStepNamesNoInventory(): void
    {
        $launcherId = (int)$this->launcher->id;
        $workflow = $this->createWorkflowTemplate($launcherId);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$this->rule->id);

        $this->workflowEngine()->launch($workflow, $launcherId);

        $payload = $this->onlyPayloadOf(NotificationTemplate::EVENT_APPROVAL_REQUESTED);
        $this->assertIsArray($payload['approval']);
        $this->assertIsArray($payload['job']);
        $this->assertSame(ApprovalRequest::REQUIRED_BY_APPROVAL_STEP, $payload['approval']['required_by'] ?? null);
        $this->assertSame(['template_id' => '', 'inventory_id' => '', 'inventory_name' => ''], array_intersect_key(
            $payload['job'],
            ['template_id' => 0, 'inventory_id' => 0, 'inventory_name' => 0]
        ));
        $request = ApprovalRequest::findOne((int)$payload['approval']['id']);
        $this->assertNotNull($request);
        $placeholder = Job::findOne($request->job_id);
        $this->assertNotNull($placeholder);
        $this->assertSame(
            [
                'job_id' => (int)$placeholder->id,
                'rule_id' => (int)$this->rule->id,
                'rule_name' => (string)$this->rule->name,
                'required_by' => ApprovalRequest::REQUIRED_BY_APPROVAL_STEP,
                'inventory_id' => null,
            ],
            $this->requestedContextOf($request)
        );
    }

    /**
     * Jobs launched before 2.12.0 recorded the inventory's ID only: the
     * notification names the inventory by its current name.
     */
    public function testAJobLaunchedBefore2120NamesTheInventoryByItsCurrentName(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        $inventory = $this->inventoryOf($template);
        $job = $this->jobWithPayload($template, ['inventory_id' => (int)$inventory->id]);

        $this->approvals()->createRequest($job, $this->rule);

        $payload = $this->onlyPayloadOf(NotificationTemplate::EVENT_APPROVAL_REQUESTED);
        $this->assertIsArray($payload['job']);
        $this->assertSame((string)$inventory->id, $payload['job']['inventory_id'] ?? null);
        $this->assertSame((string)$inventory->name, $payload['job']['inventory_name'] ?? null);
    }

    /**
     * An inventory deleted after the launch keeps the ID and the name the
     * job was launched with.
     */
    public function testAnInventoryDeletedAfterTheLaunchKeepsItsLaunchedName(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        $inventory = $this->inventoryOf($template);
        $job = $this->jobWithPayload($template, ['inventory_id' => (int)$inventory->id, Job::PAYLOAD_INVENTORY_NAME => 'Prod "web" <hosts>']);
        $this->deleteInventoryOf($template);

        $request = $this->approvals()->createRequest($job, $this->rule, ApprovalRequest::REQUIRED_BY_INVENTORY);

        $payload = $this->onlyPayloadOf(NotificationTemplate::EVENT_APPROVAL_REQUESTED);
        $this->assertIsArray($payload['job']);
        $this->assertSame((string)$inventory->id, $payload['job']['inventory_id'] ?? null);
        $this->assertSame('Prod "web" <hosts>', $payload['job']['inventory_name'] ?? null, 'as launched, raw: the channels encode it');
        $this->assertSame((int)$inventory->id, $this->requestedContextOf($request)['inventory_id'] ?? null);
    }

    /**
     * Every value of both parts is a string, so the templates render them
     * alike; the keys come in this order.
     */
    public function testEveryValueIsAString(): void
    {
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $this->rule);
        $this->launcherService()->launch($template, (int)$this->launcher->id);

        $payload = $this->onlyPayloadOf(NotificationTemplate::EVENT_APPROVAL_REQUESTED);

        $this->assertSame(['approval', 'job'], array_keys($payload));
        $this->assertIsArray($payload['approval']);
        $this->assertIsArray($payload['job']);
        $this->assertSame(['id', 'status', 'rule_id', 'rule_name', 'required_by'], array_keys($payload['approval']));
        $this->assertSame(['id', 'status', 'template_id', 'inventory_id', 'inventory_name'], array_keys($payload['job']));
        foreach ([...array_values($payload['approval']), ...array_values($payload['job'])] as $value) {
            $this->assertIsString($value);
        }
    }

    // -- Helpers ----------------------------------------------------------------

    private function inventoryOf(JobTemplate $template): Inventory
    {
        $inventory = Inventory::findOne($template->inventory_id);
        $this->assertNotNull($inventory);

        return $inventory;
    }

    /**
     * A job of $template waiting for approval whose runner payload is
     * $payload.
     *
     * @param array<string, mixed> $payload
     */
    private function jobWithPayload(JobTemplate $template, array $payload): Job
    {
        $job = $this->createJob((int)$template->id, (int)$this->launcher->id, Job::STATUS_PENDING_APPROVAL);
        $job->queued_at = null;
        $job->runner_payload = (string)json_encode($payload);
        $job->save(false);

        return $job;
    }

    /**
     * The one notification of $event.
     *
     * @return array<string, mixed>
     */
    private function onlyPayloadOf(string $event): array
    {
        $payloads = $this->notifications->payloadsOf($event);
        $this->assertCount(1, $payloads, $event);

        return $payloads[0];
    }
}
