<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\WorkflowStepMapping;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\ApiToken;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowExecutionService;
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

    /**
     * As an admin: only admins (approval-rule.update) write approval steps.
     */
    public function testCreateSavesTheTemplateAndValidatedStepsInOrder(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule($s['admin']->id);
        $this->authenticate($s['admin']);
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
        $this->assertSame($s['admin']->id, $data['created_by']);
        $this->assertSame(['build', 'approve', 'wait', 'deploy'], array_column($data['steps'], 'name'));
        $this->assertSame(['job', 'approval', 'pause', 'job'], array_column($data['steps'], 'step_type'));
        $this->assertSame([0, 1, 2, 3], array_column($data['steps'], 'step_order'));
        $this->assertSame([1, 2, 3, 4], array_column($data['steps'], 'position'));
        $this->assertSame([true, false, false, false], array_column($data['steps'], 'is_start'));
        $this->assertSame([true, true, true, true], array_column($data['steps'], 'reachable'));
        $this->assertSame([null, null, null, null], array_column($data['steps'], 'cannot_run'));
        $this->assertSame([$s['own']->id, null, null, $s['open']->id], array_column($data['steps'], 'job_template_id'));
        $this->assertSame([null, $rule->id, null, null], array_column($data['steps'], 'approval_rule_id'));
        $this->assertSame(['env' => 'target_env'], $data['steps'][0]['extra_vars_template']);
        $ids = array_column($data['steps'], 'id');
        $this->assertSame($ids[0], $data['start_step_id']);
        $this->assertSame(['step_ids' => $ids, 'end' => 'succeeds', 'loop_to_step_id' => null, 'missing_step_id' => null], $data['success_path']);
        $this->assertSame(
            [
                'success' => ['kind' => 'step', 'via' => 'next', 'step_id' => $ids[2], 'position' => 3, 'run_outcome' => null, 'loop' => false],
                'failure' => null,
                'closed_gate' => ['kind' => 'end', 'via' => 'on_failure', 'step_id' => null, 'position' => null, 'run_outcome' => 'fails', 'loop' => false],
            ],
            $data['steps'][1]['routes'],
            'the approval step: approved goes on, a closed gate ends the run as failed'
        );
        $this->assertSame(
            ['kind' => 'end', 'via' => 'next', 'step_id' => null, 'position' => null, 'run_outcome' => 'fails', 'loop' => false],
            $data['steps'][3]['routes']['failure'],
            'after the last step, a failure ends the run as failed'
        );

        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_CREATED, 'object_id' => $data['id']]);
        $this->assertNotNull($audit);
        $this->assertSame(
            [
                'name' => 'api-wf-create',
                'source' => 'api',
                'job_template_ids' => [$s['own']->id, $s['open']->id],
                'approval_rule_ids' => [$rule->id],
            ],
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
            // Before the approval-step rule (403): the steps cannot be read.
            // An unknown rule: WorkflowTemplatesStepGuardTest.
            'approval step without rule' => [[self::OWN_STEP, ['name' => 'b', 'step_type' => 'approval']], 'steps[1]: An approval step needs approval_rule_id, the ID of an approval rule.'],
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
     * a job template that would decide who may see the workflow. As an
     * admin, who may write approval steps.
     */
    public function testCreateReadsOnlyTheTargetOfEachStepType(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule($s['admin']->id);
        $this->authenticate($s['admin']);
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

    public function testCreateWithoutStepsReportsATemplateThatCannotBeSaved(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $this->setBody(['name' => 'api-wf-refused-alone']);

        $result = $this->whileRefusingInsertOf(WorkflowTemplate::class, 'api-wf-refused-alone', fn () => $this->ctrl->actionCreate());

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Validation failed.']], $result);
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => 'api-wf-refused-alone'])->exists());
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
            ['name' => 'api-wf-updated', 'source' => 'api', 'job_template_ids' => [$s['open']->id], 'approval_rule_ids' => []],
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

    /**
     * Decision 16, unchanged in 2.11.0 and documented: a "steps" list
     * replaces every step. The new steps get new IDs and no branches, as
     * the list does not read them; the per-step endpoints
     * (WorkflowStepsController) change one step and keep both. A change of
     * this behaviour must be a decision, not an accident.
     */
    public function testAStepsListReplacesEveryStepWithNewIdsAndNoBranches(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['own']->id);
        [$first, $second] = WorkflowStep::find()->where(['workflow_template_id' => $workflow->id])->orderBy(['id' => SORT_ASC])->all();
        $first->on_failure_step_id = (int)$second->id;
        $first->save(false);
        $this->authenticate($s['member']);
        $this->setBody(['steps' => [
            ['name' => 'step-0', 'job_template_id' => $s['own']->id, 'on_failure_step_id' => (int)$second->id],
            ['name' => 'step-1', 'job_template_id' => $s['own']->id],
        ]]);

        $steps = $this->data($this->ctrl->actionUpdate($workflow->id))['steps'];

        $this->assertSame(['step-0', 'step-1'], array_column($steps, 'name'));
        $this->assertSame([], array_intersect(array_column($steps, 'id'), [(int)$first->id, (int)$second->id]), 'new IDs');
        $this->assertSame([null, null], array_column($steps, 'on_failure_step_id'), 'no branches');
        $this->assertSame('next', $steps[0]['routes']['failure']['via'] ?? null);
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
        $this->assertNull($data['start_step_id']);
        $this->assertSame(['step_ids' => [], 'end' => null, 'loop_to_step_id' => null, 'missing_step_id' => null], $data['success_path']);
        $this->assertSame([], $this->stepNames($workflow->id));
    }

    // -- Variable mapping -------------------------------------------------------

    /**
     * Variable mappings that are no JSON object of extra var names to the
     * names of output variables, as a value of the body or as JSON text.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function noMappingProvider(): array
    {
        return [
            'a list of names' => [['build_version']],
            'a list of names as text' => ['["build_version"]'],
            'a list of numbers as text' => ['[1,2]'],
            'a number as text' => ['5'],
            'a JSON string as text' => ['"text"'],
            'a number as a value' => [['a' => 1]],
            'an object as a value' => [['a' => ['b' => 'c']]],
            'true' => [true],
            'a key PHP reads as a number, as text' => ['{"0": "build_version"}'],
            'an empty key' => [['' => 'build_version']],
        ];
    }

    /**
     * regression: the steps list stored any JSON as a step's variable
     * mapping, such as ["build_version"], which made the extra vars of the
     * runs' jobs a JSON list that Ansible refuses, or {"a": 1}, which the
     * API then answered in a shape its schema does not have. A create and an
     * update answer 422 now, in the words of the list's errors
     * (steps[<n>]: ...), and write nothing.
     *
     * @dataProvider noMappingProvider
     */
    public function testRegressionAStepsListWithAMappingOfAnotherShapeIs422(mixed $mapping): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id);
        $this->authenticate($s['member']);
        $steps = [
            ['name' => 'kept', 'job_template_id' => $s['own']->id, 'extra_vars_template' => ['app_version' => 'build_version']],
            ['name' => 'mapped', 'step_type' => 'pause', 'extra_vars_template' => $mapping],
        ];

        $this->setBody(['name' => 'api-wf-mapping', 'steps' => $steps]);
        $created = $this->ctrl->actionCreate();
        $createdStatus = \Yii::$app->response->statusCode;
        $this->setBody(['name' => 'api-wf-mapping-renamed', 'steps' => $steps]);
        $updated = $this->ctrl->actionUpdate($workflow->id);

        $this->assertSame([422, 422], [$createdStatus, \Yii::$app->response->statusCode], (string)json_encode([$created, $updated]));
        $refusal = ['error' => ['message' => 'steps[1]: ' . WorkflowStepMapping::INVALID]];
        $this->assertSame([$refusal, $refusal], [$created, $updated]);
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => 'api-wf-mapping'])->exists());
        $this->assertSame(['step-0'], $this->stepNames($workflow->id));
        $this->assertSame($workflow->name, WorkflowTemplate::findOne($workflow->id)?->name);
    }

    /**
     * No mapping, in any form, is stored as null and answered as []; a
     * mapping is stored as sent.
     */
    public function testAStepsListStoresNoMappingAsNull(): void
    {
        $s = $this->teamScope();
        $this->authenticate($s['member']);
        $none = [[], '[]', '{}', 'null', '', null];
        $steps = array_map(static fn (mixed $mapping): array => ['name' => 'none', 'step_type' => 'pause', 'extra_vars_template' => $mapping], $none);
        $steps[] = ['name' => 'mapped', 'step_type' => 'pause', 'extra_vars_template' => '{"app_version": "build_version"}'];
        $this->setBody(['name' => 'api-wf-no-mapping', 'steps' => $steps]);

        $data = $this->data($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame([[], [], [], [], [], [], ['app_version' => 'build_version']], array_column($data['steps'], 'extra_vars_template'));
        $stored = WorkflowStep::find()->select('extra_vars_template')->where(['workflow_template_id' => $data['id']])->orderBy(['step_order' => SORT_ASC])->column();
        $this->assertSame([null, null, null, null, null, null, '{"app_version": "build_version"}'], $stored);
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

        $this->assertSame(['deleted' => true, 'canceled_runs' => 0, 'failed_runs' => 0], $this->data($this->ctrl->actionDelete($workflow->id)));
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

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function startStepProvider(): array
    {
        return [
            'an approval step' => ['approval', 'approval'],
            'a gated job step' => ['gated job', 'approval'],
            'a pause step' => ['pause', 'resume'],
            'a job step' => ['job', 'runner'],
        ];
    }

    /**
     * The launch answers what the new run waits for at its start step
     * (waiting_for) and the approval request it waits for
     * (approval_request_id): an approval step's or a gated job step's, as
     * the run page shows them.
     *
     * @dataProvider startStepProvider
     */
    public function testTheLaunchSaysWhatTheRunWaitsFor(string $start, string $waitingFor): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $rule = $this->createApprovalRule($s['admin']->id, ApprovalRule::APPROVER_TYPE_USERS, (string)json_encode(['user_ids' => [$s['admin']->id]]));
        match ($start) {
            'approval' => $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, $rule->id),
            'gated job' => $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_JOB, $this->gatedTemplate($s['own'], $rule->id)),
            'pause' => $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_PAUSE),
            default => $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_JOB, $s['own']->id),
        };
        $this->authenticate($s['member']);

        $data = $this->data($this->ctrl->actionLaunch($workflow->id));

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame(['workflow_job_id', 'waiting_for', 'approval_request_id'], array_keys($data));
        $this->assertSame($waitingFor, $data['waiting_for']);
        $execution = WorkflowJobStep::findOne(['workflow_job_id' => $data['workflow_job_id']]);
        $this->assertNotNull($execution);
        $request = ApprovalRequest::findOne(['job_id' => $execution->job_id]);
        $this->assertSame($waitingFor === 'approval' ? (int)$request?->id : null, $data['approval_request_id']);
    }

    /**
     * A run that does not wait at its start step, such as one whose start
     * step cannot run, answers null for both.
     */
    public function testALaunchThatDoesNotWaitAnswersNullForBoth(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep($workflow->id, 0, 'JOB');
        $this->authenticate($s['member']);

        $data = $this->data($this->ctrl->actionLaunch($workflow->id));

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame([null, null], [$data['waiting_for'], $data['approval_request_id']]);
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
        $this->assertSame(['error' => ['message' => sprintf(WorkflowExecutionService::NO_STEPS, $workflow->id)]], $result);
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

    /**
     * A job template in the project of $like, gated by the approval rule
     * $ruleId; its ID.
     */
    private function gatedTemplate(JobTemplate $like, int $ruleId): int
    {
        $template = $this->createJobTemplate((int)$like->project_id, (int)$like->inventory_id, (int)$like->runner_group_id, (int)$like->created_by);
        JobTemplate::updateAll(['approval_rule_id' => $ruleId], ['id' => $template->id]);

        return (int)$template->id;
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
