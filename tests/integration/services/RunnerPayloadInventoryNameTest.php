<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\RunnerGroup;
use app\models\User;
use app\services\JobClaimService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The stored launch record (job.runner_payload) names the inventory the job
 * was launched with, its ID and, since 2.12.0, its name
 * (Job::PAYLOAD_INVENTORY_NAME), so the job page, the approval requests and
 * their notifications name it as launched. The payload a runner gets when it
 * claims the job (JobClaimService::buildExecutionPayload()) keeps its keys:
 * runners of every version read it, and none of them knows the name.
 */
class RunnerPayloadInventoryNameTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;
    use LaunchGateFixtures;

    /** The keys of the stored launch record, in their order. */
    private const STORED_KEYS = [
        'template_id', 'template_name', 'project_id', 'inventory_id', 'inventory_name', 'credential_id',
        'credential_ids', 'credential_snapshot', 'playbook', 'extra_vars', 'limit', 'verbosity', 'forks',
        'become', 'become_method', 'become_user', 'tags', 'skip_tags', 'check_mode', 'timeout_minutes',
    ];

    /** The keys of the claim payload of a job without password answers, as 2.11.1 sent them. */
    private const CLAIM_KEYS = [
        'job_id', 'project_path', 'playbook_path', 'scm_type', 'scm_url', 'scm_branch', 'scm_credential',
        'inventory_type', 'inventory_content', 'inventory_path', 'extra_vars', 'limit', 'verbosity', 'forks',
        'become', 'become_method', 'become_user', 'tags', 'skip_tags', 'check_mode', 'timeout_minutes',
        'credential', 'credentials', 'vault_password_source', 'command',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordNotifications();
        $this->user = $this->gateApprover('payload-name');
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    public function testTheStoredPayloadNamesTheInventoryRightAfterItsId(): void
    {
        $template = $this->voteTemplate((int)$this->user->id);
        $inventory = $this->inventoryOf($template);

        $payload = $this->launched($template)->decodedRunnerPayload();

        $this->assertSame(self::STORED_KEYS, array_keys($payload));
        $this->assertSame((int)$inventory->id, $payload['inventory_id']);
        $this->assertSame($inventory->name, $payload[Job::PAYLOAD_INVENTORY_NAME]);
    }

    /**
     * The name is stored as named, quotes, markup and letters outside ASCII
     * included (the pages encode it), and stays when the inventory is
     * renamed afterwards.
     */
    public function testTheNameIsStoredAsNamedAndOutlivesARename(): void
    {
        $template = $this->voteTemplate((int)$this->user->id);
        $name = 'Prod "web" <hosts> ' . "\u{00FC}ber-" . uniqid('', true);
        Inventory::updateAll(['name' => $name], ['id' => $template->inventory_id]);
        $job = $this->launched($template);

        Inventory::updateAll(['name' => 'renamed-' . uniqid('', true)], ['id' => $template->inventory_id]);

        $stored = Job::findOne($job->id);
        $this->assertNotNull($stored);
        $this->assertSame($name, $stored->decodedRunnerPayload()[Job::PAYLOAD_INVENTORY_NAME] ?? null);
        $this->assertStringContainsString("\u{00FC}ber-", (string)$stored->runner_payload, 'stored unescaped, as the rest of the payload');
    }

    /**
     * Regression guard for old runner images: the claim payload of a job
     * launched with the name has exactly the keys 2.11.1 sent, and no name.
     */
    public function testTheClaimPayloadKeepsItsKeys(): void
    {
        $template = $this->voteTemplate((int)$this->user->id);
        $job = $this->launched($template);

        $payload = $this->claims()->buildExecutionPayload($job);

        $this->assertSame(self::CLAIM_KEYS, array_keys($payload));
        $this->assertArrayNotHasKey(Job::PAYLOAD_INVENTORY_NAME, $payload);
        $this->assertSame((string)$this->inventoryOf($template)->content, $payload['inventory_content']);
    }

    /**
     * The same through a runner's claim of the next job of its group.
     */
    public function testTheClaimOfTheNextJobKeepsItsKeys(): void
    {
        $template = $this->voteTemplate((int)$this->user->id);
        $runner = $this->createRunner((int)$template->runner_group_id, (int)$this->user->id);
        $job = $this->launched($template);
        $group = RunnerGroup::findOne($template->runner_group_id);
        $this->assertNotNull($group);

        $payload = $this->claims()->claimNextPayload($group, $runner);

        $this->assertIsArray($payload);
        $this->assertSame((int)$job->id, (int)$payload['job_id']);
        $this->assertSame(self::CLAIM_KEYS, array_keys($payload));
    }

    // -- Helpers ----------------------------------------------------------------

    private function launched(JobTemplate $template): Job
    {
        $job = $this->launcherService()->launch($template, (int)$this->user->id);
        $this->assertSame(Job::STATUS_QUEUED, $job->status);

        return $job;
    }

    private function inventoryOf(JobTemplate $template): Inventory
    {
        $inventory = Inventory::findOne($template->inventory_id);
        $this->assertNotNull($inventory);

        return $inventory;
    }

    private function claims(): JobClaimService
    {
        /** @var JobClaimService $service */
        $service = \Yii::$app->get('jobClaimService');

        return $service;
    }
}
