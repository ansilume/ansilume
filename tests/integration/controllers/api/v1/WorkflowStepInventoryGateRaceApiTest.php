<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\InventoryGatedStepFixtures;
use app\tests\integration\services\WorkflowStepWriteFixtures;

/**
 * Rule 12 in the API for job templates gated through their inventory
 * (contract C15): giving a job step a job template whose inventory has an
 * approval rule is for admins
 * (ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED), on
 * POST /steps, PUT /steps/{stepId}, and the "steps" list of POST and PUT
 * /workflow-templates; replacing the steps of a workflow that has such a step
 * is too (GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED). Every path checks it
 * before the lock and again with the workflow locked. The races replayed
 * here: between the caller's checks and the lock, an admin gives the job
 * template's inventory a rule, or moves the job template to an inventory
 * with one. The change is refused with 403 and the policy's text, and
 * nothing of it is written. An admin's answer gives the step its closed
 * gate (routes.closed_gate).
 */
final class WorkflowStepInventoryGateRaceApiTest extends WebControllerTestCase
{
    use InventoryGatedStepFixtures;
    use WorkflowStepApiRequests;
    use WorkflowStepWriteFixtures;

    /** The API's requests that give a job step a job template: case => what the request does. */
    private const PATHS = [
        'POST /steps' => 'create',
        'PUT /steps/{stepId}' => 'update',
        'POST /workflow-templates with steps' => 'bulk-create',
        'PUT /workflow-templates/{id} with steps' => 'bulk-update',
    ];

    /** What an admin changes meanwhile. */
    private const RACES = ['its inventory gets a rule', 'it moves to an inventory with a rule'];

    /** The name of the workflow that the "steps" list of POST /workflow-templates creates. */
    private const CREATED_NAME = 'api-wf-inventory-gate-race';

