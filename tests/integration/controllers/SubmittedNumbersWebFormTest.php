<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\ApprovalRule;
use app\services\JobTemplateCredentialService;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\web\Response;

/**
 * Every whole-number and ID field of the web forms, posted as JSON by a
 * signed-in superadmin with the admin role: the request's JSON parser
 * (config/web.php) serves the web forms too, so a form posted as JSON, with
 * the session's CSRF token in its header, brings the numbers of its body as
 * floats, as the API does. Each field is sent each JSON number that is no
 * whole number (SubmitsNumbers: 1e30, 9223372036854775808, 1e999, -1e30,
 * -1e999, 12.5, 2.9999999999999996 and -2.9999999999999996) and is
 * refused (the form shown again with the field's
 * error, a danger flash, or a 4xx), or answers as designed, a design named
 * by the case; a refusal writes nothing, audits nothing and logs nothing
 * as an error, and the same post with a whole number at the field is taken
 * (the control that the post reaches the field). The actions run through
 * runAction(), so the access filter and the verb filter run as for a real
 * request, and render their real views, without the layout
 * (RendersRealViews).
 *
 * The spec describes no web form, so FORMS lists them, with every field a
 * form or action reads as a whole number or an ID. Left out: the status of
 * the user form (UserForm::$status is typed int: a JSON number fails its
 * assignment with a TypeError, a known item deferred with the string
 * fields that take a number the same way) and created_by, a safe attribute
 * of the notification template, credential and team forms (deferred).
 *
 * Regression: on PHP 8.5 an (int) cast of such a float warns ("The float
 * 1.0E+30 is not representable as an int, cast occurred"), and the error
 * handler turns the warning into an exception. 2.13.1 (c017f1c) answered 500
 * for such numbers at 26 of its 57 fields: the inventory and job template
 * forms (the project, inventory, credential and rule IDs and the additional
 * credentials), the launch of a job template (its ID and its verbosity) and
 * of a workflow, the move and the removal of a workflow step, the approver
 * config of the rule form (the rule saved first), the members and projects
 * of a team, and the group of a new and of a moved runner. It took a
 * fraction for the number it cut it to: 12.5 added user #12 to the team,
 * named user #12 an approver, and looked up job template, workflow and step
 * #12. The sites fixed then have tests of their own (*NumbersTest,
 * *IntRangeTest); this test keeps every field from answering 500 or taking
 * another number again, whatever reads it: a cast of a model attribute, a
 * parameter or a decoded value, which the static guard RequestIntCastsTest
 * cannot see, included.
 *
 * Regression: Yii's NumberValidator read a float as its text of 14
 * significant digits, so the integer rule took 2.9999999999999996 (text
 * "3") and -2.9999999999999996 ("-3") for whole numbers, and the column's
 * typecast and SubmittedInteger::of() cut them to 2 and -2: before
 * WholeNumberValidator, 42 of the 57 fields answered otherwise than for
 * 12.5. They saved a job template with 2 forks and an approval rule that
 * needs 2 approvals, launched a job at verbosity 2, added user #2 to a
 * team, named user #2 an approver, and looked up job template, workflow
 * and step #2; as the project of a team either number, and as its member
 * -2.9999999999999996, answered 500.
 */
class SubmittedNumbersWebFormTest extends WebControllerTestCase
{
    use RendersRealViews;
    use SubmitsNumbers;
    use SubmittedNumbersFixtures;

