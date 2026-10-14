<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\WorkflowStepPosition;
use app\controllers\WorkflowTemplateController;
use app\models\ApprovalRule;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeSummary;
use app\services\WorkflowStepReorderService;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\web\Response;

/**
 * Where the Add Step form of the workflow template page puts a new step.
 *
 * Regression (fixed in 2.10.1): since 2.3.15 every add and every move
 * renumbers a workflow's steps to 10, 20, 30…, but the form still pre-filled
 * Step Order with the number of steps (0, 1, 2…), which sorts before 10. A
 * step added with that default landed first and became the start step:
 * steps added in turn ran in reverse, and a job step added to a workflow
 * that starts with an approval step ran before it, without approval.
 *
 * Since 2.11 the form has no Step Order. Its Position is "At the end" by
 * default, and the step goes where the Position says, worked out with the
 * workflow locked. A posted step_order (a forged field, or a form of an
 * older page) is ignored, whatever its value: it never decides where the
 * step goes, and never answers 500.
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
     * workflow with two steps the form pre-filled 2, and the new step became
     * the start step. The default Position puts it after the last step.
     */
    public function testRegressionAStepAddedWithTheDefaultPositionLandedFirst(): void
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
    public function testRegressionStepsAddedInTurnWithTheDefaultPositionKeepTheirOrder(): void
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
     * approval step. With the approval step first the form pre-filled 1: the
     * job step became the start step and ran without approval.
     */
    public function testRegressionAJobStepAddedWithTheDefaultPositionRanBeforeTheApprovalStep(): void
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

    // -- A Position left empty or out -----------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function emptyPositionProvider(): array
    {
        return [
            'left empty in the form' => [['position' => ''], false],
            'left out' => [[], false],
            'null in a JSON body' => [['position' => null], true],
        ];
    }

    /**
     * A Position left empty or out puts the step after the current last
     * step.
     *
     * @dataProvider emptyPositionProvider
     * @param array<string, mixed> $position
     */
    public function testAPositionLeftEmptyOrOutPutsTheStepAtTheEnd(array $position, bool $json): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep((int)$workflow->id, 10, WorkflowStep::TYPE_PAUSE);
        $this->createWorkflowStep((int)$workflow->id, 20, WorkflowStep::TYPE_PAUSE);
        $this->loginAs($s['admin']);
        $this->post(['WorkflowStep' => ['name' => 'added', 'step_type' => WorkflowStep::TYPE_PAUSE]] + $position, $json);

        $this->makeController()->actionAddStep((int)$workflow->id);

        $this->assertAdded('added', 3, 3, 'step-20');
        $this->assertSame(['step-10' => 10, 'step-20' => 20, 'added' => 30], $this->runOrder($workflow));
    }

    /**
     * The end is after the last step whatever the numbering: a workflow
     * without steps, steps numbered by the API (0, 1, 2), the gap a removed
     * step left, and a highest step_order set by hand to the largest the
     * column holds.
     */
    public function testTheEndIsAfterTheLastStepWhateverTheNumbering(): void
    {
        $s = $this->teamScope();
        $this->loginAs($s['admin']);
        $layouts = [
            'no steps' => [[], ['added' => 10]],
            'numbered by the API' => [[0, 1, 2], ['step-0' => 10, 'step-1' => 20, 'step-2' => 30, 'added' => 40]],
            'a gap' => [[10, 30], ['step-10' => 10, 'step-30' => 20, 'added' => 30]],
            'the largest order' => [[WorkflowStep::MAX_STEP_ORDER], ['step-' . WorkflowStep::MAX_STEP_ORDER => 10, 'added' => 20]],
        ];

        foreach ($layouts as $label => [$orders, $expected]) {
            $workflow = $this->createWorkflowTemplate($s['admin']->id);
            foreach ($orders as $order) {
                $this->createWorkflowStep((int)$workflow->id, $order, WorkflowStep::TYPE_PAUSE);
            }
            $this->post(['WorkflowStep' => ['name' => 'added', 'step_type' => WorkflowStep::TYPE_PAUSE], 'position' => WorkflowStepPosition::KIND_END], false);

            $this->makeController()->actionAddStep((int)$workflow->id);

            $names = array_keys($expected);
            $this->assertAdded('added', count($names), count($names), $names[count($names) - 2] ?? null);
            $this->assertSame($expected, $this->runOrder($workflow), $label);
        }
    }

    /**
     * Steps added at the same time go one after the other: the end is worked
     * out with the workflow locked, after the add that held the lock first.
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
                    (new WorkflowStepReorderService())->renumber((int)$template->id);

                    return $change();
                });
            }
        });
        $this->loginAs($s['admin']);
        $this->post(['WorkflowStep' => ['name' => 'added second', 'step_type' => WorkflowStep::TYPE_PAUSE]], false);

        $this->makeController()->actionAddStep((int)$workflow->id);

        $this->assertAdded('added second', 5, 5, 'added first');
        $this->assertSame(
            ['step-0' => 10, 'step-1' => 20, 'step-2' => 30, 'added first' => 40, 'added second' => 50],
            $this->runOrder($workflow)
        );
    }

    // -- A posted step_order ----------------------------------------------------------

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function postedStepOrderProvider(): array
    {
        return [
            // regression: a typed Step Order still decided where the step went
            'below the first step' => ['0', false],
            'between two steps' => ['15', false],
            'the order of a step' => ['20', false],
            'above the last step' => ['99', false],
            // regression: answered 500 before 2.10.1
            'left empty' => ['', false],
            'below 0' => ['-1', false],
            'not a number' => ['abc', false],
            'a fraction' => ['1.5', false],
            // regression: answered 500, the value was out of range for the column
            'more than the column holds' => ['2147483648', false],
            'true in a JSON body' => [true, true],
            'null in a JSON body' => [null, true],
            'an empty list in a JSON body' => [[], true],
            // regression: answered 500
            'a number the column cannot hold, in a JSON body' => [3000000000, true],
        ];
    }

    /**
     * regression: a posted Step Order still decided where the step went.
     * The form has no Step Order since 2.11, and a posted one, whatever its
     * value, is ignored: the step goes where the Position says (the end
     * here), and nothing answers 500.
     *
     * @dataProvider postedStepOrderProvider
     */
    public function testRegressionAPostedStepOrderIsIgnoredNeverA500(mixed $order, bool $json): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        foreach ([10, 20, 30] as $existing) {
            $this->createWorkflowStep((int)$workflow->id, $existing, WorkflowStep::TYPE_PAUSE);
        }
        $this->loginAs($s['admin']);
        $this->post(['WorkflowStep' => ['name' => 'added', 'step_type' => WorkflowStep::TYPE_PAUSE, 'step_order' => $order]], $json);

        $this->makeController()->actionAddStep((int)$workflow->id);

        $this->assertSame(['step-10' => 10, 'step-20' => 20, 'step-30' => 30, 'added' => 40], $this->runOrder($workflow));
        $this->assertAdded('added', 4, 4, 'step-30');
    }

    // -- The page ----------------------------------------------------------------------

    /**
     * The Add Step form has no Step Order: its Position offers the end (the
     * default), the start and after each step but the last, which is the
     * end. A workflow without steps has no Position: the new step is the
     * only one.
     */
    public function testTheFormHasNoStepOrderAndAddsAtTheEndByDefault(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
        $steps = $workflow->getSteps()->all();
        $this->loginAs($s['admin']);

        $html = $this->renderPage($workflow);

        $this->assertStringNotContainsString('WorkflowStep[step_order]', $html);
        $this->assertSame(WorkflowStepPosition::KIND_END, $this->defaultPosition($html));
        $this->assertEqualsCanonicalizing(
            [
                WorkflowStepPosition::KIND_END,
                WorkflowStepPosition::KIND_START,
                WorkflowStepPosition::WEB_AFTER_PREFIX . $steps[0]->id,
            ],
            $this->positionOptions($html)
        );

        $empty = $this->renderPage($this->createWorkflowTemplate($s['admin']->id));
        $this->assertStringContainsString('id="add-step-form"', $empty);
        $this->assertSame('', $this->defaultPosition($empty), 'no Position: the new step is the only one');
        $this->assertStringNotContainsString('WorkflowStep[step_order]', $empty);
    }

    /**
     * "+ Insert below" opens the page with the step to place it after
     * (view?id=W&after=S); the form's Position is after that step. A step
     * the workflow does not have changes nothing.
     */
    public function testInsertBelowPreselectsThePositionAfterTheStep(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
        $first = $workflow->getSteps()->one();
        $this->assertInstanceOf(WorkflowStep::class, $first);
        $elsewhere = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id)->getSteps()->one();
        $this->assertInstanceOf(WorkflowStep::class, $elsewhere);
        $this->loginAs($s['admin']);

        $this->assertSame(WorkflowStepPosition::WEB_AFTER_PREFIX . $first->id, $this->defaultPosition($this->renderPage($workflow, (int)$first->id)));
        $this->assertSame(WorkflowStepPosition::KIND_END, $this->defaultPosition($this->renderPage($workflow, (int)$elsewhere->id)));
        $this->assertSame(WorkflowStepPosition::KIND_END, $this->defaultPosition($this->renderPage($workflow, 987654321)));
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * Add a step as a user who leaves Position as the form shows it: render
     * the page, read the Position the add-step form preselects and post the
     * form with it.
     *
     * @param array<string, string> $fields the step's type and target
     */
    private function addWithTheFormsDefault(WorkflowTemplate $workflow, string $name, array $fields): void
    {
        $position = $this->defaultPosition($this->renderPage($workflow));
        $names = array_keys($this->runOrder($workflow));
        $this->post(['WorkflowStep' => ['name' => $name] + $fields, 'position' => $position], false);

        $this->makeController()->actionAddStep((int)$workflow->id);

        $this->assertAdded($name, count($names) + 1, count($names) + 1, $names === [] ? null : $names[count($names) - 1]);
    }

    /**
     * The success flash of an add: the step, its position, the number of
     * steps and the step it comes after (none for the only step).
     */
    private function assertAdded(string $name, int $position, int $count, ?string $after): void
    {
        $flashes = \Yii::$app->session->getAllFlashes(true);
        $where = $after === null ? '' : sprintf(WorkflowStepChangeSummary::AFTER, $after);
        $this->assertStringStartsWith(
            sprintf(WorkflowStepChangeSummary::ADDED, $name, $position, $count, $where),
            (string)($flashes['success'] ?? ''),
            (string)json_encode($flashes)
        );
    }

    /**
     * @return array<string, string> the fields of a job step of $jobTemplateId
     */
    private function jobStep(int $jobTemplateId): array
    {
        return ['step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => (string)$jobTemplateId];
    }

    /**
     * Post the add-step form, as a form does or as a JSON body.
     *
     * @param array<string, mixed> $body
     */
    private function post(array $body, bool $json): void
    {
        if ($json) {
            $this->sendJson($body);
            return;
        }
        $this->setPost($body);
    }

    /**
     * The steps of $workflow in the order runs take them: name => step_order.
     *
     * @return array<string, int>
     */
    private function runOrder(WorkflowTemplate $workflow): array
    {
        $orders = [];
        foreach ($workflow->getSteps()->all() as $step) {
            $orders[(string)$step->name] = (int)$step->step_order;
        }

        return $orders;
    }

    /**
     * The options of the Position select of the page's add-step form; null
     * when the form has none.
     */
    private function positionSelect(string $html): ?string
    {
        $this->assertStringContainsString('id="add-step-form"', $html);
        $found = preg_match_all('#<select\b[^>]*\bname="position"[^>]*>(.*?)</select>#s', $html, $selects);
        $this->assertLessThanOrEqual(1, $found, 'the add-step form has one Position at most');

        return $selects[1][0] ?? null;
    }

    /**
     * The values of the Position options, in order.
     *
     * @return list<string>
     */
    private function positionOptions(string $html): array
    {
        preg_match_all('#<option\b[^>]*\bvalue="([^"]*)"#', (string)$this->positionSelect($html), $values);

        return array_map(static fn (string $value): string => html_entity_decode($value), $values[1]);
    }

    /**
     * The Position a browser posts when the user leaves it: the selected
     * option, else the first one; '' when the form has no Position.
     */
    private function defaultPosition(string $html): string
    {
        $select = (string)$this->positionSelect($html);
        if (preg_match('#<option\b[^>]*\bvalue="([^"]*)"[^>]*\bselected\b#', $select, $selected) === 1) {
            return html_entity_decode($selected[1]);
        }

        return $this->positionOptions($html)[0] ?? '';
    }

    /**
     * The workflow template page as the user sees it.
     */
    private function renderPage(WorkflowTemplate $workflow, ?int $after = null): string
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
            static fn (): string => $ctrl->actionView((int)$workflow->id, $after)
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
