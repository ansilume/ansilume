<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\base\ModelEvent;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

/**
 * API v1 workflow templates.
 *
 * Regression: the API ignored team scoping for workflows. Any token could
 * list, read, change, delete and launch every team's workflows, and build
 * workflows from job templates of other teams. Steps were saved unvalidated
 * (save(false)), and an update deleted the old steps before looking at the
 * new ones, so a rejected update lost them.
 */
class WorkflowTemplatesControllerTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** A valid first step; the data provider cannot know the template's ID. */
    private const OWN_STEP = ['name' => 'own', 'job_template_id' => 'OWN'];

    private WorkflowTemplatesController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app);
    }

    // -- Index ----------------------------------------------------------------

    public function testIndexListsOnlyWorkflowsTheCallerMayView(): void
    {
        $s = $this->teamScope();
        $visible = [
            $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id),
            $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id),
            $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id),
            $this->pauseOnlyWorkflow($s['admin']->id),
        ];
        $hidden = [
            $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id),
            $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id),
            $this->createWorkflowWithJobSteps($s['admin']->id, 987654321),
        ];
        $this->authenticate($s['member']);

        $result = $this->ctrl->actionIndex();

        $ids = array_column($result['data'], 'id');
        foreach ($visible as $workflow) {
            $this->assertContains($workflow->id, $ids);
        }
        foreach ($hidden as $workflow) {
            $this->assertNotContains($workflow->id, $ids);
        }
        $this->assertSame(count($ids), $result['meta']['total'], 'the total counts visible workflows only');
    }

    public function testIndexShowsAdminsEveryWorkflow(): void
    {
        $s = $this->teamScope();
        $foreign = $this->createWorkflowWithJobSteps($s['member']->id, $s['foreign']->id);
        $this->authenticate($s['admin']);

        $result = $this->ctrl->actionIndex();

        $this->assertContains($foreign->id, array_column($result['data'], 'id'));
    }

    // -- View -----------------------------------------------------------------

    public function testViewOfWorkflowWithAForeignStepIsForbidden(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id);
        $this->authenticate($s['member']);

        $result = $this->ctrl->actionView($workflow->id);

        $this->assertForbiddenResponse($result);
    }

    public function testViewOfViewedOnlyWorkflowShowsItsSteps(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $this->authenticate($s['member']);

        $data = $this->data($this->ctrl->actionView($workflow->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame($workflow->id, $data['id']);
        $this->assertSame($s['viewed']->id, $data['steps'][0]['job_template_id']);
    }

    public function testViewLetsAdminsSeeEveryWorkflowAndRefusesGuests(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['member']->id, $s['foreign']->id);

        $this->assertForbiddenResponse($this->ctrl->actionView($workflow->id));

        $this->authenticate($s['admin']);
        $this->assertSame($workflow->id, $this->data($this->ctrl->actionView($workflow->id))['id']);
    }

    public function testViewThroughTheRequestPipelineAnswers403(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);
        $this->authenticate($s['member']);

        $result = $this->ctrl->runAction('view', ['id' => $workflow->id]);

        $this->assertForbiddenResponse($result);
    }

    public function testViewOfUnknownWorkflowIs404(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);

        $this->expectException(NotFoundHttpException::class);
        $this->ctrl->actionView(987654321);
    }

    // -- Create ---------------------------------------------------------------

    public function testCreateSavesTheTemplateAndValidatedStepsInOrder(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule($s['admin']->id);
        $this->authenticate($s['member']);
        $this->setBody([
            'name' => 'api-wf-create',
            'description' => 'built through the API',
            'steps' => [
                ['name' => 'build', 'step_type' => 'job', 'job_template_id' => $s['own']->id, 'extra_vars_template' => ['env' => 'target_env']],
                ['name' => 'approve', 'step_type' => 'approval', 'approval_rule_id' => (string)$rule->id],
                ['name' => 'wait', 'step_type' => 'pause'],
                ['name' => 'deploy', 'job_template_id' => (string)$s['open']->id],
            ],
        ]);

        $data = $this->data($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame('api-wf-create', $data['name']);
        $this->assertSame($s['member']->id, $data['created_by']);
        $this->assertSame(['build', 'approve', 'wait', 'deploy'], array_column($data['steps'], 'name'));
        $this->assertSame(['job', 'approval', 'pause', 'job'], array_column($data['steps'], 'step_type'));
        $this->assertSame([0, 1, 2, 3], array_column($data['steps'], 'step_order'));
        $this->assertSame([$s['own']->id, null, null, $s['open']->id], array_column($data['steps'], 'job_template_id'));
        $this->assertSame([null, $rule->id, null, null], array_column($data['steps'], 'approval_rule_id'));
        $this->assertSame(['env' => 'target_env'], $data['steps'][0]['extra_vars_template']);

        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_CREATED, 'object_id' => $data['id']]);
        $this->assertNotNull($audit);
        $this->assertSame(
            ['name' => 'api-wf-create', 'source' => 'api', 'job_template_ids' => [$s['own']->id, $s['open']->id]],
            json_decode((string)$audit->metadata, true)
        );
    }

    public function testCreateWithTemplateOfAnotherTeamReportsItMissingAndWritesNothing(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'api-wf-foreign', 'steps' => [
            ['name' => 'own', 'job_template_id' => $s['own']->id],
            ['name' => 'foreign', 'job_template_id' => $s['foreign']->id],
        ]]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame([$s['foreign']->id], $result['error']['job_template_ids'] ?? null);
        $this->assertSame('Job template(s) not found: #' . $s['foreign']->id . '.', $result['error']['message']);
        $this->assertNothingNamed('api-wf-foreign', ['own', 'foreign']);
    }

    public function testCreateWithViewedOnlyTemplateIsForbiddenAndWritesNothing(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'api-wf-viewed', 'steps' => [
            ['name' => 'viewed', 'job_template_id' => $s['viewed']->id],
        ]]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame([$s['viewed']->id], $result['error']['job_template_ids'] ?? null);
        $this->assertStringContainsString('#' . $s['viewed']->id, $result['error']['message']);
        $this->assertNothingNamed('api-wf-viewed', ['viewed']);
    }

    public function testCreateReportsUnknownAndDeletedTemplatesAsMissingBeforeForbiddenOnes(): void
    {
        $s = $this->teamScope();
        $deleted = $this->createJobTemplate((int)$s['own']->project_id, (int)$s['own']->inventory_id, (int)$s['own']->runner_group_id, $s['admin']->id);
        $deleted->softDelete();
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'api-wf-missing', 'steps' => [
            ['name' => 'viewed', 'job_template_id' => $s['viewed']->id],
            ['name' => 'unknown', 'job_template_id' => 987654321],
            ['name' => 'deleted', 'job_template_id' => $deleted->id],
            ['name' => 'unknown-again', 'job_template_id' => '987654321'],
        ]]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame([987654321, $deleted->id], $result['error']['job_template_ids'] ?? null);
        $this->assertNothingNamed('api-wf-missing', ['viewed', 'unknown', 'deleted']);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidStepsProvider(): array
    {
        $tooLong = str_repeat('x', 129);
        return [
            'steps not a list' => [['first' => ['name' => 'a', 'step_type' => 'pause']], 'steps must be a list of steps.'],
            'steps a string' => ['pause', 'steps must be a list of steps.'],
            'step not an object' => [[self::OWN_STEP, 'pause'], 'steps[1]: A step must be an object.'],
            'name missing' => [[self::OWN_STEP, ['step_type' => 'pause']], 'steps[1]: Name cannot be blank.'],
            'name empty' => [[self::OWN_STEP, ['name' => '', 'step_type' => 'pause']], 'steps[1]: Name cannot be blank.'],
            'name not text' => [[self::OWN_STEP, ['name' => ['x'], 'step_type' => 'pause']], 'steps[1]: Name cannot be blank.'],
            'name too long' => [[self::OWN_STEP, ['name' => $tooLong, 'step_type' => 'pause']], 'steps[1]: Name should contain at most 128 characters.'],
            'unknown step type' => [[self::OWN_STEP, ['name' => 'b', 'step_type' => 'shell']], 'steps[1]: Step Type is invalid.'],
            'job step without template' => [[self::OWN_STEP, ['name' => 'b']], 'steps[1]: A job step needs job_template_id, the ID of a job template.'],
            'template ID not a number' => [[self::OWN_STEP, ['name' => 'b', 'job_template_id' => 'abc']], 'steps[1]: A job step needs job_template_id, the ID of a job template.'],
            'template ID true' => [[self::OWN_STEP, ['name' => 'b', 'job_template_id' => true]], 'steps[1]: A job step needs job_template_id, the ID of a job template.'],
            'template ID a float' => [[self::OWN_STEP, ['name' => 'b', 'job_template_id' => 1.0]], 'steps[1]: A job step needs job_template_id, the ID of a job template.'],
            'template ID zero' => [[self::OWN_STEP, ['name' => 'b', 'job_template_id' => 0]], 'steps[1]: A job step needs job_template_id, the ID of a job template.'],
            'template ID negative' => [[self::OWN_STEP, ['name' => 'b', 'job_template_id' => '-3']], 'steps[1]: A job step needs job_template_id, the ID of a job template.'],
            'approval step without rule' => [[self::OWN_STEP, ['name' => 'b', 'step_type' => 'approval']], 'steps[1]: An approval step needs approval_rule_id, the ID of an approval rule.'],
            'unknown approval rule' => [[self::OWN_STEP, ['name' => 'b', 'step_type' => 'approval', 'approval_rule_id' => 987654321]], 'Approval rule(s) not found: #987654321.'],
            'extra vars not JSON' => [[self::OWN_STEP, ['name' => 'b', 'step_type' => 'pause', 'extra_vars_template' => '{nope']], 'steps[1]: Extra_vars_template must be valid JSON.'],
        ];
    }

    /**
     * Every step is validated before anything is written: no template, no
     * step of the request may remain.
     *
     * @dataProvider invalidStepsProvider
     */
    public function testCreateRejectsAnInvalidStepWithoutWritingAnything(mixed $steps, string $message): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'api-wf-invalid', 'steps' => $this->withOwn($steps, $s['own']->id)]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame($message, $result['error']['message']);
        $this->assertArrayNotHasKey('job_template_ids', $result['error']);
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => 'api-wf-invalid'])->exists());
    }

    public function testCreateWithoutNameWritesNothing(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $this->setBody(['description' => 'no name', 'steps' => [['name' => 'lonely-step', 'step_type' => 'pause']]]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame('Name cannot be blank.', $result['error']['message']);
        $this->assertFalse(WorkflowStep::find()->where(['name' => 'lonely-step'])->exists());
    }

    /**
     * A name or description that is not text is a validation error, not a
     * PHP conversion error (500).
     */
    public function testCreateRejectsANameThatIsNotText(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $this->setBody(['name' => ['api-wf-array'], 'description' => ['x']]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame('Name cannot be blank.', $result['error']['message']);
    }

    /**
     * Regression: created_by and trigger_token could be set through the
     * body, choosing whom the workflow's trigger runs as.
     */
    public function testCreateIgnoresCreatorAndTriggerTokenInTheBody(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $this->setBody([
            'name' => 'api-wf-mass-assign',
            'created_by' => $s['admin']->id,
            'trigger_token' => hash('sha256', 'forged'),
            'trigger_token_created_by' => $s['admin']->id,
        ]);

        $data = $this->data($this->ctrl->actionCreate());

        $stored = WorkflowTemplate::findOne($data['id']);
        $this->assertNotNull($stored);
        $this->assertSame($s['member']->id, (int)$stored->created_by);
        $this->assertNull($stored->trigger_token);
        $this->assertNull($stored->trigger_token_created_by);
    }

    /**
     * Only the target of a step's type is read, so a pause step cannot carry
     * a job template that would decide who may see the workflow.
     */
    public function testCreateReadsOnlyTheTargetOfEachStepType(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule($s['admin']->id);
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'api-wf-targets', 'steps' => [
            ['name' => 'pause', 'step_type' => 'pause', 'job_template_id' => $s['foreign']->id, 'approval_rule_id' => $rule->id],
            ['name' => 'approve', 'step_type' => 'approval', 'job_template_id' => $s['foreign']->id, 'approval_rule_id' => $rule->id],
            ['name' => 'run', 'step_type' => 'job', 'job_template_id' => $s['own']->id, 'approval_rule_id' => $rule->id],
        ]]);

        $data = $this->data($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame([null, null, $s['own']->id], array_column($data['steps'], 'job_template_id'));
        $this->assertSame([null, $rule->id, null], array_column($data['steps'], 'approval_rule_id'));
    }

    public function testAdminMayCreateWorkflowsFromAnyTemplate(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['admin']);
        $this->setBody(['name' => 'api-wf-admin', 'steps' => [['name' => 'foreign', 'job_template_id' => $s['foreign']->id]]]);

        $data = $this->data($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame($s['foreign']->id, $data['steps'][0]['job_template_id']);
    }

    public function testCreateWritesNothingWhenAStepCannotBeSaved(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'api-wf-rollback', 'steps' => [
            ['name' => 'first', 'step_type' => 'pause'],
            ['name' => 'refused', 'step_type' => 'pause'],
        ]]);

        $result = $this->whileRefusingInsertOf(WorkflowStep::class, 'refused', fn () => $this->ctrl->actionCreate());

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame('steps[1]: Validation failed.', $result['error']['message']);
        $this->assertNothingNamed('api-wf-rollback', ['first', 'refused']);
    }

    public function testCreateReportsATemplateThatCannotBeSaved(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'api-wf-refused', 'steps' => [['name' => 'never', 'step_type' => 'pause']]]);

        $result = $this->whileRefusingInsertOf(WorkflowTemplate::class, 'api-wf-refused', fn () => $this->ctrl->actionCreate());

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame('Validation failed.', $result['error']['message']);
        $this->assertNothingNamed('api-wf-refused', ['never']);
    }

    public function testCreateRollsBackWhenWritingAStepFails(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'api-wf-crash', 'steps' => [
            ['name' => 'first', 'step_type' => 'pause'],
            ['name' => 'crash', 'step_type' => 'pause'],
        ]]);
        $handler = static function (ModelEvent $event): void {
            if ($event->sender instanceof WorkflowStep && $event->sender->name === 'crash') {
                throw new \RuntimeException('database went away');
            }
        };
        Event::on(WorkflowStep::class, ActiveRecord::EVENT_BEFORE_INSERT, $handler);
        try {
            $this->ctrl->actionCreate();
            $this->fail('The failure must not be swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('database went away', $e->getMessage());
        } finally {
            Event::off(WorkflowStep::class, ActiveRecord::EVENT_BEFORE_INSERT, $handler);
        }

        $this->assertNothingNamed('api-wf-crash', ['first', 'crash']);
    }

    // -- Update ---------------------------------------------------------------

    public function testUpdateReplacesTheStepsOfOwnWorkflow(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'api-wf-updated', 'steps' => [
            ['name' => 'only', 'job_template_id' => $s['open']->id],
        ]]);

        $data = $this->data($this->ctrl->actionUpdate($workflow->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame('api-wf-updated', $data['name']);
        $this->assertSame(['only'], array_column($data['steps'], 'name'));
        $this->assertSame(1, (int)WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->count());
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED, 'object_id' => $workflow->id]);
        $this->assertNotNull($audit);
        $this->assertSame(
            ['name' => 'api-wf-updated', 'source' => 'api', 'job_template_ids' => [$s['open']->id]],
            json_decode((string)$audit->metadata, true)
        );
    }

    public function testUpdateOfViewedOnlyOrForeignWorkflowIsForbidden(): void
    {
        $s = $this->teamScope();
        $viewed = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $mixed = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id);
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'hijacked', 'steps' => [['name' => 'pause', 'step_type' => 'pause']]]);

        foreach ([$viewed, $mixed] as $workflow) {
            $steps = $this->stepNames($workflow->id);
            $this->assertForbiddenResponse($this->ctrl->actionUpdate($workflow->id));
            $this->assertSame($workflow->name, WorkflowTemplate::findOne($workflow->id)?->name);
            $this->assertSame($steps, $this->stepNames($workflow->id));
        }
    }

    /**
     * Regression: update deleted all steps before it looked at the new ones,
     * so a rejected update left the workflow without its steps.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function rejectedUpdateProvider(): array
    {
        return [
            'invalid step' => ['invalid', 422],
            'template of another team' => ['foreign', 422],
            'viewed-only template' => ['viewed', 403],
        ];
    }

    /**
     * @dataProvider rejectedUpdateProvider
     */
    public function testARejectedUpdateLeavesTheStepsIntact(string $case, int $status): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
        $this->authenticate($s['member']);
        $second = match ($case) {
            'invalid' => ['name' => '', 'step_type' => 'pause'],
            'foreign' => ['name' => 'foreign', 'job_template_id' => $s['foreign']->id],
            default => ['name' => 'viewed', 'job_template_id' => $s['viewed']->id],
        };
        $this->setBody(['name' => 'renamed', 'steps' => [['name' => 'new', 'job_template_id' => $s['own']->id], $second]]);

        $this->ctrl->actionUpdate($workflow->id);

        $this->assertSame($status, \Yii::$app->response->statusCode);
        $this->assertSame(['step-0', 'step-1'], $this->stepNames($workflow->id));
        $this->assertSame($workflow->name, WorkflowTemplate::findOne($workflow->id)?->name);
    }

    public function testAnUpdateThatFailsWhileWritingLeavesTheStepsIntact(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['open']->id);
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'renamed', 'steps' => [
            ['name' => 'new', 'step_type' => 'pause'],
            ['name' => 'refused', 'step_type' => 'pause'],
        ]]);

        $result = $this->whileRefusingInsertOf(WorkflowStep::class, 'refused', fn () => $this->ctrl->actionUpdate($workflow->id));

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame('steps[1]: Validation failed.', $result['error']['message']);
        $this->assertSame(['step-0', 'step-1'], $this->stepNames($workflow->id));
        $this->assertSame($workflow->name, WorkflowTemplate::findOne($workflow->id)?->name);
    }

    public function testUpdateWithoutStepsKeepsThemAndAnEmptyListRemovesThem(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->authenticate($s['member']);

        $this->setBody(['description' => 'steps untouched', 'steps' => null]);
        $data = $this->data($this->ctrl->actionUpdate($workflow->id));
        $this->assertSame('steps untouched', $data['description']);
        $this->assertSame(['step-0'], $this->stepNames($workflow->id));

        $this->setBody(['steps' => []]);
        $data = $this->data($this->ctrl->actionUpdate($workflow->id));
        $this->assertSame([], $data['steps']);
        $this->assertSame([], $this->stepNames($workflow->id));
    }

    public function testAdminMayUpdateWorkflowOfAnotherTeam(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['member']->id, $s['foreign']->id);
        $this->authenticate($s['admin']);
        $this->setBody(['name' => 'admin-renamed']);

        $this->assertSame('admin-renamed', $this->data($this->ctrl->actionUpdate($workflow->id))['name']);
    }

    // -- Delete ---------------------------------------------------------------

    public function testDeleteOfViewedOnlyOrForeignWorkflowIsForbidden(): void
    {
        $s = $this->teamScope();
        $viewed = $this->createWorkflowWithJobSteps($s['admin']->id, $s['viewed']->id);
        $foreign = $this->createWorkflowWithJobSteps($s['admin']->id, $s['foreign']->id);
        $this->authenticate($s['member']);

        foreach ([$viewed, $foreign] as $workflow) {
            $this->assertForbiddenResponse($this->ctrl->actionDelete($workflow->id));
            $this->assertNotNull(WorkflowTemplate::findOne($workflow->id));
        }
    }

    public function testDeleteOfOwnWorkflowSoftDeletesIt(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->authenticate($s['member']);

        $this->assertSame(['deleted' => true], $this->data($this->ctrl->actionDelete($workflow->id)));
        $this->assertNull(WorkflowTemplate::findOne($workflow->id));
    }

    // -- Launch ---------------------------------------------------------------

    public function testLaunchOfOwnWorkflowRunsAsTheCaller(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->authenticate($s['member']);

        $data = $this->data($this->ctrl->actionLaunch($workflow->id));

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $run = WorkflowJob::findOne($data['workflow_job_id']);
        $this->assertNotNull($run);
        $this->assertSame($s['member']->id, (int)$run->launched_by);
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_LAUNCHED, 'object_id' => $run->id]);
        $this->assertNotNull($audit);
        $this->assertSame('api', json_decode((string)$audit->metadata, true)['source'] ?? null);
    }

    public function testLaunchOfViewedOnlyWorkflowIsForbiddenAndAudited(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['viewed']->id);
        $this->authenticate($s['member']);

        $result = $this->ctrl->actionLaunch($workflow->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame([$s['viewed']->id], $result['error']['job_template_ids'] ?? null);
        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->exists());
        $denied = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_LAUNCH_DENIED, 'object_id' => $workflow->id]);
        $this->assertNotNull($denied);
        $this->assertSame(['source' => 'api', 'job_template_ids' => [$s['viewed']->id]], json_decode((string)$denied->metadata, true));
    }

    /**
     * Regression: launching a workflow the caller may not even see answered
     * with the job template IDs of another team, in the message and in
     * error.job_template_ids. Only the audit entry names them now.
     */
    public function testLaunchOfForeignWorkflowIsForbiddenWithoutNamingItsTemplates(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id);
        $this->authenticate($s['member']);

        $result = $this->ctrl->actionLaunch($workflow->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'You may not launch this workflow.']], $result);
        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->exists());
        $denied = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_LAUNCH_DENIED, 'object_id' => $workflow->id]);
        $this->assertNotNull($denied);
        $this->assertSame(['source' => 'api', 'job_template_ids' => [$s['foreign']->id]], json_decode((string)$denied->metadata, true));
    }

    public function testLaunchWithoutTheLaunchPermissionNamesNoTemplates(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['open']->id);
        $this->authenticate($this->createUser('no-roles'));

        $result = $this->ctrl->actionLaunch($workflow->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'You may not launch workflows.']], $result);
    }

    public function testLaunchOfWorkflowWithoutStepsIs422(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->authenticate($s['member']);

        $result = $this->ctrl->actionLaunch($workflow->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame('Workflow template has no steps.', $result['error']['message']);
    }

    public function testAdminMayLaunchWorkflowOfAnotherTeam(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['member']->id, $s['foreign']->id);
        $this->authenticate($s['admin']);

        $data = $this->data($this->ctrl->actionLaunch($workflow->id));

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertNotNull(WorkflowJob::findOne($data['workflow_job_id']));
    }

    // -- Helpers --------------------------------------------------------------

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'workflow-api-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function data(array $result): array
    {
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        return $data;
    }

    private function assertForbiddenResponse(mixed $result): void
    {
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], $result ?? \Yii::$app->response->data);
    }

    /**
     * Neither the workflow template nor any of the named steps was written.
     *
     * @param list<string> $stepNames
     */
    private function assertNothingNamed(string $workflowName, array $stepNames): void
    {
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => $workflowName])->exists(), $workflowName);
        $this->assertFalse(WorkflowStep::find()->where(['name' => $stepNames])->exists(), implode(', ', $stepNames));
    }

    /**
     * @return list<string>
     */
    private function stepNames(int $workflowTemplateId): array
    {
        /** @var list<string> $names */
        $names = WorkflowStep::find()
            ->select('name')
            ->where(['workflow_template_id' => $workflowTemplateId])
            ->orderBy(['step_order' => SORT_ASC, 'id' => SORT_ASC])
            ->column();
        return $names;
    }

    private function pauseOnlyWorkflow(int $createdBy): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate($createdBy);
        $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        return $workflow;
    }

    /**
     * Replace the 'OWN' placeholder of the data provider with a real ID.
     */
    private function withOwn(mixed $steps, int $ownTemplateId): mixed
    {
        if (!is_array($steps)) {
            return $steps;
        }
        return array_map(
            static fn (mixed $step): mixed => is_array($step) && ($step['job_template_id'] ?? null) === 'OWN'
                ? ['job_template_id' => $ownTemplateId] + $step
                : $step,
            $steps
        );
    }

    /**
     * Run $action while inserts of $class records named $name are refused,
     * as when a concurrent change invalidates a record between validation
     * and the write.
     *
     * @param class-string<ActiveRecord> $class
     * @return array<string, mixed>
     */
    private function whileRefusingInsertOf(string $class, string $name, callable $action): array
    {
        $handler = static function (ModelEvent $event) use ($name): void {
            if ($event->sender instanceof ActiveRecord && $event->sender->getAttribute('name') === $name) {
                $event->isValid = false;
            }
        };
        Event::on($class, ActiveRecord::EVENT_BEFORE_INSERT, $handler);
        try {
            /** @var array<string, mixed> $result */
            $result = $action();
        } finally {
            Event::off($class, ActiveRecord::EVENT_BEFORE_INSERT, $handler);
        }
        return $result;
    }
}
