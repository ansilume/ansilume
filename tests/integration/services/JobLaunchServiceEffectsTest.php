<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\User;
use app\services\ApprovalService;
use app\services\JobLaunchService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * What follows a launch's commit is best-effort: the job.launched audit
 * entry, the announcement of the job's approval request, the job.launched
 * notification. A failure among them is logged (EFFECTS_FAILED, the error's
 * class, SQLSTATE and driver code, never its message), and the launch
 * returns the job, which stands. So an exception out of launch() always
 * means that nothing was written.
 *
 * Regression: an error in the job.launched notification (reading the
 * notification templates) or in the request's announcement escaped the
 * launch after the job was committed. The caller took the launch for failed
 * while the job was queued and ran: a workflow step failed with
 * LAUNCH_FAILED next to its live job, which no step was linked to.
 */
class JobLaunchServiceEffectsTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;
    use CancellationFixtures;
    use StrandedRunFixtures;

    private RecordingNotificationDispatcher $notifications;
    private User $launcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
        $this->launcher = $this->operator('launch-effects');
    }

    protected function tearDown(): void
    {
        $this->restoreSwappedServices();
        $this->restoreNotifications();
        parent::tearDown();
    }

    public function testRegressionAFailedJobLaunchedNotificationNoLongerFailsTheLaunch(): void
    {
        $template = $this->openTemplate((int)$this->launcher->id);
        $this->swapService(
            'notificationDispatcher',
            new FailingNotificationDispatcher([NotificationTemplate::EVENT_JOB_LAUNCHED], $this->lostConnection('launch-effects-marker'))
        );

        $logged = $this->errorsLoggedBy(JobLaunchService::class, fn () => $this->launcherService()->launch($template, (int)$this->launcher->id));

        /** @var Job $job */
        $job = $logged['result'];
        $this->assertSame(Job::STATUS_QUEUED, Job::findOne($job->id)?->status, 'the job stands');
        $this->assertSame(
            [sprintf(JobLaunchService::EFFECTS_FAILED, $job->id, \yii\db\Exception::class, 'HY000', '2006')],
            $logged['errors']
        );
        $this->assertStringNotContainsString('launch-effects-marker', implode("\n", $logged['errors']));
        $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_JOB_LAUNCHED, 'job', (int)$job->id), 'audited before the notification');
    }

    public function testRegressionAFailedAnnouncementOfTheApprovalRequestNoLongerFailsTheLaunch(): void
    {
        $approver = $this->operator('launch-effects-approver');
        $template = $this->gatedTemplate((int)$this->launcher->id, $this->usersRuleOf((int)$this->launcher->id, (int)$approver->id));
        $this->swapService('approvalService', new class ($this->lostConnection()) extends ApprovalService {
            public function __construct(private \Throwable $failure)
            {
                parent::__construct();
            }

            public function announceRequest(ApprovalRequest $request, Job $job, ApprovalRule $rule): void
            {
                throw $this->failure;
            }
        });

        $logged = $this->errorsLoggedBy(JobLaunchService::class, fn () => $this->launcherService()->launch($template, (int)$this->launcher->id));

        /** @var Job $job */
        $job = $logged['result'];
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, Job::findOne($job->id)?->status);
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->requestOfJob($job)->status, 'the request stands');
        $this->assertSame(
            [sprintf(JobLaunchService::EFFECTS_FAILED, $job->id, \yii\db\Exception::class, 'HY000', '2006')],
            $logged['errors']
        );
    }

    /**
     * Without a failure nothing is logged, and the effects are as before.
     */
    public function testALaunchWithoutAFailureLogsNothing(): void
    {
        $template = $this->openTemplate((int)$this->launcher->id);

        $logged = $this->errorsLoggedBy(JobLaunchService::class, fn () => $this->launcherService()->launch($template, (int)$this->launcher->id));

        $this->assertSame([], $logged['errors']);
        /** @var Job $job */
        $job = $logged['result'];
        $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_JOB_LAUNCHED, 'job', (int)$job->id));
        $launched = $this->notifications->payloadsOf(NotificationTemplate::EVENT_JOB_LAUNCHED);
        $this->assertCount(1, $launched);
        $payload = $launched[0]['job'] ?? null;
        $this->assertIsArray($payload);
        $this->assertSame((string)$job->id, $payload['id'] ?? null);
    }

    private function launcherService(): JobLaunchService
    {
        /** @var JobLaunchService $service */
        $service = \Yii::$app->get('jobLaunchService');

        return $service;
    }
}