    /**
     * The forms, by route: the action's parameters ('params'), a post that
     * the action takes once the field holds a whole number ('body'), and by
     * field the whole number that post takes ('valid') and what each number
     * answers: one refusal for all ('refused'), or by number ('answers',
     * over 'refused'). A field is a path into the post, as SubmitsNumbers
     * places it ('at', when the key is a label); 'text' puts the number into
     * a text (an approver config, which the rule form takes as JSON text).
     * {name} stands for the fixture of buildWorld(), in params, posts and
     * answers. An answer of a redirect reads "302 <location>", then the
     * flashes ("; flash <type>: <message>"); an answer of a page "200 form:
     * <the errors it shows>".
     *
     * @var array<string, array{params?: array<string, string>, body?: array<string, mixed>, fields: array<string, array<string, mixed>>}>
     */
    private const FORMS = [
        'project/create' => [
            'body' => ['Project' => ['name' => 'numbers-guard-project', 'scm_type' => 'manual']],
            'fields' => [
                'Project.scm_credential_id' => ['valid' => '{credential}', 'refused' => '200 form: Not saved: the hidden Git fields have errors. Choose Git under Scm Type to see and correct them, then switch back and save. Scm Credential Id must be an integer. | Scm Credential Id must be an integer.'],
            ],
        ],
        'project/update' => [
            'params' => ['id' => '{project}'],
            'body' => ['Project' => ['name' => 'numbers-guard-renamed']],
            'fields' => [
                'Project.scm_credential_id' => ['valid' => '{credential}', 'refused' => '200 form: Not saved: the hidden Git fields have errors. Choose Git under Scm Type to see and correct them, then switch back and save. Scm Credential Id must be an integer. | Scm Credential Id must be an integer.'],
            ],
        ],
        'inventory/create' => [
            'body' => ['Inventory' => ['name' => 'numbers-guard-inventory', 'inventory_type' => 'static', 'content' => "all:\n  hosts:\n    web1.example.com:\n"]],
            'fields' => self::INVENTORY_FIELDS,
        ],
        'inventory/update' => [
            'params' => ['id' => '{inventory}'],
            'body' => ['Inventory' => ['name' => 'numbers-guard-renamed']],
            'fields' => self::INVENTORY_FIELDS,
        ],
        'job-template/create' => [
            'body' => ['JobTemplate' => [
                'name' => 'numbers-guard-template',
                'project_id' => '{project}',
                'inventory_id' => '{inventory}',
                'playbook' => 'site.yml',
                'runner_group_id' => '{group}',
            ]],
            'fields' => self::JOB_TEMPLATE_FIELDS,
        ],
        'job-template/update' => [
            'params' => ['id' => '{template}'],
            'body' => ['JobTemplate' => ['name' => 'numbers-guard-renamed']],
            'fields' => self::JOB_TEMPLATE_FIELDS,
        ],
        'job-template/launch' => [
            'body' => ['id' => '{template}'],
            'fields' => [
                'id' => ['valid' => '{template}', 'refused' => '404 Job template #0 not found.'],
                'overrides.verbosity' => ['valid' => '2', 'refused' => '200 form; flash danger: Launch failed: Verbosity must be an integer.'],
            ],
        ],
        'schedule/create' => [
            'body' => ['Schedule' => ['name' => 'numbers-guard-schedule', 'cron_expression' => '0 3 * * *', 'timezone' => 'UTC']],
            'fields' => [
                'Schedule.job_template_id' => ['valid' => '{template}', 'refused' => '200 form: Job Template Id must be an integer.'],
            ],
        ],
        'schedule/update' => [
            'params' => ['id' => '{schedule}'],
            'body' => ['Schedule' => ['name' => 'numbers-guard-renamed']],
            'fields' => [
                'Schedule.job_template_id' => ['valid' => '{template}', 'refused' => '200 form: Job Template Id must be an integer.'],
            ],
        ],
        'workflow-template/add-step' => [
            'params' => ['id' => '{workflow}'],
            'body' => ['position' => 'end', 'WorkflowStep' => ['name' => 'numbers-guard-step', 'step_type' => 'job', 'job_template_id' => '{template}']],
            'fields' => [
                'WorkflowStep.job_template_id' => ['valid' => '{template}', 'refused' => '302 /workflow-template/view?id={workflow}#add-step; flash danger: Step not added: Job Template Id must be an integer.'],
                'WorkflowStep.approval_rule_id' => [
                    'body' => ['position' => 'end', 'WorkflowStep' => ['name' => 'numbers-guard-approval', 'step_type' => 'approval']],
                    'valid' => '{rule}',
                    'refused' => '302 /workflow-template/view?id={workflow}#add-step; flash danger: Step not added: Approval Rule Id must be an integer.',
                ],
                'WorkflowStep.on_success_step_id' => ['valid' => '{step1}', 'refused' => '302 /workflow-template/view?id={workflow}#add-step; flash danger: Step not added: on_success_step_id must be null, 0 (end the workflow) or the ID of a step of this workflow.'],
                'WorkflowStep.on_failure_step_id' => ['valid' => '0', 'refused' => '302 /workflow-template/view?id={workflow}#add-step; flash danger: Step not added: on_failure_step_id must be null, 0 (end the workflow) or the ID of a step of this workflow.'],
                'WorkflowStep.on_always_step_id' => ['valid' => '{step1}', 'refused' => '302 /workflow-template/view?id={workflow}#add-step; flash danger: Step not added: on_always_step_id must be null, 0 (end the workflow) or the ID of a step of this workflow.'],
                'position' => ['valid' => '"after:{step1}"', 'refused' => '302 /workflow-template/view?id={workflow}#add-step; flash danger: Step not added: Choose a position: at the start, at the end, or after a step of this workflow.'],
            ],
        ],
        'workflow-template/edit-step' => [
            'params' => ['id' => '{workflow}', 'step' => '{step2}'],
            'body' => ['WorkflowStep' => ['name' => 'numbers-guard-renamed']],
            'fields' => [
                'WorkflowStep.job_template_id' => ['valid' => '{template}', 'refused' => '200 form: Step not saved: Job Template Id must be an integer.'],
                'WorkflowStep.approval_rule_id' => [
                    'params' => ['id' => '{workflow}', 'step' => '{approvalStep}'],
                    'valid' => '{rule}',
                    'refused' => '200 form: Step not saved: Approval Rule Id must be an integer.',
                ],
                'WorkflowStep.on_success_step_id' => ['valid' => '{step1}', 'refused' => '200 form: Step not saved: on_success_step_id must be null, 0 (end the workflow) or the ID of a step of this workflow.'],
                'WorkflowStep.on_failure_step_id' => ['valid' => '0', 'refused' => '200 form: Step not saved: on_failure_step_id must be null, 0 (end the workflow) or the ID of a step of this workflow.'],
                'WorkflowStep.on_always_step_id' => ['valid' => '{step1}', 'refused' => '200 form: Step not saved: on_always_step_id must be null, 0 (end the workflow) or the ID of a step of this workflow.'],
                'position' => ['valid' => '"after:{step1}"', 'refused' => '200 form: Step not saved: Choose a position: at the start, at the end, or after a step of this workflow.'],
            ],
        ],
        'workflow-template/move-step' => [
            'params' => ['id' => '{workflow}'],
            'body' => ['step_id' => '{step2}', 'position' => 'start'],
            'fields' => [
                'step_id' => ['valid' => '{step2}', 'refused' => '404 Workflow template #{workflow} has no step with ID 0.'],
                'position' => ['valid' => '"after:{step1}"', 'refused' => '302 /workflow-template/view?id={workflow}#step-{step2}; flash danger: Step not moved: Choose a position: at the start, at the end, or after a step of this workflow.'],
            ],
        ],
        'workflow-template/remove-step' => [
            'params' => ['id' => '{workflow}'],
            'body' => [],
            'fields' => [
                // By design an unknown step, and a step_id that is no ID, is ignored: nothing is removed.
                'step_id' => ['valid' => '{step2}', 'refused' => '302 /workflow-template/view?id={workflow}#workflow-steps'],
            ],
        ],
        'workflow-template/launch' => [
            'body' => [],
            'fields' => [
                'id' => ['valid' => '{workflow}', 'refused' => '404 Workflow template #0 not found.'],
            ],
        ],
        'approval-rule/create' => [
            'body' => ['ApprovalRule' => self::RULE_FORM],
            'fields' => self::APPROVAL_RULE_FIELDS,
        ],
        'approval-rule/update' => [
            'params' => ['id' => '{rule}'],
            'body' => ['ApprovalRule' => self::RULE_FORM],
            'fields' => self::APPROVAL_RULE_FIELDS,
        ],
        'team/add-member' => [
            'params' => ['id' => '{team}'],
            'body' => [],
            'fields' => [
                'user_id' => ['valid' => '{user}', 'refused' => '400 user_id required.'],
            ],
        ],
        'team/add-project' => [
            'params' => ['id' => '{team}'],
            'body' => ['role' => 'viewer'],
            'fields' => [
                'project_id' => ['valid' => '{project}', 'refused' => '400 project_id required.'],
            ],
        ],
        'runner/create' => [
            'body' => ['name' => 'numbers-guard-runner'],
            'fields' => [
                'group_id' => ['valid' => '{group}', 'refused' => '302 /runner-group/index; flash danger: Runner group not found.'],
            ],
        ],
        'runner/move' => [
            'params' => ['id' => '{runner}'],
            'body' => [],
            'fields' => [
                'target_group_id' => ['valid' => '{otherGroup}', 'refused' => '302 /runner-group/view?id={group}; flash danger: Target runner group not found.'],
            ],
        ],
        'credential-assignment/assign' => [
            'params' => ['id' => '{vault}'],
            'body' => [],
            'fields' => [
                'job_template_ids[]' => ['valid' => '{template}', 'refused' => '302 /credential-assignment/index?id={vault}; flash danger: job_template_ids must be a non-empty list of job template IDs.'],
            ],
        ],
    ];

