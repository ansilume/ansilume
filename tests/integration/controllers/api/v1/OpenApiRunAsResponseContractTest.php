<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ApprovalRulesController;
use app\controllers\api\v1\SchedulesController;
use app\controllers\api\v1\WorkflowJobsController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\ScheduleRunAs;
use app\services\WorkflowExecutionService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Request;

/**
 * What web/openapi.yaml says about whom a launch runs as, and about the
 * refusals and failures that came with it, is what the API answers: each
 * test runs the endpoint and compares its answer with the spec.
 *
 * Regressions from the last review round of step 3, each test fails on the
 * spec as it was before: the spec said that a schedule keeps its creator
 * when it is updated, named no refusal of gated job steps, documented no
 * 500 for a run cancel or resume that failed on a database error, and did
 * not say that an update of a rule with an older approver list must send an
 * object.
 */
class OpenApiRunAsResponseContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use TeamScopeFixtures;
    use CancellationFixtures;

    private User $admin;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->userWithRole('runas-contract-admin', 'admin');
        $this->operator = $this->userWithRole('runas-contract-operator', 'operator');
        $this->loginAs($this->admin);
    }

    /**
     * Regression (decision 4): an update that changes what or when a
     * schedule runs, and enabling it, make the caller the user it runs as
     * (created_by), with run_as in the audit entry; a rename and a disable
     * keep that user. The spec says so for PUT and the toggle.
     */
    public function testAScheduleRunsAsTheUserWhoLastChangedItAsTheSpecSays(): void
    {
        $ctrl = new SchedulesController('api/v1/schedules', $this->app());
        $template = $this->jobTemplateOwnedBy($this->admin);
        $created = $this->scheduleCall($ctrl, null, ['name' => 'runas-contract', 'job_template_id' => (int)$template->id, 'cron_expression' => '0 2 * * *']);
        $id = (int)$created['id'];
        $adminId = (int)$this->admin->id;
        $operatorId = (int)$this->operator->id;
        $this->assertSame($adminId, $created['created_by'], 'The creator runs it first.');

        $this->loginAs($this->operator);
        $updated = $this->scheduleCall($ctrl, $id, ['cron_expression' => '30 2 * * *']);
        $this->assertMatchesSchema('Schedule', $updated);
        $this->assertSame($operatorId, $updated['created_by'], 'A new cron expression makes the caller the user it runs as.');
        $this->assertSame([ScheduleRunAs::AUDIT_KEY => ['from' => $adminId, 'to' => $operatorId]], $this->runAsOf(AuditLog::ACTION_SCHEDULE_UPDATED, $id));

        $this->loginAs($this->admin);
        $renamed = $this->scheduleCall($ctrl, $id, ['name' => 'runas-contract-renamed']);
        $this->assertSame($operatorId, $renamed['created_by'], 'A rename keeps the user.');
        $this->assertSame([], $this->runAsOf(AuditLog::ACTION_SCHEDULE_UPDATED, $id));
        $disabled = $this->data($ctrl->actionToggle($id));
        $this->assertFalse($disabled['enabled']);
        $this->assertSame($operatorId, $disabled['created_by'], 'Disabling keeps the user.');
        $enabled = $this->data($ctrl->actionToggle($id));
        $this->assertTrue($enabled['enabled']);
        $this->assertSame($adminId, $enabled['created_by'], 'Enabling makes the caller the user it runs as.');
        $this->assertSame([ScheduleRunAs::AUDIT_KEY => ['from' => $operatorId, 'to' => $adminId]], $this->runAsOf(AuditLog::ACTION_SCHEDULE_TOGGLED, $id));

        $put = $this->textOf(['paths', '/api/v1/schedules/{id}', 'put', 'description']);
        $this->assertStringContainsString('makes the caller the user the schedule runs as (`created_by`)', $put);
        $this->assertStringContainsString('`' . ScheduleRunAs::AUDIT_KEY . '` with `from` and `to`', $put);
        $this->assertStringContainsString('Renaming the schedule, disabling it, and sending the stored values again keep the user it runs as.', $put);
        $toggle = $this->textOf(['paths', '/api/v1/schedules/{id}/toggle', 'post', 'description']);
        $this->assertStringContainsString('Disabling always works and keeps the user the schedule runs as. Enabling makes the caller the user the schedule runs as (`created_by`)', $toggle);
        $this->assertStringContainsString('`' . ScheduleRunAs::AUDIT_KEY . '` with `from` and `to`', $toggle);
        $runsAs = $this->textOf(['components', 'schemas', 'Schedule', 'properties', 'created_by', 'description']);
        $this->assertStringContainsString('its creator, or the user who last changed its job template, cron expression, timezone or extra vars, or enabled it.', $runsAs);
        $this->assertStringNotContainsString('It cannot be changed.', $runsAs);
    }

    /**
     * Regression (decision 4): a job step whose job template has an
     * approval rule is for admins, also when the template got its rule
     * after the step was added; the API refuses with the messages the
     * spec's examples quote, and writes nothing.
     */
    public function testGatedJobStepsAreRefusedAsTheSpecSays(): void
    {
        $rule = (int)$this->usersRule((int)$this->admin->id, (int)$this->admin->id, (int)$this->operator->id)->id;
        $gated = $this->jobTemplateOwnedBy($this->admin);
        $gated->approval_rule_id = $rule;
        $this->assertTrue($gated->save(false));
        $later = $this->jobTemplateOwnedBy($this->admin);
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$later->id);
        $later->approval_rule_id = $rule;
        $this->assertTrue($later->save(false));
        $open = $this->jobTemplateOwnedBy($this->admin);
        $this->loginAs($this->operator);
        $ctrl = new WorkflowTemplatesController('api/v1/workflow-templates', $this->app());

        $this->body(['name' => 'runas-contract-gated', 'steps' => [['name' => 'Deploy', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => (int)$gated->id]]]);
        $create = $ctrl->actionCreate();
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::GATED_JOB_STEP_ADD_REFUSED]], $create);
        $this->assertContains($create['error']['message'], $this->exampleMessages('/api/v1/workflow-templates', 'post'));
        $this->assertFalse(WorkflowTemplate::find()->where(['name' => 'runas-contract-gated'])->exists(), 'Nothing was written.');

        $this->body(['steps' => [['name' => 'Build', 'step_type' => WorkflowStep::TYPE_JOB, 'job_template_id' => (int)$open->id]]]);
        $update = $ctrl->actionUpdate((int)$workflow->id);
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED]], $update);
        $this->assertContains($update['error']['message'], $this->exampleMessages('/api/v1/workflow-templates/{id}', 'put'));
        $this->assertSame([(int)$later->id], array_map('intval', WorkflowStep::find()->select('job_template_id')->where(['workflow_template_id' => $workflow->id])->column()));
    }

    /**
     * Regression (last review round of step 3, substantive 4): a database
     * error of a run cancel or resume answered a bare server error. It
     * answers 500 with the messages the spec's examples quote.
     */
    public function testARunOperationThatFailsOnADatabaseErrorAnswersAsTheSpecSays(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $before = $this->app()->getComponents(true)['workflowExecutionService'] ?? null;
        $this->app()->set('workflowExecutionService', new class () extends WorkflowExecutionService {
            public function cancel(WorkflowJob $wfJob, int $userId): void
            {
                throw new \yii\db\Exception('Deadlock found', ['40001', 1213, 'Deadlock found']);
            }

            public function resume(WorkflowJob $wfJob, int $userId): void
            {
                throw new \yii\db\Exception('Deadlock found', ['40001', 1213, 'Deadlock found']);
            }
        });
        try {
            $ctrl = new WorkflowJobsController('api/v1/workflow-jobs', $this->app());
            $answers = ['cancel' => $ctrl->actionCancel((int)$run->id), 'resume' => $ctrl->actionResume((int)$run->id)];
        } finally {
            $this->app()->set('workflowExecutionService', $before);
        }

        $documented = $this->dig($this->spec(), ['components', 'responses', 'WorkflowRunOperationFailed', 'content', 'application/json', 'examples'], 'The 500 has examples.');
        $this->assertIsArray($documented);
        foreach ($answers as $operation => $answer) {
            $this->assertSame(500, \Yii::$app->response->statusCode, $operation);
            $message = $answer['error']['message'] ?? null;
            $this->assertIsString($message, $operation);
            $example = $this->dig($documented, [$operation, 'value', 'error', 'message'], "The {$operation} example.");
            $this->assertIsString($example);
            $this->assertSame(str_replace('#' . $run->id . ' ', '#12 ', $message), $example, "The {$operation} example is the answer, for run #12.");
            $this->assertStringNotContainsString('Deadlock found', $message, 'Never the database error\'s message.');
            $this->assertSame(
                self::RESPONSE_REF_PREFIX . 'WorkflowRunOperationFailed',
                $this->dig($this->spec(), ['paths', "/api/v1/workflow-jobs/{id}/{$operation}", 'post', 'responses', '500', '$ref'], "POST {$operation} documents its 500.")
            );
        }
    }

    /**
     * An update of a rule whose approver list an older version stored as a
     * list answers 422 until it sends an object, as the spec says; the rule
     * is returned with {} meanwhile.
     */
    public function testAnUpdateOfARuleWithAnOlderApproverListAnswersAsTheSpecSays(): void
    {
        $rule = $this->createApprovalRule((int)$this->admin->id, ApprovalRule::APPROVER_TYPE_USERS, '[7, 12]');
        $ctrl = new ApprovalRulesController('api/v1/approval-rules', $this->app());
        $this->assertSame('{}', json_encode($this->data($ctrl->actionView((int)$rule->id))['approver_config']));

        $this->body(['required_approvals' => 1]);
        $refused = $ctrl->actionUpdate((int)$rule->id);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $message = sprintf(ApprovalRule::NOT_A_JSON_OBJECT, $rule->getAttributeLabel('approver_config'));
        $this->assertSame(['error' => ['message' => $message]], $refused);

        $this->body(['approver_config' => ['user_ids' => [(int)$this->operator->id]], 'required_approvals' => 1]);
        $healed = $this->data($ctrl->actionUpdate((int)$rule->id));
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(['user_ids' => [(int)$this->operator->id]], $healed['approver_config']);

        $input = $this->textOf(['components', 'schemas', 'ApprovalRuleInput', 'properties', 'approver_config', 'description']);
        $this->assertStringContainsString('`' . $message . '` So does an update that leaves the field out', $input);
    }

    /**
     * A schedule call as the signed-in user: create ($id null) or update.
     *
     * @param array<string, mixed> $body
     * @return array<mixed>
     */
    private function scheduleCall(SchedulesController $ctrl, ?int $id, array $body): array
    {
        $this->body($body);

        return $this->data($id === null ? $ctrl->actionCreate() : $ctrl->actionUpdate($id));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function body(array $body): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams($body);
    }

    /**
     * The run_as part of the context of the newest $action entry of
     * schedule #$id: [] when it has none.
     *
     * @return array<string, mixed>
     */
    private function runAsOf(string $action, int $id): array
    {
        /** @var AuditLog|null $entry */
        $entry = AuditLog::find()->where(['action' => $action, 'object_type' => 'schedule', 'object_id' => $id])->orderBy(['id' => SORT_DESC])->one();
        $this->assertNotNull($entry, "{$action} of schedule #{$id}");
        $context = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($context);

        return array_intersect_key($context, [ScheduleRunAs::AUDIT_KEY => true]);
    }

    /**
     * The messages of the examples of the 403 of $method $path.
     *
     * @return list<mixed>
     */
    private function exampleMessages(string $path, string $method): array
    {
        $examples = $this->dig($this->spec(), ['paths', $path, $method, 'responses', '403', 'content', 'application/json', 'examples'], "{$method} {$path} has 403 examples.");
        $this->assertIsArray($examples);
        $messages = [];
        foreach ($examples as $example) {
            $this->assertIsArray($example);
            $messages[] = $this->dig($example, ['value', 'error', 'message'], 'An example of the error shape.');
        }

        return $messages;
    }

    /**
     * The text at $keys of the spec, on one line.
     *
     * @param list<string> $keys
     */
    private function textOf(array $keys): string
    {
        $text = $this->dig($this->spec(), $keys, 'web/openapi.yaml has ' . implode('.', $keys) . '.');
        $this->assertIsString($text);

        return trim((string)preg_replace('/\s+/', ' ', $text));
    }
}
