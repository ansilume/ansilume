<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\components\WorkflowStepCommand;
use app\models\WorkflowStep;
use app\services\WorkflowStepFormView;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowStepFormView (contract C9): what the step form offers and selects,
 * on steps built in memory.
 *
 * Add Step puts a new step at the end by default, or after the step of "+
 * Insert below"; Edit Step keeps the step where it is and offers only the
 * places that move it. Every Position choice carries where the step would
 * run, the warnings it would cause and what "Next in the list" would be,
 * worked out from the branches as runs follow them. A new job step ends the
 * workflow when it fails; an approval step's On Failure has one end choice;
 * Edit Step offers every step as a branch target, the step itself included.
 */
class WorkflowStepFormViewTest extends TestCase
{
    use WorkflowStepModels;

    public function testTheTextsKeepTheirWording(): void
    {
        $this->assertSame(
            [
                'ADD_HEADING' => 'Add Step',
                'ADD_SUBMIT' => 'Add Step',
                'EDIT_HEADING' => 'Edit %s',
                'EDIT_SUBMIT' => 'Save Step',
                'CANCEL' => 'Cancel',
                'NAME_LABEL' => 'Name',
                'NAME_PLACEHOLDER' => 'Step name',
                'TYPE_LABEL' => 'Step Type',
                'JOB_TEMPLATE_LABEL' => 'Job Template',
                'JOB_TEMPLATE_PROMPT' => '— select a job template —',
                'APPROVAL_RULE_LABEL' => 'Approval Rule',
                'APPROVAL_RULE_PROMPT' => '— select an approval rule —',
                'POSITION_LABEL' => 'Position',
                'POSITION_FIELD_ID' => 'ws-position-field',
                'POSITION_END' => 'At the end (after %s)',
                'POSITION_START' => 'At the start (runs will start with this step)',
                'POSITION_AFTER' => 'After %s',
                'POSITION_KEEP' => 'Keep at position %d',
                'FIRST_STEP_NOTE' => 'The first step: runs will start with it.',
                'PREVIEW_START' => 'Runs first, before "%s".',
                'PREVIEW_FIRST' => 'Runs first.',
                'PREVIEW_BETWEEN' => 'Runs after "%1$s", before "%2$s".',
                'PREVIEW_END' => 'Runs last, after "%s".',
                'PREVIEW_AFTER' => 'Runs after "%s".',
                'PREVIEW_ONLY' => 'Runs as the only step.',
                'PREVIEW_SKIPPED_TO' => 'Listed after "%1$s", but a successful "%1$s" goes on with "%2$s", skipping this step.',
                'PREVIEW_SKIPPED_END' => 'Listed after "%1$s", but runs end when "%1$s" succeeds, skipping this step.',
                'PREVIEW_AFTER_BROKEN' => 'Listed after "%s", which cannot run: a run fails there instead of going on to this step.',
                'WILL_START_THIS' => 'Runs will start with this step instead of "%s".',
                'WILL_START_OTHER' => 'Runs will start with "%s" instead of this step.',
                'THIS_WILL_PRECEDE_APPROVAL' => 'This job step will run before the approval step "%s", without waiting for it.',
                'WILL_PRECEDE_THIS_APPROVAL' => 'Job step "%s" will run before this approval step, without waiting for it.',
                'THIS_APPROVAL_OFF_PATH' => 'This approval step will not be on the success path: '
                    . 'runs whose steps all succeed will not wait for it.',
                'SUCCESS_LABEL' => 'On Success → go to',
                'SUCCESS_LABEL_PAUSE' => 'After Resume → go to',
                'FAILURE_LABEL' => 'On Failure → go to',
                'ALWAYS_LABEL' => 'Always → go to (overrides success/failure)',
                'NEXT_OPTION' => 'Next in the list: "%s"',
                'NEXT_OPTION_NONE_SUCCEEDS' => 'Next in the list: none, the run succeeds',
                'NEXT_FAILURE_OPTION' => 'Next in the list: "%s", also after a failure',
                'NEXT_OPTION_NONE_FAILS' => 'Next in the list: none, the run fails',
                'END_SUCCEEDS_OPTION' => 'End the workflow: the run succeeds',
                'END_FAILS_OPTION' => 'End the workflow: the run fails',
                'ALWAYS_NONE_OPTION' => 'Not set: On Success or On Failure decides',
                'THIS_STEP_OPTION' => '%s (this step)',
                'FAILURE_DEFAULT_NOTE' => 'A new job step ends the workflow when it fails. Choose "Next in the list" to go on '
                    . 'instead.',
                'TEMPLATE_GONE_HINT' => 'The job template of this step no longer exists: choose another one.',
                'LEGACY_ALWAYS_NOTE' => 'This step has an Always branch from an older version, which runs follow when it '
                    . 'succeeds. It shows under On Success now and is saved there.',
                'ROUTED_BY_TITLE' => 'Steps that route here',
                'ROUTED_BY_NONE' => 'No other step routes here.',
                'ROUTED_BY_ALWAYS_WINS' => 'Not used: its Always branch is taken instead.',
                'ROUTED_BY_PAUSE_FAILURE' => 'Not used: a pause step does not fail. Saving that step clears its On Failure.',
                'ROUTED_BY_CANNOT_RUN' => 'Not used: that step cannot run.',
                'ROUTED_BY_UNKNOWN_TYPE' => 'Not used: that step has an unknown type and cannot be saved. Remove it first.',
                'EXTRA_VARS_READONLY' => 'Variable mapping (change it through the API):',
                'HELP_JOB_TITLE' => 'Job step:',
                'HELP_JOB' => 'success = exit code 0. Failure = any non-zero exit, timeout, or unreachable host. Always '
                    . 'overrides both. If the job needs approval (an approval rule of its job template or of its inventory) '
                    . 'and the approval is rejected, times out or is canceled, the job does not run: then only On Failure is '
                    . 'followed (left empty, the run ends as failed), and Always is not.',
                'HELP_APPROVAL_TITLE' => 'Approval step:',
                'HELP_APPROVAL' => 'success = approved; failure = rejected, timed out or canceled. After a failure the run '
                    . 'ends as failed unless On Failure names a step. An approval step has no Always route: it would continue '
                    . 'past a rejection.',
                'HELP_PAUSE_TITLE' => 'Pause step:',
                'HELP_PAUSE' => 'pauses only resume via the operator clicking Resume, which counts as success — so only the '
                    . '"After Resume → go to" field applies. On Failure and Always are ignored for pause.',
                'ADD_FORM_ID' => 'add-step-form',
                'EDIT_FORM_ID' => 'edit-step-form',
            ],
            (new \ReflectionClass(WorkflowStepFormView::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC)
        );
    }

    // -- Add Step: the Position ---------------------------------------------------------

    public function testAddStepPutsANewStepAtTheEndByDefault(): void
    {
        $form = WorkflowStepFormView::forAdd(self::mapOf(self::buildApproveDeploy()), null);

        $position = $form->position(new WorkflowStep());

        $this->assertNotNull($position);
        $this->assertSame([
            'end' => 'At the end (after 3. Deploy)',
            'start' => 'At the start (runs will start with this step)',
            'after:1' => 'After 1. Build',
            'after:2' => 'After 2. Approve (approval step)',
        ], $position['items']);
        $this->assertSame('end', $position['selected']);
        $this->assertSame('Runs last, after "Deploy".', $position['preview']);
        $this->assertSame('', $position['warning'], 'a job step at the end comes after the approval step');
        $this->assertFalse($form->isEdit());
        $this->assertSame('add-step-form', $form->formId());
        $this->assertSame('Add Step', $form->heading());
        $this->assertSame('Add Step', $form->submitLabel());
        $this->assertNull($form->cancelRoute());
        $this->assertFalse($form->hasAutofocus());
        $this->assertNull($form->typeHint());
    }

    public function testEveryAddChoiceSaysWhereTheStepWouldRunAndWhatItWouldCause(): void
    {
        $position = WorkflowStepFormView::forAdd(self::mapOf(self::buildApproveDeploy()), null)->position(new WorkflowStep());

        $this->assertNotNull($position);
        $this->assertSame([
            'end' => self::choice('Runs last, after "Deploy".', '', '', null),
            'start' => self::choice(
                'Runs first, before "Build".',
                'Runs will start with this step instead of "Build".',
                'This job step will run before the approval step "Approve", without waiting for it.',
                'Build'
            ),
            'after:1' => self::choice(
                'Runs after "Build", before "Approve".',
                '',
                'This job step will run before the approval step "Approve", without waiting for it.',
                'Approve'
            ),
            'after:2' => self::choice('Runs after "Approve", before "Deploy".', '', '', 'Deploy'),
        ], $position['options']);
    }

    public function testInsertBelowSelectsThePlaceAfterTheStepAndFocusesTheName(): void
    {
        $form = WorkflowStepFormView::forAdd(self::mapOf(self::buildApproveDeploy()), 1);

        $job = $form->position(new WorkflowStep());
        $pause = $form->position(self::pause(9, 'new'));

        $this->assertTrue($form->hasAutofocus());
        $this->assertSame('after:1', $job['selected'] ?? null);
        $this->assertSame('Runs after "Build", before "Approve".', $job['preview'] ?? null);
        $this->assertSame(
            'This job step will run before the approval step "Approve", without waiting for it.',
            $job['warning'] ?? null,
            'a job step there runs without waiting for the approval'
        );
        $this->assertSame('', $pause['warning'] ?? null, 'a pause step there waits for no approval');
    }

    /**
     * regression: the preview and the warnings of a Position went by
     * position only. An approval step placed after a step whose On Success
     * names the next step read 'Runs after "Build", before "Deploy".', with
     * no warning, while every run that succeeds goes from Build to Deploy.
     */
    public function testRegressionAPlaceNoSuccessLeadsToSaysSoAndWarnsAnApprovalStep(): void
    {
        $form = WorkflowStepFormView::forAdd(self::mapOf([self::job(1, 'Build', ['success' => 2]), self::job(2, 'Deploy')]), 1);
        $skipped = 'Listed after "Build", but a successful "Build" goes on with "Deploy", skipping this step.';
        $offPath = 'This approval step will not be on the success path: runs whose steps all succeed will not wait for it.';

        $approval = $form->position(self::stepModel(9, 'new', 'approval'));
        $job = $form->position(new WorkflowStep());

        $this->assertSame('after:1', $approval['selected'] ?? null);
        $this->assertSame($skipped, $approval['preview'] ?? null);
        $this->assertSame($offPath, $approval['warning'] ?? null);
        $this->assertSame($skipped, $job['preview'] ?? null);
        $this->assertSame('', $job['warning'] ?? null, 'the approval warning is for an approval step only');
        $this->assertSame([
            'end' => self::choice('Runs last, after "Deploy".', '', '', null),
            'start' => self::choice('Runs first, before "Build".', 'Runs will start with this step instead of "Build".', '', 'Build'),
            'after:1' => self::choice($skipped, '', '', 'Deploy', $offPath),
        ], $approval['options'] ?? null);
    }

    public function testAnApprovalStepPlacedOnTheSuccessPathGetsNoWarning(): void
    {
        $form = WorkflowStepFormView::forAdd(self::mapOf(self::buildApproveDeploy()), 1);

        $this->assertSame('', $form->position(self::stepModel(9, 'new', 'approval'))['warning'] ?? null);
    }

    public function testInsertBelowTheLastStepSelectsTheEnd(): void
    {
        $form = WorkflowStepFormView::forAdd(self::mapOf(self::buildApproveDeploy()), 3);

        $this->assertSame('end', $form->position(new WorkflowStep())['selected'] ?? null);
        $this->assertTrue($form->hasAutofocus());
    }

    public function testInsertBelowAStepTheWorkflowDoesNotHaveIsIgnored(): void
    {
        $form = WorkflowStepFormView::forAdd(self::mapOf(self::buildApproveDeploy()), 99);

        $this->assertSame('end', $form->position(new WorkflowStep())['selected'] ?? null);
        $this->assertFalse($form->hasAutofocus());
    }

    public function testTheFirstStepHasNoPosition(): void
    {
        $form = WorkflowStepFormView::forAdd(self::mapOf([]), null);

        $routes = $form->routes(new WorkflowStep());

        $this->assertNull($form->position(new WorkflowStep()));
        $this->assertSame(['items' => [0 => 'End the workflow: the run succeeds'], 'prompt' => 'Next in the list: none, the run succeeds', 'value' => null], $routes['success']);
        $this->assertSame(['items' => [0 => 'End the workflow: the run fails'], 'prompt' => 'Next in the list: none, the run fails', 'value' => '0'], $routes['failure']);
        $this->assertSame(['items' => [], 'prompt' => 'Not set: On Success or On Failure decides', 'value' => null], $routes['always']);
    }

    // -- Add Step: the branches ---------------------------------------------------------

    /**
     * A new job step ends the workflow when it fails (the regression is
     * WorkflowTemplateStepFormViewTest's).
     */
    public function testANewJobStepEndsTheWorkflowWhenItFails(): void
    {
        $routes = WorkflowStepFormView::forAdd(self::mapOf(self::buildApproveDeploy()), 1)->routes(new WorkflowStep());

        $steps = [1 => '1. Build', 2 => '2. Approve (approval step)', 3 => '3. Deploy'];
        $this->assertSame(
            ['items' => [0 => 'End the workflow: the run succeeds'] + $steps, 'prompt' => 'Next in the list: "Approve"', 'value' => null],
            $routes['success']
        );
        $this->assertSame(
            ['items' => [0 => 'End the workflow: the run fails'] + $steps, 'prompt' => 'Next in the list: "Approve", also after a failure', 'value' => '0'],
            $routes['failure']
        );
        $this->assertSame(
            ['items' => $steps, 'prompt' => 'Not set: On Success or On Failure decides', 'value' => null],
            $routes['always']
        );
    }

    public function testANewApprovalOrPauseStepHasNoFailureDefault(): void
    {
        $form = WorkflowStepFormView::forAdd(self::mapOf(self::buildApproveDeploy()), null);

        $approval = $form->routes(self::stepModel(9, 'new', 'approval'))['failure'];
        $pause = $form->routes(self::pause(9, 'new'))['failure'];

        $this->assertNull($approval['value']);
        $this->assertSame('End the workflow: the run fails', $approval['prompt'], 'its empty On Failure ends the run');
        $this->assertArrayHasKey(0, $approval['items'], 'the page script detaches the second end choice');
        $this->assertNull($pause['value']);
    }

    public function testAddStepOffersApprovalStepsOnlyToUsersWhoMayAssignRules(): void
    {
        $form = WorkflowStepFormView::forAdd(self::mapOf(self::buildApproveDeploy()), null);

        $this->assertSame(['job' => 'Job', 'approval' => 'Approval', 'pause' => 'Pause'], $form->typeItems(true, new WorkflowStep()));
        $this->assertSame(['job' => 'Job', 'pause' => 'Pause'], $form->typeItems(false, new WorkflowStep()));
    }

    public function testAddStepShowsTheFieldsOfTheChosenType(): void
    {
        $form = WorkflowStepFormView::forAdd(self::mapOf(self::buildApproveDeploy()), null);

        $this->assertSame('job', $form->type(new WorkflowStep()));
        $this->assertSame('pause', $form->type(self::pause(9, 'new')));
        $this->assertSame('job', $form->type(self::stepModel(9, 'new', 'JOB')), 'Add Step offers only the known types');
        $this->assertSame(self::shown(['ws-target-job', 'ws-route-failure', 'ws-route-always', 'ws-routing-help-job']), $form->shows(new WorkflowStep()));
        $this->assertSame(
            self::shown(['ws-target-approval', 'ws-route-failure', 'ws-routing-help-approval']),
            $form->shows(self::stepModel(9, 'new', 'approval'))
        );
        $this->assertSame(self::shown(['ws-routing-help-pause']), $form->shows(self::pause(9, 'new')));
        $this->assertSame(['failureDefault' => true, 'legacyAlways' => false, 'templateGone' => false], $form->notes(new WorkflowStep()));
        $this->assertSame(['failureDefault' => false, 'legacyAlways' => false, 'templateGone' => false], $form->notes(self::pause(9, 'new')));
    }

    // -- Edit Step: the Position --------------------------------------------------------

    public function testEditStepKeepsTheStepWhereItIsAndOffersOnlyPlacesThatMoveIt(): void
    {
        $models = self::buildApproveDeployNotify();
        $form = WorkflowStepFormView::forEdit(self::mapOf($models), $models[2]);

        $position = $form->position($models[2]);

        $this->assertNotNull($position);
        $this->assertSame([
            'keep' => 'Keep at position 3',
            'start' => 'At the start (runs will start with this step)',
            'after:1' => 'After 1. Build',
            'end' => 'At the end (after 4. Notify)',
        ], $position['items']);
        $this->assertSame('keep', $position['selected']);
        $this->assertSame('Runs after "Approve", before "Notify".', $position['preview']);
        $this->assertSame('', $position['warning']);
        $this->assertSame([
            'keep' => self::choice('Runs after "Approve", before "Notify".', '', '', 'Notify'),
            'start' => self::choice(
                'Runs first, before "Build".',
                'Runs will start with this step instead of "Build". '
                . 'This job step will run before the approval step "Approve", without waiting for it.',
                '',
                'Build'
            ),
            'after:1' => self::choice(
                'Runs after "Build", before "Approve".',
                'This job step will run before the approval step "Approve", without waiting for it.',
                '',
                'Approve'
            ),
            'end' => self::choice('Runs last, after "Notify".', '', '', null),
        ], $position['options']);
        $this->assertTrue($form->isEdit());
        $this->assertSame('edit-step-form', $form->formId());
        $this->assertSame('Edit step 3 "Deploy"', $form->heading());
        $this->assertSame('Save Step', $form->submitLabel());
        $this->assertSame(['/workflow-template/view', 'id' => 77, '#' => 'step-3'], $form->cancelRoute());
        $this->assertFalse($form->hasAutofocus());
        $this->assertSame(WorkflowStepCommand::TYPE_LOCKED, $form->typeHint());
    }

    public function testMovingAnApprovalStepWarnsAboutTheJobStepsItWouldNoLongerGuard(): void
    {
        $models = self::buildApproveDeployNotify();
        $position = WorkflowStepFormView::forEdit(self::mapOf($models), $models[1])->position($models[1]);

        $this->assertSame(['keep', 'start', 'after:3', 'end'], array_keys($position['items'] ?? []));
        $this->assertSame('Runs will start with this step instead of "Build".', $position['options']['start']['data-warn'] ?? null);
        $this->assertSame(
            'Job step "Deploy" will run before this approval step, without waiting for it.',
            $position['options']['after:3']['data-warn'] ?? null
        );
        $this->assertSame(
            'Job step "Deploy" will run before this approval step, without waiting for it. '
            . 'Job step "Notify" will run before this approval step, without waiting for it.',
            $position['options']['end']['data-warn'] ?? null
        );
    }

    public function testMovingTheStartStepAwayNamesTheNewStart(): void
    {
        $models = self::buildApproveDeployNotify();
        $position = WorkflowStepFormView::forEdit(self::mapOf($models), $models[0])->position($models[0]);

        $this->assertSame(['keep', 'after:2', 'after:3', 'end'], array_keys($position['items'] ?? []));
        $this->assertSame('Runs will start with "Approve" instead of this step.', $position['options']['after:2']['data-warn'] ?? null);
        $this->assertSame('Runs first, before "Approve".', $position['options']['keep']['data-preview'] ?? null);
    }

    public function testMovingAnApprovalStepPastAnotherNamesTheApprovalStepThatComesFirst(): void
    {
        $models = [self::approval(1, 'Gate'), self::job(2, 'Early'), self::approval(3, 'Second gate'), self::job(4, 'Late')];
        $position = WorkflowStepFormView::forEdit(self::mapOf($models), $models[0])->position($models[0]);

        $this->assertSame(
            'Runs will start with "Early" instead of this step. '
            . 'Job step "Early" will run before the approval step "Second gate", without waiting for it.',
            $position['options']['end']['data-warn'] ?? null
        );
    }

    /**
     * @return array<string, array{0: list<WorkflowStep>, 1: int, 2: string, 3: string}>
     */
    public static function previewProvider(): array
    {
        return [
            'its own success leads past the next step' => [
                [self::job(1, 'Build'), self::job(2, 'Test', ['success' => 4]), self::job(3, 'Deploy'), self::job(4, 'Notify')],
                1,
                'Runs after "Build".',
                'Runs first.',
            ],
            'the last step ends the run with an explicit end' => [
                [self::job(1, 'Build'), self::job(2, 'Deploy', ['success' => 0])],
                1,
                'Runs last, after "Build".',
                'Runs first.',
            ],
            'the last step leads back' => [
                [self::job(1, 'Build'), self::job(2, 'Deploy', ['success' => 1])],
                1,
                'Runs after "Build".',
                'Runs first, before "Build".',
            ],
            'the step above ends the run when it succeeds' => [
                [self::job(1, 'Build', ['success' => 0]), self::job(2, 'Deploy')],
                1,
                'Listed after "Build", but runs end when "Build" succeeds, skipping this step.',
                'Runs first, before "Build".',
            ],
            'the step above leads to a step that no longer exists' => [
                [self::job(1, 'Build', ['success' => 999]), self::job(2, 'Deploy')],
                1,
                'Listed after "Build", but runs end when "Build" succeeds, skipping this step.',
                'Runs first, before "Build".',
            ],
            'the step above cannot run' => [
                [self::stepModel(1, 'Build', 'job', [], null, null, 12), self::job(2, 'Deploy')],
                1,
                'Listed after "Build", which cannot run: a run fails there instead of going on to this step.',
                'Runs first, before "Build".',
            ],
            'the step above has an unknown type' => [
                [self::stepModel(1, 'Odd', 'JOB'), self::job(2, 'Deploy')],
                1,
                'Listed after "Odd", which cannot run: a run fails there instead of going on to this step.',
                'Runs first, before "Odd".',
            ],
            'a step of an unknown type takes no branch' => [
                [self::job(1, 'Build'), self::stepModel(2, 'Odd', 'JOB')],
                1,
                'Runs after "Build".',
                'Runs first.',
            ],
        ];
    }

    /**
     * The preview says what runs do: a step runs after the step above it
     * only when a success there leads to it, and before the step below it
     * only when its own success leads there.
     *
     * @dataProvider previewProvider
     * @param list<WorkflowStep> $models
     */
    public function testThePreviewFollowsTheBranches(array $models, int $edited, string $keep, string $start): void
    {
        $position = WorkflowStepFormView::forEdit(self::mapOf($models), $models[$edited])->position($models[$edited]);

        $this->assertSame($keep, $position['preview'] ?? null);
        $this->assertSame($start, $position['options']['start']['data-preview'] ?? null);
    }

    public function testEditingAnApprovalStepOffTheSuccessPathWarnsWhereItStaysOff(): void
    {
        $models = [self::job(1, 'Build', ['success' => 3]), self::approval(2, 'Approve'), self::job(3, 'Deploy')];
        $offPath = 'This approval step will not be on the success path: runs whose steps all succeed will not wait for it.';

        $position = WorkflowStepFormView::forEdit(self::mapOf($models), $models[1])->position($models[1]);

        $this->assertSame($offPath, $position['warning'] ?? null, 'where it is now');
        $this->assertSame(
            ['keep' => $offPath, 'start' => 'Runs will start with this step instead of "Build".', 'end' => ''],
            array_map(static fn (array $option): string => $option['data-warn'], $position['options'] ?? [])
        );
        $this->assertSame('Listed after "Build", but a successful "Build" goes on with "Deploy", skipping this step.', $position['preview'] ?? null);
    }

    public function testMovingAJobStepThatTakesAnApprovalStepOffTheSuccessPathWarns(): void
    {
        $models = [self::job(1, 'Build', ['success' => 2]), self::job(2, 'Test'), self::approval(3, 'Approve'), self::job(4, 'Deploy')];

        $position = WorkflowStepFormView::forEdit(self::mapOf($models), $models[1])->position($models[1]);

        $this->assertSame(
            'The approval step "Approve" will not be on the success path: runs whose steps all succeed will not wait for it.',
            $position['options']['end']['data-warn'] ?? null
        );
        $this->assertSame('', $position['options']['keep']['data-warn'] ?? null);
    }

    public function testTheOnlyStepRunsAlone(): void
    {
        $models = [self::job(1, 'Build')];
        $position = WorkflowStepFormView::forEdit(self::mapOf($models), $models[0])->position($models[0]);

        $this->assertSame(['keep' => 'Keep at position 1'], $position['items'] ?? null);
        $this->assertSame('Runs as the only step.', $position['preview'] ?? null);
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function postedPositionProvider(): array
    {
        return [
            'none keeps it' => [null, 'keep'],
            'an offered place' => ['start', 'start'],
            'after the step right above, where it is' => ['after:2', 'keep'],
            'a value the form does not offer' => ['after:99', 'keep'],
        ];
    }

    /**
     * @dataProvider postedPositionProvider
     */
    public function testAReRenderSelectsThePostedPositionWhenTheFormOffersIt(?string $posted, string $selected): void
    {
        $models = self::buildApproveDeployNotify();

        $form = WorkflowStepFormView::forEdit(self::mapOf($models), $models[2], $posted);

        $this->assertSame($selected, $form->position($models[2])['selected'] ?? null);
    }

    public function testEditStepNeedsAStepOfTheMap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The route map has no step ID 99.');

        WorkflowStepFormView::forEdit(self::mapOf(self::buildApproveDeploy()), self::job(99, 'elsewhere'));
    }

    // -- Edit Step: the branches --------------------------------------------------------

    /**
     * A branch to a later step, or to the step itself (a retry), is chosen
     * on Edit Step: the add form offered only the steps that existed then.
     */
    public function testEditStepOffersEveryStepTheStepItselfIncluded(): void
    {
        $models = self::buildApproveDeployNotify();
        $models[2]->on_success_step_id = 4;
        $models[2]->on_failure_step_id = null;
        $form = WorkflowStepFormView::forEdit(self::mapOf($models), $models[2]);

        $routes = $form->routes($models[2]);

        $steps = [1 => '1. Build', 2 => '2. Approve (approval step)', 3 => '3. Deploy (this step)', 4 => '4. Notify'];
        $this->assertSame(
            ['items' => [0 => 'End the workflow: the run succeeds'] + $steps, 'prompt' => 'Next in the list: "Notify"', 'value' => '4'],
            $routes['success']
        );
        $this->assertSame(
            ['items' => [0 => 'End the workflow: the run fails'] + $steps, 'prompt' => 'Next in the list: "Notify", also after a failure', 'value' => null],
            $routes['failure'],
            'an edit keeps an empty On Failure empty'
        );
        $this->assertSame($steps, $routes['always']['items']);
    }

    /**
     * @return array<string, array{0: int|string|null, 1: array<int, string>, 2: string|null, 3: string|null}>
     */
    public static function approvalFailureProvider(): array
    {
        $steps = [1 => '1. Build', 2 => '2. Approve (this step)', 3 => '3. Deploy'];
        $end = 'End the workflow: the run fails';

        return [
            'empty: the empty choice is the only end' => [null, $steps, $end, null],
            'a stored 0, which does the same, keeps its value' => [0, [0 => $end] + $steps, null, '0'],
            'a step' => [3, $steps, $end, '3'],
            'a step that no longer exists is offered with its label' => [999, [999 => 'missing step (ID 999) · run fails'] + $steps, $end, '999'],
        ];
    }

    /**
     * @dataProvider approvalFailureProvider
     * @param array<int, string> $items
     */
    public function testAnApprovalStepsOnFailureHasOneEndChoice(int|string|null $stored, array $items, ?string $prompt, ?string $value): void
    {
        $models = self::buildApproveDeploy();
        $models[1]->on_failure_step_id = $stored;

        $failure = WorkflowStepFormView::forEdit(self::mapOf($models), $models[1])->routes($models[1])['failure'];

        $this->assertSame(['items' => $items, 'prompt' => $prompt, 'value' => $value], $failure);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function successOnlyTypeProvider(): array
    {
        return ['an approval step' => ['approval'], 'a pause step' => ['pause']];
    }

    /**
     * @dataProvider successOnlyTypeProvider
     */
    public function testAnAlwaysBranchFromAnOlderVersionShowsUnderOnSuccess(string $type): void
    {
        $models = self::buildApproveDeploy();
        $models[1] = self::stepModel(2, 'Old', $type, ['success' => 1, 'always' => 3], null, self::rule(9, 'rule'), null, 9);
        $form = WorkflowStepFormView::forEdit(self::mapOf($models), $models[1]);
        $posted = clone $models[1];
        $posted->on_success_step_id = 1;
        $posted->on_always_step_id = null;

        $this->assertSame('3', $form->routes($models[1])['success']['value'], 'runs follow Always when the step succeeds');
        $this->assertTrue($form->notes($models[1])['legacyAlways']);
        $this->assertSame('1', $form->routes($posted)['success']['value'], 'what the user chose when the form is shown again');
        $this->assertTrue($form->notes($posted)['legacyAlways'], 'the stored step still has it');
    }

    public function testAJobStepsAlwaysIsNoOlderVersionsBranch(): void
    {
        $models = self::buildApproveDeploy();
        $models[2]->on_always_step_id = 1;

        $form = WorkflowStepFormView::forEdit(self::mapOf($models), $models[2]);

        $this->assertNull($form->routes($models[2])['success']['value']);
        $this->assertSame('1', $form->routes($models[2])['always']['value']);
        $this->assertFalse($form->notes($models[2])['legacyAlways']);
    }

    public function testStoredValuesNoChoiceHoldsAreOffered(): void
    {
        $models = self::buildApproveDeploy();
        $models[2]->on_success_step_id = 999;
        $models[2]->on_always_step_id = 0;
        $form = WorkflowStepFormView::forEdit(self::mapOf($models), $models[2]);

        $routes = $form->routes($models[2]);

        $this->assertSame('missing step (ID 999) · run fails', $routes['success']['items'][999] ?? null);
        $this->assertSame('999', $routes['success']['value']);
        $this->assertSame('end · run succeeds or fails with this step', $routes['always']['items'][0] ?? null);
        $this->assertSame('0', $routes['always']['value']);
    }

    /**
     * @return array<string, array{0: int|string|null, 1: string|null}>
     */
    public static function branchValueProvider(): array
    {
        return [
            'none' => [null, null],
            'empty' => ['', null],
            '0' => [0, '0'],
            '"0"' => ['0', '0'],
            '"00"' => ['00', '0'],
            'leading zeros' => ['012', '12'],
            'a negative number' => [-3, null],
            'text' => ['abc', null],
        ];
    }

    /**
     * @dataProvider branchValueProvider
     */
    public function testABranchIsSelectedByItsWholeNumber(int|string|null $stored, ?string $value): void
    {
        $models = self::buildApproveDeploy();
        $models[2]->setAttribute('on_success_step_id', $stored);

        $form = WorkflowStepFormView::forEdit(self::mapOf([$models[0], $models[1], self::job(3, 'Deploy')]), $models[2]);

        $this->assertSame($value, $form->routes($models[2])['success']['value']);
    }

    public function testAJobStepWhoseTemplateWasDeletedAsksForAnother(): void
    {
        $deleted = self::stepModel(2, 'Deploy', 'job', [], null, null, 12);
        $none = self::stepModel(3, 'Notify', 'job');
        $map = self::mapOf([self::job(1, 'Build'), $deleted, $none]);

        $this->assertTrue(WorkflowStepFormView::forEdit($map, $deleted)->notes($deleted)['templateGone']);
        $this->assertFalse(WorkflowStepFormView::forEdit($map, $none)->notes($none)['templateGone'], 'it never had one');
        $this->assertFalse(WorkflowStepFormView::forEdit($map, self::job(1, 'Build'))->notes($deleted)['templateGone']);
    }

    public function testEditStepShowsTheFieldsOfTheStoredType(): void
    {
        $unknown = self::stepModel(2, 'Odd', 'JOB');
        $map = self::mapOf([self::job(1, 'Build'), $unknown]);
        $form = WorkflowStepFormView::forEdit($map, $unknown);

        $this->assertSame('JOB', $form->type(new WorkflowStep()), 'the stored type, whatever the form shows');
        $this->assertSame(['job' => 'Job', 'approval' => 'Approval', 'pause' => 'Pause', 'JOB' => 'JOB'], $form->typeItems(false, $unknown));
        $this->assertSame(self::shown(['ws-route-failure', 'ws-route-always']), $form->shows($unknown));
        $this->assertSame(['failureDefault' => false, 'legacyAlways' => false, 'templateGone' => false], $form->notes($unknown));
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * Build (job), Approve (approval), Deploy (job).
     *
     * @return list<WorkflowStep>
     */
    private static function buildApproveDeploy(): array
    {
        return [self::job(1, 'Build'), self::approval(2, 'Approve'), self::job(3, 'Deploy')];
    }

    /**
     * Build (job), Approve (approval), Deploy (job), Notify (job).
     *
     * @return list<WorkflowStep>
     */
    private static function buildApproveDeployNotify(): array
    {
        return [self::job(1, 'Build'), self::approval(2, 'Approve'), self::job(3, 'Deploy'), self::job(4, 'Notify')];
    }

    /**
     * The attributes of a Position choice; $next: the name of the step after
     * it, null for none.
     *
     * @return array<string, string>
     */
    private static function choice(string $preview, string $warn, string $warnJob, ?string $next, string $warnApproval = ''): array
    {
        return [
            'data-preview' => $preview,
            'data-warn' => $warn,
            'data-warn-job' => $warnJob,
            'data-warn-approval' => $warnApproval,
            'data-success-prompt' => $next === null ? 'Next in the list: none, the run succeeds' : 'Next in the list: "' . $next . '"',
            'data-failure-prompt' => $next === null
                ? 'Next in the list: none, the run fails'
                : 'Next in the list: "' . $next . '", also after a failure',
        ];
    }

    /**
     * The parts the form shows: those in $shown.
     *
     * @param list<string> $shown
     * @return array<string, bool>
     */
    private static function shown(array $shown): array
    {
        $parts = [
            'ws-target-job',
            'ws-target-approval',
            'ws-route-failure',
            'ws-route-always',
            'ws-routing-help-job',
            'ws-routing-help-approval',
            'ws-routing-help-pause',
        ];

        return array_combine($parts, array_map(static fn (string $part): bool => in_array($part, $shown, true), $parts));
    }
}