    /** The fields of the inventory form. */
    private const INVENTORY_FIELDS = [
        'Inventory.project_id' => ['valid' => '{project}', 'refused' => '200 form: Project Id must be an integer.'],
        'Inventory.approval_rule_id' => ['valid' => '{rule}', 'refused' => '200 form: Approval rule must be an integer.'],
    ];

    /** The fields of the job template form, and its additional credentials. */
    private const JOB_TEMPLATE_FIELDS = [
        'JobTemplate.project_id' => ['valid' => '{project}', 'refused' => '200 form: Project Id must be an integer.'],
        'JobTemplate.inventory_id' => ['valid' => '{inventory}', 'refused' => '200 form: Inventory Id must be an integer.'],
        'JobTemplate.credential_id' => ['valid' => '{credential}', 'refused' => '200 form: Credential Id must be an integer.'],
        'JobTemplate.runner_group_id' => ['valid' => '{group}', 'refused' => '200 form: Runner must be an integer.'],
        'JobTemplate.verbosity' => ['valid' => '2', 'refused' => '200 form: Verbosity must be an integer.'],
        'JobTemplate.forks' => ['valid' => '5', 'refused' => '200 form: Forks must be an integer.'],
        'JobTemplate.timeout_minutes' => ['valid' => '60', 'refused' => '200 form: Timeout Minutes must be an integer.'],
        'JobTemplate.approval_rule_id' => ['valid' => '{rule}', 'refused' => '200 form: Approval rule must be an integer.'],
        'credential_ids[]' => ['valid' => '{credential}', 'refused' => '200 form: ' . JobTemplateCredentialService::INVALID_CREDENTIAL_IDS],
    ];

