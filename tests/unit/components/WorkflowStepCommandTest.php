<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\SurveySecrets;
use app\components\WorkflowStepCommand;
use app\components\WorkflowStepPosition;
use app\models\WorkflowStep;
use app\services\JobLaunchSecrets;
use PHPUnit\Framework\TestCase;

/**
 * WorkflowStepCommand (contract C5): what one request sets on a workflow
 * step. A field the request leaves out is absent, which is not null; the
 * step form sets every route (empty is null, the next step), the API only
 * the ones it sends. A route is null, 0 or a step ID, never a boolean, a
 * float, a sign or a space. step_order is never read.
 */
class WorkflowStepCommandTest extends TestCase
{
    public function testTheTextsKeepTheirWording(): void
    {
        $this->assertSame(
            '%s must be null, 0 (end the workflow) or the ID of a step of this workflow.',
            WorkflowStepCommand::ROUTE_INVALID
        );
        $this->assertSame("A step's type cannot change: add a new step and remove this one.", WorkflowStepCommand::TYPE_LOCKED);
        $this->assertSame(
            'A pause step only has on_success_step_id: Resume continues there. '
                . 'Send on_failure_step_id and on_always_step_id as null.',
            WorkflowStepCommand::PAUSE_BRANCHES_REFUSED
        );
    }

    // -- fromForm() ------------------------------------------------------------

    public function testTheAddFormSetsWhatItPostsAndEveryRoute(): void
    {
        $command = $this->command(WorkflowStepCommand::fromForm([
            'name' => 'Deploy',
            'step_type' => WorkflowStep::TYPE_JOB,
            'job_template_id' => '05',
            'on_failure_step_id' => '0',
            'on_always_step_id' => '',
        ], null, false));

        $this->assertSame([
            'name' => 'Deploy',
            'step_type' => WorkflowStep::TYPE_JOB,
            'job_template_id' => '05',
            'on_success_step_id' => null,
            'on_failure_step_id' => 0,
            'on_always_step_id' => null,
        ], $command->fields());
        $this->assertTrue($command->has('on_success_step_id'), 'a route the form leaves out is set, to null');
        $this->assertFalse($command->has('approval_rule_id'));
        $this->assertNull($command->value('approval_rule_id'));
        $this->assertSame('05', $command->value('job_template_id'), 'the write path reads IDs as the model does');
        $this->assertSame(WorkflowStepPosition::KIND_END, $command->position()?->kind(), 'Add Step defaults to the end');
    }

    /**
     * A posted Step Order never decides where the step goes, and the form
     * has no variable mapping.
     */
    public function testTheFormNeverReadsStepOrderOrTheVariableMapping(): void
    {
        $command = $this->command(WorkflowStepCommand::fromForm([
            'name' => 'x',
            'step_type' => WorkflowStep::TYPE_JOB,
            'step_order' => '0',
            'extra_vars_template' => '{"a":1}',
            'workflow_template_id' => '9',
            'id' => '3',
        ], 'start', false));

        foreach (['step_order', 'extra_vars_template', 'workflow_template_id', 'id'] as $field) {
            $this->assertFalse($command->has($field), $field);
        }
        $this->assertSame(WorkflowStepPosition::KIND_START, $command->position()?->kind());
    }

    public function testTheEditFormKeepsWhatItDoesNotPostAndThePosition(): void
    {
        $command = $this->command(WorkflowStepCommand::fromForm(['on_success_step_id' => '12'], '', true));

        $this->assertSame(
            ['on_success_step_id' => 12, 'on_failure_step_id' => null, 'on_always_step_id' => null],
            $command->fields()
        );
        $this->assertFalse($command->has('name'));
        $this->assertFalse($command->has('step_type'));
        $this->assertNull($command->position(), 'Edit Step keeps the position by default');
        $this->assertNull($this->command(WorkflowStepCommand::fromForm([], 'keep', true))->position());
        $this->assertSame(7, $this->command(WorkflowStepCommand::fromForm([], 'after:7', true))->position()?->stepId());
    }

    /**
     * An edit form posts no type; one that does keeps it in the command, so
     * that the write path refuses another type (TYPE_LOCKED).
     */
    public function testAStepTypeAnEditFormPostsIsKeptForTheTypeLock(): void
    {
        $command = $this->command(WorkflowStepCommand::fromForm(['step_type' => WorkflowStep::TYPE_APPROVAL], null, true));

        $this->assertSame(WorkflowStep::TYPE_APPROVAL, $command->value('step_type'));
    }

