<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\SchedulesController;
use app\controllers\ScheduleController;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalService;
use app\services\ScheduleRunAs;
use app\services\ScheduleService;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Response;

/**
 * Regression: editing another user's schedule let the editor approve its
 * job.
 *
 * The owner's schedule launches a job template that an approval rule gates;
 * the editor and another approver are the rule's approvers, and one approval
 * is needed. The editor changes what or when the schedule runs (the web
 * form, PUT /api/v1/schedules/{id}) or enables it (the web toggle,
 * POST /api/v1/schedules/{id}/toggle). The schedule kept running as its
 * owner, so its job named the owner as launcher, and the editor approved
 * what the editor alone had chosen. Now the schedule runs as the editor: the
 * editor's vote is refused (LAUNCHER_REFUSED), and the other approver
 * decides. A rename, a disable and a save that changes nothing keep the
 * owner.
 */
class ScheduleRunAsApprovalTest extends WebControllerTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;

    private User $owner;
    private User $editor;
    private User $approver;
    private JobTemplate $template;
    private Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swapComponent('notificationDispatcher', new RecordingNotificationDispatcher());

        $this->owner = $this->createUserWithRole('run-as-owner', 'operator');
        $this->editor = $this->createUserWithRole('run-as-editor', 'operator');
        $this->approver = $this->createUserWithRole('run-as-approver', 'operator');
        $ownerId = (int)$this->owner->id;
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );
        $rule = $this->createApprovalRule(
            $ownerId,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [(int)$this->editor->id, (int)$this->approver->id]])
        );
        $this->template->approval_rule_id = (int)$rule->id;
        $this->template->save(false);

        $this->schedule = new Schedule();
        $this->schedule->name = 'run-as-' . uniqid('', true);
        $this->schedule->job_template_id = (int)$this->template->id;
        $this->schedule->cron_expression = '0 2 * * *';
        $this->schedule->timezone = 'UTC';
        $this->schedule->extra_vars = '{"chosen_by": "owner"}';
        $this->schedule->enabled = true;
        $this->schedule->created_by = $ownerId;
        $this->schedule->computeNextRunAt();
        $this->schedule->save(false);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    // -- The editor arms the schedule: it runs as the editor --------------------

    public function testTheEditorWhoChangesWhenItRunsInTheFormLaunchesItsJobAndMayNotApproveIt(): void
    {
        $this->loginAs($this->editor);
        $this->setPost(['Schedule' => ['cron_expression' => '* * * * *'] + $this->formAsShown()]);

        $result = $this->webController()->actionUpdate((int)$this->schedule->id);

        $this->assertInstanceOf(Response::class, $result, 'saved');
        $this->assertTheEditorLaunchesAndAnotherApproverDecides();
        $this->assertRunAsAudited(AuditLog::ACTION_SCHEDULE_UPDATED);
    }

    public function testTheEditorWhoChangesTheExtraVarsThroughTheApiLaunchesItsJobAndMayNotApproveIt(): void
    {
        $this->loginAs($this->editor);
        $this->setBody(['extra_vars' => '{"chosen_by": "editor"}']);

        $result = $this->apiController()->actionUpdate((int)$this->schedule->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame((int)$this->editor->id, $this->dataOf($result)['created_by']);
        $job = $this->assertTheEditorLaunchesAndAnotherApproverDecides();
        $this->assertSame(['chosen_by' => 'editor'], json_decode((string)$job->extra_vars, true));
        $this->assertRunAsAudited(AuditLog::ACTION_SCHEDULE_UPDATED);
    }

    public function testTheEditorWhoEnablesTheScheduleWithTheWebToggleLaunchesItsJobAndMayNotApproveIt(): void
    {
        $this->disableAsOwner();
        $this->loginAs($this->editor);

        $this->webController()->actionToggle((int)$this->schedule->id);

        /** @var \yii\web\Session $session */
        $session = \Yii::$app->session;
        $this->assertSame(['success' => "Schedule \"{$this->schedule->name}\" enabled."], $session->getAllFlashes());
        $this->assertTheEditorLaunchesAndAnotherApproverDecides();
        $this->assertRunAsAudited(AuditLog::ACTION_SCHEDULE_TOGGLED);
    }

    public function testTheEditorWhoEnablesTheScheduleWithTheApiToggleLaunchesItsJobAndMayNotApproveIt(): void
    {
        $this->disableAsOwner();
        $this->loginAs($this->editor);

        $result = $this->apiController()->actionToggle((int)$this->schedule->id);

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertTrue($this->dataOf($result)['enabled']);
        $this->assertSame((int)$this->editor->id, $this->dataOf($result)['created_by']);
        $this->assertTheEditorLaunchesAndAnotherApproverDecides();
        $this->assertRunAsAudited(AuditLog::ACTION_SCHEDULE_TOGGLED);
    }

    // -- Changes that do not arm the schedule keep the owner ---------------------

    public function testARenameADisableAndAnUnchangedSaveKeepTheOwner(): void
    {
        $id = (int)$this->schedule->id;
        $this->loginAs($this->editor);

        $this->setPost(['Schedule' => ['name' => $this->schedule->name . '-renamed'] + $this->formAsShown()]);
        $this->assertInstanceOf(Response::class, $this->webController()->actionUpdate($id), 'renamed in the form');
        $this->assertRunsAs($this->owner, 'a rename in the form');

        $this->setPost(['Schedule' => $this->formAsShown()]);
        $this->assertInstanceOf(Response::class, $this->webController()->actionUpdate($id), 'saved unchanged');
        $this->assertRunsAs($this->owner, 'a form saved unchanged');

        $this->setBody(['name' => $this->schedule->name . '-renamed-again']);
        $this->dataOf($this->apiController()->actionUpdate($id));
        $this->assertRunsAs($this->owner, 'a rename through the API');

        $this->setBody($this->dataOf($this->apiController()->actionView($id)));
        $this->dataOf($this->apiController()->actionUpdate($id));
        $this->assertRunsAs($this->owner, 'the API resource sent back unchanged');

        $this->webController()->actionToggle($id);
        $this->assertRunsAs($this->owner, 'a disable with the web toggle');
        $this->assertFalse((bool)$this->storedSchedule()->enabled);

        $this->enableAsOwner();
        $this->dataOf($this->apiController()->actionToggle($id));
        $this->assertRunsAs($this->owner, 'a disable with the API toggle');
        $this->assertFalse((bool)$this->storedSchedule()->enabled);

        foreach ([AuditLog::ACTION_SCHEDULE_UPDATED, AuditLog::ACTION_SCHEDULE_TOGGLED] as $action) {
            foreach ($this->auditContexts($action) as $context) {
                $this->assertArrayNotHasKey(ScheduleRunAs::AUDIT_KEY, $context, $action);
            }
        }
    }

    /**
     * The owner's job, launched after the editor changed nothing that decides
     * what or when it runs: the editor is an approver like any other.
     */
    public function testAfterAnUnchangedSaveTheOwnerLaunchesAndTheEditorMayApprove(): void
    {
        $this->loginAs($this->editor);
        $this->setPost(['Schedule' => $this->formAsShown()]);
        $this->webController()->actionUpdate((int)$this->schedule->id);

        $job = $this->runTheScheduleNow();

        $this->assertSame((int)$this->owner->id, (int)$job->launched_by);
        $this->vote($job, $this->editor);
        $this->assertSame(Job::STATUS_QUEUED, $this->storedJob($job)->status);
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * After the change: the schedule runs as the editor, its job names the
     * editor as launcher and waits for approval, the editor's vote is
     * refused, and the other approver's vote is recorded and queues the job.
     */
    private function assertTheEditorLaunchesAndAnotherApproverDecides(): Job
    {
        $job = $this->runTheScheduleNow();

        $this->assertSame((int)$this->editor->id, (int)$job->launched_by, 'the job names the editor as launcher');
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status);
        $refusal = $this->voteRefusal($job, $this->editor);
        $this->assertNotNull($refusal, 'the editor may not approve the job');
        $this->assertSame(ApprovalDecisionRefusedException::REASON_LAUNCHER, $refusal->reason());
        $this->assertSame(ApprovalDecisionRefusedException::LAUNCHER_REFUSED, $refusal->getMessage());
        $this->assertSame(403, $refusal->httpStatus());

        $this->vote($job, $this->approver);
        $request = ApprovalRequest::findOne(['job_id' => $job->id]);
        $this->assertNotNull($request);
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status);
        $this->assertSame(Job::STATUS_QUEUED, $this->storedJob($job)->status);
        $this->assertRunsAs($this->editor, 'the schedule runs as the editor');

        return $job;
    }

    private function assertRunAsAudited(string $action): void
    {
        $contexts = $this->auditContexts($action);
        $this->assertCount(1, $contexts, $action);
        $this->assertSame(
            ['from' => (int)$this->owner->id, 'to' => (int)$this->editor->id],
            $contexts[0][ScheduleRunAs::AUDIT_KEY] ?? null
        );
    }

    private function assertRunsAs(User $user, string $message): void
    {
        $this->assertSame((int)$user->id, (int)$this->storedSchedule()->created_by, $message);
    }

    /**
     * Make the schedule due and run the due schedules; the job it launched.
     */
    private function runTheScheduleNow(): Job
    {
        Schedule::updateAll(['next_run_at' => time() - 60], ['id' => $this->schedule->id]);
        /** @var ScheduleService $service */
        $service = \Yii::$app->get('scheduleService');
        $service->runDue();

        /** @var list<Job> $jobs */
        $jobs = Job::find()->where(['job_template_id' => $this->template->id])->all();
        $this->assertCount(1, $jobs, 'the schedule launched one job');

        return $jobs[0];
    }

    private function vote(Job $job, User $voter): void
    {
        $this->assertNull($this->voteRefusal($job, $voter), 'the vote is recorded');
    }

    private function voteRefusal(Job $job, User $voter): ?ApprovalDecisionRefusedException
    {
        $request = ApprovalRequest::findOne(['job_id' => $job->id]);
        $this->assertNotNull($request, 'the job waits for an approval request');
        /** @var ApprovalService $approvals */
        $approvals = \Yii::$app->get('approvalService');
        try {
            $approvals->recordDecision($request, (int)$voter->id, ApprovalDecision::DECISION_APPROVED);
        } catch (ApprovalDecisionRefusedException $e) {
            return $e;
        }

        return null;
    }

    /**
     * The edit form's fields as it shows them for the stored schedule.
     *
     * @return array<string, string>
     */
    private function formAsShown(): array
    {
        $stored = $this->storedSchedule();

        return [
            'name' => $stored->name,
            'job_template_id' => (string)$stored->job_template_id,
            'cron_expression' => $stored->cron_expression,
            'timezone' => $stored->timezone,
            'extra_vars' => (string)$stored->extra_vars,
            'enabled' => (bool)$stored->enabled ? '1' : '0',
        ];
    }

    private function disableAsOwner(): void
    {
        Schedule::updateAll(['enabled' => 0, 'next_run_at' => null], ['id' => $this->schedule->id]);
    }

    private function enableAsOwner(): void
    {
        Schedule::updateAll(['enabled' => 1], ['id' => $this->schedule->id]);
    }

    private function storedSchedule(): Schedule
    {
        $stored = Schedule::findOne($this->schedule->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    private function storedJob(Job $job): Job
    {
        $stored = Job::findOne($job->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    /**
     * The contexts of the schedule's audit entries of $action, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function auditContexts(string $action): array
    {
        $contexts = [];
        foreach (AuditLog::find()->where(['action' => $action, 'object_id' => $this->schedule->id])->orderBy(['id' => SORT_ASC])->all() as $entry) {
            $context = json_decode((string)$entry->metadata, true);
            $this->assertIsArray($context);
            $contexts[] = $context;
        }

        return $contexts;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function dataOf(array $result): array
    {
        $data = $result['data'] ?? null;
        $this->assertIsArray($data, (string)json_encode($result));

        return $data;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
        \Yii::$app->response->statusCode = 200;
    }

    private function apiController(): SchedulesController
    {
        return new SchedulesController('api/v1/schedules', $this->app());
    }

    private function webController(): ScheduleController
    {
        return new class ('schedule', $this->app()) extends ScheduleController {
            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            /**
             * @param array<int|string, mixed>|string $url
             */
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }

    private function app(): \yii\base\Module
    {
        $app = \Yii::$app;
        $this->assertNotNull($app);

        return $app;
    }
}
