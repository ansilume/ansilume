<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\SurveySecrets;
use app\controllers\api\v1\JobsController;
use app\controllers\api\v1\JobTemplatesController;
use app\controllers\api\v1\SchedulesController;
use app\controllers\api\v1\TriggerTokensController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\models\WorkflowStep;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\web\Request;

/**
 * The response schemas in web/openapi.yaml list exactly the fields the API
 * returns, with matching types, for job templates, schedules, workflow
 * templates, jobs and trigger tokens.
 *
 * Regression: the API returns whom a schedule or trigger runs as
 * (created_by of schedules and job templates, has_trigger_token and
 * trigger_user_id of job and workflow templates), and the spec did not
 * document these fields, so API clients could not rely on them.
 *
 * Regression: the trigger fields went to every caller who may view the
 * template. Only callers who may change it get them now, so the spec
 * documents them as optional and says who gets them.
 *
 * The approval and workflow responses of step 3 are checked in
 * OpenApiApprovalWorkflowResponseContractTest; the step endpoints and the
 * route fields of 2.11.0 in OpenApiWorkflowStepsContractTest, the run states
 * in OpenApiWorkflowRunStateContractTest; the inventory answers and the
 * approval gates of 2.12.0 in OpenApiInventoryApprovalContractTest.
 */
class OpenApiResponseContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use OpenApiWorkflowAssertions;

    /** Returned only to callers who may change the job or workflow template. */
    private const TRIGGER_FIELDS = ['has_trigger_token', 'trigger_user_id'];

    /** A password survey answer of the job tests; a test value, not a secret. */
    private const CONTRACT_ANSWER = 'contract-answer-marker-5150';

    private User $admin;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->userWithRole('contract-admin', 'admin');
        $this->operator = $this->userWithRole('contract-operator', 'operator');
        $this->loginAs($this->admin);
    }

    public function testJobTemplateSchemaMatchesTheResponseToACallerWhoMayChangeIt(): void
    {
        $template = $this->jobTemplate();
        $template->generateTriggerToken((int)$this->operator->id);

        $ctrl = new JobTemplatesController('api/v1/job-templates', $this->app());
        $data = $this->data($ctrl->actionView((int)$template->id));

        $this->assertMatchesSchema('JobTemplate', $data);
        $this->assertSame((int)$this->admin->id, $data['created_by']);
        $this->assertTrue($data['has_trigger_token']);
        $this->assertSame((int)$this->operator->id, $data['trigger_user_id'], 'A trigger runs as the user who generated its token.');
        $this->assertNull($data['approval_gate'], '2.12.0: a template whose launches wait for no approval has none.');
        $this->assertTrue($this->properties('JobTemplate')['approval_gate']['nullable'] ?? false, 'JobTemplate.approval_gate is null then.');
    }

    public function testJobTemplateSchemaMatchesTheResponseToACallerWhoMayOnlyViewIt(): void
    {
        $template = $this->jobTemplate();
        $template->generateTriggerToken((int)$this->operator->id);
        $this->loginAs($this->userWithRole('contract-viewer', 'viewer'));

        $ctrl = new JobTemplatesController('api/v1/job-templates', $this->app());
        $data = $this->data($ctrl->actionView((int)$template->id));

        $this->assertMatchesSchema('JobTemplate', $data, self::TRIGGER_FIELDS);
        $this->assertDocumentedAsOnlyForCallersWhoMayChange('JobTemplate', 'template');
    }

    public function testScheduleSchemaMatchesTheResponse(): void
    {
        $schedule = new Schedule();
        $schedule->name = 'contract-schedule';
        $schedule->job_template_id = (int)$this->jobTemplate()->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = (int)$this->operator->id;
        $this->assertTrue($schedule->save(false));

        $ctrl = new SchedulesController('api/v1/schedules', $this->app());
        $data = $this->data($ctrl->actionView((int)$schedule->id));

        $this->assertMatchesSchema('Schedule', $data);
        $this->assertSame((int)$this->operator->id, $data['created_by'], 'A schedule runs as its creator.');
        $this->assertArrayHasKey('extra_vars', $data);
        $this->assertNull($data['extra_vars'], 'A schedule without extra vars has null.');
    }

    /**
     * Every schedule endpoint answers with exactly the Schedule schema, and
     * a password answer comes back as the placeholder that Schedule's
     * extra_vars documents, never as its value.
     *
     * Regression (DOC-3): the responses had no extra_vars, so the placeholder
     * round trip the spec described for the API had nothing to send back.
     */
    public function testScheduleSchemaMatchesEveryResponseWithThePlaceholderForAPasswordAnswer(): void
    {
        $template = $this->createPasswordSurveyTemplate((int)$this->admin->id);
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams([
            'name' => 'contract-schedule-answers',
            'job_template_id' => (int)$template->id,
            'cron_expression' => '0 2 * * *',
            'extra_vars' => (string)json_encode([self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => self::CONTRACT_ANSWER]),
        ]);
        $ctrl = new SchedulesController('api/v1/schedules', $this->app());
        $expected = [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER];

        $created = $this->data($ctrl->actionCreate());
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $id = (int)$created['id'];
        $request->setBodyParams(['cron_expression' => '0 5 * * *']);
        $responses = [
            'POST /api/v1/schedules' => $created,
            'GET /api/v1/schedules/{id}' => $this->data($ctrl->actionView($id)),
            'GET /api/v1/schedules' => $this->listItem($ctrl->actionIndex(), $id),
            'PUT /api/v1/schedules/{id}' => $this->data($ctrl->actionUpdate($id)),
            'POST /api/v1/schedules/{id}/toggle' => $this->data($ctrl->actionToggle($id)),
        ];

        foreach ($responses as $operation => $data) {
            $this->assertMatchesSchema('Schedule', $data);
            $this->assertArrayHasKey('extra_vars', $data, $operation);
            $this->assertSame($expected, json_decode((string)$data['extra_vars'], true), $operation);
        }
        $this->assertStringNotContainsString(self::CONTRACT_ANSWER, (string)json_encode($responses));
        $this->assertDocumentedData('/api/v1/schedules', 'post', '201', 'Schedule');
        $this->assertDocumentedData('/api/v1/schedules/{id}', 'get', '200', 'Schedule');
        $this->assertDocumentedData('/api/v1/schedules/{id}', 'put', '200', 'Schedule');
        $this->assertDocumentedData('/api/v1/schedules/{id}/toggle', 'post', '200', 'Schedule');
        $documented = $this->properties('Schedule')['extra_vars'] ?? [];
        $this->assertSame('string', $documented['type'] ?? null);
        $this->assertTrue($documented['nullable'] ?? false, 'Schedule.extra_vars is null when there are none.');
        $this->assertStringContainsString(
            'password answers appear as `$encrypted$`; send them back unchanged to keep them',
            strtolower((string)preg_replace('/\s+/', ' ', (string)($documented['description'] ?? ''))),
            'Schedule.extra_vars must say how password answers appear and how to keep them.'
        );
    }

    public function testWorkflowTemplateSchemasMatchTheResponsesToACallerWhoMayChangeIt(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->jobTemplate()->id);
        $ctrl = new WorkflowTemplatesController('api/v1/workflow-templates', $this->app());

        $detail = $this->data($ctrl->actionView((int)$workflow->id));
        $this->assertMatchesSchema('WorkflowTemplateDetail', $detail);
        $this->assertFalse($detail['has_trigger_token']);
        $this->assertNull($detail['trigger_user_id'], 'Without a token no trigger runs.');
        $steps = $detail['steps'];
        $this->assertIsArray($steps);
        $this->assertCount(1, $steps);
        $this->assertIsArray($steps[0]);
        $this->assertMatchesSchema('WorkflowStep', $steps[0]);

        // 2.11.0: each step says where runs start and where its branches
        // lead, the workflow its start step and its success path, as the
        // nested schemas document them.
        $this->assertSame([1, true, true, null], [$steps[0]['position'], $steps[0]['is_start'], $steps[0]['reachable'], $steps[0]['cannot_run']]);
        $this->assertMatchesNested('WorkflowStep', 'routes', $steps[0]['routes']);
        $this->assertIsArray($steps[0]['routes']);
        foreach ($steps[0]['routes'] as $outcome => $route) {
            $this->assertMatchesNested('WorkflowStepRoutes', (string)$outcome, $route);
        }
        $this->assertSame(
            ['kind' => 'end', 'via' => 'next', 'step_id' => null, 'position' => null, 'run_outcome' => 'fails', 'loop' => false],
            $steps[0]['routes']['failure'],
            'The last step ends the run when it fails.'
        );
        $this->assertSame($steps[0]['id'], $detail['start_step_id']);
        $this->assertMatchesNested('WorkflowTemplateDetail', 'success_path', $detail['success_path']);

        $this->assertMatchesSchema('WorkflowTemplate', $this->listItem($ctrl->actionIndex(), (int)$workflow->id));
    }

    public function testWorkflowTemplateSchemasMatchTheResponsesToACallerWhoMayOnlyViewIt(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->jobTemplate()->id);
        $workflow->generateTriggerToken((int)$this->operator->id);
        $this->loginAs($this->userWithRole('contract-viewer', 'viewer'));
        $ctrl = new WorkflowTemplatesController('api/v1/workflow-templates', $this->app());

        $this->assertMatchesSchema('WorkflowTemplateDetail', $this->data($ctrl->actionView((int)$workflow->id)), self::TRIGGER_FIELDS);
        $this->assertMatchesSchema('WorkflowTemplate', $this->listItem($ctrl->actionIndex(), (int)$workflow->id), self::TRIGGER_FIELDS);
        $this->assertDocumentedAsOnlyForCallersWhoMayChange('WorkflowTemplate', 'workflow');
    }

    /**
     * POST and DELETE /api/v1/job-templates/{id}/trigger-token answer as
     * TriggerTokenCreated and TriggerTokenRevoked document, and the spec
     * documents both endpoints with these schemas.
     */
    public function testTriggerTokenSchemasMatchTheResponsesForAJobTemplate(): void
    {
        $id = (int)$this->jobTemplate()->id;
        $ctrl = new TriggerTokensController('api/v1/trigger-tokens', $this->app());

        $this->assertTriggerTokenCreated($ctrl->actionGenerateForJobTemplate($id));
        $this->assertTriggerTokenRevoked($ctrl->actionRevokeForJobTemplate($id));
        $this->assertTriggerTokenRevoked($ctrl->actionRevokeForJobTemplate($id), 'Revoking again answers the same.');

        $this->assertDocumentedData('/api/v1/job-templates/{id}/trigger-token', 'post', '201', 'TriggerTokenCreated');
        $this->assertDocumentedData('/api/v1/job-templates/{id}/trigger-token', 'delete', '200', 'TriggerTokenRevoked');
    }

    public function testTriggerTokenSchemasMatchTheResponsesForAWorkflowTemplate(): void
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->jobTemplate()->id);
        $id = (int)$workflow->id;
        $ctrl = new TriggerTokensController('api/v1/trigger-tokens', $this->app());

        $this->assertTriggerTokenCreated($ctrl->actionGenerateForWorkflowTemplate($id));
        $this->assertTriggerTokenRevoked($ctrl->actionRevokeForWorkflowTemplate($id));

        $this->assertDocumentedData('/api/v1/workflow-templates/{id}/trigger-token', 'post', '201', 'TriggerTokenCreated');
        $this->assertDocumentedData('/api/v1/workflow-templates/{id}/trigger-token', 'delete', '200', 'TriggerTokenRevoked');
    }

    /**
     * The Job and JobDetail schemas list exactly what the launch, view and
     * list endpoints return, and a password survey answer comes back as the
     * placeholder these schemas document, never as its value.
     */
    public function testJobSchemasMatchTheResponsesWithThePlaceholderForAPasswordAnswer(): void
    {
        $template = $this->createPasswordSurveyTemplate((int)$this->admin->id);
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams([
            'job_template_id' => (int)$template->id,
            'extra_vars' => [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => self::CONTRACT_ANSWER],
        ]);
        $ctrl = new JobsController('api/v1/jobs', $this->app());
        $expected = [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER];

        $launched = $ctrl->actionCreate();
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $detail = $this->data($launched);
        $this->assertMatchesSchema('JobDetail', $detail);
        $this->assertSame($expected, $detail['extra_vars']);
        $this->assertDocumentedData('/api/v1/jobs', 'post', '201', 'JobDetail');

        $id = (int)$detail['id'];
        $view = $this->data($ctrl->actionView($id));
        $this->assertMatchesSchema('JobDetail', $view);
        $this->assertSame($expected, $view['extra_vars']);

        $item = $this->listItem($ctrl->actionIndex(), $id);
        $this->assertMatchesSchema('Job', $item);
        $this->assertSame($expected, $item['extra_vars']);
        $this->assertStringNotContainsString(self::CONTRACT_ANSWER, (string)json_encode([$launched, $view, $item]));

        // 2.12.0: every answer names the inventory the job was launched with.
        $inventory = ['inventory_id' => (int)$template->inventory_id, 'inventory_name' => $template->inventory?->name];
        $this->assertIsString($inventory['inventory_name']);
        foreach (['POST' => $detail, 'GET /{id}' => $view, 'GET' => $item] as $operation => $data) {
            $this->assertSame($inventory, array_intersect_key($data, $inventory), $operation);
        }
    }

    /**
     * The descriptions of the trigger fields of $schema say that only
     * callers who may change the $noun get them.
     */
    private function assertDocumentedAsOnlyForCallersWhoMayChange(string $schema, string $noun): void
    {
        $properties = $this->properties($schema);
        foreach (self::TRIGGER_FIELDS as $name) {
            $description = $properties[$name]['description'] ?? null;
            $this->assertIsString($description, "{$schema}.{$name}");
            $this->assertStringContainsStringIgnoringCase(
                "only callers who may change the {$noun}",
                (string)preg_replace('/\s+/', ' ', $description),
                "{$schema}.{$name} must say who gets it."
            );
        }
    }

    /**
     * A generate response: 201, exactly TriggerTokenCreated, a token of the
     * documented format, and the caller as the user the trigger runs as.
     *
     * @param array<string, mixed> $result
     */
    private function assertTriggerTokenCreated(array $result): void
    {
        $this->assertSame(201, \Yii::$app->response->statusCode);
        $created = $this->data($result);
        $this->assertMatchesSchema('TriggerTokenCreated', $created);
        $pattern = $this->properties('TriggerTokenCreated')['token']['pattern'] ?? null;
        $this->assertIsString($pattern);
        $this->assertMatchesRegularExpression('~' . $pattern . '~', (string)$created['token'], 'The token has the documented format.');
        $this->assertSame((int)$this->admin->id, $created['trigger_user_id'], 'The trigger now runs as the caller.');
    }

    /**
     * A revoke response: 200 and exactly TriggerTokenRevoked.
     *
     * @param array<string, mixed> $result
     */
    private function assertTriggerTokenRevoked(array $result, string $message = ''): void
    {
        $this->assertSame(200, \Yii::$app->response->statusCode, $message);
        $revoked = $this->data($result);
        $this->assertMatchesSchema('TriggerTokenRevoked', $revoked);
        $this->assertSame(['revoked' => true], $revoked, $message);
    }

    private function jobTemplate(): JobTemplate
    {
        return $this->jobTemplateOwnedBy($this->admin);
    }
}
