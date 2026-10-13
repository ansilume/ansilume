<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\api\v1\JobTemplatesController;
use app\controllers\JobTemplateController;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\ApprovalService;
use app\services\GatedJobTemplateLock;
use app\services\LintService;
use app\services\ScheduleService;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Regression: an operator could change a gated job template and approve its
 * job.
 *
 * The owner's schedule launches a job template that an approval rule gates;
 * the editor and another approver are the rule's approvers, and one approval
 * is needed. The editor, an operator, changed the template's extra vars (the
 * web form, PUT /api/v1/job-templates/{id}): the change was saved, the
 * owner's schedule launched the job as the owner, and the editor approved
 * what the editor alone had chosen. Now only admins (approval-rule.update)
 * change what a gated template runs: the editor's change is refused with
 * 403 and nothing is saved, so the job runs what the owner chose.
 */
class GatedJobTemplateApprovalTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private const OWNER_VARS = '{"chosen_by": "owner"}';
    private const EDITOR_VARS = '{"chosen_by": "editor"}';

    private User $owner;
    private User $editor;
    private JobTemplate $template;
    private Schedule $schedule;

    /** @var array<string, mixed> component definitions the test replaced */
    private array $replaced = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->replace('notificationDispatcher', new RecordingNotificationDispatcher());
        $this->replace('lintService', new class () extends LintService {
            public function runForTemplate(JobTemplate $template): void
            {
            }
        });

        $this->owner = $this->createUserWithRole('gated-owner', 'operator');
        $this->editor = $this->createUserWithRole('gated-editor', 'operator');
        $approver = $this->createUserWithRole('gated-approver', 'operator');
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
            (string)json_encode(['user_ids' => [(int)$this->editor->id, (int)$approver->id]])
        );
        JobTemplate::updateAll(['approval_rule_id' => $rule->id, 'extra_vars' => self::OWNER_VARS], ['id' => $this->template->id]);

        $this->schedule = new Schedule();
        $this->schedule->name = 'gated-' . uniqid('', true);
        $this->schedule->job_template_id = (int)$this->template->id;
        $this->schedule->cron_expression = '0 2 * * *';
        $this->schedule->timezone = 'UTC';
        $this->schedule->enabled = true;
        $this->schedule->created_by = $ownerId;
        $this->schedule->computeNextRunAt();
        $this->schedule->save(false);
    }

    protected function tearDown(): void
    {
        foreach ($this->replaced as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->replaced = [];
        parent::tearDown();
    }

    public function testRegressionAnOperatorCouldChangeAGatedTemplateThroughTheFormAndApproveItsJob(): void
    {
        $this->loginAs($this->editor);
        // The locked form posts name and description only: the change is a crafted post.
        $this->setPost([
            'JobTemplate' => ['name' => $this->template->name, 'description' => '', 'extra_vars' => self::EDITOR_VARS],
            JobTemplateController::KEEP_CREDENTIALS => '1',
        ]);

        $refusal = null;
        try {
            $this->webController()->actionUpdate((int)$this->template->id);
        } catch (ForbiddenHttpException $e) {
            $refusal = $e->getMessage();
        }

        $this->assertTheJobRunsWhatTheOwnerChose();
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED, $refusal, 'the change is refused with 403');
    }

    public function testRegressionAnOperatorCouldChangeAGatedTemplateThroughTheApiAndApproveItsJob(): void
    {
        $this->loginAs($this->editor);
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['extra_vars' => self::EDITOR_VARS]);

        $result = (new JobTemplatesController('api/v1/job-templates', $this->app()))->actionUpdate((int)$this->template->id);
        $status = \Yii::$app->response->statusCode;

        $this->assertTheJobRunsWhatTheOwnerChose();
        $this->assertSame(403, $status);
        $this->assertSame(['error' => ['message' => GatedJobTemplateLock::CHANGE_REFUSED]], $result);
    }

    /**
     * The template keeps the owner's extra vars, the owner's schedule
     * launches its job as the owner with them, and the editor, who chose
     * nothing, approves it like any approver.
     */
    private function assertTheJobRunsWhatTheOwnerChose(): void
    {
        $job = $this->runTheScheduleNow();

        $this->assertSame((int)$this->owner->id, (int)$job->launched_by, 'the job names the owner as launcher');
        $this->assertSame(['chosen_by' => 'owner'], json_decode((string)$job->extra_vars, true), 'the job runs what the owner chose');
        $stored = JobTemplate::findOne($this->template->id);
        $this->assertNotNull($stored);
        $this->assertSame(self::OWNER_VARS, $stored->extra_vars, 'nothing is saved');

        $request = ApprovalRequest::findOne(['job_id' => $job->id]);
        $this->assertNotNull($request, 'the job waits for approval');
        /** @var ApprovalService $approvals */
        $approvals = \Yii::$app->get('approvalService');
        $approvals->recordDecision($request, (int)$this->editor->id, ApprovalDecision::DECISION_APPROVED);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status);
    }

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

    private function replace(string $id, object $replacement): void
    {
        $this->replaced[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        \Yii::$app->set($id, $replacement);
    }

    private function webController(): JobTemplateController
    {
        return new class ('job-template', $this->app()) extends JobTemplateController {
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