    public function testAPauseStepFormIgnoresOnFailureAndAlways(): void
    {
        $command = $this->command(WorkflowStepCommand::fromForm([
            'name' => 'Wait',
            'step_type' => WorkflowStep::TYPE_PAUSE,
            'on_success_step_id' => '4',
            'on_failure_step_id' => '5',
            'on_always_step_id' => '6',
        ], 'end', false));

        $this->assertSame(4, $command->value('on_success_step_id'));
        $this->assertTrue($command->has('on_failure_step_id'));
        $this->assertNull($command->value('on_failure_step_id'));
        $this->assertNull($command->value('on_always_step_id'));
    }

    public function testAFormPositionThatIsNoneOfItsOptionsIsRefused(): void
    {
        $this->assertSame(WorkflowStepPosition::FORM_INVALID, WorkflowStepCommand::fromForm(['name' => 'x'], 'after:x', false));
        $this->assertSame(WorkflowStepPosition::FORM_INVALID, WorkflowStepCommand::fromForm(['name' => 'x'], 'keep', false));
    }

    /**
     * @return array<string, array{0: mixed, 1: int|null}>
     */
    public static function formRouteProvider(): array
    {
        return [
            'empty: the next step' => ['', null],
            '0: end the workflow' => ['0', WorkflowStep::END_WORKFLOW],
            'zeros' => ['000', WorkflowStep::END_WORKFLOW],
            'a step' => ['12', 12],
            'a step with a leading zero' => ['012', 12],
            'an int from a JSON body' => [12, 12],
        ];
    }

    /**
     * @dataProvider formRouteProvider
     */
    public function testAFormRouteIsEmptyZeroOrAStep(mixed $posted, ?int $route): void
    {
        $command = $this->command(WorkflowStepCommand::fromForm(['on_failure_step_id' => $posted], null, false));

        $this->assertSame($route, $command->value('on_failure_step_id'));
    }

    /**
     * Regression guard: completeFromForm() turned a forged route "abc" into
     * 0, which ends the workflow, and any other text into a step ID.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function noRouteProvider(): array
    {
        return [
            'text' => ['abc'],
            'negative' => ['-1'],
            'sign' => ['+5'],
            'space' => [' 5'],
            'decimal' => ['1.5'],
            'more than an int holds' => ['99999999999999999999'],
            'array' => [['5']],
            'boolean' => [true],
            'float' => [5.0],
        ];
    }

    /**
     * @dataProvider noRouteProvider
     */
    public function testAFormRouteThatIsNoRouteIsRefused(mixed $posted): void
    {
        $this->assertSame(
            sprintf(WorkflowStepCommand::ROUTE_INVALID, 'on_always_step_id'),
            WorkflowStepCommand::fromForm(['name' => 'x', 'on_always_step_id' => $posted], null, false)
        );
    }

    public function testFormTextIsReadAsText(): void
    {
        $command = $this->command(WorkflowStepCommand::fromForm(['name' => ['x'], 'step_type' => 5], null, false));

        $this->assertSame('', $command->value('name'), 'not text: empty, which the model refuses');
        $this->assertSame('5', $command->value('step_type'));
    }

    // -- fromApi() -------------------------------------------------------------

    public function testAnApiAddDefaultsToAJobStepWithEmptyRoutesAtTheEnd(): void
    {
        $command = $this->command(WorkflowStepCommand::fromApi(['name' => 'Build', 'job_template_id' => 5], true));

        $this->assertSame([
            'step_type' => WorkflowStep::TYPE_JOB,
            'on_success_step_id' => null,
            'on_failure_step_id' => null,
            'on_always_step_id' => null,
            'name' => 'Build',
            'job_template_id' => 5,
        ], $command->fields());
        $this->assertSame(WorkflowStepPosition::KIND_END, $command->position()?->kind());
    }

    public function testAnApiUpdateSetsOnlyWhatItSends(): void
    {
        $nothing = $this->command(WorkflowStepCommand::fromApi([], false));
        $this->assertSame([], $nothing->fields());
        $this->assertNull($nothing->position(), 'an update without a position keeps it');

        $command = $this->command(WorkflowStepCommand::fromApi([
            'on_failure_step_id' => null,
            'approval_rule_id' => '3',
            'extra_vars_template' => null,
            'after_step_id' => 9,
        ], false));
        $this->assertSame(['approval_rule_id' => 3, 'on_failure_step_id' => null, 'extra_vars_template' => null], $command->fields());
        $this->assertTrue($command->has('on_failure_step_id'), 'null clears the route');
        $this->assertFalse($command->has('on_success_step_id'));
        $this->assertSame(9, $command->position()?->stepId());
    }

