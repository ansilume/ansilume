<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\VersionedAssetUrl;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowStepFormView;
use app\services\WorkflowStepsTable;
use app\tests\integration\TeamScopeFixtures;

/**
 * The steps section of the workflow template page
 * (views/workflow-template/_steps.php), rendered with real views: the
 * success path, the steps table and the legend for everyone who may view the
 * workflow, and the step actions for users who may change its steps.
 *
 * Every route cell names where the branch really leads, by position; the
 * regressions below each failed on the page of 2.10.1, which labelled every
 * empty branch "→ next step" and every other one with the step order
 * ("#20 Deploy"). The steps table is built as the template page builds it
 * (WorkflowRouteMap::stepsOf(), fromModels()).
 */
class WorkflowTemplateStepsTableViewTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use WorkflowStepsViews;

    /** A step ID no step has. */
    private const GONE = 987654;

    private User $admin;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('steps-table', 'admin');
        $this->template = $this->createJobTemplate(
            (int)$this->createProject($this->admin->id)->id,
            (int)$this->createInventory($this->admin->id)->id,
            (int)$this->createRunnerGroup($this->admin->id)->id,
            $this->admin->id
        );
        $this->loginAs($this->admin);
    }

    // -- Markup -------------------------------------------------------------------------

    public function testTheTableScrollsAtPhoneWidthAndItsFirstColumnHoldsPositions(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy']);

        $xpath = $this->xpathOf($this->stepsHtml($workflow, false));

        $table = $this->one($xpath, '//div[@class="table-responsive"]/table[@id="workflow-steps-table"]');
        $headers = $this->all($xpath, './/thead/tr/th', $table);
        $this->assertSame(WorkflowStepsTable::COLUMNS, array_map(fn (\DOMElement $th): string => $this->textOf($th), $headers));
        $this->assertSame(WorkflowStepsTable::POSITION_TITLE, $headers[0]->getAttribute('title'));
        $this->one($xpath, '//h4[@id="workflow-steps"][normalize-space()="' . WorkflowStepsTable::STEPS_HEADING . '"]');
    }

    public function testEachRowNamesItsStepByPositionAndSaysWhereItsBranchesLead(): void
    {
        $rule = $this->createApprovalRule($this->admin->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$this->admin->id]]));
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        $build = $this->namedStep($workflow, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $approve = $this->namedStep($workflow, 'Approve', 20, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $spare = $this->namedStep($workflow, 'Spare', 30, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $this->route($build, ['failure' => (int)$build->id]);
        $this->route($approve, ['success' => 0]);

        $xpath = $this->xpathOf($this->stepsHtml($workflow, false, [(int)$approve->id => 2]));

        $row = $this->rowOf($xpath, $build);
        $this->assertSame('step-' . $build->id, $row->getAttribute('id'));
        $this->assertSame('1', $row->getAttribute('data-position'));
        $this->assertSame('job', $row->getAttribute('data-step-type'));
        $this->assertSame('1 ' . WorkflowStepsTable::START_BADGE, $this->textOf($this->one($xpath, './td[1][@data-position-cell]', $row)));
        $this->assertSame(WorkflowStepsTable::START_TITLE, $this->one($xpath, './/span[@data-start-badge]', $row)->getAttribute('title'));
        $this->assertSame('Build', $this->one($xpath, './td[2]', $row)->textContent, 'the second column holds the bare name');
        $this->assertSame((string)$this->template->name, $this->textOf($this->one($xpath, './td[@data-target-cell]/a', $row)));
        $this->assertSame('/job-template/view?id=' . $this->template->id, $this->one($xpath, './td[@data-target-cell]/a', $row)->getAttribute('href'));
        $this->assertSame(['next', '→ 2. Approve (next)'], $this->cell($xpath, $build, 'success'));
        $this->assertSame(['loop', '↺ 1. Build'], $this->cell($xpath, $build, 'failure'));
        $this->assertSame(
            WorkflowStepsTable::LOOP_TITLE,
            $this->one($xpath, './td[@data-route="failure"]/span', $row)->getAttribute('title')
        );

        $approval = $this->rowOf($xpath, $approve);
        $this->assertSame('2', $this->textOf($this->one($xpath, './td[1]', $approval)), 'no Start badge after the first row');
        $this->assertSame('Approval 2 run(s) waiting', $this->textOf($this->one($xpath, './td[3]', $approval)));
        $this->assertSame((string)$rule->name, $this->textOf($this->one($xpath, './td[@data-target-cell]/a', $approval)));
        $this->assertSame(['end', 'end · run succeeds'], $this->cell($xpath, $approve, 'success'));
        $this->assertSame(['end', 'end · run fails'], $this->cell($xpath, $approve, 'failure'));

        $unreachable = $this->one($xpath, './td[1]/span[@data-unreachable-badge]', $this->rowOf($xpath, $spare));
        $this->assertSame(WorkflowStepsTable::UNREACHABLE_BADGE, $this->textOf($unreachable));
        $this->assertSame(WorkflowStepsTable::UNREACHABLE_TITLE, $unreachable->getAttribute('title'));
    }

    public function testAGatedJobStepSaysItWaitsForAnApprovalAndWhatARefusalDoes(): void
    {
        $rule = $this->createApprovalRule($this->admin->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$this->admin->id]]));
        JobTemplate::updateAll(['approval_rule_id' => $rule->id], ['id' => $this->template->id]);
        $workflow = $this->workflowOf(['Deploy', 'Verify']);
        $deploy = $this->stepNamed($workflow, 'Deploy');

        $xpath = $this->xpathOf($this->stepsHtml($workflow, false));

        $badge = $this->one($xpath, './td[3]/span[@data-gated-badge]', $this->rowOf($xpath, $deploy));
        $this->assertSame(WorkflowStepsTable::GATED_BADGE, $this->textOf($badge));
        $this->assertSame(sprintf(WorkflowStepsTable::GATED_TITLE, $rule->name), $badge->getAttribute('title'));
        $this->assertSame(
            ['mixed', '→ 2. Verify (next), also after a failed job; end · run fails after a refused approval'],
            $this->cell($xpath, $deploy, 'failure')
        );
        $parts = $this->all($xpath, './td[@data-route="failure"]/span', $this->rowOf($xpath, $deploy));
        $this->assertSame(
            [WorkflowStepsTable::NEXT_TITLE, WorkflowStepsTable::REFUSED_APPROVAL_TITLE],
            array_map(static fn (\DOMElement $part): string => $part->getAttribute('title'), $parts)
        );
    }

    /**
     * regression (2.11.1): a job step whose job template had no rule of its
     * own, but whose inventory had one, showed no "needs approval" badge,
     * and its On Failure said only that the run goes on after a failure,
     * though its job waits for the inventory's rule and a refused approval
     * fails the job without running it and ends the run. Now it is a gated
     * job step (C15): the badge names the inventory's rule
     * (GATED_INVENTORY_TITLE), and On Failure has both parts.
     */
    public function testRegressionAJobStepGatedByItsInventoryHadNoBadgeAndIgnoredARefusedApproval(): void
    {
        $rule = $this->createApprovalRule($this->admin->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$this->admin->id]]));
        Inventory::updateAll(['approval_rule_id' => $rule->id], ['id' => $this->template->inventory_id]);
        $workflow = $this->workflowOf(['Deploy', 'Verify']);
        $deploy = $this->stepNamed($workflow, 'Deploy');

        $xpath = $this->xpathOf($this->stepsHtml($workflow, false));

        $badges = $this->all($xpath, './td[3]/span[@data-gated-badge]', $this->rowOf($xpath, $deploy));
        $this->assertCount(1, $badges, 'the step needs approval');
        $this->assertSame(
            ['mixed', '→ 2. Verify (next), also after a failed job; end · run fails after a refused approval'],
            $this->cell($xpath, $deploy, 'failure')
        );
        $this->assertSame(WorkflowStepsTable::GATED_BADGE, $this->textOf($badges[0]));
        $this->assertSame(sprintf(WorkflowStepsTable::GATED_INVENTORY_TITLE, $rule->name), $badges[0]->getAttribute('title'));
        $this->assertSame((string)$this->template->name, $this->textOf($this->one($xpath, './td[@data-target-cell]/a', $this->rowOf($xpath, $deploy))));
    }

    /**
     * A job step whose job template has a rule of its own and whose
     * inventory has another cannot run: its launch is refused, so the
     * Target cell and the badge say so, and the success path ends there.
     */
    public function testAJobStepWhoseTemplateAndInventoryHaveDifferentRulesSaysRunsFailThere(): void
    {
        $own = $this->createApprovalRule($this->admin->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$this->admin->id]]));
        $other = $this->createApprovalRule($this->admin->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$this->admin->id]]));
        JobTemplate::updateAll(['approval_rule_id' => $own->id], ['id' => $this->template->id]);
        Inventory::updateAll(['approval_rule_id' => $other->id], ['id' => $this->template->inventory_id]);
        $workflow = $this->workflowOf(['Deploy', 'Verify']);
        $deploy = $this->stepNamed($workflow, 'Deploy');

        $xpath = $this->xpathOf($this->stepsHtml($workflow, false));

        $row = $this->rowOf($xpath, $deploy);
        $this->assertSame(WorkflowStepsTable::TARGET_RULES_DIFFER, $this->textOf($this->one($xpath, './td[@data-target-cell]/span[@data-target-problem]', $row)));
        $this->assertSame(WorkflowStepsTable::TARGET_RULES_DIFFER, $this->one($xpath, './td[3]/span[@data-gated-badge]', $row)->getAttribute('title'));
        $this->assertSame(['unused', '—'], $this->cell($xpath, $deploy, 'failure'));
        $this->assertSame('1. Deploy cannot run · run fails', $this->successPathOf($xpath));
    }

    public function testAJobStepWhoseTemplateWasDeletedSaysRunsFailThere(): void
    {
        $workflow = $this->workflowOf(['Build']);
        JobTemplate::updateAll(['deleted_at' => time()], ['id' => $this->template->id]);
        $build = $this->stepNamed($workflow, 'Build');

        $xpath = $this->xpathOf($this->stepsHtml($workflow, false));

        $this->assertSame(
            sprintf(WorkflowStepsTable::TARGET_TEMPLATE_GONE, $this->template->id),
            $this->textOf($this->one($xpath, './td[@data-target-cell]/span[@data-target-problem]', $this->rowOf($xpath, $build)))
        );
        $this->assertSame(['unused', '—'], $this->cell($xpath, $build, 'success'));
        $this->assertSame('1. Build cannot run · run fails', $this->successPathOf($xpath));
    }

    public function testEveryoneSeesTheSuccessPathAndTheLegendButOnlyEditorsSeeTheActions(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy']);

        $viewer = $this->xpathOf($this->stepsHtml($workflow, false));
        $editor = $this->xpathOf($this->stepsHtml($workflow, true));

        foreach ([$viewer, $editor] as $xpath) {
            $this->assertSame('1. Build → 2. Deploy → end · run succeeds', $this->successPathOf($xpath));
            $this->assertSame(WorkflowStepsTable::SUCCESS_PATH_LABEL, $this->textOf($this->one($xpath, '//p[@id="wf-success-path"]/span[1]')));
            $this->assertSame(WorkflowStepsTable::LEGEND, $this->textOf($this->one($xpath, '//p[@id="wf-steps-legend"]')));
            $this->assertSame([], $this->all($xpath, '//p[@id="wf-success-path-loop"]'));
        }
        $this->assertSame([], $this->all($viewer, '//*[@data-action]'));
        $this->assertSame([], $this->all($viewer, '//form'));
        $this->assertCount(7, $this->all($viewer, '//thead/tr/th'), 'no actions column');
        $this->assertSame(WorkflowStepsTable::ACTIONS_COLUMN, $this->textOf($this->all($editor, '//thead/tr/th')[7]));
        $this->assertCount(12, $this->all($editor, '//*[@data-action]'), 'six actions per row');
    }

    public function testATableWithoutActionsShowsNoneEvenToAnEditor(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy']);
        $table = $this->stepsTableOf($workflow, false);

        $html = $this->renderStepsPartial('_steps', ['model' => $workflow, 'stepsTable' => $table, 'canChangeSteps' => true]);

        $this->assertSame([], $this->all($this->xpathOf($html), '//*[@data-action]'));
    }

    public function testALoopingSuccessPathIsExplained(): void
    {
        $workflow = $this->workflowOf(['Build', 'Poll']);
        $this->route($this->stepNamed($workflow, 'Poll'), ['success' => (int)$this->stepNamed($workflow, 'Build')->id]);

        $xpath = $this->xpathOf($this->stepsHtml($workflow, false));

        $this->assertSame('1. Build → 2. Poll → ↺ 1. Build', $this->successPathOf($xpath));
        $this->assertSame(WorkflowStepsTable::SUCCESS_PATH_LOOP_NOTE, $this->textOf($this->one($xpath, '//p[@id="wf-success-path-loop"]')));
    }

    public function testAWorkflowWithoutStepsSaysSo(): void
    {
        $workflow = $this->createWorkflowTemplate($this->admin->id);

        $xpath = $this->xpathOf($this->stepsHtml($workflow, true));

        $this->assertSame(WorkflowStepsTable::NO_STEPS, $this->textOf($this->one($xpath, '//div[@id="wf-no-steps"]')));
        $this->assertSame([], $this->all($xpath, '//table|//p[@id="wf-success-path"]|//p[@id="wf-steps-legend"]'));
    }

    // -- Actions ------------------------------------------------------------------------

    public function testTheActionsEditInsertMoveAndRemoveTheStepTheyName(): void
    {
        $workflow = $this->workflowOf(['Build', 'Test', 'Deploy']);
        $test = $this->stepNamed($workflow, 'Test');
        $id = (int)$workflow->id;

        $xpath = $this->xpathOf($this->stepsHtml($workflow, true));
        $row = $this->rowOf($xpath, $test);

        $edit = $this->one($xpath, './/a[@data-action="edit"]', $row);
        $this->assertSame("/workflow-template/edit-step?id={$id}&step={$test->id}", $edit->getAttribute('href'));
        $this->assertSame('Edit step 2 "Test"', $edit->getAttribute('aria-label'));
        $this->assertSame(WorkflowStepsTable::EDIT, $this->textOf($edit));
        $insert = $this->one($xpath, './/a[@data-action="insert-below"]', $row);
        $this->assertSame("/workflow-template/view?id={$id}&after={$test->id}#add-step", $insert->getAttribute('href'));
        $this->assertSame('Insert a step below step 2 "Test"', $insert->getAttribute('aria-label'));
        foreach (['up' => '▲', 'down' => '▼'] as $direction => $symbol) {
            $button = $this->one($xpath, './/button[@data-action="move-' . $direction . '"]', $row);
            $form = $button->parentNode;
            $this->assertInstanceOf(\DOMElement::class, $form);
            $this->assertSame("/workflow-template/move-step?id={$id}", $form->getAttribute('action'));
            $this->assertSame((string)$test->id, $this->one($xpath, './input[@name="step_id"]', $form)->getAttribute('value'));
            $this->assertSame($direction, $this->one($xpath, './input[@name="direction"]', $form)->getAttribute('value'));
            $this->assertSame("Move step 2 \"Test\" {$direction}", $button->getAttribute('aria-label'));
            $this->assertSame($symbol, $this->textOf($button));
            $this->assertFalse($button->hasAttribute('disabled'));
        }
        $remove = $this->one($xpath, './/button[@data-action="remove"]', $row);
        $this->assertSame('Remove step 2 "Test"?', $remove->getAttribute('data-confirm'));
        $this->assertSame('Remove step 2 "Test"', $remove->getAttribute('aria-label'));
        $this->assertSame("/workflow-template/remove-step?id={$id}", $this->one($xpath, './ancestor::form', $remove)->getAttribute('action'));
    }

    public function testTheFirstAndLastRowsCannotMoveFurther(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy']);

        $xpath = $this->xpathOf($this->stepsHtml($workflow, true));

        $up = $this->one($xpath, './/button[@data-action="move-up"]', $this->rowOf($xpath, $this->stepNamed($workflow, 'Build')));
        $down = $this->one($xpath, './/button[@data-action="move-down"]', $this->rowOf($xpath, $this->stepNamed($workflow, 'Deploy')));
        foreach ([$up, $down] as $button) {
            $this->assertTrue($button->hasAttribute('disabled'));
            $this->assertStringContainsString('opacity-25', $button->getAttribute('class'));
        }
    }

    public function testMoveToPostsTheChosenPositionInOneClickAndWarnsFirst(): void
    {
        $rule = $this->createApprovalRule($this->admin->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$this->admin->id]]));
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        $this->namedStep($workflow, 'Approve', 10, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $deploy = $this->namedStep($workflow, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $verify = $this->namedStep($workflow, 'Verify', 30, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $this->enableCsrf();

        $xpath = $this->xpathOf($this->stepsHtml($workflow, true));

        $menu = $this->one($xpath, './/details[@data-action="move-to"]', $this->rowOf($xpath, $verify));
        $this->assertSame('Move step 3 "Verify" to another position', $this->one($xpath, './summary', $menu)->getAttribute('aria-label'));
        $form = $this->one($xpath, './form', $menu);
        $this->assertSame('/workflow-template/move-step?id=' . $workflow->id, $form->getAttribute('action'));
        $this->assertSame('post', $form->getAttribute('method'));
        $this->assertSame((string)$verify->id, $this->one($xpath, './input[@name="step_id"]', $form)->getAttribute('value'));
        $this->assertNotSame('', $this->one($xpath, './input[@name="_csrf"]', $form)->getAttribute('value'));
        $targets = [];
        foreach ($this->all($xpath, './div[@class="wf-move-target"]', $form) as $target) {
            $button = $this->one($xpath, './button[@name="position"]', $target);
            $targets[$button->getAttribute('value')] = array_merge(
                [$this->textOf($button)],
                array_map(fn (\DOMElement $hint): string => $this->textOf($hint), $this->all($xpath, './small[@data-move-hint]', $target))
            );
        }
        $this->assertSame([
            'start' => [
                'To the start',
                'Runs will start with "Verify" instead of "Approve".',
                'Job step "Verify" will run before the approval step "Approve", without waiting for it.',
            ],
            'after:' . $this->stepNamed($workflow, 'Approve')->id => ['After 1. Approve'],
        ], $targets, 'after Deploy, right above, it is already; the end it is at');
        $forms = $this->all($xpath, './/form', $this->rowOf($xpath, $deploy));
        $this->assertCount(4, $forms, '▲, ▼, Move to… and Remove');
        foreach ($forms as $rowForm) {
            $this->assertNotSame('', $this->one($xpath, './input[@name="_csrf"]', $rowForm)->getAttribute('value'));
        }
    }

    /**
     * regression: Move to… gave no hint where an approval step is skipped:
     * after a step whose On Success names the next step, every run that
     * succeeds goes past it.
     */
    public function testRegressionMoveToSaysWhereRunsThatSucceedSkipTheApprovalStep(): void
    {
        $rule = $this->createApprovalRule($this->admin->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$this->admin->id]]));
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        $build = $this->namedStep($workflow, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $deploy = $this->namedStep($workflow, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $approve = $this->namedStep($workflow, 'Approve', 30, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $this->route($build, ['success' => (int)$deploy->id, 'failure' => 0]);

        $xpath = $this->xpathOf($this->stepsHtml($workflow, true));

        $target = $this->one(
            $xpath,
            './/details[@data-action="move-to"]//button[@name="position"][@value="after:' . $build->id . '"]/parent::div',
            $this->rowOf($xpath, $approve)
        );
        $this->assertSame(
            ['The approval step "Approve" will not be on the success path: runs whose steps all succeed will not wait for it.'],
            array_map(fn (\DOMElement $hint): string => $this->textOf($hint), $this->all($xpath, './small[@data-move-hint]', $target))
        );
    }

    /**
     * regression: every row's Move to… listed every other place, so the
     * page grew with the square of the steps. Above MOVE_MENU_LIMIT steps,
     * Move to… links to the step's edit page, at its Position field; ▲ and
     * ▼ stay.
     */
    public function testRegressionALongTableLinksMoveToToTheEditPage(): void
    {
        // One step more than WorkflowStepsTable::MOVE_MENU_LIMIT (20).
        $names = array_map(static fn (int $n): string => 'Step ' . $n, range(1, 21));
        $workflow = $this->workflowOf($names);
        $second = $this->stepNamed($workflow, 'Step 2');
        $id = (int)$workflow->id;

        $xpath = $this->xpathOf($this->stepsHtml($workflow, true));

        $this->assertSame([], $this->all($xpath, '//details[@data-action="move-to"]'), 'no menus');
        $this->assertSame([], $this->all($xpath, '//button[@name="position"]'));
        $this->assertCount(count($names), $this->all($xpath, '//a[@data-action="move-to"]'), 'one link per row');
        $row = $this->rowOf($xpath, $second);
        $link = $this->one($xpath, './/a[@data-action="move-to"]', $row);
        $this->assertSame("/workflow-template/edit-step?id={$id}&step={$second->id}#ws-position-field", $link->getAttribute('href'));
        $this->assertSame('ws-position-field', WorkflowStepFormView::POSITION_FIELD_ID);
        $this->assertSame(WorkflowStepsTable::MOVE_TO, $this->textOf($link));
        $this->assertSame('Move step 2 "Step 2" to another position', $link->getAttribute('aria-label'));
        $this->assertSame(
            'Choose the place on the page of the step: its Position field says what each place would change.',
            $link->getAttribute('title')
        );
        $this->one($xpath, './/button[@data-action="move-up"]', $row);
        $this->one($xpath, './/button[@data-action="move-down"]', $row);

        $atTheLimit = $this->xpathOf($this->stepsHtml($this->workflowOf(array_slice($names, 1)), true));
        $this->assertCount(20, $this->all($atTheLimit, '//details[@data-action="move-to"]'), 'up to the limit, menus');
        $this->assertSame([], $this->all($atTheLimit, '//a[@data-action="move-to"]'));
    }

    public function testTheStylesheetLoadsWithTheVersionOfItsContent(): void
    {
        $html = $this->stepsHtml($this->workflowOf(['Build']), true);

        $stylesheet = VersionedAssetUrl::of('/css/workflow-steps.css');
        $this->assertMatchesRegularExpression('#^/css/workflow-steps\.css\?v=[0-9a-f]{12}$#', $stylesheet);
        $this->assertStringContainsString('<link href="' . $stylesheet . '" rel="stylesheet">', $html);
    }

    // -- XSS ----------------------------------------------------------------------------

    public function testAStepNameComesOutEncodedEverywhere(): void
    {
        $name = '<img src=x onerror=alert(1)> "q" \'a\'';
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        $evil = $this->namedStep($workflow, $name, 10, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $this->namedStep($workflow, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $this->namedStep($workflow, 'Verify', 30, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $this->route($this->stepNamed($workflow, 'Deploy'), ['failure' => (int)$evil->id]);

        $html = $this->stepsHtml($workflow, true);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('"q"', $html, 'quotes are encoded in text and attributes');
        $this->assertStringNotContainsString("'a'", $html);
        $encoded = '&lt;img src=x onerror=alert(1)&gt; &quot;q&quot; &#039;a&#039;';
        $this->assertStringContainsString('<td>' . $encoded . '</td>', $html, 'the name cell');
        $this->assertStringContainsString('1. ' . $encoded . ' → 2. Deploy', $html, 'the success path');
        $this->assertStringContainsString('↺ 1. ' . $encoded . '</span>', $html, 'a route cell');
        $this->assertStringContainsString('aria-label="Edit step 1 ' . '&quot;' . $encoded . '&quot;"', $html);
        $this->assertStringContainsString('data-confirm="Remove step 1 &quot;' . $encoded . '&quot;?"', $html);
        $this->assertStringContainsString('>After 1. ' . $encoded . '</button>', $html, 'a Move to… target');
        $this->assertStringContainsString('Runs will start with &quot;Deploy&quot; instead of &quot;' . $encoded . '&quot;.', $html, 'a hint');
        $xpath = $this->xpathOf($html);
        $this->assertSame($name, $this->one($xpath, './td[2]', $this->rowOf($xpath, $evil))->textContent, 'shown as typed');
    }

    // -- Regressions (each failed on the 2.10.1 page) -----------------------------------

    /**
     * regression: the last step's empty branches read "→ next step" where
     * the run ends.
     */
    public function testRegressionTheLastStepsEmptyBranchesSayTheRunEnds(): void
    {
        $workflow = $this->workflowOf(['Build', 'Cleanup']);

        $xpath = $this->xpathOf($this->stepsHtml($workflow, false));

        $cleanup = $this->stepNamed($workflow, 'Cleanup');
        $this->assertSame(WorkflowStepsTable::ROUTE_END_SUCCEEDS, $this->cellText($xpath, $cleanup, 'success'));
        $this->assertSame(WorkflowStepsTable::ROUTE_END_FAILS, $this->cellText($xpath, $cleanup, 'failure'));
    }

    /**
     * regression: a pause step showed an On Failure route it never uses.
     */
    public function testRegressionAPauseStepShowsNoOnFailureRoute(): void
    {
        $workflow = $this->createWorkflowTemplate($this->admin->id);
        $this->namedStep($workflow, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->template->id);
        $wait = $this->namedStep($workflow, 'Wait', 20, WorkflowStep::TYPE_PAUSE);
        $this->namedStep($workflow, 'Deploy', 30, WorkflowStep::TYPE_JOB, (int)$this->template->id);

        $xpath = $this->xpathOf($this->stepsHtml($workflow, false));

        $failure = $this->one($xpath, './td[@data-route="failure"]', $this->rowOf($xpath, $wait));
        $this->assertSame(WorkflowStepsTable::ROUTE_UNUSED, $this->textOf($failure));
        $this->assertSame(WorkflowStepsTable::PAUSE_FAILURE_TITLE, $this->one($xpath, './span', $failure)->getAttribute('title'));
    }

    /**
     * regression: route labels showed the shifting sort key: a branch to
     * the second step read "#20 Deploy".
     */
    public function testRegressionABranchNamesThePositionOfItsStep(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy']);
        $build = $this->stepNamed($workflow, 'Build');
        $this->route($build, ['failure' => (int)$this->stepNamed($workflow, 'Deploy')->id]);

        $xpath = $this->xpathOf($this->stepsHtml($workflow, false));

        $this->assertSame('→ 2. Deploy', $this->cellText($xpath, $build, 'failure'));
    }

    /**
     * regression: a branch to a step that no longer exists showed its bare
     * ID ("#7"), like a step number.
     */
    public function testRegressionABranchToAMissingStepSaysTheRunFails(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy']);
        $build = $this->stepNamed($workflow, 'Build');
        $this->route($build, ['success' => self::GONE]);

        $xpath = $this->xpathOf($this->stepsHtml($workflow, false));

        $this->assertSame(sprintf(WorkflowStepsTable::ROUTE_MISSING, self::GONE), $this->cellText($xpath, $build, 'success'));
        $this->assertSame(
            WorkflowStepsTable::MISSING_TITLE,
            $this->one($xpath, './td[@data-route="success"]/span', $this->rowOf($xpath, $build))->getAttribute('title')
        );
    }

    /**
     * regression: On Success and On Failure looked used although Always
     * overrides them.
     */
    public function testRegressionOnSuccessAndOnFailureAreUnusedWhenAlwaysIsSet(): void
    {
        $workflow = $this->workflowOf(['Build', 'Test', 'Deploy']);
        $build = $this->stepNamed($workflow, 'Build');
        $this->route($build, ['success' => 0, 'always' => (int)$this->stepNamed($workflow, 'Deploy')->id]);

        $xpath = $this->xpathOf($this->stepsHtml($workflow, false));

        foreach (['success', 'failure'] as $route) {
            $cell = $this->one($xpath, './td[@data-route="' . $route . '"]', $this->rowOf($xpath, $build));
            $this->assertSame(WorkflowStepsTable::ROUTE_UNUSED, $this->textOf($cell), $route);
            $this->assertSame(WorkflowStepsTable::ALWAYS_WINS_TITLE, $this->one($xpath, './span', $cell)->getAttribute('title'));
        }
        $this->assertSame('→ 3. Deploy', $this->cellText($xpath, $build, 'always'));
    }

    /**
     * regression: a job step's empty On Failure read "→ next step" and did
     * not say that the run goes on after a failure.
     */
    public function testRegressionAJobStepsEmptyOnFailureSaysTheRunGoesOnAfterAFailure(): void
    {
        $workflow = $this->workflowOf(['Build', 'Deploy']);

        $xpath = $this->xpathOf($this->stepsHtml($workflow, false));

        $this->assertSame(
            '→ 2. Deploy (next)' . WorkflowStepsTable::ALSO_AFTER_FAILURE,
            $this->cellText($xpath, $this->stepNamed($workflow, 'Build'), 'failure')
        );
    }

    /**
     * regression: every ▲ and ▼ had the same aria-label ("Move step up"),
     * so a screen reader could not tell which step a button moves.
     */
    public function testRegressionEveryMoveButtonNamesItsStep(): void
    {
        $workflow = $this->workflowOf(['Build', 'Test', 'Deploy']);

        $xpath = $this->xpathOf($this->stepsHtml($workflow, true));

        $labels = array_map(
            static fn (\DOMElement $button): string => $button->getAttribute('aria-label'),
            $this->all($xpath, '//button[@data-action="move-up" or @data-action="move-down"]')
        );
        $this->assertSame([
            'Move step 1 "Build" up',
            'Move step 1 "Build" down',
            'Move step 2 "Test" up',
            'Move step 2 "Test" down',
            'Move step 3 "Deploy" up',
            'Move step 3 "Deploy" down',
        ], $labels);
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * The steps section of $workflow's page for the logged-in user, who may
     * change its steps when $canChangeSteps (then the table has the
     * actions). The regression tests are checked against the 2.10.1 page by
     * overriding this.
     *
     * @param array<int, int> $waitingSteps
     */
    protected function stepsHtml(WorkflowTemplate $workflow, bool $canChangeSteps, array $waitingSteps = []): string
    {
        return $this->renderStepsPartial('_steps', [
            'model' => $workflow,
            'stepsTable' => $this->stepsTableOf($workflow, $canChangeSteps, $waitingSteps),
            'canChangeSteps' => $canChangeSteps,
        ]);
    }

    /**
     * A workflow with a job step on the test's job template per name, in
     * this order, with empty branches.
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

    private function rowOf(\DOMXPath $xpath, WorkflowStep $step): \DOMElement
    {
        return $this->one($xpath, '//table[@id="workflow-steps-table"]/tbody/tr[@data-step-id="' . $step->id . '"]');
    }

    /**
     * The text of $step's $route cell.
     */
    private function cellText(\DOMXPath $xpath, WorkflowStep $step, string $route): string
    {
        return $this->textOf($this->one($xpath, './td[@data-route="' . $route . '"]', $this->rowOf($xpath, $step)));
    }

    /**
     * The kind and the text of $step's $route cell.
     *
     * @return array{0: string, 1: string}
     */
    private function cell(\DOMXPath $xpath, WorkflowStep $step, string $route): array
    {
        $cell = $this->one($xpath, './td[@data-route="' . $route . '"]', $this->rowOf($xpath, $step));

        return [$cell->getAttribute('data-route-kind'), $this->textOf($cell)];
    }

    private function successPathOf(\DOMXPath $xpath): string
    {
        return $this->textOf($this->one($xpath, '//p[@id="wf-success-path"]/span[@data-success-path]'));
    }

    /**
     * Forms carry the CSRF token, as on a real request.
     */
    private function enableCsrf(): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(\yii\web\Request::class, $request);
        $request->enableCsrfValidation = true;
    }
}
