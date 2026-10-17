<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\services\JobClaimRefusedException;
use app\services\JobLaunchService;
use app\services\JobTemplateGate;
use app\tests\integration\DbTestCase;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\services\JobClaimFixtures;
use app\tests\integration\services\LaunchGateFixtures;
use app\tests\integration\TeamScopeFixtures;
use app\tests\unit\docs\InventoryApprovalDocsContractTest;
use app\tests\unit\docs\ReadsOperatorDocs;

/**
 * The read-only queries of docs/updating.md's section for the update from
 * 2.11.1 (approval rules for inventories) run on the schema of this
 * release, in the test's transaction, and list what their text says, as the
 * claim and the launch decide: what the first and the fourth query list, a
 * runner's claim refuses, and nothing else of the same jobs; the second
 * lists the waiting jobs that the claim fails for their inventory; what the
 * fifth lists, the launch refuses because two approval rules meet. The
 * others list the job templates on an inventory with what launches them,
 * the inventories whose creator differs from their audit entry, the static
 * inventories of exactly 65,535 bytes, the changes to gated job templates
 * and to what they use, and the job templates gated only through their
 * inventory.
 *
 * Jobs are written as a launch records them (JobClaimFixtures), and the
 * runner of the fixtures claims until no job of a test is queued.
 *
 * Regression (2.12.0): the docs had none of these queries, so no operator
 * could find the jobs that the update fails before they run.
 */
class InventoryApprovalQueriesDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;
    use TeamScopeFixtures;
    use JobClaimFixtures;
    use LaunchGateFixtures;
    use CancellationFixtures;
    use GatedChangesFixtures;

    /** The placeholder of the queries on one inventory. */
    private const INVENTORY_ID = '<inventory id>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaimFixtures();
    }

    protected function tearDown(): void
    {
        $this->restoreClaimServices();
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * The section has exactly these nine ```sql blocks, in this order, each a
     * read-only SELECT that runs on the schema of this release.
     */
    public function testEverySqlBlockOfTheSectionIsOneOfTheNineReadOnlyQueries(): void
    {
        $this->assertCount(count(InventoryApprovalDocsContractTest::QUERIES), $this->blocks());
        foreach (array_keys(InventoryApprovalDocsContractTest::QUERIES) as $index) {
            $sql = $this->query($index + 1, (int)$this->inventory->id);
            $statement = trim((string)preg_replace('/^\s*--.*$/m', '', $sql));
            $this->assertSame(1, preg_match('/^SELECT\b/', $statement), "Query {$index} is a SELECT.");
            $this->assertStringNotContainsString(';', $statement, 'One statement.');
            $this->assertIsArray(\Yii::$app->db->createCommand($sql)->queryAll());
        }
        $placeholders = array_map(
            static fn (string $sql): int => substr_count((string)preg_replace('/^\s*--.*$/m', '', $sql), self::INVENTORY_ID),
            $this->blocks()
        );
        $this->assertSame([0, 0, 1, 2, 0, 0, 0, 0, 0], $placeholders, 'Only the queries on one inventory take its ID.');
    }

    /**
     * The first query lists the queued jobs of job templates with an
     * approval rule of their own that were never approved: exactly the jobs
     * of such templates that a runner's claim refuses (not_approved). A job
     * approved by the votes or by a timeout that approves runs, so does a
     * job of a template without a rule; a job that waits for approval is
     * not queued, and a job of a deleted job template is never handed out.
     */
    public function testTheFirstQueryListsTheQueuedJobsThatTheClaimRefuses(): void
    {
        $rule = $this->approvalRule();
        $gated = $this->templateOn($this->inventory);
        $this->giveTemplateRule($gated, $rule);
        $never = $this->launchedJob($gated);
        $pending = $this->launchedJob($gated);
        $this->approvalRequest($pending, $rule, ApprovalRequest::STATUS_PENDING);
        $rejected = $this->launchedJob($gated);
        $this->approvalRequest($rejected, $rule, ApprovalRequest::STATUS_REJECTED);
        $approved = $this->launchedJob($gated);
        $this->approvalRequest($approved, $rule, ApprovalRequest::STATUS_APPROVED);
        $timedOut = $this->launchedJob($gated);
        $this->approvalRequest($timedOut, $rule, ApprovalRequest::STATUS_TIMED_OUT);
        $open = $this->launchedJob($this->template);
        $waiting = $this->launchedJob($gated, Job::STATUS_PENDING_APPROVAL);
        $this->approvalRequest($waiting, $rule, ApprovalRequest::STATUS_PENDING);
        $deletedTemplate = $this->templateOn($this->inventory);
        $this->giveTemplateRule($deletedTemplate, $rule);
        $ofDeleted = $this->launchedJob($deletedTemplate);
        $this->assertTrue($deletedTemplate->softDelete());
        $jobs = [$never, $pending, $rejected, $approved, $timedOut, $open, $waiting, $ofDeleted];

        $listed = array_keys($this->rowsOf(1, 'job_id', self::idsOf($jobs)));
        $claimed = $this->claimAll($jobs);

        $this->assertSame(self::idsOf([$never, $pending, $rejected]), $listed);
        $this->assertSame(array_fill_keys($listed, JobClaimRefusedException::REASON_NOT_APPROVED), $claimed['refused'], 'The claim refuses what the query lists.');
        $this->assertSame(self::idsOf([$approved, $timedOut, $open]), $claimed['ran']);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $this->reloadJob($waiting)->status);
        $this->assertSame(Job::STATUS_QUEUED, $this->reloadJob($ofDeleted)->status, 'No runner gets a job of a deleted job template.');
        // What the audit log shows for a refused job, as the docs say.
        $this->assertSame([AuditLog::ACTION_JOB_STARTED, AuditLog::ACTION_JOB_FINISHED], $this->auditActionsOf($never));
        $finished = $this->auditContextOf($never);
        $this->assertSame('pre_execution_failure', $finished['reason'] ?? null);
        $this->assertSame(ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, $finished['required_by'] ?? null);
        $this->assertSame((int)$rule->id, $finished['approval_rule_id'] ?? null);
    }

    /**
     * The second query lists the jobs that wait for approval, to be queued
     * or for a runner, whose inventory is gone or names no hosts: exactly
     * those of the queued ones that the claim fails for their inventory. A
     * dynamic inventory without a source path still runs; a finished job is
     * not listed.
     */
    public function testTheSecondQueryListsTheWaitingJobsThatTheClaimFailsForTheirInventory(): void
    {
        $userId = (int)$this->launcher->id;
        $gone = $this->createInventory($userId);
        $null = $this->inventoryOf(Inventory::TYPE_STATIC, null, null);
        $blank = $this->inventoryOf(Inventory::TYPE_STATIC, " \n\t", null);
        $noSource = $this->inventoryOf(Inventory::TYPE_FILE, null, null);
        $blankSource = $this->inventoryOf(Inventory::TYPE_FILE, null, '  ');
        $dynamic = $this->inventoryOf(Inventory::TYPE_DYNAMIC, null, null);
        $jobs = [
            'gone' => $this->launchedJob($this->template, Job::STATUS_QUEUED, $gone),
            'not recorded' => $this->jobWithPayload($this->template, Job::STATUS_QUEUED, ['template_id' => (int)$this->template->id, 'playbook' => 'site.yml']),
            'no content' => $this->launchedJob($this->template, Job::STATUS_QUEUED, $null),
            'blank' => $this->launchedJob($this->template, Job::STATUS_QUEUED, $blank),
            'no source' => $this->launchedJob($this->template, Job::STATUS_QUEUED, $noSource),
            'blank source' => $this->launchedJob($this->template, Job::STATUS_QUEUED, $blankSource),
            'pending' => $this->launchedJob($this->template, Job::STATUS_PENDING, $null),
            'waiting for approval' => $this->launchedJob($this->template, Job::STATUS_PENDING_APPROVAL, $null),
            'finished' => $this->launchedJob($this->template, Job::STATUS_FAILED, $null),
            'dynamic' => $this->launchedJob($this->template, Job::STATUS_QUEUED, $dynamic),
            'hosts' => $this->launchedJob($this->template),
        ];
        $this->assertSame(1, Inventory::deleteAll(['id' => $gone->id]));

        $listed = array_keys($this->rowsOf(2, 'job_id', self::idsOf(array_values($jobs))));
        $claimed = $this->claimAll(array_values($jobs));

        $waiting = ['gone', 'not recorded', 'no content', 'blank', 'no source', 'blank source', 'pending', 'waiting for approval'];
        $this->assertSame(self::idsOf(array_values(array_intersect_key($jobs, array_flip($waiting)))), $listed);
        $this->assertSame([
            (int)$jobs['gone']->id => JobClaimRefusedException::REASON_INVENTORY_MISSING,
            (int)$jobs['not recorded']->id => JobClaimRefusedException::REASON_INVENTORY_NOT_RECORDED,
            (int)$jobs['no content']->id => JobClaimRefusedException::REASON_INVENTORY_EMPTY,
            (int)$jobs['blank']->id => JobClaimRefusedException::REASON_INVENTORY_EMPTY,
            (int)$jobs['no source']->id => JobClaimRefusedException::REASON_INVENTORY_NO_SOURCE,
            (int)$jobs['blank source']->id => JobClaimRefusedException::REASON_INVENTORY_NO_SOURCE,
        ], $claimed['refused'], 'The claim fails the queued jobs the query lists.');
        $this->assertSame(self::idsOf([$jobs['dynamic'], $jobs['hosts']]), $claimed['ran'], 'A dynamic inventory without a source path still runs.');
    }

    /**
     * The third query lists the live job templates on the inventory, each
     * with its own rule, its enabled schedules and the users they run as,
     * the user its trigger runs as, and the live workflows with a job step
     * for it.
     */
    public function testTheThirdQueryListsTheTemplatesOnTheInventoryWithWhatLaunchesThem(): void
    {
        $userId = (int)$this->launcher->id;
        $inventory = $this->createInventory($userId);
        $rule = $this->approvalRule();
        $owned = $this->templateOn($inventory);
        $this->giveTemplateRule($owned, $rule);
        $tokenUser = (int)$this->createUser('docs-q3-token')->id;
        JobTemplate::updateAll(['trigger_token' => hash('sha256', uniqid('docs-q3-', true)), 'trigger_token_created_by' => $tokenUser], ['id' => $owned->id]);
        $runsAs = [(int)$this->createUser('docs-q3-a')->id, (int)$this->createUser('docs-q3-b')->id];
        $enabled = [$this->schedule($owned, $runsAs[1], true), $this->schedule($owned, $runsAs[0], true)];
        $this->schedule($owned, (int)$this->createUser('docs-q3-off')->id, false);
        $workflow = $this->createWorkflowTemplate($userId);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$owned->id);
        $deletedWorkflow = $this->createWorkflowTemplate($userId);
        $this->createWorkflowStep((int)$deletedWorkflow->id, 1, WorkflowStep::TYPE_JOB, (int)$owned->id);
        $this->assertTrue($deletedWorkflow->softDelete());
        $plain = $this->templateOn($inventory);
        $olderToken = $this->templateOn($inventory);
        JobTemplate::updateAll(['trigger_token' => hash('sha256', uniqid('docs-q3-old-', true)), 'trigger_token_created_by' => null], ['id' => $olderToken->id]);
        $deleted = $this->templateOn($inventory);
        $this->assertTrue($deleted->softDelete());
        $elsewhere = $this->templateOn($this->inventory);

        $rows = $this->rowsOf(3, 'job_template_id', self::idsOf([$owned, $plain, $olderToken, $deleted, $elsewhere]), (int)$inventory->id);

        $this->assertSame(self::idsOf([$owned, $plain, $olderToken]), array_keys($rows));
        $row = $rows[(int)$owned->id];
        $this->assertSame((int)$rule->id, (int)$row['own_rule_id']);
        $this->assertSame((string)$rule->name, $row['own_rule']);
        sort($enabled);
        sort($runsAs);
        $this->assertSame(implode(',', $enabled), (string)$row['enabled_schedules']);
        $this->assertSame(implode(',', $runsAs), (string)$row['schedules_run_as']);
        $this->assertSame($tokenUser, (int)$row['trigger_runs_as']);
        $this->assertSame((string)$workflow->id, (string)$row['workflows'], 'Only the live workflow.');
        $this->assertNull($rows[(int)$plain->id]['own_rule_id']);
        $this->assertNull($rows[(int)$plain->id]['enabled_schedules']);
        $this->assertNull($rows[(int)$plain->id]['trigger_runs_as'], 'No token, no trigger.');
        $this->assertNull($rows[(int)$plain->id]['workflows']);
        $this->assertSame($olderToken->getTriggerUserId(), (int)$rows[(int)$olderToken->id]['trigger_runs_as'], 'An older token runs as the creator, as the code says.');
    }

    /**
     * The fourth query lists the queued jobs on the inventory, by the one
     * they were launched with or the one their template uses now, that were
     * never approved: once the inventory has a rule, the claim refuses
     * exactly these, and runs the approved ones and those of other
     * inventories.
     */
    public function testTheFourthQueryListsTheQueuedJobsThatTheClaimRefusesOnceTheInventoryHasARule(): void
    {
        $userId = (int)$this->launcher->id;
        $inventory = $this->createInventory($userId);
        $other = $this->createInventory($userId);
        $onIt = $this->templateOn($inventory);
        $onOther = $this->templateOn($other);
        $rule = $this->approvalRule();
        $launchedWithIt = $this->launchedJob($onOther, Job::STATUS_QUEUED, $inventory);
        $repointedToIt = $this->launchedJob($onIt, Job::STATUS_QUEUED, $other);
        $approved = $this->launchedJob($onIt);
        $this->approvalRequest($approved, $rule, ApprovalRequest::STATUS_APPROVED, ApprovalRequest::REQUIRED_BY_INVENTORY);
        $timedOut = $this->launchedJob($onIt);
        $this->approvalRequest($timedOut, $rule, ApprovalRequest::STATUS_TIMED_OUT, ApprovalRequest::REQUIRED_BY_INVENTORY);
        $waiting = $this->launchedJob($onIt, Job::STATUS_PENDING_APPROVAL);
        $elsewhere = $this->launchedJob($onOther);
        $jobs = [$launchedWithIt, $repointedToIt, $approved, $timedOut, $waiting, $elsewhere];

        $listed = array_keys($this->rowsOf(4, 'job_id', self::idsOf($jobs), (int)$inventory->id));
        $this->giveInventoryRule($inventory, $rule);
        $claimed = $this->claimAll($jobs);

        $this->assertSame(self::idsOf([$launchedWithIt, $repointedToIt]), $listed);
        $this->assertSame(array_fill_keys($listed, JobClaimRefusedException::REASON_NOT_APPROVED), $claimed['refused'], 'The claim refuses what the query lists.');
        $this->assertSame(self::idsOf([$approved, $timedOut, $elsewhere]), $claimed['ran']);
        foreach ([$launchedWithIt, $repointedToIt] as $job) {
            $context = $this->auditContextOf($job);
            $this->assertSame(ApprovalRequest::REQUIRED_BY_INVENTORY, $context['required_by'] ?? null);
            $this->assertSame((int)$inventory->id, $context['inventory_id'] ?? null);
        }
    }

    /**
     * The fifth query lists the live job templates whose own approval rule
     * differs from the rule of their inventory, and the launch refuses each
     * of them; a template with the same rule as its inventory, one whose
     * inventory has no rule, and a deleted one are not listed.
     */
    public function testTheFifthQueryListsTheTemplatesWhoseLaunchTheTwoRulesRefuse(): void
    {
        $userId = (int)$this->launcher->id;
        $ownRule = $this->approvalRule();
        $inventoryRule = $this->approvalRule();
        $gatedInventory = $this->createInventory($userId);
        $this->setInventoryRule((int)$gatedInventory->id, (int)$inventoryRule->id);
        $differ = $this->templateOn($gatedInventory);
        $this->giveTemplateRule($differ, $ownRule);
        $same = $this->templateOn($gatedInventory);
        $this->giveTemplateRule($same, $inventoryRule);
        $ownOnly = $this->templateOn($this->inventory);
        $this->giveTemplateRule($ownOnly, $ownRule);
        $deleted = $this->templateOn($gatedInventory);
        $this->giveTemplateRule($deleted, $ownRule);
        $this->assertTrue($deleted->softDelete());

        $rows = $this->rowsOf(5, 'job_template_id', self::idsOf([$differ, $same, $ownOnly, $deleted]));

        $this->assertSame([(int)$differ->id], array_keys($rows));
        $this->assertSame((int)$ownRule->id, (int)$rows[(int)$differ->id]['own_rule_id']);
        $this->assertSame((int)$inventoryRule->id, (int)$rows[(int)$differ->id]['inventory_rule_id']);
        $this->assertSame((int)$gatedInventory->id, (int)$rows[(int)$differ->id]['inventory_id']);
        $this->assertLaunchRefused(
            fn () => $this->launcherService()->launch($differ, $userId),
            static fn (): string => sprintf(JobLaunchService::RULES_DIFFER, $differ->id, $gatedInventory->id),
            $this->recordNotifications()
        );
        $this->assertFalse(JobTemplateGate::of($same)->rulesDiffer(), 'The same rule on both meets no second rule.');
    }

    /**
     * The sixth query lists the inventories whose creator differs from the
     * user of their inventory.created audit entry; not those of the same
     * user, entries without a user, or other actions.
     */
    public function testTheSixthQueryListsTheInventoriesWhoseCreatorDiffersFromTheirAudit(): void
    {
        $creator = (int)$this->launcher->id;
        $other = (int)$this->createUser('docs-q6-other')->id;
        $differs = $this->createInventory($creator);
        $this->audit(AuditLog::ACTION_INVENTORY_CREATED, 'inventory', (int)$differs->id, $other);
        $same = $this->createInventory($creator);
        $this->audit(AuditLog::ACTION_INVENTORY_CREATED, 'inventory', (int)$same->id, $creator);
        $console = $this->createInventory($creator);
        \Yii::$app->db->createCommand()->insert('{{%audit_log}}', [
            'action' => AuditLog::ACTION_INVENTORY_CREATED,
            'object_type' => 'inventory',
            'object_id' => (int)$console->id,
            'user_id' => null,
            'created_at' => time(),
        ])->execute();
        $updated = $this->createInventory($creator);
        $this->audit(AuditLog::ACTION_INVENTORY_UPDATED, 'inventory', (int)$updated->id, $other);

        $rows = $this->rowsOf(6, 'inventory_id', self::idsOf([$differs, $same, $console, $updated]));

        $this->assertSame([(int)$differs->id], array_keys($rows));
        $this->assertSame($creator, (int)$rows[(int)$differs->id]['created_by']);
        $this->assertSame($other, (int)$rows[(int)$differs->id]['created_by_audit']);
    }

    /**
     * The seventh query lists the static inventories whose content has
     * exactly 65,535 bytes, the most a TEXT column held; not shorter or
     * longer content, nor another type.
     */
    public function testTheSeventhQueryListsTheStaticInventoriesOfExactlyTheBytesTextHeld(): void
    {
        $exact = $this->inventoryOf(Inventory::TYPE_STATIC, str_repeat('a', 65535), null);
        $shorter = $this->inventoryOf(Inventory::TYPE_STATIC, str_repeat('a', 65534), null);
        $longer = $this->inventoryOf(Inventory::TYPE_STATIC, str_repeat('a', 70000), null);
        $file = $this->inventoryOf(Inventory::TYPE_FILE, str_repeat('a', 65535), 'hosts.ini');
        // 10,000 two-byte characters and 45,535 one-byte ones: 65,535 bytes, 55,535 characters.
        $multibyte = $this->inventoryOf(Inventory::TYPE_STATIC, str_repeat('ä', 10000) . str_repeat('a', 45535), null);

        $rows = $this->rowsOf(7, 'inventory_id', self::idsOf([$exact, $shorter, $longer, $file, $multibyte]));

        $this->assertSame(self::idsOf([$exact, $multibyte]), array_keys($rows), 'Bytes, not characters.');
        $this->assertSame(65535, (int)$rows[(int)$exact->id]['bytes']);
    }

    /**
     * The eighth query lists, newest first, the changes of the last 30 days
     * to the job templates gated by their own rule or by their inventory's,
     * and to the projects, inventories and credentials they use; not the
     * changes of what only templates without a rule use, other actions, or
     * older changes (GatedChangesFixtures, as for the fourteenth query of
     * 2.10.0, which leaves out the job templates gated by their inventory).
     */
    public function testTheEighthQueryListsTheChangesToGatedTemplatesAndWhatTheyUse(): void
    {
        $user = (int)$this->launcher->id;
        $other = (int)$this->createUser('docs-q8-other')->id;
        $byInventory = $this->cancelFixtureTemplate($user);
        $this->gateInventoryOf($byInventory, $this->approvalRule());
        $byOwnRule = $this->cancelFixtureTemplate($user);
        $this->giveTemplateRule($byOwnRule, $this->approvalRule());
        foreach ([$byInventory, $byOwnRule] as $gated) {
            $changes = $this->gatedChanges($gated, $user, $other);

            $rows = $this->rowsOf(8, 'id', [...$changes['listed'], ...$changes['not_listed']]);

            $this->assertListsTheGatedChanges($rows, $gated, $changes, $other);
            $this->assertSame('inventory', $rows[$changes['listed'][2]]['object_type']);
        }
    }

    /**
     * The ninth query lists the live job templates that wait for approval
     * only through their inventory, which 2.11.1 would launch without one;
     * not those with a rule of their own, those on an inventory without a
     * rule, or deleted ones.
     */
    public function testTheNinthQueryListsTheTemplatesGatedOnlyThroughTheirInventory(): void
    {
        $userId = (int)$this->launcher->id;
        $rule = $this->approvalRule();
        $gatedInventory = $this->createInventory($userId);
        $this->setInventoryRule((int)$gatedInventory->id, (int)$rule->id);
        $only = $this->templateOn($gatedInventory);
        $both = $this->templateOn($gatedInventory);
        $this->giveTemplateRule($both, $rule);
        $ungated = $this->templateOn($this->inventory);
        $deleted = $this->templateOn($gatedInventory);
        $this->assertTrue($deleted->softDelete());

        $rows = $this->rowsOf(9, 'job_template_id', self::idsOf([$only, $both, $ungated, $deleted]));

        $this->assertSame([(int)$only->id], array_keys($rows));
        $this->assertSame((int)$rule->id, (int)$rows[(int)$only->id]['approval_rule_id']);
        $this->assertSame((string)$rule->name, $rows[(int)$only->id]['approval_rule']);
        $this->assertSame(JobTemplateGate::SOURCE_INVENTORY, JobTemplateGate::of($only)->source());
        $this->assertSame(JobTemplateGate::SOURCE_JOB_TEMPLATE, JobTemplateGate::of($both)->source());
    }

    /**
     * The ```sql blocks of the section, in order.
     *
     * @return list<string>
     */
    private function blocks(): array
    {
        preg_match_all('/^```sql\R(.*?)^```/ms', $this->section('docs/updating.md', InventoryApprovalDocsContractTest::UPDATE_SECTION), $blocks);

        return $blocks[1];
    }

    /**
     * Query $number of the section (1 for the first), as the docs show it,
     * with $inventoryId in place of its placeholder.
     */
    private function query(int $number, int $inventoryId = 0): string
    {
        $sql = $this->blocks()[$number - 1] ?? null;
        $this->assertIsString($sql, "The section has a query {$number}.");
        $this->assertStringStartsWith(InventoryApprovalDocsContractTest::QUERIES[$number - 1] . "\n", $sql);

        return str_replace(self::INVENTORY_ID, (string)$inventoryId, rtrim(trim($sql), ';'));
    }

    /**
     * The rows query $number returns on the test database whose $column is
     * one of $ids, by that column, in the query's order.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function rowsOf(int $number, string $column, array $ids, int $inventoryId = 0): array
    {
        $rows = [];
        foreach (\Yii::$app->db->createCommand($this->query($number, $inventoryId))->queryAll() as $row) {
            $id = (int)$row[$column];
            if (in_array($id, $ids, true)) {
                $rows[$id] = $row;
            }
        }

        return $rows;
    }

    /**
     * The runner of the fixtures claims until none of $jobs is queued any
     * more (a job of a deleted job template stays queued): the jobs it ran,
     * and those the claim refused, each with its claim_refusal, in the
     * order of their IDs.
     *
     * @param list<Job> $jobs
     * @return array{ran: list<int>, refused: array<int, string>}
     */
    private function claimAll(array $jobs): array
    {
        $ids = self::idsOf($jobs);
        for ($round = 0; $round < 2 * count($ids) && Job::find()->where(['id' => $ids, 'status' => Job::STATUS_QUEUED])->exists(); $round++) {
            $this->claimNext();
        }
        $claimed = ['ran' => [], 'refused' => []];
        foreach ($jobs as $job) {
            $status = $this->reloadJob($job)->status;
            if ($status === Job::STATUS_RUNNING) {
                $claimed['ran'][] = (int)$job->id;
            } elseif ($status === Job::STATUS_FAILED && in_array(AuditLog::ACTION_JOB_STARTED, $this->auditActionsOf($job), true)) {
                $claimed['refused'][(int)$job->id] = (string)($this->auditContextOf($job)['claim_refusal'] ?? '');
            }
        }

        return $claimed;
    }

    /**
     * An inventory of $type with $content and $sourcePath, as stored.
     */
    private function inventoryOf(string $type, ?string $content, ?string $sourcePath): Inventory
    {
        $inventory = $this->createInventory((int)$this->launcher->id);
        $inventory->inventory_type = $type;
        $inventory->content = $content;
        $inventory->source_path = $sourcePath;
        $this->assertTrue($inventory->save(false));

        return $inventory;
    }

    /**
     * A schedule of $template that runs as $runsAs; its ID.
     */
    private function schedule(JobTemplate $template, int $runsAs, bool $enabled): int
    {
        \Yii::$app->db->createCommand()->insert('{{%schedule}}', [
            'name' => 'docs-q3-' . uniqid('', true),
            'job_template_id' => (int)$template->id,
            'cron_expression' => '0 0 1 1 *',
            'timezone' => 'UTC',
            'enabled' => $enabled ? 1 : 0,
            'created_by' => $runsAs,
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();

        return (int)\Yii::$app->db->getLastInsertID();
    }

    /**
     * @param list<\yii\db\ActiveRecord> $records
     * @return list<int>
     */
    private static function idsOf(array $records): array
    {
        return array_map(static fn (\yii\db\ActiveRecord $record): int => (int)$record->getPrimaryKey(), $records);
    }
}