    public function testAnApiBodyIgnoresStepOrderIdAndUnknownKeys(): void
    {
        $command = $this->command(WorkflowStepCommand::fromApi([
            'name' => 'x',
            'step_order' => 0,
            'id' => 4,
            'position' => 2,
            'is_start' => true,
            'workflow_template_id' => 8,
        ], false));

        $this->assertSame(['name' => 'x'], $command->fields());
        $this->assertSame(2, $command->position()?->position());
    }

    /**
     * @return array<string, array{0: mixed, 1: int|null}>
     */
    public static function apiRouteProvider(): array
    {
        return [
            'null: the next step' => [null, null],
            '0: end the workflow' => [0, WorkflowStep::END_WORKFLOW],
            'a step' => [12, 12],
            'digits' => ['12', 12],
            'digits 0' => ['0', WorkflowStep::END_WORKFLOW],
        ];
    }

    /**
     * @dataProvider apiRouteProvider
     */
    public function testAnApiRouteIsNullZeroOrAStep(mixed $sent, ?int $route): void
    {
        $command = $this->command(WorkflowStepCommand::fromApi(['on_success_step_id' => $sent], false));

        $this->assertSame($route, $command->value('on_success_step_id'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function noApiRouteProvider(): array
    {
        return [
            'true' => [true],
            'false' => [false],
            'a float' => [1.0],
            'negative' => [-1],
            'empty' => [''],
            'text' => ['abc'],
            'a sign' => ['+5'],
            'an object' => [['id' => 5]],
        ];
    }

    /**
     * @dataProvider noApiRouteProvider
     */
    public function testAnApiRouteThatIsNoRouteIsRefused(mixed $sent): void
    {
        $this->assertSame(
            sprintf(WorkflowStepCommand::ROUTE_INVALID, 'on_failure_step_id'),
            WorkflowStepCommand::fromApi(['on_failure_step_id' => $sent], false)
        );
    }

    /**
     * IDs go through WorkflowStepsInput::toId(): anything that is not an ID
     * becomes null, which the model reports as missing.
     *
     * @return array<string, array{0: mixed, 1: int|null}>
     */
    public static function apiIdProvider(): array
    {
        return [
            'an int' => [5, 5],
            'digits' => ['5', 5],
            'a plus sign' => ['+5', 5],
            'a leading zero' => ['05', null],
            'true' => [true, null],
            'a float' => [5.0, null],
            'text' => ['abc', null],
            'null' => [null, null],
        ];
    }

    /**
     * @dataProvider apiIdProvider
     */
    public function testAnApiIdIsReadAsTheBulkListReadsIt(mixed $sent, ?int $id): void
    {
        $command = $this->command(WorkflowStepCommand::fromApi(['job_template_id' => $sent], false));

        $this->assertTrue($command->has('job_template_id'));
        $this->assertSame($id, $command->value('job_template_id'));
    }

    public function testAnApiVariableMappingIsJsonTextAnObjectOrNull(): void
    {
        $this->assertSame('{"a":1}', $this->command(WorkflowStepCommand::fromApi(['extra_vars_template' => ['a' => 1]], false))->value('extra_vars_template'));
        $this->assertSame('{"b": 2}', $this->command(WorkflowStepCommand::fromApi(['extra_vars_template' => '{"b": 2}'], false))->value('extra_vars_template'));
        $this->assertNull($this->command(WorkflowStepCommand::fromApi(['extra_vars_template' => null], false))->value('extra_vars_template'));
        $this->assertSame(
            SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN),
            WorkflowStepCommand::fromApi(['extra_vars_template' => ['a' => INF]], false)
        );
    }

    /**
     * regression: [] (as the API answers no mapping) was read as the text
     * "[]". None in any form sets the mapping to null: an update that sends
     * it clears the mapping, and one that leaves the key out keeps it.
     */
    public function testNoApiVariableMappingInAnyFormSetsNull(): void
    {
        foreach ([[], '[]', '{}', 'null', ''] as $none) {
            $command = $this->command(WorkflowStepCommand::fromApi(['extra_vars_template' => $none], false));

            $this->assertTrue($command->has('extra_vars_template'), (string)json_encode($none));
            $this->assertNull($command->value('extra_vars_template'), (string)json_encode($none));
        }
        $this->assertFalse($this->command(WorkflowStepCommand::fromApi(['name' => 'Build'], false))->has('extra_vars_template'));
    }

    public function testAnApiTextFieldIsReadAsText(): void
    {
        $command = $this->command(WorkflowStepCommand::fromApi(['name' => null, 'step_type' => true], true));

        $this->assertSame('', $command->value('name'));
        $this->assertSame('1', $command->value('step_type'), 'which the model refuses as no type');
    }

    /**
     * The position's refusals come first, then the routes', then the
     * variable mapping's.
     */
    public function testAnApiBodyIsRefusedForItsFirstProblem(): void
    {
        $this->assertSame(
            WorkflowStepPosition::BOTH_GIVEN,
            WorkflowStepCommand::fromApi(['position' => 1, 'after_step_id' => 2, 'on_success_step_id' => 'x'], true)
        );
        $this->assertSame(
            WorkflowStepPosition::POSITION_INVALID,
            WorkflowStepCommand::fromApi(['position' => 'first', 'on_success_step_id' => 'x'], true)
        );
        $this->assertSame(WorkflowStepPosition::AFTER_INVALID, WorkflowStepCommand::fromApi(['after_step_id' => 0], false));
        $this->assertSame(
            sprintf(WorkflowStepCommand::ROUTE_INVALID, 'on_success_step_id'),
            WorkflowStepCommand::fromApi(['on_success_step_id' => 'x', 'extra_vars_template' => ['a' => INF]], true)
        );
    }

    /**
     * Branches on a pause step: the API refuses a route it never takes,
     * unless it is null.
     */
    public function testAnApiPauseStepWithOnFailureOrAlwaysIsRefused(): void
    {
        foreach (['on_failure_step_id' => 0, 'on_always_step_id' => 4] as $route => $value) {
            $this->assertSame(
                WorkflowStepCommand::PAUSE_BRANCHES_REFUSED,
                WorkflowStepCommand::fromApi(['name' => 'Wait', 'step_type' => WorkflowStep::TYPE_PAUSE, $route => $value], true),
                $route
            );
        }

        $command = $this->command(WorkflowStepCommand::fromApi([
            'name' => 'Wait',
            'step_type' => WorkflowStep::TYPE_PAUSE,
            'on_success_step_id' => 3,
            'on_failure_step_id' => null,
        ], true));
        $this->assertSame(3, $command->value('on_success_step_id'));
        $this->assertNull($command->value('on_failure_step_id'));
    }

    // -- forStepType() ---------------------------------------------------------

    /**
     * The write path knows an edited step's type; the request does not
     * send it.
     */
    public function testForStepTypeAppliesThePauseRuleOfTheCommandsOrigin(): void
    {
        $form = $this->command(WorkflowStepCommand::fromForm(['on_failure_step_id' => '5', 'on_always_step_id' => '6'], null, true));
        $api = $this->command(WorkflowStepCommand::fromApi(['on_always_step_id' => 6], false));
        $cleared = $this->command(WorkflowStepCommand::fromApi(['on_always_step_id' => null, 'name' => 'x'], false));

        $this->assertSame($form, $form->forStepType(WorkflowStep::TYPE_JOB), 'other types keep the command');
        $this->assertSame($api, $api->forStepType(WorkflowStep::TYPE_APPROVAL));
        $this->assertSame($api, $api->forStepType(null));

        $pauseForm = $this->command($form->forStepType(WorkflowStep::TYPE_PAUSE));
        $this->assertNull($pauseForm->value('on_failure_step_id'));
        $this->assertNull($pauseForm->value('on_always_step_id'));
        $this->assertNull($pauseForm->position());
        $this->assertSame(['on_success_step_id' => null, 'on_failure_step_id' => 5, 'on_always_step_id' => 6], $form->fields(), 'immutable');

        $this->assertSame(WorkflowStepCommand::PAUSE_BRANCHES_REFUSED, $api->forStepType(WorkflowStep::TYPE_PAUSE));
        $this->assertSame($cleared, $cleared->forStepType(WorkflowStep::TYPE_PAUSE), 'null is no branch');
    }

    private function command(WorkflowStepCommand|string $read): WorkflowStepCommand
    {
        $this->assertInstanceOf(WorkflowStepCommand::class, $read, is_string($read) ? $read : '');

        return $read;
    }
}
