<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\VersionedAssetUrl;
use app\components\WorkflowStepCommand;
use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowRouteMap;
use app\services\WorkflowStepFormView;
use app\services\WorkflowTemplatePage;
use app\tests\integration\TeamScopeFixtures;

/**
 * The step form (views/workflow-template/_step-form.php), rendered with
 * real views: Add Step as the template page shows it, and the form of the
 * page that edits one step.
 *
 * The Position replaces the Step Order field; the server renders the
 * preview and the warning of the selected choice, and the page script those
 * of the next one. A new job step ends the workflow when it fails, an
 * approval step's On Failure has one end choice, and Edit Step offers every
 * step as a branch target with the type fixed.
 */
class WorkflowTemplateStepFormViewTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use WorkflowStepsViews;

    private User $admin;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('step-form', 'admin');
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($this->admin->id)->id,
            (int)$this->createInventory($this->admin->id)->id,
            (int)$this->createRunnerGroup($this->admin->id)->id,
            $this->admin->id
        );
        $this->loginAs($this->admin);
    }

    // -- Add Step -----------------------------------------------------------------------

    public function testAddStepPlacesTheStepByPositionNotByStepOrder(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy']);

        $xpath = $this->xpathOf($this->addFormHtml($workflow));

        $this->assertSame(WorkflowStepFormView::ADD_HEADING, $this->textOf($this->one($xpath, '//h5[@id="add-step"]')));
        $form = $this->one($xpath, '//form[@id="add-step-form"]');
        $this->assertSame('/workflow-template/add-step?id=' . $workflow->id, $form->getAttribute('action'));
        $this->assertSame('add', $form->getAttribute('data-step-form'));
        $this->assertSame([], $this->all($xpath, '//*[@name="WorkflowStep[step_order]"]'), 'no Step Order');
        $this->assertSame(WorkflowStepFormView::POSITION_LABEL, $this->textOf($this->one($xpath, '//label[@for="ws-position"]')));
        $position = $this->one($xpath, '//select[@id="ws-position"][@name="position"]');
        $this->assertSame(
            ['end' => 'At the end (after 2. Deploy)', 'start' => WorkflowStepFormView::POSITION_START, 'after:' . $this->idOf($workflow, 'Build') => 'After 1. Build'],
            $this->optionsOf($xpath, $position)
        );
        $this->assertSame('end', $this->selectedOf($xpath, $position));
        $preview = $this->one($xpath, '//div[@id="ws-position-preview"]');
        $this->assertSame('Runs last, after "Deploy".', $this->textOf($preview));
        $this->assertSame('polite', $preview->getAttribute('aria-live'));
        $warning = $this->one($xpath, '//div[@id="ws-position-warning"]');
        $this->assertSame('', $this->textOf($warning));
        $this->assertStringContainsString('d-none', $warning->getAttribute('class'));
        $this->assertSame(WorkflowStepFormView::ADD_SUBMIT, $this->textOf($this->one($xpath, '//form//button[@type="submit"]')));
        $this->assertSame([], $this->all($xpath, '//a[@id="edit-step-cancel"]'));
    }

    public function testEveryPositionChoiceCarriesItsPreviewWarningsAndPrompts(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy']);

        $xpath = $this->xpathOf($this->addFormHtml($workflow));

        $start = $this->one($xpath, '//select[@id="ws-position"]/option[@value="start"]');
        $this->assertSame('Runs first, before "Build".', $start->getAttribute('data-preview'));
        $this->assertSame('Runs will start with this step instead of "Build".', $start->getAttribute('data-warn'));
        $this->assertSame('', $start->getAttribute('data-warn-job'));
        $this->assertSame('', $start->getAttribute('data-warn-approval'));
        $this->assertSame('Next in the list: "Build"', $start->getAttribute('data-success-prompt'));
        $this->assertSame('Next in the list: "Build", also after a failure', $start->getAttribute('data-failure-prompt'));
    }

    public function testInsertBelowSelectsThePlaceAfterTheStepAndWarnsBeforeTheForm(): void
    {
        $rule = $this->createApprovalRule($this->admin->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$this->admin->id]]));
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        $build = $this->namedStep($workflow, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $this->namedStep($workflow, 'Approve', 20, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);

        $xpath = $this->xpathOf($this->renderForm(WorkflowStepFormView::forAdd($this->mapOf($workflow), (int)$build->id), new WorkflowStep(), $workflow));

        $this->assertSame('after:' . $build->id, $this->selectedOf($xpath, $this->one($xpath, '//select[@id="ws-position"]')));
        $this->assertTrue($this->one($xpath, '//input[@id="workflowstep-name"]')->hasAttribute('autofocus'));
        $this->assertSame('Runs after "Build", before "Approve".', $this->textOf($this->one($xpath, '//div[@id="ws-position-preview"]')));
        $warning = $this->one($xpath, '//div[@id="ws-position-warning"]');
        $this->assertSame('This job step will run before the approval step "Approve", without waiting for it.', $this->textOf($warning));
        $this->assertStringNotContainsString('d-none', $warning->getAttribute('class'));
        $this->assertSame(
            ['end' => 'At the end (after 2. Approve (approval step))', 'start' => WorkflowStepFormView::POSITION_START, 'after:' . $build->id => 'After 1. Build'],
            $this->optionsOf($xpath, $this->one($xpath, '//select[@id="ws-position"]'))
        );
    }

    /**
     * regression: the preview of a Position went by position only. Below a
     * step whose On Success names the next step, an approval step read
     * 'Runs after "Build", before "Deploy".' without a warning, while every
     * run that succeeds goes from Build to Deploy.
     */
    public function testRegressionInsertBelowAStepThatLeadsPastItSaysSoAndWarnsAnApprovalStep(): void
    {
        $rule = $this->createApprovalRule($this->admin->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$this->admin->id]]));
        $workflow = $this->workflowOf(['Build', 'Deploy']);
        $build = $this->stepNamed($workflow, 'Build');
        $build->on_success_step_id = $this->idOf($workflow, 'Deploy');
        $build->save(false);
        $approval = new WorkflowStep(['step_type' => WorkflowStep::TYPE_APPROVAL, 'approval_rule_id' => (int)$rule->id]);
        $form = WorkflowStepFormView::forAdd($this->mapOf($workflow), (int)$build->id);

        $xpath = $this->xpathOf($this->renderForm($form, $approval, $workflow));

        $skipped = 'Listed after "Build", but a successful "Build" goes on with "Deploy", skipping this step.';
        $offPath = 'This approval step will not be on the success path: runs whose steps all succeed will not wait for it.';
        $this->assertSame($skipped, $this->textOf($this->one($xpath, '//div[@id="ws-position-preview"]')));
        $warning = $this->one($xpath, '//div[@id="ws-position-warning"]');
        $this->assertSame($offPath, $this->textOf($warning));
        $this->assertStringNotContainsString('d-none', $warning->getAttribute('class'));
        $choice = $this->one($xpath, '//select[@id="ws-position"]/option[@value="after:' . $build->id . '"]');
        $this->assertSame($skipped, $choice->getAttribute('data-preview'));
        $this->assertSame($offPath, $choice->getAttribute('data-warn-approval'));
        $this->assertSame('', $choice->getAttribute('data-warn-job'));
        $this->one($xpath, '//div[@id="' . WorkflowStepFormView::POSITION_FIELD_ID . '"]/select[@id="ws-position"]');
    }

    /**
     * regression: a job step added with the form's defaults went on with the
     * next step after a failure; the default of On Failure was "next step".
     */
    public function testRegressionANewJobStepEndsTheWorkflowWhenItFails(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy']);

        $xpath = $this->xpathOf($this->addFormHtml($workflow));

        $failure = $this->one($xpath, '//select[@id="workflowstep-on_failure_step_id"]');
        $this->assertSame('0', $this->selectedOf($xpath, $failure));
        $this->assertSame(WorkflowStepFormView::END_FAILS_OPTION, $this->textOf($this->one($xpath, './option[@value="0"]', $failure)));
    }

    public function testTheBranchSelectsNameTheNextStepAndEveryEnd(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy']);
        $build = $this->idOf($workflow, 'Build');
        $deploy = $this->idOf($workflow, 'Deploy');

        $xpath = $this->xpathOf($this->addFormHtml($workflow));

        $this->assertSame(
            ['' => 'Next in the list: none, the run succeeds', '0' => WorkflowStepFormView::END_SUCCEEDS_OPTION, $build => '1. Build', $deploy => '2. Deploy'],
            $this->optionsOf($xpath, $this->one($xpath, '//select[@id="workflowstep-on_success_step_id"]'))
        );
        $this->assertSame(
            ['' => 'Next in the list: none, the run fails', '0' => WorkflowStepFormView::END_FAILS_OPTION, $build => '1. Build', $deploy => '2. Deploy'],
            $this->optionsOf($xpath, $this->one($xpath, '//select[@id="workflowstep-on_failure_step_id"]'))
        );
        $this->assertSame(
            ['' => WorkflowStepFormView::ALWAYS_NONE_OPTION, $build => '1. Build', $deploy => '2. Deploy'],
            $this->optionsOf($xpath, $this->one($xpath, '//select[@id="workflowstep-on_always_step_id"]'))
        );
        $note = $this->one($xpath, '//div[@id="ws-failure-default-note"]');
        $this->assertSame(WorkflowStepFormView::FAILURE_DEFAULT_NOTE, $this->textOf($note));
        $this->assertStringNotContainsString('d-none', $note->getAttribute('class'));
        $success = $this->one($xpath, '//div[@id="ws-route-success"]');
        $this->assertSame(WorkflowStepFormView::SUCCESS_LABEL, $success->getAttribute('data-label-default'));
        $this->assertSame(WorkflowStepFormView::SUCCESS_LABEL_PAUSE, $success->getAttribute('data-label-pause'));
        $failure = $this->one($xpath, '//div[@id="ws-route-failure"]');
        $this->assertSame(WorkflowStepFormView::END_FAILS_OPTION, $failure->getAttribute('data-prompt-approval'));
        $this->assertSame(WorkflowStepFormView::NEXT_OPTION_NONE_FAILS, $failure->getAttribute('data-prompt-job'));
    }

    public function testAddStepShowsTheFieldsOfAJobStepFirst(): void
    {
        $xpath = $this->xpathOf($this->addFormHtml($this->workflowOf(['Build'])));

        $this->assertSame('job', $this->selectedOf($xpath, $this->one($xpath, '//select[@id="ws-step-type"]')));
        $this->assertFalse($this->one($xpath, '//select[@id="ws-step-type"]')->hasAttribute('disabled'));
        $this->assertVisible($xpath, 'ws-target-job', true);
        $this->assertVisible($xpath, 'ws-target-approval', false);
        $this->assertVisible($xpath, 'ws-route-failure', true);
        $this->assertVisible($xpath, 'ws-route-always', true);
        $this->assertStringNotContainsString('d-none', $this->one($xpath, '//span[@id="ws-routing-help-job"]')->getAttribute('class'));
        $this->assertSame('d-none', $this->one($xpath, '//span[@id="ws-routing-help-pause"]')->getAttribute('class'));
        $this->assertSame([], $this->all($xpath, '//div[@id="ws-type-hint"]'));
    }

    public function testTheFirstStepHasNoPosition(): void
    {
        $xpath = $this->xpathOf($this->addFormHtml($this->createWorkflowTemplate($this->admin->id)));

        $this->assertSame([], $this->all($xpath, '//select[@id="ws-position"]'));
        $this->assertSame(WorkflowStepFormView::FIRST_STEP_NOTE, $this->textOf($this->one($xpath, '//p[@id="ws-position-first-step"]')));
    }

    public function testUsersWhoMayNotAssignRulesGetNoApprovalChoices(): void
    {
        $workflow = $this->workflowOf(['Build']);
        $html = $this->renderForm(WorkflowStepFormView::forAdd($this->mapOf($workflow), null), new WorkflowStep(), $workflow, false);
        $xpath = $this->xpathOf($html);

        $this->assertSame(['job' => 'Job', 'pause' => 'Pause'], $this->optionsOf($xpath, $this->one($xpath, '//select[@id="ws-step-type"]')));
        $this->assertSame([], $this->all($xpath, '//*[@id="ws-target-approval"]'));
        $this->assertMatchesRegularExpression(
            '#<div id="ws-gated-templates-hint" class="hint-block">\s*' . preg_quote(WorkflowTemplatePage::GATED_TEMPLATES_HINT, '#') . '\s*</div>#',
            $html
        );
    }

    public function testTheScriptLoadsWithTheVersionOfItsContent(): void
    {
        $html = $this->addFormHtml($this->workflowOf(['Build']));

        $script = VersionedAssetUrl::of('/js/workflow-step-form.js');
        $this->assertMatchesRegularExpression('#^/js/workflow-step-form\.js\?v=[0-9a-f]{12}$#', $script);
        $this->assertStringContainsString('<script src="' . $script . '"></script>', $html);
        $this->assertStringNotContainsString('<script>', $html, 'no inline script');
    }

    /**
     * The script focuses the field marked autofocus also on a page opened at
     * a fragment, where a browser skips autofocus. So only "+ Insert below"
     * marks the name: the template page itself, "+ Insert below" a step of
     * another workflow and Edit Step mark nothing, and none of these pages
     * jumps to the form uninvited.
     */
    public function testOnlyInsertBelowMarksTheNameForTheFocus(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy']);
        $build = $this->stepNamed($workflow, 'Build');
        $elsewhere = $this->stepNamed($this->workflowOf(['Elsewhere']), 'Elsewhere');
        $add = fn (?int $after): string => $this->renderForm(WorkflowStepFormView::forAdd($this->mapOf($workflow), $after), new WorkflowStep(), $workflow);
        $marked = fn (string $html): bool => $this->one($this->xpathOf($html), '//input[@id="workflowstep-name"]')->hasAttribute('autofocus');

        $this->assertTrue($marked($add((int)$build->id)), '+ Insert below');
        $this->assertFalse($marked($add(null)), 'the template page');
        $this->assertFalse($marked($add((int)$elsewhere->id)), '+ Insert below a step of another workflow');
        $this->assertFalse($marked($this->editFormHtml($workflow, $build)), 'Edit Step');
    }

    // -- Edit Step ----------------------------------------------------------------------

    public function testEditStepShowsTheStepAsStoredWithItsTypeFixed(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy', 'Notify']);
        $deploy = $this->stepNamed($workflow, 'Deploy');
        $this->route($deploy, ['success' => $this->idOf($workflow, 'Notify')]);

        $xpath = $this->xpathOf($this->editFormHtml($workflow, $deploy));

        $form = $this->one($xpath, '//form[@id="edit-step-form"]');
        $this->assertSame("/workflow-template/edit-step?id={$workflow->id}&step={$deploy->id}", $form->getAttribute('action'));
        $this->assertSame('edit', $form->getAttribute('data-step-form'));
        $this->assertSame([], $this->all($xpath, '//h5[@id="add-step"]'), 'the edit page has the title');
        $this->assertSame('Deploy', $this->one($xpath, '//input[@id="workflowstep-name"]')->getAttribute('value'));
        $type = $this->one($xpath, '//select[@id="ws-step-type"]');
        $this->assertTrue($type->hasAttribute('disabled'), 'not posted');
        $this->assertSame('job', $this->selectedOf($xpath, $type));
        $this->assertSame(WorkflowStepCommand::TYPE_LOCKED, $this->textOf($this->one($xpath, '//div[@id="ws-type-hint"]')));
        $position = $this->one($xpath, '//select[@id="ws-position"]');
        $this->assertSame('keep', $this->selectedOf($xpath, $position));
        $this->assertSame(sprintf(WorkflowStepFormView::POSITION_KEEP, 2), $this->textOf($this->one($xpath, './option[@value="keep"]', $position)));
        $this->assertSame((string)$this->template->id, $this->selectedOf($xpath, $this->one($xpath, '//select[@id="workflowstep-job_template_id"]')));
        $success = $this->one($xpath, '//select[@id="workflowstep-on_success_step_id"]');
        $this->assertSame((string)$this->idOf($workflow, 'Notify'), $this->selectedOf($xpath, $success));
        $this->assertSame('2. Deploy (this step)', $this->textOf($this->one($xpath, './option[@value="' . $deploy->id . '"]', $success)));
        $this->assertSame('', $this->selectedOf($xpath, $this->one($xpath, '//select[@id="workflowstep-on_failure_step_id"]')), 'an empty On Failure stays empty');
        $this->assertSame([], $this->all($xpath, '//div[@id="ws-failure-default-note"]'));
        $this->assertSame(WorkflowStepFormView::EDIT_SUBMIT, $this->textOf($this->one($xpath, '//form//button[@type="submit"]')));
        $cancel = $this->one($xpath, '//a[@id="edit-step-cancel"]');
        $this->assertSame(WorkflowStepFormView::CANCEL, $this->textOf($cancel));
        $this->assertSame("/workflow-template/view?id={$workflow->id}#step-{$deploy->id}", $cancel->getAttribute('href'));
    }

    /**
     * regression: an approval step's On Failure offered two choices that
     * both end the run ("— end workflow —" and the empty one).
     */
    public function testRegressionAnApprovalStepsOnFailureHasOneEndChoice(): void
    {
        $rule = $this->createApprovalRule($this->admin->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$this->admin->id]]));
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        $approve = $this->namedStep($workflow, 'Approve', 10, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $this->namedStep($workflow, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$this->template->id);

        $xpath = $this->xpathOf($this->approvalStepFormHtml($workflow, $approve));

        $ends = $this->all($xpath, '//select[@id="workflowstep-on_failure_step_id"]/option[@value="" or @value="0"]');
        $this->assertCount(1, $ends, 'one choice ends the run');
        $this->assertSame(WorkflowStepFormView::END_FAILS_OPTION, $this->textOf($ends[0]));
    }

    public function testAnApprovalStepsFormHasNoAlwaysAndKeepsAStoredEnd(): void
    {
        $rule = $this->createApprovalRule($this->admin->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$this->admin->id]]));
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        $approve = $this->namedStep($workflow, 'Approve', 10, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id, ['failure' => 0]);
        $this->namedStep($workflow, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$this->template->id);

        $xpath = $this->xpathOf($this->editFormHtml($workflow, $approve));

        $failure = $this->one($xpath, '//select[@id="workflowstep-on_failure_step_id"]');
        $this->assertSame('0', $this->selectedOf($xpath, $failure), 'saving the form changes nothing');
        $this->assertCount(1, $this->all($xpath, './option[@value="" or @value="0"]', $failure));
        $this->assertVisible($xpath, 'ws-route-always', false);
        $this->assertVisible($xpath, 'ws-target-approval', true);
        $this->assertVisible($xpath, 'ws-target-job', false);
        $this->assertSame((string)$rule->id, $this->selectedOf($xpath, $this->one($xpath, '//select[@id="workflowstep-approval_rule_id"]')));
    }

    public function testAnAlwaysBranchFromAnOlderVersionShowsUnderOnSuccess(): void
    {
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        $wait = $this->namedStep($workflow, 'Wait', 10, WorkflowStep::TYPE_PAUSE);
        $deploy = $this->namedStep($workflow, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $this->route($wait, ['success' => 0, 'always' => (int)$deploy->id]);

        $xpath = $this->xpathOf($this->editFormHtml($workflow, $wait));

        $this->assertSame((string)$deploy->id, $this->selectedOf($xpath, $this->one($xpath, '//select[@id="workflowstep-on_success_step_id"]')));
        $this->assertSame(WorkflowStepFormView::LEGACY_ALWAYS_NOTE, $this->textOf($this->one($xpath, '//div[@id="ws-legacy-always-note"]')));
        $this->assertSame(
            WorkflowStepFormView::SUCCESS_LABEL_PAUSE,
            $this->textOf($this->one($xpath, '//label[@for="workflowstep-on_success_step_id"]')),
            'Resume continues there'
        );
        $this->assertSame('col-md-12', $this->one($xpath, '//div[@id="ws-route-success"]')->getAttribute('class'));
        $this->assertVisible($xpath, 'ws-route-failure', false);
        $this->assertVisible($xpath, 'ws-route-always', false);
        $this->assertVisible($xpath, 'ws-target-job', false);
    }

    public function testAJobStepWhoseTemplateWasDeletedAsksForAnother(): void
    {
        $workflow = $this->workflowOf(['Build']);
        JobTemplate::updateAll(['deleted_at' => time()], ['id' => $this->template->id]);

        $xpath = $this->xpathOf($this->editFormHtml($workflow, $this->stepNamed($workflow, 'Build')));

        $this->assertSame(WorkflowStepFormView::TEMPLATE_GONE_HINT, $this->textOf($this->one($xpath, '//div[@id="ws-template-gone-hint"]')));
        $this->assertSame('', $this->selectedOf($xpath, $this->one($xpath, '//select[@id="workflowstep-job_template_id"]')));
    }

    public function testARefusedSaveShowsWhyWithWhatTheUserChose(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy', 'Notify']);
        $deploy = $this->stepNamed($workflow, 'Deploy');
        $posted = clone $deploy;
        $posted->name = '';
        $posted->on_failure_step_id = $this->idOf($workflow, 'Build');
        $posted->addError('name', 'Name cannot be blank.');
        $form = WorkflowStepFormView::forEdit($this->mapOf($workflow), $deploy, 'start');

        $xpath = $this->xpathOf($this->renderForm($form, $posted, $workflow, true, 'Step not saved: Name cannot be blank.'));

        $error = $this->one($xpath, '//div[@id="step-form-error"]');
        $this->assertSame('alert alert-danger', $error->getAttribute('class'));
        $this->assertSame('Step not saved: Name cannot be blank.', $this->textOf($error));
        $this->assertSame('', $this->one($xpath, '//input[@id="workflowstep-name"]')->getAttribute('value'));
        $this->assertSame('Name cannot be blank.', $this->textOf($this->one($xpath, '//div[contains(@class, "field-workflowstep-name")]/div[@class="help-block"]')));
        $this->assertSame((string)$this->idOf($workflow, 'Build'), $this->selectedOf($xpath, $this->one($xpath, '//select[@id="workflowstep-on_failure_step_id"]')));
        $this->assertSame('start', $this->selectedOf($xpath, $this->one($xpath, '//select[@id="ws-position"]')));
        $this->assertSame(
            'Runs will start with this step instead of "Build".',
            $this->textOf($this->one($xpath, '//div[@id="ws-position-warning"]'))
        );
        $this->assertSame('Next in the list: "Build"', $this->textOf($this->one($xpath, '//select[@id="workflowstep-on_success_step_id"]/option[@value=""]')));
    }

    // -- XSS ----------------------------------------------------------------------------

    public function testAStepNameComesOutEncodedInTheChoicesAndTheirTexts(): void
    {
        $name = '<img src=x onerror=alert(1)> "q" \'a\'';
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        $evil = $this->namedStep($workflow, $name, 10, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $this->namedStep($workflow, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$this->template->id);

        $add = $this->addFormHtml($workflow);
        $edit = $this->editFormHtml($workflow, $evil);

        $encoded = '&lt;img src=x onerror=alert(1)&gt; &quot;q&quot; &#039;a&#039;';
        foreach (['add' => $add, 'edit' => $edit] as $mode => $html) {
            $this->assertStringNotContainsString('<img', $html, $mode);
            $this->assertStringNotContainsString('"q"', $html, $mode);
            $this->assertStringNotContainsString("'a'", $html, $mode);
        }
        $this->assertStringContainsString('>1. ' . $encoded . '</option>', $add, 'a branch choice');
        $this->assertStringContainsString('>After 1. ' . $encoded . '</option>', $add, 'a Position choice');
        $this->assertStringContainsString('data-preview="Runs first, before &quot;' . $encoded . '&quot;."', $add);
        $this->assertStringContainsString('data-success-prompt="Next in the list: &quot;' . $encoded . '&quot;"', $add);
        $this->assertStringContainsString('data-warn="Runs will start with this step instead of &quot;' . $encoded . '&quot;."', $add);
        $this->assertStringContainsString('>1. ' . $encoded . ' (this step)</option>', $edit);
        $this->assertStringContainsString('value="' . $encoded . '"', $edit, 'the name field');
        $xpath = $this->xpathOf($add);
        $this->assertSame('After 1. ' . $name, $this->textOf($this->one($xpath, '//select[@id="ws-position"]/option[@value="after:' . $evil->id . '"]')));
        $error = $this->renderForm(WorkflowStepFormView::forAdd($this->mapOf($workflow), null), new WorkflowStep(), $workflow, true, 'Step not added: ' . $name);
        $this->assertStringContainsString('Step not added: ' . $encoded, $error);
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * Add Step on $workflow's page, as an admin sees it. The regression tests
     * are checked against the 2.10.1 page by overriding this.
     */
    protected function addFormHtml(WorkflowTemplate $workflow): string
    {
        return $this->renderForm(WorkflowStepFormView::forAdd($this->mapOf($workflow), null), new WorkflowStep(), $workflow);
    }

    /**
     * The form in which an admin sets the branches of $step, an approval
     * step of $workflow: Edit Step. The regression test is checked against
     * the 2.10.1 page, whose only step form was Add Step, by overriding this.
     */
    protected function approvalStepFormHtml(WorkflowTemplate $workflow, WorkflowStep $step): string
    {
        return $this->editFormHtml($workflow, $step);
    }

    /**
     * Edit Step for $step as stored, as an admin sees it.
     */
    private function editFormHtml(WorkflowTemplate $workflow, WorkflowStep $step): string
    {
        $models = WorkflowRouteMap::stepsOf($workflow);
        $stored = array_values(array_filter($models, static fn (WorkflowStep $model): bool => (int)$model->id === (int)$step->id))[0];

        return $this->renderForm(WorkflowStepFormView::forEdit(WorkflowRouteMap::fromModels($models), $stored), $stored, $workflow);
    }

    private function renderForm(
        WorkflowStepFormView $form,
        WorkflowStep $model,
        WorkflowTemplate $workflow,
        bool $mayAssignRules = true,
        ?string $error = null
    ): string {
        $rules = ApprovalRule::find()->select(['name', 'id'])->indexBy('id')->column();
        // As the page offers them: job templates that were not deleted.
        $templates = JobTemplate::find()->andWhere(['id' => $this->template->id])->select(['name', 'id'])->indexBy('id')->column();

        return $this->renderStepsPartial('_step-form', [
            'form' => $form,
            'model' => $model,
            'jobTemplateOptions' => $templates,
            'approvalRuleOptions' => $mayAssignRules ? $rules : [],
            'mayAssignRules' => $mayAssignRules,
            'action' => $form->isEdit()
                ? ['/workflow-template/edit-step', 'id' => (int)$workflow->id, 'step' => (int)$model->id]
                : ['/workflow-template/add-step', 'id' => (int)$workflow->id],
            'error' => $error,
        ]);
    }

    private function mapOf(WorkflowTemplate $workflow): WorkflowRouteMap
    {
        return WorkflowRouteMap::forTemplate($workflow);
    }

    /**
     * A workflow with a job step on the test's job template per name, in
     * this order.
     *
     * @param list<string> $names
     */
    private function workflowOf(array $names): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        foreach ($names as $index => $name) {
            $this->namedStep($workflow, $name, ($index + 1) * 10, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        }

        return $workflow;
    }

    private function stepNamed(WorkflowTemplate $workflow, string $name): WorkflowStep
    {
        $step = WorkflowStep::findOne(['workflow_template_id' => $workflow->id, 'name' => $name]);
        $this->assertInstanceOf(WorkflowStep::class, $step, $name);

        return $step;
    }

    private function idOf(WorkflowTemplate $workflow, string $name): int
    {
        return (int)$this->stepNamed($workflow, $name)->id;
    }

    /**
     * The choices of $select, value => text.
     *
     * @return array<int|string, string>
     */
    private function optionsOf(\DOMXPath $xpath, \DOMElement $select): array
    {
        $options = [];
        foreach ($this->all($xpath, './option', $select) as $option) {
            $options[$option->getAttribute('value')] = $this->textOf($option);
        }

        return $options;
    }

    /**
     * The value a browser selects in $select: the selected choice, else the
     * first.
     */
    private function selectedOf(\DOMXPath $xpath, \DOMElement $select): string
    {
        $selected = $this->all($xpath, './option[@selected]', $select);
        $options = $selected === [] ? $this->all($xpath, './option', $select) : $selected;
        $this->assertNotSame([], $options);

        return $options[0]->getAttribute('value');
    }

    /**
     * Whether the part $id of the form is shown, with its select enabled.
     */
    private function assertVisible(\DOMXPath $xpath, string $id, bool $visible): void
    {
        $part = $this->one($xpath, '//div[@id="' . $id . '"]');
        $this->assertSame($visible, !str_contains($part->getAttribute('style'), 'display:none'), $id);
        foreach ($this->all($xpath, './/select', $part) as $select) {
            $this->assertSame(!$visible, $select->hasAttribute('disabled'), $id . ' select');
        }
    }
}
