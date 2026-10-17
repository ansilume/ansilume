<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\AnalyticsController;
use app\controllers\api\v1\ApprovalRulesController;
use app\controllers\api\v1\ApprovalsController;
use app\controllers\api\v1\JobTemplatesController;
use app\controllers\api\v1\WorkflowJobsController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\web\Request;

/**
 * The response schemas in web/openapi.yaml list exactly the fields the API
 * returns, with matching types, for the approval and workflow endpoints:
 * job templates with their approval rule, the deletes of job and workflow
 * templates, approval requests, approval rules with their usage, workflow
 * runs with their steps, and the approval summary.
 *
 * Regression from step 3 (approvals and workflows): these responses gained
 * fields (approval_rule_id and approval_rule_name, canceled_jobs and
 * disabled_schedules, canceled_runs and failed_runs, launched_by, used_by,
 * extra_vars, step_name and step_type, canceled) that the spec did not
 * document, and approval rules had no schema at all. Each test runs the
 * endpoint and compares its answer with the spec.
 *
 * From 2.12.0 (approval rules on inventories): approval_gate of job
 * templates, required_by, inventory_id and inventory_name of approval
 * requests, and the inventories of an approval rule's usage, each compared
 * with the answer that returns it.
 */
class OpenApiApprovalWorkflowResponseContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use OpenApiWorkflowAssertions;
    use TeamScopeFixtures;
    use CancellationFixtures;

    private User $admin;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->userWithRole('contract-admin', 'admin');
        $this->operator = $this->userWithRole('contract-operator', 'operator');
        $this->loginAs($this->admin);
    }

    /**
     * A job template that an approval rule gates returns the rule's ID and
     * name, as JobTemplate documents them; one without a rule returns null
     * for both (testJobTemplateSchemaMatches... above).
     */
    public function testJobTemplateSchemaDocumentsTheApprovalRuleOfAGatedTemplate(): void
    {
        $template = $this->jobTemplate();
        $rule = $this->usersRule((int)$this->admin->id, (int)$this->operator->id);
        $template->approval_rule_id = (int)$rule->id;
        $this->assertTrue($template->save(false));

        $ctrl = new JobTemplatesController('api/v1/job-templates', $this->app());
        $data = $this->data($ctrl->actionView((int)$template->id));

        $this->assertMatchesSchema('JobTemplate', $data);
        $this->assertSame((int)$rule->id, $data['approval_rule_id']);
        $this->assertSame($rule->name, $data['approval_rule_name']);
        // 2.12.0: approval_gate names the rule its launches wait for, here
        // its own, as JobTemplateApprovalGate documents it.
        $this->assertIsArray($data['approval_gate']);
        $this->assertMatchesSchema('JobTemplateApprovalGate', $data['approval_gate']);
        $this->assertSame(
            ['approval_rule_id' => (int)$rule->id, 'approval_rule_name' => $rule->name, 'required_by' => ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE],
            $data['approval_gate']
        );
    }

    /**
     * DELETE /api/v1/job-templates/{id} answers with what the delete
     * resolved, exactly as JobTemplateDeletion documents it.
     */
    public function testJobTemplateDeletionSchemaMatchesTheResponse(): void
    {
        $template = $this->jobTemplate();
        $this->createJob((int)$template->id, (int)$this->admin->id, Job::STATUS_QUEUED);
        $schedule = new Schedule();
        $schedule->name = 'contract-deleted-template-schedule';
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = (int)$this->admin->id;
        $this->assertTrue($schedule->save(false));

        $this->recordNotifications();
        try {
            $ctrl = new JobTemplatesController('api/v1/job-templates', $this->app());
            $data = $this->data($ctrl->actionDelete((int)$template->id));
        } finally {
            $this->restoreNotifications();
        }

        $this->assertMatchesSchema('JobTemplateDeletion', $data);
        $this->assertSame(['deleted' => true, 'canceled_jobs' => 1, 'disabled_schedules' => 1], $data);
        $this->assertDocumentedData('/api/v1/job-templates/{id}', 'delete', '200', 'JobTemplateDeletion');
    }

    /**
     * DELETE /api/v1/workflow-templates/{id} answers with the runs it
     * canceled, exactly as WorkflowTemplateDeletion documents it.
     */
    public function testWorkflowTemplateDeletionSchemaMatchesTheResponse(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);

        $this->recordNotifications();
        try {
            $ctrl = new WorkflowTemplatesController('api/v1/workflow-templates', $this->app());
            $data = $this->data($ctrl->actionDelete((int)$workflow->id));
        } finally {
            $this->restoreNotifications();
        }

        $this->assertMatchesSchema('WorkflowTemplateDeletion', $data);
        $this->assertTrue($data['deleted']);
        $this->assertSame(1, $data['canceled_runs']);
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_CANCELED, $run->status);
        $this->assertDocumentedData('/api/v1/workflow-templates/{id}', 'delete', '200', 'WorkflowTemplateDeletion');
    }

    /**
     * GET /api/v1/approvals/{id} returns exactly the ApprovalRequest
     * schema, launched_by included, and the schema's statuses are the
     * model's. Its decisions, every vote, are ApprovalDecision objects.
     *
     * Regression (wave 5, W5-API-4): the API answered decisions, which the
     * schema did not have.
     */
    public function testApprovalRequestSchemaMatchesTheResponse(): void
    {
        $this->recordNotifications();
        try {
            ['request' => $request, 'job' => $job] = $this->jobWaitingForApproval(
                $this->jobTemplate(),
                (int)$this->operator->id,
                (int)$this->admin->id
            );
        } finally {
            $this->restoreNotifications();
        }

        $ctrl = new ApprovalsController('api/v1/approvals', $this->app());
        $data = $this->data($ctrl->actionView((int)$request->id));

        $this->assertMatchesSchema('ApprovalRequest', $data);
        $this->assertSame((int)$job->launched_by, $data['launched_by'], 'launched_by is the user who launched the job.');
        $this->assertSame(
            ['workflow_job_id' => null, 'workflow_job_step_id' => null, 'workflow_template_id' => null, 'workflow_step_name' => null],
            array_intersect_key($data, array_flip(['workflow_job_id', 'workflow_job_step_id', 'workflow_template_id', 'workflow_step_name'])),
            'The request of a job template launched outside workflows names no workflow run (2.11.0).'
        );
        $this->assertSame(ApprovalRequest::statuses(), $this->properties('ApprovalRequest')['status']['enum'] ?? null);
        $this->assertSame([], array_diff(array_keys($data), $this->required('ApprovalRequest')), 'Every field is always returned.');
        // 2.12.0: what required the request, and the inventory its job was
        // launched with; this job's launch recorded none.
        $this->assertSame(
            ['required_by' => ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, 'inventory_id' => null, 'inventory_name' => null],
            array_intersect_key($data, array_flip(['required_by', 'inventory_id', 'inventory_name']))
        );
        $this->assertSame(ApprovalRequest::REQUIRED_BY_VALUES, $this->enumOf('ApprovalRequest', 'required_by'));
        $this->assertDocumentedData('/api/v1/approvals/{id}', 'get', '200', 'ApprovalRequest');
        $this->assertMatchesSchema('ApprovalRequest', $this->listItem($ctrl->actionIndex(), (int)$request->id));

        // Wave 5 (W5-API-4): every vote, as ApprovalDecision documents it.
        $this->assertSame([], $data['decisions'], 'A request without votes lists none.');
        $vote = new ApprovalDecision();
        $vote->approval_request_id = (int)$request->id;
        $vote->user_id = (int)$this->admin->id;
        $vote->decision = ApprovalDecision::DECISION_APPROVED;
        $vote->comment = null;
        $vote->created_at = time();
        $this->assertTrue($vote->save(false));
        $voted = $this->data($ctrl->actionView((int)$request->id));
        $this->assertMatchesSchema('ApprovalRequest', $voted);
        $this->assertIsArray($voted['decisions']);
        $this->assertCount(1, $voted['decisions']);
        $this->assertIsArray($voted['decisions'][0]);
        $this->assertMatchesSchema('ApprovalDecision', $voted['decisions'][0]);
        $this->assertSame([], array_diff(array_keys($voted['decisions'][0]), $this->required('ApprovalDecision')), 'Every field of a vote is always returned.');
        $this->assertSame([ApprovalDecision::DECISION_APPROVED, ApprovalDecision::DECISION_REJECTED], $this->properties('ApprovalDecision')['decision']['enum'] ?? null);
        $this->assertSame('#/components/schemas/ApprovalDecision', $this->properties('ApprovalRequest')['decisions']['items']['$ref'] ?? null);
    }

    /**
     * The approval rule endpoints return exactly the ApprovalRule schema,
     * and GET of one rule adds used_by as ApprovalRuleUsage documents it,
     * the items of its lists included.
     */
    public function testApprovalRuleSchemasMatchTheResponses(): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams([
            'name' => 'contract-rule',
            'approver_type' => 'users',
            'approver_config' => ['user_ids' => [(int)$this->operator->id]],
        ]);
        $ctrl = new ApprovalRulesController('api/v1/approval-rules', $this->app());

        $created = $this->data($ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertMatchesSchema('ApprovalRule', $created);
        $ruleId = (int)$created['id'];
        $template = $this->jobTemplate();
        $template->approval_rule_id = $ruleId;
        $this->assertTrue($template->save(false));
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_APPROVAL, null, $ruleId);
        // 2.12.0: an inventory that the rule gates is listed too.
        $inventory = $this->createInventory((int)$this->admin->id);
        $this->assertSame(1, Inventory::updateAll(['approval_rule_id' => $ruleId], ['id' => $inventory->id]));

        $detail = $this->data($ctrl->actionView($ruleId));
        $this->assertMatchesSchema('ApprovalRuleDetail', $detail);
        $usedBy = $detail['used_by'];
        $this->assertIsArray($usedBy);
        $this->assertMatchesSchema('ApprovalRuleUsage', $usedBy);
        $this->assertTrue($usedBy['in_use']);
        $usage = $this->properties('ApprovalRuleUsage');
        foreach (['inventories' => 1, 'job_templates' => 1, 'workflow_steps' => 1] as $list => $count) {
            $this->assertIsArray($usedBy[$list]);
            $this->assertCount($count, $usedBy[$list], $list);
            $item = $usedBy[$list][0];
            $this->assertIsArray($item);
            $itemProperties = $this->dig($usage, [$list, 'items', 'properties'], "ApprovalRuleUsage.{$list} documents its items.");
            $this->assertIsArray($itemProperties);
            $this->assertMatchesProperties($itemProperties, $item, "ApprovalRuleUsage.{$list}[]");
        }

        $request->setBodyParams(['required_approvals' => 1]);
        $this->assertMatchesSchema('ApprovalRule', $this->data($ctrl->actionUpdate($ruleId)));
        $this->assertMatchesSchema('ApprovalRule', $this->listItem($ctrl->actionIndex(), $ruleId));
        $this->assertDocumentedData('/api/v1/approval-rules', 'post', '201', 'ApprovalRule');
        $this->assertDocumentedData('/api/v1/approval-rules/{id}', 'get', '200', 'ApprovalRuleDetail');
        $this->assertDocumentedData('/api/v1/approval-rules/{id}', 'put', '200', 'ApprovalRule');
    }

    /**
     * Regression (review of step 3, contract-2): a rule without approvers,
     * which the web form saves as {}, came back as the JSON list [], where
     * the spec documents an object. The single rule and the list both
     * return {}.
     */
    public function testAnApproverConfigWithoutApproversIsReturnedAsTheDocumentedObject(): void
    {
        $rule = $this->createApprovalRule((int)$this->admin->id, 'role', '{}');
        $ctrl = new ApprovalRulesController('api/v1/approval-rules', $this->app());

        $detail = $this->data($ctrl->actionView((int)$rule->id));
        $item = $this->listItem($ctrl->actionIndex(), (int)$rule->id);

        $this->assertSame('object', $this->properties('ApprovalRule')['approver_config']['type'] ?? null);
        foreach (['ApprovalRuleDetail' => $detail, 'ApprovalRule' => $item] as $schema => $data) {
            $this->assertSame('{}', json_encode($data['approver_config']), "{$schema} returns an object.");
            $this->assertMatchesSchema($schema, $data);
        }
    }

    /**
     * GET /api/v1/workflow-jobs/{id} returns exactly WorkflowJobDetail: the
     * run's launch extra vars as an object, and each execution as
     * WorkflowJobStep documents it, also one whose step was removed.
     */
    public function testWorkflowJobDetailSchemaMatchesTheResponse(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $kept = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_PAUSE);
        $removed = $this->createWorkflowStep((int)$workflow->id, 2, WorkflowStep::TYPE_PAUSE);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id, WorkflowJob::STATUS_FAILED);
        $run->setLaunchExtraVars(['env' => 'prod']);
        $this->assertTrue($run->save(false));
        foreach ([$kept, $removed] as $step) {
            $execution = new WorkflowJobStep();
            $execution->workflow_job_id = (int)$run->id;
            $execution->workflow_step_id = (int)$step->id;
            $execution->snapshotStep($step);
            $execution->status = WorkflowJobStep::STATUS_SUCCEEDED;
            $execution->started_at = time();
            $execution->finished_at = time();
            $execution->created_at = time();
            $execution->updated_at = time();
            $this->assertTrue($execution->save(false));
        }
        $this->assertSame(1, $removed->delete());

        $ctrl = new WorkflowJobsController('api/v1/workflow-jobs', $this->app());
        $data = $this->data($ctrl->actionView((int)$run->id));

        $this->assertMatchesSchema('WorkflowJobDetail', $data);
        $this->assertEquals((object)['env' => 'prod'], $data['extra_vars']);
        // 2.11.0: a finished run waits for nothing and is at no step; its
        // steps wait for nothing, and these had no job and no request.
        $this->assertSame([null, null], [$data['waiting_for'], $data['current_step']]);
        $this->assertMatchesNested('WorkflowJobDetail', 'current_step', $data['current_step']);
        $steps = $data['steps'];
        $this->assertIsArray($steps);
        $this->assertCount(2, $steps);
        foreach ($steps as $step) {
            $this->assertIsArray($step);
            $this->assertMatchesSchema('WorkflowJobStep', $step);
            $this->assertSame([null, null, null, null], [$step['waiting_for'], $step['job_status'], $step['approval_request_id'], $step['approval']]);
            $this->assertMatchesNested('WorkflowJobStep', 'approval', $step['approval']);
        }
        $this->assertNull($steps[1]['workflow_step_id'], 'null once the step was removed');
        $this->assertSame($removed->name, $steps[1]['step_name'], 'The name it had when it ran.');
        $this->assertSame(WorkflowStep::TYPE_PAUSE, $steps[1]['step_type']);
        $this->assertDocumentedData('/api/v1/workflow-jobs/{id}', 'get', '200', 'WorkflowJobDetail');
    }

    /**
     * GET /api/v1/analytics/approval-summary returns exactly the
     * ApprovalSummary schema as its result, canceled included.
     */
    public function testApprovalSummarySchemaMatchesTheResult(): void
    {
        $ctrl = new AnalyticsController('api/v1/analytics', $this->app());
        $data = $this->data($ctrl->actionApprovalSummary());

        $result = $data['result'] ?? null;
        $this->assertIsArray($result);
        $this->assertMatchesSchema('ApprovalSummary', $result);
        $this->assertSame(array_keys($result), $this->required('ApprovalSummary'), 'The CSV columns follow the documented order.');
        $response = $this->dig($this->spec(), ['paths', '/api/v1/analytics/approval-summary', 'get', 'responses', '200', '$ref'], 'The approval summary documents its 200.');
        $this->assertSame(self::RESPONSE_REF_PREFIX . 'ApprovalSummaryResult', $response);
        $this->assertSame(
            self::SCHEMA_REF_PREFIX . 'ApprovalSummary',
            $this->dig(
                $this->spec(),
                ['components', 'responses', 'ApprovalSummaryResult', 'content', 'application/json', 'schema', 'properties', 'data', 'properties', 'result', '$ref'],
                'ApprovalSummaryResult documents its result.'
            )
        );
    }

    /**
     * Regression (review of step 3, contract-4): the spec said steps are
     * read and approval steps checked before the template is validated.
     * The controller validates the template first, as the spec says now: an
     * operator's POST of an approval step without a name answers 422 for
     * the name, with a name 403 for the approval step, and writes nothing.
     */
    public function testAWorkflowTemplateIsValidatedBeforeItsStepsAsTheSpecSays(): void
    {
        $this->loginAs($this->operator);
        $rule = $this->usersRule((int)$this->admin->id, (int)$this->admin->id);
        $steps = [['name' => 'Gate', 'step_type' => WorkflowStep::TYPE_APPROVAL, 'approval_rule_id' => (int)$rule->id]];
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $ctrl = new WorkflowTemplatesController('api/v1/workflow-templates', $this->app());

        $request->setBodyParams(['name' => '', 'steps' => $steps]);
        $this->assertSame(['error' => ['message' => 'Name cannot be blank.']], $ctrl->actionCreate());
        $this->assertSame(422, \Yii::$app->response->statusCode, 'The template first.');

        $request->setBodyParams(['name' => 'contract-answer-order', 'steps' => $steps]);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::APPROVAL_STEP_ADD_REFUSED]], $ctrl->actionCreate());
        $this->assertSame(403, \Yii::$app->response->statusCode, 'Then the approval steps.');
        $this->assertFalse(WorkflowTemplate::find()->where(['name' => 'contract-answer-order'])->exists(), 'Nothing was written.');

        $create = (string)preg_replace('/\s+/', ' ', (string)$this->dig($this->spec(), ['paths', '/api/v1/workflow-templates', 'post', 'description'], 'POST is described.'));
        $this->assertStringContainsString(
            'The answers come in this order: 422 for a template that is not valid, such as one without a name (`Name cannot be blank.`); 422 for steps that cannot be read; 403 for approval steps and gated job steps;',
            $create
        );
    }

    private function jobTemplate(): JobTemplate
    {
        return $this->jobTemplateOwnedBy($this->admin);
    }
}