    /** A refused approval of a gated job step ends the run as failed when its On Failure is empty. */
    private const CLOSED_GATE_ENDS = ['kind' => 'end', 'via' => 'on_failure', 'step_id' => null, 'position' => null, 'run_outcome' => 'fails', 'loop' => false];

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    private int $ruleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->teamScope();
        $this->ruleId = (int)$this->createApprovalRule((int)$this->s['admin']->id)->id;
        // open gets an inventory of its own: the inventory of teamScope() is
        // shared by all four job templates, the workflow's own step's too.
        $this->moveToInventory((int)$this->s['open']->id, (int)$this->createInventory((int)$this->s['admin']->id)->id);
        $this->s['open']->refresh();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pathProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::PATHS) as $path) {
            $cases[$path] = [$path];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function raceProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::PATHS) as $path) {
            foreach (self::RACES as $race) {
                $cases["{$path}, {$race}"] = [$path, $race];
            }
        }

        return $cases;
    }

    /**
     * regression: a job template gated only through its inventory counted
     * as ungated, so an operator gave a job step such a job template through
     * the API (per-step POST and PUT, and the "steps" list of POST and PUT
     * /workflow-templates), which is for admins (rule 12). Now the request
     * answers 403 with the policy's text, and nothing is written.
     *
     * @dataProvider pathProvider
     */
    public function testRegressionAnOperatorGaveAJobStepAJobTemplateGatedThroughItsInventory(string $path): void
    {
        $this->giveInventoryRule((int)$this->s['open']->inventory_id, $this->ruleId);
        $workflow = $this->workflow();
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);

        $answer = $this->send($path, $workflow);

        $this->assertSame(403, $answer['status'], (string)json_encode($answer['body']));
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED]], $answer['body']);
        $this->assertNothingWritten($workflow, $before);
    }

    /**
     * regression: the "steps" list of PUT /workflow-templates/{id} replaced
     * the steps of a workflow whose job step was gated only through its job
     * template's inventory, also with none, which is for admins (rule 12).
     * Now it answers 403 with the policy's text, the same steps again too,
     * and nothing is written.
     */
    public function testRegressionAnOperatorReplacedTheStepsOfAWorkflowWithAnInventoryGatedJobStep(): void
    {
        $this->giveInventoryRule((int)$this->s['open']->inventory_id, $this->ruleId);
        $workflow = $this->createWorkflowWithJobSteps((int)$this->s['admin']->id, (int)$this->s['own']->id, (int)$this->s['open']->id);
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);
        $bodies = [
            'without the gated job step' => ['steps' => [['name' => 'build', 'job_template_id' => (int)$this->s['own']->id]]],
            'no steps at all' => ['steps' => []],
            'the same steps again' => ['steps' => [
                ['name' => 'build', 'job_template_id' => (int)$this->s['own']->id],
                ['name' => 'deploy', 'job_template_id' => (int)$this->s['open']->id],
            ]],
        ];

        $answers = [];
        foreach ($bodies as $case => $body) {
            $answers[$case] = $this->bulk('update', $workflow, $body);
        }

        foreach ($answers as $case => $answer) {
            $this->assertSame(403, $answer['status'], $case);
        }
        $this->assertSame(
            ['error' => ['message' => ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_WORKFLOW_STEPS_REFUSED]],
            $answers['without the gated job step']['body']
        );
        $this->assertSame($answers['without the gated job step']['body'], $answers['no steps at all']['body']);
        $this->assertSame(
            ['error' => ['message' => ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED]],
            $answers['the same steps again']['body'],
            'the same steps again add the gated job step'
        );
        $this->assertNothingWritten($workflow, $before);
    }

    /**
     * The job template is checked again under the lock: an approval rule
     * that an admin gave its inventory between the caller's checks and the
     * lock, or an inventory with a rule that an admin gave it, stops the
     * change: 403 with the policy's text, nothing written, no audit entry.
     *
     * @dataProvider raceProvider
     */
    public function testAJobTemplateGatedThroughItsInventoryMeanwhileStopsTheChange(string $path, string $race): void
    {
        $workflow = $this->workflow();
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);
        $raced = 0;

        $answer = $this->meanwhile(
            function () use ($race, &$raced): void {
                $this->gateMeanwhile($race);
                $raced++;
            },
            fn (): array => $this->send($path, $workflow)
        );

        $this->assertSame(1, $raced, 'the admin gated the job template between the checks and the lock');
        $this->assertSame(403, $answer['status'], (string)json_encode($answer['body']));
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::GATED_INVENTORY_JOB_STEP_ADD_REFUSED]], $answer['body']);
        $this->assertNothingWritten($workflow, $before);
    }

    /**
     * An admin may give a job step such a job template: the same race
     * writes the steps for an admin, and the answer gives the step its
     * closed gate, as for any gated job step.
     *
     * @dataProvider raceProvider
     */
    public function testAnAdminChangesTheStepsInTheSameRace(string $path, string $race): void
    {
        $workflow = $this->workflow();
        $this->tokenOf($this->s['admin']);

        $answer = $this->meanwhile(fn () => $this->gateMeanwhile($race), fn (): array => $this->send($path, $workflow));

        $this->assertSame(in_array(self::PATHS[$path], ['create', 'bulk-create'], true) ? 201 : 200, $answer['status'], (string)json_encode($answer['body']));
        $step = $this->stepOfOpen($answer);
        $this->assertSame((int)$this->s['open']->id, $step['job_template_id']);
        $this->assertSame(self::CLOSED_GATE_ENDS, $step['routes']['closed_gate'] ?? null, 'a refused approval ends the run as failed');
        $this->assertNull($step['cannot_run']);
    }

    /**
     * Without the race, the same requests change the steps for the member:
     * the refusals above are the gate of the inventory.
     *
     * @dataProvider pathProvider
     */
    public function testWithoutTheRaceTheMemberChangesTheSteps(string $path): void
    {
        $workflow = $this->workflow();
        $this->tokenOf($this->s['member']);

        $answer = $this->send($path, $workflow);

        $this->assertSame(in_array(self::PATHS[$path], ['create', 'bulk-create'], true) ? 201 : 200, $answer['status'], (string)json_encode($answer['body']));
        $step = $this->stepOfOpen($answer);
        $this->assertNull($step['routes']['closed_gate'] ?? null, 'an ungated job step has no closed gate');
    }

    /**
     * A job template on a gated inventory that the caller cannot see answers
     * as any job template they cannot see (422, not found): a 403 would tell
     * that its inventory has an approval rule.
     *
     * @dataProvider pathProvider
     */
    public function testAJobTemplateOnAGatedInventoryTheCallerCannotSeeIsNotFound(string $path): void
    {
        $foreign = (int)$this->s['foreign']->id;
        $this->moveToInventory($foreign, (int)$this->gatedInventory((int)$this->s['admin']->id, $this->ruleId)->id);
        $workflow = $this->workflow();
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);

        $answer = $this->send($path, $workflow, $foreign);

        $this->assertSame(422, $answer['status'], (string)json_encode($answer['body']));
        $this->assertIsArray($answer['body']['error'] ?? null);
        $this->assertSame([$foreign], $answer['body']['error']['job_template_ids'] ?? null);
        $this->assertNothingWritten($workflow, $before);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Build, a job step of the member's own project. The job template that
     * the requests give a job step, open (a project without a team, on an
     * inventory of its own), is not one of the workflow's yet, so its gate
     * does not lock the workflow's steps.
     */
    private function workflow(): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->s['own']->id);

        return $workflow;
    }

    /**
     * What an admin changes meanwhile, by $race: open's inventory gets the
     * rule, or open moves to another inventory that has it.
     */
    private function gateMeanwhile(string $race): void
    {
        if ($race === self::RACES[0]) {
            $this->giveInventoryRule((int)$this->s['open']->inventory_id, $this->ruleId);
            return;
        }
        $this->moveToInventory((int)$this->s['open']->id, (int)$this->gatedInventory((int)$this->s['admin']->id, $this->ruleId)->id);
    }

    /**
     * Send the request of $path, which gives a job step the job template
     * $templateId (open by default): POST /steps adds the job step Lint, PUT
     * changes Build's job template, and the "steps" lists keep Build and add
     * Lint.
     *
     * @return array{status: int, body: array<mixed>}
     */
    private function send(string $path, WorkflowTemplate $workflow, ?int $templateId = null): array
    {
        $id = (int)$workflow->id;
        $templateId ??= (int)$this->s['open']->id;
        $steps = [
            ['name' => 'Build', 'job_template_id' => (int)$this->s['own']->id],
            ['name' => 'Lint', 'job_template_id' => $templateId],
        ];

        return match (self::PATHS[$path]) {
            'create' => $this->api('POST', $this->stepsPath($id), ['name' => 'Lint', 'job_template_id' => $templateId]),
            'update' => $this->api('PUT', $this->stepPath($id, $this->stepIdOf($id, 'Build')), ['job_template_id' => $templateId]),
            'bulk-create' => $this->bulk('create', $workflow, ['name' => self::CREATED_NAME, 'steps' => $steps]),
            default => $this->bulk('update', $workflow, ['steps' => $steps]),
        };
    }

    /**
     * POST /api/v1/workflow-templates, or PUT /api/v1/workflow-templates/{id}
     * of $workflow, with $body, through WorkflowTemplatesController with its
     * filters (the signed-in token, the access rules).
     *
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<mixed>}
     */
    private function bulk(string $action, WorkflowTemplate $workflow, array $body): array
    {
        $_SERVER['REQUEST_METHOD'] = $action === 'create' ? 'POST' : 'PUT';
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
        $request->setQueryParams([]);
        \Yii::$app->response->statusCode = 200;
        $params = $action === 'create' ? [] : ['id' => (string)$workflow->id];
        $result = (new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app))->runAction($action, $params);
        $this->assertIsArray($result);

        return ['status' => \Yii::$app->response->statusCode, 'body' => $result];
    }

    /**
     * The step of the answer $answer that uses the job template open: the
     * per-step endpoints answer the step, the "steps" lists the workflow
     * with its steps.
     *
     * @param array{status: int, body: array<mixed>} $answer
     * @return array<mixed>
     */
    private function stepOfOpen(array $answer): array
    {
        $data = $answer['body']['data'] ?? null;
        $this->assertIsArray($data, (string)json_encode($answer['body']));
        $steps = isset($data['steps']) && is_array($data['steps']) ? $data['steps'] : [$data];
        foreach ($steps as $step) {
            if (is_array($step) && ($step['job_template_id'] ?? null) === (int)$this->s['open']->id) {
                return $step;
            }
        }
        $this->fail('The answer has no step on the job template open: ' . json_encode($data));
    }

    /**
     * The workflow's steps are as they were, no workflow was created, and
     * neither a step nor a workflow was audited as changed.
     *
     * @param array<int, array<string, mixed>> $before
     */
    private function assertNothingWritten(WorkflowTemplate $workflow, array $before): void
    {
        $this->assertSame($before, $this->stepRows((int)$workflow->id), 'nothing of the caller\'s change was written');
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
        $this->assertFalse(WorkflowTemplate::findWithDeleted()->where(['name' => self::CREATED_NAME])->exists(), 'no workflow is created');
        $this->assertFalse(AuditLog::find()->where([
            'action' => [AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED, AuditLog::ACTION_WORKFLOW_TEMPLATE_CREATED],
            'object_type' => 'workflow_template',
        ])->andWhere(['>=', 'object_id', (int)$workflow->id])->exists());
    }
}
