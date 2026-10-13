<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\WorkflowTemplateController;
use app\models\ApprovalRule;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepReorderService;
use app\services\WorkflowTemplatePage;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\web\Response;

/**
 * Where the add-step form of the workflow template page puts a new step.
 *
 * Regression (fixed in 2.10.1): since 2.3.15 every add and every move
 * renumbers a workflow's steps to 10, 20, 30…, but the form still pre-filled
 * Step Order with the number of steps (0, 1, 2…), which sorts before 10. A
 * step added with that default landed first and became the start step:
 * steps added in turn ran in reverse, and a job step added to a workflow
 * that starts with an approval step ran before it, without approval. A Step
 * Order left empty answered 500, one left out put the step first.
 *
 * Now the field is empty by default, and a Step Order left empty or out puts
 * the step after the current last step, worked out with the workflow locked.
 * A typed number places the step as before.
 */
class WorkflowTemplateStepOrderTest extends WebControllerTestCase
{
    use RendersRealViews;
    use SendsJsonBody;
    use TeamScopeFixtures;

    /** @var array<string, mixed> */
    private array $swapped = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->session->removeAllFlashes();
    }

    protected function tearDown(): void
    {
        foreach ($this->swapped as $id => $original) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    // -- The form's default ---------------------------------------------------------

    /**
     * regression: a step added with the default order landed first. For a
     * workflow with the steps #10 and #20 the form pre-filled 2, and the new
     * step became #10, the start step.
     */
    public function testRegressionAStepAddedWithTheDefaultOrderLandedFirst(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_JOB, $s['own']->id);
        $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_JOB, $s['own']->id);
        $this->loginAs($s['admin']);

        $this->addWithTheFormsDefault($workflow, 'Notify', $this->jobStep($s['own']->id));

        $this->assertSame(['step-10' => 10, 'step-20' => 20, 'Notify' => 30], $this->runOrder($workflow));
        $this->assertSame('step-10', $workflow->getStartStep()?->name, 'runs still start with the first step');
    }

    /**
     * regression: a step added with the default order landed first, so
     * Build, Test and Deploy, added in turn, ran as Deploy, Test, Build.
     */
    public function testRegressionStepsAddedInTurnWithTheDefaultOrderKeepTheirOrder(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->loginAs($s['admin']);

        foreach (['Build', 'Test', 'Deploy'] as $name) {
            $this->addWithTheFormsDefault($workflow, $name, $this->jobStep($s['own']->id));
        }

        $this->assertSame(['Build' => 10, 'Test' => 20, 'Deploy' => 30], $this->runOrder($workflow));
        $this->assertSame('Build', $workflow->getStartStep()?->name);
    }

    /**
     * regression: a job step added with the default order ran before the
     * approval step. With the approval step at #10 the form pre-filled 1:
     * the job step became #10, the start step, and ran without approval.
     */
    public function testRegressionAJobStepAddedWithTheDefaultOrderRanBeforeTheApprovalStep(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule(
            $s['admin']->id,
            ApprovalRule::APPROVER_TYPE_USERS,
            (string)json_encode(['user_ids' => [$s['member']->id]])
        );
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $gate = $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $this->loginAs($s['admin']);

        $this->addWithTheFormsDefault($workflow, 'DB migrate', $this->jobStep($s['own']->id));

        $this->assertSame(['step-10' => 10, 'DB migrate' => 20], $this->runOrder($workflow));
        $this->assertSame((int)$gate->id, (int)$workflow->getStartStep()?->id, 'runs start at the approval step');
        /** @var \app\services\WorkflowStepRouter $router */
        $router = \Yii::$app->get('workflowStepRouter');
        $gate->refresh();
        $this->assertSame('DB migrate', $router->route($gate, true, false)['next']?->name, 'the job step runs once approved');
        $this->assertSame(['next' => null, 'error' => null], $router->route($gate, false, true), 'and not after a refusal');
    }

    // -- A Step Order left empty or out ---------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function emptyStepOrderProvider(): array
    {
        return [
            // regression: answered 500, '' became NULL in a NOT NULL column
            'left empty in the form' => [['step_order' => ''], false],
            // regression: the column's default 0 put the step first
            'left out' => [[], false],
            // regression: answered 500 as an emptied field did
            'null in a JSON body' => [['step_order' => null], true],
            // regression: stored as 0, which put the step first
            'an empty list in a JSON body' => [['step_order' => []], true],
        ];
    }

    /**
     * regression: a Step Order left empty answered 500, and one left out put
     * the step first. Both put it after the current last step now.
     *
     * @dataProvider emptyStepOrderProvider
     * @param array<string, mixed> $order
     */
    public function testAStepOrderLeftEmptyOrOutPutsTheStepAtTheEnd(array $order, bool $json): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_PAUSE);
        $this->loginAs($s['admin']);
        $this->postStep(['name' => 'added', 'step_type' => WorkflowStep::TYPE_PAUSE] + $order, $json);

        $this->makeController()->actionAddStep((int)$workflow->id);

        $this->assertSame(['success' => 'Step "added" added.'], \Yii::$app->session->getAllFlashes(true));
        $this->assertSame(['step-10' => 10, 'step-20' => 20, 'added' => 30], $this->runOrder($workflow));
    }

    /**
     * The end is after the last step whatever the numbering: a workflow
     * without steps, steps numbered by the API (0, 1, 2), the gap a removed
     * step left, and a highest Step Order set by hand to the largest the
     * column holds, where the new step ties and goes after it.
     */
    public function testTheEndIsAfterTheLastStepWhateverTheNumbering(): void
    {
        $s = $this->teamScope();
        $this->loginAs($s['admin']);
        $layouts = [
            'no steps' => [[], ['added' => 10]],
            'numbered by the API' => [[0, 1, 2], ['step-0' => 10, 'step-1' => 20, 'step-2' => 30, 'added' => 40]],
            'a gap' => [[10, 30], ['step-10' => 10, 'step-30' => 20, 'added' => 30]],
            'the largest order' => [[2147483647], ['step-2147483647' => 10, 'added' => 20]],
        ];

        foreach ($layouts as $label => [$orders, $expected]) {
            $workflow = $this->createWorkflowTemplate($s['admin']->id);
            foreach ($orders as $order) {
                $this->createWorkflowStep((int)$workflow->id, $order, WorkflowStep::TYPE_PAUSE);
            }
            $this->postStep(['name' => 'added', 'step_type' => WorkflowStep::TYPE_PAUSE, 'step_order' => ''], false);

            $this->makeController()->actionAddStep((int)$workflow->id);

            $this->assertSame(['success' => 'Step "added" added.'], \Yii::$app->session->getAllFlashes(true), $label);
            $this->assertSame($expected, $this->runOrder($workflow), $label);
        }
    }

    /**
     * Steps added at the same time go one after the other: the end is worked
     * out with the workflow locked, after the add that held the lock first.
     * Read before the lock, it was 12 here (2 + 10): the step landed second.
     */
    public function testTheEndIsWorkedOutWithTheWorkflowLocked(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        foreach ([0, 1, 2] as $order) {
            $this->createWorkflowStep((int)$workflow->id, $order, WorkflowStep::TYPE_PAUSE);
        }
        $this->swap('workflowStepChangeGuard', new class () extends WorkflowStepChangeGuard {
            public function change(WorkflowTemplate $template, int $userId, bool $addsApprovalStep, callable $change): mixed
            {
                return parent::change($template, $userId, $addsApprovalStep, static function () use ($template, $change): mixed {
                    // The add that held the lock first: its step went at the
                    // end, and the steps were renumbered.
                    $first = new WorkflowStep();
                    $first->workflow_template_id = (int)$template->id;
                    $first->name = 'added first';
                    $first->step_type = WorkflowStep::TYPE_PAUSE;
                    $first->step_order = 3;
                    $first->save(false);
                    (new WorkflowStepReorderService())->resequence($template);

                    return $change();
                });
            }
        });
        $this->loginAs($s['admin']);
        $this->postStep(['name' => 'added second', 'step_type' => WorkflowStep::TYPE_PAUSE, 'step_order' => ''], false);

        $this->makeController()->actionAddStep((int)$workflow->id);

        $this->assertSame(['success' => 'Step "added second" added.'], \Yii::$app->session->getAllFlashes(true));
        $this->assertSame(
            ['step-0' => 10, 'step-1' => 20, 'step-2' => 30, 'added first' => 40, 'added second' => 50],
            $this->runOrder($workflow)
        );
    }

    // -- A typed Step Order ---------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function typedStepOrderProvider(): array
    {
        return [
            'between two steps' => ['15', ['step-10', 'added', 'step-20', 'step-30']],
            'the number of a step: after that step' => ['20', ['step-10', 'step-20', 'added', 'step-30']],
            'below the first step' => ['0', ['added', 'step-10', 'step-20', 'step-30']],
            'above the last step' => ['99', ['step-10', 'step-20', 'step-30', 'added']],
        ];
    }

    /**
     * A typed Step Order places the step as before: a number between those
     * of two steps puts it between them, the number of a step after that
     * step. Then the steps are renumbered 10, 20, 30…
     *
     * @dataProvider typedStepOrderProvider
     * @param list<string> $expected
     */
    public function testATypedStepOrderPlacesTheStepAsBefore(string $order, array $expected): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        foreach ([10, 20, 30] as $existing) {
            $this->createWorkflowStep((int)$workflow->id, $existing, WorkflowStep::TYPE_PAUSE);
        }
        $this->loginAs($s['admin']);
        $this->postStep(['name' => 'added', 'step_type' => WorkflowStep::TYPE_PAUSE, 'step_order' => $order], false);

        $this->makeController()->actionAddStep((int)$workflow->id);

        $this->assertSame(['success' => 'Step "added" added.'], \Yii::$app->session->getAllFlashes(true));
        $this->assertSame(array_combine($expected, [10, 20, 30, 40]), $this->runOrder($workflow));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool, 2: string}>
     */
    public static function refusedStepOrderProvider(): array
    {
        return [
            'below 0' => ['-1', false, 'Step Order must be no less than 0.'],
            'not a number' => ['abc', false, 'Step Order must be an integer.'],
            'a fraction' => ['1.5', false, 'Step Order must be an integer.'],
            // regression: answered 500, the value was out of range for the column
            'more than the column holds' => ['2147483648', false, 'Step Order must be no greater than 2147483647.'],
            'true in a JSON body' => [true, true, 'Step Order must be an integer.'],
            // regression: answered 500
            'a number the column cannot hold, in a JSON body' => [3000000000, true, 'Step Order must be no greater than 2147483647.'],
        ];
    }

    /**
     * A Step Order the step cannot have is refused with a danger flash, and
     * nothing is added.
     *
     * @dataProvider refusedStepOrderProvider
     */
    public function testAStepOrderTheStepCannotHaveIsRefused(mixed $order, bool $json, string $message): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
        $this->loginAs($s['admin']);
        $this->postStep(['name' => 'refused', 'step_type' => WorkflowStep::TYPE_PAUSE, 'step_order' => $order], $json);

        $this->makeController()->actionAddStep((int)$workflow->id);

        $this->assertSame(
            ['danger' => WorkflowTemplateController::FLASH_STEP_NOT_ADDED . $message],
            \Yii::$app->session->getAllFlashes(true)
        );
        $this->assertSame(['step-10' => 10], $this->runOrder($workflow));
    }

    // -- The page ----------------------------------------------------------------------

    /**
     * The add-step form leaves Step Order empty, says that empty means at
     * the end, and takes no number below 0; the hint below the steps table
     * says how to place a step.
     */
    public function testTheFormLeavesStepOrderEmptyAndSaysWhereTheStepGoes(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
        $this->loginAs($s['admin']);

        $html = $this->renderPage($workflow);

        $input = $this->stepOrderInput($html);
        $this->assertStringNotContainsString(' value=', $input, 'no value: left empty, the step goes at the end');
        $this->assertStringContainsString(' type="number"', $input);
        $this->assertStringContainsString(' id="workflowstep-step_order"', $input);
        $this->assertStringContainsString(' min="0"', $input);
        $this->assertStringContainsString(' placeholder="' . WorkflowTemplatePage::STEP_ORDER_PLACEHOLDER . '"', $input);
        $this->assertSame([WorkflowTemplatePage::STEP_ORDER_HINT], $this->hints($html));
        $this->assertStringNotContainsString('auto-spaced', $html, 'the old hint is gone');
    }

    /**
     * The hint is shown as before: when the workflow has steps and the user
     * may change them.
     */
    public function testTheHintIsShownOnlyWithStepsTheUserMayChange(): void
    {
        $s = $this->teamScope();
        $this->loginAs($s['admin']);

        $html = $this->renderPage($this->createWorkflowTemplate($s['admin']->id));
        $this->assertSame([], $this->hints($html), 'no steps yet');
        $this->assertStringContainsString(
            ' placeholder="' . WorkflowTemplatePage::STEP_ORDER_PLACEHOLDER . '"',
            $this->stepOrderInput($html),
            'the form is there'
        );

        $running = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->createWorkflowJob((int)$running->id, $s['admin']->id);
        $html = $this->renderPage($running);
        $this->assertSame([], $this->hints($html), 'a run is active');
        $this->assertStringNotContainsString('WorkflowStep[step_order]', $html);

        $this->loginAs($s['member']);
        $html = $this->renderPage($this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id));
        $this->assertSame([], $this->hints($html), 'the user may only view the workflow');
        $this->assertStringNotContainsString('WorkflowStep[step_order]', $html);
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * Add a step as a user who leaves Step Order as the form shows it: render
     * the page, read the value the add-step form pre-fills (none: then a
     * browser posts it empty) and post the form with it.
     *
     * @param array<string, string> $fields the step's type and target
     */
    private function addWithTheFormsDefault(WorkflowTemplate $workflow, string $name, array $fields): void
    {
        $order = $this->prefilledValue($this->stepOrderInput($this->renderPage($workflow)));
        $this->postStep(['name' => $name, 'step_order' => $order] + $fields, false);

        $this->makeController()->actionAddStep((int)$workflow->id);

        $this->assertSame(['success' => "Step \"{$name}\" added."], \Yii::$app->session->getAllFlashes(true));
    }

    /**
     * @return array<string, string> the fields of a job step of $jobTemplateId
     */
    private function jobStep(int $jobTemplateId): array
    {
        return ['step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => (string)$jobTemplateId];
    }

    /**
     * Post the add-step form's fields, as a form does or as a JSON body.
     *
     * @param array<string, mixed> $fields
     */
    private function postStep(array $fields, bool $json): void
    {
        if ($json) {
            $this->sendJson(['WorkflowStep' => $fields]);
            return;
        }
        $this->setPost(['WorkflowStep' => $fields]);
    }

    /**
     * The steps of $workflow in the order runs take them: name => Step Order.
     *
     * @return array<string, int>
     */
    private function runOrder(WorkflowTemplate $workflow): array
    {
        $orders = [];
        $steps = WorkflowStep::find()
            ->where(['workflow_template_id' => $workflow->id])
            ->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
        foreach ($steps as $step) {
            $orders[(string)$step->name] = (int)$step->step_order;
        }

        return $orders;
    }

    /**
     * The Step Order input of the page's add-step form.
     */
    private function stepOrderInput(string $html): string
    {
        $found = preg_match_all('#<input[^>]*\sname="WorkflowStep\[step_order\]"[^>]*>#', $html, $inputs);
        $this->assertSame(1, $found, 'the add-step form has one Step Order');

        return $inputs[0][0];
    }

    /**
     * The value an input is pre-filled with; '' without one.
     */
    private function prefilledValue(string $input): string
    {
        return preg_match('#\svalue="([^"]*)"#', $input, $value) === 1 ? html_entity_decode($value[1]) : '';
    }

    /**
     * The texts of the step order hints below the steps table, whitespace
     * collapsed.
     *
     * @return list<string>
     */
    private function hints(string $html): array
    {
        preg_match_all('#<p class="form-text small mb-3" id="ws-step-order-hint">(.*?)</p>#s', $html, $hints);

        return array_map(
            static fn (string $hint): string => (string)preg_replace('/\s+/', ' ', trim(html_entity_decode(strip_tags($hint)))),
            $hints[1]
        );
    }

    /**
     * The workflow template page as the user sees it.
     */
    private function renderPage(WorkflowTemplate $workflow): string
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $ctrl = new class ('workflow-template', $app) extends WorkflowTemplateController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };

        return $this->withRealViews(
            $ctrl,
            '/workflow-template/view?id=' . $workflow->id,
            static fn (): string => $ctrl->actionView((int)$workflow->id)
        );
    }

    private function makeController(): WorkflowTemplateController
    {
        return new class ('workflow-template', \Yii::$app) extends WorkflowTemplateController {
            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }

    private function swap(string $id, object $replacement): void
    {
        if (!array_key_exists($id, $this->swapped)) {
            $this->swapped[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
        \Yii::$app->set($id, $replacement);
    }
}
