<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\SurveySecrets;
use app\components\WorkflowRoute;
use app\components\WorkflowRouteStep;
use app\components\WorkflowStepChange;
use app\components\WorkflowStepCommand;
use app\components\WorkflowStepMapping;
use app\components\WorkflowStepPosition;
use app\controllers\api\v1\WorkflowStepsController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApprovalRule;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\JobLaunchSecrets;
use app\services\WorkflowRouteMap;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepChangeSummary;
use app\services\WorkflowStepReorderService;
use app\services\WorkflowStepWriteService;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\helpers\Inflector;
use yii\web\NotFoundHttpException;
use yii\web\Request;

/**
 * What web/openapi.yaml says about the step endpoints of workflow templates
 * (POST /api/v1/workflow-templates/{id}/steps, PUT and DELETE
 * .../steps/{stepId}, POST .../steps/{stepId}/move) and about the route
 * fields of every step is what the code answers: the paths and verbs are the
 * URL rules of config/web.php, every quoted refusal is the code's text, the
 * enums are the route map's values, and the documented example answers are
 * what the endpoints answer for the example workflow ("Build", "Release
 * approval", "Deploy").
 *
 * Regressions (2.11.0), each fails on the spec of 2.10.1:
 * - the spec said branches cannot be set through the API ("Set branches in
 *   the web UI."), and the API could change one step only by replacing all
 *   of them, which dropped every branch: the step endpoints were missing;
 * - a steps list "replaces all of them, branches included", without saying
 *   that the new steps get new IDs and no branches;
 * - position, is_start, reachable, cannot_run and routes of a step, and
 *   start_step_id and success_path of a workflow were not documented.
 *
 * Regressions (wave 5 of 2.11.0), each fails on the spec before the fix:
 * - step_type null or empty answers 'Step Type cannot be blank.', which the
 *   spec left out, saying any other value answers 'Step Type is invalid.';
 * - step_order was said to be renumbered "after every change", though an
 *   update that does not move the step renumbers nothing;
 * - a variable mapping must be an object of extra var names to output
 *   variable names (WorkflowStepMapping::INVALID), and [] is none;
 * - the gated job template is checked again under the lock (403);
 * - the warnings follow the branches and flag an approval step that is not
 *   on the success path; reachable is false behind a step that cannot run.
 *
 * Durable guard: every API URL rule of config/web.php is documented with its
 * verb, and every documented API operation has a URL rule with its verb that
 * routes to an action of its controller.
 */
class OpenApiWorkflowStepsContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use OpenApiWorkflowAssertions;

    private const STEPS = '/api/v1/workflow-templates/{id}/steps';
    private const STEP = '/api/v1/workflow-templates/{id}/steps/{stepId}';
    private const MOVE = '/api/v1/workflow-templates/{id}/steps/{stepId}/move';

    /** The workflow and the steps of the spec's examples. */
    private const EXAMPLE_WORKFLOW_ID = 5;
    private const BUILD_ID = 41;
    private const APPROVAL_ID = 42;
    private const DEPLOY_ID = 43;
    private const VERIFY_ID = 44;
    private const SMOKE_TEST_ID = 45;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->userWithRole('steps-contract-admin', 'admin');
        $this->loginAs($this->admin);
    }

    /**
     * Regression: the API could change one step only by replacing all of
     * them, and the spec said branches cannot be set through the API. The
     * four step endpoints are documented with their parameters, bodies and
     * answers, and the workflow template writes point to them.
     */
    public function testRegressionTheStepEndpointsAreDocumented(): void
    {
        $operations = [
            ['post', self::STEPS, 'WorkflowStepCreateInput', ['201', '401', '403', '404', '409', '422'], 'WorkflowStepWriteResponse'],
            ['put', self::STEP, 'WorkflowStepUpdateInput', ['200', '401', '403', '404', '409', '422'], 'WorkflowStepWriteResponse'],
            ['post', self::MOVE, 'WorkflowStepMoveInput', ['200', '401', '403', '404', '409', '422'], 'WorkflowStepWriteResponse'],
            ['delete', self::STEP, null, ['200', '401', '403', '404', '409'], 'WorkflowStepRemoveResponse'],
        ];
        foreach ($operations as [$method, $path, $input, $codes, $answer]) {
            $operation = $this->specAt(['paths', $path, $method]);
            $this->assertIsArray($operation);
            $this->assertSame(['Workflow Templates'], $operation['tags'] ?? null, "{$method} {$path}");
            $documented = array_map('strval', array_keys((array)($operation['responses'] ?? [])));
            $this->assertEqualsCanonicalizing($codes, $documented, "{$method} {$path} documents every answer.");
            $this->assertStringContainsString('Requires `workflow-template.update`', $this->specText(['paths', $path, $method, 'description']));
            $success = $this->responseOf($path, $method, $codes[0]);
            $this->assertSame(self::SCHEMA_REF_PREFIX . $answer, $this->dig($success, ['content', 'application/json', 'schema', '$ref'], "{$method} {$path} documents its answer."));
            if ($input !== null) {
                $this->assertSame(
                    self::SCHEMA_REF_PREFIX . $input,
                    $this->specAt(['paths', $path, $method, 'requestBody', 'content', 'application/json', 'schema', '$ref'])
                );
                $this->assertNotEmpty($this->specAt(['paths', $path, $method, 'requestBody', 'content', 'application/json', 'examples']), "{$method} {$path} has body examples.");
            }
        }
        $this->assertSame(['#/components/parameters/IdPath'], array_column((array)$this->specAt(['paths', self::STEPS, 'parameters']), '$ref'));
        foreach ([self::STEP, self::MOVE] as $path) {
            $this->assertSame(
                ['#/components/parameters/IdPath', '#/components/parameters/StepIdPath'],
                array_column((array)$this->specAt(['paths', $path, 'parameters']), '$ref'),
                $path
            );
        }
        $stepId = $this->specAt(['components', 'parameters', 'StepIdPath']);
        $this->assertIsArray($stepId);
        $this->assertSame(['stepId', 'path', true], [$stepId['name'] ?? null, $stepId['in'] ?? null, $stepId['required'] ?? null]);
        foreach (['WorkflowStepWriteResponse' => 'WorkflowStep', 'WorkflowStepRemoveResponse' => 'WorkflowStepDeletion'] as $schema => $data) {
            $this->assertSame(self::SCHEMA_REF_PREFIX . $data, $this->properties($schema)['data']['$ref'] ?? null, $schema);
            $this->assertSame(self::SCHEMA_REF_PREFIX . 'WorkflowStepChange', $this->properties($schema)['change']['$ref'] ?? null, $schema);
        }

        $create = $this->specText(['paths', '/api/v1/workflow-templates', 'post', 'description']);
        $this->assertStringNotContainsString('Set branches in the web UI.', $create);
        $this->assertStringNotContainsString('cannot be set through the API', $create);
        $this->assertStringContainsString('`POST /api/v1/workflow-templates/{id}/steps`', $create);
        $this->assertStringContainsString('`PUT /api/v1/workflow-templates/{id}/steps/{stepId}`', $create);
    }

    /**
     * Regression: a steps list "replaces all of them, branches included"
     * said nothing of the new IDs and of the branches it drops, so clients
     * replaced the steps to change one and lost every branch.
     */
    public function testRegressionAStepListSaysItGivesNewIdsAndNoBranches(): void
    {
        $update = $this->specText(['paths', '/api/v1/workflow-templates/{id}', 'put', 'description']);
        $this->assertStringContainsString('the listed steps are written as new steps, with new IDs and without branches, so the branches that led to the old steps are gone too', $update);
        $this->assertStringContainsString('use `PUT /api/v1/workflow-templates/{id}/steps/{stepId}`', $update);
        $this->assertStringNotContainsString('replaces all of them, branches included', $update);
        $this->assertStringContainsString(
            'the listed steps are written as new steps, with new IDs and without branches',
            $this->specText(['components', 'schemas', 'WorkflowTemplateInput', 'properties', 'steps', 'description'])
        );
        $this->assertStringContainsString(
            'branches, `position` and `after_step_id` are not read here: the steps get none',
            $this->specText(['components', 'schemas', 'WorkflowStepInput', 'description'])
        );
        $this->assertStringContainsString(
            'A `steps` list on `POST` or `PUT /api/v1/workflow-templates` replaces every step instead: the steps get new IDs and no branches.',
            $this->tagText('Workflow Templates')
        );
    }

    /**
     * Durable guard: every API rule of config/web.php is documented with its
     * verb (a rule without a verb with GET), every documented API operation
     * has such a rule, and each rule routes to an action of its controller.
     */
    public function testEveryApiRuleIsDocumentedWithItsVerb(): void
    {
        $config = $this->webConfig();
        $ruled = [];
        foreach ($config['components']['urlManager']['rules'] as $rule) {
            if (!is_array($rule) || !str_starts_with((string)($rule['pattern'] ?? ''), 'api/v1/')) {
                continue;
            }
            $path = '/' . preg_replace('/<(\w+):[^>]+>/', '{$1}', (string)$rule['pattern']);
            foreach ((array)($rule['verb'] ?? 'GET') as $verb) {
                $ruled[strtolower((string)$verb) . ' ' . $path] = (string)$rule['route'];
            }
        }
        $documented = [];
        foreach ((array)$this->specAt(['paths']) as $path => $item) {
            foreach (array_intersect(array_keys((array)$item), ['get', 'post', 'put', 'patch', 'delete']) as $method) {
                if (str_starts_with((string)$path, '/api/v1/')) {
                    $documented[] = "{$method} {$path}";
                }
            }
        }
        $this->assertSame([], array_values(array_diff(array_keys($ruled), $documented)), 'Every API rule of config/web.php is documented with its verb.');
        $this->assertSame([], array_values(array_diff($documented, array_keys($ruled))), 'Every documented API operation has a URL rule with its verb.');
        foreach ($ruled as $operation => $route) {
            $slash = (int)strrpos($route, '/');
            $class = $config['controllerMap'][substr($route, 0, $slash)] ?? null;
            $this->assertIsString($class, "{$operation}: {$route} names a controller of the controller map.");
            $this->assertTrue(method_exists($class, 'action' . Inflector::id2camel(substr($route, $slash + 1))), "{$operation} routes to an action of {$class}.");
        }
        $this->assertSame('api/v1/workflow-steps/move', $ruled['post ' . self::MOVE] ?? null);
        $this->assertSame(WorkflowStepsController::class, $config['controllerMap']['api/v1/workflow-steps'] ?? null);
    }

    /**
     * Every refusal the step endpoints document is the code's text, and
     * every text the code answers there is documented: the readers of the
     * body, the model's rules, the placement, the policy and the guard.
     */
    public function testTheStepRefusalsAreQuotedFromTheCode(): void
    {
        $body = [
            WorkflowStepPosition::BOTH_GIVEN,
            WorkflowStepPosition::POSITION_INVALID,
            WorkflowStepPosition::AFTER_INVALID,
            WorkflowStepCommand::PAUSE_BRANCHES_REFUSED,
            $this->mappingTooLarge(),
        ];
        $model = [
            $this->firstError(['name' => '']),
            WorkflowStep::JOB_TEMPLATE_REQUIRED,
            WorkflowStep::JOB_TEMPLATE_NOT_FOUND,
            WorkflowStep::APPROVAL_RULE_NOT_FOUND,
            WorkflowStep::APPROVAL_ALWAYS_REFUSED,
            WorkflowStep::ROUTE_TARGET_OUTSIDE,
            sprintf(WorkflowStepReorderService::AFTER_STEP_MISSING, 12),
            WorkflowStepMapping::INVALID,
        ];
        $this->assertEqualsCanonicalizing(
            array_merge($body, $model, [
                sprintf(WorkflowStepCommand::ROUTE_INVALID, 'on_failure_step_id'),
                $this->firstError(['step_type' => 'JOB']),
                $this->firstError(['step_type' => '']),
                WorkflowStep::APPROVAL_RULE_REQUIRED,
                sprintf(WorkflowStepReorderService::POSITION_OUT_OF_RANGE, 4),
            ]),
            $this->exampleMessages(self::STEPS, 'post', '422')
        );
        $this->assertEqualsCanonicalizing(
            array_merge($body, $model, [
                sprintf(WorkflowStepCommand::ROUTE_INVALID, 'on_success_step_id'),
                WorkflowStepCommand::TYPE_LOCKED,
                WorkflowStepReorderService::AFTER_ITSELF,
                sprintf(WorkflowStepReorderService::POSITION_OUT_OF_RANGE, 3),
            ]),
            $this->exampleMessages(self::STEP, 'put', '422')
        );
        $this->assertEqualsCanonicalizing([
            WorkflowStepPosition::MOVE_NEEDS_ONE,
            WorkflowStepPosition::POSITION_INVALID,
            WorkflowStepPosition::AFTER_INVALID,
            WorkflowStepPosition::DIRECTION_INVALID,
            sprintf(WorkflowStepReorderService::AFTER_STEP_MISSING, 12),
            WorkflowStepReorderService::AFTER_ITSELF,
            sprintf(WorkflowStepReorderService::POSITION_OUT_OF_RANGE, 3),
        ], $this->exampleMessages(self::MOVE, 'post', '422'));
        foreach ([[self::STEPS, 'post'], [self::STEP, 'put']] as [$path, $method]) {
            $errors = $this->exampleErrors($path, $method, '422');
            $this->assertContains(['message' => WorkflowStep::JOB_TEMPLATE_NOT_FOUND, 'job_template_ids' => [12]], $errors, "{$method} {$path}");
        }
        $this->assertTheForbiddenAnswersAreQuoted();
        $this->assertTheMissingAndConflictingAnswersAreQuoted();
    }

    /**
     * The request schemas quote what their readers refuse, and what the
     * model refuses of the fields they set.
     */
    public function testTheBodiesQuoteTheReadersRefusals(): void
    {
        $schemas = 'components.schemas.';
        $quoted = [
            'WorkflowStepCreateInput.description' => [WorkflowStepPosition::BOTH_GIVEN],
            'WorkflowStepCreateInput.properties.name.description' => [$this->firstError(['name' => ''])],
            'WorkflowStepCreateInput.properties.step_type.description' => [$this->firstError(['step_type' => 'JOB']), $this->firstError(['step_type' => ''])],
            'WorkflowStepCreateInput.properties.job_template_id.description' => [WorkflowStep::JOB_TEMPLATE_REQUIRED],
            'WorkflowStepCreateInput.properties.approval_rule_id.description' => [WorkflowStep::APPROVAL_RULE_REQUIRED, WorkflowStep::APPROVAL_RULE_NOT_FOUND],
            'WorkflowStepCreateInput.properties.on_always_step_id.description' => [WorkflowStep::APPROVAL_ALWAYS_REFUSED],
            'WorkflowStepCreateInput.properties.extra_vars_template.description' => [
                $this->mappingTooLarge(),
                $this->firstError(['extra_vars_template' => '{no json']),
                WorkflowStepMapping::INVALID,
            ],
            'WorkflowStepInput.properties.extra_vars_template.description' => ['steps[<n>]: ' . WorkflowStepMapping::INVALID],
            'WorkflowStepUpdateInput.description' => [WorkflowStepPosition::BOTH_GIVEN],
            'WorkflowStepUpdateInput.properties.name.description' => [$this->firstError(['name' => ''])],
            'WorkflowStepUpdateInput.properties.step_type.description' => [WorkflowStepCommand::TYPE_LOCKED],
            'WorkflowStepUpdateInput.properties.job_template_id.description' => [WorkflowStep::JOB_TEMPLATE_REQUIRED],
            'WorkflowStepUpdateInput.properties.on_always_step_id.description' => [WorkflowStep::APPROVAL_ALWAYS_REFUSED],
            'WorkflowStepMoveInput.description' => [WorkflowStepPosition::MOVE_NEEDS_ONE],
            'WorkflowStepMoveInput.properties.direction.description' => [WorkflowStepPosition::DIRECTION_INVALID],
            'WorkflowStepPositionInput.description' => [WorkflowStepPosition::POSITION_INVALID, sprintf(WorkflowStepReorderService::POSITION_OUT_OF_RANGE, 4)],
            'WorkflowStepAfterInput.description' => [
                sprintf(WorkflowStepReorderService::AFTER_STEP_MISSING, 12),
                WorkflowStepReorderService::AFTER_ITSELF,
                WorkflowStepPosition::AFTER_INVALID,
            ],
            'WorkflowStepBranchInput.description' => [
                WorkflowStep::ROUTE_TARGET_OUTSIDE,
                sprintf(WorkflowStepCommand::ROUTE_INVALID, 'on_failure_step_id'),
                WorkflowStepCommand::PAUSE_BRANCHES_REFUSED,
            ],
        ];
        foreach ($quoted as $where => $texts) {
            $text = $this->specText(explode('.', $schemas . $where));
            foreach ($texts as $quote) {
                $this->assertStringContainsString('`' . $quote . '`', $text, $where);
            }
        }
        $this->assertSame([WorkflowStepPosition::KIND_UP, WorkflowStepPosition::KIND_DOWN], $this->enumOf('WorkflowStepMoveInput', 'direction'));
        $this->assertContains(
            ['type' => 'string', 'enum' => [WorkflowStepPosition::KIND_START, WorkflowStepPosition::KIND_END]],
            (array)$this->specAt(['components', 'schemas', 'WorkflowStepPositionInput', 'oneOf'])
        );
    }

    /**
     * The endpoints' own texts: what they refuse in words, the answers of a
     * change that writes nothing, and the order of the answers, which is the
     * controller's (testTheRefusedChangesAnswerInTheDocumentedOrder()) and
     * the guard's under the lock (WorkflowStepChangeGuardTest).
     *
     * regression: the orders left out the 403 `Forbidden.` that the step
     * write path answers, with the workflow locked, to a caller who lost
     * operator access meanwhile (WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED):
     * after the 404 for a workflow deleted meanwhile, before the 403 of rule
     * 12 and the 409.
     *
     * regression (wave 5): the gated job template of an added or edited job
     * step is checked again under the lock (WorkflowStepWriteService), after
     * the 409 (and on PUT the 404 for a step removed meanwhile), with the
     * 403 of rule 12 (WorkflowStepGatedTemplateRaceApiTest pins the answer).
     */
    public function testTheEndpointsDescribeTheirAnswersInTheCodesWords(): void
    {
        $post = $this->specText(['paths', self::STEPS, 'post', 'description']);
        $put = $this->specText(['paths', self::STEP, 'put', 'description']);
        $policy = [
            ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED,
            ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED,
            ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED,
            // 2.12.0: job steps gated through their job template's inventory.
            ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED,
            ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED,
        ];
        foreach (array_merge($policy, [ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED, WorkflowStep::JOB_TEMPLATE_NOT_FOUND, WorkflowStepWriteService::JOB_TEMPLATE_FORBIDDEN]) as $quote) {
            $this->assertStringContainsString('`' . $quote . '`', $post);
        }
        foreach (array_merge($policy, [WorkflowStepCommand::TYPE_LOCKED, WorkflowStep::APPROVAL_ALWAYS_REFUSED, sprintf(WorkflowStepChangeSummary::UNCHANGED, 'Deploy')]) as $quote) {
            $this->assertStringContainsString('`' . $quote . '`', $put);
        }
        $this->assertStringContainsString(
            '`' . sprintf(WorkflowStepChangeSummary::STAYS, 'Deploy', 3) . '`',
            $this->specText(['paths', self::MOVE, 'post', 'description'])
        );
        $this->assertStringContainsString('`' . $this->routedToExample() . '`', $this->specText(['paths', self::STEP, 'delete', 'description']));

        $orders = [
            [self::STEPS, 'post', '422 for a body that cannot be read; 403 for an approval step, a gated job step or a workflow that has one; 422 or 403 for the job template; 422 for a step that is not valid; 404 for a workflow deleted meanwhile; 403 `Forbidden.` for operator access lost meanwhile; 403 for steps locked meanwhile; 409 for an active run; 403 for a job template that got an approval rule meanwhile; 422 for a step that cannot be saved or a place it cannot take.'],
            [self::STEP, 'put', '404 for a step the workflow does not have; 422 for a body that cannot be read or another step type; 403 for a workflow whose steps are for admins, or a gated job template; 422 or 403 for a new job template; 422 for a step that is not valid; 404 for a workflow deleted meanwhile; 403 `Forbidden.` for operator access lost meanwhile; 403 for steps locked meanwhile; 409 for an active run; 404 for a step removed meanwhile; 403 for a job template the update gives a job step that got an approval rule meanwhile; 422 for a step that cannot be saved or a place it cannot take.'],
            [self::MOVE, 'post', '404 for a step the workflow does not have; 422 for a body that cannot be read; 404 for a workflow deleted meanwhile; 403 `Forbidden.` for operator access lost meanwhile; 403 for a workflow whose steps are for admins; 409 for an active run; 404 for a step removed meanwhile; 422 for a place the step cannot take.'],
            [self::STEP, 'delete', '404 for a step the workflow does not have; 404 for a workflow deleted meanwhile; 403 `Forbidden.` for operator access lost meanwhile; 403 for a workflow whose steps are for admins; 409 for an active run; 404 for a step removed meanwhile; 409 for a step that other steps route to.'],
        ];
        $start = 'The answers come in this order: 401 without a valid token; 403 `Forbidden.` without `workflow-template.update`; 404 for an unknown workflow; 403 `Forbidden.` without operator access to the workflow; ';
        foreach ($orders as [$path, $method, $rest]) {
            $this->assertStringContainsString($start . $rest, $this->specText(['paths', $path, $method, 'description']), "{$method} {$path}");
        }
        $this->assertSame('Forbidden.', WorkflowStepsController::FORBIDDEN);
        $this->assertSame(WorkflowStepsController::FORBIDDEN, WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED, 'the 403 under the lock quotes the same text');
        $this->assertStringContainsString(
            'Whether a job step\'s job template is gated is checked again while the workflow is locked: an approval rule assigned to it meanwhile answers the same 403 `'
                . ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED . '`, and nothing is written.',
            $post
        );
        $this->assertStringContainsString(
            'This is checked again while the workflow is locked: an approval rule assigned to that job template meanwhile answers the same 403, and nothing is written.',
            $put
        );
        $this->assertStringContainsString(
            'The variable mapping is compared by its JSON value, so a step sent back as `GET` answers it (`extra_vars_template: []` for none, or the stored mapping without its spaces) is unchanged.',
            $put
        );
    }

    /**
     * The route fields are the route map's: the enums of a route and of the
     * success path, why a step cannot run, and the fields of each.
     */
    public function testTheRouteFieldsAreTheRouteMapsValues(): void
    {
        $this->assertSame([WorkflowRoute::KIND_STEP, WorkflowRoute::KIND_END, WorkflowRoute::KIND_MISSING], $this->enumOf('WorkflowRouteTarget', 'kind'));
        $this->assertSame(
            [WorkflowRoute::VIA_ON_SUCCESS, WorkflowRoute::VIA_ON_FAILURE, WorkflowRoute::VIA_ON_ALWAYS, WorkflowRoute::VIA_NEXT],
            $this->enumOf('WorkflowRouteTarget', 'via')
        );
        $this->assertSame([WorkflowRoute::RUN_SUCCEEDS, WorkflowRoute::RUN_FAILS], $this->enumOf('WorkflowRouteTarget', 'run_outcome'));
        $this->assertSame(
            [WorkflowRouteMap::PATH_SUCCEEDS, WorkflowRouteMap::PATH_LOOP, WorkflowRouteMap::PATH_MISSING, WorkflowRouteMap::PATH_CANNOT_RUN],
            $this->enumOf('WorkflowSuccessPath', 'end')
        );
        $this->assertSame(
            [
                WorkflowRouteStep::PROBLEM_JOB_TEMPLATE_MISSING,
                WorkflowRouteStep::PROBLEM_APPROVAL_RULE_MISSING,
                WorkflowRouteStep::PROBLEM_APPROVAL_RULES_DIFFER,
                WorkflowRouteStep::PROBLEM_UNKNOWN_TYPE,
            ],
            $this->enumOf('WorkflowStep', 'cannot_run')
        );
        $route = new WorkflowRoute(WorkflowRoute::KIND_STEP, WorkflowRoute::VIA_NEXT, 43, 3, null, false);
        $this->assertMatchesSchema('WorkflowRouteTarget', $route->toArray());
        $this->assertSame($route->toArray(), $this->specAt(['components', 'schemas', 'WorkflowRouteTarget', 'example']), 'The example is a route the map gives.');
        $map = WorkflowRouteMap::fromSteps([new WorkflowRouteStep(self::BUILD_ID, 'Build', WorkflowStep::TYPE_JOB, null, null, null)])->toApi();
        $this->assertMatchesSchema('WorkflowSuccessPath', $map['success_path']);
        $this->assertSame(
            [WorkflowRouteMap::OUTCOME_SUCCESS, WorkflowRouteMap::OUTCOME_FAILURE, WorkflowRouteMap::OUTCOME_CLOSED_GATE],
            array_keys($this->properties('WorkflowStepRoutes'))
        );
        $this->assertSame(array_keys($map['steps'][self::BUILD_ID]['routes']), $this->required('WorkflowStepRoutes'));
        $this->assertStringContainsString(
            '`The step this branch leads to no longer exists (step ID 7).`',
            $this->specText(['components', 'schemas', 'WorkflowRouteTarget', 'properties', 'kind', 'description'])
        );
        // regression (wave 5): a step that cannot run leads nowhere, so a step only it leads to is not reachable.
        $broken = WorkflowRouteMap::fromSteps([
            new WorkflowRouteStep(self::BUILD_ID, 'Build', WorkflowStep::TYPE_JOB, null, null, null, null, WorkflowRouteStep::PROBLEM_JOB_TEMPLATE_MISSING),
            new WorkflowRouteStep(self::DEPLOY_ID, 'Deploy', WorkflowStep::TYPE_JOB, null, null, null),
        ])->toApi();
        $this->assertSame([true, false], [$broken['steps'][self::BUILD_ID]['reachable'], $broken['steps'][self::DEPLOY_ID]['reachable']]);
        $this->assertStringContainsString(
            'A step that cannot run (`cannot_run`) leads nowhere, as a run fails there: a step that only it leads to is not reachable.',
            $this->specText(['components', 'schemas', 'WorkflowStep', 'properties', 'reachable', 'description'])
        );
    }

    /**
     * The change messages the spec documents are what the summary of the
     * change says, word for word, for the example workflow.
     */
    public function testTheDocumentedChangesAreTheSummarysWords(): void
    {
        $build = self::exampleStep(self::BUILD_ID, 'Build', WorkflowStep::TYPE_JOB);
        $approval = self::exampleStep(self::APPROVAL_ID, 'Release approval', WorkflowStep::TYPE_APPROVAL);
        $deploy = self::exampleStep(self::DEPLOY_ID, 'Deploy', WorkflowStep::TYPE_JOB, 0);
        $added = self::summary(WorkflowStepChange::ADDED, $deploy, 0, 3, [$build, $approval], [$build, $approval, $deploy]);
        $moved = self::summary(WorkflowStepChange::MOVED, $deploy, 3, 1, [$build, $approval, $deploy], [$deploy, $build, $approval]);
        $removed = self::summary(WorkflowStepChange::REMOVED, $deploy, 3, 0, [$build, $approval, $deploy], [$build, $approval]);

        $this->assertSame($added, $this->writtenExample('added')['change']);
        $this->assertSame($moved, $this->writtenExample('movedToStart')['change']);
        $deleted = $this->exampleBodies($this->responseOf(self::STEP, 'delete', '200'));
        $this->assertSame([['data' => ['deleted' => true, 'id' => self::DEPLOY_ID], 'change' => $removed]], $deleted);
        $this->assertSame($added['message'], $this->specAt(['components', 'schemas', 'WorkflowStepChange', 'properties', 'message', 'example']));
        $this->assertSame([$moved['warnings'][0]], $this->specAt(['components', 'schemas', 'WorkflowStepChange', 'properties', 'warnings', 'example']));

        $smokeTest = self::exampleStep(self::SMOKE_TEST_ID, 'Smoke test', WorkflowStep::TYPE_JOB);
        $renamed = self::exampleStep(self::DEPLOY_ID, 'Deploy web', WorkflowStep::TYPE_JOB, self::BUILD_ID);
        $examples = [
            $added['message'],
            self::summary(WorkflowStepChange::ADDED, $smokeTest, 0, 2, [$build, $deploy], [$build, $smokeTest, $deploy])['message'],
            self::firstSentence($moved['message']),
            sprintf(WorkflowStepChangeSummary::SAVED, 'Deploy web', 'name, On Failure'),
            self::summary(WorkflowStepChange::UPDATED, $renamed, 3, 3, [$build, $approval, $deploy], [$build, $approval, $renamed], [
                'name' => ['from' => 'Deploy', 'to' => 'Deploy web'],
                'on_failure_step_id' => ['from' => 0, 'to' => self::BUILD_ID],
            ])['message'],
            sprintf(WorkflowStepChangeSummary::SAVED_MOVED, 'Deploy', 'On Success', 2, 3, sprintf(WorkflowStepChangeSummary::AFTER, 'Build')),
            $removed['message'],
            sprintf(WorkflowStepChangeSummary::UNCHANGED, 'Deploy'),
            sprintf(WorkflowStepChangeSummary::STAYS, 'Deploy', 3),
        ];
        $message = $this->specText(['components', 'schemas', 'WorkflowStepChange', 'properties', 'message', 'description']);
        foreach ($examples as $example) {
            $this->assertStringContainsString('`' . $example . '`', $message);
        }
        $warnings = $this->specText(['components', 'schemas', 'WorkflowStepChange', 'properties', 'warnings', 'description']);
        $quoted = [
            sprintf(WorkflowStepChangeSummary::JOB_WITHOUT_APPROVAL, 'Deploy'),
            sprintf(WorkflowStepChangeSummary::APPROVAL_OFF_SUCCESS_PATH, 'Release approval'),
        ];
        foreach (array_merge($moved['warnings'], $quoted) as $warning) {
            $this->assertStringContainsString('`' . $warning . '`', $warnings);
        }
        $this->assertStringContainsString('The branches decide, not the positions alone', $warnings);
        // regression (wave 5): an approval step added where runs that succeed do not pass it.
        $release = self::exampleStep(self::APPROVAL_ID, 'Release approval', WorkflowStep::TYPE_APPROVAL);
        $ending = self::exampleStep(self::BUILD_ID, 'Build', WorkflowStep::TYPE_JOB, null, 0);
        $offPath = self::summary(WorkflowStepChange::ADDED, $release, 0, 2, [$ending], [$ending, $release]);
        $this->assertSame([sprintf(WorkflowStepChangeSummary::APPROVAL_OFF_SUCCESS_PATH, 'Release approval')], $offPath['warnings']);
    }

    /**
     * The step endpoints answer exactly as their schemas say, and as the
     * documented examples of the example workflow show: an add, a move to
     * the start, an update and a move that write nothing, and a removal.
     */
    public function testTheStepAnswersAreTheDocumentedOnes(): void
    {
        ['id' => $id, 'build' => $build, 'approval' => $approval, 'deployTemplate' => $templateId] = $this->exampleWorkflow();
        $added = $this->call('create', ['name' => 'Deploy', 'step_type' => 'job', 'job_template_id' => $templateId, 'on_failure_step_id' => 0, 'after_step_id' => $approval], $id);
        $this->assertSame(201, $added['status']);
        $this->assertWritten($added['body']);
        $step = $added['body']['data'];
        $this->assertIsArray($step);
        $deploy = (int)$step['id'];
        $ids = [self::BUILD_ID => $build, self::APPROVAL_ID => $approval, self::DEPLOY_ID => $deploy, 15 => $templateId];
        $this->assertSame($this->withIds($this->writtenExample('added'), $ids), $added['body']);

        $moved = $this->call('move', ['position' => 'start'], $id, $deploy);
        $this->assertSame(200, $moved['status']);
        $this->assertWritten($moved['body']);
        $this->assertSame($this->withIds($this->writtenExample('movedToStart'), $ids), $moved['body']);

        $unchanged = $this->call('update', ['name' => 'Deploy', 'position' => 1], $id, $deploy);
        $this->assertWritten($unchanged['body']);
        $this->assertSame([200, ['message' => sprintf(WorkflowStepChangeSummary::UNCHANGED, 'Deploy'), 'warnings' => []]], [$unchanged['status'], $unchanged['body']['change']]);
        $stays = $this->call('move', ['direction' => 'up'], $id, $deploy);
        $this->assertWritten($stays['body']);
        $this->assertSame([200, ['message' => sprintf(WorkflowStepChangeSummary::STAYS, 'Deploy', 1), 'warnings' => []]], [$stays['status'], $stays['body']['change']]);

        $this->assertSame(200, $this->call('move', ['position' => 'end'], $id, $deploy)['status']);
        $removed = $this->call('delete', [], $id, $deploy);
        $this->assertSame(200, $removed['status']);
        $this->assertMatchesSchema('WorkflowStepRemoveResponse', $removed['body']);
        $this->assertMatchesNested('WorkflowStepRemoveResponse', 'data', $removed['body']['data']);
        $this->assertMatchesNested('WorkflowStepRemoveResponse', 'change', $removed['body']['change']);
        $documented = $this->exampleBodies($this->responseOf(self::STEP, 'delete', '200'))[0];
        $this->assertSame($this->withIds($documented, $ids), $removed['body']);

        $detail = (new WorkflowTemplatesController('api/v1/workflow-templates', $this->app()))->actionView($id);
        $workflow = $this->data($detail);
        $this->assertMatchesSchema('WorkflowTemplateDetail', $workflow);
        $this->assertMatchesNested('WorkflowTemplateDetail', 'success_path', $workflow['success_path']);
        $this->assertSame(['step_ids' => [$build, $approval], 'end' => WorkflowRouteMap::PATH_SUCCEEDS, 'loop_to_step_id' => null, 'missing_step_id' => null], $workflow['success_path']);
        $this->assertSame($build, $workflow['start_step_id']);
        foreach ((array)$workflow['steps'] as $step) {
            $this->assertIsArray($step);
            $this->assertStep($step);
        }
    }

    /**
     * The refusals answer with the documented status codes, shapes and
     * texts, in the documented order: the workflow before the step, the
     * step before the body, the body before the policy.
     */
    public function testTheRefusedChangesAnswerInTheDocumentedOrder(): void
    {
        ['id' => $id, 'build' => $build, 'approval' => $approval] = $this->exampleWorkflow();
        try {
            $this->call('create', ['name' => 'Deploy'], 999999999);
            $this->fail('An unknown workflow answers 404.');
        } catch (NotFoundHttpException $e) {
            $this->assertSame(sprintf(WorkflowStepChangeGuard::TEMPLATE_GONE, 999999999), $e->getMessage());
        }
        $unknown = $this->call('update', ['position' => 0], $id, 999999999);
        $this->assertSame([404, ['error' => ['message' => sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, $id, 999999999)]]], [$unknown['status'], $unknown['body']]);

        $this->loginAs($this->userWithRole('steps-contract-operator', 'operator'));
        $unread = $this->call('create', ['name' => 'Deploy', 'position' => 2, 'after_step_id' => $build], $id);
        $this->assertSame([422, ['error' => ['message' => WorkflowStepPosition::BOTH_GIVEN]]], [$unread['status'], $unread['body']], 'The body before the policy.');
        $locked = $this->call('create', ['name' => 'Deploy', 'step_type' => 'pause'], $id);
        $this->assertSame([403, ['error' => ['message' => ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED]]], [$locked['status'], $locked['body']]);
        $this->assertContains(ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED, $this->exampleMessages(self::STEPS, 'post', '403'));

        $this->loginAs($this->admin);
        $buildStep = WorkflowStep::findOne($build);
        $this->assertNotNull($buildStep);
        $buildStep->on_success_step_id = $approval;
        $this->assertTrue($buildStep->save(false));
        $routedTo = $this->call('delete', [], $id, $approval);
        $this->assertSame(409, $routedTo['status']);
        $this->assertMatchesSchema('WorkflowStepRoutedToError', $routedTo['body']);
        $error = $routedTo['body']['error'];
        $this->assertIsArray($error);
        $this->assertMatchesProperties($this->errorProperties('WorkflowStepRoutedToError'), $error, 'WorkflowStepRoutedToError.error');
        $this->assertSame(
            ['message' => sprintf(WorkflowStepChangeGuard::STEP_ROUTED_TO, '"Release approval"', 'step 1 "Build"'), 'step_ids' => [$build]],
            $error
        );

        $run = $this->createRunningRun($id);
        $active = $this->call('move', ['direction' => 'down'], $id, $build);
        $this->assertSame(409, $active['status']);
        $this->assertMatchesSchema('WorkflowStepsLockedError', $active['body']);
        $error = $active['body']['error'];
        $this->assertIsArray($error);
        $this->assertMatchesProperties($this->errorProperties('WorkflowStepsLockedError'), $error, 'WorkflowStepsLockedError.error');
        $this->assertSame(['message' => WorkflowStepChangeGuard::runsActiveMessage([$run]), 'workflow_job_ids' => [$run]], $error);
    }

    /**
     * regression (W5-API-6): step_type null or empty answers 'Step Type
     * cannot be blank.' and another unknown value 'Step Type is invalid.',
     * as WorkflowStepCreateInput says now (it said any other value answers
     * 'Step Type is invalid.'). A variable mapping of another shape answers
     * WorkflowStepMapping::INVALID, and [], which GET answers for none, is
     * no mapping, which the three input schemas take.
     */
    public function testRegressionTheStepTypeAndTheMappingAnswerAsTheSpecSays(): void
    {
        ['id' => $id, 'build' => $build] = $this->exampleWorkflow();
        $blank = $this->firstError(['step_type' => '']);
        foreach ([null, ''] as $type) {
            $answer = $this->call('create', ['name' => 'Deploy', 'step_type' => $type], $id);
            $this->assertSame([422, ['error' => ['message' => $blank]]], [$answer['status'], $answer['body']], var_export($type, true));
        }
        $invalid = $this->call('create', ['name' => 'Deploy', 'step_type' => 'JOB'], $id);
        $this->assertSame([422, ['error' => ['message' => $this->firstError(['step_type' => 'JOB'])]]], [$invalid['status'], $invalid['body']]);
        $this->assertStringContainsString(
            'null or an empty value answers 422 `' . $blank . '`; any other value 422 `' . $this->firstError(['step_type' => 'JOB']) . '`',
            $this->specText(['components', 'schemas', 'WorkflowStepCreateInput', 'properties', 'step_type', 'description'])
        );

        $list = $this->call('update', ['extra_vars_template' => ['build_version']], $id, $build);
        $this->assertSame([422, ['error' => ['message' => WorkflowStepMapping::INVALID]]], [$list['status'], $list['body']]);
        foreach (['WorkflowStepCreateInput', 'WorkflowStepUpdateInput', 'WorkflowStepInput'] as $schema) {
            $this->assertContains(
                ['type' => 'array', 'maxItems' => 0],
                (array)$this->specAt(['components', 'schemas', $schema, 'properties', 'extra_vars_template', 'oneOf']),
                "{$schema} takes the [] that GET answers for no mapping."
            );
        }
        $none = WorkflowStepCommand::fromApi(['name' => 'Deploy', 'extra_vars_template' => []], true);
        $this->assertInstanceOf(WorkflowStepCommand::class, $none);
        $this->assertNull($none->value('extra_vars_template'), '[] is no mapping.');
    }

    /**
     * regression (W5-API-6): WorkflowStep.step_order said the step endpoints
     * renumber the steps "after every change". An update that does not move
     * the step, and a move to where it stands, renumber nothing; a move
     * renumbers the steps 10, 20, … in run order.
     */
    public function testRegressionStepOrderIsRenumberedByAddsMovesAndRemovesOnly(): void
    {
        $text = $this->specText(['components', 'schemas', 'WorkflowStep', 'properties', 'step_order', 'description']);
        $this->assertStringContainsString(
            'in run order after every add, move and remove of a step; an update that does not move the step, and a move to where the step stands, leave the numbers as they are.',
            $text
        );
        $this->assertStringNotContainsString('after every change', $text);

        ['id' => $id, 'build' => $build, 'approval' => $approval] = $this->exampleWorkflow();
        WorkflowStep::updateAll(['step_order' => 0], ['id' => $build]);
        WorkflowStep::updateAll(['step_order' => 1], ['id' => $approval]);
        $this->assertSame(200, $this->call('update', ['name' => 'Build web'], $id, $build)['status']);
        $this->assertSame(200, $this->call('move', ['position' => 1], $id, $build)['status']);
        $this->assertSame([0, 1], $this->stepOrders($id), 'an update without a place and a move that stays renumber nothing');
        $this->assertSame(200, $this->call('move', ['direction' => 'down'], $id, $build)['status']);
        $step = WorkflowStepReorderService::ORDER_STEP;
        $this->assertSame([$step, 2 * $step], $this->stepOrders($id), 'a move renumbers the steps in run order');
    }

    /**
     * The 403 examples of the step endpoints: the gate, the policy (rule
     * 12) and the job template of a step.
     */
    private function assertTheForbiddenAnswersAreQuoted(): void
    {
        $locks = [
            WorkflowStepsController::FORBIDDEN,
            ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED,
            ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED,
            ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED,
        ];
        $gatedJobSteps = [
            ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED,
            ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED,
        ];
        $this->assertEqualsCanonicalizing(
            array_merge($locks, $gatedJobSteps, [
                ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED,
                WorkflowStepWriteService::JOB_TEMPLATE_FORBIDDEN,
            ]),
            $this->exampleMessages(self::STEPS, 'post', '403')
        );
        $this->assertEqualsCanonicalizing(
            array_merge($locks, $gatedJobSteps, [WorkflowStepWriteService::JOB_TEMPLATE_FORBIDDEN]),
            $this->exampleMessages(self::STEP, 'put', '403')
        );
        foreach ([[self::MOVE, 'post'], [self::STEP, 'delete']] as [$path, $method]) {
            $this->assertEqualsCanonicalizing($locks, $this->exampleMessages($path, $method, '403'), "{$method} {$path}");
        }
        foreach ([[self::STEPS, 'post'], [self::STEP, 'put']] as [$path, $method]) {
            $errors = $this->exampleErrors($path, $method, '403');
            $this->assertContains(['message' => WorkflowStepWriteService::JOB_TEMPLATE_FORBIDDEN, 'job_template_ids' => [15]], $errors, "{$method} {$path}");
        }
    }

    /**
     * The 404 and 409 examples of the step endpoints: an unknown workflow in
     * the framework's shape, the step, the guard and the routed-to refusal.
     */
    private function assertTheMissingAndConflictingAnswersAreQuoted(): void
    {
        $gone = sprintf(WorkflowStepChangeGuard::TEMPLATE_GONE, self::EXAMPLE_WORKFLOW_ID);
        $framework = ['name' => 'Not Found', 'message' => $gone, 'code' => 0, 'status' => 404];
        $step = sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, self::EXAMPLE_WORKFLOW_ID, self::DEPLOY_ID);
        $this->assertSame(
            [$framework, ['error' => ['message' => $gone]]],
            $this->exampleBodies($this->responseOf(self::STEPS, 'post', '404'))
        );
        foreach ([[self::STEP, 'put'], [self::MOVE, 'post'], [self::STEP, 'delete']] as [$path, $method]) {
            $this->assertSame(
                [$framework, ['error' => ['message' => $step]], ['error' => ['message' => $gone]], ['error' => ['message' => $step]]],
                $this->exampleBodies($this->responseOf($path, $method, '404')),
                "{$method} {$path}"
            );
        }
        foreach ([[self::STEPS, 'post'], [self::STEP, 'put'], [self::MOVE, 'post']] as [$path, $method]) {
            $this->assertSame('#/components/responses/WorkflowStepsLocked', $this->specAt(['paths', $path, $method, 'responses', '409', '$ref']), "{$method} {$path}");
        }
        $this->assertSame(
            [
                ['message' => WorkflowStepChangeGuard::runsActiveMessage([12]), 'workflow_job_ids' => [12]],
                ['message' => $this->routedToExample(), 'step_ids' => [self::BUILD_ID, self::VERIFY_ID]],
            ],
            $this->exampleErrors(self::STEP, 'delete', '409')
        );
        $this->assertSame($this->routedToExample(), $this->specAt(['components', 'schemas', 'WorkflowStepRoutedToError', 'properties', 'error', 'properties', 'message', 'example']));
    }

    /**
     * An add, update or move answer: the step exactly as WorkflowStep says,
     * and the change as WorkflowStepChange says.
     *
     * @param array<mixed> $body
     */
    private function assertWritten(array $body): void
    {
        $this->assertMatchesSchema('WorkflowStepWriteResponse', $body);
        $this->assertMatchesNested('WorkflowStepWriteResponse', 'change', $body['change']);
        $this->assertIsArray($body['data']);
        $this->assertStep($body['data']);
    }

    /**
     * A step exactly as WorkflowStep says, its routes as WorkflowStepRoutes
     * and each route as WorkflowRouteTarget.
     *
     * @param array<mixed> $step
     */
    private function assertStep(array $step): void
    {
        $this->assertMatchesSchema('WorkflowStep', $step);
        $this->assertMatchesNested('WorkflowStep', 'routes', $step['routes']);
        $this->assertIsArray($step['routes']);
        foreach ($step['routes'] as $outcome => $route) {
            $this->assertMatchesNested('WorkflowStepRoutes', (string)$outcome, $route);
        }
    }

    /**
     * The example workflow of the spec as an admin builds it: "Build", a job
     * step, then "Release approval", an approval step, without branches; and
     * a job template for the "Deploy" step the examples add.
     *
     * @return array{id: int, build: int, approval: int, deployTemplate: int}
     */
    private function exampleWorkflow(): array
    {
        $adminId = (int)$this->admin->id;
        $workflowId = (int)$this->createWorkflowTemplate($adminId)->id;
        $build = $this->createWorkflowStep($workflowId, 10, WorkflowStep::TYPE_JOB, (int)$this->jobTemplateOwnedBy($this->admin)->id);
        $approval = $this->createWorkflowStep($workflowId, 20, WorkflowStep::TYPE_APPROVAL, null, (int)$this->createApprovalRule($adminId)->id);
        foreach ([[$build, 'Build'], [$approval, 'Release approval']] as [$step, $name]) {
            $step->name = $name;
            $this->assertTrue($step->save(false));
        }

        return [
            'id' => $workflowId,
            'build' => (int)$build->id,
            'approval' => (int)$approval->id,
            'deployTemplate' => (int)$this->jobTemplateOwnedBy($this->admin)->id,
        ];
    }

    /**
     * The step orders of the steps of workflow $workflowId, in run order.
     *
     * @return list<int>
     */
    private function stepOrders(int $workflowId): array
    {
        return array_map('intval', WorkflowStep::find()
            ->select('step_order')
            ->where(['workflow_template_id' => $workflowId])
            ->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])
            ->column());
    }

    /**
     * A running run of workflow $workflowId, which locks its steps.
     */
    private function createRunningRun(int $workflowId): int
    {
        $run = new WorkflowJob();
        $run->workflow_template_id = $workflowId;
        $run->launched_by = (int)$this->admin->id;
        $run->status = WorkflowJob::STATUS_RUNNING;
        $run->started_at = time();
        $run->created_at = time();
        $run->updated_at = time();
        $this->assertTrue($run->save(false));

        return (int)$run->id;
    }

    /**
     * The answer of $action of the step endpoints with $body, as the
     * controller gives it, and its status.
     *
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<mixed>}
     */
    private function call(string $action, array $body, int ...$ids): array
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams($body);
        \Yii::$app->response->statusCode = 200;
        $answer = (new WorkflowStepsController('api/v1/workflow-steps', $this->app()))->{'action' . ucfirst($action)}(...$ids);
        $this->assertIsArray($answer);

        return ['status' => \Yii::$app->response->statusCode, 'body' => $answer];
    }

    /**
     * The body of the example $name of the add, update and move answer.
     *
     * @return array<mixed>
     */
    private function writtenExample(string $name): array
    {
        $example = $this->specAt(['components', 'responses', 'WorkflowStepWritten', 'content', 'application/json', 'examples', $name, 'value']);
        $this->assertIsArray($example);

        return $example;
    }

    /**
     * $example with the example's IDs (keys of $ids) replaced by the real
     * ones: the step's ID, its job template, and the steps its routes lead
     * to.
     *
     * @param array<mixed> $example
     * @param array<int, int> $ids
     * @return array<mixed>
     */
    private function withIds(array $example, array $ids): array
    {
        $data = $example['data'];
        $this->assertIsArray($data);
        $data['id'] = $ids[$data['id']] ?? $data['id'];
        if (isset($data['job_template_id'])) {
            $data['job_template_id'] = $ids[$data['job_template_id']];
        }
        foreach ((array)($data['routes'] ?? []) as $outcome => $route) {
            if (is_array($route) && $route['step_id'] !== null) {
                $data['routes'][$outcome]['step_id'] = $ids[$route['step_id']];
            }
        }
        $example['data'] = $data;

        return $example;
    }

    /**
     * The properties of the `error` object of the error schema $schema.
     *
     * @return array<mixed>
     */
    private function errorProperties(string $schema): array
    {
        $properties = $this->dig($this->properties($schema), ['error', 'properties'], "{$schema} documents its error.");
        $this->assertIsArray($properties);

        return $properties;
    }

    /**
     * The first validation error of a step with $attributes, as the model
     * words it, such as "Name cannot be blank.".
     *
     * @param array<string, mixed> $attributes
     */
    private function firstError(array $attributes): string
    {
        $step = new WorkflowStep();
        $step->setAttributes(array_merge(['name' => 'Deploy', 'step_type' => WorkflowStep::TYPE_PAUSE], $attributes), false);
        $this->assertFalse($step->validate(array_keys($attributes)));

        return (string)current($step->getFirstErrors());
    }

    /**
     * What a variable mapping with a number too large for JSON is refused
     * with, as the body's reader words it.
     */
    private function mappingTooLarge(): string
    {
        $refusal = WorkflowStepCommand::fromApi(['name' => 'Deploy', 'extra_vars_template' => ['version' => INF]], true);
        $this->assertSame(SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_INF_OR_NAN), $refusal);

        return $refusal;
    }

    /**
     * STEP_ROUTED_TO of the example: "Deploy", which "Build" (position 1)
     * and "Verify" (position 4) route to.
     */
    private function routedToExample(): string
    {
        $deploy = self::exampleStep(self::DEPLOY_ID, 'Deploy', WorkflowStep::TYPE_JOB);
        $routing = [
            self::exampleStep(self::BUILD_ID, 'Build', WorkflowStep::TYPE_JOB),
            self::exampleStep(self::VERIFY_ID, 'Verify', WorkflowStep::TYPE_JOB),
        ];

        return WorkflowStepChangeGuard::routedToMessage($deploy, $routing, [self::BUILD_ID => 1, self::DEPLOY_ID => 3, self::VERIFY_ID => 4]);
    }

    /**
     * The text of the tag $name.
     */
    private function tagText(string $name): string
    {
        $tags = array_column((array)$this->specAt(['tags']), 'description', 'name');

        return trim((string)preg_replace('/\s+/', ' ', (string)($tags[$name] ?? '')));
    }

    /**
     * @return array{components: array{urlManager: array{rules: list<mixed>}}, controllerMap: array<string, string>}
     */
    private function webConfig(): array
    {
        /** @var array{components: array{urlManager: array{rules: list<mixed>}}, controllerMap: array<string, string>} $config */
        $config = require dirname(__DIR__, 5) . '/config/web.php';

        return $config;
    }

    /**
     * A step of the example workflow as the summary reads it, with its
     * relations in memory: a job step's job template (15, without an
     * approval rule) and an approval step's rule (3) exist, so that every
     * step can run, as in the spec's example. A step without them cannot
     * run, and the warnings, which follow the branches, never pass it.
     */
    private static function exampleStep(int $id, string $name, string $type, ?int $onFailure = null, ?int $onSuccess = null): WorkflowStep
    {
        $step = new WorkflowStep();
        $step->id = $id;
        $step->workflow_template_id = self::EXAMPLE_WORKFLOW_ID;
        $step->name = $name;
        $step->step_type = $type;
        $step->on_success_step_id = $onSuccess;
        $step->on_failure_step_id = $onFailure;
        $template = null;
        if ($type === WorkflowStep::TYPE_JOB) {
            $template = new JobTemplate();
            $template->id = 15;
            $template->approval_rule_id = null;
            $template->populateRelation('approvalRule', null);
            $template->populateRelation('inventory', null);
        }
        $rule = null;
        if ($type === WorkflowStep::TYPE_APPROVAL) {
            $rule = new ApprovalRule();
            $rule->id = 3;
        }
        $step->job_template_id = $template?->id;
        $step->approval_rule_id = $rule?->id;
        $step->populateRelation('jobTemplate', $template);
        $step->populateRelation('approvalRule', $rule);

        return $step;
    }

    /**
     * The summary of a change of $step, as the API answers it (change).
     *
     * @param 'added'|'updated'|'moved'|'removed'|'unchanged' $kind
     * @param list<WorkflowStep> $before
     * @param list<WorkflowStep> $after
     * @param array<string, array{from: mixed, to: mixed}> $changes
     * @return array{message: string, warnings: list<string>}
     */
    private static function summary(string $kind, WorkflowStep $step, int $from, int $to, array $before, array $after, array $changes = []): array
    {
        $change = new WorkflowStepChange($kind, $step, $from, $to, $before, $after, $changes, WorkflowStepWriteService::SOURCE_API);

        return WorkflowStepChangeSummary::of($change)->toArray();
    }

    /**
     * The first sentence of a change message: up to the first '.' that ends
     * a sentence.
     */
    private static function firstSentence(string $message): string
    {
        return (string)preg_replace('/(?<=\.) .*$/s', '', $message);
    }
}
