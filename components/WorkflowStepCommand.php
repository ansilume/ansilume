<?php

declare(strict_types=1);

namespace app\components;

use app\models\WorkflowStep;

/**
 * What one request sets on a workflow step: the step form of the template
 * page (fromForm(): Add Step and Edit Step) or the body of the API's
 * per-step add and update (fromApi()), and where the step goes (position()).
 *
 * A field the request leaves out is absent, which is not the same as null:
 * an update keeps an absent field, while null clears a route (the run goes
 * on with the next step) or the variable mapping. Fields carry the model's
 * attribute names: name, step_type, job_template_id, approval_rule_id, the
 * routes (ROUTE_FIELDS) and extra_vars_template. step_order and id are never
 * read: placement decides where a step runs, and a step keeps its ID.
 *
 * A route is null (the next step in the run order), 0 (END_WORKFLOW: the
 * workflow ends) or the ID of a step, as an int or a string of digits;
 * booleans, floats, signs, spaces and negative numbers are refused
 * (ROUTE_INVALID). Whether a route leads to a step of the workflow is the
 * model's rule.
 *
 * The readers check the shape of each value only: the write path
 * (WorkflowStepWriteService) checks who may do what, the references and the
 * model's rules. Each reader returns the command, or why the request is
 * refused: the text the web shows and the API answers 422 with.
 */
final class WorkflowStepCommand
{
    /** sprintf(): the route's field, such as on_failure_step_id. */
    public const ROUTE_INVALID = '%s must be null, 0 (end the workflow) or the ID of a step of this workflow.';

    /** An edit or update that sends another step type than the step has. */
    public const TYPE_LOCKED = "A step's type cannot change: add a new step and remove this one.";

    /** An API add or update that sends a pause step a route it never takes. */
    public const PAUSE_BRANCHES_REFUSED = 'A pause step only has on_success_step_id: Resume continues there. '
        . 'Send on_failure_step_id and on_always_step_id as null.';

    /** The routes of a step, as the model names them. */
    public const ROUTE_FIELDS = ['on_success_step_id', 'on_failure_step_id', 'on_always_step_id'];

    /** The routes a pause step never takes: Resume continues with On Success. */
    private const PAUSE_UNUSED_ROUTES = ['on_failure_step_id', 'on_always_step_id'];

    /** The fields read as text. */
    private const TEXT_FIELDS = ['name', 'step_type'];

    /** The targets: the job template of a job step, the approval rule of an approval step. */
    private const ID_FIELDS = ['job_template_id', 'approval_rule_id'];

    /** What an API add sets when the body leaves it out. */
    private const CREATE_DEFAULTS = [
        'step_type' => WorkflowStep::TYPE_JOB,
        'on_success_step_id' => null,
        'on_failure_step_id' => null,
        'on_always_step_id' => null,
    ];

    /**
     * @param array<string, mixed> $fields the fields the request sets, by attribute
     * @param WorkflowStepPosition|null $position where the step goes; null keeps it where it is
     * @param bool $form whether the step form sent it, not the API
     */
    private function __construct(
        private readonly array $fields,
        private readonly ?WorkflowStepPosition $position,
        private readonly bool $form,
    ) {
    }

    /**
     * The step form: $posted are its WorkflowStep fields, $position its
     * Position (WorkflowStepPosition::fromWeb()), for Add Step, or for Edit
     * Step when $edit. Name, the job template and the approval rule are read
     * when posted, the IDs as posted (the write path reads them as the
     * model's integer rule does). step_type is read on an add; an edit form
     * does not post it, and one that does is refused if it names another
     * type (TYPE_LOCKED, by the write path). A route the form leaves out or
     * empty is null: the form shows every route the step takes, so an empty
     * one was chosen. A pause step ignores On Failure and Always (null).
     * step_order and extra_vars_template are never read.
     *
     * @param array<array-key, mixed> $posted
     * @return self|string the command; WorkflowStepPosition::FORM_INVALID or ROUTE_INVALID
     */
    public static function fromForm(array $posted, mixed $position, bool $edit): self|string
    {
        $at = WorkflowStepPosition::fromWeb($position, $edit);
        if (is_string($at)) {
            return $at;
        }
        $routes = self::routes($posted, true);
        if (is_string($routes)) {
            return $routes;
        }
        $fields = array_merge(
            self::present($posted, self::TEXT_FIELDS, static fn (mixed $value): string => WorkflowStepsInput::text($value)),
            self::present($posted, self::ID_FIELDS, static fn (mixed $value): mixed => $value),
            $routes
        );

        return (new self($fields, $at, true))->forStepType($fields['step_type'] ?? null);
    }

