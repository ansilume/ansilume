<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Launches racing a change of what gates the job template
 * (JobLaunchService): the launch transaction locks the template's row, then
 * its inventory's row, and compares them with the gate of the template as
 * the caller loaded it. A template that uses another inventory since it was
 * loaded, an inventory whose approval rule changed meanwhile, or an
 * inventory that is gone refuses the launch with nothing written, so no job
 * slips past a rule given to its inventory, nor waits for one that was
 * removed, nor runs on hosts other than the template's.
 *
 * The changes "during the launch" are made in newJobRecord(), which runs
 * after the gate was read and before the launch transaction starts.
 */
class JobLaunchServiceGateRaceTest extends DbTestCase
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
        $this->launcher = $this->gateApprover('race-launcher');
        $this->rule = $this->listRule((int)$this->launcher->id, [$this->gateApprover('race-approver')]);
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function repointedProvider(): array
    {
        return [
            'to an inventory with a rule' => [true],
            'to an inventory without a rule' => [false],
        ];
    }

    /**
     * regression: a job template loaded before it was pointed at another
     * inventory (a schedule loads its template up front) launched with the
     * inventory it had then: the job ran on the old hosts, and an approval
     * rule of the new inventory was never asked.
     *
     * @dataProvider repointedProvider
     */
    public function testRegressionATemplateRepointedAfterItWasLoadedLaunchedWithTheOldInventory(bool $gated): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        $loaded = JobTemplate::findOne($template->id);
        $this->assertNotNull($loaded);
        $this->assertNotNull($loaded->inventory, 'loaded with its inventory, as a schedule run loads it');
        $other = $this->createInventory((int)$this->launcher->id);
        if ($gated) {
            $this->setInventoryRule((int)$other->id, (int)$this->rule->id);
        }
        JobTemplate::updateAll(['inventory_id' => $other->id], ['id' => $template->id]);

        $this->assertLaunchRefused(
            fn () => $this->launcherService()->launch($loaded, (int)$this->launcher->id),
            fn (): string => sprintf(JobLaunchService::TEMPLATE_INVENTORY_CHANGED, (int)$template->id),
            $this->notifications
        );
    }

    /**
     * regression: an approval rule given to the template's inventory after
     * the launch had read the template, but before the job was written, let
     * the job queue without approval.
     */
    public function testRegressionARuleGivenToTheInventoryDuringTheLaunchLetTheJobQueue(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        $service = $this->serviceThatChangesTheInventoryRule((int)$template->inventory_id, (int)$this->rule->id);

        $this->assertLaunchRefused(
            fn () => $service->launch($template, (int)$this->launcher->id),
            fn (): string => sprintf(JobLaunchService::INVENTORY_CHANGED, (int)$template->id, (int)$template->inventory_id),
            $this->notifications
        );
    }

    /**
     * A rule removed from the inventory, or replaced, during the launch: the
     * job must not wait for a rule that no longer gates it, nor for the old
     * one.
     *
     * @return array<string, array{0: bool}>
     */
    public static function changedRuleProvider(): array
    {
        return [
            'the rule was removed' => [false],
            'the rule was replaced' => [true],
        ];
    }

    /**
     * @dataProvider changedRuleProvider
     */
    public function testARuleRemovedOrReplacedOnTheInventoryDuringTheLaunchIsRefused(bool $replaced): void
    {
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $this->rule);
        $other = $this->listRule((int)$this->launcher->id, [$this->gateApprover('race-other')]);
        $service = $this->serviceThatChangesTheInventoryRule((int)$template->inventory_id, $replaced ? (int)$other->id : null);

        $this->assertLaunchRefused(
            fn () => $service->launch($template, (int)$this->launcher->id),
            fn (): string => sprintf(JobLaunchService::INVENTORY_CHANGED, (int)$template->id, (int)$template->inventory_id),
            $this->notifications
        );
    }

    public function testATemplateRepointedDuringTheLaunchIsRefused(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        $other = $this->createInventory((int)$this->launcher->id);
        $service = $this->serviceThatRuns(static function () use ($template, $other): void {
            JobTemplate::updateAll(['inventory_id' => $other->id], ['id' => $template->id]);
        });

        $this->assertLaunchRefused(
            fn () => $service->launch($template, (int)$this->launcher->id),
            fn (): string => sprintf(JobLaunchService::TEMPLATE_INVENTORY_CHANGED, (int)$template->id),
            $this->notifications
        );
    }

    /**
     * An inventory deleted meanwhile (the foreign key restricts it; here
     * with the checks off) refuses the launch: a runner would not know the
     * job's hosts.
     */
    public function testAnInventoryDeletedDuringTheLaunchIsRefused(): void
    {
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $this->rule);
        $service = $this->serviceThatRuns(fn () => $this->deleteInventoryOf($template));

        $this->assertLaunchRefused(
            fn () => $service->launch($template, (int)$this->launcher->id),
            fn (): string => sprintf(JobLaunchService::INVENTORY_MISSING, (int)$template->id, (int)$template->inventory_id),
            $this->notifications
        );
    }

    /**
     * A template whose inventory row is gone before it is loaded has no
     * inventory to read a rule from: the lock finds no row and refuses.
     */
    public function testATemplateWhoseInventoryIsGoneIsRefused(): void
    {
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $this->rule);
        $this->deleteInventoryOf($template);
        $loaded = JobTemplate::findOne($template->id);
        $this->assertNotNull($loaded);
        $this->assertNull($loaded->inventory);

        $this->assertLaunchRefused(
            fn () => $this->launcherService()->launch($loaded, (int)$this->launcher->id),
            fn (): string => sprintf(JobLaunchService::INVENTORY_MISSING, (int)$template->id, (int)$template->inventory_id),
            $this->notifications
        );
    }

    /**
     * The template's own rule assigned during the launch is still refused
     * as before (TEMPLATE_CHANGED), and comes before the inventory checks.
     */
    public function testTheTemplatesOwnRuleChangingDuringTheLaunchIsCheckedFirst(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        $other = $this->createInventory((int)$this->launcher->id);
        $rule = $this->rule;
        $service = $this->serviceThatRuns(static function () use ($template, $other, $rule): void {
            JobTemplate::updateAll(['approval_rule_id' => $rule->id, 'inventory_id' => $other->id], ['id' => $template->id]);
        });

        $this->assertLaunchRefused(
            fn () => $service->launch($template, (int)$this->launcher->id),
            fn (): string => sprintf(JobLaunchService::TEMPLATE_CHANGED, (int)$template->id),
            $this->notifications
        );
    }

    /**
     * A rename of the inventory meanwhile changes nothing that gates the
     * job: it waits as launched.
     */
    public function testARenameOfTheInventoryDuringTheLaunchDoesNotRefuseIt(): void
    {
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $this->rule);
        $service = $this->serviceThatRuns(static function () use ($template): void {
            Inventory::updateAll(['name' => 'renamed-' . uniqid('', true)], ['id' => $template->inventory_id]);
        });

        $job = $service->launch($template, (int)$this->launcher->id);

        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $this->reloadJob($job)->status);
    }

    // -- Helpers ----------------------------------------------------------------

    private function serviceThatChangesTheInventoryRule(int $inventoryId, ?int $ruleId): JobLaunchService
    {
        return $this->serviceThatRuns(fn () => $this->setInventoryRule($inventoryId, $ruleId));
    }

    /**
     * A launch service that runs $change once the gate was read, before the
     * launch transaction locks anything: the job record hook.
     */
    private function serviceThatRuns(callable $change): JobLaunchService
    {
        return new class ($change) extends JobLaunchService {
            /** @var callable */
            private $change;

            public function __construct(callable $change)
            {
                parent::__construct();
                $this->change = $change;
            }

            protected function newJobRecord(): Job
            {
                ($this->change)();

                return parent::newJobRecord();
            }
        };
    }
}
