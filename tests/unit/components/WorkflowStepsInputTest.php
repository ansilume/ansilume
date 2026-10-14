<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\SurveySecrets;
use app\components\WorkflowStepMapping;
use app\components\WorkflowStepsInput;
use app\models\WorkflowStep;
use app\services\JobLaunchSecrets;
use PHPUnit\Framework\TestCase;
use yii\base\DynamicModel;

/**
 * WorkflowStepsInput: the "steps" list of the API's bulk create and replace
 * of a workflow's steps, and the readers the per-step endpoints share with it
 * (WorkflowStepCommand::fromApi()): text(), toId() and extraVarsTemplate().
 * The list's step_order is the index; routes are not read (the list replaces
 * every step, with new IDs); only the target of a step's type is read.
 */
class WorkflowStepsInputTest extends TestCase
{
    public function testTheListBecomesUnsavedStepsInOrder(): void
    {
        $steps = $this->steps(WorkflowStepsInput::read([
            ['name' => 'Build', 'job_template_id' => 4, 'approval_rule_id' => 9, 'on_success_step_id' => 3],
            ['name' => 'Approve', 'step_type' => WorkflowStep::TYPE_APPROVAL, 'approval_rule_id' => '2', 'job_template_id' => 4],
            ['name' => 'Wait', 'step_type' => WorkflowStep::TYPE_PAUSE, 'job_template_id' => 4, 'extra_vars_template' => ['a' => 'b']],
        ]));

        $this->assertSame(['Build', 'Approve', 'Wait'], array_map(static fn (WorkflowStep $s): string => $s->name, $steps));
        $this->assertSame([0, 1, 2], array_map(static fn (WorkflowStep $s): int => $s->step_order, $steps), 'the index');
        $this->assertSame([WorkflowStep::TYPE_JOB, WorkflowStep::TYPE_APPROVAL, WorkflowStep::TYPE_PAUSE], array_map(
            static fn (WorkflowStep $s): string => $s->step_type,
            $steps
        ), 'job by default');
        $this->assertSame([4, null, null], array_map(static fn (WorkflowStep $s): ?int => $s->job_template_id, $steps));
        $this->assertSame([null, 2, null], array_map(static fn (WorkflowStep $s): ?int => $s->approval_rule_id, $steps));
        $this->assertNull($steps[0]->on_success_step_id, 'routes are not read');
        $this->assertSame('{"a":"b"}', $steps[2]->extra_vars_template);
        $this->assertSame([null, null], [$steps[0]->extra_vars_template, $steps[1]->extra_vars_template], 'none');
        $this->assertTrue($steps[0]->getIsNewRecord());
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'not a list' => [['a' => ['name' => 'x']], 'steps must be a list of steps.'],
            'not an array' => ['steps', 'steps must be a list of steps.'],
            'a step that is no object' => [['x'], 'steps[0]: A step must be an object.'],
            'no name' => [[['job_template_id' => 4]], 'steps[0]: Name cannot be blank.'],
            'a type that is none' => [[['name' => 'x', 'step_type' => 'JOB']], 'steps[0]: Step Type is invalid.'],
            'a job step without a job template' => [
                [['name' => 'x', 'job_template_id' => '05']],
                'steps[0]: A job step needs job_template_id, the ID of a job template.',
            ],
            'an approval step without a rule' => [
                [['name' => 'x', 'step_type' => WorkflowStep::TYPE_APPROVAL]],
                'steps[0]: An approval step needs approval_rule_id, the ID of an approval rule.',
            ],
            'JSON text that is none' => [[['name' => 'x', 'step_type' => WorkflowStep::TYPE_PAUSE, 'extra_vars_template' => '{x']], 'steps[0]: Extra_vars_template must be valid JSON.'],
            'a mapping that is a list' => [
                [['name' => 'x', 'step_type' => WorkflowStep::TYPE_PAUSE, 'extra_vars_template' => ['build_version']]],
                'steps[0]: ' . WorkflowStepMapping::INVALID,
            ],
            'a mapping with a number as a value, as JSON text' => [
                [['name' => 'x', 'step_type' => WorkflowStep::TYPE_PAUSE, 'extra_vars_template' => '{"a": 1}']],
                'steps[0]: ' . WorkflowStepMapping::INVALID,
            ],
            'a mapping and no name: the name first' => [
                [['step_type' => WorkflowStep::TYPE_PAUSE, 'extra_vars_template' => ['a' => ['b']]]],
                'steps[0]: Name cannot be blank.',
            ],
            'the second step' => [[['name' => 'ok', 'step_type' => WorkflowStep::TYPE_PAUSE], []], 'steps[1]: Name cannot be blank.'],
        ];
    }

    /**
     * @dataProvider refusedProvider
     */
    public function testAListItCannotTakeIsRefusedWithTheFirstProblem(mixed $raw, string $message): void
    {
        $this->assertSame($message, WorkflowStepsInput::read($raw));
    }

    /**
     * An object that cannot be encoded is refused after the other fields'
     * errors, as JSON text is.
     */
    public function testAnUnencodableVariableMappingIsRefusedAfterTheOtherFields(): void
    {
        $unencodable = SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN);

        $this->assertSame(
            'steps[0]: Name cannot be blank.',
            WorkflowStepsInput::read([['step_type' => WorkflowStep::TYPE_PAUSE, 'extra_vars_template' => ['a' => INF]]])
        );
        $this->assertSame(
            'steps[0]: ' . $unencodable,
            WorkflowStepsInput::read([['name' => 'x', 'step_type' => WorkflowStep::TYPE_PAUSE, 'extra_vars_template' => ['a' => INF]]])
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: array{value: string|null, error: string|null}}>
     */
    public static function extraVarsProvider(): array
    {
        return [
            'an object, encoded' => [['env' => 'prod', 'n' => 2], ['value' => '{"env":"prod","n":2}', 'error' => null]],
            'JSON text, as it is' => ['{"env": "prod"}', ['value' => '{"env": "prod"}', 'error' => null]],
            'null' => [null, ['value' => null, 'error' => null]],
            'a number, as text' => [5, ['value' => '5', 'error' => null]],
            // None is stored as null: the API answers [] for a step without a mapping.
            'an empty array: [] or {} in a JSON body' => [[], ['value' => null, 'error' => null]],
            'the text []' => ['[]', ['value' => null, 'error' => null]],
            'the text {} with spaces' => [' { } ', ['value' => null, 'error' => null]],
            'the text null' => ['null', ['value' => null, 'error' => null]],
            'empty text' => ['', ['value' => null, 'error' => null]],
            'false, empty as text' => [false, ['value' => null, 'error' => null]],
            'a list, encoded for the model to refuse' => [['build_version'], ['value' => '["build_version"]', 'error' => null]],
            'infinity in an object' => [
                ['n' => INF],
                ['value' => null, 'error' => SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN)],
            ],
        ];
    }

    /**
     * regression: a step without a mapping, which the API answers as [],
     * got the text "[]" when a client sent that back; an empty mapping in
     * any form is stored as null now.
     *
     * @dataProvider extraVarsProvider
     * @param array{value: string|null, error: string|null} $read
     */
    public function testTheVariableMappingIsReadAsTextAnObjectOrNull(mixed $sent, array $read): void
    {
        $this->assertSame($read, WorkflowStepsInput::extraVarsTemplate($sent));
    }

    /**
     * @return array<string, array{0: mixed, 1: int|null}>
     */
    public static function idProvider(): array
    {
        return [
            'an int' => [7, 7],
            'digits' => ['7', 7],
            'a plus sign' => ['+7', 7],
            '0' => [0, null],
            'negative' => [-7, null],
            'a leading zero' => ['07', null],
            'true' => [true, null],
            'a float' => [7.0, null],
            'text' => ['seven', null],
            'null' => [null, null],
        ];
    }

    /**
     * @dataProvider idProvider
     */
    public function testAnIdIsAPositiveIntegerOrDigitString(mixed $sent, ?int $id): void
    {
        $this->assertSame($id, WorkflowStepsInput::toId($sent));
    }

    public function testTextIsAScalarAsTextAndEmptyOtherwise(): void
    {
        $this->assertSame('Build', WorkflowStepsInput::text('Build'));
        $this->assertSame('5', WorkflowStepsInput::text(5));
        $this->assertSame('1', WorkflowStepsInput::text(true));
        $this->assertSame('', WorkflowStepsInput::text(null));
        $this->assertSame('', WorkflowStepsInput::text(['Build']));
    }

    public function testTheFirstErrorIsTheFirstAttributesFirstOne(): void
    {
        $model = new DynamicModel(['a' => null, 'b' => null]);
        $this->assertSame('Validation failed.', WorkflowStepsInput::firstError($model));

        $model->addError('a', 'A is wrong.');
        $model->addError('a', 'A is still wrong.');
        $model->addError('b', 'B is wrong.');
        $this->assertSame('A is wrong.', WorkflowStepsInput::firstError($model));
    }

    /**
     * @param list<WorkflowStep>|string $read
     * @return list<WorkflowStep>
     */
    private function steps(array|string $read): array
    {
        $this->assertIsArray($read, is_string($read) ? $read : '');

        return $read;
    }
}
