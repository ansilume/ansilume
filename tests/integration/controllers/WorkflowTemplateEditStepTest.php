<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\WorkflowStepCommand;
use app\components\WorkflowStepPosition;
use app\controllers\WorkflowTemplateController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeSummary;
use app\services\WorkflowStepFormView;
use app\services\WorkflowStepReorderService;
use app\services\WorkflowStepsTable;
use app\services\WorkflowStepWriteService;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The page that edits a workflow step: GET and POST
 * /workflow-template/edit-step?id=W&step=S.
 *
 * regression: a step could not be edited. Changing its name, target or
 * branches meant removing it and adding it again: it got a new ID, the
 * branches of other steps that led to it had to be removed first (a step
 * that other steps route to is not removed), and its runs named a removed
 * step. An edit keeps the step's ID, so the branches that lead to it and
 * its run history stay.
 */
class WorkflowTemplateEditStepTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;

    /** @var array<string, mixed> */
    private array $swapped = [];

    private WorkflowTemplate $workflow;
    private WorkflowStep $build;
    private WorkflowStep $test;
    private WorkflowStep $deploy;

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->session->removeAllFlashes();
        $this->s = $this->teamScope();
        // Build (On Failure → Deploy), Test, Deploy: job steps of the member's team.
        $this->workflow = $this->createWorkflowTemplate($this->s['admin']->id);
        $this->build = $this->step('Build', 10);
        $this->test = $this->step('Test', 20);
        $this->deploy = $this->step('Deploy', 30);
        $this->build->on_failure_step_id = (int)$this->deploy->id;
        $this->build->save(false);
    }

    protected function tearDown(): void
    {
        foreach ($this->swapped as $id => $original) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    // -- The page ---------------------------------------------------------------------

    public function testThePageShowsTheStoredStepAndTheStepsThatRouteToIt(): void
    {
        $this->loginAs($this->s['member']);
        $ctrl = $this->makeController();

        $this->assertSame('rendered:edit-step', $ctrl->actionEditStep((int)$this->workflow->id, (int)$this->deploy->id));

        $params = $ctrl->capturedParams;
        $this->assertSame((int)$this->deploy->id, (int)$params['step']->id);
        $this->assertSame(3, $params['position']);
        $this->assertSame($params['step'], $params['formModel']);
        $this->assertNull($params['error']);
        $this->assertSame([['label' => '1. Build', 'note' => null]], $params['routedBy']);
        $this->assertSame([], $params['activeRunIds']);

        $html = $this->renderEdit((int)$this->deploy->id);
        $reference = sprintf(WorkflowStepsTable::STEP_REFERENCE, 3, 'Deploy');
        $this->assertStringContainsString('<h2>' . Html::encode(sprintf(WorkflowStepFormView::EDIT_HEADING, $reference)) . '</h2>', $html);
        $this->assertStringContainsString('id="edit-step-form"', $html);
        $this->assertStringContainsString(
            'action="' . Html::encode(Url::to(['/workflow-template/edit-step', 'id' => $this->workflow->id, 'step' => $this->deploy->id])) . '"',
            $html
        );
        $this->assertSame(['1. Build'], $this->routedBy($html));
        $this->assertStringContainsString('<fieldset id="edit-step-fieldset">', $html, 'the form can be sent');
        $this->assertStringNotContainsString('edit-step-locked', $html);
        $this->assertStringNotContainsString('edit-step-extra-vars', $html, 'no variable mapping');
        $this->assertStringContainsString(Url::to(['/workflow-template/view', 'id' => $this->workflow->id, '#' => 'step-' . $this->deploy->id]), $html, 'back to the step');
    }

    /**
     * A step no other step routes to says so; its variable mapping, which
     * only the API changes, is shown read-only and encoded.
     */
    public function testThePageSaysWhenNoStepRoutesHereAndShowsTheMappingReadOnly(): void
    {
        $this->test->extra_vars_template = '{"target": "<prod>"}';
        $this->test->save(false);
        $this->loginAs($this->s['admin']);

        $html = $this->renderEdit((int)$this->test->id);

        $this->assertMatchesRegularExpression(
            '#id="edit-step-routed-by".*' . preg_quote(Html::encode(WorkflowStepFormView::ROUTED_BY_NONE), '#') . '#s',
            $html
        );
        $this->assertSame([], $this->routedBy($html));
        $this->assertStringContainsString(Html::encode(WorkflowStepFormView::EXTRA_VARS_READONLY), $html);
        $this->assertStringContainsString('{&quot;target&quot;: &quot;&lt;prod&gt;&quot;}', $html);
        $this->assertStringNotContainsString('"<prod>"', $html);
    }

    public function testAStepTheWorkflowDoesNotHaveIsNotFound(): void
    {
        $this->loginAs($this->s['admin']);
        $elsewhere = $this->createWorkflowWithJobSteps($this->s['admin']->id, $this->s['own']->id)->getSteps()->one();
        $this->assertInstanceOf(WorkflowStep::class, $elsewhere);

        foreach ([987654321, (int)$elsewhere->id] as $stepId) {
            $this->assertNotFound(
                fn () => $this->makeController()->actionEditStep((int)$this->workflow->id, $stepId),
                sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$this->workflow->id, $stepId)
            );
        }
        $this->assertNotFound(
            fn () => $this->makeController()->actionEditStep(987654321, (int)$this->deploy->id),
            'Workflow template #987654321 not found.'
        );
    }

    /**
     * A member who may only view a job step's project may not edit the
     * workflow's steps; the team scope is checked before the step is looked
     * up, so the answer tells nothing about the step.
     */
    public function testAWorkflowTheUserMayOnlyViewIsForbidden(): void
    {
        $viewed = $this->createWorkflowWithJobSteps($this->s['admin']->id, $this->s['viewed']->id);
        $step = $viewed->getSteps()->one();
        $this->assertInstanceOf(WorkflowStep::class, $step);
        $this->loginAs($this->s['member']);
        $refusal = 'You do not have permission to modify this resource.';

        $this->assertForbidden(fn () => $this->makeController()->actionEditStep((int)$viewed->id, (int)$step->id), $refusal);
        $this->assertForbidden(fn () => $this->makeController()->actionEditStep((int)$viewed->id, 987654321), $refusal);
        $this->setPost(['WorkflowStep' => ['name' => 'renamed']]);
        $this->assertForbidden(fn () => $this->makeController()->actionEditStep((int)$viewed->id, (int)$step->id), $refusal);
        $step->refresh();
        $this->assertSame('step-0', $step->name);
    }

    /**
     * Only admins change the steps of a workflow with an approval step or a
     * gated job step: an operator gets 403 for the page and for a save.
     */
    public function testAnOperatorMayNotEditTheStepsOfAGatedWorkflow(): void
    {
        $this->gate($this->s['own']);
        $this->loginAs($this->s['member']);
        $refusal = ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED;

        $this->assertForbidden(fn () => $this->makeController()->actionEditStep((int)$this->workflow->id, (int)$this->test->id), $refusal);
        $this->setPost(['WorkflowStep' => ['name' => 'renamed']]);
        $this->assertForbidden(fn () => $this->makeController()->actionEditStep((int)$this->workflow->id, (int)$this->test->id), $refusal);

        $this->test->refresh();
        $this->assertSame('Test', $this->test->name);
        $this->assertNull($this->updateAudit());
    }

    /**
     * While a run is active the steps do not change: the page names the
     * runs and its form cannot be sent; a save that is sent anyway is shown
     * again with the refusal, and nothing is written.
     */
    public function testWhileARunIsActiveTheFormCannotBeSent(): void
    {
        $run = $this->createWorkflowJob((int)$this->workflow->id, $this->s['admin']->id);
        $this->loginAs($this->s['admin']);

        $html = $this->renderEdit((int)$this->test->id);
        $this->assertStringContainsString('id="edit-step-locked"', $html);
        $this->assertStringContainsString('<a href="' . Url::to(['/workflow-job/view', 'id' => $run->id]) . '">#' . $run->id . '</a>', $html);
        $this->assertStringContainsString('<fieldset id="edit-step-fieldset" disabled>', $html);

        $this->setPost(['WorkflowStep' => ['name' => 'renamed'], 'position' => WorkflowStepPosition::KIND_START]);
        $ctrl = $this->makeController();
        $this->assertSame('rendered:edit-step', $ctrl->actionEditStep((int)$this->workflow->id, (int)$this->test->id));
        $this->assertSame(
            WorkflowTemplateController::FLASH_STEP_NOT_SAVED . WorkflowStepChangeGuard::runsActiveMessage([(int)$run->id]),
            $ctrl->capturedParams['error']
        );
        $this->assertSame('renamed', $ctrl->capturedParams['formModel']->name, 'what the user typed');
        $this->assertSame([(int)$run->id], $ctrl->capturedParams['activeRunIds']);
        $this->assertSame([], \Yii::$app->session->getAllFlashes(), 'shown on the page, not as a flash');
        $this->test->refresh();
        $this->assertSame('Test', $this->test->name);
        $this->assertNull($this->updateAudit());

        $html = $this->renderEdit((int)$this->test->id, true);
        $this->assertStringContainsString(Html::encode($ctrl->capturedParams['error']), $html);
        $this->assertSame(WorkflowStepPosition::KIND_START, $this->selectedPosition($html), 'the Position as posted');
    }

    // -- The save ---------------------------------------------------------------------

    /**
     * regression: a step could not be edited. The step keeps its ID, the
     * branch of Build that leads to it stays, and the change is audited
     * once, by the step write service.
     */
    public function testRegressionAStepIsEditedInPlaceKeepingItsIdAndTheBranchesThatLeadToIt(): void
    {
        $this->loginAs($this->s['member']);
        $this->setPost([
            'WorkflowStep' => [
                'name' => 'Deploy prod',
                'job_template_id' => (string)$this->s['open']->id,
                'on_success_step_id' => (string)WorkflowStep::END_WORKFLOW,
                'on_failure_step_id' => '',
            ],
            'position' => WorkflowStepPosition::WEB_KEEP,
        ]);
        $ctrl = $this->makeController();

        $this->assertInstanceOf(Response::class, $ctrl->actionEditStep((int)$this->workflow->id, (int)$this->deploy->id));

        $this->assertSame(['view', 'id' => (int)$this->workflow->id, '#' => 'step-' . $this->deploy->id], $ctrl->redirectedTo);
        $this->assertSame(
            ['success' => sprintf(WorkflowStepChangeSummary::SAVED, 'Deploy prod', 'name, job template, On Success')],
            \Yii::$app->session->getAllFlashes(true)
        );
        $saved = WorkflowStep::findOne($this->deploy->id);
        $this->assertNotNull($saved, 'the same step');
        $this->assertSame('Deploy prod', $saved->name);
        $this->assertSame((int)$this->s['open']->id, (int)$saved->job_template_id);
        $this->assertSame(WorkflowStep::END_WORKFLOW, (int)$saved->on_success_step_id);
        $this->build->refresh();
        $this->assertSame((int)$this->deploy->id, (int)$this->build->on_failure_step_id, 'the branch that leads to it stays');
        $this->assertSame(3, (int)WorkflowStep::find()->where(['workflow_template_id' => $this->workflow->id])->count());
        $audit = $this->updateAudit();
        $this->assertNotNull($audit);
        $this->assertSame($this->s['member']->id, (int)$audit->user_id);
        $metadata = json_decode((string)$audit->metadata, true);
        $this->assertIsArray($metadata);
        $this->assertSame((int)$this->deploy->id, $metadata['step_id'] ?? null);
        $this->assertSame('web', $metadata['source'] ?? null);
    }

    /**
     * An edit can move the step too: the flash says where it is now, and
     * warns that runs start with another step.
     */
    public function testAnEditCanMoveTheStep(): void
    {
        $this->loginAs($this->s['admin']);
        $this->setPost([
            'WorkflowStep' => ['name' => 'Test all', 'job_template_id' => (string)$this->s['own']->id],
            'position' => WorkflowStepPosition::KIND_START,
        ]);

        $this->makeController()->actionEditStep((int)$this->workflow->id, (int)$this->test->id);

        $flashes = \Yii::$app->session->getAllFlashes(true);
        $this->assertStringStartsWith(
            sprintf(WorkflowStepChangeSummary::SAVED_MOVED, 'Test all', 'name', 1, 3, WorkflowStepChangeSummary::AT_START),
            (string)($flashes['success'] ?? '')
        );
        $this->assertSame([sprintf(WorkflowStepChangeSummary::START_CHANGED, 'Test all', 'Build')], $flashes['warning'] ?? null);
        $this->assertSame([(int)$this->test->id, (int)$this->build->id, (int)$this->deploy->id], $this->runOrder());
    }

    /**
     * Saving what is stored changes nothing, writes nothing and says so in
     * an info flash.
     */
    public function testAnEditThatChangesNothingSaysSo(): void
    {
        $this->loginAs($this->s['admin']);
        $this->setPost([
            'WorkflowStep' => [
                'name' => 'Build',
                'job_template_id' => (string)$this->s['own']->id,
                'on_success_step_id' => '',
                'on_failure_step_id' => (string)$this->deploy->id,
                'on_always_step_id' => '',
            ],
            'position' => WorkflowStepPosition::WEB_KEEP,
        ]);

        $this->makeController()->actionEditStep((int)$this->workflow->id, (int)$this->build->id);

        $this->assertSame(['info' => sprintf(WorkflowStepChangeSummary::UNCHANGED, 'Build')], \Yii::$app->session->getAllFlashes(true));
        $this->assertNull($this->updateAudit());
    }

    /**
     * A refused save shows the page again (200), with the refusal above the
     * form and what the user typed in it; nothing is written.
     */
    public function testARefusedEditIsShownAgainWithWhatWasTyped(): void
    {
        $this->loginAs($this->s['admin']);
        $this->setPost(['WorkflowStep' => [
            'name' => '',
            'job_template_id' => (string)$this->s['own']->id,
            'on_failure_step_id' => (string)WorkflowStep::END_WORKFLOW,
        ]]);
        $ctrl = $this->makeController();

        $this->assertSame('rendered:edit-step', $ctrl->actionEditStep((int)$this->workflow->id, (int)$this->test->id));

        $this->assertSame(WorkflowTemplateController::FLASH_STEP_NOT_SAVED . 'Name cannot be blank.', $ctrl->capturedParams['error']);
        $formModel = $ctrl->capturedParams['formModel'];
        $this->assertSame('', $formModel->name);
        $this->assertSame('0', (string)$formModel->on_failure_step_id);
        $this->assertSame((int)$this->test->id, (int)$formModel->id);
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
        $this->test->refresh();
        $this->assertSame('Test', $this->test->name);
        $this->assertNull($this->test->on_failure_step_id);

        $html = $this->renderEdit((int)$this->test->id, true);
        $this->assertMatchesRegularExpression(
            '#id="step-form-error"[^>]*>\s*' . preg_quote(Html::encode(WorkflowTemplateController::FLASH_STEP_NOT_SAVED . 'Name cannot be blank.'), '#') . '#',
            $html
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusedSaveProvider(): array
    {
        return [
            'another step type' => [['WorkflowStep' => ['name' => 'x', 'step_type' => WorkflowStep::TYPE_PAUSE]], WorkflowStepCommand::TYPE_LOCKED],
            'a branch the form does not offer' => [
                ['WorkflowStep' => ['name' => 'x', 'on_success_step_id' => 'abc']],
                sprintf(WorkflowStepCommand::ROUTE_INVALID, 'on_success_step_id'),
            ],
            'a position the form does not offer' => [['WorkflowStep' => ['name' => 'x'], 'position' => 'first'], WorkflowStepPosition::FORM_INVALID],
            'a branch to a step of another workflow' => [['WorkflowStep' => ['name' => 'x', 'on_success_step_id' => 'elsewhere']], WorkflowStep::ROUTE_TARGET_OUTSIDE],
        ];
    }

    /**
     * @dataProvider refusedSaveProvider
     * @param array<string, mixed> $post
     */
    public function testARefusedSaveSaysWhy(array $post, string $refusal): void
    {
        $elsewhere = $this->createWorkflowWithJobSteps($this->s['admin']->id, $this->s['own']->id)->getSteps()->one();
        $this->assertInstanceOf(WorkflowStep::class, $elsewhere);
        if (($post['WorkflowStep']['on_success_step_id'] ?? null) === 'elsewhere') {
            $post['WorkflowStep']['on_success_step_id'] = (string)$elsewhere->id;
        }
        $this->loginAs($this->s['admin']);
        $this->setPost($post);
        $ctrl = $this->makeController();

        $this->assertSame('rendered:edit-step', $ctrl->actionEditStep((int)$this->workflow->id, (int)$this->test->id));

        $this->assertSame(WorkflowTemplateController::FLASH_STEP_NOT_SAVED . $refusal, $ctrl->capturedParams['error']);
        $this->test->refresh();
        $this->assertSame('Test', $this->test->name);
        $this->assertSame(WorkflowStep::TYPE_JOB, $this->test->step_type);
    }

    /**
     * Security rule 12: only admins give a job step a job template with an
     * approval rule, by an edit too (403, nothing written).
     */
    public function testAnOperatorMayNotGiveAJobStepAGatedJobTemplate(): void
    {
        $this->gate($this->s['open']);
        $this->loginAs($this->s['member']);
        $this->setPost(['WorkflowStep' => ['name' => 'Test', 'job_template_id' => (string)$this->s['open']->id]]);

        $this->assertForbidden(
            fn () => $this->makeController()->actionEditStep((int)$this->workflow->id, (int)$this->test->id),
            ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED
        );

        $this->test->refresh();
        $this->assertSame((int)$this->s['own']->id, (int)$this->test->job_template_id);
        $this->assertNull($this->updateAudit());
    }

    /**
     * A Position after a step that was removed since the page was shown is
     * refused; the form shown again keeps the step where it is, as the
     * removed step is no choice any more.
     */
    public function testAPositionAfterAStepRemovedMeanwhileIsRefused(): void
    {
        $gone = $this->step('Gone', 40);
        $goneId = (int)$gone->id;
        $gone->delete();
        $this->loginAs($this->s['admin']);
        $this->setPost(['WorkflowStep' => ['name' => 'Test'], 'position' => WorkflowStepPosition::WEB_AFTER_PREFIX . $goneId]);
        $ctrl = $this->makeController();

        $ctrl->actionEditStep((int)$this->workflow->id, (int)$this->test->id);

        $this->assertSame(
            WorkflowTemplateController::FLASH_STEP_NOT_SAVED . sprintf(WorkflowStepReorderService::AFTER_STEP_MISSING, $goneId),
            $ctrl->capturedParams['error']
        );
        $html = $this->renderEdit((int)$this->test->id, true);
        $this->assertSame(WorkflowStepPosition::WEB_KEEP, $this->selectedPosition($html));
        $this->assertSame([(int)$this->build->id, (int)$this->test->id, (int)$this->deploy->id], $this->runOrder());
    }

    /**
     * A step removed by another request while the save waited for the
     * workflow's lock is not found (404).
     */
    public function testAStepRemovedWhileTheSaveWaitedIsNotFound(): void
    {
        $this->swap('workflowStepChangeGuard', new class () extends WorkflowStepChangeGuard {
            public function change(WorkflowTemplate $template, int $userId, bool $addsApprovalStep, callable $change): mixed
            {
                return parent::change($template, $userId, $addsApprovalStep, static function () use ($template, $change): mixed {
                    WorkflowStep::deleteAll(['workflow_template_id' => $template->id, 'name' => 'Test']);

                    return $change();
                });
            }
        });
        $this->loginAs($this->s['admin']);
        $this->setPost(['WorkflowStep' => ['name' => 'Test again']]);

        $this->assertNotFound(
            fn () => $this->makeController()->actionEditStep((int)$this->workflow->id, (int)$this->test->id),
            sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$this->workflow->id, (int)$this->test->id)
        );
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    /**
     * A step removed by another request between the page's checks and its
     * read of the workflow's steps is not found (404), for the page and for
     * the form shown again after a refusal.
     */
    public function testAStepRemovedWhileThePageWasBuiltIsNotFound(): void
    {
        $policy = new class () extends ApprovalRuleAssignmentPolicy {
            public int $removes = 0;

            public function workflowStepsChangeRefusal(int $userId, WorkflowTemplate $template, bool $addsApprovalStep): ?string
            {
                WorkflowStep::deleteAll(['id' => $this->removes]);

                return parent::workflowStepsChangeRefusal($userId, $template, $addsApprovalStep);
            }
        };
        $policy->removes = (int)$this->test->id;
        $this->swap('approvalRuleAssignmentPolicy', $policy);
        $this->loginAs($this->s['admin']);
        $gone = sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, (int)$this->workflow->id, (int)$this->test->id);

        $this->assertNotFound(fn () => $this->makeController()->actionEditStep((int)$this->workflow->id, (int)$this->test->id), $gone);
    }

    // -- Helpers ------------------------------------------------------------------------

    private function step(string $name, int $order): WorkflowStep
    {
        $step = $this->createWorkflowStep((int)$this->workflow->id, $order, WorkflowStep::TYPE_JOB, $this->s['own']->id);
        $step->name = $name;
        $step->save(false);

        return $step;
    }

    /**
     * Give $template an approval rule whose approvers are the admin and the
     * member, which makes its job steps gated.
     */
    private function gate(JobTemplate $template): void
    {
        $rule = $this->createApprovalRule(
            $this->s['admin']->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [$this->s['admin']->id, $this->s['member']->id]])
        );
        JobTemplate::updateAll(['approval_rule_id' => $rule->id], ['id' => $template->id]);
    }

    /**
     * @return list<int> the workflow's step IDs in run order
     */
    private function runOrder(): array
    {
        return array_map('intval', WorkflowStep::find()
            ->select('id')
            ->where(['workflow_template_id' => $this->workflow->id])
            ->orderBy(WorkflowTemplate::RUN_ORDER)
            ->column());
    }

    private function updateAudit(): ?AuditLog
    {
        /** @var AuditLog|null $audit */
        $audit = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_UPDATED, 'object_id' => $this->workflow->id])
            ->one();

        return $audit;
    }

    /**
     * The edit page of the step $stepId as the signed-in user sees it, for a
     * GET, or for the POST set up before ($post).
     */
    private function renderEdit(int $stepId, bool $post = false): string
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        if (!$post) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
        $ctrl = new class ('workflow-template', $app) extends WorkflowTemplateController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
        $workflowId = (int)$this->workflow->id;
        $result = $this->withRealViews(
            $ctrl,
            '/workflow-template/edit-step?id=' . $workflowId . '&step=' . $stepId,
            static fn (): Response|string => $ctrl->actionEditStep($workflowId, $stepId)
        );
        $this->assertIsString($result);

        return $result;
    }

    /**
     * The labels of the steps the page lists as routing to the step.
     *
     * @return list<string>
     */
    private function routedBy(string $html): array
    {
        $this->assertSame(1, preg_match('#<div[^>]*id="edit-step-routed-by"[^>]*>(.*?)</div>\s*</div>#s', $html, $card));
        preg_match_all('#<li>(.*?)</li>#s', $card[1], $items);

        return array_map(static fn (string $item): string => html_entity_decode(trim($item)), $items[1]);
    }

    /**
     * The Position the form shows selected.
     */
    private function selectedPosition(string $html): string
    {
        $this->assertSame(1, preg_match('#<select\b[^>]*\bname="position"[^>]*>(.*?)</select>#s', $html, $select));
        $this->assertSame(1, preg_match('#<option\b[^>]*\bvalue="([^"]*)"[^>]*\bselected\b#', $select[1], $selected));

        return html_entity_decode($selected[1]);
    }

    private function assertForbidden(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected ForbiddenHttpException.');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function assertNotFound(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected NotFoundHttpException.');
        } catch (NotFoundHttpException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function swap(string $id, object $replacement): void
    {
        if (!array_key_exists($id, $this->swapped)) {
            $this->swapped[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $replacement);
    }

    /**
     * @return WorkflowTemplateController&object{capturedParams: array<string, mixed>, redirectedTo: array<int|string, mixed>|string|null}
     */
    private function makeController(): WorkflowTemplateController
    {
        return new class ('workflow-template', \Yii::$app) extends WorkflowTemplateController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];
            /** @var array<int|string, mixed>|string|null */
            public array|string|null $redirectedTo = null;

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $this->redirectedTo = $url;
                return new Response();
            }
        };
    }
}
