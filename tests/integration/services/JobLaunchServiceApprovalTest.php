<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveyDefinition;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\ApproverEligibility;
use app\services\JobLaunchRejectedException;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\base\ModelEvent;
use yii\db\BaseActiveRecord;

/**
 * Launches of job templates that wait for an approval rule, and launches
 * racing the template's deletion (JobLaunchService):
 * - too few eligible approvers besides the launcher, or a rule that no
 *   longer exists, refuse the launch before anything is written;
 * - the launch transaction locks the template's row first, then its
 *   inventory's, and refuses a template deleted since it was loaded (or
 *   whose rule changed);
 * - the job and its pending request are written in that one transaction;
 *   approval.requested is announced after the commit.
 */
class JobLaunchServiceApprovalTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;

    private RecordingNotificationDispatcher $notifications;

    private User $launcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->launcher = $this->approverUser('launch-gated');
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    // -- A gated launch ---------------------------------------------------------------

    public function testAGatedLaunchWaitsForApprovalWithItsRequest(): void
    {
        $rule = $this->listRule((int)$this->launcher->id, [$this->launcher, $this->approverUser()]);
        $rule->timeout_minutes = 30;
        $rule->save(false);
        $template = $this->gatedTemplate($rule);
        $before = time();

        $job = $this->launcherService()->launch($template, (int)$this->launcher->id);

        $job = $this->reloadJob($job);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status);
        $this->assertNull($job->queued_at);
        $request = ApprovalRequest::findOne(['job_id' => $job->id]);
        $this->assertNotNull($request);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertSame((int)$rule->id, (int)$request->approval_rule_id);
        $this->assertGreaterThanOrEqual($before + 30 * 60, (int)$request->expires_at);

        $launched = $this->onlyAuditOf(AuditLog::ACTION_JOB_LAUNCHED, 'job', (int)$job->id);
        $requested = $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_REQUESTED, 'approval_request', (int)$request->id);
        $this->assertLessThan((int)$requested->id, (int)$launched->id, 'job.launched first, then approval.requested');
        $this->assertSame(
            [
                'job_id' => (int)$job->id,
                'rule_id' => (int)$rule->id,
                'rule_name' => (string)$rule->name,
                'required_by' => ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE,
                'inventory_id' => (int)$template->inventory_id,
            ],
            $this->contextOf($requested)
        );
        $this->assertCount(1, $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_REQUESTED));
        $this->assertSame([], $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_LAUNCHED), 'the job may never run');
    }

    public function testATemplateWithoutARuleIsQueued(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);

        $job = $this->launcherService()->launch($template, (int)$this->launcher->id);

        $this->assertSame(Job::STATUS_QUEUED, $this->reloadJob($job)->status);
        $this->assertNull(ApprovalRequest::findOne(['job_id' => $job->id]));
        $this->assertCount(1, $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_LAUNCHED));
    }

    /**
     * Regression: the approval request was created after the launch had
     * committed: a pending_approval job could exist without its request (a
     * cancel or a failure in between left it waiting for ever). Now the job
     * and the request are written in the transaction that locks the template
     * row, and the audit entries follow its commit.
     */
    public function testRegressionTheRequestWasCreatedAfterTheLaunchCommitted(): void
    {
        $template = $this->gatedTemplate($this->listRule((int)$this->launcher->id, [$this->approverUser()]));

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
        $this->assertSame($lock + 1, $inventoryLock, "the inventory's share lock is the next statement:\n" . $trace);
        $this->assertLessThan($jobInsert, $inventoryLock);
        $this->assertLessThan($requestInsert, $jobInsert);
        $this->assertLessThan($release, $requestInsert, "the request is part of the launch transaction:\n" . $trace);
        $this->assertLessThan($audit, $release, "the audit follows the commit:\n" . $trace);
    }

    /**
     * A request that cannot be written fails the launch, and the job goes
     * with the rollback: there is never a waiting job without its request.
     */
    public function testAFailingRequestTakesTheJobWithIt(): void
    {
        $template = $this->gatedTemplate($this->listRule((int)$this->launcher->id, [$this->approverUser()]));
        $jobsBefore = (int)Job::find()->count();
        $veto = static function (ModelEvent $event): void {
            $event->isValid = false;
        };
        Event::on(ApprovalRequest::class, BaseActiveRecord::EVENT_BEFORE_INSERT, $veto);
        try {
            $this->launcherService()->launch($template, (int)$this->launcher->id);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(JobLaunchRejectedException::class, $e);
            $this->assertStringStartsWith('Job launch failed: Failed to create approval request', $e->getMessage());
        } finally {
            Event::off(ApprovalRequest::class, BaseActiveRecord::EVENT_BEFORE_INSERT, $veto);
        }

        $this->assertSame($jobsBefore, (int)Job::find()->count());
        $this->assertSame([], $this->notifications->events);
    }

    // -- Refused launches -------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function tooFewProvider(): array
    {
        return [
            'only the launcher is listed' => ['launcher'],
            'the other approver may not decide' => ['viewer'],
            'the other approver is inactive' => ['inactive'],
        ];
    }

    /**
     * Regression: a launch whose rule had too few eligible approvers was
     * accepted and could never be approved. The launcher was the only
     * approver (or the only one who may decide), so the request stayed
     * pending for ever, or the launcher approved it.
     *
     * @dataProvider tooFewProvider
     */
    public function testRegressionALaunchWithTooFewEligibleApproversWasAcceptedAndCouldNeverBeApproved(string $other): void
    {
        $approvers = [$this->launcher];
        if ($other === 'viewer') {
            $approvers[] = $this->createUserWithRole('launch-viewer', 'viewer');
        }
        if ($other === 'inactive') {
            $inactive = $this->approverUser('launch-inactive');
            $inactive->status = User::STATUS_INACTIVE;
            $inactive->save(false);
            $approvers[] = $inactive;
        }
        $rule = $this->listRule((int)$this->launcher->id, $approvers);
        $template = $this->gatedTemplate($rule);

        $this->assertRefused(
            $template,
            fn (): string => sprintf(ApproverEligibility::REFUSAL_JOB, SurveyDefinition::quote((string)$rule->name), 1, 0)
        );
    }

    public function testTheLauncherDoesNotCountTowardTheRequiredApprovals(): void
    {
        $rule = $this->listRule((int)$this->launcher->id, [$this->launcher, $this->approverUser()], 2);
        $template = $this->gatedTemplate($rule);

        $this->assertRefused(
            $template,
            fn (): string => sprintf(ApproverEligibility::REFUSAL_JOB, SurveyDefinition::quote((string)$rule->name), 2, 1)
        );
    }

    /**
     * Regression: a template whose approval rule was gone launched without
     * approval: the missing rule counted as "no rule". The foreign key keeps
     * a rule in use; a template loaded before its rule was replaced and
     * deleted still carries the old ID.
     */
    public function testRegressionATemplateWhoseRuleWasGoneLaunchedWithoutApproval(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        $template->approval_rule_id = 2147483000;

        $this->assertRefused($template, fn (): string => sprintf(JobLaunchService::RULE_MISSING, (int)$template->id));
    }

    /**
     * Regression: a launch of a deleted job template was accepted. The
     * caller had loaded the template before it was deleted; the job was
     * queued, and no runner ever claims a job of a deleted template.
     */
    public function testRegressionALaunchOfADeletedJobTemplateWasAccepted(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        JobTemplate::updateAll(['deleted_at' => time()], ['id' => $template->id]);

        $this->assertRefused($template, fn (): string => sprintf(JobLaunchService::TEMPLATE_DELETED, (int)$template->id));
    }

    public function testALaunchOfATemplateThatIsGoneIsRefused(): void
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        JobTemplate::getDb()->createCommand()->delete(JobTemplate::tableName(), ['id' => $template->id])->execute();

        $this->assertRefused($template, fn (): string => sprintf(JobLaunchService::TEMPLATE_DELETED, (int)$template->id));
    }

    /**
     * A launch with the template as loaded before an admin assigned (or
     * removed) its approval rule is refused: it must neither slip past the
     * new gate nor wait for one that was removed.
     *
     * @return array<string, array{0: bool}>
     */
    public static function changedRuleProvider(): array
    {
        return [
            'a rule was assigned' => [true],
            'the rule was removed' => [false],
        ];
    }

    /**
     * @dataProvider changedRuleProvider
     */
    public function testALaunchWhoseApprovalRuleChangedMeanwhileIsRefused(bool $assigned): void
    {
        $rule = $this->listRule((int)$this->launcher->id, [$this->approverUser()]);
        $template = $assigned ? $this->voteTemplate((int)$this->launcher->id) : $this->gatedTemplate($rule);
        JobTemplate::updateAll(['approval_rule_id' => $assigned ? $rule->id : null], ['id' => $template->id]);

        $this->assertRefused($template, fn (): string => sprintf(JobLaunchService::TEMPLATE_CHANGED, (int)$template->id));
    }

    // -- Helpers ----------------------------------------------------------------------

    private function gatedTemplate(ApprovalRule $rule): JobTemplate
    {
        $template = $this->voteTemplate((int)$this->launcher->id);
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);

        return $template;
    }

    /**
     * The launch is refused with the message $message() returns (computed
     * after the launch, so this fails on the launch itself, not on a
     * constant, where the refusal is missing), unchanged (not wrapped), and
     * nothing is written: no job, no request, no audit entry, no
     * notification.
     *
     * @param callable(): string $message
     */
    private function assertRefused(JobTemplate $template, callable $message): void
    {
        $jobs = (int)Job::find()->count();
        $requests = (int)ApprovalRequest::find()->count();
        $audits = (int)AuditLog::find()->where(['action' => [AuditLog::ACTION_JOB_LAUNCHED, AuditLog::ACTION_APPROVAL_REQUESTED]])->count();

        try {
            $this->launcherService()->launch($template, (int)$this->launcher->id);
            $this->fail('expected a JobLaunchRejectedException');
        } catch (JobLaunchRejectedException $e) {
            $this->assertSame($message(), $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        $this->assertSame($jobs, (int)Job::find()->count());
        $this->assertSame($requests, (int)ApprovalRequest::find()->count());
        $this->assertSame(
            $audits,
            (int)AuditLog::find()->where(['action' => [AuditLog::ACTION_JOB_LAUNCHED, AuditLog::ACTION_APPROVAL_REQUESTED]])->count()
        );
        $this->assertSame([], $this->notifications->events);
    }

    private function launcherService(): JobLaunchService
    {
        /** @var JobLaunchService $service */
        $service = \Yii::$app->get('jobLaunchService');

        return $service;
    }
}
