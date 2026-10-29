<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\services\JobTemplateCredentialService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every whole-number field of every JSON request body that web/openapi.yaml
 * describes, of the REST API v1 and the triggers (OpenApiRequestNumbers),
 * and of the runner API, sent each JSON number that is no whole number
 * (SubmitsNumbers: 1e30, 9223372036854775808, 1e999, -1e30, -1e999, 12.5,
 * 2.9999999999999996 and -2.9999999999999996) by a superadmin, the caller
 * every permission check lets through,
 * or by the runner that runs the job. Each is refused with a 4xx and the
 * field's message, or answers as designed, a design named by the case; a
 * refusal writes nothing, audits nothing and logs nothing as an error, and
 * the same body with a whole number at the field is taken (the control
 * that the body reaches the field). The request goes to the action that
 * config/web.php routes its method and path to (WebRoutes), through
 * runAction(), so authentication, the permission check and the verb filter
 * run as for a real request.
 *
 * A field the spec describes without a case here fails, and so does a case
 * of a field the spec does not describe: a new endpoint or field gets its
 * case when the spec describes it. UNDESCRIBED holds the fields the spec
 * describes in prose only (the IDs of an approval rule's approver config),
 * RUNNER_API those of the runner API, which the spec leaves out. The web
 * forms, which take JSON too, are SubmittedNumbersWebFormTest's.
 *
 * Regression: on PHP 8.5 an (int) cast of such a float warns ("The float
 * 1.0E+30 is not representable as an int, cast occurred"), and the error
 * handler turns the warning into an exception. 2.13.1 (c017f1c) answered 500
 * for such numbers at 39 of its 76 fields: of projects, inventories, job
 * templates, job launches, schedules, runners, team members and projects,
 * users and the approver configs of approval rules (the rule saved first),
 * of both triggers and of the runner's reports. It took a fraction for the
 * number it cut it to: 12.5 saved a job template with 12 forks, added user
 * #12 to a team, named user #12 an approver, and stored a log chunk, a task
 * and an exit code. The sites fixed then have tests of their own
 * (*NumbersTest, *IntRangeTest); this test keeps every field, also of a
 * later endpoint, from answering 500 or taking another number again,
 * whatever reads it: a cast of a model attribute, a parameter or a decoded
 * value, which the static guard RequestIntCastsTest cannot see, included.
 *
 * Regression: Yii's NumberValidator read a float as its text of 14
 * significant digits, so the integer rule took 2.9999999999999996 (text
 * "3") and -2.9999999999999996 ("-3") for whole numbers, and the column's
 * typecast and SubmittedInteger::of() cut them to 2 and -2: before
 * WholeNumberValidator, 43 of the 76 fields answered otherwise than for
 * 12.5. They saved a job template with 2 forks, launched a job at
 * verbosity 2, added user #2 to a team, named user #2 an approver, stored a
 * log chunk, a task and an exit code, and looked up job template #2.
 */
class SubmittedNumbersApiTest extends WebControllerTestCase
{
    use SubmitsNumbers;
    use SubmittedNumbersFixtures;

    /**
     * The cases of the spec's fields, by operation: the path's parameters
     * ('path'), a body that the operation takes once the field holds a
     * whole number ('body'), and by field the whole number that body takes
     * ('valid') and what each number answers: one refusal for all
     * ('refused'), or by number ('answers', over 'refused'). {name} stands
     * for the fixture of buildWorld(), in paths, bodies and answers. A field
     * can have a body (or a path) of its own; 'auth' => 'trigger' sends the
     * template's trigger token instead of the API token, 'auth' => 'runner'
     * the token of the runner that runs the job. A trigger logs the launch
     * it refuses as a warning, by design (LaunchInputTest): its answer says
     * so ("; logged").
     *
     * @var array<string, array{path?: array<string, string>, body?: array<string, mixed>, auth?: string, fields: array<string, array<string, mixed>>}>
     */
    private const OPERATIONS = [
        'POST /api/v1/projects' => [
            'body' => ['name' => 'numbers-guard-project', 'scm_type' => 'manual'],
            'fields' => [
                'scm_credential_id' => ['valid' => '{credential}', 'refused' => '422 Scm Credential Id must be an integer.'],
            ],
        ],
        'PUT /api/v1/projects/{id}' => [
            'path' => ['id' => '{project}'],
            'body' => ['name' => 'numbers-guard-renamed'],
            'fields' => [
                'scm_credential_id' => ['valid' => '{credential}', 'refused' => '422 Scm Credential Id must be an integer.'],
            ],
        ],
        'POST /api/v1/inventories' => [
            'body' => ['name' => 'numbers-guard-inventory', 'inventory_type' => 'static', 'content' => "all:\n  hosts:\n    web1.example.com:\n"],
            'fields' => [
                'project_id' => ['valid' => '{project}', 'refused' => '422 Project Id must be an integer.'],
                'approval_rule_id' => ['valid' => '{rule}', 'refused' => '422 Approval rule must be an integer.'],
            ],
        ],
        'PUT /api/v1/inventories/{id}' => [
            'path' => ['id' => '{inventory}'],
            'body' => ['name' => 'numbers-guard-renamed'],
            'fields' => [
                'project_id' => ['valid' => '{project}', 'refused' => '422 Project Id must be an integer.'],
                'approval_rule_id' => ['valid' => '{rule}', 'refused' => '422 Approval rule must be an integer.'],
            ],
        ],
        'POST /api/v1/credentials/{id}/job-templates' => [
            'path' => ['id' => '{vault}'],
            'body' => [],
            'fields' => [
                'job_template_ids' => ['valid' => '[{template}]', 'refused' => '422 job_template_ids must be a non-empty list of job template IDs.'],
                'job_template_ids[]' => ['valid' => '{template}', 'refused' => '422 job_template_ids must be a non-empty list of job template IDs.'],
            ],
        ],
        'POST /api/v1/job-templates' => [
            'body' => [
                'name' => 'numbers-guard-template',
                'project_id' => '{project}',
                'inventory_id' => '{inventory}',
                'playbook' => 'site.yml',
                'runner_group_id' => '{group}',
            ],
            'fields' => self::JOB_TEMPLATE_FIELDS,
        ],
        'PUT /api/v1/job-templates/{id}' => [
            'path' => ['id' => '{template}'],
            'body' => ['name' => 'numbers-guard-renamed'],
            'fields' => self::JOB_TEMPLATE_FIELDS,
        ],
        'POST /api/v1/jobs' => [
            'body' => ['job_template_id' => '{template}'],
            'fields' => [
                'job_template_id' => ['valid' => '{template}', 'refused' => '422 job_template_id is required.'],
                'template_id' => ['body' => [], 'valid' => '{template}', 'refused' => '422 job_template_id is required.'],
                'verbosity' => ['valid' => '2', 'refused' => '422 Verbosity must be an integer. {"fields":{"verbosity":"Verbosity must be an integer."}}'],
            ],
        ],
        'POST /api/v1/schedules' => [
            'body' => ['name' => 'numbers-guard-schedule', 'cron_expression' => '0 3 * * *', 'timezone' => 'UTC'],
            'fields' => [
                'job_template_id' => ['valid' => '{template}', 'refused' => '422 Job Template Id must be an integer.'],
            ],
        ],
        'PUT /api/v1/schedules/{id}' => [
            'path' => ['id' => '{schedule}'],
            'body' => ['name' => 'numbers-guard-renamed'],
            'fields' => [
                'job_template_id' => ['valid' => '{template}', 'refused' => '422 Job Template Id must be an integer.'],
            ],
        ],
        'POST /api/v1/runners/{id}/move' => [
            'path' => ['id' => '{runner}'],
            'body' => [],
            'fields' => [
                'target_group_id' => ['valid' => '{otherGroup}', 'refused' => '404 Target runner group not found.'],
            ],
        ],
        'POST /api/v1/teams/{id}/members' => [
            'path' => ['id' => '{team}'],
            'body' => [],
            'fields' => [
                'user_id' => ['valid' => '{user}', 'refused' => '404 User #0 not found.'],
            ],
        ],
        'POST /api/v1/teams/{id}/projects' => [
            'path' => ['id' => '{team}'],
            'body' => ['role' => 'viewer'],
            'fields' => [
                'project_id' => ['valid' => '{project}', 'refused' => '404 Project #0 not found.'],
            ],
        ],
        'POST /api/v1/users' => [
            'body' => ['username' => 'numbers-guard-user', 'email' => 'numbers-guard-user@example.com', 'password' => 'numbers-guard-fixture', 'role' => 'viewer'],
            'fields' => [
                'status' => ['valid' => '10', 'refused' => '422 Status is invalid.'],
            ],
        ],
        'PUT /api/v1/users/{id}' => [
            'path' => ['id' => '{user}'],
            'body' => [],
            'fields' => [
                'status' => ['valid' => '0', 'refused' => '422 Status is invalid.'],
            ],
        ],
        'POST /api/v1/approval-rules' => [
            'body' => ['name' => 'numbers-guard-rule', 'approver_type' => 'role', 'approver_config' => ['role' => 'operator']],
            'fields' => self::APPROVAL_RULE_FIELDS,
        ],
        'PUT /api/v1/approval-rules/{id}' => [
            'path' => ['id' => '{rule}'],
            'body' => ['name' => 'numbers-guard-renamed'],
            'fields' => self::APPROVAL_RULE_FIELDS,
        ],
        'POST /api/v1/workflow-templates' => [
            'body' => ['name' => 'numbers-guard-workflow', 'steps' => [['name' => 'numbers-guard-step', 'step_type' => 'job']]],
            'fields' => self::WORKFLOW_STEP_LIST_FIELDS,
        ],
        'PUT /api/v1/workflow-templates/{id}' => [
            'path' => ['id' => '{workflow}'],
            'body' => ['steps' => [['name' => 'numbers-guard-step', 'step_type' => 'job']]],
            'fields' => self::WORKFLOW_STEP_LIST_FIELDS,
        ],
        'POST /api/v1/workflow-templates/{id}/steps' => [
            'path' => ['id' => '{workflow}'],
            'body' => ['name' => 'numbers-guard-step', 'step_type' => 'job', 'job_template_id' => '{template}'],
            'fields' => self::WORKFLOW_STEP_FIELDS,
        ],
        'PUT /api/v1/workflow-templates/{id}/steps/{stepId}' => [
            'path' => ['id' => '{workflow}', 'stepId' => '{step2}'],
            'body' => ['name' => 'numbers-guard-renamed'],
            'fields' => self::WORKFLOW_STEP_FIELDS,
        ],
        'POST /api/v1/workflow-templates/{id}/steps/{stepId}/move' => [
            'path' => ['id' => '{workflow}', 'stepId' => '{step2}'],
            'body' => [],
            'fields' => [
                'position' => ['valid' => '1', 'refused' => '422 position must be "start", "end" or a whole number from 1.'],
                'after_step_id' => ['valid' => '{step1}', 'refused' => '422 after_step_id must be the ID of a step of this workflow.'],
            ],
        ],
        'POST /trigger/fire' => [
            'auth' => 'trigger',
            'body' => [],
            'fields' => [
                'verbosity' => ['valid' => '2', 'refused' => '422 Verbosity must be an integer.; logged Trigger for template #{template} rejected: Verbosity must be an integer.'],
            ],
        ],
        'POST /trigger/{token}' => [
            'path' => ['token' => '{token}'],
            'auth' => 'trigger',
            'body' => [],
            'fields' => [
                'verbosity' => ['valid' => '2', 'refused' => '422 Verbosity must be an integer.; logged Trigger for template #{template} rejected: Verbosity must be an integer.'],
            ],
        ],
    ];

    /** The fields of a job template, created or changed. */
    private const JOB_TEMPLATE_FIELDS = [
        'project_id' => ['valid' => '{project}', 'refused' => '422 Project Id must be an integer.'],
        'inventory_id' => ['valid' => '{inventory}', 'refused' => '422 Inventory Id must be an integer.'],
        'credential_id' => ['valid' => '{credential}', 'refused' => '422 Credential Id must be an integer.'],
        'credential_ids' => ['valid' => '[{credential}]', 'refused' => '422 credential_ids must be an array of credential IDs.'],
        'credential_ids[]' => ['valid' => '{credential}', 'refused' => '422 ' . JobTemplateCredentialService::INVALID_CREDENTIAL_IDS],
        'verbosity' => ['valid' => '2', 'refused' => '422 Verbosity must be an integer.'],
        'forks' => ['valid' => '5', 'refused' => '422 Forks must be an integer.'],
        'timeout_minutes' => ['valid' => '60', 'refused' => '422 Timeout Minutes must be an integer.'],
        'runner_group_id' => ['valid' => '{group}', 'refused' => '422 Runner must be an integer.'],
        'approval_rule_id' => ['valid' => '{rule}', 'refused' => '422 Approval rule must be an integer.'],
    ];

    /** The fields of an approval rule, created or changed. */
    private const APPROVAL_RULE_FIELDS = [
        'required_approvals' => ['valid' => '1', 'refused' => '422 Required Approvals must be an integer.'],
        'timeout_minutes' => ['valid' => '60', 'refused' => '422 Timeout Minutes must be an integer.'],
        'job_template_id' => ['valid' => 'null', 'refused' => '422 job_template_id is not used by approval rules: set approval_rule_id on the job template (admins only).'],
    ];

    /** The fields of the steps list of a workflow template. */
    private const WORKFLOW_STEP_LIST_FIELDS = [
        'steps[].job_template_id' => ['valid' => '{template}', 'refused' => '422 steps[0]: A job step needs job_template_id, the ID of a job template.'],
        'steps[].approval_rule_id' => [
            'body' => ['name' => 'numbers-guard-workflow', 'steps' => [['name' => 'numbers-guard-approval', 'step_type' => 'approval']]],
            'valid' => '{rule}',
            'refused' => '422 steps[0]: An approval step needs approval_rule_id, the ID of an approval rule.',
        ],
    ];

    /** The fields of one workflow step, added or changed. */
    private const WORKFLOW_STEP_FIELDS = [
        'job_template_id' => ['valid' => '{template}', 'refused' => '422 A job step needs a job template.'],
        'approval_rule_id' => [
            'body' => ['name' => 'numbers-guard-approval', 'step_type' => 'approval'],
            'path' => ['id' => '{workflow}', 'stepId' => '{approvalStep}'],
            'valid' => '{rule}',
            'refused' => '422 An approval step needs an approval rule.',
        ],
        'on_success_step_id' => ['valid' => '{step1}', 'refused' => '422 on_success_step_id must be null, 0 (end the workflow) or the ID of a step of this workflow.'],
        'on_failure_step_id' => ['valid' => '0', 'refused' => '422 on_failure_step_id must be null, 0 (end the workflow) or the ID of a step of this workflow.'],
        'on_always_step_id' => ['valid' => '{step1}', 'refused' => '422 on_always_step_id must be null, 0 (end the workflow) or the ID of a step of this workflow.'],
        'position' => ['valid' => '1', 'refused' => '422 position must be "start", "end" or a whole number from 1.'],
        'after_step_id' => ['valid' => '{step1}', 'refused' => '422 after_step_id must be the ID of a step of this workflow.'],
    ];

    /**
     * Fields the spec describes in prose only, by operation, as OPERATIONS:
     * the team and the users of an approval rule's approver config, an
     * object the spec gives no properties. A number that is no ID names
     * nobody, by design (ApprovalRuleConfigNumbersTest): the rule is saved
     * and names the other approvers only ('designed' => 'approvers' adds
     * whom the rule names to the answer). Infinity cannot be encoded as
     * JSON: the API takes the config of ±1e999 for none.
     *
     * @var array<string, array{path?: array<string, string>, body?: array<string, mixed>, fields: array<string, array<string, mixed>>}>
     */
    private const UNDESCRIBED = [
        'POST /api/v1/approval-rules' => [
            'body' => ['name' => 'numbers-guard-rule'],
            'fields' => self::APPROVER_CONFIG_FIELDS,
        ],
        'PUT /api/v1/approval-rules/{id}' => [
            'path' => ['id' => '{rule}'],
            'body' => [],
            'fields' => self::APPROVER_CONFIG_FIELDS,
        ],
    ];

    /** The IDs of an approver config. */
    private const APPROVER_CONFIG_FIELDS = [
        'approver_config.team_id' => [
            'body' => ['name' => 'numbers-guard-rule', 'approver_type' => 'team', 'approver_config' => []],
            'designed' => 'approvers',
            'valid' => '{team}',
            'refused' => '%d names no approver; wrote approval_rule, audit_log',
            'answers' => [
                '1e999' => '422 Approver Config cannot be blank.',
                '-1e999' => '422 Approver Config cannot be blank.',
            ],
        ],
        'approver_config.user_ids[]' => [
            'body' => ['name' => 'numbers-guard-rule', 'approver_type' => 'users', 'approver_config' => ['user_ids' => ['{user}']]],
            'designed' => 'approvers',
            'valid' => '{admin}',
            'refused' => '%d names approvers #{user}; wrote approval_rule, audit_log',
            'answers' => [
                '1e999' => '422 Approver Config cannot be blank.',
                '-1e999' => '422 Approver Config cannot be blank.',
            ],
        ],
    ];

    /**
     * The whole-number fields of the runner API, which the spec leaves out,
     * as OPERATIONS: the reports of the runner that runs the job
     * (RunnerReportNumbers). Its other requests, the heartbeat, the claim
     * and the registration, read text and lists only.
     *
     * @var array<string, array{path?: array<string, string>, body?: array<string, mixed>, auth?: string, fields: array<string, array<string, mixed>>}>
     */
    private const RUNNER_API = [
        'POST /api/runner/v1/jobs/{id}/logs' => [
            'path' => ['id' => '{job}'],
            'auth' => 'runner',
            'body' => ['stream' => 'stdout', 'content' => "numbers-guard line\n"],
            'fields' => [
                'sequence' => ['valid' => '0', 'refused' => '422 sequence must be a whole number from 0 to 4294967295.'],
            ],
        ],
        'POST /api/runner/v1/jobs/{id}/complete' => [
            'path' => ['id' => '{job}'],
            'auth' => 'runner',
            'body' => [],
            'fields' => [
                'exit_code' => ['valid' => '0', 'refused' => '422 exit_code must be a whole number from -256 to 255.'],
            ],
        ],
        'POST /api/runner/v1/jobs/{id}/tasks' => [
            'path' => ['id' => '{job}'],
            'auth' => 'runner',
            'body' => ['tasks' => [['seq' => 1, 'name' => 'ping', 'action' => 'ping', 'host' => 'web1.example.com', 'status' => 'ok', 'changed' => false, 'duration_ms' => 5]]],
            'fields' => [
                'tasks[].seq' => ['valid' => '1', 'refused' => '422 tasks[0].seq must be a whole number from -2147483648 to 2147483647.'],
                'tasks[].duration_ms' => ['valid' => '5', 'refused' => '422 tasks[0].duration_ms must be a whole number from -2147483648 to 2147483647.'],
            ],
        ],
    ];

    private DatabaseWrites $writes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->writes = new DatabaseWrites(\Yii::$app->db, (int)$this->world['admin']);
    }

    /**
     * One data set per field the spec describes, and per field of
     * UNDESCRIBED and RUNNER_API.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fieldProvider(): array
    {
        $fields = [];
        foreach (OpenApiRequestNumbers::ofTheSpec() as $operation => $names) {
            foreach ($names as $field) {
                $fields["{$operation} {$field}"] = [$operation, $field];
            }
        }
        foreach ([self::UNDESCRIBED, self::RUNNER_API] as $cases) {
            foreach ($cases as $operation => $case) {
                foreach (array_keys($case['fields']) as $field) {
                    $fields["{$operation} {$field}"] = [$operation, $field];
                }
            }
        }

        return $fields;
    }

    /**
     * The cases and the spec name the same fields: a field the spec
     * describes needs a case, and a case needs a field the spec describes.
     */
    public function testEveryFieldOfTheSpecHasACaseAndEveryCaseAField(): void
    {
        $cases = [];
        foreach (self::OPERATIONS as $operation => $case) {
            $cases[$operation] = array_keys($case['fields']);
        }

        $this->assertSame($cases, OpenApiRequestNumbers::ofTheSpec(), 'OPERATIONS, in the order of the spec');
    }

    /**
     * UNDESCRIBED and RUNNER_API hold fields the spec does not describe:
     * one it starts to describe moves to OPERATIONS.
     */
    public function testTheUndescribedFieldsAreNotInTheSpec(): void
    {
        foreach (self::UNDESCRIBED + self::RUNNER_API as $operation => $case) {
            $described = OpenApiRequestNumbers::ofTheSpec()[$operation] ?? [];
            $this->assertSame([], array_values(array_intersect(array_keys($case['fields']), $described)), $operation);
        }
    }

    #[DataProvider('fieldProvider')]
    public function testEachNumberIsRefusedOrAnsweredAsDesigned(string $operation, string $field): void
    {
        $found = self::caseOf($operation, $field);
        $this->assertNotNull($found, "no case for the field {$field} of {$operation}: add one to OPERATIONS");
        [$operationCase, $case] = $found;
        [$method, $specPath] = explode(' ', $operation, 2);
        $pathParams = is_array($case['path'] ?? null) ? $case['path'] : (is_array($operationCase['path'] ?? null) ? $operationCase['path'] : []);
        $path = (string)preg_replace_callback(
            '/\{(\w+)\}/',
            fn (array $m): string => $this->resolve((string)($pathParams[$m[1]] ?? $m[0])),
            $specPath
        );
        $route = WebRoutes::resolve($method, $path);
        $this->assertNotNull($route, "config/web.php routes {$method} {$path}");
        $body = is_array($case['body'] ?? null) ? $case['body'] : (is_array($operationCase['body'] ?? null) ? $operationCase['body'] : []);
        $call = function () use ($route): mixed {
            $controller = new $route['class']($route['controller'], \Yii::$app);

            return $controller->runAction($route['action'], $this->webRequest()->getQueryParams());
        };
        $send = function (string $value) use ($method, $route, $body, $field, $operationCase): void {
            $this->prepareRequest($route['params'], (string)($operationCase['auth'] ?? 'api'));
            $this->sendJsonBody($this->resolve(self::bodyWith($body, $field, $value)), $method);
        };

        $control = $this->answerToAWholeNumber(fn () => $send($this->resolve((string)$case['valid'])), $call, $this->writes);
        $answers = $this->answersToNumbers($send, $this->designed($call, $case), $this->writes);

        $this->assertMatchesRegularExpression('/^2\d\d .*$/', $control, 'the body with a whole number at the field is taken');
        $this->assertStringNotContainsString('; logged', $control, 'the body with a whole number at the field logs no failure');
        $this->assertSame($this->expected($case, $method), $answers, "{$operation}: {$field}");
    }

    protected function answerOf(mixed $result): string
    {
        $status = \Yii::$app->response->statusCode;
        if ($result instanceof \yii\web\Response) {
            $result = $result->data;
        }
        if (is_array($result) && is_string($result['designed'] ?? null)) {
            return $status . ' ' . $result['designed'];
        }
        if (is_array($result) && isset($result['error'])) {
            $error = $result['error'];
            if (!is_array($error)) {
                return $status . ' ' . (is_string($error) ? $error : (string)json_encode($error));
            }
            $message = $error['message'] ?? '';
            unset($error['message']);

            return trim($status . ' ' . (is_string($message) ? $message : (string)json_encode($message)) . ($error === [] ? '' : ' ' . json_encode($error)));
        }

        return $status . ' ok';
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * The case of the operation and the case of its field $field, from
     * OPERATIONS, UNDESCRIBED or RUNNER_API; null when there is none.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private static function caseOf(string $operation, string $field): ?array
    {
        /** @var list<array<string, array<string, mixed>>> $tables */
        $tables = [self::OPERATIONS, self::UNDESCRIBED, self::RUNNER_API];
        foreach ($tables as $cases) {
            $operationCase = $cases[$operation] ?? [];
            $fields = is_array($operationCase['fields'] ?? null) ? $operationCase['fields'] : [];
            if (is_array($fields[$field] ?? null)) {
                /** @var array<string, mixed> $fieldCase */
                $fieldCase = $fields[$field];

                return [$operationCase, $fieldCase];
            }
        }

        return null;
    }

    /**
     * What each number answers, by number: 'answers' over 'refused', with
     * the fixtures of the answers resolved; %d is the status of a designed
     * answer that saved: 201 for a create (POST), 200 for an update.
     *
     * @param array<string, mixed> $case
     * @return array<string, string>
     */
    private function expected(array $case, string $method): array
    {
        $answers = is_array($case['answers'] ?? null) ? $case['answers'] : [];
        $expected = [];
        foreach (self::submittedNumbers() as $number) {
            $answer = (string)($answers[$number] ?? $case['refused'] ?? '');
            $expected[$number] = $this->resolve(str_replace('%d', $method === 'POST' ? '201' : '200', $answer));
        }

        return $expected;
    }

    /**
     * $call, followed by the check of a designed answer: whom the saved
     * approval rule names ('approvers').
     *
     * @param callable(): mixed $call
     * @param array<string, mixed> $case
     * @return callable(): mixed
     */
    private function designed(callable $call, array $case): callable
    {
        if (($case['designed'] ?? null) !== 'approvers') {
            return $call;
        }

        return function () use ($call): mixed {
            $result = $call();
            $data = is_array($result) ? ($result['data'] ?? null) : null;
            if (!is_array($data) || !isset($data['id'])) {
                return $result;
            }

            return ['designed' => $this->approversOf((int)$data['id'])];
        };
    }

    /**
     * The request of the next call: the path's parameters as its query
     * parameters (as Request::resolve() gives them), the credentials of
     * $auth ('api': the API token, 'runner': the runner's token, 'trigger':
     * the trigger token in the X-Trigger-Token header, unless the path has
     * it), and nobody signed in.
     *
     * @param array<string, string> $params
     */
    private function prepareRequest(array $params, string $auth): void
    {
        $request = $this->webRequest();
        $request->setQueryParams($params);
        $headers = $request->getHeaders();
        $headers->remove('Authorization');
        $headers->remove('X-Trigger-Token');
        if ($auth === 'api' || $auth === 'runner') {
            $headers->set('Authorization', 'Bearer ' . $this->world[$auth === 'api' ? 'apiToken' : 'runnerToken']);
        } elseif (!isset($params['token'])) {
            $headers->set('X-Trigger-Token', (string)$this->world['token']);
        }
        \Yii::$app->user->setIdentity(null);
    }
}