    /**
     * The body of an API add ($create) or update. Only the keys the body
     * sends are set, except that an add defaults step_type to job and the
     * routes to null. name and step_type are read as text, the job template
     * and the approval rule as IDs (WorkflowStepsInput::toId(): anything
     * else is no ID, which the model then reports as missing), the variable
     * mapping as JSON text, a JSON object or null, and none ([] or {}, also
     * as JSON text) as null (WorkflowStepsInput::extraVarsTemplate(); its
     * shape is the model's rule), the position as
     * WorkflowStepPosition::fromApi() reads it. A pause step sent with a
     * route it never takes is refused. step_order, id and unknown keys are
     * ignored.
     *
     * @param array<array-key, mixed> $body
     * @return self|string the command; the position's refusals, ROUTE_INVALID,
     *     the variable mapping's, or PAUSE_BRANCHES_REFUSED
     */
    public static function fromApi(array $body, bool $create): self|string
    {
        $at = WorkflowStepPosition::fromApi($body, $create);
        if (is_string($at)) {
            return $at;
        }
        $routes = self::routes($body, false);
        if (is_string($routes)) {
            return $routes;
        }
        $extraVars = self::extraVars($body);
        if (is_string($extraVars)) {
            return $extraVars;
        }
        $fields = array_merge(
            $create ? self::CREATE_DEFAULTS : [],
            self::present($body, self::TEXT_FIELDS, static fn (mixed $value): string => WorkflowStepsInput::text($value)),
            self::present($body, self::ID_FIELDS, static fn (mixed $value): ?int => WorkflowStepsInput::toId($value)),
            $routes,
            $extraVars
        );

        return (new self($fields, $at, false))->forStepType($fields['step_type'] ?? null);
    }

    /**
     * The command for a step of $type: the readers apply it to the type a
     * request sends, the write path to the type an edited step has. A pause
     * step never takes On Failure or Always: the step form's are ignored
     * (null), and an API body that sends one that is not null is refused.
     * Any other type keeps the command as it is.
     *
     * @return self|string the command; PAUSE_BRANCHES_REFUSED
     */
    public function forStepType(mixed $type): self|string
    {
        if ($type !== WorkflowStep::TYPE_PAUSE) {
            return $this;
        }
        if ($this->form) {
            return new self(array_merge($this->fields, array_fill_keys(self::PAUSE_UNUSED_ROUTES, null)), $this->position, true);
        }
        $sent = array_filter(self::PAUSE_UNUSED_ROUTES, fn (string $field): bool => isset($this->fields[$field]));

        return $sent === [] ? $this : self::PAUSE_BRANCHES_REFUSED;
    }

    /**
     * Whether the request sets $field (it may set it to null).
     */
    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    /**
     * The value the request sets $field to; null when it leaves it out (see
     * has()).
     */
    public function value(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }

    /**
     * The fields the request sets, by attribute.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Where the step goes; null keeps it where it is (an edit or update that
     * names no position).
     */
    public function position(): ?WorkflowStepPosition
    {
        return $this->position;
    }

    /**
     * The $fields that $data sends, each read by $read.
     *
     * @template T
     * @param array<array-key, mixed> $data
     * @param list<string> $fields
     * @param callable(mixed): T $read
     * @return array<string, T>
     */
    private static function present(array $data, array $fields, callable $read): array
    {
        $values = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $values[$field] = $read($data[$field]);
            }
        }

        return $values;
    }

    /**
     * The routes $data sets (route()), or ROUTE_INVALID for the first one
     * that is no route. The step form ($form) sets every route: one it
     * leaves out or empty is null. An API body sets the ones it sends.
     *
     * @param array<array-key, mixed> $data
     * @return array<string, int|null>|string
     */
    private static function routes(array $data, bool $form): array|string
    {
        $routes = [];
        foreach (self::ROUTE_FIELDS as $field) {
            if (!$form && !array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field] ?? null;
            $route = self::route($form && $value === '' ? null : $value);
            if ($route === false) {
                return sprintf(self::ROUTE_INVALID, $field);
            }
            $routes[$field] = $route;
        }

        return $routes;
    }

    /**
     * The variable mapping $body sets: nothing when the body leaves it out,
     * else the text to store, null for none
     * (WorkflowStepsInput::extraVarsTemplate()); or why a JSON object cannot
     * be stored.
     *
     * @param array<array-key, mixed> $body
     * @return array<string, string|null>|string
     */
    private static function extraVars(array $body): array|string
    {
        if (!array_key_exists('extra_vars_template', $body)) {
            return [];
        }
        $read = WorkflowStepsInput::extraVarsTemplate($body['extra_vars_template']);

        return $read['error'] ?? ['extra_vars_template' => $read['value']];
    }

    /**
     * A route: null, or 0 or a step ID as an int or as a string of digits
     * whose value fits into an int (leading zeros are read away); false for
     * anything else, such as a boolean, a float, a sign, a space, an empty
     * string or a negative number.
     */
    private static function route(mixed $value): int|false|null
    {
        if ($value === null || (is_int($value) && $value >= 0)) {
            return $value;
        }
        if (!is_string($value) || !ctype_digit($value)) {
            return false;
        }
        $digits = ltrim($value, '0');
        if ($digits === '') {
            return 0;
        }

        return (string)(int)$digits === $digits ? (int)$digits : false;
    }
}
