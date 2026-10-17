<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\TriggerController;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\JobLaunchService;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\InventoryGatedStepFixtures;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * What POST /trigger/fire answers: 201 with the launched job's ID and its
 * status, "queued", or "pending_approval" when the job waits for the
 * approval rule of its job template or of the template's inventory; 422
 * with the reason for a launch the approval refuses (nothing is launched);
 * 400 without a token, 404 for an unknown one, and 403 when the user the
 * trigger runs as may not launch the template.
 */
class TriggerJobAnswerTest extends WebControllerTestCase
{
    use InventoryGatedStepFixtures;
    use PresentsTriggerTokens;
    use RestoresComponents;
    use TeamScopeFixtures;

    private User $user;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        // Swapped before any write, so that tearDown() restores it also when
        // a write fails.
        $this->swapComponent('notificationDispatcher', new RecordingNotificationDispatcher());
        $this->user = $this->createUserWithRole('trigger-answer-user', 'operator');
        $this->approver = $this->createUserWithRole('trigger-answer-approver', 'operator');
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: bool}> whether the template's inventory (else the template) has the rule
     */
    public static function gateProvider(): array
    {
        return [
            'the rule of the template\'s inventory' => [true],
            'the template\'s own rule' => [false],
        ];
    }

    /**
     * regression: the job trigger answered only {"job_id": N}, also for a
     * job that waits for an approval and may never run, so a caller (a CI
     * pipeline) could not tell that it waits. It answers the job's status:
     * pending_approval.
     *
     * @dataProvider gateProvider
     */
    public function testRegressionTheJobTriggerDidNotSayItsJobWaitsForApproval(bool $byInventory): void
    {
        $ruleId = (int)$this->ruleListing($this->approver)->id;
        $template = $this->templateOnGatedInventory((int)$this->user->id, $byInventory ? $ruleId : null, $byInventory ? null : $ruleId);
        $this->presentTriggerToken($template->generateTriggerToken((int)$this->user->id));

        $answer = $this->controller()->actionFire();

        $job = $this->onlyJobOf($template);
        $this->assertSame(Job::STATUS_PENDING_APPROVAL, $job->status, 'the job waits for approval');
        $request = ApprovalRequest::findOne(['job_id' => (int)$job->id]);
        $this->assertNotNull($request);
        $this->assertSame($byInventory ? 'inventory' : 'job_template', $request->requiredBy());
        $this->assertSame(201, $answer->statusCode);
        $this->assertSame(['job_id' => (int)$job->id, 'status' => 'pending_approval'], $answer->data);
    }

    /**
     * A job that waits for nothing answers queued, as JSON with the ID as a
     * number, through the controller's filters as the web application runs
     * it.
     */
    public function testAJobThatWaitsForNothingAnswersQueued(): void
    {
        $template = $this->templateOnGatedInventory((int)$this->user->id, null);
        $this->presentTriggerToken($template->generateTriggerToken((int)$this->user->id));

        $answer = $this->controller()->runAction('fire');

        $this->assertInstanceOf(Response::class, $answer);
        $job = $this->onlyJobOf($template);
        $this->assertSame(Job::STATUS_QUEUED, $job->status);
        $this->assertSame(201, $answer->statusCode);
        $this->assertSame(Response::FORMAT_JSON, $answer->format);
        $this->assertSame(['job_id' => (int)$job->id, 'status' => 'queued'], $answer->data);
        $this->assertSame('{"job_id":' . (int)$job->id . ',"status":"queued"}', json_encode($answer->data));
    }

    /**
     * A superadmin without any role whose token launches a template gated
     * through its inventory gets the job's status too.
     */
    public function testARolelessSuperadminsTriggerAnswersTheStatus(): void
    {
        $superadmin = $this->createUser('trigger-answer-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $template = $this->templateOnGatedInventory((int)$superadmin->id, (int)$this->ruleListing($this->approver)->id);
        $this->presentTriggerToken($template->generateTriggerToken((int)$superadmin->id));

        $answer = $this->controller()->actionFire();

        $job = $this->onlyJobOf($template);
        $this->assertSame((int)$superadmin->id, (int)$job->launched_by);
        $this->assertSame(201, $answer->statusCode);
        $this->assertSame(['job_id' => (int)$job->id, 'status' => 'pending_approval'], $answer->data);
    }

    /**
     * A launch that the approval refuses answers 422 with the reason, as
     * {"error": ...}: here the template's own rule differs from its
     * inventory's (JobLaunchService::RULES_DIFFER). Nothing is launched and
     * no trigger.fired is written.
     */
    public function testALaunchTheApprovalRefusesAnswers422AndLaunchesNothing(): void
    {
        $template = $this->templateOnGatedInventory(
            (int)$this->user->id,
            (int)$this->ruleListing($this->approver)->id,
            (int)$this->ruleListing($this->approver)->id
        );
        $this->presentTriggerToken($template->generateTriggerToken((int)$this->user->id));

        $answer = $this->controller()->actionFire();

        $this->assertFalse(Job::find()->where(['job_template_id' => $template->id])->exists(), 'no job is launched');
        $this->assertFalse($this->fired($template), 'no trigger.fired');
        $this->assertSame(422, $answer->statusCode);
        $this->assertSame(['error' => sprintf(JobLaunchService::RULES_DIFFER, (int)$template->id, (int)$template->inventory_id)], $answer->data);
    }

    /**
     * When the user the trigger runs as may not launch the template (no
     * job.launch), the answer is 403 without details, and nothing is
     * launched.
     */
    public function testATriggerWhoseUserMayNotLaunchIs403(): void
    {
        $viewer = $this->createUserWithRole('trigger-answer-viewer', 'viewer');
        $template = $this->templateOnGatedInventory((int)$viewer->id, (int)$this->ruleListing($this->approver)->id);
        $this->presentTriggerToken($template->generateTriggerToken((int)$viewer->id));

        $answer = $this->controller()->actionFire();

        $this->assertFalse(Job::find()->where(['job_template_id' => $template->id])->exists(), 'no job is launched');
        $this->assertSame(403, $answer->statusCode);
        $this->assertSame(['error' => 'Launch refused.'], $answer->data);
    }

    public function testACallWithoutATokenIs400(): void
    {
        $this->setPost([]);

        try {
            $this->controller()->actionFire();
            $this->fail('Expected BadRequestHttpException');
        } catch (BadRequestHttpException $e) {
            $this->assertSame([400, 'Missing trigger token.'], [$e->statusCode, $e->getMessage()]);
        }
    }

    public function testAnUnknownTokenIs404(): void
    {
        $this->presentTriggerToken('abcdef' . str_repeat('0', 58));

        try {
            $this->controller()->actionFire();
            $this->fail('Expected NotFoundHttpException');
        } catch (NotFoundHttpException $e) {
            $this->assertSame([404, 'Trigger not found.'], [$e->statusCode, $e->getMessage()]);
        }
    }

    // -- Helpers --------------------------------------------------------------

    private function controller(): TriggerController
    {
        return new TriggerController('trigger', \Yii::$app);
    }

    private function ruleListing(User $approver): ApprovalRule
    {
        return $this->createApprovalRule(
            (int)$this->user->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [(int)$approver->id]])
        );
    }

    private function onlyJobOf(JobTemplate $template): Job
    {
        /** @var list<Job> $jobs */
        $jobs = Job::find()->where(['job_template_id' => $template->id])->all();
        $this->assertCount(1, $jobs, 'the trigger launched one job');

        return $jobs[0];
    }

    private function fired(JobTemplate $template): bool
    {
        return AuditLog::find()->where([
            'action' => AuditLog::ACTION_TRIGGER_FIRED,
            'object_type' => 'job_template',
            'object_id' => $template->id,
        ])->exists();
    }
}
