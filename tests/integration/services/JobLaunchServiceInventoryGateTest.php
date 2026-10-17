<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveyDefinition;
use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\TeamProject;
use app\models\User;
use app\services\ApproverEligibility;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Launches of job templates whose inventory has an approval rule
 * (JobLaunchService with JobTemplateGate): the job waits for the
 * inventory's rule like for a rule of the template's own, the request says
 * what required it, and a launch that meets two different rules, a rule
 * that is gone or too few eligible approvers is refused before anything is
 * written. The approvers are judged by the job template's project, also for
 * the rule of its inventory.
 */
class JobLaunchServiceInventoryGateTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;
    use LaunchGateFixtures;

    private RecordingNotificationDispatcher $notifications;

    private User $launcher;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->launcher = $this->gateApprover('inv-gate-launcher');
        $this->approver = $this->gateApprover('inv-gate-approver');
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    // -- Launches that wait ---------------------------------------------------------

    /**
     * regression: a job template without a rule of its own launched a queued
     * job although its inventory had an approval rule: the launch read only
     * the template's rule, so a runner ran the gated hosts unapproved.
     */
    public function testRegressionALaunchOfATemplateWhoseInventoryHasARuleWasQueuedWithoutApproval(): void
    {
        $rule = $this->listRule((int)$this->launcher->id, [$this->launcher, $this->approver]);
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $rule);

        $job = $this->launcherService()->launch($template, (int)$this->launcher->id);

        $request = $this->assertWaitsFor($job, $rule, ApprovalRequest::REQUIRED_BY_INVENTORY);
        $this->assertSame(
            [
                'job_id' => (int)$job->id,
                'rule_id' => (int)$rule->id,
                'rule_name' => (string)$rule->name,
                'required_by' => ApprovalRequest::REQUIRED_BY_INVENTORY,
                'inventory_id' => (int)$template->inventory_id,
            ],
            $this->requestedContextOf($request)
        );
        $this->assertSame((int)$this->launcher->id, (int)$this->onlyAuditOf(AuditLog::ACTION_APPROVAL_REQUESTED, 'approval_request', (int)$request->id)->user_id);
        $this->assertCount(1, $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_REQUESTED));
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_LAUNCHED), 'the job may never run');
    }

    /**
     * A template with the same rule as its inventory waits for that one
     * rule, once, recorded as the template's own: removing the template's
     * rule would leave the inventory's.
     */
    public function testTheSameRuleOnTheTemplateAndItsInventoryIsRequiredByTheJobTemplate(): void
    {
        $rule = $this->listRule((int)$this->launcher->id, [$this->approver]);
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $rule);
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);

        $job = $this->launcherService()->launch($template, (int)$this->launcher->id);

        $request = $this->assertWaitsFor($job, $rule, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE);
        $this->assertSame(1, (int)ApprovalRequest::find()->where(['job_id' => $job->id])->count(), 'one request per job');
        $this->assertSame(ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, $this->requestedContextOf($request)['required_by'] ?? null);
    }

    public function testACheckModeLaunchWaitsForTheInventorysRuleToo(): void
    {
        $rule = $this->listRule((int)$this->launcher->id, [$this->approver]);
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $rule);

        $job = $this->launcherService()->launch($template, (int)$this->launcher->id, ['check_mode' => 1]);

        $this->assertSame(1, (int)$job->check_mode);
        $this->assertWaitsFor($job, $rule, ApprovalRequest::REQUIRED_BY_INVENTORY);
    }

    /**
     * A template whose inventory has no rule is queued as before, and its
     * runner payload names the inventory as launched.
     */
    public function testATemplateOnAnInventoryWithoutARuleIsQueued(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);

        $job = $this->launcherService()->launch($template, (int)$this->launcher->id);

        $stored = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_QUEUED, $stored->status);
        $this->assertNull(ApprovalRequest::findOne(['job_id' => $job->id]));
        $inventory = Inventory::findOne($template->inventory_id);
        $this->assertNotNull($inventory);
        $this->assertSame((int)$inventory->id, $stored->decodedRunnerPayload()['inventory_id'] ?? null);
        $this->assertSame($inventory->name, $stored->decodedRunnerPayload()[Job::PAYLOAD_INVENTORY_NAME] ?? null);
        $this->assertCount(1, $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_LAUNCHED));
    }

    // -- Refused launches -----------------------------------------------------------

    /**
     * A launch waits for one rule only: a template whose own rule differs
     * from its inventory's is refused, with nothing written.
     */
    public function testTwoDifferentRulesRefuseTheLaunch(): void
    {
        $inventoryRule = $this->listRule((int)$this->launcher->id, [$this->approver]);
        $ownRule = $this->listRule((int)$this->launcher->id, [$this->approver]);
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $inventoryRule);
        $template->approval_rule_id = (int)$ownRule->id;
        $template->save(false);

        $this->assertLaunchRefused(
            fn () => $this->launcherService()->launch($template, (int)$this->launcher->id),
            fn (): string => sprintf(JobLaunchService::RULES_DIFFER, (int)$template->id, (int)$template->inventory_id),
            $this->notifications
        );
    }

    /**
     * An inventory loaded before its rule was replaced and deleted still
     * carries the old ID (the foreign key keeps a rule in use): the launch
     * fails closed instead of counting the missing rule as none.
     */
    public function testAnInventoryRuleThatIsGoneRefusesTheLaunch(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        /** @var Inventory $inventory */
        $inventory = $template->inventory;
        $inventory->setAttribute('approval_rule_id', 2147483000);

        $this->assertLaunchRefused(
            fn () => $this->launcherService()->launch($template, (int)$this->launcher->id),
            fn (): string => sprintf(JobLaunchService::INVENTORY_RULE_MISSING, (int)$template->id, (int)$template->inventory_id),
            $this->notifications
        );
    }

    /**
     * Too few eligible approvers besides the launcher refuse a launch for
     * the rule of the inventory as for the template's own rule.
     */
    public function testTooFewEligibleApproversOfTheInventorysRuleRefuseTheLaunch(): void
    {
        $rule = $this->listRule((int)$this->launcher->id, [$this->launcher]);
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $rule);

        $this->assertLaunchRefused(
            fn () => $this->launcherService()->launch($template, (int)$this->launcher->id),
            fn (): string => sprintf(ApproverEligibility::REFUSAL_JOB, SurveyDefinition::quote((string)$rule->name), 1, 0),
            $this->notifications
        );
    }

    /**
     * The approvers of an inventory's rule are judged by the job template's
     * project: an approver who may not see it does not count (the
     * inventory, here without a project, is no audience of its own). Once
     * the approver's team has the project, the launch waits.
     */
    public function testTheApproversOfTheInventorysRuleAreJudgedByTheTemplatesProject(): void
    {
        $rule = $this->listRule((int)$this->launcher->id, [$this->approver]);
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $rule);
        $team = $this->createTeam((int)$this->launcher->id);
        $this->addTeamMember((int)$team->id, (int)$this->launcher->id);
        $this->createTeamProject((int)$team->id, (int)$template->project_id, TeamProject::ROLE_OPERATOR);

        $this->assertLaunchRefused(
            fn () => $this->launcherService()->launch($template, (int)$this->launcher->id),
            fn (): string => sprintf(ApproverEligibility::REFUSAL_JOB, SurveyDefinition::quote((string)$rule->name), 1, 0),
            $this->notifications
        );

        $this->addTeamMember((int)$team->id, (int)$this->approver->id);
        $job = $this->launcherService()->launch($template, (int)$this->launcher->id);

        $this->assertWaitsFor($job, $rule, ApprovalRequest::REQUIRED_BY_INVENTORY);
    }

    // -- The launch transaction -------------------------------------------------------

    /**
     * The launch locks the template row first, then the inventory row it
     * reads (one statement, LOCK IN SHARE MODE), then writes the job and its
     * request, and announces the request after the commit.
     */
    public function testAnInventoryGatedLaunchLocksTheTemplateThenItsInventoryThenWrites(): void
    {
        $rule = $this->listRule((int)$this->launcher->id, [$this->approver]);
        $template = $this->inventoryGatedTemplate((int)$this->launcher->id, $rule);

        $statements = $this->statementsOf(fn () => $this->launcherService()->launch($template, (int)$this->launcher->id));

        $trace = implode("\n", $statements);
        $savepoint = $this->statementIndex($statements, '~^SAVEPOINT LEVEL\d+$~');
        $lock = $this->statementIndex(
            $statements,
            '~^SELECT `id`, `deleted_at`, `approval_rule_id`, `inventory_id` FROM `job_template` WHERE `id` = ' . $template->id . ' FOR UPDATE$~'
        );
        $inventoryLock = $this->statementIndex(
            $statements,
            '~^SELECT \* FROM `inventory` WHERE `id` IN \(' . $template->inventory_id . '\) ORDER BY `id` LOCK IN SHARE MODE$~'
        );
        $jobInsert = $this->statementIndex($statements, '~^INSERT INTO `job`~');
        $requestInsert = $this->statementIndex($statements, '~^INSERT INTO `approval_request`~');
        $release = $this->statementIndex($statements, '~^RELEASE SAVEPOINT LEVEL\d+$~');
        $audit = $this->statementIndex($statements, '~^INSERT INTO `audit_log`~');
        $this->assertSame($savepoint + 1, $lock, "the template lock opens the transaction:\n" . $trace);
        $this->assertSame($lock + 1, $inventoryLock, "the inventory's share lock follows it:\n" . $trace);
        $this->assertTrue($inventoryLock < $jobInsert && $jobInsert < $requestInsert && $requestInsert < $release, $trace);
        $this->assertLessThan($audit, $release, "the audit follows the commit:\n" . $trace);
        $this->assertSame(1, preg_match_all('~LOCK IN SHARE MODE~', $trace), 'one share lock, on the inventory');
    }

    /**
     * An ungated launch takes the same locks: the inventory may get a rule
     * meanwhile, and the lock is where the launch sees it.
     */
    public function testAnUngatedLaunchSharesTheInventoryLockToo(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);

        $statements = $this->statementsOf(fn () => $this->launcherService()->launch($template, (int)$this->launcher->id));

        $lock = $this->statementIndex($statements, '~^SELECT `id`, `deleted_at`, `approval_rule_id`, `inventory_id` FROM `job_template`~');
        $inventoryLock = $this->statementIndex($statements, '~^SELECT \* FROM `inventory` WHERE `id` IN \(' . $template->inventory_id . '\) ORDER BY `id` LOCK IN SHARE MODE$~');
        $this->assertSame($lock + 1, $inventoryLock, implode("\n", $statements));
        $this->assertLessThan($this->statementIndex($statements, '~^INSERT INTO `job`~'), $inventoryLock);
    }
}