    /** A rule form that is taken. */
    private const RULE_FORM = [
        'name' => 'numbers-guard-rule',
        'approver_type' => 'role',
        'approver_config' => '{"role": "operator"}',
        'required_approvals' => 1,
        'timeout_action' => 'reject',
    ];

    /**
     * The fields of the rule form. The IDs of the approver config, which the
     * form takes as JSON text: a number that is no ID names nobody, by
     * design (ApprovalRuleConfigNumbersTest), and the rule is saved naming
     * the other approvers only ('designed' => 'approvers' adds whom the
     * saved rule names to the answer). The text keeps ±1e999 as sent, which
     * JSON cannot encode again: a known item, deferred
     * (ApprovalRule::validateJson(); the API's GET of such a rule answers
     * 500), that this test does not reach.
     */
    private const APPROVAL_RULE_FIELDS = [
        'ApprovalRule.required_approvals' => ['valid' => '1', 'refused' => '200 form: Required Approvals must be an integer.'],
        'ApprovalRule.timeout_minutes' => ['valid' => '60', 'refused' => '200 form: Timeout Minutes must be an integer.'],
        'ApprovalRule.approver_config: team_id' => [
            'at' => 'ApprovalRule.approver_config',
            'text' => '{"team_id": %s}',
            'body' => ['ApprovalRule' => ['approver_type' => 'team'] + self::RULE_FORM],
            'valid' => '{team}',
            'designed' => 'approvers',
            'refused' => '302 /approval-rule/view?id=%d; flash success: Approval rule "numbers-guard-rule" %s.; names no approver; wrote approval_rule, audit_log',
        ],
        'ApprovalRule.approver_config: user_ids' => [
            'at' => 'ApprovalRule.approver_config',
            'text' => '{"user_ids": [{user}, %s]}',
            'body' => ['ApprovalRule' => ['approver_type' => 'users'] + self::RULE_FORM],
            'valid' => '{admin}',
            'designed' => 'approvers',
            'refused' => '302 /approval-rule/view?id=%d; flash success: Approval rule "numbers-guard-rule" %s.; names approvers #{user}; wrote approval_rule, audit_log',
        ],
    ];

    private DatabaseWrites $writes;

    /** The JSON text the current call sends at the field. */
    private string $sent = '';

    /** Whom the rule a designed call saved names, for answerOf(). */
    private ?string $approvers = null;

    /** @var array<string, int> the rule a designed call saved, by number */
    private array $savedRules = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->writes = new DatabaseWrites(\Yii::$app->db, (int)$this->world['admin']);
        // Started here: a session that starts within logged() warns that PHP's session of an earlier test is active.
        \Yii::$app->session->removeAllFlashes();
    }

    /**
     * One data set per field of FORMS.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fieldProvider(): array
    {
        $fields = [];
        foreach (self::FORMS as $route => $form) {
            foreach (array_keys($form['fields']) as $field) {
                $fields["{$route} {$field}"] = [$route, $field];
            }
        }

        return $fields;
    }

    #[DataProvider('fieldProvider')]
    public function testEachNumberIsRefusedOrAnsweredAsDesigned(string $route, string $field): void
    {
        /** @var array<string, mixed> $form */
        $form = self::FORMS[$route];
        /** @var array<string, array<string, mixed>> $fields */
        $fields = $form['fields'];
        $case = $fields[$field];
        /** @var array<string, string> $params */
        $params = is_array($case['params'] ?? null) ? $case['params'] : (is_array($form['params'] ?? null) ? $form['params'] : []);
        $params = array_map(fn (string $value): string => $this->resolve($value), $params);
        [$controllerId, $actionId] = explode('/', $route, 2);
        $class = WebRoutes::controllerClass($controllerId);
        $this->assertNotNull($class, "the controller of {$route}");
        $body = is_array($case['body'] ?? null) ? $case['body'] : (is_array($form['body'] ?? null) ? $form['body'] : []);
        $call = function () use ($class, $controllerId, $actionId, $params, $route): mixed {
            $controller = new $class($controllerId, \Yii::$app);
            $controller->layout = false;

            return $this->withRealViews($controller, '/' . $route, fn (): mixed => $controller->runAction($actionId, $params));
        };
        $send = function (string $value) use ($body, $field, $case): void {
            $this->sent = $value;
            $this->loginAs($this->admin());
            \Yii::$app->session->removeAllFlashes();
            $this->webRequest()->setQueryParams([]);
            if (isset($case['text'])) {
                $value = (string)json_encode(sprintf($this->resolve((string)$case['text']), $value), JSON_UNESCAPED_SLASHES);
            }
            $this->sendJsonBody($this->resolve(self::bodyWith($body, (string)($case['at'] ?? $field), $value)), 'POST');
        };

        $control = $this->answerToAWholeNumber(fn () => $send($this->resolve((string)$case['valid'])), $call, $this->writes);
        $answers = $this->answersToNumbers($send, $this->designed($call, $case), $this->writes);

        $this->assertMatchesRegularExpression('/^302 /', $control, 'the post with a whole number at the field is taken');
        $this->assertDoesNotMatchRegularExpression('/; flash danger|; logged/', $control, 'the post with a whole number at the field is taken without a failure');
        $this->assertSame($this->expected($case, $route), $answers, "{$route}: {$field}");
    }

    protected function answerOf(mixed $result): string
    {
        $status = \Yii::$app->response->statusCode;
        if ($result instanceof Response) {
            $answer = $result->statusCode . ' ' . (string)$result->headers->get('Location');
        } elseif (is_string($result)) {
            $errors = self::errorsIn($result);
            $answer = $status . ' form' . ($errors === [] ? '' : ': ' . implode(' | ', $errors));
        } else {
            $answer = $status . ' ' . get_debug_type($result);
        }
        $flashes = \Yii::$app->session->getAllFlashes();
        ksort($flashes);
        foreach ($flashes as $type => $message) {
            $answer .= '; flash ' . $type . ': ' . (is_string($message) ? $message : (string)json_encode($message));
        }
        if (is_string($this->approvers)) {
            $answer .= '; ' . $this->approvers;
            $this->approvers = null;
        }

        return $answer;
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * What each number answers, by number: 'answers' over 'refused', with
     * the fixtures of the answers resolved; %d is the ID of the rule a
     * designed answer saved, %s "created" or "updated", as the route
     * creates or updates.
     *
     * @param array<string, mixed> $case
     * @return array<string, string>
     */
    private function expected(array $case, string $route): array
    {
        $answers = is_array($case['answers'] ?? null) ? $case['answers'] : [];
        $saved = str_ends_with($route, '/create') ? 'created' : 'updated';
        $expected = [];
        foreach (self::submittedNumbers() as $number) {
            $answer = (string)($answers[$number] ?? $case['refused'] ?? '');
            $answer = str_replace(['%d', '%s'], [(string)($this->savedRules[$number] ?? ''), $saved], $answer);
            $expected[$number] = $this->resolve($answer);
        }

        return $expected;
    }

    /**
     * $call, followed by the check of a designed answer: whom the saved
     * approval rule names ('approvers'), for answerOf().
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
            $rule = ApprovalRule::find()->where(['name' => self::RULE_FORM['name']])->orderBy(['id' => SORT_DESC])->one();
            if ($rule instanceof ApprovalRule) {
                $this->savedRules[$this->sent] = (int)$rule->id;
                $this->approvers = $this->approversOf((int)$rule->id);
            }

            return $result;
        };
    }

    /**
     * The errors a page shows: the error of each field (help-block,
     * invalid-feedback), and the danger alerts, as text.
     *
     * @return list<string>
     */
    private static function errorsIn(string $html): array
    {
        preg_match_all('#<div class="(?:help-block|invalid-feedback[^"]*|alert alert-danger[^"]*)"[^>]*>(.*?)</div>#s', $html, $matches);
        $errors = [];
        foreach ($matches[1] as $match) {
            $text = trim(html_entity_decode(strip_tags($match), ENT_QUOTES | ENT_HTML5));
            if ($text !== '') {
                $errors[] = (string)preg_replace('/\s+/', ' ', $text);
            }
        }

        return $errors;
    }

    private function admin(): \app\models\User
    {
        $admin = \app\models\User::findOne((int)$this->world['admin']);
        $this->assertNotNull($admin);

        return $admin;
    }
}
